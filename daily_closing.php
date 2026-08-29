<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

$selected_date = $_GET['date'] ?? date('Y-m-d');

// إيجاد حساب الصندوق الرئيسي (نفس منطق findAccountId في بقية النظام، بحثاً بلا إنشاء تلقائي هنا)
$cash_account_id = null;
try {
    $stmt_cols = $conn->query("SHOW COLUMNS FROM accounts");
    $cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);
    $name_col = null;
    foreach (['name', 'account_name', 'title', 'name_ar', 'acc_name'] as $c) {
        if (in_array($c, $cols)) { $name_col = $c; break; }
    }
    if ($name_col) {
        $stmt = $conn->prepare("SELECT id, `{$name_col}` AS acc_name FROM accounts WHERE `{$name_col}` LIKE '%صندوق%' OR `{$name_col}` LIKE '%نقد%' OR `{$name_col}` LIKE '%cash%' ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $cash_account_id = $row['id']; $cash_account_name = $row['acc_name']; }
    }
} catch (Exception $e) {}

$opening_balance = 0;
$day_lines = [];
$total_in = 0;
$total_out = 0;

if ($cash_account_id) {
    // الرصيد الافتتاحي = صافي كل حركات الصندوق قبل هذا اليوم
    $stmt_open = $conn->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) FROM journal_entries WHERE account_id = ? AND entry_date < ?");
    $stmt_open->execute([$cash_account_id, $selected_date]);
    $opening_balance = floatval($stmt_open->fetchColumn());

    // حركات اليوم المحدد نفسه
    $stmt_day = $conn->prepare("SELECT * FROM journal_entries WHERE account_id = ? AND entry_date = ? ORDER BY id ASC");
    $stmt_day->execute([$cash_account_id, $selected_date]);
    $day_lines = $stmt_day->fetchAll(PDO::FETCH_ASSOC);

    foreach ($day_lines as $line) {
        $total_in += floatval($line['debit']);
        $total_out += floatval($line['credit']);
    }
}

$closing_balance = $opening_balance + $total_in - $total_out;

// تصنيف الحركات حسب مصدرها (source_module) لعرض ملخص سريع أعلى الصفحة
$by_source = [];
foreach ($day_lines as $line) {
    $src = $line['source_module'] ?: 'غير مصنَّف';
    if (!isset($by_source[$src])) { $by_source[$src] = ['in' => 0, 'out' => 0]; }
    $by_source[$src]['in'] += floatval($line['debit']);
    $by_source[$src]['out'] += floatval($line['credit']);
}
$source_labels = [
    'Sales' => 'مبيعات', 'Sales Return' => 'مرتجعات مبيعات', 'Supplier Payment' => 'دفعات موردين',
    'Representative Payment' => 'دفعات مندوبين', 'Operational Expense' => 'مصاريف تشغيلية',
    'Expense Accrual' => 'استحقاق مصاريف', 'Payroll' => 'رواتب', 'Employee Advance' => 'سلف موظفين',
    'Purchase' => 'فواتير شراء', 'Manual' => 'قيود يدوية', 'غير مصنَّف' => 'غير مصنَّف',
];
?>
<style>
    @media print {
        .no-print { display: none !important; }
        body * { visibility: hidden; }
        #printArea, #printArea * { visibility: visible; }
        #printArea { position: absolute; left: 0; top: 0; width: 100%; }
    }
</style>

<div class="no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <h2><i class="fas fa-cash-register"></i> الإقفال اليومي للصندوق</h2>
    <div style="display:flex; gap:10px; align-items:center;">
        <form method="GET" style="display:flex; gap:8px; align-items:center;">
            <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" style="padding:7px; border:1px solid #ccc; border-radius:4px;">
            <button type="submit" style="background:#4e73df; color:white; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:bold;">عرض</button>
        </form>
        <button onclick="window.print()" style="background:#1cc88a; color:white; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:bold;"><i class="fas fa-print"></i> طباعة</button>
    </div>
</div>

<?php if (!$cash_account_id): ?>
    <div style="background:#fff3cd; color:#856404; padding:15px; border-radius:6px;">لم يُعثر على حساب "الصندوق" في شجرة الحسابات بعد. سجِّل أول عملية مالية (فاتورة/دفعة) ليُنشأ تلقائياً، ثم عد لهذه الصفحة.</div>
<?php else: ?>

