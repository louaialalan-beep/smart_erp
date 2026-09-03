<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';
require_once __DIR__ . '/functions.php';

// ضمان وجود عمودي التكلفة التاريخية والعمولة الدقيقة للقطعة (للتوافق مع القاعدة القائمة قبل هذا التصحيح)
try {
    $sale_items_cols = $conn->query("SHOW COLUMNS FROM sale_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cost_price_usd_at_sale', $sale_items_cols)) {
        $conn->exec("ALTER TABLE sale_items ADD COLUMN cost_price_usd_at_sale DECIMAL(15,4) DEFAULT NULL");
    }
    if (!in_array('commission_per_unit', $sale_items_cols)) {
        $conn->exec("ALTER TABLE sale_items ADD COLUMN commission_per_unit DECIMAL(15,4) DEFAULT NULL");
    }
} catch (Exception $e) { /* يُتجاهل إن تعذّر (صلاحيات محدودة)، مع تراجع تلقائي للحسابات القديمة في الاستعلامات */ }

// ضمان وجود عمود تكلفة الشحن على مستوى الفاتورة (الآن مُرحَّل محاسبياً — انظر الأسفل)
try {
    $sales_cols_chk = $conn->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('shipping_cost_syp', $sales_cols_chk)) {
        $conn->exec("ALTER TABLE sales ADD COLUMN shipping_cost_syp DECIMAL(15,2) DEFAULT 0");
    }
    if (!in_array('delivery_type', $sales_cols_chk)) {
        $conn->exec("ALTER TABLE sales ADD COLUMN delivery_type ENUM('شحن','توصيل') DEFAULT NULL");
    }
} catch (Exception $e) { /* يُتجاهل إن تعذّر */ }

// حساب "تكاليف الشحن" (مصروف) — يُنشأ مرة واحدة بالكود والنوع الصحيحين إن لم يكن موجوداً، بدل الاعتماد
// على findAccountId العامة التي لا تضبط account_type عند الإنشاء التلقائي (نفس العلّة المُصلَحة سابقاً
// لحسابات أخرى في هذا النظام — نتجنبها هنا من البداية لهذا الحساب الجديد تحديداً).
function ensureShippingExpenseAccount($conn) {
    $stmt = $conn->prepare("SELECT id FROM accounts WHERE account_name = 'تكاليف الشحن' LIMIT 1");
    $stmt->execute();
    $id = $stmt->fetchColumn();
    if ($id) { return $id; }

    $code = '5141';
    $chk = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
    for ($i = 0; $i < 50; $i++) {
        $chk->execute([$code]);
        if ($chk->fetchColumn() == 0) { break; }
        $code = (string)(intval($code) + 1);
    }
    $conn->prepare("INSERT INTO accounts (account_code, account_name, account_type) VALUES (?, 'تكاليف الشحن', 'Expense')")->execute([$code]);
    return $conn->lastInsertId();
}

$msg = "";
$error = "";

$current_year_month = date('Y-m');

// قواميس ترجمة قيم الـ ENUM المخزنة بالإنجليزية إلى نص عربي للعرض فقط
// يجب أن تطابق القيم الفعلية المعرّفة في قاعدة البيانات:
// payment_status  : enum('Paid','Unpaid','Partial')
// delivery_status : enum('Delivered','Pending','Deferred')
$payment_labels  = ['Paid' => 'نقداً في الصندوق', 'Unpaid' => 'آجل / ذمم عملاء', 'Partial' => 'دفعة جزئية'];
$delivery_labels = ['Delivered' => 'تم التسليم', 'Pending' => 'قيد الانتظار', 'Deferred' => 'مؤجلة'];

// 1. دالة عامة للبحث عن حساب محاسبي مطابق لكلمات مفتاحية محددة (صندوق / ذمم / إيرادات...)
//    أو إنشائه تلقائياً إن لم يوجد، لضمان توفر حسابَين منفصلَين لطرفي القيد المزدوج (مدين/دائن)
//    ومنع دمجهما في حساب واحد كما كان يحدث سابقاً (وهو ما كان يُسبب غياب طرف الدائن بالكامل).
function findAccountId($conn, array $keywords, string $fallback_name) {
    try {
        // معرفة أعمدة جدول accounts ديناميكياً
        $stmt_cols = $conn->query("SHOW COLUMNS FROM accounts");
        $cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

        $name_col = null;
        foreach (['name', 'account_name', 'title', 'name_ar', 'acc_name'] as $c) {
            if (in_array($c, $cols)) {
                $name_col = $c;
                break;
            }
        }

        // البحث عن حساب يطابق إحدى الكلمات المفتاحية المُمرَّرة
        if ($name_col && count($keywords) > 0) {
            $conditions = implode(' OR ', array_fill(0, count($keywords), "`{$name_col}` LIKE ?"));
            $params = array_map(fn($k) => "%{$k}%", $keywords);
            $stmt = $conn->prepare("SELECT id FROM accounts WHERE {$conditions} ORDER BY id ASC LIMIT 1");
            $stmt->execute($params);
            $acc_id = $stmt->fetchColumn();
            if ($acc_id) {
                return $acc_id;
            }
        }

        // إن لم يوجد: إنشاء الحساب تلقائياً بالاسم الاحتياطي المُمرَّر (منعاً لخطأ Foreign Key 1452)
        $target_col = $name_col ?: ($cols[1] ?? 'name');
        $conn->exec("INSERT INTO accounts (`{$target_col}`) VALUES (" . $conn->quote($fallback_name) . ")");
        return $conn->lastInsertId();

    } catch (Exception $e) {
        return null;
    }
}

// جلب أحدث سعر صرف للدولار (موحَّد الآن عبر functions.php بدل استعلام مكرر بقيمة احتياطية مختلفة)
$default_exchange_rate = getExchangeRateForDate($conn, 'USD', date('Y-m-d'));

// إضافة مندوب جديد مباشرة من نافذة إصدار الفاتورة — بدون الحاجة للخروج إلى representatives.php
$newly_added_rep_id = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_representative_inline'])) {
    $new_rep_name = trim($_POST['new_rep_name'] ?? '');
    $new_rep_phone = trim($_POST['new_rep_phone'] ?? '');
    if (empty($new_rep_name)) {
        $error = "خطأ: اسم المندوب لا يمكن أن يكون فارغاً.";
    } else {
        try {
            $conn->prepare("INSERT INTO representatives (name, phone, email, notes) VALUES (?, ?, '', '')")->execute([$new_rep_name, $new_rep_phone]);
            $newly_added_rep_id = $conn->lastInsertId();
            logAudit($conn, 'INSERT', 'المندوبون', "إضافة مندوب جديد من صفحة المبيعات: $new_rep_name", $newly_added_rep_id);
            $msg = "تمت إضافة المندوب \"$new_rep_name\" بنجاح — تم اختياره تلقائياً في الفاتورة.";
        } catch (Exception $e) {
            $error = "خطأ أثناء إضافة المندوب: " . $e->getMessage();
        }
    }
}

// معالجة حفظ فاتورة مبيعات جديدة
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_sale'])) {
    requireRole($conn, ['admin', 'accountant']);
    $invoice_number = trim($_POST['invoice_number']);
    $customer_name = trim($_POST['customer_name']);
    // تصحيح: قيمة 0 ("بدون مندوب") ليست معرِّف مندوب حقيقياً — يجب تحويلها لـ NULL وإلا يرفض قيد
    // Foreign Key العملية بالكامل (لأن 0 لا يُطابق أي صف موجود فعلياً في جدول representatives)
    $representative_id = intval($_POST['representative_id'] ?? 0);
    if ($representative_id <= 0) { $representative_id = null; }
    $exchange_rate = floatval($_POST['exchange_rate']);
    $payment_status = trim($_POST['payment_status']);
    $delivery_status = trim($_POST['delivery_status']);
    $invoice_date = $_POST['invoice_date'];
    $shipping_cost_syp = floatval($_POST['shipping_cost_syp'] ?? 0);
    $delivery_type = trim($_POST['delivery_type'] ?? '');
    if (!in_array($delivery_type, ['شحن', 'توصيل'])) { $delivery_type = null; }

    // تحقق دفاعي: اقبل فقط القيم المطابقة لتعريف ENUM الفعلي، وإلا استخدم القيمة الافتراضية
    if (!array_key_exists($payment_status, $payment_labels)) {
        $payment_status = 'Unpaid';
    }
    if (!array_key_exists($delivery_status, $delivery_labels)) {
        $delivery_status = 'Pending';
    }
    
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price_syp'] ?? [];
    $commissions = $_POST['commission_amount'] ?? [];

    if (empty($invoice_number) || empty($customer_name) || count($product_ids) == 0) {
        $error = "خطأ: يرجى إدخال رقم الفاتورة، اسم العميل، وصنف واحد على الأقل.";
    } elseif (isDateInClosedPeriod($conn, $invoice_date)) {
        $error = getPeriodLockErrorMessage($invoice_date);
    } else {
        try {
            $conn->beginTransaction();

            $total_syp = 0;
            $total_comm = 0;
            $items_data = [];

            for ($i = 0; $i < count($product_ids); $i++) {
                $p_id = intval($product_ids[$i]);
                $qty = floatval($quantities[$i]);
                $price = floatval($prices[$i]);
                $comm_per_piece = floatval($commissions[$i] ?? 0);

                if ($p_id > 0 && $qty > 0) {
                    $item_total = $qty * $price;
                    $item_comm_total = $qty * $comm_per_piece;

                    $total_syp += $item_total;
                    $total_comm += $item_comm_total;

                    // تصحيح دقة COGS: نلتقط تكلفة المنتج الحالية *وقت البيع بالضبط* بدل الاعتماد
                    // لاحقاً على تكلفة المنتج "الحالية" في وقت التقرير (والتي قد تتغيّر لاحقاً بأثر رجعي)
                    $stmt_cost = $conn->prepare("SELECT cost_price_usd FROM products WHERE id = ?");
                    $stmt_cost->execute([$p_id]);
                    $cost_at_sale = floatval($stmt_cost->fetchColumn());

                    $items_data[] = [
                        'product_id' => $p_id,
                        'qty' => $qty,
                        'price' => $price,
                        'total' => $item_total,
                        'cost_at_sale' => $cost_at_sale,
                        'comm_per_unit' => $comm_per_piece
                    ];
                }
            }

            $total_usd = $exchange_rate > 0 ? ($total_syp / $exchange_rate) : 0;

            // إدخال الفاتورة الرئيسية
            $stmt = $conn->prepare("INSERT INTO sales (invoice_number, customer_name, representative_id, exchange_rate, total_amount_syp, total_amount_usd, total_commissions, payment_status, delivery_status, invoice_date, shipping_cost_syp, delivery_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoice_number, $customer_name, $representative_id, $exchange_rate, $total_syp, $total_usd, $total_comm, $payment_status, $delivery_status, $invoice_date, $shipping_cost_syp, $delivery_type]);
            $sale_id = $conn->lastInsertId();

            // إدخال الأصناف وتعديل المخزون
            // تصحيح: يُخزَّن الآن cost_price_usd_at_sale (تكلفة تاريخية ثابتة) و commission_per_unit (عمولة دقيقة
            // للقطعة) في كل سطر — بدل الاعتماد على قيم "حالية" متغيّرة لاحقاً في التقارير والمرتجعات.
            foreach ($items_data as $it) {
                $stmt_item = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price_syp, total_price_syp, cost_price_usd_at_sale, commission_per_unit) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_item->execute([$sale_id, $it['product_id'], $it['qty'], $it['price'], $it['total'], $it['cost_at_sale'], $it['comm_per_unit']]);

                $stmt_stock = $conn->prepare("UPDATE products SET current_quantity = current_quantity - ? WHERE id = ?");
                $stmt_stock->execute([$it['qty'], $it['product_id']]);
            }

            // تسجيل عمولة المندوب
            if ($representative_id > 0 && $total_comm > 0) {
                $stmt_rep = $conn->prepare("INSERT INTO representative_transactions (representative_id, transaction_type, amount, notes, transaction_date) VALUES (?, 'commission', ?, ?, ?)");
                $stmt_rep->execute([$representative_id, $total_comm, "عمولة فاتورة مبيعات رقم: " . $invoice_number, $invoice_date]);
            }

            // --- معالجة قيد الحسابات: طرفان متوازنان (مدين ودائن) وليس طرفاً واحداً ---
            $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
            $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

            $journal_desc = "قيد فاتورة مبيعات رقم: " . $invoice_number . " للعميل: " . $customer_name;
            $entry_num = "JE-" . $invoice_number;

            // طرف المدين: نقدية (الصندوق) إن كانت الفاتورة مدفوعة فوراً، أو ذمم عملاء إن كانت آجلة/جزئية
            if ($payment_status === 'Paid') {
                $debit_account_id = findAccountId($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');
            } else {
                $debit_account_id = findAccountId($conn, ['عملاء', 'receivable'], 'ذمم العملاء');
            }

            // === إصلاح معماري جوهري: توقيت الاعتراف بالإيراد ===
            // طرف الدائن يعتمد الآن على حالة التسليم وقت إصدار الفاتورة:
            // - "تم التسليم" مباشرة: دائن إيرادات المبيعات الحقيقية (اعتراف فوري، مطابق لما كان سابقاً).
            // - "قيد الانتظار/مؤجلة": دائن "إيرادات مؤجلة" (التزام مؤقت)، ولا يُعترَف بالإيراد الحقيقي
            //   ولا COGS ولا عمولة المندوب إلا لاحقاً عند تأكيد التسليم فعلياً (عبر recognizeSaleRevenue()).
            //   هذا يمنع تسجيل ربح غير محقق لفواتير قد تُرتجَع بالكامل قبل التسليم أصلاً.
            if ($delivery_status === 'Delivered') {
                $credit_account_id = findAccountId($conn, ['إيرادات المبيعات', 'مبيعات', 'sales revenue'], 'إيرادات المبيعات');
            } else {
                $credit_account_id = findAccountId($conn, ['إيرادات مؤجلة', 'مؤجل', 'deferred'], 'إيرادات مؤجلة');
            }

            if ($debit_account_id && $credit_account_id && in_array('account_id', $existing_cols)
                && in_array('debit', $existing_cols) && in_array('credit', $existing_cols)) {

                // دالة مساعدة محلية لبناء وتنفيذ سطر قيد واحد (مدين أو دائن) مع الأعمدة الاختيارية المتوفرة
                $insertJournalLine = function ($account_id, $debit_amt, $credit_amt) use ($conn, $existing_cols, $entry_num, $invoice_date, $journal_desc, $exchange_rate, $invoice_number) {
                    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                    $vals = [$account_id, $invoice_date, $journal_desc, $debit_amt, $credit_amt];

                    if (in_array('entry_number', $existing_cols)) {
                        $cols_to_insert[] = 'entry_number';
                        $vals[] = $entry_num;
                    }
                    if (in_array('currency_code', $existing_cols)) {
                        $cols_to_insert[] = 'currency_code';
                        $vals[] = 'SYP';
                    }
                    if (in_array('exchange_rate', $existing_cols)) {
                        $cols_to_insert[] = 'exchange_rate';
                        $vals[] = $exchange_rate;
                    }
                    if (in_array('source_module', $existing_cols)) {
                        $cols_to_insert[] = 'source_module';
                        $vals[] = 'Sales';
                    }
                    if (in_array('reference', $existing_cols)) {
                        $cols_to_insert[] = 'reference';
                        $vals[] = $invoice_number;
                    }

                    $placeholders = implode(',', array_fill(0, count($cols_to_insert), '?'));
                    $col_names = implode(',', $cols_to_insert);

                    $stmt_j = $conn->prepare("INSERT INTO journal_entries ({$col_names}) VALUES ({$placeholders})");
                    $stmt_j->execute($vals);
                };

                // السطر الأول: مدين (نقدية أو ذمم) بكامل قيمة الفاتورة — يُرحَّل فوراً بغض النظر عن حالة
                // التسليم، لأن استحقاق المبلغ من العميل (نقداً أو ديناً) يحدث فعلياً لحظة الفاتورة.
                $insertJournalLine($debit_account_id, $total_syp, 0);
                // السطر الثاني: دائن (إيرادات حقيقية أو مؤجلة حسب الحالة) بنفس القيمة لضمان توازن القيد
                $insertJournalLine($credit_account_id, 0, $total_syp);

                // قيد تكلفة الشحن (إن أُدخلت) — مدين تكاليف الشحن (مصروف) / دائن الصندوق الرئيسي (نقداً)
                // منفصل تماماً عن قيد الفاتورة الرئيسي، برقم قيد خاص به لسهولة العكس عند التعديل لاحقاً.
                if ($shipping_cost_syp > 0) {
                    $shipping_expense_id = ensureShippingExpenseAccount($conn);
                    $shipping_cash_id = findAccountId($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');
                    if ($shipping_expense_id && $shipping_cash_id) {
                        $ship_entry_num = "JE-" . $invoice_number . "-SHIP";
                        $ship_desc = "تكلفة شحن فاتورة مبيعات رقم: " . $invoice_number;
                        $insertShippingLine = function ($account_id, $debit_amt, $credit_amt) use ($conn, $existing_cols, $ship_entry_num, $invoice_date, $ship_desc, $exchange_rate) {
                            $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                            $vals = [$account_id, $invoice_date, $ship_desc, $debit_amt, $credit_amt];
                            if (in_array('entry_number', $existing_cols)) { $cols_to_insert[] = 'entry_number'; $vals[] = $ship_entry_num; }
                            if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'SYP'; }
                            if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $exchange_rate; }
                            if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Sales'; }
                            $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                            $cn = implode(',', $cols_to_insert);
                            $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                        };
                        $insertShippingLine($shipping_expense_id, $shipping_cost_syp, 0);
                        $insertShippingLine($shipping_cash_id, 0, $shipping_cost_syp);
                    }
                }
            }

            // إن كانت الفاتورة "مُسلَّمة" منذ لحظة الإصدار مباشرة، اعترف بـCOGS والعمولة فوراً أيضاً
            // (نفس الدالة المُستخدَمة لاحقاً عند تأكيد تسليم فاتورة كانت "قيد الانتظار" — منطق موحَّد بلا تكرار)
            if ($delivery_status === 'Delivered') {
                recognizeSaleRevenue($conn, $sale_id);
            }

            $conn->commit();
            $msg = "تم حفظ وترحيل فاتورة المبيعات والقيد المحاسبي بنجاح!";
            logAudit($conn, 'INSERT', 'فواتير المبيعات', "إنشاء فاتورة مبيعات رقم $invoice_number للعميل: $customer_name بقيمة " . number_format($total_syp, 2) . " ل.س", $sale_id);
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "<strong>خطأ أثناء الحفظ:</strong> " . $e->getMessage();
        }
    }
}

