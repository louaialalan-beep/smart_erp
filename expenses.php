<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';
require_once __DIR__ . '/functions.php';
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
    currency_code ENUM('SYP','USD') NOT NULL DEFAULT 'SYP',
    frequency ENUM('weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// دفاعي: إضافة عمودي frequency وcurrency_code لو كان الجدول موجوداً مسبقاً من نسخة أقدم بلا هذين
// العمودين، أو تحديث نطاق frequency (ENUM) لو كان موجوداً بنسخة أقدم بلا خيار 'weekly'
try {
    $rt_cols = $conn->query("SHOW COLUMNS FROM recurring_expense_templates")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('frequency', $rt_cols)) {
        $conn->exec("ALTER TABLE recurring_expense_templates ADD COLUMN frequency ENUM('weekly','monthly','yearly') NOT NULL DEFAULT 'monthly'");
    } else {
        $conn->exec("ALTER TABLE recurring_expense_templates MODIFY COLUMN frequency ENUM('weekly','monthly','yearly') NOT NULL DEFAULT 'monthly'");
    }
    if (!in_array('currency_code', $rt_cols)) {
        $conn->exec("ALTER TABLE recurring_expense_templates ADD COLUMN currency_code ENUM('SYP','USD') NOT NULL DEFAULT 'SYP'");
    }
} catch (Exception $e) { /* يُتجاهل */ }

// دالة موحَّدة لحساب الاستحقاق اليومي **بالعملة الأصلية للبند** لأي بند بغض النظر عن تكراره —
// أسبوعي (÷6 أيام عمل فعلية، وليس 7)، شهري (÷30)، أو سنوي (÷360 = 12×30). التحويل الفعلي لليرة
// (إن كانت العملة دولاراً) يحدث لاحقاً بسعر الصرف الحقيقي *ليوم الاستحقاق بالذات* في نقطة الترحيل
// نفسها، وليس هنا — لضمان دقة تعكس تقلب السعر يوماً بيوم، لا سعراً ثابتاً واحداً طوال السنة.
function getDailyAccrualAmount($tpl) {
    $freq = $tpl['frequency'] ?? 'monthly';
    if ($freq === 'weekly') { return round(floatval($tpl['monthly_amount']) / 6, 2); }
    if ($freq === 'yearly') { return round(floatval($tpl['monthly_amount']) / 360, 2); }
    return round(floatval($tpl['monthly_amount']) / 30, 2);
}
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
            // تصحيح: كل فئات المصاريف الفورية (تلج، غاز، قهوة، بدل طعام...) تُرحَّل الآن تحت حساب واحد
            // موحَّد "مصاريف تشغيلية" بدل إنشاء حساب منفصل لكل فئة — يستثني هذا التوحيد "المصاريف
            // المتكررة" (إيجار، رواتب) عمداً، لأنها تبقى مصنَّفة كل واحدة بحسابها الخاص (منطق منفصل
            // تماماً في معالج الاستحقاقات أدناه، لم يُمَس).
            $debit_account_id  = findOrCreateAccount($conn, ['مصاريف تشغيلية'], 'مصاريف تشغيلية', 'Expense');
            $credit_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي', 'Asset');

            if (!$debit_account_id || !$credit_account_id) {
                throw new Exception("تعذّر تحديد/إنشاء الحساب المحاسبي اللازم — لم يُحفَظ المصروف لتفادي تسجيل بلا قيد محاسبي مقابل.");
            }
            insertJournalLine($conn, $debit_account_id, $amount, 0, $entry_num, $expense_date, $desc, 'Operational Expense');
            insertJournalLine($conn, $credit_account_id, 0, $amount, $entry_num, $expense_date, $desc, 'Operational Expense');

            $conn->commit();
            $msg = "تم تسجيل المصروف التشغيلي وترحيل القيد المحاسبي بنجاح!";
            logAudit($conn, 'INSERT', 'المصاريف التشغيلية', "تسجيل مصروف: $category بقيمة " . number_format($amount, 2) . " ل.س" . (!empty($cost_center) ? " (مركز التكلفة: $cost_center)" : ""), $expense_id);
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ: " . $e->getMessage();
        }
    } else { $error = "يرجى تعبئة الحقول الأساسية ومبلغ أكبر من الصفر."; }
}

