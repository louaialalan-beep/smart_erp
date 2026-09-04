<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';
$msg = ""; $error = "";

// دالة عامة للبحث عن حساب محاسبي بكلمات مفتاحية أو إنشائه إن لم يوجد
// (نفس المنطق المستخدم في sales.php وrepresentative_profile.php وsupplier_view.php)

// دالة عامة لإدراج سطر قيد واحد داخل جدول journal_entries (مطابقة لبنية الجدول الفعلية:
// account_id + debit + credit في نفس الصف، وليس جدول أسطر منفصل كما كان مفترضاً خطأً سابقاً)
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

// 1. إضافة موظف جديد بالبيانات المؤسسية الشاملة
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_employee'])) {
    $employee_code = trim($_POST['employee_code']);
    $full_name = trim($_POST['full_name']);
    $national_id = trim($_POST['national_id']);
    $gender = $_POST['gender'];
    $date_of_birth = $_POST['date_of_birth'];
    $nationality = trim($_POST['nationality']);
    $marital_status = $_POST['marital_status'];
    
    $department = trim($_POST['department']);
    $position = trim($_POST['position']); // تصحيح: إزالة المتغير الوهمي $_Position الناتج عن خطأ كتابي سابق
    $employment_type = $_POST['employment_type'];
    $hire_date = $_POST['hire_date'];
    $probation_end_date = $_POST['probation_end_date'];
    $status = $_POST['status'];
    
    $base_salary = floatval($_POST['base_salary']);
    $payment_method = $_POST['payment_method'];
    $bank_name = trim($_POST['bank_name']);
    $bank_account = trim($_POST['bank_account']);
    $iban = trim($_POST['iban']);
    
    $phone = trim($_POST['phone']);
    $work_email = trim($_POST['work_email']);
    $address = trim($_POST['address']);
    $emergency_contact_name = trim($_POST['emergency_contact_name']);
    $emergency_contact_phone = trim($_POST['emergency_contact_phone']);
    $emergency_relation = trim($_POST['emergency_relation']);

    if (!empty($full_name) && !empty($national_id) && $base_salary > 0) {
        try {
            $stmt = $conn->prepare("INSERT INTO employees (
                employee_code, full_name, national_id, gender, date_of_birth, nationality, marital_status,
                department, position, employment_type, hire_date, probation_end_date, status,
                base_salary, payment_method, bank_name, bank_account, iban,
                phone, work_email, address, emergency_contact_name, emergency_contact_phone, emergency_relation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $employee_code, $full_name, $national_id, $gender, $date_of_birth, $nationality, $marital_status,
                $department, $position, $employment_type, $hire_date, $probation_end_date, $status,
                $base_salary, $payment_method, $bank_name, $bank_account, $iban,
                $phone, $work_email, $address, $emergency_contact_name, $emergency_contact_phone, $emergency_relation
            ]);
            $new_emp_id = $conn->lastInsertId();
            $msg = "تمت إضافة الموظف المؤسسي وتوثيق ملفه بنجاح!";
            logAudit($conn, 'INSERT', 'الموارد البشرية', "إضافة موظف جديد: $full_name (كود: $employee_code) براتب أساسي " . number_format($base_salary, 2) . " ل.س", $new_emp_id);
        } catch (Exception $e) { $error = "خطأ في قاعدة البيانات (ربما الرقم الوظيفي أو الهوية مكرر): " . $e->getMessage(); }
    } else { $error = "الاسم الكامل، الرقم الهوية، والراتب الأساسي حقول إلزامية أساسية."; }
}

// 1ب. تعديل بيانات موظف موجود
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_employee'])) {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $employee_code = trim($_POST['employee_code']);
    $full_name = trim($_POST['full_name']);
    $national_id = trim($_POST['national_id']);
    $gender = $_POST['gender'];
    $date_of_birth = $_POST['date_of_birth'];
    $nationality = trim($_POST['nationality']);
    $marital_status = $_POST['marital_status'];
    $department = trim($_POST['department']);
    $position = trim($_POST['position']);
    $employment_type = $_POST['employment_type'];
    $hire_date = $_POST['hire_date'];
    $probation_end_date = $_POST['probation_end_date'];
    $status = $_POST['status'];
    $base_salary = floatval($_POST['base_salary']);
    $payment_method = $_POST['payment_method'];
    $bank_name = trim($_POST['bank_name']);
    $bank_account = trim($_POST['bank_account']);
    $iban = trim($_POST['iban']);
    $phone = trim($_POST['phone']);
    $work_email = trim($_POST['work_email']);
    $address = trim($_POST['address']);
    $emergency_contact_name = trim($_POST['emergency_contact_name']);
    $emergency_contact_phone = trim($_POST['emergency_contact_phone']);

    if ($employee_id <= 0) {
        $error = "معرف الموظف غير صالح.";
    } elseif (empty($full_name) || empty($national_id) || $base_salary <= 0) {
        $error = "الاسم الكامل، الرقم الهوية، والراتب الأساسي حقول إلزامية أساسية.";
    } else {
        try {
            $stmt = $conn->prepare("UPDATE employees SET
                employee_code = ?, full_name = ?, national_id = ?, gender = ?, date_of_birth = ?, nationality = ?, marital_status = ?,
                department = ?, position = ?, employment_type = ?, hire_date = ?, probation_end_date = ?, status = ?,
                base_salary = ?, payment_method = ?, bank_name = ?, bank_account = ?, iban = ?,
                phone = ?, work_email = ?, address = ?, emergency_contact_name = ?, emergency_contact_phone = ?
                WHERE id = ?");
            $stmt->execute([
                $employee_code, $full_name, $national_id, $gender, $date_of_birth, $nationality, $marital_status,
                $department, $position, $employment_type, $hire_date, $probation_end_date, $status,
                $base_salary, $payment_method, $bank_name, $bank_account, $iban,
                $phone, $work_email, $address, $emergency_contact_name, $emergency_contact_phone,
                $employee_id
            ]);
            $msg = "تم تحديث ملف الموظف بنجاح!";
            logAudit($conn, 'UPDATE', 'الموارد البشرية', "تعديل ملف الموظف: $full_name (كود: $employee_code) — الراتب الأساسي أصبح " . number_format($base_salary, 2) . " ل.س، الحالة: $status", $employee_id);
        } catch (Exception $e) { $error = "خطأ في قاعدة البيانات: " . $e->getMessage(); }
    }
}

// 2. منح سلفة مقسطة على عدة شهور مع جدولتها تلقائياً
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_advance'])) {
    requireRole($conn, ['admin', 'accountant']);
    $employee_id = intval($_POST['employee_id']);
    $total_amount = floatval($_POST['total_amount']);
    $installments_count = intval($_POST['installments_count']);
    $start_month = $_POST['start_month'];
    $notes = trim($_POST['notes']);

    if ($employee_id > 0 && $total_amount > 0 && $installments_count > 0 && isDateInClosedPeriod($conn, date('Y-m-d'))) {
        $error = getPeriodLockErrorMessage(date('Y-m-d'));
    } elseif ($employee_id > 0 && $total_amount > 0 && $installments_count > 0) {
        try {
            $conn->beginTransaction();
            $monthly_installment = round($total_amount / $installments_count, 2);

            $stmt = $conn->prepare("INSERT INTO employee_advances (employee_id, total_amount, installments_count, monthly_installment, start_month, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$employee_id, $total_amount, $installments_count, $monthly_installment, $start_month, $notes]);
            $advance_id = $conn->lastInsertId();

            $current_date = $start_month . '-01';
            for ($i = 0; $i < $installments_count; $i++) {
                $installment_month = date('Y-m', strtotime("$current_date +$i month"));
                $current_installment = $monthly_installment;
                if ($i == $installments_count - 1) {
                    $sum_prev = $monthly_installment * ($installments_count - 1);
                    $current_installment = round($total_amount - $sum_prev, 2);
                }

                $stmt_inst = $conn->prepare("INSERT INTO advance_installments (advance_id, employee_id, installment_month, amount, is_paid) VALUES (?, ?, ?, ?, 0)");
                $stmt_inst->execute([$advance_id, $employee_id, $installment_month, $current_installment]);
            }

            // تصحيح: قيد مزدوج متوافق مع بنية journal_entries الفعلية (account_id/debit/credit في نفس الصف)
            // بدل عمود total_amount غير الموجود وجدول journal_entry_items الوهمي
            $stmt_name = $conn->prepare("SELECT full_name FROM employees WHERE id = ?");
            $stmt_name->execute([$employee_id]);
            $emp_name = $stmt_name->fetchColumn() ?: ('موظف #' . $employee_id);

            $entry_num = "JE-ADV-" . $advance_id;
            $desc = "صرف سلفة مقسطة للموظف: " . $emp_name;
            $today = date('Y-m-d');

            $debit_account_id  = findOrCreateAccount($conn, ['سلف', 'موظف'], 'سلف الموظفين', 'Asset');
            $credit_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي', 'Asset');

            if ($debit_account_id && $credit_account_id) {
                insertJournalLine($conn, $debit_account_id, $total_amount, 0, $entry_num, $today, $desc, 'Employee Advance');
                insertJournalLine($conn, $credit_account_id, 0, $total_amount, $entry_num, $today, $desc, 'Employee Advance');
            }

            $conn->commit();
            $msg = "تمت جدولة وصرف السلفة المقسطة وترحيل القيد المحاسبي بنجاح!";
            logAudit($conn, 'INSERT', 'سلف الموظفين', "صرف سلفة مقسطة للموظف: $emp_name بقيمة " . number_format($total_amount, 2) . " ل.س على $installments_count قسط", $advance_id);
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ: " . $e->getMessage();
        }
    } else { $error = "بيانات السلفة غير صحيحة."; }
}

