<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';
$msg = ""; $error = "";

// دالة عامة للبحث عن حساب محاسبي بكلمات مفتاحية أو إنشائه إن لم يوجد (نفس منطق باقي النظام)

// دالة عامة لإدراج سطر قيد واحد (account_id/debit/credit في نفس الصف، مطابقة لبنية journal_entries الفعلية)
function insertJournalLine($conn, $account_id, $debit, $credit, $entry_number, $entry_date, $description, $source_module) {
    $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
    $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
    $vals = [$account_id, $entry_date, $description, $debit, $credit];

    if (in_array('entry_number', $existing_cols)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_number; }
    if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'SYP'; }
    if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = 1; }
    if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = $source_module; }

    $placeholders = implode(',', array_fill(0, count($cols_to_insert), '?'));
    $col_names = implode(',', $cols_to_insert);
    $stmt_j = $conn->prepare("INSERT INTO journal_entries ({$col_names}) VALUES ({$placeholders})");
    $stmt_j->execute($vals);
}

// إنشاء جداول المصاريف المتكررة والاستحقاق اليومي إن لم تكن موجودة
$conn->exec("CREATE TABLE IF NOT EXISTS recurring_expense_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    cost_center VARCHAR(100),
    monthly_amount DECIMAL(15,2) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->exec("CREATE TABLE IF NOT EXISTS expense_accruals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    accrual_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_template_day (template_id, accrual_date)
)");

// 1. معالجة إضافة مصروف فوري (نقدي) عادي
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_expense'])) {
    requireRole($conn, ['admin', 'accountant']);
    $category = trim($_POST['category']);
    $amount = floatval($_POST['amount']);
    $cost_center = trim($_POST['cost_center']);
    $expense_date = $_POST['expense_date'];
    $notes = trim($_POST['notes']);

    if (!empty($category) && $amount > 0 && isDateInClosedPeriod($conn, $expense_date)) {
        $error = getPeriodLockErrorMessage($expense_date);
    } elseif (!empty($category) && $amount > 0) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("INSERT INTO operational_expenses (category, amount, cost_center, expense_date, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$category, $amount, $cost_center, $expense_date, $notes]);
            $expense_id = $conn->lastInsertId();

            // تصحيح: قيد مزدوج متوافق مع بنية journal_entries الفعلية بدل عمود total_amount غير الموجود
            // هذا مصروف مدفوع نقداً فوراً: مدين المصروف / دائن الصندوق
            $entry_num = "JE-EXP-" . $expense_id;
            $desc = "مصروف تشغيلي: $category" . (!empty($cost_center) ? " (مركز التكلفة: $cost_center)" : "");
            $debit_account_id  = findOrCreateAccount($conn, [$category, 'مصروفات تشغيلية'], $category);
            $credit_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي');

            if ($debit_account_id && $credit_account_id) {
                insertJournalLine($conn, $debit_account_id, $amount, 0, $entry_num, $expense_date, $desc, 'Operational Expense');
                insertJournalLine($conn, $credit_account_id, 0, $amount, $entry_num, $expense_date, $desc, 'Operational Expense');
            }

            $conn->commit();
            $msg = "تم تسجيل المصروف التشغيلي وترحيل القيد المحاسبي بنجاح!";
            logAudit($conn, 'INSERT', 'المصاريف التشغيلية', "تسجيل مصروف: $category بقيمة " . number_format($amount, 2) . " ل.س" . (!empty($cost_center) ? " (مركز التكلفة: $cost_center)" : ""), $expense_id);
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ: " . $e->getMessage();
        }
    } else { $error = "يرجى تعبئة الحقول الأساسية ومبلغ أكبر من الصفر."; }
}

