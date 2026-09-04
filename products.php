<?php
/**
 * صفحة إدارة المنتجات والمخزون الاحترافية - Smart ERP
 * تم تطبيق معايير الأمان، التحقق من المدخلات، وتحسين واجهة المستخدم.
 */
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

// التأكد من وجود اتصال قاعدة البيانات
if (!isset($conn)) {
    die("خطأ: اتصال قاعدة البيانات غير متوفر.");
}

$msg = "";
$error = "";

// دالة محلية لترحيل قيد يدعم المبلغ الأجنبي (foreign_debit/foreign_credit) — نفس تعريف الدالة
// المستخدَمة في Purchases.php، لأن postJournalLine في system_helpers.php لا تدعم هذين الحقلين،
// وهذا الملف لا يُحمَّل أبداً في نفس الطلب مع Purchases.php (كل صفحة تُفتح مستقلة)، فلا تعارض تسمية.
function insertJournalLine($conn, $account_id, $debit, $credit, $entry_number, $entry_date, $description, $source_module, $currency_code = 'SYP', $exchange_rate = 1, $foreign_debit = null, $foreign_credit = null) {
    $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
    $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);
    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
    $vals = [$account_id, $entry_date, $description, $debit, $credit];
    if (in_array('entry_number', $existing_cols)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_number; }
    if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = $currency_code; }
    if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $exchange_rate; }
    if ($foreign_debit !== null && in_array('foreign_debit', $existing_cols)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = $foreign_debit; }
    if ($foreign_credit !== null && in_array('foreign_credit', $existing_cols)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = $foreign_credit; }
    if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = $source_module; }
    $placeholders = implode(',', array_fill(0, count($cols_to_insert), '?'));
    $col_names = implode(',', $cols_to_insert);
    $conn->prepare("INSERT INTO journal_entries ({$col_names}) VALUES ({$placeholders})")->execute($vals);
}

// معرفة أعمدة جدول products ديناميكياً (تُستخدم لدعم purchased_quantity إن وُجد دون كسر التوافق)
$products_cols_stmt = $conn->query("SHOW COLUMNS FROM products");
$products_existing_cols = $products_cols_stmt->fetchAll(PDO::FETCH_COLUMN);
$has_purchased_quantity_col = in_array('purchased_quantity', $products_existing_cols);

// التأكد من وجود جدول التصنيفات (بنفس أسلوب الإنشاء الذاتي المعتمد في بقية صفحات النظام)
$conn->exec("CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(150) NOT NULL
)");

// 0. إضافة تصنيف جديد — لم تكن هذه الميزة موجودة إطلاقاً سابقاً، القائمة المنسدلة كانت تعرض فقط
// التصنيفات الموجودة أصلاً في القاعدة دون أي وسيلة لإضافة تصنيف جديد منها.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category'])) {
    $new_category_name = trim($_POST['category_name'] ?? '');
    if (empty($new_category_name)) {
        $error = "خطأ: اسم التصنيف لا يمكن أن يكون فارغاً.";
    } else {
        try {
            $check_cat = $conn->prepare("SELECT id FROM categories WHERE category_name = ?");
            $check_cat->execute([$new_category_name]);
            if ($check_cat->rowCount() > 0) {
                $error = "خطأ: يوجد تصنيف بنفس هذا الاسم مسبقاً.";
            } else {
                $conn->prepare("INSERT INTO categories (category_name) VALUES (?)")->execute([$new_category_name]);
                $new_cat_id = $conn->lastInsertId();
                logAudit($conn, 'INSERT', 'التصنيفات', "إضافة تصنيف جديد: $new_category_name", $new_cat_id);
                $msg = "تمت إضافة التصنيف \"$new_category_name\" بنجاح.";
            }
        } catch (PDOException $e) {
            $error = "خطأ في قاعدة البيانات أثناء إضافة التصنيف: " . $e->getMessage();
        }
    }
}

