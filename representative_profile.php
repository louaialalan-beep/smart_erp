<?php
/**
 * لوحة حساب المندوب وتتبع العمولات والتسليم الذكي - Smart ERP
 */
session_start();
ob_start(); // تخزين الإخراج مؤقتاً للسماح بإرسال header() لاحقاً حتى بعد طباعة header.php
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

if (!isset($conn)) {
    die("خطأ: اتصال قاعدة البيانات غير متوفر.");
}

$rep_id = intval($_GET['id'] ?? 0);
if ($rep_id <= 0) {
    echo "<div style='padding: 30px; color: red;'>معرف المندوب غير صالح.</div>";
    include 'footer.php';
    exit;
}

// جلب بيانات المندوب الأساسية
$stmt_rep = $conn->prepare("SELECT * FROM representatives WHERE id = ?");
$stmt_rep->execute([$rep_id]);
$rep = $stmt_rep->fetch(PDO::FETCH_ASSOC);

if (!$rep) {
    echo "<div style='padding: 30px; color: red;'>المندوب غير موجود.</div>";
    include 'footer.php';
    exit;
}

$msg = "";
$error = "";

// قواميس ترجمة قيم الـ ENUM المخزنة بالإنجليزية إلى عرض عربي
$payment_labels  = ['Paid' => 'نقداً في الصندوق', 'Unpaid' => 'آجل / ذمم', 'Partial' => 'دفعة جزئية'];
$delivery_labels = ['Delivered' => 'تم التسليم', 'Pending' => 'قيد الانتظار', 'Deferred' => 'مؤجلة'];

// دالة عامة للبحث عن حساب محاسبي بكلمات مفتاحية أو إنشائه إن لم يوجد (نفس المنطق المستخدم في sales.php)

// 1. معالجة تسجيل دفعة نقدية مسددة للمندوب + قيد محاسبي متوازن (مدين: عمولات مستحقة الدفع / دائن: الصندوق)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_payment'])) {
    requireRole($conn, ['admin', 'accountant']);
    $amount_syp   = filter_var($_POST['amount_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes        = trim($_POST['notes'] ?? '');

    if ($amount_syp <= 0) {
        $error = "مبلغ الدفعة غير صالح.";
    } elseif (isDateInClosedPeriod($conn, $payment_date)) {
        $error = getPeriodLockErrorMessage($payment_date);
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("INSERT INTO representative_payments (representative_id, amount_syp, notes, payment_date) VALUES (?, ?, ?, ?)");
            $stmt->execute([$rep_id, $amount_syp, $notes, $payment_date]);
            $payment_id = $conn->lastInsertId();

            // القيد المحاسبي: تسديد عمولة مستحقة للمندوب يُخفِّض الالتزام (مدين) ويُخفِّض النقدية (دائن)
            $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
            $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

            if (in_array('account_id', $existing_cols) && in_array('debit', $existing_cols) && in_array('credit', $existing_cols)) {
                $debit_account_id  = findOrCreateAccount($conn, ['عمولات', 'مندوب'], 'عمولات المندوبين المستحقة');
                $credit_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');

                if ($debit_account_id && $credit_account_id) {
                    $entry_num = "JE-RPAY-" . $payment_id;
                    $journal_desc = "سداد دفعة نقدية للمندوب: " . $rep['name'] . (!empty($notes) ? " (" . $notes . ")" : "");

                    $insertJournalLine = function ($account_id, $debit_amt, $credit_amt) use ($conn, $existing_cols, $entry_num, $payment_date, $journal_desc) {
                        $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                        $vals = [$account_id, $payment_date, $journal_desc, $debit_amt, $credit_amt];

                        if (in_array('entry_number', $existing_cols)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_num; }
                        if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'SYP'; }
                        if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = 1; }
                        if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Representative Payment'; }

                        $placeholders = implode(',', array_fill(0, count($cols_to_insert), '?'));
                        $col_names = implode(',', $cols_to_insert);
                        $stmt_j = $conn->prepare("INSERT INTO journal_entries ({$col_names}) VALUES ({$placeholders})");
                        $stmt_j->execute($vals);
                    };

                    $insertJournalLine($debit_account_id, $amount_syp, 0);
                    $insertJournalLine($credit_account_id, 0, $amount_syp);
                }
            }

            $conn->commit();
            $msg = "تم تسجيل الدفعة النقدية وترحيل القيد المحاسبي بنجاح!";
            logAudit($conn, 'INSERT', 'دفعات المندوبين', "تسجيل دفعة نقدية بقيمة " . number_format($amount_syp, 2) . " ل.س للمندوب: " . $rep['name'], $payment_id);
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ أثناء تسجيل الدفعة: " . $e->getMessage();
        }
    }
}