// تعديل البيانات للفواتير ضمن الشهر الحالي
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_sale'])) {
    requireRole($conn, ['admin', 'accountant']);
    $sale_id = intval($_POST['sale_id']);
    $customer_name = trim($_POST['customer_name']);
    $representative_id = intval($_POST['representative_id'] ?? 0);
    if ($representative_id <= 0) { $representative_id = null; }
    $payment_status = trim($_POST['payment_status']);
    $delivery_status = trim($_POST['delivery_status']);
    $shipping_cost_syp = floatval($_POST['shipping_cost_syp'] ?? 0);
    $delivery_type = trim($_POST['delivery_type'] ?? '');
    if (!in_array($delivery_type, ['شحن', 'توصيل'])) { $delivery_type = null; }

    // تحقق دفاعي: اقبل فقط القيم المطابقة لتعريف ENUM الفعلي
    if (!array_key_exists($payment_status, $payment_labels)) {
        $payment_status = 'Unpaid';
    }
    if (!array_key_exists($delivery_status, $delivery_labels)) {
        $delivery_status = 'Pending';
    }

    $stmt_check = $conn->prepare("SELECT * FROM sales WHERE id = ?");
    $stmt_check->execute([$sale_id]);
    $old_sale = $stmt_check->fetch(PDO::FETCH_ASSOC);
    $inv_date_orig = $old_sale['invoice_date'] ?? null;

    // هل لهذه الفاتورة أي مرتجع مسجَّل مسبقاً؟ إن كان كذلك، لا يُسمَح بتعديل الأصناف/رقم الفاتورة/
    // تاريخها/سعر صرفها — لأن ذلك سيُبطل مرجعية المرتجعات المسجَّلة (sale_item_id) ويكسر تتبعها.
    // تبقى الحقول الأخرى (العميل، المندوب، حالتا الدفع/التسليم، الشحن) قابلة للتعديل دائماً كما كانت.
    $stmt_has_ret = $conn->prepare("
        SELECT COUNT(*) FROM sales_return_items sri
        INNER JOIN sale_items si ON sri.sale_item_id = si.id
        WHERE si.sale_id = ?
    ");
    $stmt_has_ret->execute([$sale_id]);
    $has_returns = $stmt_has_ret->fetchColumn() > 0;

    $full_edit_requested = isset($_POST['product_id']) && is_array($_POST['product_id']) && count($_POST['product_id']) > 0;

    if (!$old_sale) {
        $error = "الفاتورة غير موجودة.";
    } elseif (isDateInClosedPeriod($conn, $inv_date_orig)) {
        $error = getPeriodLockErrorMessage($inv_date_orig);
    } elseif ($has_returns && $full_edit_requested) {
        $error = "لا يمكن تعديل أصناف/رقم/تاريخ فاتورة لها مرتجع مسجَّل بالفعل — استخدم شاشة المرتجع بدلاً من ذلك. باقي الحقول (العميل، المندوب، حالتا الدفع والتسليم، الشحن) قابلة للتعديل عبر نموذج التعديل المبسَّط.";
    } else {
        try {
            $conn->beginTransaction();

            $old_delivery_status = $old_sale['delivery_status'];
            $old_payment_status = $old_sale['payment_status'];
            $old_shipping_cost = floatval($old_sale['shipping_cost_syp'] ?? 0);
            $old_invoice_number = $old_sale['invoice_number'];
            $inv_date = $inv_date_orig;

            if ($full_edit_requested && !$has_returns) {
                // ==================== التعديل الكامل: رقم/تاريخ الفاتورة + سعر الصرف + الأصناف ====================
                $new_invoice_number = trim($_POST['invoice_number']);
                $new_invoice_date = $_POST['invoice_date'];
                $new_exchange_rate = floatval($_POST['exchange_rate']);
                if (empty($new_invoice_number)) { throw new Exception("رقم الفاتورة لا يمكن أن يكون فارغاً."); }
                if (isDateInClosedPeriod($conn, $new_invoice_date)) { throw new Exception(getPeriodLockErrorMessage($new_invoice_date)); }

                $product_ids = $_POST['product_id'] ?? [];
                $quantities = $_POST['quantity'] ?? [];
                $prices = $_POST['unit_price_syp'] ?? [];
                $commissions = $_POST['commission_amount'] ?? [];

                // 1) استرجاع كل الكميات القديمة للمخزون قبل حذف الأصناف القديمة
                $stmt_old_items = $conn->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
                $stmt_old_items->execute([$sale_id]);
                foreach ($stmt_old_items->fetchAll(PDO::FETCH_ASSOC) as $oi) {
                    $conn->prepare("UPDATE products SET current_quantity = current_quantity + ? WHERE id = ?")->execute([$oi['quantity'], $oi['product_id']]);
                }
                $conn->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$sale_id]);

                // 2) حذف كل القيود القديمة المرتبطة بهذه الفاتورة (بكل امتداداتها) وحركة عمولة المندوب القديمة
                $conn->prepare("DELETE FROM journal_entries WHERE entry_number LIKE ?")->execute(["JE-" . $old_invoice_number . "%"]);
                $conn->prepare("DELETE FROM representative_transactions WHERE notes LIKE ? AND transaction_type IN ('commission','commission_reversal')")->execute(["%" . $old_invoice_number . "%"]);

                // 3) إعادة بناء الأصناف من جديد (بنفس منطق إضافة فاتورة جديدة تماماً)
                $total_syp = 0;
                $total_comm = 0;
                $items_data = [];
                for ($i = 0; $i < count($product_ids); $i++) {
                    $p_id = intval($product_ids[$i]);
                    $qty = floatval($quantities[$i]);
                    $price = floatval($prices[$i]);
                    $comm_per_piece = floatval($commissions[$i] ?? 0);
                    if ($p_id > 0 && $qty > 0) {
                        $item_total = $qty * $price;
                        $total_syp += $item_total;
                        $total_comm += $qty * $comm_per_piece;
                        $stmt_cost = $conn->prepare("SELECT cost_price_usd FROM products WHERE id = ?");
                        $stmt_cost->execute([$p_id]);
                        $cost_at_sale = floatval($stmt_cost->fetchColumn());
                        $items_data[] = ['product_id' => $p_id, 'qty' => $qty, 'price' => $price, 'total' => $item_total, 'cost_at_sale' => $cost_at_sale, 'comm_per_unit' => $comm_per_piece];
                    }
                }
                if (count($items_data) == 0) { throw new Exception("يجب إدخال صنف واحد على الأقل."); }
                $total_usd = $new_exchange_rate > 0 ? ($total_syp / $new_exchange_rate) : 0;

                foreach ($items_data as $it) {
                    $conn->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price_syp, total_price_syp, cost_price_usd_at_sale, commission_per_unit) VALUES (?, ?, ?, ?, ?, ?, ?)")
                         ->execute([$sale_id, $it['product_id'], $it['qty'], $it['price'], $it['total'], $it['cost_at_sale'], $it['comm_per_unit']]);
                    $conn->prepare("UPDATE products SET current_quantity = current_quantity - ? WHERE id = ?")->execute([$it['qty'], $it['product_id']]);
                }

                if ($representative_id > 0 && $total_comm > 0) {
                    $conn->prepare("INSERT INTO representative_transactions (representative_id, transaction_type, amount, notes, transaction_date) VALUES (?, 'commission', ?, ?, ?)")
                         ->execute([$representative_id, $total_comm, "عمولة فاتورة مبيعات رقم: " . $new_invoice_number . " (مُعدَّلة)", $new_invoice_date]);
                }

                $stmt_up = $conn->prepare("UPDATE sales SET invoice_number = ?, customer_name = ?, representative_id = ?, exchange_rate = ?, total_amount_syp = ?, total_amount_usd = ?, total_commissions = ?, payment_status = ?, delivery_status = ?, invoice_date = ?, shipping_cost_syp = ?, delivery_type = ? WHERE id = ?");
                $stmt_up->execute([$new_invoice_number, $customer_name, $representative_id, $new_exchange_rate, $total_syp, $total_usd, $total_comm, $payment_status, $delivery_status, $new_invoice_date, $shipping_cost_syp, $delivery_type, $sale_id]);

                // 4) إعادة ترحيل القيد الرئيسي من الصفر (نفس منطق إضافة فاتورة جديدة تماماً)
                $stmt_cols3 = $conn->query("SHOW COLUMNS FROM journal_entries");
                $existing_cols3 = $stmt_cols3->fetchAll(PDO::FETCH_COLUMN);
                $journal_desc3 = "قيد فاتورة مبيعات رقم: " . $new_invoice_number . " للعميل: " . $customer_name . " (مُعدَّلة)";
                $entry_num3 = "JE-" . $new_invoice_number;

                if ($payment_status === 'Paid') {
                    $debit_account_id3 = findAccountId($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');
                } else {
                    $debit_account_id3 = findAccountId($conn, ['عملاء', 'receivable'], 'ذمم العملاء');
                }
                if ($delivery_status === 'Delivered') {
                    $credit_account_id3 = findAccountId($conn, ['إيرادات المبيعات', 'مبيعات', 'sales revenue'], 'إيرادات المبيعات');
                } else {
                    $credit_account_id3 = findAccountId($conn, ['إيرادات مؤجلة', 'مؤجل', 'deferred'], 'إيرادات مؤجلة');
                }

                if ($debit_account_id3 && $credit_account_id3 && in_array('account_id', $existing_cols3)) {
                    $insertLine3 = function ($account_id, $debit_amt, $credit_amt) use ($conn, $existing_cols3, $entry_num3, $new_invoice_date, $journal_desc3, $new_exchange_rate, $new_invoice_number) {
                        $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                        $vals = [$account_id, $new_invoice_date, $journal_desc3, $debit_amt, $credit_amt];
                        if (in_array('entry_number', $existing_cols3)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_num3; }
                        if (in_array('currency_code', $existing_cols3)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'SYP'; }
                        if (in_array('exchange_rate', $existing_cols3)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $new_exchange_rate; }
                        if (in_array('source_module', $existing_cols3)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Sales'; }
                        if (in_array('reference', $existing_cols3)) { $cols_to_insert[] = 'reference'; $vals[] = $new_invoice_number; }
                        $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                        $cn = implode(',', $cols_to_insert);
                        $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                    };
                    $insertLine3($debit_account_id3, $total_syp, 0);
                    $insertLine3($credit_account_id3, 0, $total_syp);

                    if ($shipping_cost_syp > 0) {
                        $shipping_expense_id3 = ensureShippingExpenseAccount($conn);
                        $shipping_cash_id3 = findAccountId($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');
                        if ($shipping_expense_id3 && $shipping_cash_id3) {
                            $ship_entry_num3 = "JE-" . $new_invoice_number . "-SHIP";
                            $ship_desc3 = "تكلفة شحن فاتورة مبيعات رقم: " . $new_invoice_number;
                            $insertShip3 = function ($account_id, $debit_amt, $credit_amt) use ($conn, $existing_cols3, $ship_entry_num3, $new_invoice_date, $ship_desc3) {
                                $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                                $vals = [$account_id, $new_invoice_date, $ship_desc3, $debit_amt, $credit_amt];
                                if (in_array('entry_number', $existing_cols3)) { $cols_to_insert[] = 'entry_number'; $vals[] = $ship_entry_num3; }
                                if (in_array('currency_code', $existing_cols3)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'SYP'; }
                                if (in_array('source_module', $existing_cols3)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Sales'; }
                                $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                                $cn = implode(',', $cols_to_insert);
                                $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                            };
                            $insertShip3($shipping_expense_id3, $shipping_cost_syp, 0);
                            $insertShip3($shipping_cash_id3, 0, $shipping_cost_syp);
                        }
                    }
                }

                if ($delivery_status === 'Delivered') {
                    recognizeSaleRevenue($conn, $sale_id);
                }

                $msg = "تم تحديث الفاتورة بالكامل (رقمها، تاريخها، أصنافها، وقيودها المحاسبية) بنجاح!";
                $audit_msg = "تعديل شامل لفاتورة رقم #{$sale_id} ({$old_invoice_number} → {$new_invoice_number})";
            } else {
                // ==================== التعديل المبسَّط (كما كان سابقاً) — للفواتير ذات المرتجعات ====================
                $stmt_up = $conn->prepare("UPDATE sales SET customer_name = ?, representative_id = ?, payment_status = ?, delivery_status = ?, shipping_cost_syp = ?, delivery_type = ? WHERE id = ?");
                $stmt_up->execute([$customer_name, $representative_id, $payment_status, $delivery_status, $shipping_cost_syp, $delivery_type, $sale_id]);

                if (abs($shipping_cost_syp - $old_shipping_cost) > 0.001) {
                    $ship_entry_num = "JE-" . $old_invoice_number . "-SHIP";
                    $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ?")->execute([$ship_entry_num]);
                    if ($shipping_cost_syp > 0) {
                        $stmt_cols2 = $conn->query("SHOW COLUMNS FROM journal_entries");
                        $existing_cols2 = $stmt_cols2->fetchAll(PDO::FETCH_COLUMN);
                        $shipping_expense_id = ensureShippingExpenseAccount($conn);
                        $shipping_cash_id = findAccountId($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');
                        if ($shipping_expense_id && $shipping_cash_id) {
                            $ship_desc = "تكلفة شحن فاتورة مبيعات رقم: " . $old_invoice_number . " (مُعدَّلة)";
                            $insertShippingLine2 = function ($account_id, $debit_amt, $credit_amt) use ($conn, $existing_cols2, $ship_entry_num, $inv_date, $ship_desc) {
                                $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                                $vals = [$account_id, $inv_date, $ship_desc, $debit_amt, $credit_amt];
                                if (in_array('entry_number', $existing_cols2)) { $cols_to_insert[] = 'entry_number'; $vals[] = $ship_entry_num; }
                                if (in_array('currency_code', $existing_cols2)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'SYP'; }
                                if (in_array('source_module', $existing_cols2)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Sales'; }
                                $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                                $cn = implode(',', $cols_to_insert);
                                $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                            };
                            $insertShippingLine2($shipping_expense_id, $shipping_cost_syp, 0);
                            $insertShippingLine2($shipping_cash_id, 0, $shipping_cost_syp);
                        }
                    }
                }

                if ($old_delivery_status !== 'Delivered' && $delivery_status === 'Delivered') {
                    recognizeSaleRevenue($conn, $sale_id);
                } elseif ($old_delivery_status === 'Delivered' && $delivery_status !== 'Delivered') {
                    deferSaleRevenue($conn, $sale_id);
                }

                // === تصحيح: قيد تحصيل حقيقي عند تغيّر حالة الدفع، مؤرَّخ بيوم التغيير الفعلي ===
                // سابقاً: تغيير حالة الدفع من "آجل" إلى "نقداً" عبر التعديل كان يُحدِّث الحقل في جدول
                // sales فقط بلا أي أثر محاسبي — فلا يظهر المبلغ في "إجمالي المقبوضات" اليومي أبداً، رغم
                // أن النقدية دخلت الصندوق فعلياً في يوم التعديل هذا (لا يوم إصدار الفاتورة الأصلي).
                if ($old_payment_status !== 'Paid' && $payment_status === 'Paid') {
                    $collect_cash_id = findAccountId($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');
                    $collect_recv_id = findAccountId($conn, ['عملاء', 'receivable'], 'ذمم العملاء');
                    if ($collect_cash_id && $collect_recv_id) {
                        $collect_entry_num = "JE-" . $old_invoice_number . "-COLLECT-" . time();
                        $collect_desc = "تحصيل نقدي لفاتورة رقم: " . $old_invoice_number . " (تغيير حالة الدفع إلى نقداً)";
                        postJournalLine($conn, $collect_cash_id, floatval($old_sale['total_amount_syp']), 0, $collect_entry_num, date('Y-m-d'), $collect_desc, 'Payment Collection');
                        postJournalLine($conn, $collect_recv_id, 0, floatval($old_sale['total_amount_syp']), $collect_entry_num, date('Y-m-d'), $collect_desc, 'Payment Collection');
                    }
                } elseif ($old_payment_status === 'Paid' && $payment_status !== 'Paid') {
                    // عكس تحصيل بالخطأ: التراجع عن تعليم فاتورة كانت "مدفوعة" لتصبح "آجلة" مرة أخرى
                    $collect_cash_id = findAccountId($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');
                    $collect_recv_id = findAccountId($conn, ['عملاء', 'receivable'], 'ذمم العملاء');
                    if ($collect_cash_id && $collect_recv_id) {
                        $collect_entry_num = "JE-" . $old_invoice_number . "-UNCOLLECT-" . time();
                        $collect_desc = "عكس تحصيل نقدي لفاتورة رقم: " . $old_invoice_number . " (تراجع عن حالة الدفع نقداً)";
                        postJournalLine($conn, $collect_recv_id, floatval($old_sale['total_amount_syp']), 0, $collect_entry_num, date('Y-m-d'), $collect_desc, 'Payment Collection');
                        postJournalLine($conn, $collect_cash_id, 0, floatval($old_sale['total_amount_syp']), $collect_entry_num, date('Y-m-d'), $collect_desc, 'Payment Collection');
                    }
                }

                $msg = "تم تحديث بيانات الفاتورة بنجاح!" . ($has_returns ? " (التعديل الكامل للأصناف معطَّل لوجود مرتجع مسجَّل على هذه الفاتورة)" : "");
                $audit_msg = "تعديل فاتورة رقم #{$sale_id} — العميل: {$customer_name}، حالة الدفع: {$payment_status}، حالة التسليم: {$delivery_status}";
            }

            $conn->commit();
            logAudit($conn, 'UPDATE', 'فواتير المبيعات', $audit_msg, $sale_id);
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ في التحديث: " . $e->getMessage();
        }
    }
}

// ============================================================
// وحدة مرتجعات المبيعات (Credit Notes) — البند 9
// ============================================================
$conn->exec("CREATE TABLE IF NOT EXISTS sales_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    return_date DATE NOT NULL,
    total_amount_syp DECIMAL(15,2) NOT NULL,
    total_commission_reversed DECIMAL(15,2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->exec("CREATE TABLE IF NOT EXISTS sales_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sales_return_id INT NOT NULL,
    sale_item_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    unit_price_syp DECIMAL(15,2) NOT NULL,
    total_price_syp DECIMAL(15,2) NOT NULL
)");

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_return'])) {
    requireRole($conn, ['admin', 'accountant']);

    $ret_sale_id = intval($_POST['return_sale_id']);
    $return_date = $_POST['return_date'];
    $ret_notes = trim($_POST['return_notes']);
    $sale_item_ids = $_POST['ret_sale_item_id'] ?? [];
    $ret_quantities = $_POST['ret_quantity'] ?? [];

    if ($ret_sale_id <= 0 || count($sale_item_ids) == 0) {
        $error = "خطأ: يرجى اختيار فاتورة وكمية مرتجعة واحدة على الأقل.";
    } elseif (isDateInClosedPeriod($conn, $return_date)) {
        $error = getPeriodLockErrorMessage($return_date);
    } else {
        try {
            $conn->beginTransaction();

            $stmt_sale = $conn->prepare("SELECT * FROM sales WHERE id = ?");
            $stmt_sale->execute([$ret_sale_id]);
            $sale = $stmt_sale->fetch(PDO::FETCH_ASSOC);
            if (!$sale) { throw new Exception("الفاتورة غير موجودة."); }

            $total_return_amount = 0;
            $return_lines = [];

            for ($i = 0; $i < count($sale_item_ids); $i++) {
                $sale_item_id = intval($sale_item_ids[$i]);
                $ret_qty = floatval($ret_quantities[$i] ?? 0);
                if ($ret_qty <= 0) { continue; }

                $stmt_item = $conn->prepare("SELECT * FROM sale_items WHERE id = ? AND sale_id = ?");
                $stmt_item->execute([$sale_item_id, $ret_sale_id]);
                $item = $stmt_item->fetch(PDO::FETCH_ASSOC);
                if (!$item) { continue; }

                // التحقق أن الكمية المرتجعة لا تتجاوز (الكمية الأصلية - ما أُعيد سابقاً)
                $stmt_already = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM sales_return_items WHERE sale_item_id = ?");
                $stmt_already->execute([$sale_item_id]);
                $already_returned = floatval($stmt_already->fetchColumn());
                $max_returnable = floatval($item['quantity']) - $already_returned;

                if ($ret_qty > $max_returnable) {
                    throw new Exception("الكمية المرتجعة لصنف (معرف #$sale_item_id) تتجاوز المتاح للإرجاع ($max_returnable).");
                }

                $line_total = $ret_qty * floatval($item['unit_price_syp']);
                $total_return_amount += $line_total;

                // تصحيح دقة: عمولة القطعة الفعلية المخزَّنة وقت البيع (commission_per_unit) — إن توفرت —
                // تُستخدم لحساب العمولة المرتجعة بدقة 100% لهذا الصنف تحديداً، بدل توزيع تناسبي تقريبي
                // على مستوى الفاتورة كاملة. للفواتير القديمة السابقة لهذا التصحيح (العمود NULL)، نُعلِّم
                // السطر لاستخدام التوزيع التناسبي الاحتياطي كما كان سابقاً (fallback دقيق قدر الإمكان).
                $line_commission = null;
                if ($item['commission_per_unit'] !== null) {
                    $line_commission = $ret_qty * floatval($item['commission_per_unit']);
                }

                $return_lines[] = [
                    'sale_item_id' => $sale_item_id, 'product_id' => $item['product_id'], 'qty' => $ret_qty,
                    'price' => $item['unit_price_syp'], 'total' => $line_total, 'exact_commission' => $line_commission,
                    'cost_at_sale' => $item['cost_price_usd_at_sale']
                ];
            }

            if (count($return_lines) == 0) {
                throw new Exception("لم تُدخَل أي كمية مرتجعة صحيحة.");
            }

            // احتساب العمولة المرتجعة: دقيقة 100% للأسطر التي لها commission_per_unit مسجَّل فعلياً،
            // وتناسبية (تقديرية) فقط للأسطر القديمة السابقة لهذا التصحيح التي تفتقد هذه القيمة.
            $commission_reversed = 0;
            $fallback_needed_amount = 0;
            foreach ($return_lines as $rl) {
                if ($rl['exact_commission'] !== null) {
                    $commission_reversed += $rl['exact_commission'];
                } else {
                    $fallback_needed_amount += $rl['total'];
                }
            }
            if ($fallback_needed_amount > 0 && floatval($sale['total_amount_syp']) > 0 && floatval($sale['total_commissions']) > 0) {
                $commission_reversed += ($fallback_needed_amount / floatval($sale['total_amount_syp'])) * floatval($sale['total_commissions']);
            }
            $commission_reversed = round($commission_reversed, 2);

            $stmt_ret = $conn->prepare("INSERT INTO sales_returns (sale_id, return_date, total_amount_syp, total_commission_reversed, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt_ret->execute([$ret_sale_id, $return_date, $total_return_amount, $commission_reversed, $ret_notes]);
            $return_id = $conn->lastInsertId();

            foreach ($return_lines as $line) {
                $conn->prepare("INSERT INTO sales_return_items (sales_return_id, sale_item_id, product_id, quantity, unit_price_syp, total_price_syp) VALUES (?, ?, ?, ?, ?, ?)")
                     ->execute([$return_id, $line['sale_item_id'], $line['product_id'], $line['qty'], $line['price'], $line['total']]);

                // إعادة الكمية للمخزون
                $conn->prepare("UPDATE products SET current_quantity = current_quantity + ? WHERE id = ?")->execute([$line['qty'], $line['product_id']]);
            }

            // عكس عمولة المندوب المتعلقة بالمرتجع (إن وُجدت)
            if ($sale['representative_id'] > 0 && $commission_reversed > 0) {
                $conn->prepare("INSERT INTO representative_transactions (representative_id, transaction_type, amount, notes, transaction_date) VALUES (?, 'commission_reversal', ?, ?, ?)")
                     ->execute([$sale['representative_id'], -$commission_reversed, "عكس عمولة عن مرتجع فاتورة رقم: " . $sale['invoice_number'], $return_date]);
            }

            // القيد المحاسبي: عكس جزء من الإيراد المُعترَف به أصلاً — مدين إيرادات المبيعات / دائن (نفس طرف الفاتورة الأصلي: صندوق أو ذمم عملاء)
            $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
            $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

            // === تصحيح متوافق مع الاعتراف المؤجَّل بالإيراد ===
            // إن كانت الفاتورة لا تزال "قيد الانتظار" (لم يُعترَف بإيرادها الحقيقي بعد)، فالمرتجع يجب أن
            // يعكس "الإيراد المؤجَّل" (الالتزام المؤقت)، وليس "إيرادات المبيعات" الحقيقية التي لم تُسجَّل
            // أصلاً بعد لهذه الفاتورة — وإلا سيظهر رصيد إيراد سالب وهمي مع بقاء الالتزام المؤجَّل متضخماً.
            if ($sale['delivery_status'] === 'Delivered') {
                $revenue_account_id = findAccountId($conn, ['إيرادات المبيعات', 'مبيعات', 'sales revenue'], 'إيرادات المبيعات');
            } else {
                $revenue_account_id = findAccountId($conn, ['إيرادات مؤجلة', 'مؤجل', 'deferred'], 'إيرادات مؤجلة');
            }
            if ($sale['payment_status'] === 'Paid') {
                $other_account_id = findAccountId($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');
            } else {
                $other_account_id = findAccountId($conn, ['عملاء', 'receivable'], 'ذمم العملاء');
            }

            if ($revenue_account_id && $other_account_id && in_array('account_id', $existing_cols)) {
                $entry_num = "JE-RET-" . $return_id;
                $desc = "مرتجع مبيعات على فاتورة رقم: " . $sale['invoice_number'] . (!empty($ret_notes) ? " ($ret_notes)" : "");

                $insertReturnLine = function ($account_id, $debit, $credit) use ($conn, $existing_cols, $entry_num, $return_date, $desc) {
                    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                    $vals = [$account_id, $return_date, $desc, $debit, $credit];
                    if (in_array('entry_number', $existing_cols)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_num; }
                    if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'SYP'; }
                    if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Sales Return'; }
                    $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                    $cn = implode(',', $cols_to_insert);
                    $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                };
                // مدين: إيرادات المبيعات (يُخفِّض الإيراد المسجَّل) — دائن: صندوق/ذمم عملاء (حسب نوع الفاتورة الأصلية)
                $insertReturnLine($revenue_account_id, $total_return_amount, 0);
                $insertReturnLine($other_account_id, 0, $total_return_amount);

                // === تصحيح: قيدا COGS واستحقاق العمولة يُعكسان فقط إن كانت الفاتورة "مُسلَّمة" فعلياً ===
                // (أي أنهما كانا مُعترَفاً بهما أصلاً عبر recognizeSaleRevenue). لو كانت الفاتورة لا تزال
                // "قيد الانتظار"، فلا COGS ولا عمولة سُجِّلا أصلاً، فلا معنى لعكس ما لم يُسجَّل بعد.
                if ($sale['delivery_status'] === 'Delivered') {
                    // مدين: المخزون (يعود الأصل) / دائن: تكلفة البضاعة المباعة (يُخفَّض المصروف المُسجَّل أصلاً)
                    $total_cogs_reversed_syp = 0;
                    foreach ($return_lines as $rl) {
                        if ($rl['cost_at_sale'] !== null) {
                            $total_cogs_reversed_syp += floatval($rl['qty']) * floatval($rl['cost_at_sale']) * floatval($sale['exchange_rate']);
                        }
                    }
                    if ($total_cogs_reversed_syp > 0) {
                        $cogs_expense_account_id = findAccountId($conn, ['تكلفة البضاعة', 'تكلفة البضائع', 'cogs'], 'تكلفة البضائع المباعة (COGS)');
                        $inventory_account_id = findAccountId($conn, ['مخزون', 'بضاعة', 'inventory'], 'المخزون');
                        if ($cogs_expense_account_id && $inventory_account_id) {
                            $insertReturnLine($inventory_account_id, $total_cogs_reversed_syp, 0);
                            $insertReturnLine($cogs_expense_account_id, 0, $total_cogs_reversed_syp);
                        }
                    }

                    // مدين: عمولات مندوبين مستحقة الدفع (يُخفَّض الالتزام) / دائن: مصروف عمولات المندوبين (يُخفَّض المصروف)
                    if ($sale['representative_id'] > 0 && $commission_reversed > 0) {
                        $commission_expense_account_id = findAccountId($conn, ['مصروف عمولات', 'عمولات مندوبين'], 'مصروف عمولات المندوبين');
                        $commission_payable_account_id = findAccountId($conn, ['عمولات', 'مندوب'], 'عمولات المندوبين المستحقة');
                        if ($commission_expense_account_id && $commission_payable_account_id) {
                            $insertReturnLine($commission_payable_account_id, $commission_reversed, 0);
                            $insertReturnLine($commission_expense_account_id, 0, $commission_reversed);
                        }
                    }
                }
            }

            $conn->commit();
            logAudit($conn, 'INSERT', 'مرتجعات المبيعات', "مرتجع على فاتورة رقم " . $sale['invoice_number'] . " بقيمة " . number_format($total_return_amount, 2) . " ل.س" . ($commission_reversed > 0 ? " (عمولة معكوسة: " . number_format($commission_reversed, 2) . ")" : ""), $return_id);
            $msg = "تم تسجيل المرتجع، إعادة الكمية للمخزون، وترحيل القيد المحاسبي بنجاح!";
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ أثناء تسجيل المرتجع: " . $e->getMessage();
        }
    }
}

// ============================================================
// دُمج هذا القسم مع فلتر حالة التسليم أسفل الجدول: نطاق التاريخ (اليوم/الأسبوع/مخصص) هنا هو نفسه
// المستخدَم الآن لفلترة جدول الفواتير، وكل بطاقة حالة أدناه رابط قابل للنقر يُطبِّق فلتر تلك الحالة
// تحديداً على الجدول مع الحفاظ على نفس نطاق التاريخ المختار.
$qf_period = $_GET['qf_period'] ?? 'today';
$today_str = date('Y-m-d');
if ($qf_period === 'week') {
    $qf_from = date('Y-m-d', strtotime('monday this week'));
    $qf_to = date('Y-m-d', strtotime('sunday this week'));
} elseif ($qf_period === 'custom' && !empty($_GET['qf_from']) && !empty($_GET['qf_to'])) {
    $qf_from = $_GET['qf_from'];
    $qf_to = $_GET['qf_to'];
} elseif ($qf_period === 'all') {
    $qf_from = '2000-01-01';
    $qf_to = '2100-01-01';
} else {
    $qf_period = 'today';
    $qf_from = $today_str;
    $qf_to = $today_str;
}

// ضمان وجود عمود تاريخ التسليم الفعلي (يُضاف أيضاً تلقائياً من recognizeSaleRevenue، هذا فحص دفاعي إضافي)
try {
    $sales_cols_qf = $conn->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('delivered_at', $sales_cols_qf)) {
        $conn->exec("ALTER TABLE sales ADD COLUMN delivered_at DATE NULL");
    }
} catch (Exception $e) { /* يُتجاهل إن تعذّر */ }

// تصحيح: "تم التسليم" تُصفَّى الآن بتاريخ التسليم الفعلي (delivered_at — لحظة تأكيد التسليم الحقيقية)
// بدل تاريخ إصدار الفاتورة الأصلي، حتى تظهر القطعة/المبلغ في اليوم الذي تحوَّلت فيه الحالة فعلياً وليس
// يوم الإصدار. الفواتير القديمة السابقة لهذا التصحيح (delivered_at فارغة) تعود احتياطياً لـ invoice_date
// حتى لا تختفي من الإحصائيات التاريخية. "قيد الانتظار/مؤجلة" تبقى على invoice_date (لا معنى آخر لها).
// تصحيح إضافي: تُطرَح الآن أي كمية أُرجِعت فعلياً من كل سطر — كانت البطاقات تجمع الكمية الأصلية
// المباعة فقط بلا طرح المرتجعات، فلا ينخفض العدد إطلاقاً بعد أي عملية إرجاع رغم أنها حدثت فعلاً.
$stmt_qty_delivered = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0)
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
");
$stmt_qty_delivered->execute([$qf_from, $qf_to]);
$qty_delivered = floatval($stmt_qty_delivered->fetchColumn());

$stmt_qty_pending = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0)
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    WHERE s.delivery_status = 'Pending' AND s.invoice_date BETWEEN ? AND ?
");
$stmt_qty_pending->execute([$qf_from, $qf_to]);
$qty_pending = floatval($stmt_qty_pending->fetchColumn());

$stmt_qty_deferred = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0)
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    WHERE s.delivery_status = 'Deferred' AND s.invoice_date BETWEEN ? AND ?
");
$stmt_qty_deferred->execute([$qf_from, $qf_to]);
$qty_deferred = floatval($stmt_qty_deferred->fetchColumn());

$stmt_qty_returned = $conn->prepare("
    SELECT COALESCE(SUM(sri.quantity), 0)
    FROM sales_return_items sri
    INNER JOIN sales_returns sr ON sri.sales_return_id = sr.id
    WHERE sr.return_date BETWEEN ? AND ?
");
$stmt_qty_returned->execute([$qf_from, $qf_to]);
$qty_returned = floatval($stmt_qty_returned->fetchColumn());

// المجموع الكلي: كل القطع المباعة ضمن الفترة بغض النظر عن حالة تسليمها (لا يشمل المرتجعة، فهي عملية عكسية منفصلة)
$qty_grand_total = $qty_delivered + $qty_pending + $qty_deferred;

// إجمالي المبلغ (ل.س) لكل حالة تسليم ضمن نفس الفترة — نفس مبدأ delivered_at أعلاه لحالة "تم التسليم" تحديداً
// تصحيح إضافي: يُطرَح صافي أي مرتجع فعلي حدث على هذه الفواتير (بغض النظر عن تاريخ المرتجع نفسه)،
// وإلا يبقى المبلغ المعروض هو الإجمالي الأصلي الخام رغم إرجاع جزء منه فعلياً.
$stmt_amt_delivered = $conn->prepare("
    SELECT COALESCE(SUM(s.total_amount_syp - COALESCE(ret.total_returned, 0)), 0)
    FROM sales s
    LEFT JOIN (
        SELECT sale_id, SUM(total_amount_syp) AS total_returned
        FROM sales_returns
        GROUP BY sale_id
    ) ret ON ret.sale_id = s.id
    WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
");
$stmt_amt_delivered->execute([$qf_from, $qf_to]);
$amt_delivered = floatval($stmt_amt_delivered->fetchColumn());

$stmt_amt_by_status = $conn->prepare("
    SELECT delivery_status, COALESCE(SUM(total_amount_syp), 0) AS total_syp
    FROM sales
    WHERE delivery_status IN ('Pending', 'Deferred') AND invoice_date BETWEEN ? AND ?
    GROUP BY delivery_status
");
$stmt_amt_by_status->execute([$qf_from, $qf_to]);
$amt_pending = 0; $amt_deferred = 0;
foreach ($stmt_amt_by_status->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['delivery_status'] === 'Pending') { $amt_pending = floatval($row['total_syp']); }
    elseif ($row['delivery_status'] === 'Deferred') { $amt_deferred = floatval($row['total_syp']); }
}
$amt_grand_total = $amt_delivered + $amt_pending + $amt_deferred;

$stmt_amt_returned = $conn->prepare("SELECT COALESCE(SUM(total_amount_syp), 0) FROM sales_returns WHERE return_date BETWEEN ? AND ?");
$stmt_amt_returned->execute([$qf_from, $qf_to]);
$amt_returned = floatval($stmt_amt_returned->fetchColumn());

// قيمة مبيعات "منتجات المكتب" — الأصناف التي لا تعود لمورد محدَّد (supplier_id فارغ، جرد مباشر أُدخل
// كأصل مكتبي مباشرة كما شُرح سابقاً في products.php)، ضمن نفس فترة الإحصائيات أعلاه. يُحتسَب على
// مستوى سطر الصنف (sale_items) لا الفاتورة كاملة، لأن فاتورة واحدة قد تخلط منتجات مورد ومنتجات مكتب معاً.
$stmt_office_qty_val = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity), 0) AS qty, COALESCE(SUM(si.total_price_syp), 0) AS val
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE p.supplier_id IS NULL AND s.invoice_date BETWEEN ? AND ?
");
$stmt_office_qty_val->execute([$qf_from, $qf_to]);
$office_products_sales = $stmt_office_qty_val->fetch(PDO::FETCH_ASSOC);
$office_products_qty = floatval($office_products_sales['qty']);
$office_products_val = floatval($office_products_sales['val']);

// ============================================================
// فلتر حالة التسليم + ترقيم صفحات جدول الفواتير — يستخدم الآن نفس نطاق التاريخ (qf_from/qf_to) أعلاه
// ============================================================
$list_status = $_GET['list_status'] ?? '';
if (!in_array($list_status, ['Delivered', 'Pending', 'Deferred'])) { $list_status = ''; }
$list_search = trim($_GET['list_search'] ?? '');
$list_source = $_GET['list_source'] ?? '';
if ($list_source !== 'office') { $list_source = ''; }
$list_page = max(1, intval($_GET['list_page'] ?? 1));
$list_per_page = 20;

// تصحيح جوهري: نفس الفاتورة قد تكون "قيد الانتظار" (invoice_date وحدها ذات معنى) أو "تم التسليم"
// (delivered_at هو التاريخ الصحيح). فلتر تاريخ الجدول كان يعتمد invoice_date دائماً بلا استثناء —
// فتختفي فواتير صدرت بتاريخ سابق وأُكِّد تسليمها اليوم من نتائج "اليوم"، رغم ظهورها بشكل صحيح في
// بطاقات الإحصاء أعلاه (التي أُصلِحت سابقاً). الشرط أدناه يختار التاريخ الصحيح حسب حالة كل فاتورة.
$list_where = ["((s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?) OR (s.delivery_status != 'Delivered' AND s.invoice_date BETWEEN ? AND ?))"];
$list_params = [$qf_from, $qf_to, $qf_from, $qf_to];
if ($list_status !== '') {
    $list_where[] = "s.delivery_status = ?";
    $list_params[] = $list_status;
}
if ($list_search !== '') {
    $list_where[] = "(s.customer_name LIKE ? OR s.invoice_number LIKE ?)";
    $list_params[] = "%{$list_search}%";
    $list_params[] = "%{$list_search}%";
}
if ($list_source === 'office') {
    // فقط الفواتير التي تحتوي صنفاً واحداً على الأقل بلا مورد محدَّد (منتجات المكتب)
    $list_where[] = "EXISTS (SELECT 1 FROM sale_items si2 INNER JOIN products p2 ON si2.product_id = p2.id WHERE si2.sale_id = s.id AND p2.supplier_id IS NULL)";
}
$list_where_sql = 'WHERE ' . implode(' AND ', $list_where);

$stmt_count = $conn->prepare("SELECT COUNT(*) FROM sales s {$list_where_sql}");
$stmt_count->execute($list_params);
$list_total_count = intval($stmt_count->fetchColumn());
$list_total_pages = max(1, ceil($list_total_count / $list_per_page));
if ($list_page > $list_total_pages) { $list_page = $list_total_pages; }
$list_offset = ($list_page - 1) * $list_per_page;

// الاستعلامات للجدول والقوائم
$sql_sales = "SELECT s.*, r.name AS rep_name 
              FROM sales s 
              LEFT JOIN representatives r ON s.representative_id = r.id 
              {$list_where_sql}
              ORDER BY s.id DESC
              LIMIT {$list_per_page} OFFSET {$list_offset}";
$stmt_sales_list = $conn->prepare($sql_sales);
$stmt_sales_list->execute($list_params);
$sales_list = $stmt_sales_list->fetchAll(PDO::FETCH_ASSOC);

// جلب أصناف كل الفواتير مع الكمية المتبقية القابلة للإرجاع (لبناء نافذة المرتجع بالجافاسكريبت)
$items_by_sale = [];
$stmt_all_items = $conn->query("
    SELECT si.id, si.sale_id, si.product_id, si.quantity, si.unit_price_syp, si.commission_per_unit, p.product_name,
           COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0) AS already_returned
    FROM sale_items si
    LEFT JOIN products p ON si.product_id = p.id
");
foreach ($stmt_all_items->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $row['remaining'] = floatval($row['quantity']) - floatval($row['already_returned']);
    $items_by_sale[$row['sale_id']][] = $row;
}

// إجمالي قيمة المرتجعات لكل فاتورة — الإجمالي الأصلي (total_amount_syp) يبقى ثابتاً دائماً كسجل
// تاريخي لما صدر فعلاً (تعتمد عليه حسابات أخرى كالعمولة وCOGS)، لكن هذا يُستخدَم لعرض "الصافي بعد
// الإرجاع" بجانبه في قائمة الفواتير، دون تعديل الرقم الأصلي المخزَّن إطلاقاً.
$returns_by_sale = [];
$stmt_returns_totals = $conn->query("SELECT sale_id, COALESCE(SUM(total_amount_syp), 0) AS total_returned FROM sales_returns GROUP BY sale_id");
foreach ($stmt_returns_totals->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $returns_by_sale[$row['sale_id']] = floatval($row['total_returned']);
}

// فقط المنتجات المتوفرة فعلياً بالمخزون تظهر في نموذج فاتورة المبيعات — لا معنى لعرض صنف نافد للبيع
$products_list = $conn->query("SELECT * FROM products WHERE current_quantity > 0 ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$reps_list = $conn->query("SELECT * FROM representatives ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2>إدارة المبيعات وفواتير العملاء</h2>
        <p style="color: #666; margin: 0;">متابعة الفواتير وحالات التسليم وإدخال القيود تلقائياً.</p>
    </div>
    <div>
        <button onclick="openSaleModal()" style="background: #1cc88a; color: white; padding: 9px 18px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold;">
            <i class="fas fa-plus"></i> إصدار فاتورة مبيعات جديدة
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $msg; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- عدد القطع حسب الفترة + فلتر حالة التسليم (قسم موحَّد) -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 15px;">
        <h3 style="margin: 0; color: #4e73df; font-size: 16px;"><i class="fas fa-boxes"></i> عدد القطع حسب الفترة والحالة</h3>
        <form method="GET" action="" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php if ($list_status !== ''): ?><input type="hidden" name="list_status" value="<?php echo htmlspecialchars($list_status); ?>"><?php endif; ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['qf_period' => 'today', 'qf_from' => null, 'qf_to' => null, 'list_page' => 1])); ?>" style="text-decoration: none;">
                <span style="padding: 7px 14px; border-radius: 5px; font-size: 13px; font-weight: bold; cursor: pointer; background: <?php echo $qf_period === 'today' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $qf_period === 'today' ? '#fff' : '#4e73df'; ?>;">اليوم</span>
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['qf_period' => 'week', 'qf_from' => null, 'qf_to' => null, 'list_page' => 1])); ?>" style="text-decoration: none;">
                <span style="padding: 7px 14px; border-radius: 5px; font-size: 13px; font-weight: bold; cursor: pointer; background: <?php echo $qf_period === 'week' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $qf_period === 'week' ? '#fff' : '#4e73df'; ?>;">هذا الأسبوع</span>
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['qf_period' => 'all', 'qf_from' => null, 'qf_to' => null, 'list_page' => 1])); ?>" style="text-decoration: none;">
                <span style="padding: 7px 14px; border-radius: 5px; font-size: 13px; font-weight: bold; cursor: pointer; background: <?php echo $qf_period === 'all' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $qf_period === 'all' ? '#fff' : '#4e73df'; ?>;">كل الفترات</span>
            </a>
            <input type="hidden" name="qf_period" value="custom">
            <input type="date" name="qf_from" value="<?php echo htmlspecialchars($qf_from); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <span style="color: #888;">إلى</span>
            <input type="date" name="qf_to" value="<?php echo htmlspecialchars($qf_to); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <button type="submit" style="background: #6f42c1; color: white; border: none; padding: 7px 14px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold;">تطبيق</button>
        </form>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 15px;">
        <?php
            $mk_status_link = function ($status) {
                global $_GET;
                $qs = $_GET;
                $qs['list_status'] = $status;
                $qs['list_page'] = 1;
                return '?' . http_build_query($qs);
            };
            $mk_source_link = function () use ($list_source) {
                global $_GET;
                $qs = $_GET;
                $qs['list_source'] = $list_source === 'office' ? '' : 'office';
                $qs['list_page'] = 1;
                return '?' . http_build_query($qs);
            };
        ?>
        <a href="<?php echo $mk_status_link(''); ?>" style="text-decoration: none;">
            <div style="background: #eef1fc; border-right: 4px solid #2e59d9; padding: 15px; border-radius: 6px; <?php echo $list_status === '' ? 'box-shadow: 0 0 0 2px #2e59d9 inset;' : ''; ?>">
                <div style="color: #2e59d9; font-size: 13px; font-weight: bold;">الإجمالي الكلي</div>
                <div style="font-size: 22px; font-weight: bold; color: #2e59d9; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($qty_grand_total, 2), '0'), '.'); ?> <span style="font-size: 12px; font-weight: normal;">قطعة</span></div>
                <div style="font-size: 13px; color: #2e59d9; font-family: monospace; margin-top: 3px;"><?php echo number_format($amt_grand_total, 2); ?> ل.س</div>
            </div>
        </a>
        <a href="<?php echo $mk_status_link('Delivered'); ?>" style="text-decoration: none;">
            <div style="background: #eafaf1; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 6px; <?php echo $list_status === 'Delivered' ? 'box-shadow: 0 0 0 2px #1cc88a inset;' : ''; ?>">
                <div style="color: #1a8f5f; font-size: 13px; font-weight: bold;">تم التسليم</div>
                <div style="font-size: 22px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($qty_delivered, 2), '0'), '.'); ?> <span style="font-size: 12px; font-weight: normal;">قطعة</span></div>
                <div style="font-size: 13px; color: #1a8f5f; font-family: monospace; margin-top: 3px;"><?php echo number_format($amt_delivered, 2); ?> ل.س</div>
            </div>
        </a>
        <a href="<?php echo $mk_status_link('Pending'); ?>" style="text-decoration: none;">
            <div style="background: #fff8e6; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px; <?php echo $list_status === 'Pending' ? 'box-shadow: 0 0 0 2px #f6c23e inset;' : ''; ?>">
                <div style="color: #96751c; font-size: 13px; font-weight: bold;">قيد الانتظار</div>
                <div style="font-size: 22px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($qty_pending, 2), '0'), '.'); ?> <span style="font-size: 12px; font-weight: normal;">قطعة</span></div>
                <div style="font-size: 13px; color: #96751c; font-family: monospace; margin-top: 3px;"><?php echo number_format($amt_pending, 2); ?> ل.س</div>
            </div>
        </a>
        <a href="<?php echo $mk_status_link('Deferred'); ?>" style="text-decoration: none;">
            <div style="background: #eaf1fc; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px; <?php echo $list_status === 'Deferred' ? 'box-shadow: 0 0 0 2px #4e73df inset;' : ''; ?>">
                <div style="color: #2c4e9c; font-size: 13px; font-weight: bold;">مؤجلة</div>
                <div style="font-size: 22px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($qty_deferred, 2), '0'), '.'); ?> <span style="font-size: 12px; font-weight: normal;">قطعة</span></div>
                <div style="font-size: 13px; color: #2c4e9c; font-family: monospace; margin-top: 3px;"><?php echo number_format($amt_deferred, 2); ?> ل.س</div>
            </div>
        </a>
        <div style="background: #fdecea; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px;">
            <div style="color: #a33636; font-size: 13px; font-weight: bold;">قطع مرتجعة</div>
            <div style="font-size: 22px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($qty_returned, 2), '0'), '.'); ?> <span style="font-size: 12px; font-weight: normal;">قطعة</span></div>
            <div style="font-size: 13px; color: #a33636; font-family: monospace; margin-top: 3px;"><?php echo number_format($amt_returned, 2); ?> ل.س</div>
        </div>
        <a href="<?php echo $mk_source_link(); ?>" style="text-decoration: none;">
            <div style="background: #f3eefc; border-right: 4px solid #8b5cf6; padding: 15px; border-radius: 6px; <?php echo $list_source === 'office' ? 'box-shadow: 0 0 0 2px #8b5cf6 inset;' : ''; ?>">
                <div style="color: #5b3a99; font-size: 13px; font-weight: bold;" title="أصناف supplier_id فارغ — جرد مكتبي مباشر بلا مورد">مبيعات منتجات المكتب (بلا مورد)</div>
                <div style="font-size: 22px; font-weight: bold; color: #8b5cf6; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($office_products_qty, 2), '0'), '.'); ?> <span style="font-size: 12px; font-weight: normal;">قطعة</span></div>
                <div style="font-size: 13px; color: #5b3a99; font-family: monospace; margin-top: 3px;"><?php echo number_format($office_products_val, 2); ?> ل.س</div>
            </div>
        </a>
    </div>
    <p style="color: #999; font-size: 12px; margin: 12px 0 0 0;">
        اضغط أي بطاقة لتصفية جدول الفواتير أدناه بنفس الحالة والفترة تلقائياً. القطع المرتجعة محسوبة بتاريخ المرتجع نفسه (لا رابط تصفية لها لأنها ليست حالة تسليم). الإجمالي الكلي (قطعاً ومبلغاً) = تم التسليم + قيد الانتظار + مؤجلة، ولا يشمل المرتجعة.
    </p>