// 0-ب. إضافة/زيادة جرد مكتبي (بضاعة بلا مورد) — ميزة جديدة تحل محل القيد اليدوي المعزول في دفتر
// اليومية الذي كان يُستخدَم سابقاً لهذا الغرض (ويُنتج رصيداً "وهمياً" غير مرتبط بأي منتج فعلي، لا
// يُخصَم تلقائياً عند البيع). هنا: الكمية والتكلفة تُدخَلان معاً، تُربَطان بمنتج فعلي مباشرة (جديد أو
// موجود بلا مورد)، ويُرحَّل قيد محاسبي مرتبط بهذا المنتج تحديداً (وليس مبلغاً إجمالياً معزولاً) — فيُخصَم
// تلقائياً عبر COGS عند البيع تماماً كأي منتج آخر يأتي من مورد.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_office_inventory'])) {
    $oi_mode = $_POST['oi_mode'] ?? 'new'; // 'new' أو 'existing'
    $oi_qty = filter_var($_POST['oi_quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
    $oi_cost_usd = filter_var($_POST['oi_cost_price_usd'] ?? 0, FILTER_VALIDATE_FLOAT);
    $oi_date = $_POST['oi_date'] ?? date('Y-m-d');
    $oi_notes = trim($_POST['oi_notes'] ?? '');

    if ($oi_qty <= 0 || $oi_cost_usd <= 0) {
        $error = "خطأ: الكمية وتكلفة الوحدة بالدولار يجب أن تكونا أكبر من صفر.";
    } else {
        try {
            $conn->beginTransaction();
            $oi_product_id = null;
            $oi_product_name = '';
            $oi_sku = '';

            if ($oi_mode === 'existing') {
                $oi_product_id = intval($_POST['oi_existing_product_id'] ?? 0);
                $stmt_chk = $conn->prepare("SELECT product_name, sku, supplier_id FROM products WHERE id = ?");
                $stmt_chk->execute([$oi_product_id]);
                $existing_p = $stmt_chk->fetch(PDO::FETCH_ASSOC);
                if (!$existing_p) { throw new Exception("المنتج المحدَّد غير موجود."); }
                if ($existing_p['supplier_id'] !== null) { throw new Exception("هذا المنتج مرتبط بمورد — الجرد المكتبي مخصَّص فقط للمنتجات بلا مورد. استخدم فاتورة شراء بدلاً من ذلك."); }
                $oi_product_name = $existing_p['product_name'];
                $oi_sku = $existing_p['sku'];
                $conn->prepare("UPDATE products SET current_quantity = current_quantity + ?, purchased_quantity = purchased_quantity + ?, cost_price_usd = ? WHERE id = ?")
                     ->execute([$oi_qty, $oi_qty, $oi_cost_usd, $oi_product_id]);
            } else {
                $oi_product_name = trim($_POST['oi_new_product_name'] ?? '');
                $oi_sku = trim($_POST['oi_new_sku'] ?? '');
                $oi_category_id = !empty($_POST['oi_category_id']) ? filter_var($_POST['oi_category_id'], FILTER_VALIDATE_INT) : null;
                $oi_base_unit = trim($_POST['oi_base_unit'] ?? 'قطعة');
                if (empty($oi_product_name) || empty($oi_sku)) { throw new Exception("اسم المنتج والباركود إجباريان."); }
                $check_sku = $conn->prepare("SELECT id FROM products WHERE sku = ?");
                $check_sku->execute([$oi_sku]);
                if ($check_sku->rowCount() > 0) { throw new Exception("رمز الباركود (SKU) مستخدم مسبقاً."); }
                $conn->prepare("INSERT INTO products (product_name, sku, category_id, cost_price_usd, wholesale_price_syp, retail_price_syp, special_price_syp, base_unit, current_quantity, purchased_quantity, supplier_id) VALUES (?, ?, ?, ?, 0, 0, 0, ?, ?, ?, NULL)")
                     ->execute([$oi_product_name, $oi_sku, $oi_category_id, $oi_cost_usd, $oi_base_unit, $oi_qty, $oi_qty]);
                $oi_product_id = $conn->lastInsertId();
            }

            // القيد المحاسبي: مدين المخزون (يدخل أصل جديد) / دائن "أرصدة افتتاحية" (نفس الحساب المعتمد
            // أصلاً في النظام لتوثيق بضاعة موجودة فعلياً قبل تسجيلها كفاتورة شراء حقيقية) — لكن هنا
            // مرتبط بمنتج محدَّد عبر entry_number، وليس مبلغاً إجمالياً معزولاً كما كان سابقاً.
            $exchange_rate = getExchangeRateForDate($conn, 'USD', $oi_date);
            $amount_syp = $oi_qty * $oi_cost_usd * $exchange_rate;
            $inv_acc = findOrCreateAccount($conn, ['مخزون', 'بضاعة', 'inventory'], 'المخزون', 'Asset');
            $equity_acc = findOrCreateAccount($conn, ['أرصدة افتتاحية', 'opening'], 'أرصدة افتتاحية', 'Equity');
            if ($inv_acc && $equity_acc) {
                $oi_entry_num = "JE-OFFICE-" . $oi_product_id . "-" . time();
                $oi_desc = "إضافة جرد مكتبي: $oi_product_name (SKU: $oi_sku) — $oi_qty وحدة × \$$oi_cost_usd" . (!empty($oi_notes) ? " — $oi_notes" : "");
                insertJournalLine($conn, $inv_acc, $amount_syp, 0, $oi_entry_num, $oi_date, $oi_desc, 'Office Inventory', 'USD', $exchange_rate, $oi_qty * $oi_cost_usd, 0);
                insertJournalLine($conn, $equity_acc, 0, $amount_syp, $oi_entry_num, $oi_date, $oi_desc, 'Office Inventory', 'USD', $exchange_rate, 0, $oi_qty * $oi_cost_usd);
            }

            $conn->commit();
            logAudit($conn, 'INSERT', 'الجرد المكتبي', "إضافة جرد مكتبي: $oi_product_name (SKU: $oi_sku) — كمية $oi_qty بتكلفة \$$oi_cost_usd للوحدة", $oi_product_id);
            $msg = "تم تسجيل الجرد المكتبي وتحديث المخزون وترحيل القيد المحاسبي بنجاح!";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ أثناء تسجيل الجرد المكتبي: " . $e->getMessage();
        }
    }
}


// 1. معالجة إضافة منتج جديد مع حماية كاملة للمدخلات
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    // التحقق من رمز التحقق CSRF إذا وجد أو تنظيف المدخلات بصرامة
    $product_name        = trim($_POST['product_name'] ?? '');
    $sku                 = trim($_POST['sku'] ?? '');
    $category_id         = !empty($_POST['category_id']) ? filter_var($_POST['category_id'], FILTER_VALIDATE_INT) : null;
    // تصحيح معماري إضافي: نفس مبدأ فك الربط المطبَّق على الكمية يُطبَّق الآن على التكلفة أيضاً —
    // مصدر الحقيقة الوحيد لـ cost_price_usd هو فاتورة الشراء (purchases.php) حصراً، وليس إدخالاً يدوياً
    // هنا. المنتج الجديد يُنشأ بتكلفة صفر دائماً، وتُحدَّد تلقائياً عند تسجيل أول فاتورة شراء له.
    $cost_price_usd      = 0;
    $wholesale_price_syp = filter_var($_POST['wholesale_price_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $retail_price_syp    = filter_var($_POST['retail_price_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    // السعر الخاص أُزيل من نموذج الإضافة بناءً على الطلب؛ يبقى العمود موجوداً بقاعدة البيانات
    // (إن وُجد) ويُخزَّن صفراً افتراضياً، ويمكن تحديده لاحقاً عبر التعديل إن لزم مستقبلاً.
    $special_price_syp   = 0;
    $base_unit           = trim($_POST['base_unit'] ?? 'قطعة');
    $packing_unit        = trim($_POST['packing_unit'] ?? '');
    // تصحيح معماري: فك الربط بين إنشاء المنتج ودخول البضاعة للمخزون. المنتج الجديد يُنشأ دائماً
    // بمخزون صفري — الكمية الفعلية تدخل حصراً عبر فاتورة شراء حقيقية (purchases.php)، فيبقى
    // purchase_invoice_items المصدر الوحيد لكل حسابات المشتريات دون أي استثناءات لاحقاً.
    $current_quantity    = 0;
    $supplier_id         = !empty($_POST['supplier_id']) ? filter_var($_POST['supplier_id'], FILTER_VALIDATE_INT) : null;

    // التحقق من الحقول الإجبارية
    if (empty($product_name) || empty($sku)) {
        $error = "خطأ: اسم المنتج ورمز الباركود (SKU) حقول إجبارية لا يمكن تركها فارغة.";
    } else {
        try {
            // التحقق مسبقاً إذا كان SKU مستخدماً لتجنب خطأ قاعدة البيانات المباشر
            $check_sku = $conn->prepare("SELECT id FROM products WHERE sku = ?");
            $check_sku->execute([$sku]);
            if ($check_sku->rowCount() > 0) {
                $error = "خطأ: رمز الباركود (SKU) مستخدم مصداقاً لصنف آخر، يرجى اختيار رمز فريد.";
            } else {
                // ملاحظة: purchased_quantity لا يُملأ هنا إطلاقاً بعد الآن — يبقى 0 حتى تُسجَّل أول
                // فاتورة شراء حقيقية له عبر purchases.php، وهي التي تُنشئ current_quantity وpurchased_quantity معاً.
                $cols = ['product_name', 'sku', 'category_id', 'cost_price_usd', 'wholesale_price_syp', 'retail_price_syp', 'special_price_syp', 'base_unit', 'packing_unit', 'current_quantity', 'supplier_id'];
                $vals = [$product_name, $sku, $category_id, $cost_price_usd, $wholesale_price_syp, $retail_price_syp, $special_price_syp, $base_unit, $packing_unit, $current_quantity, $supplier_id];

                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $col_names = implode(',', $cols);
                $stmt = $conn->prepare("INSERT INTO products ({$col_names}) VALUES ({$placeholders})");
                $stmt->execute($vals);
                $new_prod_id = $conn->lastInsertId();
                logAudit($conn, 'INSERT', 'المنتجات والمخزون', "إضافة منتج: $product_name (SKU: $sku) بتكلفة $" . number_format($cost_price_usd, 4) . " — بلا مخزون أولي (يُضاف عبر فاتورة شراء)", $new_prod_id);

                $msg = "تم إضافة المنتج بنجاح! توجّه إلى (فواتير الشراء) لتسجيل أول كمية تدخل المخزون منه.";
            }
        } catch (PDOException $e) {
            $error = "خطأ في قاعدة البيانات أثناء الحفظ: " . $e->getMessage();
        }
    }
}

// 2. معالجة تعديل منتج موجود
// ملاحظة: لا يُعدَّل purchased_quantity من هنا عمداً — فهو سجل تاريخي لقيمة الشراء الأصلية.
// تعديل current_quantity هنا يُستخدم فقط لتصحيح المخزون الحالي (جرد يدوي) ولا يمس ذلك السجل.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_product'])) {
    $product_id          = intval($_POST['product_id'] ?? 0);
    $product_name        = trim($_POST['product_name'] ?? '');
    $sku                 = trim($_POST['sku'] ?? '');
    $category_id         = !empty($_POST['category_id']) ? filter_var($_POST['category_id'], FILTER_VALIDATE_INT) : null;
    // تصحيح معماري: cost_price_usd لم يعد يُقبَل من هذا النموذج إطلاقاً — القيمة المُرسَلة (إن وُجدت)
    // تُتجاهَل تماماً؛ التعديل الوحيد المسموح للتكلفة يكون عبر فاتورة شراء جديدة في purchases.php.
    $wholesale_price_syp = filter_var($_POST['wholesale_price_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $retail_price_syp    = filter_var($_POST['retail_price_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $base_unit           = trim($_POST['base_unit'] ?? 'قطعة');
    $packing_unit        = trim($_POST['packing_unit'] ?? '');
    $current_quantity    = filter_var($_POST['current_quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
    $supplier_id         = !empty($_POST['supplier_id']) ? filter_var($_POST['supplier_id'], FILTER_VALIDATE_INT) : null;

    if ($product_id <= 0) {
        $error = "خطأ: معرف المنتج غير صالح.";
    } elseif (empty($product_name) || empty($sku)) {
        $error = "خطأ: اسم المنتج ورمز الباركود (SKU) حقول إجبارية لا يمكن تركها فارغة.";
    } else {
        try {
            // التحقق أن SKU الجديد غير مستخدم من قِبل منتج آخر (باستثناء المنتج الحالي نفسه)
            $check_sku = $conn->prepare("SELECT id FROM products WHERE sku = ? AND id != ?");
            $check_sku->execute([$sku, $product_id]);
            if ($check_sku->rowCount() > 0) {
                $error = "خطأ: رمز الباركود (SKU) مستخدم مسبقاً لصنف آخر، يرجى اختيار رمز فريد.";
            } else {
                $stmt_old = $conn->prepare("SELECT current_quantity, cost_price_usd FROM products WHERE id = ?");
                $stmt_old->execute([$product_id]);
                $old_vals = $stmt_old->fetch(PDO::FETCH_ASSOC);

                // ملاحظة: cost_price_usd مُستثنى عمداً من جملة UPDATE هذه — يبقى كما رحَّلته آخر
                // فاتورة شراء فقط، ولا يُمكن لأي مستخدم تغييره من هذا النموذج مهما أرسل في الطلب.
                $stmt = $conn->prepare("UPDATE products SET 
                    product_name = ?, sku = ?, category_id = ?, 
                    wholesale_price_syp = ?, retail_price_syp = ?, base_unit = ?, 
                    packing_unit = ?, current_quantity = ?, supplier_id = ? 
                    WHERE id = ?");
                $stmt->execute([
                    $product_name, $sku, $category_id,
                    $wholesale_price_syp, $retail_price_syp, $base_unit,
                    $packing_unit, $current_quantity, $supplier_id,
                    $product_id
                ]);

                // تصحيح جوهري: تعديل الكمية هنا (جرد يدوي) كان يُحدِّث current_quantity بلا أي قيد
                // محاسبي مقابل — فيبقى حساب "المخزون" في دفتر الأستاذ بلا علاقة بالكمية الفعلية الجديدة،
                // نفس فئة الخلل المكتشفة سابقاً في القيود اليدوية المعزولة (245,256 ل.س وهمية). الآن أي
                // فرق (qty_delta) يُرحَّل فوراً كقيد "تسوية مخزون" حقيقي: زيادة الكمية = ربح مخزون (دائن
                // حساب تسوية، مدين المخزون)، نقصانها = خسارة/هالك (العكس)، بالتكلفة الحالية للمنتج.
                $old_qty = floatval($old_vals['current_quantity'] ?? 0);
                $qty_delta = $current_quantity - $old_qty;
                $product_cost_usd = floatval($old_vals['cost_price_usd'] ?? 0);
                if (abs($qty_delta) > 0.00001 && $product_cost_usd > 0) {
                    $adj_rate = getExchangeRateForDate($conn, 'USD', date('Y-m-d'));
                    $adj_amount_usd = abs($qty_delta) * $product_cost_usd;
                    $adj_amount_syp = $adj_amount_usd * $adj_rate;
                    $inv_acc = findOrCreateAccount($conn, ['مخزون', 'بضاعة', 'inventory'], 'المخزون', 'Asset');
                    $adj_acc = findOrCreateAccount($conn, ['تسوية المخزون', 'فروقات جرد'], 'تسوية المخزون (جرد)', 'Expense');
                    if ($inv_acc && $adj_acc) {
                        $adj_entry_num = "JE-STOCKADJ-" . $product_id . "-" . time();
                        $adj_desc = "تسوية جرد يدوي للمنتج: $product_name (SKU: $sku) — من " . number_format($old_qty, 2) . " إلى " . number_format($current_quantity, 2);
                        if ($qty_delta > 0) {
                            // زيادة الكمية: مدين المخزون (يزيد الأصل) / دائن تسوية المخزون (ربح/تخفيض مصروف)
                            insertJournalLine($conn, $inv_acc, $adj_amount_syp, 0, $adj_entry_num, date('Y-m-d'), $adj_desc, 'Inventory Adjustment', 'USD', $adj_rate, $adj_amount_usd, 0);
                            insertJournalLine($conn, $adj_acc, 0, $adj_amount_syp, $adj_entry_num, date('Y-m-d'), $adj_desc, 'Inventory Adjustment', 'USD', $adj_rate, 0, $adj_amount_usd);
                        } else {
                            // نقصان الكمية: دائن المخزون (ينقص الأصل) / مدين تسوية المخزون (خسارة/هالك)
                            insertJournalLine($conn, $adj_acc, $adj_amount_syp, 0, $adj_entry_num, date('Y-m-d'), $adj_desc, 'Inventory Adjustment', 'USD', $adj_rate, $adj_amount_usd, 0);
                            insertJournalLine($conn, $inv_acc, 0, $adj_amount_syp, $adj_entry_num, date('Y-m-d'), $adj_desc, 'Inventory Adjustment', 'USD', $adj_rate, 0, $adj_amount_usd);
                        }
                    }
                }

                $log_details = "تعديل منتج: $product_name (SKU: $sku)";
                if ($old_vals && floatval($old_vals['current_quantity']) != $current_quantity) {
                    $log_details .= " — تصحيح المخزون من " . number_format($old_vals['current_quantity'], 2) . " إلى " . number_format($current_quantity, 2) . " (قيد تسوية مُرحَّل تلقائياً)";
                }
                logAudit($conn, 'UPDATE', 'المنتجات والمخزون', $log_details, $product_id);

                $msg = "تم تحديث بيانات المنتج بنجاح!";
            }
        } catch (PDOException $e) {
            $error = "خطأ في قاعدة البيانات أثناء التحديث: " . $e->getMessage();
        }
    }
}

// 3. معاملات البحث والفلترة الآمنة
$search           = trim($_GET['search'] ?? '');
$filter_category  = $_GET['category_id'] ?? '';
$filter_supplier  = $_GET['supplier_id'] ?? '';
$filter_source    = $_GET['source'] ?? ''; // 'supplier' أو 'office' أو فارغ = الكل

$where  = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[]  = "(p.product_name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!empty($filter_category)) {
    $where[]  = "p.category_id = ?";
    $params[] = $filter_category;
}
if (!empty($filter_supplier)) {
    $where[]  = "p.supplier_id = ?";
    $params[] = $filter_supplier;
}
if ($filter_source === 'supplier') {
    $where[] = "p.supplier_id IS NOT NULL";
} elseif ($filter_source === 'office') {
    $where[] = "p.supplier_id IS NULL";
}

// إحصائيات "منتجات الموردين" مقابل "منتجات المكتب" (بلا مورد — بضاعة أُدخلت مباشرة كأصل جرد مكتبي)
// بغض النظر عن فلاتر البحث/التصنيف الحالية، لتبقى الأرقام مرجعية ثابتة يمكن الضغط عليها للتصفية.
$stmt_src_counts = $conn->query("
    SELECT
        SUM(CASE WHEN supplier_id IS NOT NULL THEN 1 ELSE 0 END) AS supplier_products_count,
        SUM(CASE WHEN supplier_id IS NOT NULL THEN current_quantity ELSE 0 END) AS supplier_products_qty,
        SUM(CASE WHEN supplier_id IS NULL THEN 1 ELSE 0 END) AS office_products_count,
        SUM(CASE WHEN supplier_id IS NULL THEN current_quantity ELSE 0 END) AS office_products_qty
    FROM products
");
$src_counts = $stmt_src_counts->fetch(PDO::FETCH_ASSOC);

// 4. استعلام جلب المنتجات مع التصنيفات والموردين (Prepared Statements)
$sql = "SELECT p.*, c.category_name, s.supplier_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        LEFT JOIN suppliers s ON p.supplier_id = s.id 
        WHERE " . implode(" AND ", $where) . " 
        ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب التصنيفات والموردين للقوائم المنسدلة
$categories = $conn->query("SELECT id, category_name FROM categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$suppliers  = $conn->query("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$office_products = $conn->query("SELECT id, product_name, sku, current_quantity, base_unit FROM products WHERE supplier_id IS NULL ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// توليد الباركود/SKU التالي تلقائياً — يتبع نمط "PRD-XXX" الموجود أصلاً في القاعدة، برقم تسلسلي
// أكبر رقم حالي + 1. حقل الإدخال يبقى قابلاً للتعديل يدوياً إن أراد المستخدم رمزاً مختلفاً.
$stmt_next_sku = $conn->query("SELECT sku FROM products WHERE sku LIKE 'PRD-%' ORDER BY CAST(SUBSTRING(sku, 5) AS UNSIGNED) DESC LIMIT 1");
$last_sku = $stmt_next_sku->fetchColumn();
$next_sku_number = 1;
if ($last_sku && preg_match('/PRD-(\d+)/', $last_sku, $m)) {
    $next_sku_number = intval($m[1]) + 1;
}
$auto_sku = 'PRD-' . str_pad($next_sku_number, 3, '0', STR_PAD_LEFT);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="color: #2e384d; margin-bottom: 5px;">إدارة المنتجات والمخزون (Products Module)</h2>
        <p style="color: #6c757d; margin: 0; font-size: 14px;">التحكم بالأصناف، تكاليف البضائع بالدولار، أسعار البيع، والتتبع اللحظي للمخزون.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <button onclick="toggleOfficeInventoryModal(true)" style="background: #8b5cf6; color: white; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <i class="fas fa-warehouse"></i> إضافة جرد مكتبي
        </button>
        <button onclick="toggleProductModal(true)" style="background: #1cc88a; color: white; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <i class="fas fa-plus"></i> إضافة صنف جديد
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- بطاقتا "منتجات الموردين" و"منتجات المكتب" — إحصاء + فلتر بالضغط -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px;">
    <a href="?<?php echo http_build_query(array_merge($_GET, ['source' => $filter_source === 'supplier' ? '' : 'supplier'])); ?>" style="text-decoration: none;">
        <div style="background: #eaf1fc; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px; <?php echo $filter_source === 'supplier' ? 'box-shadow: 0 0 0 2px #4e73df inset;' : ''; ?>">
            <div style="color: #2c4e9c; font-size: 13px; font-weight: bold;"><i class="fas fa-truck"></i> منتجات الموردين (مشتراة)</div>
            <div style="font-size: 22px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo intval($src_counts['supplier_products_count']); ?> <span style="font-size: 12px; font-weight: normal;">صنف</span></div>
            <div style="font-size: 12.5px; color: #2c4e9c; font-family: monospace; margin-top: 3px;">إجمالي الكمية بالمخزون: <?php echo rtrim(rtrim(number_format($src_counts['supplier_products_qty'], 2), '0'), '.'); ?></div>
        </div>
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['source' => $filter_source === 'office' ? '' : 'office'])); ?>" style="text-decoration: none;">
        <div style="background: #eafaf1; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 6px; <?php echo $filter_source === 'office' ? 'box-shadow: 0 0 0 2px #1cc88a inset;' : ''; ?>">
            <div style="color: #1a8f5f; font-size: 13px; font-weight: bold;"><i class="fas fa-building"></i> منتجات المكتب (جرد مباشر بلا مورد)</div>
            <div style="font-size: 22px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;"><?php echo intval($src_counts['office_products_count']); ?> <span style="font-size: 12px; font-weight: normal;">صنف</span></div>
            <div style="font-size: 12.5px; color: #1a8f5f; font-family: monospace; margin-top: 3px;">إجمالي الكمية بالمخزون: <?php echo rtrim(rtrim(number_format($src_counts['office_products_qty'], 2), '0'), '.'); ?></div>
        </div>
    </a>
</div>

<!-- شريط البحث والفلترة الاحترافي -->
<div style="background: #f8f9fc; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58, 59, 69, 0.05);">
    <form method="GET" action="" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 2; min-width: 220px;">
            <label style="font-size: 13px; font-weight: bold; color: #4e73df;">بحث بالاسم أو الباركود (SKU):</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="أدخل اسم المنتج أو رمز الباركود..." style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; margin-top: 5px;">
        </div>
        <div style="flex: 1; min-width: 160px;">
            <label style="font-size: 13px; font-weight: bold; color: #4e73df;">التصنيف:</label>
            <div style="display: flex; gap: 6px; margin-top: 5px;">
                <select name="category_id" style="flex: 1; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                    <option value="">-- كافة التصنيفات --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="openAddCategoryModal()" title="إضافة تصنيف جديد" style="background: #1cc88a; color: white; border: none; width: 38px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 16px;">+</button>
            </div>
        </div>
        <div style="flex: 1; min-width: 160px;">
            <label style="font-size: 13px; font-weight: bold; color: #4e73df;">المورد الأساسي:</label>
            <select name="supplier_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; margin-top: 5px; background: #fff;">
                <option value="">-- كافة الموردين --</option>
                <?php foreach ($suppliers as $sup): ?>
                    <option value="<?php echo $sup['id']; ?>" <?php echo $filter_supplier == $sup['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sup['supplier_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 10px 18px; border-radius: 6px; cursor: pointer; font-weight: bold;">بحث وتصفية</button>
            <a href="products.php" style="background: #858796; color: white; padding: 10px 15px; border-radius: 6px; text-decoration: none; display: inline-block; font-size: 13px; margin-right: 5px; font-weight: bold;">إلغاء الفلتر</a>
        </div>
    </form>
</div>

<!-- جدول عرض المنتجات والمخزون -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #4e73df; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">الباركود / SKU</th>
                    <th style="padding: 12px 15px;">اسم المنتج</th>
                    <th style="padding: 12px 15px;">التصنيف</th>
                    <th style="padding: 12px 15px; color: #e74a3b;">التكلفة (USD)</th>
                    <th style="padding: 12px 15px;">سعر الجملة (ل.س)</th>
                    <th style="padding: 12px 15px;">سعر المفرق (ل.س)</th>
                    <th style="padding: 12px 15px;">المخزون الحالي</th>
                    <th style="padding: 12px 15px;">المورد الأساسي</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $prod): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1; transition: background 0.2s;" onmouseover="this.style.background='#f8f9fc'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #4e73df;">
                                <?php echo htmlspecialchars($prod['sku']); ?>
                            </td>
                            <td style="padding: 12px 15px; font-weight: 600; color: #333;">
                                <?php echo htmlspecialchars($prod['product_name']); ?>
                            </td>
                            <td style="padding: 12px 15px; color: #666;">
                                <?php echo htmlspecialchars($prod['category_name'] ?: 'غير مصنف'); ?>
                            </td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #e74a3b; font-weight: bold;">
                                $<?php echo number_format($prod['cost_price_usd'], 4); ?>
                            </td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #1cc88a; font-weight: bold;">
                                <?php echo number_format($prod['wholesale_price_syp'], 2); ?>
                            </td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #2e59d9; font-weight: bold;">
                                <?php echo number_format($prod['retail_price_syp'], 2); ?>
                            </td>
                            <td style="padding: 12px 15px; font-family: monospace; font-weight: bold;">
                                <span style="background: #eaecf4; padding: 4px 10px; border-radius: 4px; color: #333; display: inline-block;">
                                    <?php echo number_format($prod['current_quantity'], 2); ?> <?php echo htmlspecialchars($prod['base_unit']); ?>
                                </span>
                            </td>
                            <td style="padding: 12px 15px; color: #555; font-size: 13px;">
                                <?php echo htmlspecialchars($prod['supplier_name'] ?: 'بدون مورد'); ?>
                            </td>
                            <td style="padding: 12px 15px; text-align: center;">
                                <button onclick='openEditProductModal(<?php echo json_encode($prod, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #f6c23e; color: white; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">
                                    <i class="fas fa-edit"></i> تعديل
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="padding: 40px; text-align: center; color: #777;">
                            <i class="fas fa-box-open" style="font-size: 35px; color: #ccc; margin-bottom: 10px; display: block;"></i>
                            لا توجد منتجات مسجلة مطابقة لخيارات البحث أو الفلترة الحالية.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- نافذة إضافة صنف جديد (Modal) -->
<div id="productModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1050; justify-content: center; align-items: center;">
    <div style="background: white; width: 900px; max-width: 95%; border-radius: 8px; padding: 25px; max-height: 90vh; overflow-y: auto; box-shadow: 0 5px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e3e6f0; padding-bottom: 12px; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #4e73df;"><i class="fas fa-plus-circle"></i> إضافة صنف منتج جديد وتحديد التسعير والمخزون</h3>
            <button onclick="toggleProductModal(false)" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #888; line-height: 1;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_product" value="1">

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">اسم المنتج: <span style="color: red;">*</span></label>
                    <input type="text" name="product_name" required placeholder="مثال: لابتوب ديل / سكر ناعم..." style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">الباركود / SKU: <span style="color: red;">*</span></label>
                    <input type="text" name="sku" required value="<?php echo htmlspecialchars($auto_sku); ?>" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">التصنيف أو الفئة:</label>
                    <div style="display: flex; gap: 6px;">
                        <select name="category_id" style="flex: 1; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                            <option value="">-- اختر التصنيف --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="openAddCategoryModal()" title="إضافة تصنيف جديد" style="background: #1cc88a; color: white; border: none; width: 38px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 16px;">+</button>
                    </div>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">المورد الأساسي:</label>
                    <select name="supplier_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                        <option value="">-- اختر المورد الأساسي --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h4 style="margin: 20px 0 10px 0; border-bottom: 1px solid #e3e6f0; padding-bottom: 5px; color: #4e73df;"><i class="fas fa-dollar-sign"></i> أسعار البيع (ل.س):</h4>
            <div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 10px 14px; border-radius: 6px; margin-bottom: 15px; color: #0c5460; font-size: 12.5px;">
                <i class="fas fa-info-circle"></i> سعر التكلفة بالدولار (COGS) لا يُدخَل هنا — يبدأ صفراً، ويُحدَّد تلقائياً وحصراً من <strong>فاتورة الشراء</strong> الأولى لهذا المنتج في صفحة "فواتير الشراء".
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">سعر البيع جملة (ل.س):</label>
                <input type="number" step="0.01" name="wholesale_price_syp" value="0.00" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">سعر البيع مفرق (ل.س):</label>
                <input type="number" step="0.01" name="retail_price_syp" value="0.00" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
            </div>

            <h4 style="margin: 20px 0 10px 0; border-bottom: 1px solid #e3e6f0; padding-bottom: 5px; color: #4e73df;"><i class="fas boxes"></i> الوحدات:</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">وحدة القياس الأساسية:</label>
                    <input type="text" name="base_unit" value="قطعة" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">وحدات التعبئة (اختياري):</label>
                    <input type="text" name="packing_unit" placeholder="مثال: كرتونة = 12 قطعة" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
            </div>
            <div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 10px 14px; border-radius: 6px; margin-bottom: 20px; color: #0c5460; font-size: 12.5px;">
                <i class="fas fa-info-circle"></i> المخزون يبدأ بصفر دائماً. لتسجيل أول كمية تدخل المخزون، توجّه إلى <strong>فواتير الشراء</strong> بعد حفظ هذا المنتج.
            </div>

            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px; margin-top: 25px;">
                <button type="button" onclick="toggleProductModal(false)" style="background: #e2e8f0; color: #333; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; margin-left: 10px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-weight: bold;">حفظ الصنف</button>
            </div>
        </form>
    </div>
</div>

<!-- نافذة تعديل صنف موجود (Modal) -->
<div id="editProductModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1050; justify-content: center; align-items: center;">
    <div style="background: white; width: 900px; max-width: 95%; border-radius: 8px; padding: 25px; max-height: 90vh; overflow-y: auto; box-shadow: 0 5px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e3e6f0; padding-bottom: 12px; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #f6c23e;"><i class="fas fa-edit"></i> تعديل بيانات الصنف والتسعير والمخزون</h3>
            <button onclick="toggleEditProductModal(false)" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #888; line-height: 1;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="edit_product" value="1">
            <input type="hidden" name="product_id" id="edit_product_id">

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">اسم المنتج: <span style="color: red;">*</span></label>
                    <input type="text" name="product_name" id="edit_product_name" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">الباركود / SKU: <span style="color: red;">*</span></label>
                    <input type="text" name="sku" id="edit_sku" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">التصنيف أو الفئة:</label>
                    <div style="display: flex; gap: 6px;">
                        <select name="category_id" id="edit_category_id" style="flex: 1; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                            <option value="">-- اختر التصنيف --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="openAddCategoryModal()" title="إضافة تصنيف جديد" style="background: #1cc88a; color: white; border: none; width: 38px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 16px;">+</button>
                    </div>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">المورد الأساسي:</label>
                    <select name="supplier_id" id="edit_supplier_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                        <option value="">-- اختر المورد الأساسي --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h4 style="margin: 20px 0 10px 0; border-bottom: 1px solid #e3e6f0; padding-bottom: 5px; color: #4e73df;"><i class="fas fa-dollar-sign"></i> التسعير:</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div style="background: #f8f9fc; padding: 12px; border-radius: 6px; border: 1px dashed #ccc;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #888;">سعر التكلفة الحالي (للعرض فقط):</label>
                    <input type="text" id="edit_cost_price_usd_display" readonly disabled style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-weight: bold; font-family: monospace; background: #eee; color: #666; cursor: not-allowed;">
                    <p style="font-size: 11px; color: #888; margin: 5px 0 0;"><i class="fas fa-lock"></i> يُحدَّث تلقائياً حصراً من فاتورة شراء جديدة في "فواتير الشراء"</p>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">سعر البيع جملة (ل.س):</label>
                    <input type="number" step="0.01" name="wholesale_price_syp" id="edit_wholesale_price_syp" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">سعر البيع مفرق (ل.س):</label>
                <input type="number" step="0.01" name="retail_price_syp" id="edit_retail_price_syp" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
            </div>

            <h4 style="margin: 20px 0 10px 0; border-bottom: 1px solid #e3e6f0; padding-bottom: 5px; color: #4e73df;"><i class="fas boxes"></i> الوحدات والمخزون:</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 10px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">وحدة القياس الأساسية:</label>
                    <input type="text" name="base_unit" id="edit_base_unit" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">وحدات التعبئة (اختياري):</label>
                    <input type="text" name="packing_unit" id="edit_packing_unit" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">الكمية الحالية بالمخزون:</label>
                    <input type="number" step="0.0001" name="current_quantity" id="edit_current_quantity" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
            </div>
            <p style="font-size: 12px; color: #888; margin: 0 0 15px 0;">
                <i class="fas fa-info-circle"></i> تعديل الكمية هنا يُصحِّح المخزون الحالي فقط (كجرد يدوي) ولا يُغيِّر سجل الكمية الأصلية المشتراة من المورد.
            </p>

            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px; margin-top: 25px;">
                <button type="button" onclick="toggleEditProductModal(false)" style="background: #e2e8f0; color: #333; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; margin-left: 10px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-weight: bold;">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleProductModal(show) {
        document.getElementById('productModal').style.display = show ? 'flex' : 'none';
    }

    function toggleOfficeInventoryModal(show) {
        document.getElementById('officeInventoryModal').style.display = show ? 'flex' : 'none';
    }

    function toggleOiMode() {
        var mode = document.querySelector('input[name="oi_mode"]:checked').value;
        document.getElementById('oi_existing_block').style.display = (mode === 'existing') ? 'block' : 'none';
        document.getElementById('oi_new_block').style.display = (mode === 'new') ? 'block' : 'none';
    }

    function toggleEditProductModal(show) {
        document.getElementById('editProductModal').style.display = show ? 'flex' : 'none';
    }

    function openAddCategoryModal() {
        document.getElementById('addCategoryModal').style.display = 'flex';
    }

    function closeAddCategoryModal() {
        document.getElementById('addCategoryModal').style.display = 'none';
    }

    function openEditProductModal(prod) {
        document.getElementById('edit_product_id').value = prod.id;
        document.getElementById('edit_product_name').value = prod.product_name;
        document.getElementById('edit_sku').value = prod.sku;
        document.getElementById('edit_category_id').value = prod.category_id || '';
        document.getElementById('edit_supplier_id').value = prod.supplier_id || '';
        document.getElementById('edit_cost_price_usd_display').value = '$' + parseFloat(prod.cost_price_usd).toFixed(4);
        document.getElementById('edit_wholesale_price_syp').value = prod.wholesale_price_syp;
        document.getElementById('edit_retail_price_syp').value = prod.retail_price_syp;
        document.getElementById('edit_base_unit').value = prod.base_unit;
        document.getElementById('edit_packing_unit').value = prod.packing_unit || '';
        document.getElementById('edit_current_quantity').value = prod.current_quantity;
        toggleEditProductModal(true);
    }
</script>

<!-- نافذة إضافة تصنيف جديد -->
<div id="addCategoryModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1100; justify-content: center; align-items: center;">
    <div style="background: white; width: 400px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 5px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e3e6f0; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #1cc88a;"><i class="fas fa-tag"></i> إضافة تصنيف جديد</h3>
            <button type="button" onclick="closeAddCategoryModal()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_category" value="1">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">اسم التصنيف: <span style="color: red;">*</span></label>
                <input type="text" name="category_name" required autofocus placeholder="مثال: مواد غذائية / إلكترونيات..." style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
            </div>
            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px;">
                <button type="button" onclick="closeAddCategoryModal()" style="background: #e2e8f0; color: #333; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-left: 8px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ التصنيف</button>
            </div>
        </form>
    </div>
</div>

<!-- نافذة إضافة جرد مكتبي (Modal) -->
<div id="officeInventoryModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1050; justify-content: center; align-items: center;">
    <div style="background: white; width: 650px; max-width: 95%; border-radius: 8px; padding: 25px; max-height: 90vh; overflow-y: auto; box-shadow: 0 5px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e3e6f0; padding-bottom: 12px; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #8b5cf6;"><i class="fas fa-warehouse"></i> إضافة جرد مكتبي (بضاعة بلا مورد)</h3>
            <button onclick="toggleOfficeInventoryModal(false)" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #888; line-height: 1;">&times;</button>
        </div>
        <div style="background: #f3eefc; border: 1px solid #ddd0fa; padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; color: #5b3a99; font-size: 12.5px;">
            <i class="fas fa-info-circle"></i> لتسجيل بضاعة موجودة فعلياً بلا فاتورة شراء من مورد (جرد مكتبي مباشر). يُرحَّل قيد محاسبي مرتبط بهذا المنتج تحديداً، ويُخصَم تلقائياً عبر تكلفة البضاعة المباعة (COGS) عند بيعه — تماماً كأي منتج آخر.
        </div>
        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_office_inventory" value="1">

            <div style="margin-bottom: 15px;">
                <label style="display:flex; gap:20px;">
                    <label style="font-weight:normal; cursor:pointer;"><input type="radio" name="oi_mode" value="existing" checked onchange="toggleOiMode()"> زيادة كمية صنف مكتبي موجود</label>
                    <label style="font-weight:normal; cursor:pointer;"><input type="radio" name="oi_mode" value="new" onchange="toggleOiMode()"> صنف جديد كلياً</label>
                </label>
            </div>

            <div id="oi_existing_block" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">اختر الصنف المكتبي:</label>
                <select name="oi_existing_product_id" id="oi_existing_product_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                    <option value="">-- اختر --</option>
                    <?php foreach ($office_products as $op): ?>
                        <option value="<?php echo $op['id']; ?>"><?php echo htmlspecialchars($op['product_name']); ?> (<?php echo htmlspecialchars($op['sku']); ?>) — الحالي: <?php echo number_format($op['current_quantity'], 2); ?> <?php echo htmlspecialchars($op['base_unit']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (count($office_products) === 0): ?>
                    <p style="font-size: 12px; color: #a33636; margin: 5px 0 0;"><i class="fas fa-exclamation-circle"></i> لا يوجد أي صنف مكتبي بعد — اختر "صنف جديد كلياً" أدناه.</p>
                <?php endif; ?>
            </div>

            <div id="oi_new_block" style="display:none;">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">اسم المنتج:</label>
                        <input type="text" name="oi_new_product_name" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">الباركود / SKU:</label>
                        <input type="text" name="oi_new_sku" value="<?php echo htmlspecialchars($auto_sku); ?>" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">التصنيف:</label>
                        <select name="oi_category_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                            <option value="">-- اختر --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">وحدة القياس:</label>
                        <input type="text" name="oi_base_unit" value="قطعة" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">الكمية:</label>
                    <input type="number" step="0.0001" name="oi_quantity" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">تكلفة الوحدة ($):</label>
                    <input type="number" step="0.0001" name="oi_cost_price_usd" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">التاريخ:</label>
                    <input type="date" name="oi_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">ملاحظات (اختياري):</label>
                <textarea name="oi_notes" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; height: 50px;" placeholder="مثال: جرد افتتاحي، بضاعة مُهداة، تصحيح جرد سنوي..."></textarea>
            </div>

            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px; margin-top: 20px;">
                <button type="button" onclick="toggleOfficeInventoryModal(false)" style="background: #e2e8f0; color: #333; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; margin-left: 10px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: #8b5cf6; color: white; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-weight: bold;">حفظ وترحيل القيد</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>