// 1-ب. معالجة تعديل مصروف فوري موجود — لم تكن هذه الميزة موجودة إطلاقاً سابقاً (إضافة/حذف فقط).
// يعكس القيد القديم بالكامل ويعيد ترحيله بالقيم الجديدة، بنفس مبدأ عدم تعديل القيود المرحَّلة مباشرة.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_expense'])) {
    requireRole($conn, ['admin', 'accountant']);
    $expense_id = intval($_POST['expense_id']);
    $category = trim($_POST['category']);
    $amount = floatval($_POST['amount']);
    $cost_center = trim($_POST['cost_center']);
    $expense_date = $_POST['expense_date'];
    $notes = trim($_POST['notes']);

    $stmt_old_exp = $conn->prepare("SELECT * FROM operational_expenses WHERE id = ?");
    $stmt_old_exp->execute([$expense_id]);
    $old_exp = $stmt_old_exp->fetch(PDO::FETCH_ASSOC);

    if (!$old_exp) {
        $error = "المصروف غير موجود.";
    } elseif (empty($category) || $amount <= 0) {
        $error = "يرجى تعبئة الحقول الأساسية ومبلغ أكبر من الصفر.";
    } elseif (isDateInClosedPeriod($conn, $old_exp['expense_date']) || isDateInClosedPeriod($conn, $expense_date)) {
        $error = getPeriodLockErrorMessage($expense_date);
    } else {
        try {
            $conn->beginTransaction();

            $conn->prepare("UPDATE operational_expenses SET category = ?, amount = ?, cost_center = ?, expense_date = ?, notes = ? WHERE id = ?")
                 ->execute([$category, $amount, $cost_center, $expense_date, $notes, $expense_id]);

            // عكس القيد القديم بالكامل (بنفس رقمه) وإعادة ترحيله بالقيم الجديدة
            $entry_num = "JE-EXP-" . $expense_id;
            $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ?")->execute([$entry_num]);

            $desc = "مصروف تشغيلي: $category" . (!empty($cost_center) ? " (مركز التكلفة: $cost_center)" : "") . " (مُعدَّل)";
            $debit_account_id  = findOrCreateAccount($conn, ['مصاريف تشغيلية'], 'مصاريف تشغيلية', 'Expense');
            $credit_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي', 'Asset');

            if (!$debit_account_id || !$credit_account_id) {
                throw new Exception("تعذّر تحديد/إنشاء الحساب المحاسبي اللازم — أُلغي التعديل لتفادي قيد غير مكتمل.");
            }
            insertJournalLine($conn, $debit_account_id, $amount, 0, $entry_num, $expense_date, $desc, 'Operational Expense');
            insertJournalLine($conn, $credit_account_id, 0, $amount, $entry_num, $expense_date, $desc, 'Operational Expense');

            $conn->commit();
            $msg = "تم تحديث المصروف والقيد المحاسبي المرتبط به بنجاح!";
            logAudit($conn, 'UPDATE', 'المصاريف التشغيلية', "تعديل مصروف #$expense_id: $category بقيمة " . number_format($amount, 2) . " ل.س", $expense_id);
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ: " . $e->getMessage();
        }
    }
}

// 2. إضافة قالب مصروف متكرر (إيجار شهري، رواتب شهرية...) يُستخدم لاحقاً في توليد الاستحقاق اليومي
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_template'])) {
    $t_name = trim($_POST['t_name']);
    $t_category = trim($_POST['t_category']);
    $t_cost_center = trim($_POST['t_cost_center']);
    $t_monthly_amount = floatval($_POST['t_monthly_amount']);
    $t_frequency = in_array($_POST['t_frequency'] ?? '', ['weekly','monthly','yearly']) ? $_POST['t_frequency'] : 'monthly';
    $t_currency = ($_POST['t_currency'] ?? 'SYP') === 'USD' ? 'USD' : 'SYP';

    if (!empty($t_name) && $t_monthly_amount > 0) {
        try {
            $stmt = $conn->prepare("INSERT INTO recurring_expense_templates (name, category, cost_center, monthly_amount, frequency, currency_code) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$t_name, $t_category, $t_cost_center, $t_monthly_amount, $t_frequency, $t_currency]);
            $template_id = $conn->lastInsertId();
            $msg = "تمت إضافة بند المصروف المتكرر بنجاح!";
            logAudit($conn, 'INSERT', 'بنود المصاريف المتكررة', "إضافة بند متكرر: $t_name بمبلغ ($t_frequency) " . number_format($t_monthly_amount, 2) . " $t_currency", $template_id);
        } catch (Exception $e) { $error = "خطأ: " . $e->getMessage(); }
    } else { $error = "يرجى إدخال اسم البند ومبلغ شهري صحيح."; }
}

