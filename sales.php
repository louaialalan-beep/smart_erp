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
            $stmt = $conn->prepare("INSERT INTO sales (invoice_number, customer_name, representative_id, exchange_rate, total_amount_syp, total_amount_usd, total_commissions, payment_status, delivery_status, invoice_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoice_number, $customer_name, $representative_id, $exchange_rate, $total_syp, $total_usd, $total_comm, $payment_status, $delivery_status, $invoice_date]);
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
                $debit_account_id = findAccountId($conn, ['ذمم', 'عملاء', 'receivable'], 'ذمم العملاء');
            }

            // === إصلاح معماري جوهري: توقيت الاعتراف بالإيراد ===
            // طرف الدائن يعتمد الآن على حالة التسليم وقت إصدار الفاتورة:
            // - "تم التسليم" مباشرة: دائن إيرادات المبيعات الحقيقية (اعتراف فوري، مطابق لما كان سابقاً).
            // - "قيد الانتظار/مؤجلة": دائن "إيرادات مؤجلة" (التزام مؤقت)، ولا يُعترَف بالإيراد الحقيقي
            //   ولا COGS ولا عمولة المندوب إلا لاحقاً عند تأكيد التسليم فعلياً (عبر recognizeSaleRevenue()).
            //   هذا يمنع تسجيل ربح غير محقق لفواتير قد تُرتجَع بالكامل قبل التسليم أصلاً.
            if ($delivery_status === 'Delivered') {
                $credit_account_id = findAccountId($conn, ['مبيعات', 'إيراد', 'revenue', 'sales'], 'إيرادات المبيعات');
            } else {
                $credit_account_id = findAccountId($conn, ['إيراد مؤجل', 'ايراد مؤجل', 'deferred'], 'إيرادات مؤجلة');
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
            $conn->rollBack();
            $error = "<strong>خطأ أثناء الحفظ:</strong> " . $e->getMessage();
        }
    }
}

// تعديل البيانات للفواتير ضمن الشهر الحالي
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_sale'])) {
    $sale_id = intval($_POST['sale_id']);
    $customer_name = trim($_POST['customer_name']);
    $representative_id = intval($_POST['representative_id'] ?? 0);
    if ($representative_id <= 0) { $representative_id = null; }
    $payment_status = trim($_POST['payment_status']);
    $delivery_status = trim($_POST['delivery_status']);

    // تحقق دفاعي: اقبل فقط القيم المطابقة لتعريف ENUM الفعلي
    if (!array_key_exists($payment_status, $payment_labels)) {
        $payment_status = 'Unpaid';
    }
    if (!array_key_exists($delivery_status, $delivery_labels)) {
        $delivery_status = 'Pending';
    }

    $stmt_check = $conn->prepare("SELECT invoice_date FROM sales WHERE id = ?");
    $stmt_check->execute([$sale_id]);
    $inv_date = $stmt_check->fetchColumn();

    if (!$inv_date) {
        $error = "الفاتورة غير موجودة.";
    } elseif (date('Y-m', strtotime($inv_date)) !== $current_year_month) {
        $error = "عذراً، لا يمكن تعديل الفاتورة لأنها خارج نطاق الشهر الحالي.";
    } elseif (isDateInClosedPeriod($conn, $inv_date)) {
        $error = getPeriodLockErrorMessage($inv_date);
    } else {
        try {
            // معرفة حالة التسليم القديمة قبل التعديل، لمعرفة هل نحتاج الاعتراف بالإيراد أو عكسه
            $stmt_old_status = $conn->prepare("SELECT delivery_status FROM sales WHERE id = ?");
            $stmt_old_status->execute([$sale_id]);
            $old_delivery_status = $stmt_old_status->fetchColumn();

            $stmt_up = $conn->prepare("UPDATE sales SET customer_name = ?, representative_id = ?, payment_status = ?, delivery_status = ? WHERE id = ?");
            $stmt_up->execute([$customer_name, $representative_id, $payment_status, $delivery_status, $sale_id]);

            // تغيّرت حالة التسليم عبر نموذج التعديل: طبِّق نفس منطق الاعتراف بالإيراد/عكسه المُستخدَم
            // في زر "تأكيد/إلغاء التسليم" — بدل ترك القيود غير متسقة مع الحالة الجديدة
            if ($old_delivery_status !== 'Delivered' && $delivery_status === 'Delivered') {
                recognizeSaleRevenue($conn, $sale_id);
            } elseif ($old_delivery_status === 'Delivered' && $delivery_status !== 'Delivered') {
                deferSaleRevenue($conn, $sale_id);
            }

            $msg = "تم تحديث بيانات الفاتورة بنجاح!";
            logAudit($conn, 'UPDATE', 'فواتير المبيعات', "تعديل فاتورة رقم #{$sale_id} — العميل: {$customer_name}، حالة الدفع: {$payment_status}، حالة التسليم: {$delivery_status}", $sale_id);
        } catch (Exception $e) {
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
                $revenue_account_id = findAccountId($conn, ['مبيعات', 'إيراد', 'revenue', 'sales'], 'إيرادات المبيعات');
            } else {
                $revenue_account_id = findAccountId($conn, ['إيراد مؤجل', 'ايراد مؤجل', 'deferred'], 'إيرادات مؤجلة');
            }
            if ($sale['payment_status'] === 'Paid') {
                $other_account_id = findAccountId($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');
            } else {
                $other_account_id = findAccountId($conn, ['ذمم', 'عملاء', 'receivable'], 'ذمم العملاء');
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
            $conn->rollBack();
            $error = "خطأ أثناء تسجيل المرتجع: " . $e->getMessage();
        }
    }
}