</div>

<!-- جدول عرض الفواتير -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="padding: 15px 20px; border-bottom: 1px solid #e3e6f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <form method="GET" action="" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <?php foreach (['qf_period', 'qf_from', 'qf_to'] as $preserve_key): if (isset($_GET[$preserve_key])): ?>
                <input type="hidden" name="<?php echo $preserve_key; ?>" value="<?php echo htmlspecialchars($_GET[$preserve_key]); ?>">
            <?php endif; endforeach; ?>
            <label style="font-size: 13px; font-weight: bold; color: #555;">حالة التسليم:</label>
            <select name="list_status" onchange="this.form.submit()" style="padding: 7px 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 13px;">
                <option value="" <?php echo $list_status === '' ? 'selected' : ''; ?>>-- الكل --</option>
                <option value="Delivered" <?php echo $list_status === 'Delivered' ? 'selected' : ''; ?>>تم التسليم فقط</option>
                <option value="Pending" <?php echo $list_status === 'Pending' ? 'selected' : ''; ?>>قيد الانتظار فقط</option>
                <option value="Deferred" <?php echo $list_status === 'Deferred' ? 'selected' : ''; ?>>مؤجلة فقط</option>
            </select>
            <input type="text" name="list_search" value="<?php echo htmlspecialchars($list_search); ?>" placeholder="بحث باسم العميل أو رقم الفاتورة..." style="padding: 7px 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 13px; min-width: 220px;">
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 7px 14px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold;"><i class="fas fa-search"></i> بحث</button>
            <span style="font-size: 12.5px; color: #999;"><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($qf_from); ?> إلى <?php echo htmlspecialchars($qf_to); ?></span>
            <?php if ($list_status !== '' || $qf_period !== 'today' || $list_search !== '' || $list_source !== ''): ?>
                <a href="?" style="font-size: 12.5px; color: #e74a3b; text-decoration: none;"><i class="fas fa-times"></i> إلغاء كل الفلاتر</a>
            <?php endif; ?>
        </form>
        <span style="font-size: 12.5px; color: #888;">إجمالي النتائج: <strong style="color: #4e73df;"><?php echo $list_total_count; ?></strong> فاتورة</span>
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #4e73df; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 10px 12px; white-space: nowrap;">رقم الفاتورة</th>
                    <th style="padding: 10px 12px;">اسم العميل</th>
                    <th style="padding: 10px 12px; max-width: 220px;">الأصناف (الكمية)</th>
                    <th style="padding: 10px 12px; white-space: nowrap;">المندوب</th>
                    <th style="padding: 10px 12px; color: #2e59d9; white-space: nowrap;">الإجمالي (SYP)</th>
                    <th style="padding: 10px 12px; color: #e74a3b; white-space: nowrap;">الإجمالي (USD)</th>
                    <th style="padding: 10px 12px; color: #6f42c1; white-space: nowrap;">الشحن</th>
                    <th style="padding: 10px 12px; color: #6f42c1; white-space: nowrap;">نوع التسليم</th>
                    <th style="padding: 10px 12px; white-space: nowrap;">حالة الدفع</th>
                    <th style="padding: 10px 12px; color: #f6c23e; white-space: nowrap;">حالة التسليم</th>
                    <th style="padding: 10px 12px; white-space: nowrap;">التاريخ</th>
                    <th style="padding: 10px 12px; text-align: center; white-space: nowrap;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($sales_list) > 0): ?>
                    <?php foreach ($sales_list as $sale): 
                        // تصحيح: كانت الفاتورة تُقفَل عن التعديل بمجرد تغيّر الشهر التقويمي، حتى لو
                        // كانت لا تزال "قيد الانتظار" فعلياً بلا أي داعٍ محاسبي حقيقي لقفلها. الآن يُعتمَد
                        // فقط على القفل الصحيح المرتبط بالفترات المالية المُقفَلة يدوياً من الإدارة.
                        $is_editable = !isDateInClosedPeriod($conn, $sale['invoice_date']);

                        // القيم الفعلية المخزنة بالإنجليزية (مطابقة لـ ENUM)
                        $p_status = trim($sale['payment_status'] ?? '');
                        $d_status = trim($sale['delivery_status'] ?? '');

                        // تنسيق ألوان حالة الدفع بناءً على القيمة الإنجليزية الفعلية
                        $p_bg = '#f8f9fc'; $p_color = '#5a5c69';
                        if ($p_status === 'Paid') {
                            $p_bg = '#d4edda'; $p_color = '#155724';
                        } elseif ($p_status === 'Unpaid') {
                            $p_bg = '#f8d7da'; $p_color = '#721c24';
                        } elseif ($p_status === 'Partial') {
                            $p_bg = '#cce5ff'; $p_color = '#004085';
                        }

                        // تنسيق ألوان حالة التسليم بناءً على القيمة الإنجليزية الفعلية
                        $d_bg = '#f8f9fc'; $d_color = '#5a5c69';
                        if ($d_status === 'Delivered') {
                            $d_bg = '#d4edda'; $d_color = '#155724';
                        } elseif ($d_status === 'Deferred') {
                            $d_bg = '#cce5ff'; $d_color = '#004085';
                        } elseif ($d_status === 'Pending') {
                            $d_bg = '#fff3cd'; $d_color = '#856404';
                        }

                        // نص العرض العربي المطابق للقيمة المخزنة
                        $p_display = $payment_labels[$p_status] ?? ($p_status ?: 'غير محدد');
                        $d_display = $delivery_labels[$d_status] ?? ($d_status ?: 'غير محدد');

                        $items_for_sale = $items_by_sale[$sale['id']] ?? [];

                        // شارة "مرتجع بالكامل" — محسوبة من بيانات الأصناف مباشرة (لا تمس عمود delivery_status
                        // نفسه إطلاقاً، تفادياً لأي أثر جانبي على استعلامات أخرى تفلتر عليه في ملفات أخرى).
                        // تظهر فقط عند ارتجاع كل صنف في الفاتورة بالكامل (لا رصيد قابل للإرجاع متبقٍ لأي صنف)،
                        // وليس عند ارتجاع جزئي.
                        $is_fully_returned = false;
                        if (count($items_for_sale) > 0) {
                            $all_zero_remaining = true;
                            $any_returned = false;
                            foreach ($items_for_sale as $it) {
                                if (floatval($it['remaining']) > 0) { $all_zero_remaining = false; }
                                if (floatval($it['already_returned']) > 0) { $any_returned = true; }
                            }
                            $is_fully_returned = $all_zero_remaining && $any_returned;
                        }
                    ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 9px 12px; font-weight: bold; color: #4e73df; font-family: monospace; white-space: nowrap;"><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                            <td style="padding: 9px 12px; font-weight: 600; color: #333; white-space: nowrap;"><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                            <td style="padding: 9px 12px; color: #555; font-size: 12.5px; max-width: 220px; line-height: 1.5;">
                                <?php if (count($items_for_sale) > 0): ?>
                                    <?php
                                        $item_parts = [];
                                        foreach ($items_for_sale as $it) {
                                            $qty_display = rtrim(rtrim(number_format(floatval($it['quantity']), 2), '0'), '.');
                                            $item_parts[] = htmlspecialchars(($it['product_name'] ?: 'منتج محذوف') . ' × ' . $qty_display);
                                        }
                                        echo implode('، ', $item_parts);
                                    ?>
                                <?php else: ?>
                                    <span style="color: #aaa;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 9px 12px; color: #555; white-space: nowrap;"><?php echo htmlspecialchars($sale['rep_name'] ?: 'بدون مندوب'); ?></td>
                            <?php
                                $sale_returned_syp = $returns_by_sale[$sale['id']] ?? 0;
                                $sale_net_syp = floatval($sale['total_amount_syp']) - $sale_returned_syp;
                            ?>
                            <td style="padding: 9px 12px; font-family: monospace; color: #2e59d9; font-weight: bold; white-space: nowrap;">
                                <?php if ($sale_returned_syp > 0): ?>
                                    <span style="text-decoration: line-through; color: #aaa; font-size: 11.5px; display: block;"><?php echo number_format($sale['total_amount_syp'], 2); ?></span>
                                    <span title="الصافي بعد خصم مرتجعات بقيمة <?php echo number_format($sale_returned_syp, 2); ?> ل.س"><?php echo number_format($sale_net_syp, 2); ?> ل.س</span>
                                <?php else: ?>
                                    <?php echo number_format($sale['total_amount_syp'], 2); ?> ل.س
                                <?php endif; ?>
                            </td>
                            <td style="padding: 9px 12px; font-family: monospace; color: #e74a3b; font-weight: bold; white-space: nowrap;">$<?php echo number_format($sale['total_amount_usd'], 2); ?></td>
                            <td style="padding: 9px 12px; font-family: monospace; color: #6f42c1; white-space: nowrap;"><?php echo number_format($sale['shipping_cost_syp'] ?? 0, 2); ?> ل.س</td>
                            <td style="padding: 9px 12px; color: #6f42c1; white-space: nowrap;"><?php echo htmlspecialchars($sale['delivery_type'] ?: '-'); ?></td>
                            <td style="padding: 9px 12px; white-space: nowrap;">
                                <span class="status-badge" style="display: inline-block; white-space: nowrap; background: <?php echo $p_bg; ?>; color: <?php echo $p_color; ?>;">
                                    <?php echo htmlspecialchars($p_display); ?>
                                </span>
                            </td>
                            <td style="padding: 9px 12px; white-space: nowrap;">
                                <?php if ($is_fully_returned): ?>
                                    <span class="status-badge" style="display: inline-block; white-space: nowrap; background: #f4dede; color: #a33636;" title="كل أصناف هذه الفاتورة أُرجعت بالكامل">
                                        <i class="fas fa-undo"></i> مرتجع بالكامل
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge" style="display: inline-block; white-space: nowrap; background: <?php echo $d_bg; ?>; color: <?php echo $d_color; ?>;">
                                        <?php echo htmlspecialchars($d_display); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 9px 12px; font-family: monospace; color: #666; white-space: nowrap;"><?php echo htmlspecialchars($sale['invoice_date']); ?></td>
                            <td style="padding: 9px 12px; text-align: center; white-space: nowrap;">
                                <div style="display: inline-flex; gap: 6px; align-items: center;">
                                <?php if ($is_editable): ?>
                                    <button onclick='openEditModal(<?php echo json_encode($sale, JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($items_for_sale, JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo $any_returned ? 'true' : 'false'; ?>)' class="row-action-btn row-action-edit" title="تعديل الفاتورة">
                                        <i class="fas fa-pen"></i> تعديل
                                    </button>
                                <?php else: ?>
                                    <span style="color: #b0b7c3; font-size: 11px;" title="تاريخ الفاتورة ضمن فترة مالية مُقفَلة">مغلقة (فترة مقفلة)</span>
                                <?php endif; ?>
                                <?php if (count(array_filter($items_for_sale, fn($it) => $it['remaining'] > 0)) > 0): ?>
                                    <button onclick='openReturnModal(<?php echo $sale['id']; ?>, "<?php echo htmlspecialchars($sale['invoice_number'], ENT_QUOTES); ?>", <?php echo json_encode($items_for_sale, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="row-action-btn row-action-return" title="تسجيل مرتجع">
                                        <i class="fas fa-rotate-left"></i> مرتجع
                                    </button>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="12" style="padding: 30px; text-align: center; color: #777;">
                            <?php echo ($list_status !== '' || $qf_period !== 'today') ? 'لا توجد فواتير مطابقة للفلاتر المحددة.' : 'لا توجد فواتير مبيعات مسجلة اليوم.'; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($list_total_pages > 1): ?>
    <div style="padding: 15px 20px; border-top: 1px solid #e3e6f0; display: flex; justify-content: center; align-items: center; gap: 6px; flex-wrap: wrap;">
        <?php
            $qs_base = $_GET;
            $mk_link = function ($p) use ($qs_base) {
                $qs_base['list_page'] = $p;
                return '?' . http_build_query($qs_base);
            };
        ?>
        <?php if ($list_page > 1): ?>
            <a href="<?php echo $mk_link($list_page - 1); ?>" style="padding: 6px 12px; border: 1px solid #ddd; border-radius: 5px; text-decoration: none; color: #4e73df; font-size: 13px;">السابق</a>
        <?php endif; ?>
        <?php
            $start_p = max(1, $list_page - 2);
            $end_p = min($list_total_pages, $list_page + 2);
            for ($p = $start_p; $p <= $end_p; $p++):
        ?>
            <a href="<?php echo $mk_link($p); ?>" style="padding: 6px 12px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold; background: <?php echo $p === $list_page ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $p === $list_page ? '#fff' : '#4e73df'; ?>;"><?php echo $p; ?></a>
        <?php endfor; ?>
        <?php if ($list_page < $list_total_pages): ?>
            <a href="<?php echo $mk_link($list_page + 1); ?>" style="padding: 6px 12px; border: 1px solid #ddd; border-radius: 5px; text-decoration: none; color: #4e73df; font-size: 13px;">التالي</a>
        <?php endif; ?>
        <span style="color: #999; font-size: 12.5px; margin-right: 10px;">صفحة <?php echo $list_page; ?> من <?php echo $list_total_pages; ?></span>
    </div>
    <?php endif; ?>