// 1ب. معالجة تعديل دفعة موجودة + تحديث القيد المحاسبي المرتبط بها (إن وُجد) للحفاظ على توازنه
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_payment'])) {
    $payment_id   = intval($_POST['payment_id'] ?? 0);
    $amount_syp   = filter_var($_POST['amount_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes        = trim($_POST['notes'] ?? '');

    if ($amount_syp <= 0) {
        $error = "مبلغ الدفعة غير صالح.";
    } elseif (isDateInClosedPeriod($conn, $payment_date)) {
        $error = getPeriodLockErrorMessage($payment_date);
    } else {
        try {
            $conn->beginTransaction();

            // تأكيد أن الدفعة تخص هذا المندوب فعلاً قبل التعديل
            $stmt_check = $conn->prepare("SELECT id FROM representative_payments WHERE id = ? AND representative_id = ?");
            $stmt_check->execute([$payment_id, $rep_id]);
            if (!$stmt_check->fetchColumn()) {
                throw new Exception("الدفعة غير موجودة أو لا تخص هذا المندوب.");
            }

            $stmt_up = $conn->prepare("UPDATE representative_payments SET amount_syp = ?, notes = ?, payment_date = ? WHERE id = ?");
            $stmt_up->execute([$amount_syp, $notes, $payment_date, $payment_id]);

            // تصحيح جوهري: بدل تعديل القيد الأصلي مباشرة (UPDATE)، نُرحِّل قيد عكس ثم قيداً تصحيحياً جديداً،
            // فيبقى الأثر المُرحَّل أصلاً محفوظاً كما هو تاريخياً وقابلاً للتتبع الكامل عبر سجل التدقيق.
            $original_entry_num = "JE-RPAY-" . $payment_id;
            $stmt_je = $conn->prepare("SELECT id, account_id, debit, credit FROM journal_entries WHERE entry_number = ?");
            $stmt_je->execute([$original_entry_num]);
            $je_lines = $stmt_je->fetchAll(PDO::FETCH_ASSOC);

            if (count($je_lines) > 0) {
                $rev_entry_num = $original_entry_num . "-REV-" . time();
                $new_entry_num = $original_entry_num . "-CORR-" . time();
                $today = date('Y-m-d');
                $rev_desc = "عكس تلقائي لقيد سداد دفعة معدَّلة (الأصل: $original_entry_num) للمندوب: " . $rep['name'];
                $new_desc = "سداد دفعة نقدية معدَّلة للمندوب: " . $rep['name'] . (!empty($notes) ? " (" . $notes . ")" : "");

                foreach ($je_lines as $line) {
                    // 1) قيد العكس: يُبدِّل المدين/الدائن بنفس القيمة الأصلية بالضبط
                    $conn->prepare("INSERT INTO journal_entries (account_id, entry_date, description, debit, credit, entry_number, source_module) VALUES (?, ?, ?, ?, ?, ?, 'Representative Payment Reversal')")
                         ->execute([$line['account_id'], $today, $rev_desc, floatval($line['credit']), floatval($line['debit']), $rev_entry_num]);

                    // 2) القيد التصحيحي الجديد بالمبلغ المُحدَّث
                    $is_debit_line = floatval($line['debit']) > 0;
                    $conn->prepare("INSERT INTO journal_entries (account_id, entry_date, description, debit, credit, entry_number, source_module) VALUES (?, ?, ?, ?, ?, ?, 'Representative Payment')")
                         ->execute([$line['account_id'], $payment_date, $new_desc, $is_debit_line ? $amount_syp : 0, $is_debit_line ? 0 : $amount_syp, $new_entry_num]);
                }
            }

            $conn->commit();
            $msg = "تم تحديث الدفعة" . (count($je_lines) > 0 ? " مع ترحيل قيد عكس وقيد تصحيحي" : "") . " بنجاح!";
            logAudit($conn, 'UPDATE', 'دفعات المندوبين', "تعديل دفعة رقم #$payment_id للمندوب: " . $rep['name'] . " إلى " . number_format($amount_syp, 2) . " ل.س — تم ترحيل قيد عكس + قيد تصحيحي", $payment_id);
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ أثناء تحديث الدفعة: " . $e->getMessage();
        }
    }
}

// 2. معالجة تغيير حالة التسليم سريعاً (لتفعيل قاعدة الترحيل الذكي للعمولات)
// تصحيح أمني: تحوَّل من رابط GET (عرضة لهجوم CSRF عبر مجرد النقر على رابط خارجي) إلى نموذج POST محمي بالرمز السري
// القيم يجب أن تطابق تعريف ENUM الفعلي في جدول sales: 'Delivered' / 'Pending' / 'Deferred'
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_status']) && isset($_POST['sale_id'])) {
    $sale_id = intval($_POST['sale_id']);
    $new_status = $_POST['toggle_status'] === 'Delivered' ? 'Delivered' : 'Pending';
    try {
        $stmt_up = $conn->prepare("UPDATE sales SET delivery_status = ? WHERE id = ? AND representative_id = ?");
        $stmt_up->execute([$new_status, $sale_id, $rep_id]);

        // === الإصلاح المعماري: هنا بالضبط يُعترَف بالإيراد الحقيقي + COGS + استحقاق العمولة ===
        // (أو يُعكَسان لو أُلغي تأكيد التسليم بالخطأ) — هذه هي اللحظة المحاسبية الحقيقية للاعتراف،
        // وليس لحظة إصدار الفاتورة، تماشياً مع معيار الاعتراف بالإيراد عند انتقال السيطرة للعميل.
        if ($new_status === 'Delivered') {
            recognizeSaleRevenue($conn, $sale_id);
        } else {
            deferSaleRevenue($conn, $sale_id);
        }

        logAudit($conn, 'UPDATE', 'فواتير المبيعات', "تبديل حالة تسليم الفاتورة #$sale_id إلى: $new_status (تؤثر على احتساب عمولة المندوب: " . $rep['name'] . " والاعتراف بالإيراد الرسمي)", $sale_id);
        ob_end_clean(); // تفريغ أي إخراج مخزَّن (من header.php) قبل إرسال الترويسة
        header("Location: representative_profile.php?id=$rep_id");
        exit;
    } catch (Exception $e) {
        $error = "خطأ أثناء تحديث حالة التسليم.";
    }
}

