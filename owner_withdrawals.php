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

$error = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_withdrawal'])) {
    verifyCsrfToken();

    $w_date = $_POST['withdrawal_date'] ?? date('Y-m-d');
    $w_amount = filter_var($_POST['amount_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $w_notes = trim($_POST['notes'] ?? '');

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

            $conn->prepare("INSERT INTO owner_withdrawals (withdrawal_date, amount_syp, notes) VALUES (?, ?, ?)")
                 ->execute([$w_date, $w_amount, $w_notes]);
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
        $conn->prepare("DELETE FROM owner_withdrawals WHERE id = ?")->execute([$del_id]);
        $conn->commit();
        logAudit($conn, 'DELETE', 'سحوبات صاحب العمل', "حذف سحب رقم $del_id", $del_id);
        $msg = "تم حذف السحب وعكس قيده المحاسبي بنجاح.";
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        $error = "خطأ أثناء الحذف: " . $e->getMessage();
    }
}

$withdrawals = $conn->query("SELECT * FROM owner_withdrawals ORDER BY withdrawal_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$total_withdrawals = array_sum(array_column($withdrawals, 'amount_syp'));

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
                <h3 style="margin: 0; font-size: 15px; color: #333;">سجل السحوبات</h3>
                <span style="font-weight: bold; color: #6f42c1;">الإجمالي: <?php echo number_format($total_withdrawals, 2); ?> ل.س</span>
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
                <thead>
                    <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0;">
                        <th style="padding: 10px 15px;">التاريخ</th>
                        <th style="padding: 10px 15px;">المبلغ (ل.س)</th>
                        <th style="padding: 10px 15px;">ملاحظات</th>
                        <th style="padding: 10px 15px;">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($withdrawals) > 0): foreach ($withdrawals as $w): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-family: monospace;"><?php echo htmlspecialchars($w['withdrawal_date']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #6f42c1; font-weight: bold;"><?php echo number_format($w['amount_syp'], 2); ?></td>
                            <td style="padding: 10px 15px; color: #666;"><?php echo htmlspecialchars($w['notes'] ?: '-'); ?></td>
                            <td style="padding: 10px 15px;">
                                <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا السحب؟ سيُعكَس قيده المحاسبي بالكامل.');" style="display:inline;">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="delete_withdrawal" value="1">
                                    <input type="hidden" name="withdrawal_id" value="<?php echo $w['id']; ?>">
                                    <button type="submit" style="background: #fdecea; color: #e74a3b; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">حذف</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" style="padding: 30px; text-align: center; color: #999;">لا توجد سحوبات مسجَّلة بعد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>