</div>

<!-- Modal إصدار الفاتورة -->
<div id="saleModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; overflow-y: auto;">
    <div style="background: white; width: 850px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); margin: 30px auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #1cc88a;"><i class="fas fa-file-invoice-dollar"></i> إصدار فاتورة مبيعات جديدة</h3>
            <button onclick="closeSaleModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_sale" value="1">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">رقم الفاتورة:</label>
                    <input type="text" name="invoice_number" required value="INV-<?php echo time(); ?>" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">اسم العميل:</label>
                    <input type="text" name="customer_name" required placeholder="اسم العميل..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">المندوب المسؤول:</label>
                    <div style="display: flex; gap: 6px;">
                        <select name="representative_id" id="sale_representative_id" style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="0">-- بدون مندوب --</option>
                            <?php foreach ($reps_list as $rep): ?>
                                <option value="<?php echo $rep['id']; ?>" <?php echo ($newly_added_rep_id && $rep['id'] == $newly_added_rep_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rep['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="openAddRepModal()" title="إضافة مندوب جديد" style="background: #1cc88a; color: white; border: none; width: 38px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 16px;">+</button>
                    </div>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">سعر الصرف المثبت تاريخياً:</label>
                    <input type="number" step="0.0001" name="exchange_rate" value="<?php echo htmlspecialchars($default_exchange_rate); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">حالة الدفع:</label>
                    <select name="payment_status" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="Unpaid">آجل / ذمم عملاء</option>
                        <option value="Paid">نقداً في الصندوق</option>
                        <option value="Partial">دفعة جزئية</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">حالة التسليم / الشحن:</label>
                    <select name="delivery_status" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="Pending">قيد الانتظار</option>
                        <option value="Delivered">تم التسليم</option>
                        <option value="Deferred">مؤجلة</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ الفاتورة:</label>
                    <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">تكلفة الشحن (ل.س):</label>
                    <input type="number" step="0.01" min="0" name="shipping_cost_syp" value="0" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                    <p style="color: #999; font-size: 11.5px; margin: 4px 0 0 0;">يُرحَّل تلقائياً: مدين "تكاليف الشحن" / دائن الصندوق.</p>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">نوع التسليم:</label>
                    <select name="delivery_type" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="">-- غير محدد --</option>
                        <option value="شحن">شحن</option>
                        <option value="توصيل">توصيل</option>
                    </select>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 15px 0;">

            <h4 style="margin: 0 0 10px 0; color: #4e73df;">أصناف المنتجات المباعة</h4>
            <div id="itemsContainer">
                <div class="sale-row" style="display: grid; grid-template-columns: 3fr 1fr 1fr 1fr auto; gap: 10px; margin-bottom: 10px; align-items: center;">
                    <div class="product-search-wrap" style="position: relative;">
                        <input type="text" class="product-search-input" placeholder="🔍 اكتب لبحث المنتج..." autocomplete="off" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <input type="hidden" name="product_id[]" class="product-id-hidden">
                        <div class="product-search-results" style="display: none; position: absolute; top: 100%; right: 0; left: 0; background: #fff; border: 1px solid #ccc; border-radius: 4px; max-height: 220px; overflow-y: auto; z-index: 1200; box-shadow: 0 4px 12px rgba(0,0,0,0.15);"></div>
                    </div>
                    <input type="number" step="0.0001" name="quantity[]" placeholder="الكمية" required style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                    <input type="number" step="0.01" name="unit_price_syp[]" placeholder="السعر (ل.س)" required style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                    <input type="number" step="0.01" name="commission_amount[]" placeholder="العمولة للقطعة" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                    <button type="button" onclick="this.parentElement.remove()" style="background: #e74a3b; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer;"><i class="fas fa-trash"></i></button>
                </div>
            </div>

            <button type="button" onclick="addRow()" style="background: #4e73df; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 13px; margin-top: 5px;">
                <i class="fas fa-plus"></i> إضافة صنف آخر
            </button>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; margin-top: 20px;">
                <button type="button" onclick="closeSaleModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ وترحيل الفاتورة</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal إضافة مندوب جديد (من داخل نافذة الفاتورة مباشرة) -->
<div id="addRepModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.65); z-index: 1100; justify-content: center; align-items: center;">
    <div style="background: white; width: 400px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 5px 25px rgba(0,0,0,0.25);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e3e6f0; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #1cc88a;"><i class="fas fa-user-plus"></i> إضافة مندوب جديد</h3>
            <button type="button" onclick="closeAddRepModal()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_representative_inline" value="1">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">اسم المندوب: <span style="color:red;">*</span></label>
                <input type="text" name="new_rep_name" required autofocus style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">رقم الهاتف:</label>
                <input type="text" name="new_rep_phone" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>
            <p style="font-size: 12px; color: #999; margin-bottom: 15px;">ملاحظة: ستُغلَق نافذة الفاتورة الحالية عند الحفظ (إعادة تحميل الصفحة)، لكن المندوب الجديد سيكون محدَّداً تلقائياً — أعد فتح "إصدار فاتورة مبيعات جديدة" واستكمل باقي البيانات.</p>
            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px;">
                <button type="button" onclick="closeAddRepModal()" style="background: #e2e8f0; color: #333; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-left: 8px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ المندوب</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal تعديل الفاتورة -->
<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; overflow-y: auto;">
    <div style="background: white; width: 850px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); margin: 30px auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #f6c23e;"><i class="fas fa-edit"></i> تعديل بيانات الفاتورة</h3>
            <button onclick="closeEditModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <div id="editReturnsNotice" style="display: none; background: #fdecea; color: #a33636; padding: 10px 12px; border-radius: 6px; font-size: 12.5px; margin-bottom: 15px;">
            <i class="fas fa-lock"></i> هذه الفاتورة لها مرتجع مسجَّل بالفعل — رقم الفاتورة وتاريخها وسعر صرفها وأصنافها مقفلة لحماية سلامة ربط المرتجع. باقي الحقول أدناه قابلة للتعديل بأمان.
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="edit_sale" value="1">
            <input type="hidden" name="sale_id" id="edit_sale_id">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">رقم الفاتورة:</label>
                    <input type="text" name="invoice_number" id="edit_invoice_number" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">اسم العميل:</label>
                    <input type="text" name="customer_name" id="edit_customer_name" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">المندوب المسؤول:</label>
                    <div style="display: flex; gap: 6px;">
                        <select name="representative_id" id="edit_representative_id" style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="0">-- بدون مندوب --</option>
                            <?php foreach ($reps_list as $rep): ?>
                                <option value="<?php echo $rep['id']; ?>"><?php echo htmlspecialchars($rep['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="openAddRepModal()" title="إضافة مندوب جديد" style="background: #1cc88a; color: white; border: none; width: 38px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 16px;">+</button>
                    </div>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">سعر الصرف:</label>
                    <input type="number" step="0.0001" name="exchange_rate" id="edit_exchange_rate" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">حالة الدفع:</label>
                    <select name="payment_status" id="edit_payment_status" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="Unpaid">آجل / ذمم عملاء</option>
                        <option value="Paid">نقداً في الصندوق</option>
                        <option value="Partial">دفعة جزئية</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">حالة التسليم / الشحن:</label>
                    <select name="delivery_status" id="edit_delivery_status" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="Pending">قيد الانتظار</option>
                        <option value="Delivered">تم التسليم</option>
                        <option value="Deferred">مؤجلة</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ الفاتورة:</label>
                    <input type="date" name="invoice_date" id="edit_invoice_date" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">تكلفة الشحن (ل.س):</label>
                    <input type="number" step="0.01" min="0" name="shipping_cost_syp" id="edit_shipping_cost_syp" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">نوع التسليم:</label>
                    <select name="delivery_type" id="edit_delivery_type" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="">-- غير محدد --</option>
                        <option value="شحن">شحن</option>
                        <option value="توصيل">توصيل</option>
                    </select>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 15px 0;">

            <h4 id="editItemsHeader" style="margin: 0 0 10px 0; color: #4e73df;">أصناف المنتجات المباعة</h4>
            <div id="editItemsContainer"></div>
            <button type="button" id="editAddRowBtn" onclick="addEditRow()" style="background: #4e73df; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 13px; margin-top: 5px;">
                <i class="fas fa-plus"></i> إضافة صنف آخر
            </button>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; margin-top: 20px;">
                <button type="button" onclick="closeEditModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">تحديث الفاتورة</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openSaleModal() {
        document.getElementById('saleModal').style.display = 'flex';
        document.querySelectorAll('#itemsContainer .sale-row').forEach(function (row) { setupProductSearch(row); });
    }

    function closeSaleModal() {
        document.getElementById('saleModal').style.display = 'none';
    }

    function openAddRepModal() {
        document.getElementById('addRepModal').style.display = 'flex';
    }

    function closeAddRepModal() {
        document.getElementById('addRepModal').style.display = 'none';
    }

    <?php if ($newly_added_rep_id): ?>
    // بعد إضافة مندوب جديد بنجاح، أعد فتح نافذة إصدار الفاتورة تلقائياً مع تحديد المندوب الجديد مسبقاً
    document.addEventListener('DOMContentLoaded', function () {
        openSaleModal();
    });
    <?php endif; ?>

    function openEditModal(saleData, items, hasReturns) {
        document.getElementById('edit_sale_id').value = saleData.id;
        document.getElementById('edit_invoice_number').value = saleData.invoice_number;
        document.getElementById('edit_customer_name').value = saleData.customer_name;
        document.getElementById('edit_representative_id').value = saleData.representative_id || 0;
        document.getElementById('edit_exchange_rate').value = saleData.exchange_rate;
        document.getElementById('edit_payment_status').value = saleData.payment_status;
        document.getElementById('edit_delivery_status').value = saleData.delivery_status;
        document.getElementById('edit_invoice_date').value = saleData.invoice_date;
        document.getElementById('edit_shipping_cost_syp').value = saleData.shipping_cost_syp || 0;
        document.getElementById('edit_delivery_type').value = saleData.delivery_type || '';

        var notice = document.getElementById('editReturnsNotice');
        var invNumInput = document.getElementById('edit_invoice_number');
        var invDateInput = document.getElementById('edit_invoice_date');
        var exRateInput = document.getElementById('edit_exchange_rate');
        var addRowBtn = document.getElementById('editAddRowBtn');
        var itemsHeader = document.getElementById('editItemsHeader');

        if (hasReturns) {
            // فاتورة لها مرتجع: قفل رقم/تاريخ/سعر الصرف والأصناف — لا يُرسَل product_id[] إطلاقاً
            // فيتعرّف الخادم تلقائياً على أنها حالة "تعديل مبسَّط" فقط.
            notice.style.display = 'block';
            invNumInput.readOnly = true; invNumInput.style.background = '#f1f1f1';
            invDateInput.readOnly = true; invDateInput.style.background = '#f1f1f1';
            exRateInput.readOnly = true; exRateInput.style.background = '#f1f1f1';
            addRowBtn.style.display = 'none';
            itemsHeader.style.display = 'none';
            document.getElementById('editItemsContainer').innerHTML = '';
        } else {
            notice.style.display = 'none';
            invNumInput.readOnly = false; invNumInput.style.background = '';
            invDateInput.readOnly = false; invDateInput.style.background = '';
            exRateInput.readOnly = false; exRateInput.style.background = '';
            addRowBtn.style.display = 'inline-block';
            itemsHeader.style.display = 'block';
            var container = document.getElementById('editItemsContainer');
            container.innerHTML = '';
            (items || []).forEach(function(it) {
                addEditRow({
                    product_id: it.product_id,
                    quantity: it.quantity,
                    unit_price_syp: it.unit_price_syp,
                    commission_amount: it.commission_per_unit
                });
            });
            if ((items || []).length === 0) { addEditRow({}); }
        }

        document.getElementById('editModal').style.display = 'flex';
    }

    // مصفوفة كل المنتجات المتوفرة بالمخزون (نفس القائمة المستخدمة في نموذج الإضافة) — أساس البحث
    // الحي في كلا نموذجي الإضافة والتعديل، بلا تكرار استعلام PHP أو تحميل مكتبة خارجية.
    var ALL_PRODUCTS = <?php echo json_encode(array_map(function ($prod) {
        $p_price = $prod['retail_price_syp'] ?? $prod['wholesale_price_syp'] ?? $prod['special_price_syp'] ?? $prod['unit_price_syp'] ?? $prod['price'] ?? 0;
        $p_comm = $prod['commission_syp'] ?? $prod['commission'] ?? $prod['commission_amount'] ?? 0;
        return ['id' => $prod['id'], 'name' => $prod['product_name'], 'qty' => floatval($prod['current_quantity']), 'price' => floatval($p_price), 'commission' => floatval($p_comm)];
    }, $products_list)); ?>;

    function setupProductSearch(row) {
        var input = row.querySelector('.product-search-input');
        var hidden = row.querySelector('.product-id-hidden');
        var results = row.querySelector('.product-search-results');
        if (!input || input.dataset.wired) return;
        input.dataset.wired = '1';

        function renderResults(list) {
            results.innerHTML = '';
            if (list.length === 0) {
                results.innerHTML = '<div style="padding:8px 10px;color:#999;font-size:13px;">لا توجد نتائج مطابقة</div>';
                results.style.display = 'block';
                return;
            }
            list.slice(0, 40).forEach(function (p) {
                var item = document.createElement('div');
                item.style.cssText = 'padding:8px 10px;cursor:pointer;border-bottom:1px solid #f1f1f1;font-size:13px;';
                item.textContent = p.name + ' (متوفر: ' + p.qty + ')';
                item.addEventListener('mouseenter', function () { item.style.background = '#f1f3f9'; });
                item.addEventListener('mouseleave', function () { item.style.background = ''; });
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    hidden.value = p.id;
                    input.value = p.name;
                    results.style.display = 'none';
                    var priceInput = row.querySelector('input[name="unit_price_syp[]"]');
                    var commInput = row.querySelector('input[name="commission_amount[]"]');
                    if (priceInput) priceInput.value = p.price;
                    if (commInput) commInput.value = p.commission;
                });
                results.appendChild(item);
            });
            results.style.display = 'block';
        }

        input.addEventListener('input', function () {
            hidden.value = ''; // أي تعديل يدوي في النص يُبطل الاختيار السابق حتى يُعاد الاختيار من القائمة
            var q = input.value.trim().toLowerCase();
            if (q.length === 0) { results.style.display = 'none'; return; }
            renderResults(ALL_PRODUCTS.filter(function (p) { return p.name.toLowerCase().indexOf(q) !== -1; }));
        });
        input.addEventListener('focus', function () {
            if (input.value.trim().length > 0) { input.dispatchEvent(new Event('input')); }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () { results.style.display = 'none'; }, 150);
        });
    }

    function addRow() {
        var container = document.getElementById('itemsContainer');
        var firstRow = container.querySelector('.sale-row');
        var newRow = firstRow.cloneNode(true);

        newRow.querySelectorAll('input[type="text"], input[type="number"], input[type="hidden"]').forEach(function (i) { i.value = ''; });
        var newInput = newRow.querySelector('.product-search-input');
        delete newInput.dataset.wired;

        container.appendChild(newRow);
        setupProductSearch(newRow);
    }

    function addEditRow(itemData) {
        itemData = itemData || {};
        var container = document.getElementById('editItemsContainer');
        var row = document.createElement('div');
        row.className = 'sale-row';
        row.style.cssText = 'display: grid; grid-template-columns: 3fr 1fr 1fr 1fr auto; gap: 10px; margin-bottom: 10px; align-items: center;';

        var searchWrap = document.createElement('div');
        searchWrap.className = 'product-search-wrap';
        searchWrap.style.position = 'relative';
        var searchInput = document.createElement('input');
        searchInput.type = 'text'; searchInput.className = 'product-search-input'; searchInput.autocomplete = 'off';
        searchInput.placeholder = '🔍 اكتب لبحث المنتج...'; searchInput.required = true;
        searchInput.style.cssText = 'width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;';
        var hiddenId = document.createElement('input');
        hiddenId.type = 'hidden'; hiddenId.name = 'product_id[]'; hiddenId.className = 'product-id-hidden';
        var resultsDiv = document.createElement('div');
        resultsDiv.className = 'product-search-results';
        resultsDiv.style.cssText = 'display:none;position:absolute;top:100%;right:0;left:0;background:#fff;border:1px solid #ccc;border-radius:4px;max-height:220px;overflow-y:auto;z-index:1200;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
        searchWrap.appendChild(searchInput);
        searchWrap.appendChild(hiddenId);
        searchWrap.appendChild(resultsDiv);
        row.appendChild(searchWrap);

        if (itemData.product_id) {
            hiddenId.value = itemData.product_id;
            var matched = ALL_PRODUCTS.find(function (p) { return String(p.id) === String(itemData.product_id); });
            searchInput.value = matched ? matched.name : ('منتج #' + itemData.product_id);
        }

        var qtyInput = document.createElement('input');
        qtyInput.type = 'number'; qtyInput.step = '0.0001'; qtyInput.name = 'quantity[]'; qtyInput.required = true;
        qtyInput.placeholder = 'الكمية';
        qtyInput.style.cssText = 'padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;';
        if (itemData.quantity !== undefined) { qtyInput.value = itemData.quantity; }
        row.appendChild(qtyInput);

        var priceInput = document.createElement('input');
        priceInput.type = 'number'; priceInput.step = '0.01'; priceInput.name = 'unit_price_syp[]'; priceInput.required = true;
        priceInput.placeholder = 'السعر (ل.س)';
        priceInput.style.cssText = 'padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;';
        if (itemData.unit_price_syp !== undefined) { priceInput.value = itemData.unit_price_syp; }
        row.appendChild(priceInput);

        var commInput = document.createElement('input');
        commInput.type = 'number'; commInput.step = '0.01'; commInput.name = 'commission_amount[]';
        commInput.placeholder = 'العمولة للقطعة';
        commInput.style.cssText = 'padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;';
        if (itemData.commission_amount !== undefined && itemData.commission_amount !== null) { commInput.value = itemData.commission_amount; }
        row.appendChild(commInput);

        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.innerHTML = '<i class="fas fa-trash"></i>';
        delBtn.style.cssText = 'background: #e74a3b; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer;';
        delBtn.onclick = function () { row.remove(); };
        row.appendChild(delBtn);

        container.appendChild(row);
        setupProductSearch(row);
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function openReturnModal(saleId, invoiceNumber, items) {
        document.getElementById('return_sale_id').value = saleId;
        document.getElementById('return_invoice_label').innerText = invoiceNumber;
        var tbody = document.getElementById('returnItemsBody');
        tbody.innerHTML = '';
        items.forEach(function(it) {
            if (parseFloat(it.remaining) <= 0) return;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td style="padding:8px;">' + (it.product_name || '') + '</td>' +
                '<td style="padding:8px; font-family:monospace;">' + it.quantity + '</td>' +
                '<td style="padding:8px; font-family:monospace; color:#e74a3b;">' + it.remaining + '</td>' +
                '<td style="padding:8px;"><input type="hidden" name="ret_sale_item_id[]" value="' + it.id + '">' +
                '<input type="number" step="0.0001" min="0" max="' + it.remaining + '" name="ret_quantity[]" value="0" style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;"></td>';
            tbody.appendChild(tr);
        });
        if (tbody.children.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="padding:15px; text-align:center; color:#777;">لا توجد كمية متاحة للإرجاع في هذه الفاتورة.</td></tr>';
        }
        document.getElementById('returnModal').style.display = 'flex';
    }

    function closeReturnModal() {
        document.getElementById('returnModal').style.display = 'none';
    }
</script>

<!-- Modal مرتجع مبيعات -->
<div id="returnModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; overflow-y: auto;">
    <div style="background: white; width: 650px; max-width: 95%; border-radius: 8px; padding: 25px; margin: 30px auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #e74a3b;"><i class="fas fa-undo"></i> مرتجع على فاتورة: <span id="return_invoice_label"></span></h3>
            <button onclick="closeReturnModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="add_return" value="1">
            <input type="hidden" name="return_sale_id" id="return_sale_id">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ المرتجع:</label>
                <input type="date" name="return_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: right; margin-bottom: 15px;">
                <thead>
                    <tr style="background: #f8f9fc; border-bottom: 1px solid #ddd;">
                        <th style="padding: 8px;">الصنف</th><th style="padding: 8px;">الكمية الأصلية</th><th style="padding: 8px;">المتاح للإرجاع</th><th style="padding: 8px;">كمية المرتجع</th>
                    </tr>
                </thead>
                <tbody id="returnItemsBody"></tbody>
            </table>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">سبب المرتجع / ملاحظات:</label>
                <textarea name="return_notes" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; height: 55px;"></textarea>
            </div>
            <p style="font-size: 12px; color: #888;">سيُعاد المخزون تلقائياً، وتُعكَس عمولة المندوب تناسبياً، ويُرحَّل قيد محاسبي عكسي للإيراد المرتجع.</p>
            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px;">
                <button type="button" onclick="closeReturnModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer;">إلغاء</button>
                <button type="submit" style="background: #e74a3b; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">تسجيل المرتجع</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>