// 2. إضافة قالب مصروف متكرر (إيجار شهري، رواتب شهرية...) يُستخدم لاحقاً في توليد الاستحقاق اليومي
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_template'])) {
    $t_name = trim($_POST['t_name']);
    $t_category = trim($_POST['t_category']);
    $t_cost_center = trim($_POST['t_cost_center']);
    $t_monthly_amount = floatval($_POST['t_monthly_amount']);

    if (!empty($t_name) && $t_monthly_amount > 0) {
        try {
            $stmt = $conn->prepare("INSERT INTO recurring_expense_templates (name, category, cost_center, monthly_amount) VALUES (?, ?, ?, ?)");
            $stmt->execute([$t_name, $t_category, $t_cost_center, $t_monthly_amount]);
            $template_id = $conn->lastInsertId();
            $msg = "تمت إضافة بند المصروف المتكرر بنجاح!";
            logAudit($conn, 'INSERT', 'بنود المصاريف المتكررة', "إضافة بند متكرر: $t_name بمبلغ شهري " . number_format($t_monthly_amount, 2) . " ل.س", $template_id);
        } catch (Exception $e) { $error = "خطأ: " . $e->getMessage(); }
    } else { $error = "يرجى إدخال اسم البند ومبلغ شهري صحيح."; }
}

