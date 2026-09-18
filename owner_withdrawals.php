<?php
session_start();
include 'header.php';

if (!isset($conn)) { die("خطأ: اتصال قاعدة البيانات غير متوفر."); }

requireRole($conn, ['admin', 'accountant']);

$conn->exec("CREATE TABLE IF NOT EXISTS owner_withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    withdrawal_date DATE NOT NULL,
    amount_syp DECIMAL(15,2) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// تصحيح جوهري بناءً على توضيح صريح: ليست كل "سحوبات المالك" توزيع أرباح نهائي — أحياناً يكون المالك
// قد اقترض مبلغاً من العمل وعليه إعادته (ذمة مدينة على المالك للشركة)، وهذا مختلف محاسبياً تماماً عن
// سحب نهائي يُخفِّض حقوق الملكية بشكل دائم. أضفنا عمود نوع السحب، وجدولاً لتتبّع سداد القروض تدريجياً.
try {
    $ow_cols = $conn->query("SHOW COLUMNS FROM owner_withdrawals")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('withdrawal_type', $ow_cols)) {
        $conn->exec("ALTER TABLE owner_withdrawals ADD COLUMN withdrawal_type ENUM('سحب نهائي','قرض للمالك') NOT NULL DEFAULT 'سحب نهائي'");
    }
} catch (Exception $e) { /* يُتجاهل إن تعذّر */ }

