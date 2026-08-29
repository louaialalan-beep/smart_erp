<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';
require_once __DIR__ . '/functions.php';

$supplier_id = intval($_GET['id'] ?? 0);
if ($supplier_id <= 0) {
    echo "<div style='padding: 20px; color: red;'>معرف المورد غير صالح.</div>";
    include 'footer.php';
    exit;
}

$msg = "";
$error = "";

// دالة عامة للبحث عن حساب محاسبي بكلمات مفتاحية أو إنشائه إن لم يوجد
// (نفس المنطق المستخدم في sales.php وrepresentative_profile.php لضمان توفر حسابين
// منفصلين لطرفي القيد المزدوج مدين/دائن)

// معالجة إضافة دفعة مالية للمورد + قيد محاسبي متوازن بالدولار
// (مدين: ذمم الموردين "يُخفِّض الالتزام تجاه المورد" / دائن: الصندوق "تخرج النقدية")
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_payment'])) {
    requireRole($conn, ['admin', 'accountant']);
    $amount_usd = floatval($_POST['amount_usd']);
    $payment_date = $_POST['payment_date'];
    $notes = trim($_POST['notes']);

    if ($amount_usd <= 0) {
        $error = "خطأ: يرجى إدخال مبلغ صحيح للدفعة.";
    } elseif (isDateInClosedPeriod($conn, $payment_date)) {
        $error = getPeriodLockErrorMessage($payment_date);
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("INSERT INTO supplier_payments (supplier_id, amount_usd, payment_date, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$supplier_id, $amount_usd, $payment_date, $notes]);
            $payment_id = $conn->lastInsertId();

            // جلب اسم المورد واستخدامه في وصف القيد
            $stmt_name = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt_name->execute([$supplier_id]);
            $supplier_name_for_entry = $stmt_name->fetchColumn() ?: ('مورد #' . $supplier_id);

            // تصحيح: سعر الصرف يُجلب بتاريخ الدفعة الفعلي (وليس "أحدث سعر" دائماً)، لأن المستخدم قد
            // يُسجِّل دفعة بتاريخ سابق (قيد متأخر) فيجب تثبيت السعر الذي كان سارياً حينها فعلاً،
            // تماشياً مع مبدأ "الحصانة التاريخية" المعتمد في بقية النظام.
            $exchange_rate = getExchangeRateForDate($conn, 'USD', $payment_date);

            $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
            $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

            if (in_array('account_id', $existing_cols) && in_array('debit', $existing_cols) && in_array('credit', $existing_cols)) {
                $debit_account_id  = findOrCreateAccount($conn, ['ذمم', 'مورد', 'payable'], 'ذمم الموردين');
                $credit_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');

                if ($debit_account_id && $credit_account_id) {
                    $entry_num = "JE-SPAY-" . $payment_id;
                    $journal_desc = "سداد دفعة نقدية للمورد: " . $supplier_name_for_entry . (!empty($notes) ? " (" . $notes . ")" : "");
                    $base_amount = $amount_usd * $exchange_rate;

                    $insertJournalLine = function ($account_id, $f_debit, $f_credit, $b_debit, $b_credit) use ($conn, $existing_cols, $entry_num, $payment_date, $journal_desc, $exchange_rate) {
                        $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                        $vals = [$account_id, $payment_date, $journal_desc, $b_debit, $b_credit];

                        if (in_array('entry_number', $existing_cols)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_num; }
                        if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'USD'; }
                        if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $exchange_rate; }
                        if (in_array('foreign_debit', $existing_cols)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = $f_debit; }
                        if (in_array('foreign_credit', $existing_cols)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = $f_credit; }
                        if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Supplier Payment'; }

                        $placeholders = implode(',', array_fill(0, count($cols_to_insert), '?'));
                        $col_names = implode(',', $cols_to_insert);
                        $stmt_j = $conn->prepare("INSERT INTO journal_entries ({$col_names}) VALUES ({$placeholders})");
                        $stmt_j->execute($vals);
                    };

                    // مدين: ذمم الموردين بالدولار (foreign) وما يقابلها بالليرة (base)
                    $insertJournalLine($debit_account_id, $amount_usd, 0, $base_amount, 0);
                    // دائن: الصندوق بنفس القيمة تماماً لضمان توازن القيد
                    $insertJournalLine($credit_account_id, 0, $amount_usd, 0, $base_amount);
                }
            }

            $conn->commit();
            $msg = "تم تسجيل الدفعة النقدية وترحيل القيد المحاسبي بنجاح!";
            logAudit($conn, 'INSERT', 'دفعات الموردين', "تسجيل دفعة نقدية بقيمة $" . number_format($amount_usd, 2) . " للمورد: " . $supplier_name_for_entry, $payment_id);
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "خطأ في تسجيل الدفعة: " . $e->getMessage();
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ في تسجيل الدفعة: " . $e->getMessage();
        }
    }
}

