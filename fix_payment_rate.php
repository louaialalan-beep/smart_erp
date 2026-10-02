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

                // جلب الحسابات الثلاثة المحتملة من القيد الحالي (ذمم/فرق صرف/صندوق) — بحث موسَّع بعد
                // اكتشاف خلل حقيقي: قد لا يكون الاسم المجرَّد هو القيد النشط فعلياً إن كانت الدفعة قد
                // عُدِّلت مسبقاً (عبر هذه الأداة نفسها أو أداة "تعديل دفعة محددة" أدناه)، فيحمل القيد
                // النشط لاحقة "-CORR-<توقيت>" بدلاً من الاسم المجرَّد. تجاهل هذا كان يعني تخطّي الدفعة
                // بصمت بدل تصحيحها، لا خطراً مباشراً، لكنه يمنع إعادة التصحيح الصحيحة.
                $stmt_old = $conn->prepare("SELECT entry_number, account_id, debit, credit FROM journal_entries WHERE entry_number = ? OR entry_number LIKE ? ORDER BY id DESC");
                $stmt_old->execute(["JE-SPAY-" . $payment_id, "JE-SPAY-" . $payment_id . "-%"]);
                $old_lines_all = $stmt_old->fetchAll(PDO::FETCH_ASSOC);
                if (count($old_lines_all) === 0) { continue; } // لا قيد فعلي لهذه الدفعة (بيانات قديمة تالفة) — تخطَّ بأمان
                // نحصر الحذف/القراءة على القيد الفعلي النشط الأحدث فقط (بأي اسم كان)، لا كل تاريخ التصحيحات
                $active_entry_number = $old_lines_all[0]['entry_number'];
                $old_lines = array_values(array_filter($old_lines_all, function ($l) use ($active_entry_number) { return $l['entry_number'] === $active_entry_number; }));

                // حذف القيد النشط الفعلي بالكامل (تصحيح بيانات، وليس تسوية محاسبية لاحقة — لا حاجة لعكس توثيقي)
                $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ?")->execute([$active_entry_number]);

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
// ============================================================
// تعديل دفعة واحدة محددة — بناءً على طلب صريح من المستخدم، بجانب التصحيح الجماعي بالتاريخ أعلاه.
// يسمح بالبحث عن دفعة مورد واحدة (بمعرّفها أو باسم المورد) وتعديل مبلغها/تاريخها/سعر صرفها فرادى،
// بنفس منهجية الترحيل ثلاثية الأطراف (تسوية الذمم بالسعر التاريخي المرجَّح + فرق الصرف + الصندوق).
// ============================================================
$single_search = trim($_GET['single_search'] ?? '');
$single_payment_id = intval($_GET['single_payment_id'] ?? $_POST['payment_id'] ?? 0);
$single_results = [];
$single_selected = null;
$single_msg = '';
$single_error = '';

