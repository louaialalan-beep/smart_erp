<?php
/**
 * أداة تصحيح سعر الصرف لكل دفعات الموردين في يوم واحد - Smart ERP
 * ------------------------------------------------------------
 * الغرض: عند اكتشاف أن سعر الصرف المُستخدَم فعلياً عند ترحيل دفعات يوم معيّن كان خاطئاً (كحالة
 * 2026-09-15 التي أظهرت سعراً ثابتاً 135.00 بينما السعر الحقيقي الموحَّد لذلك اليوم هو 134.33)،
 * تعيد هذه الأداة ترحيل **كل** دفعات ذلك اليوم (لكل الموردين معاً) بالسعر الصحيح الجديد، مع عكس
 * القيود القديمة بالكامل (بغض النظر عن عدد أطرافها: طرفان أو ثلاثة إن كان قد رُحِّل فرق صرف).
 *
 * تُعيد استخدام نفس منهجية الترحيل ثلاثي الأطراف المعتمدة في supplier_view.php بالضبط (تسوية الذمم
 * بسعرها التاريخي المرجَّح + فرق الصرف الحقيقي + الصندوق بالسعر المصحَّح الجديد)، فتبقى كل الحسابات
 * متّسقة تماماً بعد التصحيح.
 */
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

if (!isset($conn)) { die("خطأ: اتصال قاعدة البيانات غير متوفر."); }
requireRole($conn, ['admin', 'accountant']);

$msg = '';
$error = '';
$preview = [];
$target_date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');
$new_rate = floatval($_GET['new_rate'] ?? $_POST['new_rate'] ?? 0);