// 2-ب. تعديل بند متكرر موجود — لم تكن هذه الميزة موجودة إطلاقاً سابقاً (إضافة فقط). لا يمسّ هذا
// أي استحقاق سابق مُرحَّل بالفعل (تلك القيود ثابتة تاريخياً) — يؤثر فقط على الترحيلات القادمة.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_template'])) {
    requireRole($conn, ['admin', 'accountant']);
    $tpl_id = intval($_POST['tpl_id']);
    $t_name = trim($_POST['t_name']);
    $t_category = trim($_POST['t_category']);
    $t_cost_center = trim($_POST['t_cost_center']);
    $t_monthly_amount = floatval($_POST['t_monthly_amount']);
    $t_frequency = in_array($_POST['t_frequency'] ?? '', ['weekly','monthly','yearly']) ? $_POST['t_frequency'] : 'monthly';
    $t_currency = ($_POST['t_currency'] ?? 'SYP') === 'USD' ? 'USD' : 'SYP';

    if (empty($t_name) || $t_monthly_amount <= 0) {
        $error = "يرجى إدخال اسم البند ومبلغ صحيح أكبر من صفر.";
    } else {
        try {
            $conn->prepare("UPDATE recurring_expense_templates SET name = ?, category = ?, cost_center = ?, monthly_amount = ?, frequency = ?, currency_code = ? WHERE id = ?")
                 ->execute([$t_name, $t_category, $t_cost_center, $t_monthly_amount, $t_frequency, $t_currency, $tpl_id]);
            $msg = "تم تحديث بند المصروف المتكرر بنجاح! (لا يؤثر على الاستحقاقات السابقة المُرحَّلة بالفعل، فقط على الترحيلات القادمة)";
            logAudit($conn, 'UPDATE', 'بنود المصاريف المتكررة', "تعديل بند متكرر #$tpl_id: $t_name إلى ($t_frequency) " . number_format($t_monthly_amount, 2) . " $t_currency", $tpl_id);
        } catch (Exception $e) { $error = "خطأ: " . $e->getMessage(); }
    }
}