// 3. ترحيل استحقاق يومي لبند متكرر واحد (أو لكل البنود النشطة دفعة واحدة)
// القيد هنا استحقاق وليس دفعاً نقدياً: مدين المصروف / دائن "مصروفات مستحقة الدفع" (التزام)
// يُصفَّى هذا الالتزام لاحقاً بقيد منفصل عند السداد الفعلي (خارج نطاق هذه الشاشة حالياً).
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['accrue_one']) || isset($_POST['accrue_all']))) {
    $accrual_date = date('Y-m-d');
    // القسمة على 30 يوماً (شهر محاسبي موحّد) هي الاصطلاح الذي طلبته؛ البديل الأدق هو القسمة على
    // عدد أيام الشهر الفعلي: (int) date('t') بدل الرقم الثابت 30 إن رغبت بدقة تقويمية أعلى.
    $days_divisor = 30;

    if (isDateInClosedPeriod($conn, $accrual_date)) {
        $error = getPeriodLockErrorMessage($accrual_date);
    } else {
    try {
        if (isset($_POST['accrue_one'])) {
            $template_ids = [intval($_POST['accrue_one'])];
        } else {
            $stmt_ids = $conn->query("SELECT id FROM recurring_expense_templates WHERE is_active = 1");
            $template_ids = $stmt_ids->fetchAll(PDO::FETCH_COLUMN);
        }

        $posted_count = 0;
        $skipped_count = 0;

        foreach ($template_ids as $tid) {
            $stmt_t = $conn->prepare("SELECT * FROM recurring_expense_templates WHERE id = ? AND is_active = 1");
            $stmt_t->execute([$tid]);
            $tpl = $stmt_t->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) { continue; }

            // منع الترحيل المزدوج لنفس البند في نفس اليوم (القيد الفريد على template_id + accrual_date يحمي أيضاً على مستوى القاعدة)
            $stmt_dup = $conn->prepare("SELECT id FROM expense_accruals WHERE template_id = ? AND accrual_date = ?");
            $stmt_dup->execute([$tid, $accrual_date]);
            if ($stmt_dup->fetchColumn()) {
                $skipped_count++;
                continue;
            }

            $daily_amount = round($tpl['monthly_amount'] / $days_divisor, 2);

            $conn->beginTransaction();

            $stmt_acc = $conn->prepare("INSERT INTO expense_accruals (template_id, accrual_date, amount) VALUES (?, ?, ?)");
            $stmt_acc->execute([$tid, $accrual_date, $daily_amount]);

            // يظهر أيضاً في جدول المصاريف العادي للتقارير الموحّدة
            $note = "استحقاق يومي تلقائي (" . number_format($tpl['monthly_amount'], 2) . " ÷ $days_divisor يوم) لبند: " . $tpl['name'];
            $stmt_exp = $conn->prepare("INSERT INTO operational_expenses (category, amount, cost_center, expense_date, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt_exp->execute([$tpl['category'], $daily_amount, $tpl['cost_center'], $accrual_date, $note]);
            $expense_id = $conn->lastInsertId();

            $entry_num = "JE-ACCR-" . $expense_id;
            $debit_account_id  = findOrCreateAccount($conn, [$tpl['category'], 'مصروفات تشغيلية'], $tpl['category']);
            $credit_account_id = findOrCreateAccount($conn, ['مصروفات مستحقة', 'ذمم دائنة', 'accrued'], 'مصروفات مستحقة الدفع');

            if ($debit_account_id && $credit_account_id) {
                insertJournalLine($conn, $debit_account_id, $daily_amount, 0, $entry_num, $accrual_date, $note, 'Expense Accrual');
                insertJournalLine($conn, $credit_account_id, 0, $daily_amount, $entry_num, $accrual_date, $note, 'Expense Accrual');
            }

            $conn->commit();
            logAudit($conn, 'INSERT', 'استحقاق المصاريف المتكررة', "ترحيل استحقاق يومي لبند: " . $tpl['name'] . " بقيمة " . number_format($daily_amount, 2) . " ل.س (يوم $accrual_date)", $expense_id);
            $posted_count++;
        }

        if ($posted_count > 0) {
            $msg = "تم ترحيل استحقاق $posted_count بند/بنود لليوم بنجاح" . ($skipped_count > 0 ? " (تم تخطي $skipped_count بند مُرحَّل مسبقاً اليوم)." : ".");
        } elseif ($skipped_count > 0) {
            $error = "كل البنود المحددة مُرحَّلة بالفعل عن اليوم.";
        } else {
            $error = "لا توجد بنود متكررة نشطة لترحيلها.";
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        $error = "خطأ أثناء ترحيل الاستحقاق: " . $e->getMessage();
    }
    }
}

$expenses_list = $conn->query("SELECT * FROM operational_expenses ORDER BY expense_date DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$templates_list = $conn->query("SELECT * FROM recurring_expense_templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// تحقق لكل بند إن كان قد رُحِّل اليوم فعلاً (لتعطيل الزر وتفادي طلب ترحيل مكرر بصرياً)
$today = date('Y-m-d');
$accrued_today_ids = [];
if (count($templates_list) > 0) {
    $stmt_today = $conn->prepare("SELECT template_id FROM expense_accruals WHERE accrual_date = ?");
    $stmt_today->execute([$today]);
    $accrued_today_ids = $stmt_today->fetchAll(PDO::FETCH_COLUMN);
}

// إجمالي الرواتب الشهرية النشطة (استرشادي فقط لتسهيل تعبئة قالب "رواتب" دون الحاجة للتنقل بين الصفحات)
$total_active_payroll = 0;
try {
    $stmt_payroll = $conn->query("SELECT COALESCE(SUM(base_salary), 0) FROM employees WHERE status = 'active'");
    $total_active_payroll = $stmt_payroll->fetchColumn();
} catch (Exception $e) {
    // في حال عدم توفر جدول employees لأي سبب، يُتجاهَل بصمت ولا يُعطَّل عمل الصفحة
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2>المصاريف التشغيلية ومراكز التكلفة</h2>
        <p style="color: #666; margin: 0;">تسجيل ومتابعة كافة المصاريف الإدارية والتشغيلية موزعة حسب مراكز التكلفة.</p>
    </div>
    <div style="display: flex; gap: 8px;">
        <button onclick="openTplModal()" style="background: #4e73df; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;"><i class="fas fa-sync-alt"></i> بند متكرر جديد</button>
        <button onclick="openExpModal()" style="background: #e74a3b; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;"><i class="fas fa-plus"></i> تسجيل مصروف جديد</button>
    </div>
</div>

<?php if ($msg): ?><div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $msg; ?></div><?php endif; ?>
<?php if ($error): ?><div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $error; ?></div><?php endif; ?>

<!-- توضيح منطق الاستحقاق اليومي -->
<div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; color: #0c5460; font-size: 13.5px;">
    <i class="fas fa-info-circle" style="margin-left: 5px;"></i>
    <strong>الاستحقاق اليومي للمصاريف المتكررة:</strong> عرِّف البند مرة واحدة (كإيجار المحل أو إجمالي الرواتب الشهرية)، ثم رحِّل استحقاقه اليومي بضغطة زر. القيد المُنشأ هو <strong>استحقاق</strong> (مدين المصروف / دائن "مصروفات مستحقة الدفع") وليس دفعاً نقدياً فورياً، لأنك لم تدفع المبلغ فعلياً كل يوم — يُصفَّى هذا الالتزام لاحقاً بقيد سداد منفصل عند الدفع الفعلي.
</div>

<!-- قسم البنود المتكررة والاستحقاق اليومي -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; margin-bottom: 25px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="background: #f8f9fc; padding: 12px 20px; border-bottom: 1px solid #e3e6f0; display: flex; justify-content: space-between; align-items: center;">
        <div style="font-weight: bold; color: #4e73df;"><i class="fas fa-calendar-day"></i> بنود المصاريف المتكررة والاستحقاق اليومي</div>
        <?php if (count($templates_list) > 0): ?>
            <form method="POST" onsubmit="return confirm('سيتم ترحيل استحقاق اليوم لكل البنود النشطة غير المُرحَّلة بعد. متابعة؟');">
<?php csrfField(); ?>
                <input type="hidden" name="accrue_all" value="1">
                <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">
                    <i class="fas fa-check-double"></i> ترحيل استحقاق اليوم لكل البنود
                </button>
            </form>
        <?php endif; ?>
    </div>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
        <thead>
            <tr style="background: #fdfdfd; color: #555; border-bottom: 2px solid #e3e6f0;">
                <th style="padding: 10px 15px;">اسم البند</th>
                <th style="padding: 10px 15px;">التصنيف</th>
                <th style="padding: 10px 15px;">مركز التكلفة</th>
                <th style="padding: 10px 15px;">المبلغ الشهري</th>
                <th style="padding: 10px 15px;">الاستحقاق اليومي (÷30)</th>
                <th style="padding: 10px 15px; text-align: center;">الإجراء</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($templates_list) > 0): foreach ($templates_list as $tpl): 
                $daily = round($tpl['monthly_amount'] / 30, 2);
                $already_today = in_array($tpl['id'], $accrued_today_ids);
            ?>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 10px 15px; font-weight: bold; color: #333;"><?php echo htmlspecialchars($tpl['name']); ?></td>
                    <td style="padding: 10px 15px; color: #666;"><?php echo htmlspecialchars($tpl['category']); ?></td>
                    <td style="padding: 10px 15px; color: #4e73df;"><?php echo htmlspecialchars($tpl['cost_center'] ?: 'عام'); ?></td>
                    <td style="padding: 10px 15px; font-family: monospace; font-weight: bold;"><?php echo number_format($tpl['monthly_amount'], 2); ?> ل.س</td>
                    <td style="padding: 10px 15px; font-family: monospace; color: #e74a3b; font-weight: bold;"><?php echo number_format($daily, 2); ?> ل.س</td>
                    <td style="padding: 10px 15px; text-align: center;">
                        <?php if ($already_today): ?>
                            <span style="background: #d4edda; color: #155724; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: bold;">تم ترحيل اليوم ✓</span>
                        <?php else: ?>
                            <form method="POST">
<?php csrfField(); ?>
                                <input type="hidden" name="accrue_one" value="<?php echo $tpl['id']; ?>">
                                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;">ترحيل استحقاق اليوم</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" style="padding: 20px; text-align: center; color: #777;">لا توجد بنود متكررة معرَّفة بعد. أضف بنداً مثل "إيجار المحل" أو "إجمالي الرواتب الشهرية".</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- جدول سجل المصاريف -->
<div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
        <thead>
            <tr style="background: #f8f9fc; color: #e74a3b; border-bottom: 2px solid #e3e6f0;">
                <th style="padding: 12px 15px;">التاريخ</th>
                <th style="padding: 12px 15px;">بند المصروف</th>
                <th style="padding: 12px 15px;">المبلغ (ل.س)</th>
                <th style="padding: 12px 15px;">مركز التكلفة</th>
                <th style="padding: 12px 15px;">ملاحظات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($expenses_list) > 0): foreach ($expenses_list as $exp): ?>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 12px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($exp['expense_date']); ?></td>
                    <td style="padding: 12px 15px; font-weight: bold; color: #333;"><?php echo htmlspecialchars($exp['category']); ?></td>
                    <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #e74a3b;"><?php echo number_format($exp['amount'], 2); ?> ل.س</td>
                    <td style="padding: 12px 15px; color: #4e73df; font-weight: 500;"><?php echo htmlspecialchars($exp['cost_center'] ?: 'عام'); ?></td>
                    <td style="padding: 12px 15px; color: #777;"><?php echo htmlspecialchars($exp['notes'] ?: '-'); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" style="padding: 25px; text-align: center; color: #777;">لا توجد مصاريف مسجلة.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal: تسجيل مصروف فوري (نقدي) -->
<div id="expModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; padding: 25px; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #e74a3b;"><i class="fas fa-receipt"></i> تسجيل مصروف تشغيلي نقدي</h3>
            <button onclick="closeExpModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="add_expense" value="1">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">بند المصروف:</label>
                <input type="text" name="category" required placeholder="مثال: كهرباء، صيانة، قرطاسية..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">المبلغ (ل.س):</label>
                <input type="number" step="0.01" name="amount" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">مركز التكلفة (اختياري):</label>
                <input type="text" name="cost_center" placeholder="مثال: الفرع الرئيسي..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ المصروف:</label>
                <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">ملاحظات:</label>
                <textarea name="notes" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; height: 55px;"></textarea>
            </div>
            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px;">
                <button type="button" onclick="closeExpModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer;">إلغاء</button>
                <button type="submit" style="background: #e74a3b; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ المصروف</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: إضافة بند متكرر -->
<div id="tplModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; padding: 25px; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #4e73df;"><i class="fas fa-sync-alt"></i> إضافة بند مصروف متكرر</h3>
            <button onclick="closeTplModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <?php if ($total_active_payroll > 0): ?>
            <div style="background: #f8f9fc; padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; font-size: 12.5px; color: #555;">
                <i class="fas fa-lightbulb" style="color: #f6c23e;"></i> إجمالي الرواتب الأساسية للموظفين النشطين حالياً:
                <b style="color: #4e73df; cursor: pointer;" onclick="fillPayrollAmount(<?php echo $total_active_payroll; ?>)"><?php echo number_format($total_active_payroll, 2); ?> ل.س (انقر للتعبئة التلقائية)</b>
            </div>
        <?php endif; ?>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="add_template" value="1">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">اسم البند:</label>
                <input type="text" name="t_name" required placeholder="مثال: إيجار المحل / إجمالي الرواتب الشهرية..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">التصنيف المحاسبي:</label>
                <input type="text" name="t_category" required placeholder="مثال: إيجارات / رواتب وأجور..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">مركز التكلفة (اختياري):</label>
                <input type="text" name="t_cost_center" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">المبلغ الشهري الكامل (ل.س):</label>
                <input type="number" step="0.01" name="t_monthly_amount" id="tplMonthlyAmount" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px;">
                <button type="button" onclick="closeTplModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer;">إلغاء</button>
                <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ البند</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openExpModal() { document.getElementById('expModal').style.display = 'flex'; }
    function closeExpModal() { document.getElementById('expModal').style.display = 'none'; }
    function openTplModal() { document.getElementById('tplModal').style.display = 'flex'; }
    function closeTplModal() { document.getElementById('tplModal').style.display = 'none'; }
    function fillPayrollAmount(amount) { document.getElementById('tplMonthlyAmount').value = amount; }
</script>

<?php include 'footer.php'; ?>