if ($single_search !== '') {
    $stmt_single_search = $conn->prepare("
        SELECT sp.id, sp.payment_date, sp.amount_usd, sp.notes, s.supplier_name,
            (SELECT je.credit FROM journal_entries je INNER JOIN accounts a ON je.account_id = a.id
             WHERE je.entry_number = CONCAT('JE-SPAY-', sp.id) AND a.account_name LIKE '%صندوق الرئيسي%' AND je.credit > 0
             LIMIT 1) AS current_syp
        FROM supplier_payments sp
        INNER JOIN suppliers s ON sp.supplier_id = s.id
        WHERE s.supplier_name LIKE ? OR sp.id = ? OR sp.notes LIKE ?
        ORDER BY sp.payment_date DESC LIMIT 30
    ");
    $stmt_single_search->execute(['%' . $single_search . '%', intval($single_search), '%' . $single_search . '%']);
    $single_results = $stmt_single_search->fetchAll(PDO::FETCH_ASSOC);
}

if ($single_payment_id > 0) {
    $stmt_single_sel = $conn->prepare("
        SELECT sp.*, s.supplier_name,
            (SELECT je.credit FROM journal_entries je INNER JOIN accounts a ON je.account_id = a.id
             WHERE je.entry_number = CONCAT('JE-SPAY-', sp.id) AND a.account_name LIKE '%صندوق الرئيسي%' AND je.credit > 0
             LIMIT 1) AS current_syp,
            (SELECT je.exchange_rate FROM journal_entries je INNER JOIN accounts a ON je.account_id = a.id
             WHERE je.entry_number = CONCAT('JE-SPAY-', sp.id) AND a.account_name LIKE '%صندوق الرئيسي%' AND je.credit > 0
             LIMIT 1) AS current_rate
        FROM supplier_payments sp INNER JOIN suppliers s ON sp.supplier_id = s.id WHERE sp.id = ?
    ");
    $stmt_single_sel->execute([$single_payment_id]);
    $single_selected = $stmt_single_sel->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply_single_fix'])) {
    verifyCsrfToken();
    requireRole($conn, ['admin', 'accountant']);
    $edit_amount_usd = floatval($_POST['edit_amount_usd'] ?? 0);
    $edit_date = $_POST['edit_date'] ?? '';
    $edit_rate = floatval($_POST['edit_rate'] ?? 0);
    $edit_notes = trim($_POST['edit_notes'] ?? '');

    if ($single_payment_id <= 0) {
        $single_error = "لم تُحدَّد أي دفعة للتعديل.";
    } elseif ($edit_amount_usd <= 0) {
        $single_error = "المبلغ يجب أن يكون أكبر من صفر.";
    } elseif ($edit_rate <= 0) {
        $single_error = "سعر الصرف يجب أن يكون أكبر من صفر.";
    } elseif (empty($edit_date)) {
        $single_error = "التاريخ مطلوب.";
    } elseif (isDateInClosedPeriod($conn, $edit_date)) {
        $single_error = getPeriodLockErrorMessage($edit_date);
    } else {
        try {
            $conn->beginTransaction();

            $stmt_p = $conn->prepare("SELECT sp.*, s.supplier_name FROM supplier_payments sp INNER JOIN suppliers s ON sp.supplier_id = s.id WHERE sp.id = ?");
            $stmt_p->execute([$single_payment_id]);
            $p_row = $stmt_p->fetch(PDO::FETCH_ASSOC);
            if (!$p_row) { throw new Exception("الدفعة غير موجودة."); }

            $conn->prepare("UPDATE supplier_payments SET amount_usd = ?, payment_date = ?, notes = ? WHERE id = ?")
                 ->execute([$edit_amount_usd, $edit_date, $edit_notes, $single_payment_id]);

            // تصحيح جوهري لخلل حقيقي مكتشَف بالبيانات الفعلية: الاسم المجرَّد "JE-SPAY-<id>" قد لا يكون
            // القيد النشط فعلياً إن كانت هذه الدفعة قد عُدِّلت مسبقاً عبر أداة التصحيح الجماعي أعلاه (تترك
            // قيداً نشطاً بلاحقة "-CORR-<توقيت>" بدل الاسم المجرَّد). حذف الاسم المجرَّد فقط كان يترك ذلك
            // القيد قائماً فيُضاف قيد جديد فوقه = ازدواج حقيقي في خصم الصندوق. نحذف الآن كل قيد يخص هذه
            // الدفعة تحديداً بأي لاحقة كانت (حدّ الفاصلة "-" يمنع تطابق معرّفات أخرى تبدأ بنفس الأرقام).
            $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ? OR entry_number LIKE ?")
                 ->execute(["JE-SPAY-" . $single_payment_id, "JE-SPAY-" . $single_payment_id . "-%"]);

            $supplier_id_p = intval($p_row['supplier_id']);
            $supplier_name_p = $p_row['supplier_name'];

            $stmt_hist = $conn->prepare("
                SELECT COALESCE(SUM(total_amount_usd * exchange_rate), 0) AS weighted_syp, COALESCE(SUM(total_amount_usd), 0) AS total_usd
                FROM purchase_invoices WHERE supplier_id = ? AND payment_status != 'Paid'
            ");
            $stmt_hist->execute([$supplier_id_p]);
            $hist_row = $stmt_hist->fetch(PDO::FETCH_ASSOC);
            $historical_rate = (floatval($hist_row['total_usd']) > 0.009) ? (floatval($hist_row['weighted_syp']) / floatval($hist_row['total_usd'])) : $edit_rate;

            $liability_base = $edit_amount_usd * $historical_rate;
            $cash_base = $edit_amount_usd * $edit_rate;
            $fx_diff = $cash_base - $liability_base;

            $debit_acc = findOrCreateAccount($conn, ['ذمم الموردين', 'موردون'], 'ذمم الموردين', 'Liability');
            $stmt_cash = $conn->prepare("SELECT id FROM accounts WHERE account_name LIKE '%صندوق الرئيسي%' LIMIT 1");
            $stmt_cash->execute();
            $credit_acc = $stmt_cash->fetchColumn();

            $entry_num = "JE-SPAY-" . $single_payment_id;
            $desc = "سداد دفعة نقدية للمورد: " . $supplier_name_p . " (تعديل يدوي لدفعة محددة)" . (!empty($edit_notes) ? " — " . $edit_notes : "");

            $stmt_cols3 = $conn->query("SHOW COLUMNS FROM journal_entries")->fetchAll(PDO::FETCH_COLUMN);
            $insertLineSingle = function ($account_id, $f_debit, $f_credit, $b_debit, $b_credit) use ($conn, $stmt_cols3, $entry_num, $edit_date, $desc, $edit_rate) {
                $cols = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                $vals = [$account_id, $edit_date, $desc, $b_debit, $b_credit];
                if (in_array('entry_number', $stmt_cols3)) { $cols[] = 'entry_number'; $vals[] = $entry_num; }
                if (in_array('currency_code', $stmt_cols3)) { $cols[] = 'currency_code'; $vals[] = 'USD'; }
                if (in_array('exchange_rate', $stmt_cols3)) { $cols[] = 'exchange_rate'; $vals[] = $edit_rate; }
                if (in_array('foreign_debit', $stmt_cols3)) { $cols[] = 'foreign_debit'; $vals[] = $f_debit; }
                if (in_array('foreign_credit', $stmt_cols3)) { $cols[] = 'foreign_credit'; $vals[] = $f_credit; }
                if (in_array('source_module', $stmt_cols3)) { $cols[] = 'source_module'; $vals[] = 'Supplier Payment'; }
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $conn->prepare("INSERT INTO journal_entries (" . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);
            };

            if ($debit_acc && $credit_acc) {
                $insertLineSingle($debit_acc, $edit_amount_usd, 0, $liability_base, 0);
                if (abs($fx_diff) > 0.5) {
                    if ($fx_diff > 0) {
                        $fx_loss_acc = findOrCreateAccount($conn, ['خسارة فروقات', 'فروقات العملة'], 'خسارة فروقات العملة', 'Expense');
                        if ($fx_loss_acc) { $insertLineSingle($fx_loss_acc, 0, 0, $fx_diff, 0); }
                    } else {
                        $fx_gain_acc = findOrCreateAccount($conn, ['أرباح فروقات', 'ربح فروقات العملة'], 'أرباح فروقات العملة', 'Revenue');
                        if ($fx_gain_acc) { $insertLineSingle($fx_gain_acc, 0, 0, 0, abs($fx_diff)); }
                    }
                }
                $insertLineSingle($credit_acc, 0, $edit_amount_usd, 0, $cash_base);
            }

            $conn->commit();
            logAudit($conn, 'UPDATE', 'مدفوعات الموردين', "تعديل يدوي لدفعة رقم $single_payment_id للمورد $supplier_name_p — المبلغ الجديد: $" . number_format($edit_amount_usd, 2), $single_payment_id);
            $single_msg = "تم تعديل الدفعة بنجاح.";

            $stmt_single_sel->execute([$single_payment_id]);
            $single_selected = $stmt_single_sel->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $single_error = "خطأ أثناء التعديل: " . $e->getMessage();
        }
    }
}
?>

<div style="padding: 20px;">
    <h2 style="margin: 0 0 5px;"><i class="fas fa-edit"></i> تعديل دفعة مورد محددة</h2>
    <p style="color: #666; margin: 0 0 20px; font-size: 14px;">ابحث عن دفعة واحدة (بمعرّفها، اسم المورد، أو نص الملاحظات) وعدِّل مبلغها/تاريخها/سعر صرفها منفردة، بنفس منهجية الترحيل ثلاثية الأطراف (ذمم بالسعر التاريخي المرجَّح + فرق صرف + صندوق).</p>

    <?php if ($single_error): ?><div style="background: #fdecea; color: #a33636; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($single_error); ?></div><?php endif; ?>
    <?php if ($single_msg): ?><div style="background: #e8f8f2; color: #1a7a5e; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($single_msg); ?></div><?php endif; ?>

    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px;">
        <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <label style="font-size: 13px; font-weight: bold; color: #555;">بحث (معرّف / اسم مورد / ملاحظة):</label>
            <input type="text" name="single_search" value="<?php echo htmlspecialchars($single_search); ?>" placeholder="مثال: شركة الشاهين" style="padding: 8px; border: 1px solid #ccc; border-radius: 5px; min-width: 220px;">
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 18px; border-radius: 5px; cursor: pointer; font-weight: bold;"><i class="fas fa-search"></i> بحث</button>
        </form>
    </div>

    <?php if (count($single_results) > 0): ?>
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; margin-bottom: 20px;">
        <div style="background: #f8f9fc; padding: 12px 20px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #4e73df;">نتائج البحث (<?php echo count($single_results); ?>)</div>
        <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 8px 15px;">#</th>
                    <th style="padding: 8px 15px;">المورد</th>
                    <th style="padding: 8px 15px;">التاريخ</th>
                    <th style="padding: 8px 15px;">المبلغ (USD)</th>
                    <th style="padding: 8px 15px;">الناتج الحالي (SYP)</th>
                    <th style="padding: 8px 15px;">ملاحظات</th>
                    <th style="padding: 8px 15px;">إجراء</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($single_results as $r): ?>
                <tr style="border-bottom: 1px solid #f1f1f1; <?php echo $single_payment_id == $r['id'] ? 'background:#eef4ff;' : ''; ?>">
                    <td style="padding: 8px 15px; font-family: monospace;">#<?php echo intval($r['id']); ?></td>
                    <td style="padding: 8px 15px;"><?php echo htmlspecialchars($r['supplier_name']); ?></td>
                    <td style="padding: 8px 15px; font-family: monospace;"><?php echo htmlspecialchars($r['payment_date']); ?></td>
                    <td style="padding: 8px 15px; font-family: monospace;">$<?php echo number_format($r['amount_usd'], 2); ?></td>
                    <td style="padding: 8px 15px; font-family: monospace;"><?php echo number_format(floatval($r['current_syp']), 2); ?></td>
                    <td style="padding: 8px 15px; color: #777; font-size: 12px;"><?php echo htmlspecialchars($r['notes'] ?? ''); ?></td>
                    <td style="padding: 8px 15px;">
                        <a href="?single_search=<?php echo urlencode($single_search); ?>&single_payment_id=<?php echo intval($r['id']); ?>" style="background: #f6c23e; color: #fff; padding: 5px 14px; border-radius: 4px; text-decoration: none; font-size: 12.5px; font-weight: bold;">تعديل</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($single_search !== ''): ?>
        <div style="background: #f8f9fc; color: #777; padding: 20px; border-radius: 6px; text-align: center; margin-bottom: 20px;">لا توجد أي دفعة مطابقة لبحثك.</div>
    <?php endif; ?>

    <?php if ($single_selected): ?>
    <div style="background: #fff; border: 1px solid #f6c23e; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
        <h3 style="margin: 0 0 15px; color: #96751c; font-size: 15px;"><i class="fas fa-pen"></i> تعديل الدفعة #<?php echo intval($single_selected['id']); ?> — <?php echo htmlspecialchars($single_selected['supplier_name']); ?></h3>
        <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; align-items: end;">
            <?php csrfField(); ?>
            <input type="hidden" name="apply_single_fix" value="1">
            <input type="hidden" name="payment_id" value="<?php echo intval($single_selected['id']); ?>">
            <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">المبلغ (USD):</label>
                <input type="number" step="0.01" name="edit_amount_usd" value="<?php echo htmlspecialchars($single_selected['amount_usd']); ?>" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:monospace;"></div>
            <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">تاريخ الدفعة:</label>
                <input type="date" name="edit_date" value="<?php echo htmlspecialchars($single_selected['payment_date']); ?>" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:monospace;"></div>
            <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">سعر الصرف المُستخدَم:</label>
                <input type="number" step="0.0001" name="edit_rate" value="<?php echo htmlspecialchars($single_selected['current_rate'] ?: ''); ?>" required placeholder="مثال: 136.50" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:monospace;"></div>
            <div style="grid-column: 1 / -1;"><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">ملاحظات:</label>
                <input type="text" name="edit_notes" value="<?php echo htmlspecialchars($single_selected['notes'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;"></div>
            <div style="grid-column: 1 / -1;">
                <button type="submit" onclick="return confirm('سيُحذَف القيد القديم لهذه الدفعة ويُعاد ترحيله بالبيانات الجديدة. متابعة؟');" style="background: #f6c23e; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px;">
                    <i class="fas fa-save"></i> حفظ التعديل
                </button>
            </div>
        </form>
        <p style="font-size: 11.5px; color: #999; margin: 12px 0 0;">القيمة الحالية المُرحَّلة بالصندوق حالياً: <?php echo number_format(floatval($single_selected['current_syp']), 2); ?> ل.س.</p>
    </div>
    <?php endif; ?>

    <hr style="border: none; border-top: 1px solid #e3e6f0; margin: 30px 0;">
</div>
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