// 2ب. تعديل سلفة موجودة (مسموح فقط إذا لم يُسدَّد أي قسط منها بعد، لتفادي تعارض الجدولة)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_advance'])) {
    $advance_id = intval($_POST['advance_id'] ?? 0);
    $total_amount = floatval($_POST['total_amount']);
    $installments_count = intval($_POST['installments_count']);
    $start_month = $_POST['start_month'];
    $notes = trim($_POST['notes']);

    if ($advance_id <= 0 || $total_amount <= 0 || $installments_count <= 0) {
        $error = "بيانات السلفة غير صحيحة.";
    } else {
        try {
            $stmt_check = $conn->prepare("SELECT employee_id FROM employee_advances WHERE id = ?");
            $stmt_check->execute([$advance_id]);
            $employee_id = $stmt_check->fetchColumn();
            if (!$employee_id) {
                throw new Exception("السلفة غير موجودة.");
            }

            $stmt_paid = $conn->prepare("SELECT COUNT(*) FROM advance_installments WHERE advance_id = ? AND is_paid = 1");
            $stmt_paid->execute([$advance_id]);
            if ($stmt_paid->fetchColumn() > 0) {
                throw new Exception("لا يمكن تعديل السلفة بعد أن بدأ استقطاع قسط واحد منها على الأقل. يمكنك فقط تعديل الملاحظات، أو إلغاء الأقساط المتبقية يدوياً.");
            }

            // التحقق من قفل الفترة بتاريخ القيد المحاسبي الأصلي لهذه السلفة (تاريخ الصرف الفعلي)
            $stmt_orig_date = $conn->prepare("SELECT entry_date FROM journal_entries WHERE entry_number = ? LIMIT 1");
            $stmt_orig_date->execute(["JE-ADV-" . $advance_id]);
            $orig_entry_date = $stmt_orig_date->fetchColumn();
            if ($orig_entry_date && isDateInClosedPeriod($conn, $orig_entry_date)) {
                throw new Exception(getPeriodLockErrorMessage($orig_entry_date));
            }

            $conn->beginTransaction();
            $monthly_installment = round($total_amount / $installments_count, 2);

            $stmt_up = $conn->prepare("UPDATE employee_advances SET total_amount = ?, installments_count = ?, monthly_installment = ?, start_month = ?, notes = ? WHERE id = ?");
            $stmt_up->execute([$total_amount, $installments_count, $monthly_installment, $start_month, $notes, $advance_id]);

            // إعادة توليد جدول الأقساط بالكامل (لا يوجد أقساط مسدَّدة بعد، فالحذف والإعادة آمن)
            $conn->prepare("DELETE FROM advance_installments WHERE advance_id = ?")->execute([$advance_id]);

            $current_date = $start_month . '-01';
            for ($i = 0; $i < $installments_count; $i++) {
                $installment_month = date('Y-m', strtotime("$current_date +$i month"));
                $current_installment = $monthly_installment;
                if ($i == $installments_count - 1) {
                    $sum_prev = $monthly_installment * ($installments_count - 1);
                    $current_installment = round($total_amount - $sum_prev, 2);
                }
                $stmt_inst = $conn->prepare("INSERT INTO advance_installments (advance_id, employee_id, installment_month, amount, is_paid) VALUES (?, ?, ?, ?, 0)");
                $stmt_inst->execute([$advance_id, $employee_id, $installment_month, $current_installment]);
            }

            // تصحيح جوهري: بدل تعديل القيد الأصلي مباشرة، نُرحِّل قيد عكس ثم قيداً تصحيحياً جديداً
            $stmt_name = $conn->prepare("SELECT full_name FROM employees WHERE id = ?");
            $stmt_name->execute([$employee_id]);
            $emp_name = $stmt_name->fetchColumn() ?: ('موظف #' . $employee_id);

            $original_entry_num = "JE-ADV-" . $advance_id;
            $stmt_active = $conn->prepare(
                "SELECT entry_number FROM journal_entries
                 WHERE entry_number = ? OR entry_number LIKE ?
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt_active->execute([$original_entry_num, $original_entry_num . "-CORR-%"]);
            $active_entry_num = $stmt_active->fetchColumn() ?: $original_entry_num;

            $stmt_je = $conn->prepare("SELECT id, account_id, debit, credit FROM journal_entries WHERE entry_number = ?");
            $stmt_je->execute([$active_entry_num]);
            $je_lines = $stmt_je->fetchAll(PDO::FETCH_ASSOC);

            if (count($je_lines) > 0) {
                $rev_entry_num = $original_entry_num . "-REV-" . time();
                $new_entry_num = $original_entry_num . "-CORR-" . time();
                $today = date('Y-m-d');
                $rev_desc = "عكس تلقائي لقيد سلفة معدَّلة (عكس القيد: $active_entry_num) للموظف: $emp_name";
                $new_desc = "صرف سلفة مقسطة معدَّلة للموظف: $emp_name";

                foreach ($je_lines as $line) {
                    $conn->prepare("INSERT INTO journal_entries (account_id, entry_date, description, debit, credit, entry_number, source_module) VALUES (?, ?, ?, ?, ?, ?, 'Employee Advance Reversal')")
                         ->execute([$line['account_id'], $today, $rev_desc, floatval($line['credit']), floatval($line['debit']), $rev_entry_num]);

                    $is_debit_line = floatval($line['debit']) > 0;
                    $conn->prepare("INSERT INTO journal_entries (account_id, entry_date, description, debit, credit, entry_number, source_module) VALUES (?, ?, ?, ?, ?, ?, 'Employee Advance')")
                         ->execute([$line['account_id'], $today, $new_desc, $is_debit_line ? $total_amount : 0, $is_debit_line ? 0 : $total_amount, $new_entry_num]);
                }
            }

            $conn->commit();
            $msg = "تم تحديث السلفة وإعادة جدولة الأقساط، مع ترحيل قيد عكس وقيد تصحيحي، بنجاح!";
            logAudit($conn, 'UPDATE', 'سلف الموظفين', "تعديل سلفة رقم #$advance_id للموظف: $emp_name إلى " . number_format($total_amount, 2) . " ل.س على $installments_count قسط — تم ترحيل قيد عكس + قيد تصحيحي", $advance_id);
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ: " . $e->getMessage();
        }
    }
}

// 3. إضافة جزاء أو مكافأة شهرية متغيرة
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_variable_item'])) {
    requireRole($conn, ['admin', 'accountant']);
    $employee_id = intval($_POST['employee_id']);
    $item_type = $_POST['item_type'];
    $amount = floatval($_POST['amount']);
    $target_month = $_POST['target_month'];
    $notes = trim($_POST['notes']);

    if ($employee_id > 0 && $amount > 0 && isDateInClosedPeriod($conn, $target_month . '-01')) {
        $error = getPeriodLockErrorMessage($target_month);
    } elseif ($employee_id > 0 && $amount > 0) {
        try {
            $stmt = $conn->prepare("INSERT INTO employee_variable_items (employee_id, item_type, amount, target_month, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$employee_id, $item_type, $amount, $target_month, $notes]);
            $item_id = $conn->lastInsertId();
            $msg = "تم تسجيل البند المتغير بنجاح!";

            $stmt_name = $conn->prepare("SELECT full_name FROM employees WHERE id = ?");
            $stmt_name->execute([$employee_id]);
            $emp_name = $stmt_name->fetchColumn() ?: ('موظف #' . $employee_id);
            $type_label = $item_type === 'bonus' ? 'مكافأة' : 'جزاء';
            logAudit($conn, 'INSERT', 'الجزاءات والمكافآت', "تسجيل $type_label للموظف: $emp_name بقيمة " . number_format($amount, 2) . " ل.س عن شهر $target_month", $item_id);
        } catch (Exception $e) { $error = "خطأ: " . $e->getMessage(); }
    } else { $error = "أدخل بيانات صحيحة."; }
}