// معالجة تعديل دفعة موجودة + تحديث القيد المحاسبي المرتبط بها (إن وُجد) للحفاظ على توازنه
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_payment'])) {
    $payment_id = intval($_POST['payment_id']);
    $amount_usd = floatval($_POST['amount_usd']);
    $payment_date = $_POST['payment_date'];
    $notes = trim($_POST['notes']);

    if ($amount_usd <= 0) {
        $error = "خطأ: يرجى إدخال مبلغ صحيح للدفعة.";
    } elseif (isDateInClosedPeriod($conn, $payment_date)) {
        $error = getPeriodLockErrorMessage($payment_date);
    } else {
        try {
            $conn->beginTransaction();

            // تأكيد أن الدفعة تخص هذا المورد فعلاً قبل التعديل
            $stmt_check = $conn->prepare("SELECT id FROM supplier_payments WHERE id = ? AND supplier_id = ?");
            $stmt_check->execute([$payment_id, $supplier_id]);
            if (!$stmt_check->fetchColumn()) {
                throw new Exception("الدفعة غير موجودة أو لا تخص هذا المورد.");
            }

            $stmt_up = $conn->prepare("UPDATE supplier_payments SET amount_usd = ?, payment_date = ?, notes = ? WHERE id = ?");
            $stmt_up->execute([$amount_usd, $payment_date, $notes, $payment_id]);

            // تصحيح جوهري: القيود المرحَّلة لا تُعدَّل مباشرة أبداً (مبدأ محاسبي أساسي) — بدل UPDATE على
            // السطور الأصلية، نُنشئ قيد عكس (Reversal) يُلغي الأثر الأصلي بالضبط، ثم قيداً جديداً صحيحاً.
            // الأثر الصافي في دفتر الأستاذ مطابق تماماً، لكن الأثر الأصلي يبقى محفوظاً كما رُحِّل فعلياً — قابل للتتبع الكامل.
            $stmt_name = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt_name->execute([$supplier_id]);
            $supplier_name_for_entry = $stmt_name->fetchColumn() ?: ('مورد #' . $supplier_id);

            $original_entry_num = "JE-SPAY-" . $payment_id;
            $stmt_je = $conn->prepare("SELECT id, account_id, debit, credit, foreign_debit, foreign_credit, exchange_rate FROM journal_entries WHERE entry_number = ?");
            $stmt_je->execute([$original_entry_num]);
            $je_lines = $stmt_je->fetchAll(PDO::FETCH_ASSOC);

            $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
            $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

            if (count($je_lines) > 0) {
                $rev_entry_num = $original_entry_num . "-REV-" . time();
                $today = date('Y-m-d');
                $rev_desc = "عكس تلقائي لقيد سداد دفعة معدَّلة (الأصل: $original_entry_num) للمورد: " . $supplier_name_for_entry;

                // 1) قيد العكس: يُبدِّل المدين والدائن لكل سطر أصلي بنفس قيمه تماماً
                foreach ($je_lines as $line) {
                    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit', 'entry_number'];
                    $vals = [$line['account_id'], $today, $rev_desc, floatval($line['credit']), floatval($line['debit']), $rev_entry_num];
                    if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'USD'; }
                    if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $line['exchange_rate']; }
                    if (in_array('foreign_debit', $existing_cols)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = floatval($line['foreign_credit'] ?? 0); }
                    if (in_array('foreign_credit', $existing_cols)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = floatval($line['foreign_debit'] ?? 0); }
                    if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Supplier Payment Reversal'; }
                    $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                    $cn = implode(',', $cols_to_insert);
                    $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                }

                // 2) القيد الصحيح الجديد بالمبلغ المُحدَّث، بنفس سعر الصرف التاريخي الأصلي المثبت (الحصانة التاريخية)
                $orig_rate = floatval($je_lines[0]['exchange_rate']) > 0 ? floatval($je_lines[0]['exchange_rate']) : 1.0;
                $new_entry_num = $original_entry_num . "-CORR-" . time();
                $new_desc = "سداد دفعة نقدية معدَّلة للمورد: " . $supplier_name_for_entry . (!empty($notes) ? " (" . $notes . ")" : "");
                $new_base = $amount_usd * $orig_rate;

                foreach ($je_lines as $line) {
                    $is_debit_line = floatval($line['debit']) > 0;
                    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit', 'entry_number'];
                    $vals = [$line['account_id'], $payment_date, $new_desc, $is_debit_line ? $new_base : 0, $is_debit_line ? 0 : $new_base, $new_entry_num];
                    if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'USD'; }
                    if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $orig_rate; }
                    if (in_array('foreign_debit', $existing_cols)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = $is_debit_line ? $amount_usd : 0; }
                    if (in_array('foreign_credit', $existing_cols)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = $is_debit_line ? 0 : $amount_usd; }
                    if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Supplier Payment'; }
                    $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                    $cn = implode(',', $cols_to_insert);
                    $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                }
            }

            $conn->commit();
            $msg = "تم تحديث الدفعة" . (count($je_lines) > 0 ? " مع ترحيل قيد عكس وقيد تصحيحي (بدل تعديل القيد الأصلي مباشرة)" : "") . " بنجاح!";
            logAudit($conn, 'UPDATE', 'دفعات الموردين', "تعديل دفعة رقم #$payment_id للمورد: " . $supplier_name_for_entry . " إلى $" . number_format($amount_usd, 2) . " — تم ترحيل قيد عكس + قيد تصحيحي", $payment_id);
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ أثناء تحديث الدفعة: " . $e->getMessage();
        }
    }
}

