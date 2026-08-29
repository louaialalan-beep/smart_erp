<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-12-31');
$drill_account = intval($_GET['account_id'] ?? 0);

// ميزان المراجعة: رصيد كل حساب = مجموع مدين - مجموع دائن ضمن الفترة
$stmt = $conn->prepare("
    SELECT a.id, a.account_code, a.account_name, a.account_type,
           COALESCE(SUM(j.debit), 0) AS total_debit,
           COALESCE(SUM(j.credit), 0) AS total_credit
    FROM accounts a
    LEFT JOIN journal_entries j ON j.account_id = a.id AND j.entry_date BETWEEN ? AND ?
    GROUP BY a.id, a.account_code, a.account_name, a.account_type
    ORDER BY a.account_code ASC
");
$stmt->execute([$start_date, $end_date]);
$ledger_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grand_debit = 0; $grand_credit = 0;
foreach ($ledger_rows as $r) { $grand_debit += $r['total_debit']; $grand_credit += $r['total_credit']; }

// تفاصيل حركات حساب واحد (عند الضغط على "تفاصيل")
$account_lines = [];
$account_info = null;
if ($drill_account > 0) {
    $stmt_info = $conn->prepare("SELECT * FROM accounts WHERE id = ?");
    $stmt_info->execute([$drill_account]);
    $account_info = $stmt_info->fetch(PDO::FETCH_ASSOC);

    $stmt_lines = $conn->prepare("SELECT * FROM journal_entries WHERE account_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date ASC, id ASC");
    $stmt_lines->execute([$drill_account, $start_date, $end_date]);
    $account_lines = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);
}

$type_labels = ['Asset' => 'أصول', 'Liability' => 'خصوم', 'Equity' => 'حقوق ملكية', 'Revenue' => 'إيرادات', 'Expense' => 'مصروفات'];
?>
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <h2><i class="fas fa-book"></i> دفتر الأستاذ العام وميزان المراجعة</h2>
</div>

<div style="background:white; padding:15px 20px; border-radius:8px; border:1px solid #e3e6f0; margin-bottom:20px;">
    <form method="GET" style="display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap;">
        <div><label style="display:block; font-size:12px; font-weight:bold;">من تاريخ:</label><input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" style="padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <div><label style="display:block; font-size:12px; font-weight:bold;">إلى تاريخ:</label><input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" style="padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <button type="submit" style="background:#4e73df; color:white; border:none; padding:8px 18px; border-radius:6px; cursor:pointer; font-weight:bold;">تطبيق</button>
    </form>
</div>

<?php if ($account_info): ?>
<!-- تفاصيل حركات الحساب المختار -->
<div style="background:white; border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-bottom:25px;">
    <div style="background:#f8f9fc; padding:15px 20px; border-bottom:1px solid #e3e6f0; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0; color:#4e73df;">حركات حساب: <?php echo htmlspecialchars($account_info['account_code'] . ' - ' . $account_info['account_name']); ?></h3>
        <a href="ledger.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" style="color:#666; font-size:13px;">إغلاق التفاصيل ✕</a>
    </div>
    <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:right;">
        <thead>
            <tr style="background:#fdfdfe; border-bottom:2px solid #e3e6f0;">
                <th style="padding:10px;">التاريخ</th><th style="padding:10px;">رقم القيد</th><th style="padding:10px;">البيان</th>
                <th style="padding:10px;">مدين</th><th style="padding:10px;">دائن</th><th style="padding:10px;">الرصيد التراكمي</th>
            </tr>
        </thead>
        <tbody>
            <?php $running = 0; foreach ($account_lines as $line):
                $running += floatval($line['debit']) - floatval($line['credit']);
            ?>
                <tr style="border-bottom:1px solid #f1f1f1;">
                    <td style="padding:8px; font-family:monospace;"><?php echo htmlspecialchars($line['entry_date']); ?></td>
                    <td style="padding:8px; font-family:monospace; color:#4e73df;"><?php echo htmlspecialchars($line['entry_number'] ?? ''); ?></td>
                    <td style="padding:8px;"><?php echo htmlspecialchars($line['description'] ?? ''); ?></td>
                    <td style="padding:8px; color:#1cc88a; font-family:monospace;"><?php echo $line['debit'] > 0 ? number_format($line['debit'], 2) : '-'; ?></td>
                    <td style="padding:8px; color:#e74a3b; font-family:monospace;"><?php echo $line['credit'] > 0 ? number_format($line['credit'], 2) : '-'; ?></td>
                    <td style="padding:8px; font-weight:bold; font-family:monospace;"><?php echo number_format($running, 2); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($account_lines) == 0): ?>
                <tr><td colspan="6" style="padding:20px; text-align:center; color:#777;">لا توجد حركات لهذا الحساب ضمن الفترة المحددة.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ميزان المراجعة -->