// 4. معالجة وتوليد مسير الرواتب الشهري (Payroll Engine)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_payroll'])) {
    requireRole($conn, ['admin', 'accountant']);
    $target_month = $_POST['target_month'];
    $salary_type = ($_POST['salary_type'] ?? 'شهري') === 'أسبوعي' ? 'أسبوعي' : 'شهري';

    // عمود نوع الراتب (شهري/أسبوعي) لكل سطر — يُحدَّد لكل مسير/موظف على حدة، ويُستخدَم لحساب الراتب
    // الأساسي الفعلي المستحق: الراتب الأسبوعي = الراتب الشهري المسجَّل للموظف ÷ 4 تقريباً.
    try {
        $pd_cols_chk = $conn->query("SHOW COLUMNS FROM payroll_details")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('salary_type', $pd_cols_chk)) {
            $conn->exec("ALTER TABLE payroll_details ADD COLUMN salary_type ENUM('شهري','أسبوعي') NOT NULL DEFAULT 'شهري'");
        }
    } catch (Exception $e) { /* يُتجاهل إن تعذّر */ }

    if (isDateInClosedPeriod($conn, date('Y-m-d'))) {
        $error = getPeriodLockErrorMessage(date('Y-m-d'));
    } else {
    try {
        $conn->beginTransaction();
        $check_run = $conn->prepare("SELECT id FROM payroll_runs WHERE salary_month = ?");
        $check_run->execute([$target_month]);
        if ($check_run->fetch()) { throw new Exception("مسير رواتب هذا الشهر ($target_month) معالج مسبقاً."); }

        $employees = $conn->query("SELECT * FROM employees WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        if (count($employees) == 0) { throw new Exception("لا توجد موظفين نشطين حالياً."); }

        $total_run_amount = 0;
        $total_base_salaries = 0;
        $total_bonuses = 0;
        $total_penalties = 0;
        $total_advances_recovered = 0;

        $stmt_run = $conn->prepare("INSERT INTO payroll_runs (salary_month, total_payroll_amount) VALUES (?, 0)");
        $stmt_run->execute([$target_month]);
        $payroll_run_id = $conn->lastInsertId();

        foreach ($employees as $emp) {
            $emp_id = $emp['id'];
            $base_salary = $emp['base_salary'];
            if ($salary_type === 'أسبوعي') { $base_salary = round($base_salary / 4, 2); }

            // أ. الأقساط
            $inst_stmt = $conn->prepare("SELECT SUM(amount) as total_inst, GROUP_CONCAT(id) as inst_ids FROM advance_installments WHERE employee_id = ? AND installment_month = ? AND is_paid = 0");
            $inst_stmt->execute([$emp_id, $target_month]);
            $inst_res = $inst_stmt->fetch(PDO::FETCH_ASSOC);
            $deducted_advances = floatval($inst_res['total_inst'] ?? 0);
            $inst_ids = $inst_res['inst_ids'] ?? '';

            // ب. الجزاءات
            $pen_stmt = $conn->prepare("SELECT SUM(amount) as total_pen FROM employee_variable_items WHERE employee_id = ? AND target_month = ? AND item_type = 'penalty'");
            $pen_stmt->execute([$emp_id, $target_month]);
            $penalties = floatval($pen_stmt->fetch(PDO::FETCH_ASSOC)['total_pen'] ?? 0);

            // ج. المكافآت
            $bon_stmt = $conn->prepare("SELECT SUM(amount) as total_bon FROM employee_variable_items WHERE employee_id = ? AND target_month = ? AND item_type = 'bonus'");
            $bon_stmt->execute([$emp_id, $target_month]);
            $bonuses = floatval($bon_stmt->fetch(PDO::FETCH_ASSOC)['total_bon'] ?? 0);

            $net_salary = $base_salary - $deducted_advances - $penalties + $bonuses;
            if ($net_salary < 0) $net_salary = 0;
            $total_run_amount += $net_salary;

            // تجميع كل بند على حدة عبر كل الموظفين — ليُرحَّل كل بند بقيد مستقل بدل رقم واحد مُدمَج
            $total_base_salaries += $base_salary;
            $total_bonuses += $bonuses;
            $total_penalties += $penalties;
            $total_advances_recovered += $deducted_advances;

            $stmt_det = $conn->prepare("INSERT INTO payroll_details (payroll_run_id, employee_id, base_salary, deducted_advances, penalties, bonuses, net_salary, salary_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_det->execute([$payroll_run_id, $emp_id, $base_salary, $deducted_advances, $penalties, $bonuses, $net_salary, $salary_type]);

            if (!empty($inst_ids)) {
                $conn->query("UPDATE advance_installments SET is_paid = 1 WHERE id IN ($inst_ids)");
            }
        }

        $conn->prepare("UPDATE payroll_runs SET total_payroll_amount = ? WHERE id = ?")->execute([$total_run_amount, $payroll_run_id]);

        // === إصلاح جوهري: قيد محاسبي مُفصَّل بدل رقم "صافي" واحد مُدمَج ===
        // سابقاً كان يُرحَّل فقط: مدين "الرواتب والأجور" / دائن "الصندوق" بصافي المبلغ بعد الخصومات —
        // فتختفي الجزاءات والحوافز والسلف المُستردَّة داخل رقم واحد، بلا أي أثر مستقل في دفتر الأستاذ
        // ولا في قائمة الدخل الرسمية. الآن يُرحَّل كل بند بسطر مستقل، ويبقى القيد متوازناً رياضياً لأن:
        // مجموع المدين (الرواتب الأساسية + الحوافز) = مجموع الدائن (سلف مُستردَّة + جزاءات + نقد مدفوع فعلياً)
        $entry_num = "JE-PAYROLL-" . $payroll_run_id;
        $desc = "إثبات مسير الرواتب والأجور لشهر: $target_month";
        $today = date('Y-m-d');

        $salaries_expense_id = findOrCreateAccount($conn, ['رواتب', 'أجور', 'salaries'], 'الرواتب والأجور', 'Expense');
        $cash_id             = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي', 'Asset');
        $bonus_expense_id    = findOrCreateAccount($conn, ['مصروف حوافز', 'مكافآت الموظفين'], 'مصروف حوافز ومكافآت الموظفين', 'Expense');
        $penalty_income_id   = findOrCreateAccount($conn, ['جزاءات وخصومات', 'جزاءات الموظفين'], 'جزاءات وخصومات الموظفين', 'Revenue');
        $advances_asset_id   = findOrCreateAccount($conn, ['سلف', 'موظف'], 'سلف الموظفين', 'Asset');

        if ($salaries_expense_id && $cash_id) {
            // مدين: الرواتب الأساسية (إجمالي المصروف قبل أي خصم/إضافة)
            if ($total_base_salaries > 0) {
                insertJournalLine($conn, $salaries_expense_id, $total_base_salaries, 0, $entry_num, $today, $desc, 'Payroll');
            }
            // مدين: مصروف الحوافز والمكافآت (بند منفصل يظهر بوضوح في قائمة الدخل)
            if ($total_bonuses > 0 && $bonus_expense_id) {
                insertJournalLine($conn, $bonus_expense_id, $total_bonuses, 0, $entry_num, $today, $desc, 'Payroll');
            }
            // دائن: سلف الموظفين (الأصل يتناقص لأن القسط اقتُطِع واستُرِدَّ فعلياً هذا الشهر)
            if ($total_advances_recovered > 0 && $advances_asset_id) {
                insertJournalLine($conn, $advances_asset_id, 0, $total_advances_recovered, $entry_num, $today, $desc, 'Payroll');
            }
            // دائن: جزاءات وخصومات الموظفين (تُخفِّض صافي تكلفة الرواتب — بند منفصل وواضح)
            if ($total_penalties > 0 && $penalty_income_id) {
                insertJournalLine($conn, $penalty_income_id, 0, $total_penalties, $entry_num, $today, $desc, 'Payroll');
            }
            // دائن: الصندوق (المبلغ الصافي الفعلي المدفوع نقداً للموظفين)
            insertJournalLine($conn, $cash_id, 0, $total_run_amount, $entry_num, $today, $desc, 'Payroll');
        }

        $conn->commit();
        $msg = "تم توليد مسير الرواتب الاحترافي لشهر ($target_month) وترحيل القيود المُفصَّلة (رواتب، حوافز، جزاءات، سلف) بنجاح!";
        logAudit($conn, 'INSERT', 'مسيرات الرواتب', "توليد مسير رواتب لشهر $target_month — رواتب أساسية: " . number_format($total_base_salaries, 2) . "، حوافز: " . number_format($total_bonuses, 2) . "، جزاءات: " . number_format($total_penalties, 2) . "، صافي مدفوع: " . number_format($total_run_amount, 2) . " ل.س لعدد " . count($employees) . " موظف", $payroll_run_id);
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "خطأ في المعالجة: " . $e->getMessage();
    }
    }
}

// دالة مساعدة مشتركة: تُعيد حساب مجاميع مسير رواتب كامل من كل سطوره الحالية في payroll_details،
// وتُعيد ترحيل القيد المحاسبي المُجمَّع للمسير بالكامل من الصفر (عكس القديم بحذفه، ثم إعادة الترحيل).
// تُستخدَم عند إضافة موظف واحد لمسير قائم، أو تعديل سطر موظف واحد فيه — بدل حساب جزئي غير دقيق.
function recomputeAndPostPayrollJournal($conn, $payroll_run_id, $target_month) {
    $stmt_sum = $conn->prepare("SELECT COALESCE(SUM(base_salary),0) AS s_base, COALESCE(SUM(bonuses),0) AS s_bonus, COALESCE(SUM(penalties),0) AS s_pen, COALESCE(SUM(deducted_advances),0) AS s_adv, COALESCE(SUM(net_salary),0) AS s_net FROM payroll_details WHERE payroll_run_id = ?");
    $stmt_sum->execute([$payroll_run_id]);
    $sums = $stmt_sum->fetch(PDO::FETCH_ASSOC);

    $conn->prepare("UPDATE payroll_runs SET total_payroll_amount = ? WHERE id = ?")->execute([$sums['s_net'], $payroll_run_id]);

    $entry_num = "JE-PAYROLL-" . $payroll_run_id;
    $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ?")->execute([$entry_num]);

    $desc = "إثبات مسير الرواتب والأجور لشهر: $target_month";
    $today = date('Y-m-d');
    $salaries_expense_id = findOrCreateAccount($conn, ['رواتب', 'أجور', 'salaries'], 'الرواتب والأجور', 'Expense');
    $cash_id              = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي', 'Asset');
    $bonus_expense_id     = findOrCreateAccount($conn, ['مصروف حوافز', 'مكافآت الموظفين'], 'مصروف حوافز ومكافآت الموظفين', 'Expense');
    $penalty_income_id    = findOrCreateAccount($conn, ['جزاءات وخصومات', 'جزاءات الموظفين'], 'جزاءات وخصومات الموظفين', 'Revenue');
    $advances_asset_id    = findOrCreateAccount($conn, ['سلف', 'موظف'], 'سلف الموظفين', 'Asset');

    if ($salaries_expense_id && $cash_id) {
        if ($sums['s_base'] > 0) { insertJournalLine($conn, $salaries_expense_id, $sums['s_base'], 0, $entry_num, $today, $desc, 'Payroll'); }
        if ($sums['s_bonus'] > 0 && $bonus_expense_id) { insertJournalLine($conn, $bonus_expense_id, $sums['s_bonus'], 0, $entry_num, $today, $desc, 'Payroll'); }
        if ($sums['s_adv'] > 0 && $advances_asset_id) { insertJournalLine($conn, $advances_asset_id, 0, $sums['s_adv'], $entry_num, $today, $desc, 'Payroll'); }
        if ($sums['s_pen'] > 0 && $penalty_income_id) { insertJournalLine($conn, $penalty_income_id, 0, $sums['s_pen'], $entry_num, $today, $desc, 'Payroll'); }
        if ($sums['s_net'] > 0) { insertJournalLine($conn, $cash_id, 0, $sums['s_net'], $entry_num, $today, $desc, 'Payroll'); }
    }
}

// معالجة صرف راتب لموظف واحد فقط — تُنشئ مسير الشهر إن لم يكن موجوداً، أو تضيف هذا الموظف لمسير
// قائم إن لم يكن قد صُرِف له فيه مسبقاً. تُعيد حساب القيد المُجمَّع لكل المسير من جديد بعد الإضافة.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_single_payroll'])) {
    requireRole($conn, ['admin', 'accountant']);
    $target_month = $_POST['single_target_month'];
    $emp_id = intval($_POST['single_employee_id']);
    $single_salary_type = ($_POST['single_salary_type'] ?? 'شهري') === 'أسبوعي' ? 'أسبوعي' : 'شهري';
    $week_start = $_POST['single_week_start'] ?? '';
    $week_end = $_POST['single_week_end'] ?? '';

    try {
        $pd_cols_chk2 = $conn->query("SHOW COLUMNS FROM payroll_details")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('salary_type', $pd_cols_chk2)) {
            $conn->exec("ALTER TABLE payroll_details ADD COLUMN salary_type ENUM('شهري','أسبوعي') NOT NULL DEFAULT 'شهري'");
        }
        if (!in_array('week_start_date', $pd_cols_chk2)) {
            $conn->exec("ALTER TABLE payroll_details ADD COLUMN week_start_date DATE NULL");
        }
        if (!in_array('week_end_date', $pd_cols_chk2)) {
            $conn->exec("ALTER TABLE payroll_details ADD COLUMN week_end_date DATE NULL");
        }
    } catch (Exception $e) { /* يُتجاهل إن تعذّر */ }

    if (isDateInClosedPeriod($conn, date('Y-m-d'))) {
        $error = getPeriodLockErrorMessage(date('Y-m-d'));
    } else {
        try {
            $conn->beginTransaction();

            $stmt_run = $conn->prepare("SELECT id FROM payroll_runs WHERE salary_month = ?");
            $stmt_run->execute([$target_month]);
            $payroll_run_id = $stmt_run->fetchColumn();
            if (!$payroll_run_id) {
                $conn->prepare("INSERT INTO payroll_runs (salary_month, total_payroll_amount) VALUES (?, 0)")->execute([$target_month]);
                $payroll_run_id = $conn->lastInsertId();
            }

            $stmt_exists = $conn->prepare("SELECT id FROM payroll_details WHERE payroll_run_id = ? AND employee_id = ?");
            $stmt_exists->execute([$payroll_run_id, $emp_id]);
            if ($stmt_exists->fetch()) { throw new Exception("راتب هذا الموظف لشهر $target_month مصروف مسبقاً — استخدم زر التعديل بدلاً من الصرف مرة أخرى."); }

            $stmt_emp = $conn->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt_emp->execute([$emp_id]);
            $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);
            if (!$emp) { throw new Exception("الموظف غير موجود."); }
            $base_salary = $emp['base_salary'];

            // === حساب دقيق للراتب الأسبوعي: السعر اليومي (الأساسي ÷ 30) × عدد الأيام الفعلية في
            // الفترة المحددة (من/إلى) — وليس قسمة تقريبية ثابتة على 4. يدعم أي طول أسبوع فعلي (6، 7، ...).
            $days_in_period = null;
            if ($single_salary_type === 'أسبوعي') {
                if (!empty($week_start) && !empty($week_end)) {
                    $d1 = new DateTime($week_start);
                    $d2 = new DateTime($week_end);
                    if ($d2 < $d1) { throw new Exception("تاريخ نهاية الأسبوع يجب أن يكون بعد تاريخ البداية."); }
                    $days_in_period = $d1->diff($d2)->days + 1; // شامل يومَي البداية والنهاية معاً
                    $daily_rate = $base_salary / 30;
                    $base_salary = round($daily_rate * $days_in_period, 2);
                } else {
                    // لا تواريخ محددة: تراجع للقسمة التقريبية القديمة على 4 كحل احتياطي فقط
                    $base_salary = round($base_salary / 4, 2);
                }
            } else {
                $week_start = null; $week_end = null;
            }

            $inst_stmt = $conn->prepare("SELECT SUM(amount) as total_inst, GROUP_CONCAT(id) as inst_ids FROM advance_installments WHERE employee_id = ? AND installment_month = ? AND is_paid = 0");
            $inst_stmt->execute([$emp_id, $target_month]);
            $inst_res = $inst_stmt->fetch(PDO::FETCH_ASSOC);
            $deducted_advances = floatval($inst_res['total_inst'] ?? 0);
            $inst_ids = $inst_res['inst_ids'] ?? '';

            $pen_stmt = $conn->prepare("SELECT SUM(amount) as total_pen FROM employee_variable_items WHERE employee_id = ? AND target_month = ? AND item_type = 'penalty'");
            $pen_stmt->execute([$emp_id, $target_month]);
            $penalties = floatval($pen_stmt->fetch(PDO::FETCH_ASSOC)['total_pen'] ?? 0);

            $bon_stmt = $conn->prepare("SELECT SUM(amount) as total_bon FROM employee_variable_items WHERE employee_id = ? AND target_month = ? AND item_type = 'bonus'");
            $bon_stmt->execute([$emp_id, $target_month]);
            $bonuses = floatval($bon_stmt->fetch(PDO::FETCH_ASSOC)['total_bon'] ?? 0);

            $net_salary = $base_salary - $deducted_advances - $penalties + $bonuses;
            if ($net_salary < 0) { $net_salary = 0; }

            $conn->prepare("INSERT INTO payroll_details (payroll_run_id, employee_id, base_salary, deducted_advances, penalties, bonuses, net_salary, salary_type, week_start_date, week_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                 ->execute([$payroll_run_id, $emp_id, $base_salary, $deducted_advances, $penalties, $bonuses, $net_salary, $single_salary_type, $week_start ?: null, $week_end ?: null]);

            if (!empty($inst_ids)) {
                $conn->query("UPDATE advance_installments SET is_paid = 1 WHERE id IN ($inst_ids)");
            }

            recomputeAndPostPayrollJournal($conn, $payroll_run_id, $target_month);

            $conn->commit();
            $period_note = ($single_salary_type === 'أسبوعي' && $days_in_period) ? " (عن $days_in_period يوم: من $week_start إلى $week_end)" : " ($single_salary_type)";
            $msg = "تم صرف راتب الموظف (" . htmlspecialchars($emp['full_name']) . ") لشهر $target_month{$period_note} بصافي " . number_format($net_salary, 2) . " ل.س، وتحديث القيد المحاسبي للمسير بالكامل.";
            logAudit($conn, 'INSERT', 'مسيرات الرواتب', "صرف راتب فردي ($single_salary_type) للموظف #$emp_id لشهر $target_month بصافي " . number_format($net_salary, 2) . " ل.س", $payroll_run_id);
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ: " . $e->getMessage();
        }
    }
}