// حساب مؤشرات لوحة القيادة (Dashboard Statistics)
// قاعدة الترحيل الذكي: العمولات المستحقة تُحسب حصراً للفواتير التي تم تسليمها فعلياً (Delivered)
// ملاحظة: نستخدم total_commissions (وهو العمود الفعلي في جدول sales الذي تكتبه صفحة الفواتير)
$stmt_comm = $conn->prepare("SELECT COALESCE(SUM(total_commissions), 0) FROM sales WHERE representative_id = ? AND delivery_status = 'Delivered'");
$stmt_comm->execute([$rep_id]);
$total_earned_commissions = $stmt_comm->fetchColumn();

// إجمالي الدفعات النقدية المسددة للمندوب
$stmt_pay = $conn->prepare("SELECT COALESCE(SUM(amount_syp), 0) FROM representative_payments WHERE representative_id = ?");
$stmt_pay->execute([$rep_id]);
$total_payments_made = $stmt_pay->fetchColumn();

// صافي الرصيد / الذمة لصالح المندوب (العمولات المحققة ناقص الدفعات المسددة)
$net_balance = $total_earned_commissions - $total_payments_made;

// === فلتر تاريخ + حالة تسليم + بحث (لفواتير المندوب وسجل دفعاته) ===
$filter_start = $_GET['filter_start'] ?? '';
$filter_end = $_GET['filter_end'] ?? '';
$filter_delivery = $_GET['filter_delivery'] ?? '';
$filter_search = trim($_GET['filter_search'] ?? '');

$sales_where = ["representative_id = ?"];
$sales_params = [$rep_id];
if (!empty($filter_start)) { $sales_where[] = "invoice_date >= ?"; $sales_params[] = $filter_start; }
if (!empty($filter_end)) { $sales_where[] = "invoice_date <= ?"; $sales_params[] = $filter_end; }
if (!empty($filter_delivery) && array_key_exists($filter_delivery, $delivery_labels)) { $sales_where[] = "delivery_status = ?"; $sales_params[] = $filter_delivery; }
if (!empty($filter_search)) { $sales_where[] = "(invoice_number LIKE ? OR customer_name LIKE ?)"; $sales_params[] = "%$filter_search%"; $sales_params[] = "%$filter_search%"; }