<div id="printArea">
    <div style="text-align:center; margin-bottom:20px; display:none;" class="print-only">
        <h2>تقرير الإقفال اليومي — <?php echo htmlspecialchars($selected_date); ?></h2>
    </div>

    <!-- بطاقات الملخص -->
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:20px;">
        <div style="background:white; padding:18px; border-radius:8px; border-right:4px solid #6c757d;">
            <div style="font-size:12px; color:#888; font-weight:bold;">الرصيد الافتتاحي</div>
            <div style="font-size:20px; font-weight:bold; font-family:monospace; margin-top:5px;"><?php echo number_format($opening_balance, 2); ?> ل.س</div>
        </div>
        <div style="background:white; padding:18px; border-radius:8px; border-right:4px solid #1cc88a;">
            <div style="font-size:12px; color:#888; font-weight:bold;">إجمالي المقبوضات (مدين)</div>
            <div style="font-size:20px; font-weight:bold; font-family:monospace; color:#1cc88a; margin-top:5px;"><?php echo number_format($total_in, 2); ?> ل.س</div>
        </div>
        <div style="background:white; padding:18px; border-radius:8px; border-right:4px solid #e74a3b;">
            <div style="font-size:12px; color:#888; font-weight:bold;">إجمالي المدفوعات (دائن)</div>
            <div style="font-size:20px; font-weight:bold; font-family:monospace; color:#e74a3b; margin-top:5px;"><?php echo number_format($total_out, 2); ?> ل.س</div>
        </div>
        <div style="background:white; padding:18px; border-radius:8px; border-right:4px solid #4e73df;">
            <div style="font-size:12px; color:#888; font-weight:bold;">الرصيد الختامي المتوقع</div>
            <div style="font-size:20px; font-weight:bold; font-family:monospace; color:#4e73df; margin-top:5px;"><?php echo number_format($closing_balance, 2); ?> ل.س</div>
        </div>
    </div>

    <!-- ملخص حسب المصدر -->
    <?php if (count($by_source) > 0): ?>
    <div style="background:white; border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-bottom:20px;">
        <div style="background:#f8f9fc; padding:12px 20px; border-bottom:1px solid #e3e6f0; font-weight:bold; color:#4e73df;">ملخص حسب مصدر الحركة</div>
        <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:right;">
            <thead><tr style="background:#fdfdfe; border-bottom:1px solid #ddd;"><th style="padding:8px 15px;">المصدر</th><th style="padding:8px 15px;">مقبوضات</th><th style="padding:8px 15px;">مدفوعات</th></tr></thead>
            <tbody>
                <?php foreach ($by_source as $src => $vals): ?>
                    <tr style="border-bottom:1px solid #f1f1f1;">
                        <td style="padding:8px 15px; font-weight:bold;"><?php echo htmlspecialchars($source_labels[$src] ?? $src); ?></td>
                        <td style="padding:8px 15px; color:#1cc88a; font-family:monospace;"><?php echo $vals['in'] > 0 ? number_format($vals['in'], 2) : '-'; ?></td>
                        <td style="padding:8px 15px; color:#e74a3b; font-family:monospace;"><?php echo $vals['out'] > 0 ? number_format($vals['out'], 2) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- التفاصيل الكاملة -->
    <div style="background:white; border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
        <div style="background:#f8f9fc; padding:12px 20px; border-bottom:1px solid #e3e6f0; font-weight:bold; color:#333;">تفاصيل حركات الصندوق ليوم <?php echo htmlspecialchars($selected_date); ?></div>
        <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:right;">
            <thead>
                <tr style="background:#fdfdfe; border-bottom:2px solid #e3e6f0;">
                    <th style="padding:10px 15px;">رقم القيد</th><th style="padding:10px 15px;">المصدر</th><th style="padding:10px 15px;">البيان</th>
                    <th style="padding:10px 15px;">مقبوضات</th><th style="padding:10px 15px;">مدفوعات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($day_lines) > 0): foreach ($day_lines as $line): ?>
                    <tr style="border-bottom:1px solid #f1f1f1;">
                        <td style="padding:8px 15px; font-family:monospace; color:#4e73df;"><?php echo htmlspecialchars($line['entry_number'] ?? ''); ?></td>
                        <td style="padding:8px 15px; font-size:12px; color:#666;"><?php echo htmlspecialchars($source_labels[$line['source_module']] ?? $line['source_module'] ?? ''); ?></td>
                        <td style="padding:8px 15px;"><?php echo htmlspecialchars($line['description'] ?? ''); ?></td>
                        <td style="padding:8px 15px; color:#1cc88a; font-family:monospace;"><?php echo $line['debit'] > 0 ? number_format($line['debit'], 2) : '-'; ?></td>
                        <td style="padding:8px 15px; color:#e74a3b; font-family:monospace;"><?php echo $line['credit'] > 0 ? number_format($line['credit'], 2) : '-'; ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" style="padding:25px; text-align:center; color:#777;">لا توجد حركات نقدية مسجَّلة لهذا اليوم.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f8f9fc; border-top:2px solid #e3e6f0; font-weight:bold;">
                    <td colspan="3" style="padding:10px 15px;">الإجمالي</td>
                    <td style="padding:10px 15px; color:#1cc88a;"><?php echo number_format($total_in, 2); ?></td>
                    <td style="padding:10px 15px; color:#e74a3b;"><?php echo number_format($total_out, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>
<?php include 'footer.php'; ?>