// معالجة تعديل راتب موظف واحد مصروف بالفعل ضمن مسير — تُعيد حساب صافي راتبه والقيد المُجمَّع للمسير بالكامل
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_payroll_detail'])) {
    requireRole($conn, ['admin', 'accountant']);
    $detail_id = intval($_POST['detail_id']);
    try {
        $pd_cols_chk3 = $conn->query("SHOW COLUMNS FROM payroll_details")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('salary_type', $pd_cols_chk3)) {
            $conn->exec("ALTER TABLE payroll_details ADD COLUMN salary_type ENUM('شهري','أسبوعي') NOT NULL DEFAULT 'شهري'");
        }
    } catch (Exception $e) { /* يُتجاهل إن تعذّر */ }
    $new_base = floatval($_POST['edit_base_salary']);
    $new_adv = floatval($_POST['edit_deducted_advances']);
    $new_pen = floatval($_POST['edit_penalties']);
    $new_bonus = floatval($_POST['edit_bonuses']);
    $new_salary_type = ($_POST['edit_salary_type'] ?? 'شهري') === 'أسبوعي' ? 'أسبوعي' : 'شهري';

    $stmt_det = $conn->prepare("SELECT pd.*, pr.salary_month FROM payroll_details pd JOIN payroll_runs pr ON pd.payroll_run_id = pr.id WHERE pd.id = ?");
    $stmt_det->execute([$detail_id]);
    $detail = $stmt_det->fetch(PDO::FETCH_ASSOC);

    if (!$detail) {
        $error = "سجل الراتب غير موجود.";
    } elseif (isDateInClosedPeriod($conn, date('Y-m-d'))) {
        $error = getPeriodLockErrorMessage(date('Y-m-d'));
    } else {
        try {
            $conn->beginTransaction();
            $new_net = $new_base - $new_adv - $new_pen + $new_bonus;
            if ($new_net < 0) { $new_net = 0; }

            $conn->prepare("UPDATE payroll_details SET base_salary = ?, deducted_advances = ?, penalties = ?, bonuses = ?, net_salary = ?, salary_type = ? WHERE id = ?")
                 ->execute([$new_base, $new_adv, $new_pen, $new_bonus, $new_net, $new_salary_type, $detail_id]);

            recomputeAndPostPayrollJournal($conn, $detail['payroll_run_id'], $detail['salary_month']);

            $conn->commit();
            $msg = "تم تحديث راتب الموظف والقيد المحاسبي المُجمَّع للمسير بالكامل بنجاح!";
            logAudit($conn, 'UPDATE', 'مسيرات الرواتب', "تعديل راتب فردي #$detail_id ضمن مسير شهر " . $detail['salary_month'] . " — صافي جديد: " . number_format($new_net, 2) . " ل.س", $detail_id);
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ: " . $e->getMessage();
        }
    }
}