// عرض معاينة: كل دفعات ذلك اليوم، بسعرها الحالي المُرحَّل فعلياً، والفرق المتوقَّع بعد التصحيح
$stmt_preview = $conn->prepare("
    SELECT sp.id, sp.amount_usd, sp.notes, s.supplier_name,
        (SELECT je.credit FROM journal_entries je INNER JOIN accounts a ON je.account_id = a.id
         WHERE je.entry_number = CONCAT('JE-SPAY-', sp.id) AND a.account_name LIKE '%صندوق الرئيسي%' AND je.credit > 0
         LIMIT 1) AS current_syp
    FROM supplier_payments sp
    INNER JOIN suppliers s ON sp.supplier_id = s.id
    WHERE sp.payment_date = ?
    ORDER BY s.supplier_name, sp.id
");
$stmt_preview->execute([$target_date]);
$payments_for_date = $stmt_preview->fetchAll(PDO::FETCH_ASSOC);

foreach ($payments_for_date as $p) {
    $current_syp = floatval($p['current_syp']);
    $new_syp = floatval($p['amount_usd']) * $new_rate;
    $preview[] = [
        'id' => $p['id'], 'supplier' => $p['supplier_name'], 'usd' => floatval($p['amount_usd']),
        'current_syp' => $current_syp, 'new_syp' => $new_syp, 'diff' => $new_syp - $current_syp, 'notes' => $p['notes'],
    ];
}
$total_current = array_sum(array_column($preview, 'current_syp'));
$total_new = array_sum(array_column($preview, 'new_syp'));

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply_fix'])) {
    verifyCsrfToken();
    if ($new_rate <= 0) {
        $error = "يرجى إدخال سعر صرف صحيح أكبر من صفر.";
    } elseif (isDateInClosedPeriod($conn, $target_date)) {
        $error = getPeriodLockErrorMessage($target_date);
    } elseif (count($payments_for_date) === 0) {
        $error = "لا توجد أي دفعات موردين بتاريخ $target_date.";
    } else {
        try {
            $conn->beginTransaction();
            $fixed_count = 0;

            foreach ($payments_for_date as $p) {
                $payment_id = intval($p['id']);
                $amount_usd = floatval($p['amount_usd']);

                // جلب الحسابات الثلاثة المحتملة من القيد الحالي (ذمم/فرق صرف/صندوق) لمعرفة أي حسابات استُخدمت فعلاً
                $stmt_old = $conn->prepare("SELECT account_id, debit, credit FROM journal_entries WHERE entry_number = ?");
                $stmt_old->execute(["JE-SPAY-" . $payment_id]);
                $old_lines = $stmt_old->fetchAll(PDO::FETCH_ASSOC);
                if (count($old_lines) === 0) { continue; } // لا قيد فعلي لهذه الدفعة (بيانات قديمة تالفة) — تخطَّ بأمان

                // حذف القيد القديم بالكامل (تصحيح بيانات، وليس تسوية محاسبية لاحقة — لا حاجة لعكس توثيقي)
                $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ?")->execute(["JE-SPAY-" . $payment_id]);

                // إعادة الترحيل بنفس منهجية supplier_view.php ثلاثية الأطراف بالضبط
                $stmt_sname = $conn->prepare("SELECT s.supplier_name, sp.supplier_id FROM supplier_payments sp INNER JOIN suppliers s ON sp.supplier_id = s.id WHERE sp.id = ?");
                $stmt_sname->execute([$payment_id]);
                $srow = $stmt_sname->fetch(PDO::FETCH_ASSOC);
                $supplier_id_p = intval($srow['supplier_id']);
                $supplier_name_p = $srow['supplier_name'];

                $stmt_hist = $conn->prepare("
                    SELECT COALESCE(SUM(total_amount_usd * exchange_rate), 0) AS weighted_syp, COALESCE(SUM(total_amount_usd), 0) AS total_usd
                    FROM purchase_invoices WHERE supplier_id = ? AND payment_status != 'Paid'
                ");
                $stmt_hist->execute([$supplier_id_p]);
                $hist_row = $stmt_hist->fetch(PDO::FETCH_ASSOC);
                $historical_rate = (floatval($hist_row['total_usd']) > 0.009) ? (floatval($hist_row['weighted_syp']) / floatval($hist_row['total_usd'])) : $new_rate;

                $liability_base = $amount_usd * $historical_rate;
                $cash_base = $amount_usd * $new_rate;
                $fx_diff = $cash_base - $liability_base;

                $debit_acc = findOrCreateAccount($conn, ['ذمم الموردين', 'موردون'], 'ذمم الموردين', 'Liability');
                $credit_acc = null;
                $stmt_cash = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE '%صندوق الرئيسي%' LIMIT 1");
                $stmt_cash->execute();
                $credit_acc = $stmt_cash->fetchColumn();

                $entry_num = "JE-SPAY-" . $payment_id;
                $desc = "سداد دفعة نقدية للمورد: " . $supplier_name_p . " (سعر مُصحَّح إلى " . number_format($new_rate, 2) . ")";

                $stmt_cols2 = $conn->query("SHOW COLUMNS FROM journal_entries")->fetchAll(PDO::FETCH_COLUMN);
                $insertLine = function ($account_id, $f_debit, $f_credit, $b_debit, $b_credit) use ($conn, $stmt_cols2, $entry_num, $target_date, $desc, $new_rate) {
                    $cols = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                    $vals = [$account_id, $target_date, $desc, $b_debit, $b_credit];
                    if (in_array('entry_number', $stmt_cols2)) { $cols[] = 'entry_number'; $vals[] = $entry_num; }
                    if (in_array('currency_code', $stmt_cols2)) { $cols[] = 'currency_code'; $vals[] = 'USD'; }
                    if (in_array('exchange_rate', $stmt_cols2)) { $cols[] = 'exchange_rate'; $vals[] = $new_rate; }
                    if (in_array('foreign_debit', $stmt_cols2)) { $cols[] = 'foreign_debit'; $vals[] = $f_debit; }
                    if (in_array('foreign_credit', $stmt_cols2)) { $cols[] = 'foreign_credit'; $vals[] = $f_credit; }
                    if (in_array('source_module', $stmt_cols2)) { $cols[] = 'source_module'; $vals[] = 'Supplier Payment'; }
                    $ph = implode(',', array_fill(0, count($cols), '?'));
                    $conn->prepare("INSERT INTO journal_entries (" . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);
                };

                if ($debit_acc && $credit_acc) {
                    $insertLine($debit_acc, $amount_usd, 0, $liability_base, 0);
                    if (abs($fx_diff) > 0.5) {
                        if ($fx_diff > 0) {
                            $fx_loss_acc = findOrCreateAccount($conn, ['خسارة فروقات', 'فروقات العملة'], 'خسارة فروقات العملة', 'Expense');
                            if ($fx_loss_acc) { $insertLine($fx_loss_acc, 0, 0, $fx_diff, 0); }
                        } else {
                            $fx_gain_acc = findOrCreateAccount($conn, ['أرباح فروقات', 'ربح فروقات العملة'], 'أرباح فروقات العملة', 'Revenue');
                            if ($fx_gain_acc) { $insertLine($fx_gain_acc, 0, 0, 0, abs($fx_diff)); }
                        }
                    }
                    $insertLine($credit_acc, 0, $amount_usd, 0, $cash_base);
                    $fixed_count++;
                }
            }

            $conn->commit();
            logAudit($conn, 'UPDATE', 'مدفوعات الموردين', "تصحيح سعر صرف $fixed_count دفعة بتاريخ $target_date إلى " . number_format($new_rate, 4));
            $msg = "تم تصحيح $fixed_count دفعة بتاريخ $target_date بسعر صرف موحَّد " . number_format($new_rate, 4) . " بنجاح.";

            // تحديث المعاينة بعد التصحيح
            $stmt_preview->execute([$target_date]);
            $payments_for_date = $stmt_preview->fetchAll(PDO::FETCH_ASSOC);
            $preview = [];
            foreach ($payments_for_date as $p) {
                $current_syp = floatval($p['current_syp']);
                $preview[] = ['id' => $p['id'], 'supplier' => $p['supplier_name'], 'usd' => floatval($p['amount_usd']), 'current_syp' => $current_syp, 'new_syp' => $current_syp, 'diff' => 0, 'notes' => $p['notes']];
            }
            $total_current = array_sum(array_column($preview, 'current_syp'));
            $total_new = $total_current;
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ أثناء التصحيح: " . $e->getMessage();
        }
    }
}
?>

<div style="padding: 20px;">
    <h2 style="margin: 0 0 5px;"><i class="fas fa-wrench"></i> تصحيح سعر الصرف لدفعات يوم كامل</h2>
    <p style="color: #666; margin: 0 0 20px; font-size: 14px;">يُعيد ترحيل كل دفعات الموردين (لكل الموردين معاً) بتاريخ محدَّد باستخدام سعر صرف صحيح موحَّد، مع إعادة حساب فرق الصرف بدقة. استخدمها عند اكتشاف أن السعر المُستخدَم فعلياً وقت الترحيل كان خاطئاً.</p>

    <?php if ($error): ?><div style="background: #fdecea; color: #a33636; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($msg): ?><div style="background: #e8f8f2; color: #1a7a5e; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px;">
        <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <label style="font-size: 13px; font-weight: bold; color: #555;">التاريخ:</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($target_date); ?>" style="padding: 8px; border: 1px solid #ccc; border-radius: 5px; font-family: monospace;">
            <label style="font-size: 13px; font-weight: bold; color: #555;">السعر الصحيح الموحَّد:</label>
            <input type="number" step="0.0001" name="new_rate" value="<?php echo $new_rate > 0 ? htmlspecialchars($new_rate) : ''; ?>" placeholder="مثال: 134.33" style="padding: 8px; border: 1px solid #ccc; border-radius: 5px; font-family: monospace; width: 150px;">
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 18px; border-radius: 5px; cursor: pointer; font-weight: bold;"><i class="fas fa-search"></i> معاينة</button>
        </form>
    </div>

    <?php if (count($preview) > 0): ?>
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; margin-bottom: 20px;">
        <div style="background: #f8f9fc; padding: 12px 20px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #4e73df;">معاينة: <?php echo count($preview); ?> دفعة بتاريخ <?php echo htmlspecialchars($target_date); ?></div>
        <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 8px 15px;">المورد</th>
                    <th style="padding: 8px 15px;">المبلغ (USD)</th>
                    <th style="padding: 8px 15px;">الناتج الحالي (SYP)</th>
                    <th style="padding: 8px 15px;">الناتج بعد التصحيح (SYP)</th>
                    <th style="padding: 8px 15px;">الفرق</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview as $row): ?>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 8px 15px;"><?php echo htmlspecialchars($row['supplier']); ?></td>
                    <td style="padding: 8px 15px; font-family: monospace;">$<?php echo number_format($row['usd'], 2); ?></td>
                    <td style="padding: 8px 15px; font-family: monospace;"><?php echo number_format($row['current_syp'], 2); ?></td>
                    <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color: #2e59d9;"><?php echo number_format($row['new_syp'], 2); ?></td>
                    <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color: <?php echo $row['diff'] > 0 ? '#e74a3b' : ($row['diff'] < 0 ? '#1cc88a' : '#999'); ?>;"><?php echo ($row['diff'] > 0 ? '+' : '') . number_format($row['diff'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8f9fc; border-top: 2px solid #e3e6f0; font-weight: bold;">
                    <td colspan="2" style="padding: 8px 15px;">الإجمالي</td>
                    <td style="padding: 8px 15px; font-family: monospace;"><?php echo number_format($total_current, 2); ?></td>
                    <td style="padding: 8px 15px; font-family: monospace; color: #2e59d9;"><?php echo number_format($total_new, 2); ?></td>
                    <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color: <?php echo ($total_new - $total_current) > 0 ? '#e74a3b' : '#1cc88a'; ?>;"><?php echo number_format($total_new - $total_current, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($new_rate > 0): ?>
    <form method="POST" onsubmit="return confirm('سيُعاد ترحيل كل الدفعات أعلاه بالسعر الجديد نهائياً. لا يمكن التراجع تلقائياً بعد الحفظ. متابعة؟');">
        <?php csrfField(); ?>
        <input type="hidden" name="apply_fix" value="1">
        <input type="hidden" name="date" value="<?php echo htmlspecialchars($target_date); ?>">
        <input type="hidden" name="new_rate" value="<?php echo htmlspecialchars($new_rate); ?>">
        <button type="submit" style="background: #e74a3b; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px;">
            <i class="fas fa-check-double"></i> تطبيق التصحيح على كل الدفعات أعلاه
        </button>
    </form>
    <?php endif; ?>
    <?php elseif ($target_date): ?>
        <div style="background: #f8f9fc; color: #777; padding: 20px; border-radius: 6px; text-align: center;">لا توجد دفعات موردين مسجَّلة بتاريخ <?php echo htmlspecialchars($target_date); ?>.</div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>