$conn->exec("CREATE TABLE IF NOT EXISTS owner_loan_repayments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    withdrawal_id INT NOT NULL,
    amount_syp DECIMAL(15,2) NOT NULL,
    repayment_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$error = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_withdrawal'])) {
    verifyCsrfToken();

    $w_date = $_POST['withdrawal_date'] ?? date('Y-m-d');
    $w_amount = filter_var($_POST['amount_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $w_notes = trim($_POST['notes'] ?? '');
    $w_type = ($_POST['withdrawal_type'] ?? 'سحب نهائي') === 'قرض للمالك' ? 'قرض للمالك' : 'سحب نهائي';

    if ($w_amount <= 0) {
        $error = "خطأ: يرجى إدخال مبلغ أكبر من صفر.";
    } elseif (isDateInClosedPeriod($conn, $w_date)) {
        $error = getPeriodLockErrorMessage($w_date);
    } else {
        try {
            $conn->beginTransaction();

            // تحقق تحذيري: هل المبلغ المسحوب أكبر من الرصيد النقدي الفعلي المتاح؟ لا يمنع العملية
            // (قد يكون السحب من مصدر آخر مؤقتاً)، لكن يُنبِّه صاحب الحساب بوضوح قبل التأكيد.
            $stmt_cash_bal = $conn->query("
                SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
                FROM journal_entries je JOIN accounts a ON je.account_id = a.id
                WHERE a.account_name LIKE '%صندوق الرئيسي%'
            ");
            $current_cash = floatval($stmt_cash_bal->fetchColumn());

            $conn->prepare("INSERT INTO owner_withdrawals (withdrawal_date, amount_syp, notes, withdrawal_type) VALUES (?, ?, ?, ?)")
                 ->execute([$w_date, $w_amount, $w_notes, $w_type]);
            $withdrawal_id = $conn->lastInsertId();

            // نفس منطق getNextAvailableCode() المعتمد في accounts.php: يبحث عن أول رمز رقمي متاح
            // غير مستخدَم، بدل قيمة عشوائية أو فارغة قد تتعارض مع قيد account_code الفريد.
            $get_next_code = function ($conn, $preferred) {
                $code = $preferred;
                $stmt = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
                for ($i = 0; $i < 50; $i++) {
                    $stmt->execute([$code]);
                    if ($stmt->fetchColumn() == 0) { return $code; }
                    $code = (string)(intval($code) + 1);
                }
                return $preferred . '-' . substr(uniqid(), -4);
            };

            $acc_cols = $conn->query("SHOW COLUMNS FROM accounts")->fetchAll(PDO::FETCH_COLUMN);

            $drawings_acc = null;
            if ($w_type === 'قرض للمالك') {
                // قرض للمالك = أصل (ذمة مدينة عليه)، وليس توزيع أرباح نهائي يُخفِّض حقوق الملكية
                $stmt_da = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE ? LIMIT 1");
                $stmt_da->execute(['%قرض للمالك%']);
                $drawings_acc = $stmt_da->fetchColumn();
                if (!$drawings_acc) {
                    $ins_cols = ['account_name']; $ins_vals = ['قرض للمالك'];
                    if (in_array('account_type', $acc_cols)) { $ins_cols[] = 'account_type'; $ins_vals[] = 'Asset'; }
                    if (in_array('account_code', $acc_cols)) { $ins_cols[] = 'account_code'; $ins_vals[] = $get_next_code($conn, '1310'); }
                    $ph = implode(',', array_fill(0, count($ins_cols), '?'));
                    $conn->prepare("INSERT INTO accounts (" . implode(',', $ins_cols) . ") VALUES ($ph)")->execute($ins_vals);
                    $drawings_acc = $conn->lastInsertId();
                }
            } else {
                $stmt_da = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE ? OR account_name LIKE ? LIMIT 1");
                $stmt_da->execute(['%مسحوبات صاحب العمل%', '%مسحوبات شخصية%']);
                $drawings_acc = $stmt_da->fetchColumn();
                if (!$drawings_acc) {
                    $ins_cols = ['account_name']; $ins_vals = ['مسحوبات صاحب العمل'];
                    if (in_array('account_type', $acc_cols)) { $ins_cols[] = 'account_type'; $ins_vals[] = 'Equity'; }
                    if (in_array('account_code', $acc_cols)) { $ins_cols[] = 'account_code'; $ins_vals[] = $get_next_code($conn, '3910'); }
                    $ph = implode(',', array_fill(0, count($ins_cols), '?'));
                    $conn->prepare("INSERT INTO accounts (" . implode(',', $ins_cols) . ") VALUES ($ph)")->execute($ins_vals);
                    $drawings_acc = $conn->lastInsertId();
                }
            }

            $cash_acc = null;
            $stmt_ca = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE '%صندوق الرئيسي%' LIMIT 1");
            $stmt_ca->execute();
            $cash_acc = $stmt_ca->fetchColumn();
            if (!$cash_acc) {
                $ins_cols2 = ['account_name']; $ins_vals2 = ['الصندوق الرئيسي'];
                if (in_array('account_type', $acc_cols)) { $ins_cols2[] = 'account_type'; $ins_vals2[] = 'Asset'; }
                if (in_array('account_code', $acc_cols)) { $ins_cols2[] = 'account_code'; $ins_vals2[] = $get_next_code($conn, '1111'); }
                $ph2 = implode(',', array_fill(0, count($ins_cols2), '?'));
                $conn->prepare("INSERT INTO accounts (" . implode(',', $ins_cols2) . ") VALUES ($ph2)")->execute($ins_vals2);
                $cash_acc = $conn->lastInsertId();
            }

            if (!$drawings_acc || !$cash_acc) {
                throw new Exception("تعذّر تحديد أو إنشاء الحسابات المحاسبية اللازمة (مسحوبات/صندوق).");
            }

            $entry_num = "JE-DRAW-" . $withdrawal_id;
            $desc = "سحب شخصي لصاحب العمل" . (!empty($w_notes) ? " — " . $w_notes : "");
            postJournalLine($conn, $drawings_acc, $w_amount, 0, $entry_num, $w_date, $desc, 'Owner Withdrawal');
            postJournalLine($conn, $cash_acc, 0, $w_amount, $entry_num, $w_date, $desc, 'Owner Withdrawal');

            $conn->commit();
            logAudit($conn, 'INSERT', 'سحوبات صاحب العمل', "سحب بقيمة " . number_format($w_amount, 2) . " ل.س", $withdrawal_id);

            if ($w_amount > $current_cash) {
                $msg = "تم تسجيل السحب بنجاح — تنبيه: المبلغ المسحوب (" . number_format($w_amount, 2) . ") أكبر من الرصيد النقدي المتاح حالياً (" . number_format($current_cash, 2) . " ل.س) وقت التسجيل.";
            } else {
                $msg = "تم تسجيل السحب وترحيل القيد المحاسبي بنجاح.";
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ أثناء تسجيل السحب: " . $e->getMessage();
        }
    }
}

// حذف سحب (بلا تعديل — فقط حذف كامل مع عكس قيده)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_withdrawal'])) {
    verifyCsrfToken();
    requireRole($conn, ['admin']);
    $del_id = intval($_POST['withdrawal_id']);
    try {
        $conn->beginTransaction();
        $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ?")->execute(["JE-DRAW-" . $del_id]);
        $conn->prepare("DELETE FROM owner_loan_repayments WHERE withdrawal_id = ?")->execute([$del_id]);
        $conn->prepare("DELETE FROM owner_withdrawals WHERE id = ?")->execute([$del_id]);
        $conn->commit();
        logAudit($conn, 'DELETE', 'سحوبات صاحب العمل', "حذف سحب رقم $del_id", $del_id);
        $msg = "تم حذف السحب وعكس قيده المحاسبي بنجاح.";
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        $error = "خطأ أثناء الحذف: " . $e->getMessage();
    }
}

// إعادة تصنيف سحب موجود مسبقاً (سحب نهائي ⇄ قرض للمالك) — يعكس القيد الأصلي بالكامل ويُعيد ترحيله على
// الحساب الصحيح، لتصحيح إدخالات قديمة سُجِّلت بالتصنيف الخطأ (كحالة سحب اتضح لاحقاً أنه قرض واجب السداد)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reclassify_withdrawal'])) {
    verifyCsrfToken();
    requireRole($conn, ['admin', 'accountant']);
    $rc_id = intval($_POST['withdrawal_id']);
    $rc_new_type = ($_POST['new_type'] ?? '') === 'قرض للمالك' ? 'قرض للمالك' : 'سحب نهائي';
    try {
        $conn->beginTransaction();
        $stmt_w = $conn->prepare("SELECT * FROM owner_withdrawals WHERE id = ?");
        $stmt_w->execute([$rc_id]);
        $w_row = $stmt_w->fetch(PDO::FETCH_ASSOC);
        if (!$w_row) { throw new Exception("السحب غير موجود."); }
        if ($w_row['withdrawal_type'] === $rc_new_type) { throw new Exception("السحب مُصنَّف بالفعل كـ " . $rc_new_type . "."); }

        // عكس القيد الأصلي بالكامل
        $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ?")->execute(["JE-DRAW-" . $rc_id]);

        // إعادة الترحيل على الحساب الصحيح حسب التصنيف الجديد
        $acc_cols_rc = $conn->query("SHOW COLUMNS FROM accounts")->fetchAll(PDO::FETCH_COLUMN);
        $get_next_code_rc = function ($conn, $preferred) {
            $code = $preferred;
            $stmt = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
            for ($i = 0; $i < 50; $i++) {
                $stmt->execute([$code]);
                if ($stmt->fetchColumn() == 0) { return $code; }
                $code = (string)(intval($code) + 1);
            }
            return $preferred . '-' . substr(uniqid(), -4);
        };
        if ($rc_new_type === 'قرض للمالك') {
            $stmt_da = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE ? LIMIT 1");
            $stmt_da->execute(['%قرض للمالك%']);
            $target_acc = $stmt_da->fetchColumn();
            if (!$target_acc) {
                $ins_cols = ['account_name', 'account_type', 'account_code'];
                $ins_vals = ['قرض للمالك', 'Asset', $get_next_code_rc($conn, '1310')];
                $ph = implode(',', array_fill(0, count($ins_cols), '?'));
                $conn->prepare("INSERT INTO accounts (" . implode(',', $ins_cols) . ") VALUES ($ph)")->execute($ins_vals);
                $target_acc = $conn->lastInsertId();
            }
        } else {
            $stmt_da = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE ? OR account_name LIKE ? LIMIT 1");
            $stmt_da->execute(['%مسحوبات صاحب العمل%', '%مسحوبات شخصية%']);
            $target_acc = $stmt_da->fetchColumn();
            if (!$target_acc) {
                $ins_cols = ['account_name', 'account_type', 'account_code'];
                $ins_vals = ['مسحوبات صاحب العمل', 'Equity', $get_next_code_rc($conn, '3910')];
                $ph = implode(',', array_fill(0, count($ins_cols), '?'));
                $conn->prepare("INSERT INTO accounts (" . implode(',', $ins_cols) . ") VALUES ($ph)")->execute($ins_vals);
                $target_acc = $conn->lastInsertId();
            }
        }
        $stmt_cash_rc = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE '%صندوق الرئيسي%' LIMIT 1");
        $stmt_cash_rc->execute();
        $cash_acc_rc = $stmt_cash_rc->fetchColumn();
        if (!$target_acc || !$cash_acc_rc) { throw new Exception("تعذّر تحديد الحسابات المحاسبية اللازمة."); }

        $entry_num = "JE-DRAW-" . $rc_id;
        $desc = ($rc_new_type === 'قرض للمالك' ? "قرض للمالك (أُعيد تصنيفه)" : "سحب شخصي لصاحب العمل (أُعيد تصنيفه)") . (!empty($w_row['notes']) ? " — " . $w_row['notes'] : "");
        postJournalLine($conn, $target_acc, floatval($w_row['amount_syp']), 0, $entry_num, $w_row['withdrawal_date'], $desc, 'Owner Withdrawal');
        postJournalLine($conn, $cash_acc_rc, 0, floatval($w_row['amount_syp']), $entry_num, $w_row['withdrawal_date'], $desc, 'Owner Withdrawal');

        $conn->prepare("UPDATE owner_withdrawals SET withdrawal_type = ? WHERE id = ?")->execute([$rc_new_type, $rc_id]);
        $conn->commit();
        logAudit($conn, 'UPDATE', 'سحوبات صاحب العمل', "إعادة تصنيف سحب رقم $rc_id من '" . $w_row['withdrawal_type'] . "' إلى '$rc_new_type'", $rc_id);
        $msg = "تم إعادة تصنيف السحب إلى \"$rc_new_type\" وتصحيح قيده المحاسبي بنجاح.";
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        $error = "خطأ أثناء إعادة التصنيف: " . $e->getMessage();
    }
}

// تسجيل سداد دفعة من المالك على قرض قائم (يزيد الصندوق ويُخفِّض رصيد القرض المستحق على المالك)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_repayment'])) {
    verifyCsrfToken();
    $rp_withdrawal_id = intval($_POST['withdrawal_id']);
    $rp_amount = filter_var($_POST['repayment_amount_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $rp_date = $_POST['repayment_date'] ?? date('Y-m-d');
    $rp_notes = trim($_POST['repayment_notes'] ?? '');

    if ($rp_amount <= 0) {
        $error = "خطأ: يرجى إدخال مبلغ سداد أكبر من صفر.";
    } else {
        try {
            $conn->beginTransaction();
            $stmt_lw = $conn->prepare("SELECT * FROM owner_withdrawals WHERE id = ? AND withdrawal_type = 'قرض للمالك'");
            $stmt_lw->execute([$rp_withdrawal_id]);
            $loan_row = $stmt_lw->fetch(PDO::FETCH_ASSOC);
            if (!$loan_row) { throw new Exception("القرض المحدَّد غير موجود أو ليس مصنَّفاً كقرض."); }

            $conn->prepare("INSERT INTO owner_loan_repayments (withdrawal_id, amount_syp, repayment_date, notes) VALUES (?, ?, ?, ?)")
                 ->execute([$rp_withdrawal_id, $rp_amount, $rp_date, $rp_notes]);
            $repayment_id = $conn->lastInsertId();

            $stmt_loan_acc = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE '%قرض للمالك%' LIMIT 1");
            $stmt_loan_acc->execute();
            $loan_acc = $stmt_loan_acc->fetchColumn();
            $stmt_cash_rp = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE '%صندوق الرئيسي%' LIMIT 1");
            $stmt_cash_rp->execute();
            $cash_acc_rp = $stmt_cash_rp->fetchColumn();
            if (!$loan_acc || !$cash_acc_rp) { throw new Exception("تعذّر تحديد الحسابات المحاسبية اللازمة."); }

            $entry_num = "JE-DRAWREPAY-" . $repayment_id;
            $desc = "سداد دفعة من المالك على قرض بتاريخ " . $loan_row['withdrawal_date'] . (!empty($rp_notes) ? " — " . $rp_notes : "");
            postJournalLine($conn, $cash_acc_rp, $rp_amount, 0, $entry_num, $rp_date, $desc, 'Owner Loan Repayment');
            postJournalLine($conn, $loan_acc, 0, $rp_amount, $entry_num, $rp_date, $desc, 'Owner Loan Repayment');

            $conn->commit();
            logAudit($conn, 'INSERT', 'سحوبات صاحب العمل', "سداد دفعة " . number_format($rp_amount, 2) . " ل.س على قرض رقم $rp_withdrawal_id", $repayment_id);
            $msg = "تم تسجيل سداد الدفعة وترحيل قيدها بنجاح.";
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ أثناء تسجيل السداد: " . $e->getMessage();
        }
    }
}

$withdrawals = $conn->query("
    SELECT ow.*, COALESCE((SELECT SUM(olr.amount_syp) FROM owner_loan_repayments olr WHERE olr.withdrawal_id = ow.id), 0) AS repaid_amount
    FROM owner_withdrawals ow ORDER BY ow.withdrawal_date DESC, ow.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total_withdrawals_final = 0;   // سحب نهائي فقط — توزيع أرباح حقيقي
$total_loans_outstanding = 0;   // قروض للمالك، صافي بعد السداد — ذمة مدينة عليه
$total_loans_gross = 0;
$total_loans_repaid = 0;
foreach ($withdrawals as $w) {
    if ($w['withdrawal_type'] === 'قرض للمالك') {
        $total_loans_gross += floatval($w['amount_syp']);
        $total_loans_repaid += floatval($w['repaid_amount']);
        $total_loans_outstanding += floatval($w['amount_syp']) - floatval($w['repaid_amount']);
    } else {
        $total_withdrawals_final += floatval($w['amount_syp']);
    }
}
$total_withdrawals = $total_withdrawals_final; // للحفاظ على التوافق مع أي استخدام سابق لهذا الاسم

$current_cash_display = 0;
try {
    $stmt_cd = $conn->query("
        SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name LIKE '%صندوق الرئيسي%'
    ");
    $current_cash_display = floatval($stmt_cd->fetchColumn());
} catch (Exception $e) { /* يُتجاهل */ }
?>

<div style="padding: 20px;">
    <h2 style="color: #2e384d; margin-bottom: 5px;"><i class="fas fa-hand-holding-usd"></i> سحوبات صاحب العمل</h2>
    <p style="color: #6c757d; margin: 0 0 20px; font-size: 14px;">تسجيل أي مبلغ يسحبه صاحب العمل من الصندوق لاستخدام شخصي — يُخفِّض النقد وحقوق الملكية معاً، وليس مصروفاً تشغيلياً يؤثر على صافي الربح.</p>

    <?php if ($error): ?>
        <div style="background: #fdecea; color: #a33636; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div style="background: #e8f8f2; color: #1a7a5e; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px;">
        <div style="background: #f3eefe; border-right: 4px solid #6f42c1; padding: 15px; border-radius: 6px;">
            <div style="color: #5b3aa8; font-size: 12.5px; font-weight: bold;">إجمالي السحوبات النهائية</div>
            <div style="font-size: 20px; font-weight: bold; color: #6f42c1; font-family: monospace; margin-top: 5px;"><?php echo number_format($total_withdrawals_final, 2); ?> ل.س</div>
            <div style="font-size: 10.5px; color: #888; margin-top: 3px;">توزيع أرباح — يُخصَم من "صافي الربح بعد سحوبات الملّاك"</div>
        </div>
        <div style="background: #fff8e6; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px;">
            <div style="color: #96751c; font-size: 12.5px; font-weight: bold;">قروض للمالك — المتبقي غير المسدَّد</div>
            <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;"><?php echo number_format($total_loans_outstanding, 2); ?> ل.س</div>
            <div style="font-size: 10.5px; color: #888; margin-top: 3px;">إجمالي القروض: <?php echo number_format($total_loans_gross, 2); ?> | المسدَّد: <?php echo number_format($total_loans_repaid, 2); ?></div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; align-items: start;">

        <!-- نموذج تسجيل سحب جديد -->
        <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0;">
            <h3 style="margin-top: 0; font-size: 15px; color: #333;">تسجيل سحب جديد</h3>
            <div style="background: #eaf1fc; color: #2c4e9c; padding: 8px 10px; border-radius: 5px; font-size: 12px; margin-bottom: 15px;">
                الرصيد النقدي الحالي: <strong><?php echo number_format($current_cash_display, 2); ?> ل.س</strong>
            </div>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="add_withdrawal" value="1">
                <div style="margin-bottom: 12px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 13px;">التاريخ:</label>
                    <input type="date" name="withdrawal_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 13px;">نوع السحب:</label>
                    <select name="withdrawal_type" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="سحب نهائي">سحب نهائي (توزيع أرباح — لا يُعاد سداده)</option>
                        <option value="قرض للمالك">قرض للمالك (ذمة عليه، سيُعاد سداده لاحقاً)</option>
                    </select>
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 13px;">المبلغ (ل.س):</label>
                    <input type="number" step="0.01" name="amount_syp" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 13px;">ملاحظات (اختياري):</label>
                    <textarea name="notes" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; height: 60px;"></textarea>
                </div>
                <button type="submit" style="background: #6f42c1; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold; width: 100%;">
                    <i class="fas fa-hand-holding-usd"></i> تسجيل السحب
                </button>
            </form>
        </div>

        <!-- سجل السحوبات -->
        <div style="background: white; border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
            <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 15px; color: #333;">سجل السحوبات والقروض</h3>
                <span style="font-weight: bold; color: #6f42c1;">إجمالي السحوبات النهائية: <?php echo number_format($total_withdrawals_final, 2); ?> ل.س</span>
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
                <thead>
                    <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0;">
                        <th style="padding: 10px 15px;">التاريخ</th>
                        <th style="padding: 10px 15px;">النوع</th>
                        <th style="padding: 10px 15px;">المبلغ (ل.س)</th>
                        <th style="padding: 10px 15px;">المتبقي (للقروض)</th>
                        <th style="padding: 10px 15px;">ملاحظات</th>
                        <th style="padding: 10px 15px;">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($withdrawals) > 0): foreach ($withdrawals as $w):
                        $is_loan = $w['withdrawal_type'] === 'قرض للمالك';
                        $remaining = floatval($w['amount_syp']) - floatval($w['repaid_amount']);
                    ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-family: monospace;"><?php echo htmlspecialchars($w['withdrawal_date']); ?></td>
                            <td style="padding: 10px 15px;">
                                <span style="background: <?php echo $is_loan ? '#fff8e6' : '#f3eefe'; ?>; color: <?php echo $is_loan ? '#96751c' : '#6f42c1'; ?>; padding: 3px 8px; border-radius: 10px; font-size: 11px; font-weight: bold;"><?php echo htmlspecialchars($w['withdrawal_type']); ?></span>
                                <?php if ($is_loan && $remaining <= 0.009): ?><div style="font-size:10px; color:#1cc88a; margin-top:3px;"><i class="fas fa-check-circle"></i> سُدِّد بالكامل</div><?php endif; ?>
                            </td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #6f42c1; font-weight: bold;"><?php echo number_format($w['amount_syp'], 2); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: <?php echo $remaining > 0.009 ? '#e74a3b' : '#999'; ?>; font-weight: bold;"><?php echo $is_loan ? number_format($remaining, 2) : '—'; ?></td>
                            <td style="padding: 10px 15px; color: #666;"><?php echo htmlspecialchars($w['notes'] ?: '-'); ?></td>
                            <td style="padding: 10px 15px; white-space: nowrap;">
                                <?php if ($is_loan && $remaining > 0.009): ?>
                                    <button type="button" onclick="var f=document.getElementById('repayForm<?php echo $w['id']; ?>'); f.style.display = f.style.display==='none' ? 'flex' : 'none';" style="background: #eafaf1; color: #1a8f5f; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 11.5px; margin-left: 4px;">تسجيل سداد</button>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="reclassify_withdrawal" value="1">
                                    <input type="hidden" name="withdrawal_id" value="<?php echo $w['id']; ?>">
                                    <input type="hidden" name="new_type" value="<?php echo $is_loan ? 'سحب نهائي' : 'قرض للمالك'; ?>">
                                    <button type="submit" onclick="return confirm('سيُعاد تصنيف هذا السحب إلى «<?php echo $is_loan ? 'سحب نهائي' : 'قرض للمالك'; ?>» ويُصحَّح قيده المحاسبي تلقائياً. متابعة؟');" style="background: #eef1f9; color: #4e73df; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 11.5px; margin-left: 4px;">
                                        إعادة تصنيف إلى <?php echo $is_loan ? 'سحب نهائي' : 'قرض'; ?>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا السحب؟ سيُعكَس قيده المحاسبي بالكامل.');" style="display:inline;">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="delete_withdrawal" value="1">
                                    <input type="hidden" name="withdrawal_id" value="<?php echo $w['id']; ?>">
                                    <button type="submit" style="background: #fdecea; color: #e74a3b; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">حذف</button>
                                </form>
                            </td>
                        </tr>
                        <?php if ($is_loan): ?>
                        <tr id="repayForm<?php echo $w['id']; ?>" style="display:none; background:#fafbfe;">
                            <td colspan="6" style="padding: 12px 15px;">
                                <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="add_repayment" value="1">
                                    <input type="hidden" name="withdrawal_id" value="<?php echo $w['id']; ?>">
                                    <label style="font-size:12.5px; font-weight:bold; color:#555;">سداد قرض <?php echo htmlspecialchars($w['withdrawal_date']); ?>:</label>
                                    <input type="number" step="0.01" name="repayment_amount_syp" placeholder="المبلغ المسدَّد (ل.س)" required max="<?php echo $remaining; ?>" style="padding:6px; border:1px solid #ccc; border-radius:4px; font-family:monospace; width:160px;">
                                    <input type="date" name="repayment_date" value="<?php echo date('Y-m-d'); ?>" required style="padding:6px; border:1px solid #ccc; border-radius:4px; font-family:monospace;">
                                    <input type="text" name="repayment_notes" placeholder="ملاحظات (اختياري)" style="padding:6px; border:1px solid #ccc; border-radius:4px; flex:1; min-width:150px;">
                                    <button type="submit" style="background:#1cc88a; color:white; border:none; padding:7px 16px; border-radius:5px; cursor:pointer; font-weight:bold; font-size:12.5px;">حفظ السداد</button>
                                </form>
                                <?php if (floatval($w['repaid_amount']) > 0): ?>
                                <div style="margin-top:8px; font-size:11.5px; color:#888;">
                                    <?php
                                        $stmt_reps = $conn->prepare("SELECT * FROM owner_loan_repayments WHERE withdrawal_id = ? ORDER BY repayment_date ASC");
                                        $stmt_reps->execute([$w['id']]);
                                        $reps = $stmt_reps->fetchAll(PDO::FETCH_ASSOC);
                                    ?>
                                    سداد سابق: <?php foreach ($reps as $ri => $rep): ?><?php echo ($ri > 0 ? '، ' : ''); ?><?php echo htmlspecialchars($rep['repayment_date']); ?> (<?php echo number_format($rep['amount_syp'], 2); ?> ل.س)<?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" style="padding: 30px; text-align: center; color: #999;">لا توجد سحوبات مسجَّلة بعد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>