$employees_list = $conn->query("SELECT * FROM employees ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$selected_filter_month = $_GET['filter_month'] ?? date('Y-m');

$current_payroll_run = $conn->prepare("SELECT * FROM payroll_runs WHERE salary_month = ?");
$current_payroll_run->execute([$selected_filter_month]);
$active_run_data = $current_payroll_run->fetch(PDO::FETCH_ASSOC);

$payroll_details_list = [];
if ($active_run_data) {
    $det_stmt = $conn->prepare("SELECT pd.*, e.full_name as emp_name, e.position, e.department FROM payroll_details pd JOIN employees e ON pd.employee_id = e.id WHERE pd.payroll_run_id = ?");
    $det_stmt->execute([$active_run_data['id']]);
    $payroll_details_list = $det_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// فلتر شهر لتبويب السلف: يعرض السلف التي يوجد لها قسط (مسدَّد أو غير مسدَّد) ضمن الشهر المختار
$adv_filter_month = $_GET['adv_filter_month'] ?? '';
if (!empty($adv_filter_month)) {
    $advances_view_stmt = $conn->prepare("SELECT a.*, e.full_name as emp_name FROM employee_advances a 
        JOIN employees e ON a.employee_id = e.id 
        WHERE EXISTS (SELECT 1 FROM advance_installments ai WHERE ai.advance_id = a.id AND ai.installment_month = ?)
        ORDER BY a.id DESC");
    $advances_view_stmt->execute([$adv_filter_month]);
    $advances_view = $advances_view_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $advances_view = $conn->query("SELECT a.*, e.full_name as emp_name FROM employee_advances a JOIN employees e ON a.employee_id = e.id ORDER BY a.id DESC")->fetchAll(PDO::FETCH_ASSOC);
}

// حساب عدد الأقساط المسددة لكل سلفة لتحديد إمكانية عرض زر التعديل
$paid_counts = [];
if (count($advances_view) > 0) {
    $ids = array_column($advances_view, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt_paid_counts = $conn->prepare("SELECT advance_id, SUM(is_paid) as paid_count FROM advance_installments WHERE advance_id IN ($placeholders) GROUP BY advance_id");
    $stmt_paid_counts->execute($ids);
    foreach ($stmt_paid_counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $paid_counts[$row['advance_id']] = intval($row['paid_count']);
    }
}

// فلتر شهر لتبويب الجزاءات والحوافز
$var_filter_month = $_GET['var_filter_month'] ?? '';
if (!empty($var_filter_month)) {
    $variable_items_stmt = $conn->prepare("SELECT v.*, e.full_name as emp_name FROM employee_variable_items v JOIN employees e ON v.employee_id = e.id WHERE v.target_month = ? ORDER BY v.id DESC");
    $variable_items_stmt->execute([$var_filter_month]);
} else {
    $variable_items_stmt = $conn->query("SELECT v.*, e.full_name as emp_name FROM employee_variable_items v JOIN employees e ON v.employee_id = e.id ORDER BY v.id DESC");
}
$variable_items_view = $variable_items_stmt->fetchAll(PDO::FETCH_ASSOC);
$total_bonuses_shown = array_sum(array_map(fn($v) => $v['item_type'] === 'bonus' ? floatval($v['amount']) : 0, $variable_items_view));
$total_penalties_shown = array_sum(array_map(fn($v) => $v['item_type'] === 'penalty' ? floatval($v['amount']) : 0, $variable_items_view));
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2>نظام إدارة الموارد البشرية والرواتب (Enterprise HR & Payroll)</h2>
        <p style="color: #666; margin: 0;">إدارة بيانات الموظفين الشاملة، ملفات الهوية والمصارف، السلف المقسطة، ومسيرات الرواتب المحاسبية.</p>
    </div>
    <div>
        <button onclick="openModal('empModal')" style="background: #1cc88a; color: white; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold;"><i class="fas fa-user-plus"></i> ملف موظف جديد</button>
        <button onclick="openModal('advanceModal')" style="background: #f6c23e; color: white; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-right: 5px;"><i class="fas fa-hand-holding-usd"></i> سلفة مقسطة</button>
        <button onclick="openModal('varModal')" style="background: #36b9cc; color: white; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-right: 5px;"><i class="fas fa-plus-circle"></i> جزاء / مكافأة</button>
        <button onclick="openModal('payrollModal')" style="background: #4e73df; color: white; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-right: 5px;"><i class="fas fa-calculator"></i> توليد مسير راتب</button>
        <button onclick="openModal('singlePayrollModal')" style="background: #1cc88a; color: white; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-right: 5px;"><i class="fas fa-user-check"></i> صرف راتب موظف واحد</button>
    </div>
</div>

<?php if ($msg): ?><div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $msg; ?></div><?php endif; ?>
<?php if ($error): ?><div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $error; ?></div><?php endif; ?>

<!-- التبويبات -->
<div style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e3e6f0; padding-bottom: 10px;">
    <button onclick="switchTab('payrollReportTab')" id="btnReportTab" style="background: #4e73df; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: bold;">مسيرات الرواتب والتقارير</button>
    <button onclick="switchTab('employeesTab')" id="btnEmpTab" style="background: #e3e6f0; color: #333; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: bold;">سجل الموظفين الشامل</button>
    <button onclick="switchTab('advancesTab')" id="btnAdvTab" style="background: #e3e6f0; color: #333; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: bold;">السلف والأقساط النشطة</button>
    <button onclick="switchTab('variableTab')" id="btnVarTab" style="background: #e3e6f0; color: #333; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: bold;">الجزاءات والحوافز</button>
</div>

<!-- 1. تبويب مسيرات الرواتب -->
<div id="payrollReportTab" class="tab-content" style="display: block;">
    <div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; padding: 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
        <form method="GET" style="display: flex; align-items: center; gap: 10px;">
            <label style="font-weight: bold; color: #4e73df;">فلتر شهر الاستحقاق:</label>
            <input type="month" name="filter_month" value="<?php echo htmlspecialchars($selected_filter_month); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 7px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">استعراض</button>
        </form>
        <?php if ($active_run_data): ?>
            <div style="font-size: 14px; color: #1cc88a; font-weight: bold;">
                إجمالي مسير الرواتب: <?php echo number_format($active_run_data['total_payroll_amount'], 2); ?> ل.س
            </div>
        <?php endif; ?>
    </div>

    <div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #4e73df; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">الموظف</th>
                    <th style="padding: 12px 15px;">القسم / المسمى</th>
                    <th style="padding: 12px 15px;">نوع الراتب</th>
                    <th style="padding: 12px 15px;">الأساسي</th>
                    <th style="padding: 12px 15px;">خصم الأقساط</th>
                    <th style="padding: 12px 15px;">الجزاءات</th>
                    <th style="padding: 12px 15px;">المكافآت</th>
                    <th style="padding: 12px 15px;">الصافي المستحق</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($payroll_details_list) > 0): foreach ($payroll_details_list as $det): ?>
                    <tr style="border-bottom: 1px solid #f1f1f1;">
                        <td style="padding: 12px 15px; font-weight: bold; color: #333;"><?php echo htmlspecialchars($det['emp_name']); ?></td>
                        <td style="padding: 12px 15px; color: #666;"><?php echo htmlspecialchars($det['department'] . ' - ' . $det['position']); ?></td>
                        <td style="padding: 12px 15px;">
                            <span class="status-badge" style="background: <?php echo ($det['salary_type'] ?? 'شهري') === 'أسبوعي' ? '#eaf1fc' : '#eafaf1'; ?>; color: <?php echo ($det['salary_type'] ?? 'شهري') === 'أسبوعي' ? '#2c4e9c' : '#1a8f5f'; ?>;">
                                <?php echo htmlspecialchars($det['salary_type'] ?? 'شهري'); ?>
                            </span>
                        </td>
                        <td style="padding: 12px 15px; font-family: monospace;"><?php echo number_format($det['base_salary'], 2); ?></td>
                        <td style="padding: 12px 15px; font-family: monospace; color: #e74a3b;"><?php echo number_format($det['deducted_advances'], 2); ?></td>
                        <td style="padding: 12px 15px; font-family: monospace; color: #e74a3b;"><?php echo number_format($det['penalties'], 2); ?></td>
                        <td style="padding: 12px 15px; font-family: monospace; color: #1cc88a;"><?php echo number_format($det['bonuses'], 2); ?></td>
                        <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #2e59d9;"><?php echo number_format($det['net_salary'], 2); ?> ل.س</td>
                        <td style="padding: 12px 15px; text-align: center;">
                            <button onclick='openEditPayrollDetailModal(<?php echo json_encode($det, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #f6c23e; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">
                                <i class="fas fa-edit"></i> تعديل
                            </button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" style="padding: 30px; text-align: center; color: #777;">لا يوجد مسير رواتب مسجل لهذا الشهر (<?php echo htmlspecialchars($selected_filter_month); ?>).</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 2. تبويب السجل الشامل للموظفين (الملفات الاحترافية) -->
<div id="employeesTab" class="tab-content" style="display: none;">
    <div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #1cc88a; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">الكود والهوية</th>
                    <th style="padding: 12px 15px;">الاسم الكامل</th>
                    <th style="padding: 12px 15px;">القسم والمسمى</th>
                    <th style="padding: 12px 15px;">التعاقد</th>
                    <th style="padding: 12px 15px;">الراتب الأساسي</th>
                    <th style="padding: 12px 15px;">طريقة الدفع والبنك</th>
                    <th style="padding: 12px 15px;">الحالة</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees_list as $emp): ?>
                    <tr style="border-bottom: 1px solid #f1f1f1;">
                        <td style="padding: 12px 15px; font-family: monospace;">
                            <b><?php echo htmlspecialchars($emp['employee_code']); ?></b><br>
                            <span style="color: #888; font-size: 11px;"><?php echo htmlspecialchars($emp['national_id']); ?></span>
                        </td>
                        <td style="padding: 12px 15px; font-weight: bold; color: #333;"><?php echo htmlspecialchars($emp['full_name']); ?><br><span style="color: #666; font-weight: normal; font-size: 11px;"><?php echo htmlspecialchars($emp['phone']); ?></span></td>
                        <td style="padding: 12px 15px;"><?php echo htmlspecialchars($emp['department']); ?><br><span style="color: #666; font-size: 11px;"><?php echo htmlspecialchars($emp['position']); ?></span></td>
                        <td style="padding: 12px 15px;"><?php echo htmlspecialchars($emp['employment_type']); ?><br><span style="color: #666; font-size: 11px;">منذ: <?php echo $emp['hire_date']; ?></span></td>
                        <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #2e59d9;"><?php echo number_format($emp['base_salary'], 2); ?> ل.س</td>
                        <td style="padding: 12px 15px;"><?php echo htmlspecialchars($emp['payment_method']); ?><br><span style="color: #666; font-size: 11px;"><?php echo htmlspecialchars($emp['bank_name'] ?: '-'); ?></span></td>
                        <td style="padding: 12px 15px;">
                            <span style="padding: 3px 8px; border-radius: 4px; font-size: 11px; background: <?php echo $emp['status'] == 'active' ? '#d4edda; color: #155724;' : '#f8d7da; color: #721c24;'; ?>">
                                <?php echo htmlspecialchars($emp['status']); ?>
                            </span>
                        </td>
                        <td style="padding: 12px 15px; text-align: center;">
                            <button onclick='openEditEmployeeModal(<?php echo json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #f6c23e; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;">
                                <i class="fas fa-edit"></i> تعديل
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 3. تبويب السلف النشطة -->
<div id="advancesTab" class="tab-content" style="display: none;">
    <div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <form method="GET" style="display: flex; align-items: center; gap: 10px;">
            <input type="hidden" name="filter_month" value="<?php echo htmlspecialchars($selected_filter_month); ?>">
            <label style="font-weight: bold; color: #f6c23e;">فلتر شهر القسط:</label>
            <input type="month" name="adv_filter_month" value="<?php echo htmlspecialchars($adv_filter_month); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 7px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">بحث</button>
            <?php if (!empty($adv_filter_month)): ?>
                <a href="employees.php" style="color: #666; font-size: 13px; text-decoration: none;">إلغاء الفلتر</a>
            <?php endif; ?>
        </form>
    </div>
    <div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #f6c23e; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">الموظف</th>
                    <th style="padding: 12px 15px;">الإجمالي</th>
                    <th style="padding: 12px 15px;">الأقساط</th>
                    <th style="padding: 12px 15px;">قسط الشهر</th>
                    <th style="padding: 12px 15px;">البداية</th>
                    <th style="padding: 12px 15px;">ملاحظات</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($advances_view) > 0): foreach ($advances_view as $adv): 
                    $has_paid = ($paid_counts[$adv['id']] ?? 0) > 0;
                ?>
                    <tr style="border-bottom: 1px solid #f1f1f1;">
                        <td style="padding: 12px 15px; font-weight: bold;"><?php echo htmlspecialchars($adv['emp_name']); ?></td>
                        <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #e74a3b;"><?php echo number_format($adv['total_amount'], 2); ?></td>
                        <td style="padding: 12px 15px; font-family: monospace;"><?php echo $adv['installments_count']; ?> أشهر</td>
                        <td style="padding: 12px 15px; font-family: monospace; color: #2e59d9;"><?php echo number_format($adv['monthly_installment'], 2); ?></td>
                        <td style="padding: 12px 15px; font-family: monospace; color: #666;"><?php echo $adv['start_month']; ?></td>
                        <td style="padding: 12px 15px; color: #777;"><?php echo htmlspecialchars($adv['notes'] ?: '-'); ?></td>
                        <td style="padding: 12px 15px; text-align: center;">
                            <?php if ($has_paid): ?>
                                <span title="لا يمكن التعديل بعد بدء استقطاع الأقساط" style="color: #aaa; font-size: 11px;">مغلقة (قيد السداد)</span>
                            <?php else: ?>
                                <button onclick='openEditAdvanceModal(<?php echo json_encode($adv, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #f6c23e; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;">
                                    <i class="fas fa-edit"></i> تعديل
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" style="padding: 20px; text-align: center; color: #777;">لا توجد سلف مقسطة مطابقة.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 4. تبويب الجزاءات والحوافز -->
<div id="variableTab" class="tab-content" style="display: none;">
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
        <div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px;">
            <div style="color: #1cc88a; font-weight: bold; font-size: 13px;">إجمالي الحوافز المعروضة</div>
            <div style="font-size: 20px; font-weight: bold; font-family: monospace; margin-top: 5px;"><?php echo number_format($total_bonuses_shown, 2); ?> ل.س</div>
        </div>
        <div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px;">
            <div style="color: #e74a3b; font-weight: bold; font-size: 13px;">إجمالي الجزاءات المعروضة</div>
            <div style="font-size: 20px; font-weight: bold; font-family: monospace; margin-top: 5px;"><?php echo number_format($total_penalties_shown, 2); ?> ل.س</div>
        </div>
    </div>

    <div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <form method="GET" style="display: flex; align-items: center; gap: 10px;">
            <input type="hidden" name="filter_month" value="<?php echo htmlspecialchars($selected_filter_month); ?>">
            <label style="font-weight: bold; color: #36b9cc;">فلتر الشهر المستهدَف:</label>
            <input type="month" name="var_filter_month" value="<?php echo htmlspecialchars($var_filter_month); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            <button type="submit" style="background: #36b9cc; color: white; border: none; padding: 7px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">بحث</button>
            <?php if (!empty($var_filter_month)): ?>
                <a href="hr_payroll_advanced.php" style="color: #666; font-size: 13px; text-decoration: none;">إلغاء الفلتر</a>
            <?php endif; ?>
        </form>
    </div>

    <div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #36b9cc; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">الموظف</th>
                    <th style="padding: 12px 15px;">النوع</th>
                    <th style="padding: 12px 15px;">المبلغ</th>
                    <th style="padding: 12px 15px;">الشهر المستهدَف</th>
                    <th style="padding: 12px 15px;">ملاحظات / السبب</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($variable_items_view) > 0): foreach ($variable_items_view as $v): ?>
                    <tr style="border-bottom: 1px solid #f1f1f1;">
                        <td style="padding: 12px 15px; font-weight: bold;"><?php echo htmlspecialchars($v['emp_name']); ?></td>
                        <td style="padding: 12px 15px;">
                            <?php if ($v['item_type'] === 'bonus'): ?>
                                <span style="background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: bold;">مكافأة / حافز</span>
                            <?php else: ?>
                                <span style="background: #f8d7da; color: #721c24; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: bold;">جزاء / خصم</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: <?php echo $v['item_type'] === 'bonus' ? '#1cc88a' : '#e74a3b'; ?>;"><?php echo number_format($v['amount'], 2); ?> ل.س</td>
                        <td style="padding: 12px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($v['target_month']); ?></td>
                        <td style="padding: 12px 15px; color: #777;"><?php echo htmlspecialchars($v['notes'] ?: '-'); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" style="padding: 20px; text-align: center; color: #777;">لا توجد جزاءات أو حوافز مسجَّلة مطابقة.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- النوافذ المنبثقة (Modals) المحدثة لاحترافية كاملة -->