// 2-ج. حذف بند متكرر — يُسمح فقط إن لم يكن له أي استحقاق مُرحَّل سابقاً (لتفادي كسر السجل التاريخي)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_template'])) {
    requireRole($conn, ['admin']);
    $tpl_id = intval($_POST['tpl_id']);
    $stmt_usage = $conn->prepare("SELECT COUNT(*) FROM expense_accruals WHERE template_id = ?");
    $stmt_usage->execute([$tpl_id]);
    if ($stmt_usage->fetchColumn() > 0) {
        $error = "لا يمكن حذف هذا البند: له استحقاقات مُرحَّلة سابقاً في السجل. يمكنك إلغاء تفعيله بدلاً من ذلك.";
    } else {
        $conn->prepare("DELETE FROM recurring_expense_templates WHERE id = ?")->execute([$tpl_id]);
        $msg = "تم حذف البند المتكرر بنجاح (لم يكن له أي استحقاق سابق مُرحَّل).";
        logAudit($conn, 'DELETE', 'بنود المصاريف المتكررة', "حذف بند متكرر #$tpl_id", $tpl_id);
    }
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

            $daily_amount_native = getDailyAccrualAmount($tpl);
            $tpl_currency = $tpl['currency_code'] ?? 'SYP';
            $daily_rate = ($tpl_currency === 'USD') ? getExchangeRateForDate($conn, 'USD', $accrual_date) : 1;
            $daily_amount = ($tpl_currency === 'USD') ? round($daily_amount_native * $daily_rate, 2) : $daily_amount_native;

            $conn->beginTransaction();

            $stmt_acc = $conn->prepare("INSERT INTO expense_accruals (template_id, accrual_date, amount) VALUES (?, ?, ?)");
            $stmt_acc->execute([$tid, $accrual_date, $daily_amount]);

            // يظهر أيضاً في جدول المصاريف العادي للتقارير الموحّدة
            $note = "استحقاق يومي تلقائي (" . number_format($tpl['monthly_amount'], 2) . " $tpl_currency ÷ $days_divisor يوم" . ($tpl_currency === 'USD' ? "، بسعر صرف $daily_rate ليوم $accrual_date" : "") . ") لبند: " . $tpl['name'];
            $stmt_exp = $conn->prepare("INSERT INTO operational_expenses (category, amount, cost_center, expense_date, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt_exp->execute([$tpl['category'], $daily_amount, $tpl['cost_center'], $accrual_date, $note]);
            $expense_id = $conn->lastInsertId();

            $entry_num = "JE-ACCR-" . $expense_id;
            $debit_account_id  = findOrCreateAccount($conn, [$tpl['category']], $tpl['category'], 'Expense');
            $credit_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي', 'Asset');

            if (!$debit_account_id || !$credit_account_id) {
                throw new Exception("تعذّر تحديد/إنشاء الحساب المحاسبي اللازم لبند: " . $tpl['name']);
            }
            insertJournalLine($conn, $debit_account_id, $daily_amount, 0, $entry_num, $accrual_date, $note, 'Expense Accrual');
            insertJournalLine($conn, $credit_account_id, 0, $daily_amount, $entry_num, $accrual_date, $note, 'Expense Accrual');

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

// 3-ب. ترحيل استحقاق تراكمي (Backfill) لبند واحد من تاريخ بداية محدَّد وحتى اليوم — لحالة إضافة بند
// متكرر لم يكن موجوداً من أول الشهر (مثل إيجار يبدأ فعلياً من 2026-09-01 لكنك تُضيفه اليوم في منتصف
// الشهر)، فيُرحَّل استحقاق كل يوم فائت بين تاريخ البداية واليوم، بلا تكرار لأي يوم سبق ترحيله فعلاً.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['backfill_accrual'])) {
    requireRole($conn, ['admin', 'accountant']);
    $tid = intval($_POST['backfill_template_id']);
    $backfill_start = $_POST['backfill_start_date'] ?? date('Y-m-01');
    $backfill_end = date('Y-m-d');

    $stmt_t = $conn->prepare("SELECT * FROM recurring_expense_templates WHERE id = ? AND is_active = 1");
    $stmt_t->execute([$tid]);
    $tpl = $stmt_t->fetch(PDO::FETCH_ASSOC);

    if (!$tpl) {
        $error = "البند المتكرر غير موجود أو غير نشط.";
    } elseif ($backfill_start > $backfill_end) {
        $error = "تاريخ البداية يجب أن يكون قبل اليوم أو يساويه.";
    } else {
        try {
            $days_divisor2 = 30;
            $daily_amount_native = getDailyAccrualAmount($tpl);
            $tpl_currency = $tpl['currency_code'] ?? 'SYP';
            $posted_count = 0; $skipped_count = 0; $blocked_dates = [];

            $cursor = new DateTime($backfill_start);
            $end_dt = new DateTime($backfill_end);
            while ($cursor <= $end_dt) {
                $day_str = $cursor->format('Y-m-d');

                if (isDateInClosedPeriod($conn, $day_str)) {
                    $blocked_dates[] = $day_str;
                    $cursor->modify('+1 day');
                    continue;
                }

                $stmt_dup = $conn->prepare("SELECT id FROM expense_accruals WHERE template_id = ? AND accrual_date = ?");
                $stmt_dup->execute([$tid, $day_str]);
                if ($stmt_dup->fetchColumn()) {
                    $skipped_count++;
                    $cursor->modify('+1 day');
                    continue;
                }

                // سعر الصرف الفعلي لهذا اليوم بالذات — لا سعر ثابت واحد لكل أيام الترحيل التراكمي
                $day_rate = ($tpl_currency === 'USD') ? getExchangeRateForDate($conn, 'USD', $day_str) : 1;
                $daily_amount = ($tpl_currency === 'USD') ? round($daily_amount_native * $day_rate, 2) : $daily_amount_native;

                $conn->beginTransaction();

                $conn->prepare("INSERT INTO expense_accruals (template_id, accrual_date, amount) VALUES (?, ?, ?)")
                     ->execute([$tid, $day_str, $daily_amount]);

                $note = "استحقاق يومي تراكمي (تصحيح لاحق) — " . number_format($tpl['monthly_amount'], 2) . " $tpl_currency ÷ $days_divisor2 يوم" . ($tpl_currency === 'USD' ? "، بسعر صرف $day_rate ليوم $day_str" : "") . " — لبند: " . $tpl['name'];
                $conn->prepare("INSERT INTO operational_expenses (category, amount, cost_center, expense_date, notes) VALUES (?, ?, ?, ?, ?)")
                     ->execute([$tpl['category'], $daily_amount, $tpl['cost_center'], $day_str, $note]);
                $expense_id = $conn->lastInsertId();

                $entry_num = "JE-ACCR-" . $expense_id;
                $debit_account_id  = findOrCreateAccount($conn, [$tpl['category']], $tpl['category'], 'Expense');
                $credit_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي', 'Asset');

                if (!$debit_account_id || !$credit_account_id) {
                    throw new Exception("تعذّر تحديد/إنشاء الحساب المحاسبي اللازم لبند: " . $tpl['name'] . " ليوم $day_str");
                }
                insertJournalLine($conn, $debit_account_id, $daily_amount, 0, $entry_num, $day_str, $note, 'Expense Accrual');
                insertJournalLine($conn, $credit_account_id, 0, $daily_amount, $entry_num, $day_str, $note, 'Expense Accrual');

                $conn->commit();
                $posted_count++;
                $cursor->modify('+1 day');
            }

            $parts = [];
            if ($posted_count > 0) { $parts[] = "تم ترحيل استحقاق $posted_count يوم بنجاح من $backfill_start إلى $backfill_end"; }
            if ($skipped_count > 0) { $parts[] = "تخطي $skipped_count يوم مُرحَّل مسبقاً"; }
            if (count($blocked_dates) > 0) { $parts[] = "تعذّر ترحيل " . count($blocked_dates) . " يوم ضمن فترة مالية مُقفَلة"; }

            if ($posted_count > 0) {
                $msg = implode(" — ", $parts) . " لبند: " . $tpl['name'] . ".";
                logAudit($conn, 'INSERT', 'استحقاق المصاريف المتكررة', "ترحيل استحقاق تراكمي لبند: " . $tpl['name'] . " من $backfill_start إلى $backfill_end — $posted_count يوم بقيمة " . number_format($daily_amount, 2) . " ل.س/يوم");
            } else {
                $error = implode(" — ", $parts) ?: "لا توجد أيام جديدة لترحيلها ضمن هذا النطاق.";
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ أثناء الترحيل التراكمي: " . $e->getMessage();
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

<?php
// ============================================================
// فلتر تاريخ (من/إلى) + بطاقتان: إجمالي المصاريف التشغيلية (الفورية، بحساب "مصاريف تشغيلية" الموحَّد)
// مقابل إجمالي المصاريف المتكررة (استحقاقات إيجار/رواتب، بحساباتها المنفصلة الخاصة بكل بند)
// ============================================================
$exp_filter_start = $_GET['exp_filter_start'] ?? date('Y-m-01');
$exp_filter_end   = $_GET['exp_filter_end'] ?? date('Y-m-t');

$total_immediate_expenses = 0;
$total_recurring_expenses = 0;
try {
    $stmt_immediate = $conn->prepare("
        SELECT COALESCE(SUM(je.debit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'مصاريف تشغيلية' AND je.source_module = 'Operational Expense'
          AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_immediate->execute([$exp_filter_start, $exp_filter_end]);
    $total_immediate_expenses = floatval($stmt_immediate->fetchColumn());

    $stmt_recurring = $conn->prepare("
        SELECT COALESCE(SUM(je.debit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE je.source_module = 'Expense Accrual' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_recurring->execute([$exp_filter_start, $exp_filter_end]);
    $total_recurring_expenses = floatval($stmt_recurring->fetchColumn());
} catch (Exception $e) { /* يُتجاهل إن تعذّر */ }
?>

<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 15px;">
    <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <label style="font-size: 13px; font-weight: bold; color: #555;"><i class="fas fa-filter"></i> من:</label>
        <input type="date" name="exp_filter_start" value="<?php echo htmlspecialchars($exp_filter_start); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
        <label style="font-size: 13px; font-weight: bold; color: #555;">إلى:</label>
        <input type="date" name="exp_filter_end" value="<?php echo htmlspecialchars($exp_filter_end); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
        <button type="submit" style="background: #4e73df; color: white; border: none; padding: 7px 16px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold;">تطبيق</button>
    </form>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 15px; margin-bottom: 20px;">
    <div style="background: #fdecea; border-right: 4px solid #e74a3b; padding: 18px; border-radius: 8px;">
        <div style="color: #a33636; font-size: 13px; font-weight: bold;">إجمالي المصاريف التشغيلية (الفورية)</div>
        <div style="font-size: 22px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 6px;"><?php echo number_format($total_immediate_expenses, 2); ?> ل.س</div>
    </div>
    <div style="background: #fff8e6; border-right: 4px solid #f6c23e; padding: 18px; border-radius: 8px;">
        <div style="color: #856404; font-size: 13px; font-weight: bold;">إجمالي المصاريف التشغيلية المتكررة (استحقاق إيجار/رواتب)</div>
        <div style="font-size: 22px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 6px;"><?php echo number_format($total_recurring_expenses, 2); ?> ل.س</div>
    </div>
</div>

<!-- توضيح منطق الاستحقاق اليومي -->
<div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; color: #0c5460; font-size: 13.5px;">
    <i class="fas fa-info-circle" style="margin-left: 5px;"></i>
    <strong>الاستحقاق اليومي للمصاريف المتكررة:</strong> عرِّف البند مرة واحدة (كإيجار المحل أو إجمالي الرواتب الشهرية)، ثم رحِّل استحقاقه اليومي بضغطة زر. القيد المُنشأ الآن يُخصَم **فعلياً من الصندوق النقدي** كل يوم (مدين المصروف / دائن "الصندوق الرئيسي") — يُعامَل كدفع نقدي فعلي يومي، لا كالتزام مؤجَّل.
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
                <th style="padding: 10px 15px;">التكرار / المبلغ</th>
                <th style="padding: 10px 15px;">الاستحقاق اليومي</th>
                <th style="padding: 10px 15px; text-align: center;">الإجراء</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($templates_list) > 0): foreach ($templates_list as $tpl): 
                $daily = getDailyAccrualAmount($tpl);
                $already_today = in_array($tpl['id'], $accrued_today_ids);
            ?>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 10px 15px; font-weight: bold; color: #333;"><?php echo htmlspecialchars($tpl['name']); ?></td>
                    <td style="padding: 10px 15px; color: #666;"><?php echo htmlspecialchars($tpl['category']); ?></td>
                    <td style="padding: 10px 15px; color: #4e73df;"><?php echo htmlspecialchars($tpl['cost_center'] ?: 'عام'); ?></td>
                    <td style="padding: 10px 15px; font-family: monospace; font-weight: bold;">
                        <?php
                            $freq_val = $tpl['frequency'] ?? 'monthly';
                            $tpl_cur = $tpl['currency_code'] ?? 'SYP';
                            $freq_labels = ['weekly' => 'أسبوعي', 'monthly' => 'شهري', 'yearly' => 'سنوي'];
                            $freq_colors = ['weekly' => ['#fdecea', '#a33636'], 'monthly' => ['#eef1f6', '#555'], 'yearly' => ['#e2d9f3', '#4b3869']];
                            $cur_symbol = $tpl_cur === 'USD' ? '$' : 'ل.س';
                        ?>
                        <?php echo $tpl_cur === 'USD' ? '$' : ''; ?><?php echo number_format($tpl['monthly_amount'], 2); ?><?php echo $tpl_cur === 'SYP' ? ' ل.س' : ''; ?>
                        <span style="background: <?php echo $freq_colors[$freq_val][0]; ?>; color: <?php echo $freq_colors[$freq_val][1]; ?>; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-right: 4px;">
                            <?php echo $freq_labels[$freq_val]; ?>
                        </span>
                    </td>
                    <td style="padding: 10px 15px; font-family: monospace; color: #e74a3b; font-weight: bold;">
                        <?php if ($tpl_cur === 'USD'): ?>
                            $<?php echo number_format($daily, 2); ?>
                            <div style="font-size: 10px; color: #999; font-weight: normal;">(÷<?php echo ['weekly' => 6, 'monthly' => 30, 'yearly' => 360][$freq_val]; ?>) — يُحوَّل لليرة بسعر يوم الترحيل الفعلي</div>
                        <?php else: ?>
                            <?php echo number_format($daily, 2); ?> ل.س
                            <div style="font-size: 10px; color: #999; font-weight: normal;">(÷<?php echo ['weekly' => 6, 'monthly' => 30, 'yearly' => 360][$freq_val]; ?>)</div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 10px 15px; text-align: center;">
                        <?php if ($already_today): ?>
                            <span style="background: #d4edda; color: #155724; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; display: block; margin-bottom: 4px;">تم ترحيل اليوم ✓</span>
                        <?php else: ?>
                            <form method="POST" style="display: inline-block; margin-bottom: 4px;">
<?php csrfField(); ?>
                                <input type="hidden" name="accrue_one" value="<?php echo $tpl['id']; ?>">
                                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;">ترحيل استحقاق اليوم</button>
                            </form>
                        <?php endif; ?>
                        <br>
                        <button type="button" onclick="openBackfillModal(<?php echo $tpl['id']; ?>, '<?php echo htmlspecialchars($tpl['name'], ENT_QUOTES); ?>')" style="background: #6f42c1; color: white; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 10.5px; font-weight: bold; margin-top: 3px;">
                            <i class="fas fa-history"></i> ترحيل تراكمي منذ تاريخ
                        </button>
                        <br>
                        <button type="button" onclick='openEditTplModal(<?php echo json_encode($tpl, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #4e73df; color: white; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 10.5px; font-weight: bold; margin-top: 3px;">
                            <i class="fas fa-edit"></i> تعديل البند
                        </button>
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
                <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
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
                    <td style="padding: 12px 15px; text-align: center;">
                        <button onclick='openEditExpModal(<?php echo json_encode($exp, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #f6c23e; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">
                            <i class="fas fa-edit"></i> تعديل
                        </button>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" style="padding: 25px; text-align: center; color: #777;">لا توجد مصاريف مسجلة.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal: تعديل مصروف تشغيلي -->
<div id="editExpModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; padding: 25px; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #f6c23e;"><i class="fas fa-edit"></i> تعديل مصروف تشغيلي</h3>
            <button onclick="closeEditExpModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="edit_expense" value="1">
            <input type="hidden" name="expense_id" id="edit_exp_id">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">بند المصروف:</label>
                <input type="text" name="category" id="edit_exp_category" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">المبلغ (ل.س):</label>
                <input type="number" step="0.01" min="0.01" name="amount" id="edit_exp_amount" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">مركز التكلفة:</label>
                <input type="text" name="cost_center" id="edit_exp_cost_center" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ المصروف:</label>
                <input type="date" name="expense_date" id="edit_exp_date" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">ملاحظات:</label>
                <textarea name="notes" id="edit_exp_notes" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; height: 55px;"></textarea>
            </div>
            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px;">
                <button type="button" onclick="closeEditExpModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer;">إلغاء</button>
                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">تحديث المصروف</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditExpModal(exp) {
        document.getElementById('edit_exp_id').value = exp.id;
        document.getElementById('edit_exp_category').value = exp.category;
        document.getElementById('edit_exp_amount').value = exp.amount;
        document.getElementById('edit_exp_cost_center').value = exp.cost_center || '';
        document.getElementById('edit_exp_date').value = exp.expense_date;
        document.getElementById('edit_exp_notes').value = exp.notes || '';
        document.getElementById('editExpModal').style.display = 'flex';
    }
    function closeEditExpModal() {
        document.getElementById('editExpModal').style.display = 'none';
    }
</script>

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
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">التكرار:</label>
                <select name="t_frequency" id="tplFrequency" onchange="updateAmountLabel()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background: #fff;">
                    <option value="weekly">أسبوعي (يُقسَّم ÷6 أيام عمل)</option>
                    <option value="monthly" selected>شهري (يُقسَّم ÷30 يومياً)</option>
                    <option value="yearly">سنوي (يُقسَّم ÷360 يومياً)</option>
                </select>
            </div>
            <div style="margin-bottom: 15px; display: flex; gap: 10px;">
                <div style="flex: 2;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;" id="tplAmountLabel">المبلغ الشهري الكامل:</label>
                    <input type="number" step="0.01" name="t_monthly_amount" id="tplMonthlyAmount" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
                <div style="flex: 1;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">العملة:</label>
                    <select name="t_currency" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background: #fff;">
                        <option value="SYP">ل.س</option>
                        <option value="USD">$</option>
                    </select>
                </div>
            </div>
            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px;">
                <button type="button" onclick="closeTplModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer;">إلغاء</button>
                <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ البند</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: تعديل بند مصروف متكرر -->
<div id="editTplModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; padding: 25px; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #4e73df;"><i class="fas fa-edit"></i> تعديل بند مصروف متكرر</h3>
            <button onclick="closeEditTplModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <div style="background: #fff3cd; color: #856404; padding: 8px 12px; border-radius: 4px; margin-bottom: 15px; font-size: 12px;">
            <i class="fas fa-info-circle"></i> التعديل يؤثر فقط على الترحيلات القادمة — لا يُغيِّر أي استحقاق سابق مُرحَّل بالفعل.
        </div>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="edit_template" value="1">
            <input type="hidden" name="tpl_id" id="edit_tpl_id">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">اسم البند:</label>
                <input type="text" name="t_name" id="edit_tpl_name" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">التصنيف المحاسبي:</label>
                <input type="text" name="t_category" id="edit_tpl_category" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">مركز التكلفة (اختياري):</label>
                <input type="text" name="t_cost_center" id="edit_tpl_cost_center" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">التكرار:</label>
                <select name="t_frequency" id="edit_tpl_frequency" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background: #fff;">
                    <option value="weekly">أسبوعي (يُقسَّم ÷6 أيام عمل)</option>
                    <option value="monthly">شهري (يُقسَّم ÷30 يومياً)</option>
                    <option value="yearly">سنوي (يُقسَّم ÷360 يومياً)</option>
                </select>
            </div>
            <div style="margin-bottom: 15px; display: flex; gap: 10px;">
                <div style="flex: 2;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">المبلغ الكامل:</label>
                    <input type="number" step="0.01" name="t_monthly_amount" id="edit_tpl_amount" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
                <div style="flex: 1;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500;">العملة:</label>
                    <select name="t_currency" id="edit_tpl_currency" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background: #fff;">
                        <option value="SYP">ل.س</option>
                        <option value="USD">$</option>
                    </select>
                </div>
            </div>
            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; display: flex; justify-content: space-between;">
                <button type="button" onclick="deleteTplFromModal()" style="background: #fdecea; color: #e74a3b; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 12px;"><i class="fas fa-trash-alt"></i> حذف البند</button>
                <div>
                    <button type="button" onclick="closeEditTplModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer;">إلغاء</button>
                    <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ التعديلات</button>
                </div>
            </div>
        </form>
    </div>
</div>
<form method="POST" id="deleteTplForm" style="display:none;">
<?php csrfField(); ?>
    <input type="hidden" name="delete_template" value="1">
    <input type="hidden" name="tpl_id" id="delete_tpl_id">
</form>

<!-- Modal: ترحيل استحقاق تراكمي منذ تاريخ -->
<div id="backfillModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 420px; max-width: 95%; padding: 25px; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #6f42c1; font-size: 16px;"><i class="fas fa-history"></i> ترحيل تراكمي: <span id="backfill_tpl_name"></span></h3>
            <button onclick="closeBackfillModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <p style="font-size: 12.5px; color: #666; margin-bottom: 15px;">يُرحِّل استحقاق يوم بيوم من التاريخ المحدَّد وحتى اليوم — يتخطى تلقائياً أي يوم سبق ترحيله فعلاً.</p>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="backfill_accrual" value="1">
            <input type="hidden" name="backfill_template_id" id="backfill_template_id">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">ابدأ الترحيل من تاريخ:</label>
                <input type="date" name="backfill_start_date" id="backfill_start_date" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px;">
                <button type="button" onclick="closeBackfillModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer;">إلغاء</button>
                <button type="submit" style="background: #6f42c1; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">ترحيل حتى اليوم</button>
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
    function updateAmountLabel() {
        var freq = document.getElementById('tplFrequency').value;
        var labels = { weekly: 'المبلغ الأسبوعي الكامل:', monthly: 'المبلغ الشهري الكامل:', yearly: 'المبلغ السنوي الكامل:' };
        document.getElementById('tplAmountLabel').innerText = labels[freq] || labels.monthly;
    }

    function openBackfillModal(tplId, tplName) {
        document.getElementById('backfill_template_id').value = tplId;
        document.getElementById('backfill_tpl_name').innerText = tplName;
        // افتراضي: أول يوم من الشهر الحالي — يطابق طلبك المعتاد "البدء من أول الشهر"
        var now = new Date();
        var firstOfMonth = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
        document.getElementById('backfill_start_date').value = firstOfMonth;
        document.getElementById('backfillModal').style.display = 'flex';
    }
    function closeBackfillModal() {
        document.getElementById('backfillModal').style.display = 'none';
    }
</script>

<?php include 'footer.php'; ?>