// جلب فواتير المبيعات الخاصة بهذا المندوب (من نفس جدول ومخطط صفحة الفواتير)
$stmt_sales = $conn->prepare("SELECT * FROM sales WHERE " . implode(' AND ', $sales_where) . " ORDER BY invoice_date DESC, id DESC");
$stmt_sales->execute($sales_params);
$sales_list = $stmt_sales->fetchAll(PDO::FETCH_ASSOC);

// جلب سجل الدفعات النقدية (بنفس فلتر التاريخ)
$pay_where = ["representative_id = ?"];
$pay_params = [$rep_id];
if (!empty($filter_start)) { $pay_where[] = "payment_date >= ?"; $pay_params[] = $filter_start; }
if (!empty($filter_end)) { $pay_where[] = "payment_date <= ?"; $pay_params[] = $filter_end; }
if (!empty($filter_search)) { $pay_where[] = "notes LIKE ?"; $pay_params[] = "%$filter_search%"; }
$stmt_payments_log = $conn->prepare("SELECT * FROM representative_payments WHERE " . implode(' AND ', $pay_where) . " ORDER BY payment_date DESC, id DESC");
$stmt_payments_log->execute($pay_params);
$payments_list = $stmt_payments_log->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <a href="representatives.php" style="color: #4e73df; text-decoration: none; font-weight: bold; font-size: 13px; display: inline-block; margin-bottom: 5px;">
            <i class="fas fa-arrow-right"></i> العودة لقائمة المندوبين
        </a>
        <h2 style="color: #2e384d; margin: 0;">حساب المندوب: <?php echo htmlspecialchars($rep['name']); ?></h2>
    </div>
    <div style="display: flex; gap: 10px;">
        <button onclick="openPaymentModal()" style="background: #4e73df; color: white; padding: 10px 18px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <i class="fas fa-money-bill-wave"></i> تسجيل دفعة نقدية
        </button>
        <a href="sales.php" style="background: #1cc88a; color: white; padding: 10px 18px; border-radius: 6px; text-decoration: none; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: inline-flex; align-items: center; gap: 6px;">
            <i class="fas fa-file-invoice-dollar"></i> إصدار فاتورة مبيعات جديدة
        </a>
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

<!-- لوحة المندوب (Dashboard Summary Cards) -->
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px;">
    <div style="background: white; border-right: 4px solid #4e73df; padding: 20px; border-radius: 8px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <div style="font-size: 13px; font-weight: bold; color: #4e73df; text-transform: uppercase; margin-bottom: 5px;">إجمالي العمولات المستحقة (المسلمة)</div>
        <div style="font-size: 22px; font-weight: bold; color: #2e384d; font-family: monospace;"><?php echo number_format($total_earned_commissions, 2); ?> ل.س</div>
    </div>
    <div style="background: white; border-right: 4px solid #1cc88a; padding: 20px; border-radius: 8px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <div style="font-size: 13px; font-weight: bold; color: #1cc88a; text-transform: uppercase; margin-bottom: 5px;">إجمالي الدفعات المسددة للمندوب</div>
        <div style="font-size: 22px; font-weight: bold; color: #2e384d; font-family: monospace;"><?php echo number_format($total_payments_made, 2); ?> ل.س</div>
    </div>
    <div style="background: white; border-right: 4px solid #f6c23e; padding: 20px; border-radius: 8px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <div style="font-size: 13px; font-weight: bold; color: #f6c23e; text-transform: uppercase; margin-bottom: 5px;">صافي الرصيد / الذمة المالية</div>
        <div style="font-size: 22px; font-weight: bold; color: #2e384d; font-family: monospace;"><?php echo number_format($net_balance, 2); ?> ل.س</div>
    </div>
</div>

<!-- توضيح قاعدة الترحيل الذكي -->
<div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 12px 18px; border-radius: 6px; margin-bottom: 25px; color: #0c5460; font-size: 13.5px;">
    <i class="fas fa-info-circle" style="margin-left: 5px;"></i> <strong>قاعدة الترحيل الذكي للعمولات:</strong> يُحاسب المندوب حصراً على الفواتير ذات حالة <strong>"تم التسليم"</strong>؛ أما الفواتير التي لم تُسلم (قيد الانتظار أو مؤجلة) فلا تُحسب ضمن الرصيد الحالي وتُرحّل تلقائياً. يمكنك تبديل حالة التسليم لأي فاتورة مباشرة من الجدول أدناه.