<!-- 1. نافذة إضافة موظف شاملة (Enterprise Employee Form) -->
<div id="empModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; justify-content: center; align-items: center; overflow-y: auto;">
    <div style="background: white; width: 700px; max-height: 90vh; overflow-y: auto; padding: 25px; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #1cc88a; border-bottom: 2px solid #e3e6f0; padding-bottom: 10px;">إضافة ملف موظف مؤسسي متكامل</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="add_employee" value="1">
            
            <h4 style="color: #4e73df; margin-bottom: 10px;">1. البيانات الشخصية والقانونية</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <div><label>الكود الوظيفي:</label><input type="text" name="employee_code" value="EMP-<?php echo rand(100,999); ?>" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>الاسم الكامل:</label><input type="text" name="full_name" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>الرقم الوطني / الهوية:</label><input type="text" name="national_id" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>الجنس:</label><select name="gender" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="male">ذكر</option><option value="female">أنثى</option></select></div>
                <div><label>تاريخ الميلاد:</label><input type="date" name="date_of_birth" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>الجنسية:</label><input type="text" name="nationality" value="سوري" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>الحالة الاجتماعية:</label><select name="marital_status" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="single">أعزب / عزباء</option><option value="married">متزوج / متزوجة</option><option value="divorced">مطلق / مطلقة</option><option value="widowed">أرمل / أرملة</option></select></div>
            </div>

            <h4 style="color: #4e73df; margin-bottom: 10px;">2. البيانات الوظيفية والتعاقدية</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <div><label>القسم الإداري:</label><input type="text" name="department" placeholder="تقنية المعلومات / المبيعات..." required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>المسمى الوظيفي:</label><input type="text" name="position" placeholder="مطور / محاسب..." required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>نوع العقد:</label><select name="employment_type" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="full_time">دوام كامل (Full-Time)</option><option value="part_time">دوام جزئي (Part-Time)</option><option value="contractor">مقاول / استشاري (Contractor)</option><option value="intern">متدرب (Intern)</option></select></div>
                <div><label>حالة الموظف:</label><select name="status" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="active">نشط على رأس عمله</option><option value="suspended">موقوف مؤقتاً</option><option value="terminated">مفصول</option><option value="resigned">مستقيل</option></select></div>
                <div><label>تاريخ التعيين المباشر:</label><input type="date" name="hire_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>نهاية فترة التجربة:</label><input type="date" name="probation_end_date" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            </div>

            <h4 style="color: #4e73df; margin-bottom: 10px;">3. الهيكل المالي والبنكي</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <div><label>الراتب الأساسي (ل.س):</label><input type="number" step="0.01" name="base_salary" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>طريقة صرف الراتب:</label><select name="payment_method" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="bank_transfer">تحويل مصرفي (Bank Transfer)</option><option value="cash">نقدي من الخزينة (Cash)</option><option value="check">شيك (Check)</option></select></div>
                <div><label>اسم المصرف / البنك:</label><input type="text" name="bank_name" placeholder="بنك بيمو / المصرف التجاري..." style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>رقم الحساب المصرفي:</label><input type="text" name="bank_account" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div style="grid-column: span 2;"><label>رقم الحساب الدولي (IBAN):</label><input type="text" name="iban" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            </div>

            <h4 style="color: #4e73df; margin-bottom: 10px;">4. الاتصال وطوارئ</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <div><label>رقم الهاتف الشخصي:</label><input type="text" name="phone" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>البريد الإلكتروني للعمل:</label><input type="email" name="work_email" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div style="grid-column: span 2;"><label>العنوان السكني التفصيلي:</label><input type="text" name="address" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>اسم شخص الطوارئ:</label><input type="text" name="emergency_contact_name" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>هاتف الطوارئ:</label><input type="text" name="emergency_contact_phone" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            </div>

            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px;">
                <button type="button" onclick="closeModal('empModal')" style="background: none; border: none; padding: 8px; cursor: pointer;">إلغاء</button>
                <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 9px 20px; border-radius: 4px; font-weight: bold;">حفظ الملف الشامل للموظف</button>
            </div>
        </form>
    </div>