<div style="background:white; border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
    <table style="width:100%; border-collapse:collapse; font-size:14px; text-align:right;">
        <thead>
            <tr style="background:#f8f9fc; color:#4e73df; border-bottom:2px solid #e3e6f0;">
                <th style="padding:10px 15px;">الرمز</th><th style="padding:10px 15px;">اسم الحساب</th><th style="padding:10px 15px;">النوع</th>
                <th style="padding:10px 15px;">إجمالي مدين</th><th style="padding:10px 15px;">إجمالي دائن</th><th style="padding:10px 15px;">الرصيد الصافي</th><th style="padding:10px 15px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ledger_rows as $r):
                $net = $r['total_debit'] - $r['total_credit'];
                if ($r['total_debit'] == 0 && $r['total_credit'] == 0) continue; // إخفاء الحسابات بلا أي حركة لتقليل الضجيج
            ?>
                <tr style="border-bottom:1px solid #f1f1f1;">
                    <td style="padding:10px 15px; font-family:monospace; color:#4e73df; font-weight:bold;"><?php echo htmlspecialchars($r['account_code'] ?: '—'); ?></td>
                    <td style="padding:10px 15px;"><?php echo htmlspecialchars($r['account_name']); ?></td>
                    <td style="padding:10px 15px; font-size:12px; color:#666;"><?php echo htmlspecialchars($type_labels[$r['account_type']] ?? ($r['account_type'] ?: '—')); ?></td>
                    <td style="padding:10px 15px; font-family:monospace; color:#1cc88a;"><?php echo number_format($r['total_debit'], 2); ?></td>
                    <td style="padding:10px 15px; font-family:monospace; color:#e74a3b;"><?php echo number_format($r['total_credit'], 2); ?></td>
                    <td style="padding:10px 15px; font-family:monospace; font-weight:bold; color:<?php echo $net >= 0 ? '#2e59d9' : '#e74a3b'; ?>;"><?php echo number_format($net, 2); ?></td>
                    <td style="padding:10px 15px;"><a href="ledger.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&account_id=<?php echo $r['id']; ?>" style="color:#4e73df; font-size:12px; font-weight:bold;">تفاصيل</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f8f9fc; border-top:2px solid #e3e6f0; font-weight:bold;">
                <td colspan="3" style="padding:10px 15px;">الإجمالي</td>
                <td style="padding:10px 15px; color:#1cc88a;"><?php echo number_format($grand_debit, 2); ?></td>
                <td style="padding:10px 15px; color:#e74a3b;"><?php echo number_format($grand_credit, 2); ?></td>
                <td colspan="2" style="padding:10px 15px;">
                    <?php if (round($grand_debit, 2) === round($grand_credit, 2)): ?>
                        <span style="background:#d4edda; color:#155724; padding:3px 10px; border-radius:4px; font-size:12px;">ميزان متوازن ✓</span>
                    <?php else: ?>
                        <span style="background:#f8d7da; color:#721c24; padding:3px 10px; border-radius:4px; font-size:12px;">فرق: <?php echo number_format($grand_debit - $grand_credit, 2); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>
<?php include 'footer.php'; ?>