</div>

<!-- شريط الفلترة (تاريخ + حالة تسليم + بحث) -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px;">
    <form method="GET" action="" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="id" value="<?php echo $rep_id; ?>">
        <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">من تاريخ:</label>
            <input type="date" name="filter_start" value="<?php echo htmlspecialchars($filter_start); ?>" style="padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">إلى تاريخ:</label>
            <input type="date" name="filter_end" value="<?php echo htmlspecialchars($filter_end); ?>" style="padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">حالة التسليم:</label>
            <select name="filter_delivery" style="padding:7px; border:1px solid #ccc; border-radius:4px;">
                <option value="">-- الكل --</option>
                <?php foreach ($delivery_labels as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $filter_delivery === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1; min-width:180px;"><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">بحث (رقم فاتورة / اسم عميل):</label>
            <input type="text" name="filter_search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="ابحث..." style="width:100%; padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <button type="submit" style="background:#4e73df; color:white; border:none; padding:8px 18px; border-radius:6px; cursor:pointer; font-weight:bold;">تطبيق الفلتر</button>
        <?php if (!empty($filter_start) || !empty($filter_end) || !empty($filter_delivery) || !empty($filter_search)): ?>
            <a href="representative_profile.php?id=<?php echo $rep_id; ?>" style="color:#666; font-size:13px; padding:8px 0;">إلغاء الفلتر</a>
        <?php endif; ?>
    </form>
</div>

<!-- فواتير المبيعات وتتبع التسليم -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; margin-bottom: 30px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #4e73df;">
        <i class="fas fa-shopping-cart"></i> فواتير المبيعات وتتبع التسليم
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfd; color: #555; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">التاريخ</th>
                    <th style="padding: 12px 15px;">رقم الفاتورة</th>
                    <th style="padding: 12px 15px;">اسم العميل</th>
                    <th style="padding: 12px 15px;">الإجمالي (ل.س)</th>
                    <th style="padding: 12px 15px;">إجمالي العمولة (ل.س)</th>
                    <th style="padding: 12px 15px;">حالة الدفع</th>
                    <th style="padding: 12px 15px;">الحالة وتتبع التسليم</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($sales_list) > 0): ?>
                    <?php foreach ($sales_list as $sale): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 12px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($sale['invoice_date']); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #4e73df;"><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                            <td style="padding: 12px 15px; font-weight: 600; color: #333;"><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #2e59d9;"><?php echo number_format($sale['total_amount_syp'], 2); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #1cc88a;"><?php echo number_format($sale['total_commissions'], 2); ?></td>
                            <td style="padding: 12px 15px;">
                                <?php echo htmlspecialchars($payment_labels[$sale['payment_status']] ?? $sale['payment_status']); ?>
                            </td>
                            <td style="padding: 12px 15px;">
                                <?php if ($sale['delivery_status'] == 'Delivered'): ?>
                                    <span style="background: #d4edda; color: #155724; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; display: inline-block;">تم التسليم (محسوبة)</span>
                                <?php elseif ($sale['delivery_status'] == 'Deferred'): ?>
                                    <span style="background: #cce5ff; color: #004085; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; display: inline-block;">مؤجلة (مرحّلة)</span>
                                <?php else: ?>
                                    <span style="background: #fff3cd; color: #856404; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; display: inline-block;">قيد الانتظار (مرحّلة)</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 15px; text-align: center;">
                                <?php if ($sale['delivery_status'] == 'Delivered'): ?>
                                    <form method="POST" style="display:inline;">
<?php csrfField(); ?>
                                        <input type="hidden" name="sale_id" value="<?php echo $sale['id']; ?>">
                                        <input type="hidden" name="toggle_status" value="Pending">
                                        <button type="submit" class="row-action-edit row-action-btn" title="تحويل لقيد الانتظار">إلغاء التسليم</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display:inline;">