</div>

<!-- 1ب. نافذة تعديل موظف موجود -->
<div id="editEmpModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; justify-content: center; align-items: center; overflow-y: auto;">
    <div style="background: white; width: 700px; max-height: 90vh; overflow-y: auto; padding: 25px; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #f6c23e; border-bottom: 2px solid #e3e6f0; padding-bottom: 10px;">تعديل ملف الموظف</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="edit_employee" value="1">
            <input type="hidden" name="employee_id" id="edit_employee_id">

            <h4 style="color: #4e73df; margin-bottom: 10px;">1. البيانات الشخصية والقانونية</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <div><label>الكود الوظيفي:</label><input type="text" name="employee_code" id="edit_employee_code" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>الاسم الكامل:</label><input type="text" name="full_name" id="edit_full_name" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>الرقم الوطني / الهوية:</label><input type="text" name="national_id" id="edit_national_id" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>الجنس:</label><select name="gender" id="edit_gender" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="male">ذكر</option><option value="female">أنثى</option></select></div>
                <div><label>تاريخ الميلاد:</label><input type="date" name="date_of_birth" id="edit_date_of_birth" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>الجنسية:</label><input type="text" name="nationality" id="edit_nationality" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>الحالة الاجتماعية:</label><select name="marital_status" id="edit_marital_status" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="single">أعزب / عزباء</option><option value="married">متزوج / متزوجة</option><option value="divorced">مطلق / مطلقة</option><option value="widowed">أرمل / أرملة</option></select></div>
            </div>

            <h4 style="color: #4e73df; margin-bottom: 10px;">2. البيانات الوظيفية والتعاقدية</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <div><label>القسم الإداري:</label><input type="text" name="department" id="edit_department" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>المسمى الوظيفي:</label><input type="text" name="position" id="edit_position" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>نوع العقد:</label><select name="employment_type" id="edit_employment_type" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="full_time">دوام كامل (Full-Time)</option><option value="part_time">دوام جزئي (Part-Time)</option><option value="contractor">مقاول / استشاري (Contractor)</option><option value="intern">متدرب (Intern)</option></select></div>
                <div><label>حالة الموظف:</label><select name="status" id="edit_status" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="active">نشط على رأس عمله</option><option value="suspended">موقوف مؤقتاً</option><option value="terminated">مفصول</option><option value="resigned">مستقيل</option></select></div>
                <div><label>تاريخ التعيين المباشر:</label><input type="date" name="hire_date" id="edit_hire_date" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>نهاية فترة التجربة:</label><input type="date" name="probation_end_date" id="edit_probation_end_date" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            </div>

            <h4 style="color: #4e73df; margin-bottom: 10px;">3. الهيكل المالي والبنكي</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <div><label>الراتب الأساسي (ل.س):</label><input type="number" step="0.01" name="base_salary" id="edit_base_salary" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>طريقة صرف الراتب:</label><select name="payment_method" id="edit_payment_method" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"><option value="bank_transfer">تحويل مصرفي (Bank Transfer)</option><option value="cash">نقدي من الخزينة (Cash)</option><option value="check">شيك (Check)</option></select></div>
                <div><label>اسم المصرف / البنك:</label><input type="text" name="bank_name" id="edit_bank_name" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>رقم الحساب المصرفي:</label><input type="text" name="bank_account" id="edit_bank_account" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div style="grid-column: span 2;"><label>رقم الحساب الدولي (IBAN):</label><input type="text" name="iban" id="edit_iban" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            </div>

            <h4 style="color: #4e73df; margin-bottom: 10px;">4. الاتصال وطوارئ</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <div><label>رقم الهاتف الشخصي:</label><input type="text" name="phone" id="edit_phone" required style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
                <div><label>البريد الإلكتروني للعمل:</label><input type="email" name="work_email" id="edit_work_email" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div style="grid-column: span 2;"><label>العنوان السكني التفصيلي:</label><input type="text" name="address" id="edit_address" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>اسم شخص الطوارئ:</label><input type="text" name="emergency_contact_name" id="edit_emergency_contact_name" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px;"></div>
                <div><label>هاتف الطوارئ:</label><input type="text" name="emergency_contact_phone" id="edit_emergency_contact_phone" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            </div>

            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px;">
                <button type="button" onclick="closeModal('editEmpModal')" style="background: none; border: none; padding: 8px; cursor: pointer;">إلغاء</button>
                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 9px 20px; border-radius: 4px; font-weight: bold;">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. نافذة السلفة المقسطة -->