// جلب بيانات المورد الأساسية
$stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    echo "<div style='padding: 20px; color: red;'>المورد غير موجود.</div>";
    include 'footer.php';
    exit;
}

// ضمان وجود جداول فواتير الشراء (قد لم تُزار صفحة purchases.php بعد على هذا الخادم)
$conn->exec("CREATE TABLE IF NOT EXISTS purchase_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL,
    supplier_id INT NOT NULL,
    exchange_rate DECIMAL(15,4) DEFAULT 1,
    total_amount_usd DECIMAL(15,2) DEFAULT 0,
    invoice_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->exec("CREATE TABLE IF NOT EXISTS purchase_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_invoice_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    unit_cost_usd DECIMAL(15,4) NOT NULL,
    total_cost_usd DECIMAL(15,2) NOT NULL
)");

// حساب إجمالي المشتريات — تصحيح دقة تاريخية إضافي:
// المصدر الأدق الآن هو purchase_invoice_items (القيمة الفعلية المسجَّلة لحظة كل فاتورة شراء حقيقية
// عبر purchases.php)، وليس products.cost_price_usd الحالي الذي يتغيّر مع كل عملية شراء لاحقة.
$stmt_pi = $conn->prepare("
    SELECT COALESCE(SUM(pii.total_cost_usd), 0)
    FROM purchase_invoice_items pii
    JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
    WHERE pi.supplier_id = ?
");
$stmt_pi->execute([$supplier_id]);
$total_purchases_from_invoices = floatval($stmt_pi->fetchColumn());

// تصحيح خطأ سابق: الحساب القديم كان يستبعد المنتج بالكامل من "القيمة القديمة" بمجرد وجود أي فاتورة
// شراء واحدة له، فيختفي كل تاريخه المتراكم دفعة واحدة (وهو ما أدى لعدم ظهور فاتورة الشراء الجديدة
// فعلياً في الإجمالي). الصحيح: احسب فقط "الكمية القديمة المتبقية غير المُغطَّاة بعد بأي فاتورة شراء
// حقيقية" لكل منتج = (إجمالي الكمية المشتراة تاريخياً في عمود المنتج) - (مجموع كميات فواتير الشراء
// الفعلية المسجَّلة له). هذا الفارق يتقلّص تدريجياً مع كل فاتورة شراء جديدة بدل أن يُصفَّر دفعة واحدة.
$stmt_legacy = $conn->prepare("
    SELECT COALESCE(SUM(
        GREATEST(0, p.purchased_quantity - COALESCE((SELECT SUM(pii2.quantity) FROM purchase_invoice_items pii2 WHERE pii2.product_id = p.id), 0))
        * p.cost_price_usd
    ), 0)
    FROM products p
    WHERE p.supplier_id = ?
");
$stmt_legacy->execute([$supplier_id]);
$total_purchases_legacy = floatval($stmt_legacy->fetchColumn());

$total_purchases = $total_purchases_from_invoices + $total_purchases_legacy;

// حساب إجمالي المدفوعات النقدية
$stmt_pay = $conn->prepare("SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_payments WHERE supplier_id = ?");
$stmt_pay->execute([$supplier_id]);
$total_payments = $stmt_pay->fetchColumn();

$returns_discounts = floatval($supplier['returns_discounts']);
$net_balance = $total_purchases - $total_payments - $returns_discounts;

// جلب قائمة المنتجات المرتبطة بهذا المورد
$stmt_products = $conn->prepare("SELECT * FROM products WHERE supplier_id = ? ORDER BY id DESC");
$stmt_products->execute([$supplier_id]);
$products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

// === فلتر تاريخ + بحث (مشترك لفواتير الشراء وسجل المدفوعات) ===
$filter_start = $_GET['filter_start'] ?? '';
$filter_end = $_GET['filter_end'] ?? '';
$filter_search = trim($_GET['filter_search'] ?? '');

// فواتير الشراء الخاصة بهذا المورد (لم تكن معروضة هنا إطلاقاً سابقاً رغم وجود الجدول)
$pi_where = ["supplier_id = ?"];
$pi_params = [$supplier_id];
if (!empty($filter_start)) { $pi_where[] = "invoice_date >= ?"; $pi_params[] = $filter_start; }
if (!empty($filter_end)) { $pi_where[] = "invoice_date <= ?"; $pi_params[] = $filter_end; }
if (!empty($filter_search)) { $pi_where[] = "(invoice_number LIKE ? OR notes LIKE ?)"; $pi_params[] = "%$filter_search%"; $pi_params[] = "%$filter_search%"; }
$stmt_pi_list = $conn->prepare("SELECT * FROM purchase_invoices WHERE " . implode(' AND ', $pi_where) . " ORDER BY invoice_date DESC, id DESC");
$stmt_pi_list->execute($pi_params);
$purchase_invoices_list = $stmt_pi_list->fetchAll(PDO::FETCH_ASSOC);

// جلب سجل المدفوعات السابقة (مع نفس الفلتر)
$pay_where = ["supplier_id = ?"];
$pay_params = [$supplier_id];
if (!empty($filter_start)) { $pay_where[] = "payment_date >= ?"; $pay_params[] = $filter_start; }
if (!empty($filter_end)) { $pay_where[] = "payment_date <= ?"; $pay_params[] = $filter_end; }
if (!empty($filter_search)) { $pay_where[] = "notes LIKE ?"; $pay_params[] = "%$filter_search%"; }
$stmt_payments_list = $conn->prepare("SELECT * FROM supplier_payments WHERE " . implode(' AND ', $pay_where) . " ORDER BY payment_date DESC, id DESC");
$stmt_payments_list->execute($pay_params);
$payments_list = $stmt_payments_list->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <a href="suppliers.php" style="color: #4e73df; text-decoration: none; font-size: 13px; font-weight: bold;"><i class="fas fa-arrow-right"></i> العودة لقائمة الموردين</a>
        <h2 style="margin: 5px 0 0 0; color: #333;">الملف التفصيلي للمورد: <?php echo htmlspecialchars($supplier['supplier_name']); ?></h2>
    </div>
    <div>
        <button onclick="togglePaymentModal(true)" style="background: #1cc88a; color: white; padding: 9px 18px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold;">
            <i class="fas fa-money-bill-wave"></i> تسجيل دفعة جديدة للمورد
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $msg; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- بطاقات الملخص المالي -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px;">
    <div style="background: #fff; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px; box-shadow: 0 0.15rem 1rem rgba(0,0,0,0.05);">
        <div style="color: #888; font-size: 13px; font-weight: bold;">إجمالي المشتريات (له)</div>
        <div style="font-size: 20px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;">$<?php echo number_format($total_purchases, 2); ?></div>
    </div>
    <div style="background: #fff; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 6px; box-shadow: 0 0.15rem 1rem rgba(0,0,0,0.05);">
        <div style="color: #888; font-size: 13px; font-weight: bold;">إجمالي المدفوعات (عليه)</div>
        <div style="font-size: 20px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;">$<?php echo number_format($total_payments, 2); ?></div>
    </div>
    <div style="background: #fff; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px; box-shadow: 0 0.15rem 1rem rgba(0,0,0,0.05);">
        <div style="color: #888; font-size: 13px; font-weight: bold;">المردودات / الخصم</div>
        <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;">$<?php echo number_format($returns_discounts, 2); ?></div>
    </div>
    <div style="background: #fff; border-right: 4px solid #2e59d9; padding: 15px; border-radius: 6px; box-shadow: 0 0.15rem 1rem rgba(0,0,0,0.05);">
        <div style="color: #888; font-size: 13px; font-weight: bold;">صافي الحساب الباقي</div>
        <div style="font-size: 20px; font-weight: bold; color: #2e59d9; font-family: monospace; margin-top: 5px;">$<?php echo number_format($net_balance, 2); ?></div>
    </div>
</div>

<!-- معلومات الاتصال والشروط -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px; margin-bottom: 25px; display: flex; gap: 30px; font-size: 14px;">
    <div><strong>رقم الهاتف:</strong> <span style="font-family: monospace; color: #555;"><?php echo htmlspecialchars($supplier['phone'] ?: 'غير متوفر'); ?></span></div>
    <div><strong>العملة المعتمدة:</strong> <span style="color: #4e73df; font-weight: bold;"><?php echo htmlspecialchars($supplier['currency']); ?></span></div>
    <div><strong>شروط السداد:</strong> <span style="color: #666;"><?php echo htmlspecialchars($supplier['payment_terms']); ?></span></div>
</div>

<!-- جدول المنتجات المرتبطة بالمورد -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; margin-bottom: 25px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="background: #f8f9fc; padding: 12px 15px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #4e73df;">
        <i class="fas fa-boxes"></i> المنتجات المرتبطة والمشتراة من هذا المورد
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0; color: #555;">
                    <th style="padding: 10px 15px;">اسم المنتج</th>
                    <th style="padding: 10px 15px;">رمز الصنف (SKU)</th>
                    <th style="padding: 10px 15px;">إجمالي الكمية المشتراة</th>
                    <th style="padding: 10px 15px;">الكمية الحالية بالمخزون</th>
                    <th style="padding: 10px 15px;">سعر التكلفة (USD)</th>
                    <th style="padding: 10px 15px;">إجمالي قيمة الشراء (USD)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $prod): 
                        // إجمالي قيمة الشراء يعتمد على الكمية المشتراة الأصلية (ثابتة) وليس المتبقية بالمخزون
                        $purchased_qty = $prod['purchased_quantity'] ?? $prod['current_quantity'];
                        $total_val = $purchased_qty * $prod['cost_price_usd'];
                    ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-weight: 600; color: #333;"><?php echo htmlspecialchars($prod['product_name']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($prod['sku']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; font-weight: bold; color: #333;"><?php echo number_format($purchased_qty, 2); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #888;"><?php echo number_format($prod['current_quantity'], 2); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #e74a3b;">$<?php echo number_format($prod['cost_price_usd'], 4); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #2e59d9; font-weight: bold;">$<?php echo number_format($total_val, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="padding: 20px; text-align: center; color: #777;">لا توجد منتجات مسجلة مرتبطة بهذا المورد حتى الآن.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- شريط الفلترة المشترك (تاريخ + بحث) لفواتير الشراء وسجل المدفوعات أدناه -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px;">
    <form method="GET" action="" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="id" value="<?php echo $supplier_id; ?>">
        <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">من تاريخ:</label>
            <input type="date" name="filter_start" value="<?php echo htmlspecialchars($filter_start); ?>" style="padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">إلى تاريخ:</label>
            <input type="date" name="filter_end" value="<?php echo htmlspecialchars($filter_end); ?>" style="padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <div style="flex:1; min-width:180px;"><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">بحث (رقم فاتورة / ملاحظات):</label>
            <input type="text" name="filter_search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="ابحث..." style="width:100%; padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <button type="submit" style="background:#4e73df; color:white; border:none; padding:8px 18px; border-radius:6px; cursor:pointer; font-weight:bold;">تطبيق الفلتر</button>
        <?php if (!empty($filter_start) || !empty($filter_end) || !empty($filter_search)): ?>
            <a href="supplier_view.php?id=<?php echo $supplier_id; ?>" style="color:#666; font-size:13px; padding:8px 0;">إلغاء الفلتر</a>
        <?php endif; ?>
    </form>
</div>

<!-- فواتير الشراء الخاصة بهذا المورد -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08); margin-bottom: 25px;">
    <div style="background: #f8f9fc; padding: 12px 15px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #4e73df;">
        <i class="fas fa-truck-loading"></i> فواتير الشراء المسجَّلة من هذا المورد
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0; color: #555;">
                    <th style="padding: 10px 15px;">رقم الفاتورة</th>
                    <th style="padding: 10px 15px;">التاريخ</th>
                    <th style="padding: 10px 15px;">القيمة (USD)</th>
                    <th style="padding: 10px 15px;">سعر الصرف</th>
                    <th style="padding: 10px 15px;">ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($purchase_invoices_list) > 0): ?>
                    <?php foreach ($purchase_invoices_list as $pi): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-family: monospace; font-weight: bold; color: #4e73df;"><?php echo htmlspecialchars($pi['invoice_number']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($pi['invoice_date']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; font-weight: bold; color: #e74a3b;">$<?php echo number_format($pi['total_amount_usd'], 2); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace;"><?php echo number_format($pi['exchange_rate'], 2); ?></td>
                            <td style="padding: 10px 15px; color: #777;"><?php echo htmlspecialchars($pi['notes'] ?: '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="padding: 20px; text-align: center; color: #777;">لا توجد فواتير شراء مطابقة لخيارات الفلتر الحالية.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- جدول سجل الحركات والدفعات السابقة -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="background: #f8f9fc; padding: 12px 15px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #1cc88a;">
        <i class="fas fa-history"></i> سجل المدفوعات النقدية المسددة للمورد
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0; color: #555;">
                    <th style="padding: 10px 15px;">تاريخ الدفعة</th>
                    <th style="padding: 10px 15px;">المبلغ المسدد (USD)</th>
                    <th style="padding: 10px 15px;">ملاحظات / البيان</th>
                    <th style="padding: 10px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($payments_list) > 0): ?>
                    <?php foreach ($payments_list as $pay): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-family: monospace; color: #555;"><?php echo htmlspecialchars($pay['payment_date']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #1cc88a; font-weight: bold;">$<?php echo number_format($pay['amount_usd'], 2); ?></td>
                            <td style="padding: 10px 15px; color: #666;"><?php echo htmlspecialchars($pay['notes'] ?: 'بدون ملاحظات'); ?></td>
                            <td style="padding: 10px 15px; text-align: center;">
                                <button onclick='openEditPaymentModal(<?php echo json_encode($pay, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #f6c23e; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">
                                    <i class="fas fa-edit"></i> تعديل
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="padding: 20px; text-align: center; color: #777;">لم يتم تسجيل أي دفعات مالية لهذا المورد بعد.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- نافذة تسجيل دفعة جديدة (Modal) -->
<div id="paymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #1cc88a;">تسجيل دفعة جديدة للمورد</h3>
            <button onclick="togglePaymentModal(false)" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_payment" value="1">

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">المبلغ المدفوع (USD):</label>
                <input type="number" step="0.0001" name="amount_usd" required placeholder="0.00" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ الدفعة:</label>
                <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">ملاحظات / السند:</label>
                <textarea name="notes" rows="3" placeholder="تفاصيل الدفعة أو رقم السند..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            </div>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">
                <button type="button" onclick="togglePaymentModal(false)" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ الدفعة</button>
            </div>
        </form>
    </div>
</div>

<script>
    function togglePaymentModal(show) {
        document.getElementById('paymentModal').style.display = show ? 'flex' : 'none';
    }

    function openEditPaymentModal(payment) {
        document.getElementById('edit_payment_id').value = payment.id;
        document.getElementById('edit_amount_usd').value = payment.amount_usd;
        document.getElementById('edit_payment_date').value = payment.payment_date;
        document.getElementById('edit_notes').value = payment.notes || '';
        document.getElementById('editPaymentModal').style.display = 'flex';
    }

    function closeEditPaymentModal() {
        document.getElementById('editPaymentModal').style.display = 'none';
    }
</script>

<!-- نافذة تعديل دفعة موجودة (Modal) -->
<div id="editPaymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #f6c23e;"><i class="fas fa-edit"></i> تعديل دفعة المورد</h3>
            <button onclick="closeEditPaymentModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="edit_payment" value="1">
            <input type="hidden" name="payment_id" id="edit_payment_id">

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">المبلغ المدفوع (USD):</label>
                <input type="number" step="0.0001" name="amount_usd" id="edit_amount_usd" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ الدفعة:</label>
                <input type="date" name="payment_date" id="edit_payment_date" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">ملاحظات / السند:</label>
                <textarea name="notes" id="edit_notes" rows="3" placeholder="تفاصيل الدفعة أو رقم السند..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            </div>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">
                <button type="button" onclick="closeEditPaymentModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ التعديل</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>