// الاستعلامات للجدول والقوائم
$sql_sales = "SELECT s.*, r.name AS rep_name 
              FROM sales s 
              LEFT JOIN representatives r ON s.representative_id = r.id 
              ORDER BY s.id DESC";
$sales_list = $conn->query($sql_sales)->fetchAll(PDO::FETCH_ASSOC);

// جلب أصناف كل الفواتير مع الكمية المتبقية القابلة للإرجاع (لبناء نافذة المرتجع بالجافاسكريبت)
$items_by_sale = [];
$stmt_all_items = $conn->query("
    SELECT si.id, si.sale_id, si.product_id, si.quantity, si.unit_price_syp, p.product_name,
           COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0) AS already_returned
    FROM sale_items si
    LEFT JOIN products p ON si.product_id = p.id
");
foreach ($stmt_all_items->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $row['remaining'] = floatval($row['quantity']) - floatval($row['already_returned']);
    $items_by_sale[$row['sale_id']][] = $row;
}

$products_list = $conn->query("SELECT * FROM products ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
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

<!-- جدول عرض الفواتير -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #4e73df; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">رقم الفاتورة</th>
                    <th style="padding: 12px 15px;">اسم العميل</th>
                    <th style="padding: 12px 15px;">المندوب</th>
                    <th style="padding: 12px 15px; color: #2e59d9;">الإجمالي (SYP)</th>
                    <th style="padding: 12px 15px; color: #e74a3b;">الإجمالي (USD)</th>
                    <th style="padding: 12px 15px;">حالة الدفع</th>
                    <th style="padding: 12px 15px; color: #f6c23e;">حالة التسليم</th>
                    <th style="padding: 12px 15px;">التاريخ</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($sales_list) > 0): ?>
                    <?php foreach ($sales_list as $sale): 
                        $inv_month = date('Y-m', strtotime($sale['invoice_date']));
                        $is_current_month = ($inv_month === $current_year_month);

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
                    ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 12px 15px; font-weight: bold; color: #4e73df; font-family: monospace;"><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                            <td style="padding: 12px 15px; font-weight: 600; color: #333;"><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                            <td style="padding: 12px 15px; color: #555;"><?php echo htmlspecialchars($sale['rep_name'] ?: 'بدون مندوب'); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #2e59d9; font-weight: bold;"><?php echo number_format($sale['total_amount_syp'], 2); ?> ل.س</td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #e74a3b; font-weight: bold;">$<?php echo number_format($sale['total_amount_usd'], 2); ?></td>
                            <td style="padding: 12px 15px;">
                                <span class="status-badge" style="background: <?php echo $p_bg; ?>; color: <?php echo $p_color; ?>;">
                                    <?php echo htmlspecialchars($p_display); ?>
                                </span>
                            </td>
                            <td style="padding: 12px 15px;">
                                <span class="status-badge" style="background: <?php echo $d_bg; ?>; color: <?php echo $d_color; ?>;">
                                    <?php echo htmlspecialchars($d_display); ?>
                                </span>
                            </td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($sale['invoice_date']); ?></td>
                            <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                                <div style="display: inline-flex; gap: 6px; align-items: center;">
                                <?php if ($is_current_month): ?>
                                    <button onclick='openEditModal(<?php echo json_encode($sale, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="row-action-btn row-action-edit" title="تعديل الفاتورة">
                                        <i class="fas fa-pen"></i> تعديل
                                    </button>
                                <?php else: ?>
                                    <span style="color: #b0b7c3; font-size: 11px;">مغلق (أرشيف)</span>
                                <?php endif; ?>
                                <?php $items_for_sale = $items_by_sale[$sale['id']] ?? []; ?>
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
                        <td colspan="9" style="padding: 30px; text-align: center; color: #777;">لا توجد فواتير مبيعات مسجلة.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
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
                    <select name="representative_id" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="0">-- بدون مندوب --</option>
                        <?php foreach ($reps_list as $rep): ?>
                            <option value="<?php echo $rep['id']; ?>"><?php echo htmlspecialchars($rep['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">سعر الصرف المثبت تاريخياً:</label>
                    <input type="number" step="0.0001" name="exchange_rate" value="<?php echo htmlspecialchars($default_exchange_rate); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
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

            <hr style="border: 0; border-top: 1px solid #eee; margin: 15px 0;">

            <h4 style="margin: 0 0 10px 0; color: #4e73df;">أصناف المنتجات المباعة</h4>
            <div id="itemsContainer">
                <div class="sale-row" style="display: grid; grid-template-columns: 3fr 1fr 1fr 1fr auto; gap: 10px; margin-bottom: 10px; align-items: center;">
                    <select name="product_id[]" onchange="updateRowDetails(this)" required style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="">-- اختر المنتج --</option>
                        <?php foreach ($products_list as $prod): 
                            $p_price = $prod['retail_price_syp'] ?? $prod['wholesale_price_syp'] ?? $prod['special_price_syp'] ?? $prod['unit_price_syp'] ?? $prod['price'] ?? 0;
                            $p_comm = $prod['commission_syp'] ?? $prod['commission'] ?? $prod['commission_amount'] ?? 0;
                        ?>
                            <option value="<?php echo $prod['id']; ?>" data-price="<?php echo floatval($p_price); ?>" data-commission="<?php echo floatval($p_comm); ?>">
                                <?php echo htmlspecialchars($prod['product_name']); ?> (متوفر: <?php echo $prod['current_quantity']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
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

<!-- Modal تعديل الفاتورة -->
<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; overflow-y: auto;">
    <div style="background: white; width: 550px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); margin: 30px auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #f6c23e;"><i class="fas fa-edit"></i> تعديل بيانات الفاتورة</h3>
            <button onclick="closeEditModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="edit_sale" value="1">
            <input type="hidden" name="sale_id" id="edit_sale_id">

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">اسم العميل:</label>
                <input type="text" name="customer_name" id="edit_customer_name" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">المندوب المسؤول:</label>
                <select name="representative_id" id="edit_representative_id" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="0">-- بدون مندوب --</option>
                    <?php foreach ($reps_list as $rep): ?>
                        <option value="<?php echo $rep['id']; ?>"><?php echo htmlspecialchars($rep['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
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
    }

    function closeSaleModal() {
        document.getElementById('saleModal').style.display = 'none';
    }

    function openEditModal(saleData) {
        document.getElementById('edit_sale_id').value = saleData.id;
        document.getElementById('edit_customer_name').value = saleData.customer_name;
        document.getElementById('edit_representative_id').value = saleData.representative_id || 0;
        document.getElementById('edit_payment_status').value = saleData.payment_status;
        document.getElementById('edit_delivery_status').value = saleData.delivery_status;
        document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function updateRowDetails(selectElem) {
        var row = selectElem.closest('.sale-row');
        if (!row) return;

        var opt = selectElem.options[selectElem.selectedIndex];
        var priceInput = row.querySelector('input[name="unit_price_syp[]"]');
        var commInput = row.querySelector('input[name="commission_amount[]"]');

        if (opt && opt.value !== "") {
            var price = opt.getAttribute('data-price');
            var comm = opt.getAttribute('data-commission');

            if (priceInput) priceInput.value = (price !== null && price !== "") ? price : 0;
            if (commInput) commInput.value = (comm !== null && comm !== "") ? comm : 0;
        } else {
            if (priceInput) priceInput.value = '';
            if (commInput) commInput.value = '';
        }
    }

    function addRow() {
        var container = document.getElementById('itemsContainer');
        var firstRow = container.querySelector('.sale-row');
        var newRow = firstRow.cloneNode(true);
        
        newRow.querySelectorAll('input').forEach(function(i) { i.value = ''; });
        var select = newRow.querySelector('select');
        select.selectedIndex = 0;
        select.onchange = function() { updateRowDetails(this); };
        
        container.appendChild(newRow);
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