<?php csrfField(); ?>
                                        <input type="hidden" name="sale_id" value="<?php echo $sale['id']; ?>">
                                        <input type="hidden" name="toggle_status" value="Delivered">
                                        <button type="submit" class="row-action-success row-action-btn" title="تأكيد التسليم لاحتساب العمولة">تأكيد التسليم</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="padding: 30px; text-align: center; color: #777;">لا توجد فواتير مبيعات مسجلة لهذا المندوب حالياً.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- سجل الدفعات النقدية -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08); margin-bottom: 30px;">
    <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #4e73df;">
        <i class="fas fa-history"></i> سجل الدفعات النقدية المسددة للمندوب
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfd; color: #555; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">التاريخ</th>
                    <th style="padding: 12px 15px;">المبلغ المسدد (ل.س)</th>
                    <th style="padding: 12px 15px;">ملاحظات</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($payments_list) > 0): ?>
                    <?php foreach ($payments_list as $pay): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 12px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($pay['payment_date']); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #e74a3b;"><?php echo number_format($pay['amount_syp'], 2); ?> ل.س</td>
                            <td style="padding: 12px 15px; color: #777;"><?php echo htmlspecialchars($pay['notes'] ?: 'لا توجد ملاحظات'); ?></td>
                            <td style="padding: 12px 15px; text-align: center;">
                                <button onclick='openEditPaymentModal(<?php echo json_encode($pay, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #f6c23e; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">
                                    <i class="fas fa-edit"></i> تعديل
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="padding: 25px; text-align: center; color: #777;">لا توجد دفعات نقدية مسجلة لهذا المندوب حتى الآن.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- نافذة تسجيل دفعة نقدية (Modal) -->
<div id="paymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1050; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 5px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e3e6f0; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #4e73df;"><i class="fas fa-money-bill-wave"></i> تسجيل دفعة نقدية للمندوب</h3>
            <button onclick="closePaymentModal()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_payment" value="1">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: bold; font-size: 13px;">المبلغ المسدد (ل.س): <span style="color: red;">*</span></label>
                <input type="number" step="0.01" name="amount_syp" required placeholder="0.00..." style="width: 100%; padding: 8px; border: 1px solid #d1d3e2; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: bold; font-size: 13px;">تاريخ الدفعة:</label>
                <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 8px; border: 1px solid #d1d3e2; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 4px; font-weight: bold; font-size: 13px;">ملاحظات أو سند الدفع:</label>
                <textarea name="notes" placeholder="ملاحظات حول الدفعة..." style="width: 100%; padding: 8px; border: 1px solid #d1d3e2; border-radius: 4px; height: 65px;"></textarea>
            </div>
            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px;">
                <button type="button" onclick="closePaymentModal()" style="background: #e2e8f0; color: #333; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-left: 8px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ وتسجيل الدفعة</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openPaymentModal() { document.getElementById('paymentModal').style.display = 'flex'; }
    function closePaymentModal() { document.getElementById('paymentModal').style.display = 'none'; }

    function openEditPaymentModal(payment) {
        document.getElementById('edit_payment_id').value = payment.id;
        document.getElementById('edit_amount_syp').value = payment.amount_syp;
        document.getElementById('edit_payment_date').value = payment.payment_date;
        document.getElementById('edit_notes').value = payment.notes || '';
        document.getElementById('editPaymentModal').style.display = 'flex';
    }
    function closeEditPaymentModal() { document.getElementById('editPaymentModal').style.display = 'none'; }
</script>

<!-- نافذة تعديل دفعة موجودة (Modal) -->
<div id="editPaymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1050; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 5px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e3e6f0; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #f6c23e;"><i class="fas fa-edit"></i> تعديل دفعة المندوب</h3>
            <button onclick="closeEditPaymentModal()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="edit_payment" value="1">
            <input type="hidden" name="payment_id" id="edit_payment_id">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: bold; font-size: 13px;">المبلغ المسدد (ل.س): <span style="color: red;">*</span></label>
                <input type="number" step="0.01" name="amount_syp" id="edit_amount_syp" required style="width: 100%; padding: 8px; border: 1px solid #d1d3e2; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: bold; font-size: 13px;">تاريخ الدفعة:</label>
                <input type="date" name="payment_date" id="edit_payment_date" style="width: 100%; padding: 8px; border: 1px solid #d1d3e2; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 4px; font-weight: bold; font-size: 13px;">ملاحظات أو سند الدفع:</label>
                <textarea name="notes" id="edit_notes" style="width: 100%; padding: 8px; border: 1px solid #d1d3e2; border-radius: 4px; height: 65px;"></textarea>
            </div>
            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px;">
                <button type="button" onclick="closeEditPaymentModal()" style="background: #e2e8f0; color: #333; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-left: 8px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ التعديل</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>