<div id="advanceModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; padding: 25px; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #f6c23e;">منح سلفة مقسطة</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="add_advance" value="1">
            <div style="margin-bottom: 10px;">
                <label>اختر الموظف:</label>
                <select name="employee_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <?php foreach ($employees_list as $e): ?>
                        <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['full_name']); ?> (<?php echo $e['employee_code']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom: 10px;"><label>إجمالي مبلغ السلفة (ل.س):</label><input type="number" step="0.01" name="total_amount" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 10px;"><label>عدد الأقساط (بالأشهر):</label><input type="number" name="installments_count" value="3" min="1" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 10px;"><label>شهر بدء الاستقطاع:</label><input type="month" name="start_month" value="<?php echo date('Y-m'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 15px;"><label>ملاحظات البيان:</label><textarea name="notes" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; height: 50px;"></textarea></div>
            <div style="text-align: left;"><button type="button" onclick="closeModal('advanceModal')" style="background: none; border: none; padding: 8px; cursor: pointer;">إلغاء</button><button type="submit" style="background: #f6c23e; color: white; border: none; padding: 8px 15px; border-radius: 4px; font-weight: bold;">جدولة وصرف</button></div>
        </form>
    </div>
</div>

<!-- 2ب. نافذة تعديل سلفة موجودة -->
<div id="editAdvanceModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; padding: 25px; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #f6c23e;">تعديل السلفة المقسطة</h3>
        <p style="font-size: 12px; color: #888; margin-top: -8px;">التعديل يُعيد جدولة كل الأقساط من جديد (متاح فقط طالما لم يُسدَّد أي قسط بعد).</p>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="edit_advance" value="1">
            <input type="hidden" name="advance_id" id="edit_advance_id">
            <div style="margin-bottom: 10px;"><label>إجمالي مبلغ السلفة (ل.س):</label><input type="number" step="0.01" name="total_amount" id="edit_adv_total_amount" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 10px;"><label>عدد الأقساط (بالأشهر):</label><input type="number" name="installments_count" id="edit_adv_installments_count" min="1" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 10px;"><label>شهر بدء الاستقطاع:</label><input type="month" name="start_month" id="edit_adv_start_month" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 15px;"><label>ملاحظات البيان:</label><textarea name="notes" id="edit_adv_notes" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; height: 50px;"></textarea></div>
            <div style="text-align: left;"><button type="button" onclick="closeModal('editAdvanceModal')" style="background: none; border: none; padding: 8px; cursor: pointer;">إلغاء</button><button type="submit" style="background: #f6c23e; color: white; border: none; padding: 8px 15px; border-radius: 4px; font-weight: bold;">حفظ وإعادة الجدولة</button></div>
        </form>
    </div>
</div>

<!-- 3. نافذة الجزاءات والمكافآت -->
<div id="varModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; padding: 25px; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #36b9cc;">إضافة جزاء أو مكافأة</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="add_variable_item" value="1">
            <div style="margin-bottom: 10px;">
                <label>اختر الموظف:</label>
                <select name="employee_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <?php foreach ($employees_list as $e): ?>
                        <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom: 10px;">
                <label>نوع البند:</label>
                <select name="item_type" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="penalty">جزاء / خصم إداري</option>
                    <option value="bonus">مكافأة / حافز</option>
                </select>
            </div>
            <div style="margin-bottom: 10px;"><label>المبلغ (ل.س):</label><input type="number" step="0.01" name="amount" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 10px;"><label>يخص شهر:</label><input type="month" name="target_month" value="<?php echo date('Y-m'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 15px;"><label>السبب / ملاحظات:</label><textarea name="notes" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; height: 50px;"></textarea></div>
            <div style="text-align: left;"><button type="button" onclick="closeModal('varModal')" style="background: none; border: none; padding: 8px; cursor: pointer;">إلغاء</button><button type="submit" style="background: #36b9cc; color: white; border: none; padding: 8px 15px; border-radius: 4px; font-weight: bold;">حفظ</button></div>
        </form>
    </div>
</div>

<!-- 4. نافذة توليد مسير الرواتب -->
<div id="payrollModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; padding: 25px; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #4e73df;">توليد مسير الرواتب الشهري</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="process_payroll" value="1">
            <div style="margin-bottom: 15px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">اختر شهر الاستحقاق:</label>
                <input type="month" name="target_month" value="<?php echo date('Y-m'); ?>" required style="width: 100%; padding: 9px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 15px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">نوع الراتب لكل موظفي هذا المسير:</label>
                <select name="salary_type" style="width: 100%; padding: 9px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="شهري">شهري (الراتب الأساسي كاملاً)</option>
                    <option value="أسبوعي">أسبوعي (الراتب الأساسي ÷ 4)</option>
                </select>
            </div>
            <p style="font-size: 13px; color: #e74a3b; background: #fff3f3; padding: 10px; border-radius: 4px; line-height: 1.5;">
                <b>محرك الرواتب:</b> يقوم النظام بحساب الصافي لكل موظف بعد خصم الأقساط والجزاءات وإضافة المكافآت، وإنشاء القيد المحاسبي المزدوج.
            </p>
            <div style="text-align: left; margin-top: 15px;"><button type="button" onclick="closeModal('payrollModal')" style="background: none; border: none; padding: 8px; cursor: pointer;">إلغاء</button><button type="submit" style="background: #4e73df; color: white; border: none; padding: 9px 20px; border-radius: 4px; font-weight: bold;">معالجة المسير</button></div>
        </form>
    </div>
</div>

<!-- 4-ب. نافذة صرف راتب لموظف واحد فقط -->
<div id="singlePayrollModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; padding: 25px; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #1cc88a;">صرف راتب لموظف واحد فقط</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="process_single_payroll" value="1">
            <div style="margin-bottom: 12px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">الموظف:</label>
                <select name="single_employee_id" required style="width: 100%; padding: 9px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- اختر الموظف --</option>
                    <?php foreach ($employees_list as $emp_opt): ?>
                        <option value="<?php echo $emp_opt['id']; ?>"><?php echo htmlspecialchars($emp_opt['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom: 15px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">شهر الاستحقاق:</label>
                <input type="month" name="single_target_month" value="<?php echo date('Y-m'); ?>" required style="width: 100%; padding: 9px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">نوع الراتب:</label>
                <select name="single_salary_type" id="single_salary_type_select" onchange="toggleWeekFields()" style="width: 100%; padding: 9px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="شهري">شهري (الراتب الأساسي كاملاً)</option>
                    <option value="أسبوعي">أسبوعي (سعر اليوم × عدد الأيام الفعلية)</option>
                </select>
            </div>
            <div id="weekFieldsWrap" style="display: none; margin-bottom: 15px; background: #f8f9fc; padding: 12px; border-radius: 6px; border: 1px solid #e3e6f0;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px; font-size: 13px;">فترة الأسبوع الفعلية (من / إلى):</label>
                <div style="display: flex; gap: 8px;">
                    <input type="date" name="single_week_start" style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                    <input type="date" name="single_week_end" style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
                <p style="font-size: 11.5px; color: #888; margin: 6px 0 0 0;">السعر اليومي = الراتب الأساسي ÷ 30، والمبلغ المستحق = السعر اليومي × عدد أيام هذه الفترة (شاملة اليومين). اتركهما فارغَين للرجوع لقسمة تقريبية على 4.</p>
            </div>
            <p style="font-size: 12.5px; color: #1a8f5f; background: #eafaf1; padding: 10px; border-radius: 4px; line-height: 1.5;">
                يُنشئ (أو يُلحق بـ) مسير هذا الشهر لهذا الموظف فقط، دون التأثير على أي موظف آخر — مفيد لصرف راتب متأخر أو استثنائي بمعزل عن باقي الفريق.
            </p>
            <div style="text-align: left; margin-top: 10px;"><button type="button" onclick="closeModal('singlePayrollModal')" style="background: none; border: none; padding: 8px; cursor: pointer;">إلغاء</button><button type="submit" style="background: #1cc88a; color: white; border: none; padding: 9px 20px; border-radius: 4px; font-weight: bold;">صرف الراتب</button></div>
        </form>
    </div>
</div>

<script>
    function toggleWeekFields() {
        var sel = document.getElementById('single_salary_type_select');
        document.getElementById('weekFieldsWrap').style.display = (sel.value === 'أسبوعي') ? 'block' : 'none';
    }
</script>

<!-- 4-ج. نافذة تعديل راتب موظف مصروف بالفعل -->
<div id="editPayrollDetailModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; padding: 25px; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #f6c23e;">تعديل راتب موظف</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="edit_payroll_detail" value="1">
            <input type="hidden" name="detail_id" id="epd_id">
            <div id="epd_emp_name" style="font-weight: bold; color: #333; margin-bottom: 12px;"></div>
            <div style="margin-bottom: 10px;">
                <label>نوع الراتب:</label>
                <select name="edit_salary_type" id="epd_salary_type" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="شهري">شهري</option>
                    <option value="أسبوعي">أسبوعي</option>
                </select>
            </div>
            <div style="margin-bottom: 10px;"><label>الراتب الأساسي:</label><input type="number" step="0.01" name="edit_base_salary" id="epd_base" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 10px;"><label>خصم الأقساط:</label><input type="number" step="0.01" name="edit_deducted_advances" id="epd_adv" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 10px;"><label>الجزاءات:</label><input type="number" step="0.01" name="edit_penalties" id="epd_pen" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <div style="margin-bottom: 15px;"><label>المكافآت:</label><input type="number" step="0.01" name="edit_bonuses" id="epd_bonus" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;"></div>
            <p style="font-size: 12px; color: #856404; background: #fff3cd; padding: 10px; border-radius: 4px;">سيُعاد حساب القيد المحاسبي المُجمَّع لكل مسير هذا الشهر تلقائياً بعد الحفظ.</p>
            <div style="text-align: left; margin-top: 10px;"><button type="button" onclick="closeModal('editPayrollDetailModal')" style="background: none; border: none; padding: 8px; cursor: pointer;">إلغاء</button><button type="submit" style="background: #f6c23e; color: white; border: none; padding: 9px 20px; border-radius: 4px; font-weight: bold;">حفظ التعديل</button></div>
        </form>
    </div>
</div>

<script>
    function openEditPayrollDetailModal(det) {
        document.getElementById('epd_id').value = det.id;
        document.getElementById('epd_emp_name').innerText = det.emp_name;
        document.getElementById('epd_salary_type').value = det.salary_type || 'شهري';
        document.getElementById('epd_base').value = det.base_salary;
        document.getElementById('epd_adv').value = det.deducted_advances;
        document.getElementById('epd_pen').value = det.penalties;
        document.getElementById('epd_bonus').value = det.bonuses;
        document.getElementById('editPayrollDetailModal').style.display = 'flex';
    }
</script>

<script>
    function switchTab(tabId) {
        document.getElementById('payrollReportTab').style.display = 'none';
        document.getElementById('employeesTab').style.display = 'none';
        document.getElementById('advancesTab').style.display = 'none';
        document.getElementById('variableTab').style.display = 'none';

        var allButtons = ['btnReportTab', 'btnEmpTab', 'btnAdvTab', 'btnVarTab'];
        allButtons.forEach(function (btnId) {
            document.getElementById(btnId).style.background = '#e3e6f0';
            document.getElementById(btnId).style.color = '#333';
        });

        document.getElementById(tabId).style.display = 'block';

        var tabToBtn = { payrollReportTab: 'btnReportTab', employeesTab: 'btnEmpTab', advancesTab: 'btnAdvTab', variableTab: 'btnVarTab' };
        var activeBtn = tabToBtn[tabId];
        document.getElementById(activeBtn).style.background = '#4e73df';
        document.getElementById(activeBtn).style.color = 'white';
    }

    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    function openEditEmployeeModal(emp) {
        document.getElementById('edit_employee_id').value = emp.id;
        document.getElementById('edit_employee_code').value = emp.employee_code;
        document.getElementById('edit_full_name').value = emp.full_name;
        document.getElementById('edit_national_id').value = emp.national_id;
        document.getElementById('edit_gender').value = emp.gender;
        document.getElementById('edit_date_of_birth').value = emp.date_of_birth;
        document.getElementById('edit_nationality').value = emp.nationality;
        document.getElementById('edit_marital_status').value = emp.marital_status;
        document.getElementById('edit_department').value = emp.department;
        document.getElementById('edit_position').value = emp.position;
        document.getElementById('edit_employment_type').value = emp.employment_type;
        document.getElementById('edit_hire_date').value = emp.hire_date;
        document.getElementById('edit_probation_end_date').value = emp.probation_end_date;
        document.getElementById('edit_status').value = emp.status;
        document.getElementById('edit_base_salary').value = emp.base_salary;
        document.getElementById('edit_payment_method').value = emp.payment_method;
        document.getElementById('edit_bank_name').value = emp.bank_name;
        document.getElementById('edit_bank_account').value = emp.bank_account;
        document.getElementById('edit_iban').value = emp.iban;
        document.getElementById('edit_phone').value = emp.phone;
        document.getElementById('edit_work_email').value = emp.work_email;
        document.getElementById('edit_address').value = emp.address;
        document.getElementById('edit_emergency_contact_name').value = emp.emergency_contact_name;
        document.getElementById('edit_emergency_contact_phone').value = emp.emergency_contact_phone;
        openModal('editEmpModal');
    }

    function openEditAdvanceModal(adv) {
        document.getElementById('edit_advance_id').value = adv.id;
        document.getElementById('edit_adv_total_amount').value = adv.total_amount;
        document.getElementById('edit_adv_installments_count').value = adv.installments_count;
        document.getElementById('edit_adv_start_month').value = adv.start_month;
        document.getElementById('edit_adv_notes').value = adv.notes || '';
        openModal('editAdvanceModal');
    }
</script>

<?php include 'footer.php'; ?>