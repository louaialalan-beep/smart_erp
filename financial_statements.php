<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';
require_once __DIR__ . '/functions.php';

$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$period_start = $_GET['period_start'] ?? date('Y-01-01');
$period_end = $_GET['period_end'] ?? $as_of_date;

$type_labels = ['Asset' => 'الأصول', 'Liability' => 'الخصوم', 'Equity' => 'حقوق الملكية', 'Revenue' => 'الإيرادات', 'Expense' => 'المصروفات'];

// ============================================================
// قائمة الدخل (Income Statement) — لفترة زمنية محددة
// الإيرادات والمصروفات (الحسابات من نوع Revenue/Expense) خلال الفترة فقط
// ============================================================
$stmt_is = $conn->prepare("
    SELECT a.account_type, a.account_name, a.account_code,
           COALESCE(SUM(j.credit) - SUM(j.debit), 0) AS net_revenue,
           COALESCE(SUM(j.debit) - SUM(j.credit), 0) AS net_expense
    FROM accounts a
    JOIN journal_entries j ON j.account_id = a.id
    WHERE a.account_type IN ('Revenue', 'Expense')
    AND j.entry_date BETWEEN ? AND ?
    GROUP BY a.id, a.account_type, a.account_name, a.account_code
    HAVING (net_revenue != 0 OR net_expense != 0)
    ORDER BY a.account_type DESC, a.account_code ASC
");
$stmt_is->execute([$period_start, $period_end]);
$is_rows = $stmt_is->fetchAll(PDO::FETCH_ASSOC);

$total_revenue = 0;
$total_expense = 0;
$revenue_lines = [];
$expense_lines = [];
foreach ($is_rows as $row) {
    if ($row['account_type'] === 'Revenue') {
        $total_revenue += floatval($row['net_revenue']);
        $revenue_lines[] = $row;
    } else {
        $total_expense += floatval($row['net_expense']);
        $expense_lines[] = $row;
    }
}
$net_income = $total_revenue - $total_expense;

// ============================================================
// الميزانية العمومية (Balance Sheet) — رصيد تراكمي حتى تاريخ معيَّن
// الأصول = الخصوم + حقوق الملكية (بما فيها صافي الربح المُرحَّل التلقائي ضمن حقوق الملكية)
// ============================================================
$stmt_bs = $conn->prepare("
    SELECT a.account_type, a.account_name, a.account_code,
           COALESCE(SUM(j.debit) - SUM(j.credit), 0) AS net_balance
    FROM accounts a
    JOIN journal_entries j ON j.account_id = a.id
    WHERE a.account_type IN ('Asset', 'Liability', 'Equity')
    AND j.entry_date <= ?
    GROUP BY a.id, a.account_type, a.account_name, a.account_code
    HAVING net_balance != 0
    ORDER BY a.account_type ASC, a.account_code ASC
");
$stmt_bs->execute([$as_of_date]);
$bs_rows = $stmt_bs->fetchAll(PDO::FETCH_ASSOC);

$total_assets = 0;
$total_liabilities = 0;
$total_equity = 0;
$asset_lines = [];
$liability_lines = [];
$equity_lines = [];
foreach ($bs_rows as $row) {
    $bal = floatval($row['net_balance']);
    if ($row['account_type'] === 'Asset') {
        $total_assets += $bal;
        $asset_lines[] = $row;
    } elseif ($row['account_type'] === 'Liability') {
        // الخصوم دائنة بطبيعتها، فتُعرض القيمة الموجبة كمقلوب الرصيد المدين الصافي
        $total_liabilities += -$bal;
        $row['net_balance'] = -$bal;
        $liability_lines[] = $row;
    } else {
        $total_equity += -$bal;
        $row['net_balance'] = -$bal;
        $equity_lines[] = $row;
    }
}

// صافي الربح التراكمي (من بداية النظام حتى as_of_date) يُضاف كبند ضمن حقوق الملكية تلقائياً
// (الأرباح المحتجزة غير المُوزَّعة — Retained Earnings)، حتى تتوازن المعادلة المحاسبية الأساسية
$stmt_retained = $conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN a.account_type = 'Revenue' THEN j.credit - j.debit ELSE 0 END), 0) AS rev,
        COALESCE(SUM(CASE WHEN a.account_type = 'Expense' THEN j.debit - j.credit ELSE 0 END), 0) AS exp
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    WHERE a.account_type IN ('Revenue', 'Expense') AND j.entry_date <= ?
");
$stmt_retained->execute([$as_of_date]);
$retained_data = $stmt_retained->fetch(PDO::FETCH_ASSOC);
$retained_earnings = floatval($retained_data['rev']) - floatval($retained_data['exp']);
$total_equity += $retained_earnings;

// ============================================================
// بطاقات سريعة إضافية: الصندوق الرئيسي، ذمم العملاء، ذمم الموردين (دولار وليرة)، مخزون المكتب
// ============================================================
$stmt_qcash = $conn->prepare("
    SELECT COALESCE(SUM(j.debit) - SUM(j.credit), 0)
    FROM journal_entries j JOIN accounts a ON j.account_id = a.id
    WHERE a.account_name LIKE '%صندوق الرئيسي%' AND j.entry_date <= ?
");
$stmt_qcash->execute([$as_of_date]);
$quick_cash = floatval($stmt_qcash->fetchColumn());

$stmt_qar = $conn->prepare("
    SELECT COALESCE(SUM(j.debit) - SUM(j.credit), 0)
    FROM journal_entries j JOIN accounts a ON j.account_id = a.id
    WHERE a.account_name LIKE '%ذمم العملاء%' AND j.entry_date <= ?
");
$stmt_qar->execute([$as_of_date]);
$quick_ar = floatval($stmt_qar->fetchColumn());

// ذمم الموردين بالليرة (العمود الأساسي debit/credit) وبالدولار (foreign_debit/foreign_credit إن وُجدا،
// فهذه هي القيمة الحقيقية الأصلية التي رُحِّلت بها أغلب قيود الموردين في هذا النظام أساساً بالدولار)
try {
    $je_cols_fs = $conn->query("SHOW COLUMNS FROM journal_entries")->fetchAll(PDO::FETCH_COLUMN);
    $has_foreign_cols = in_array('foreign_debit', $je_cols_fs) && in_array('foreign_credit', $je_cols_fs);
} catch (Exception $e) { $has_foreign_cols = false; }

$stmt_qap_syp = $conn->prepare("
    SELECT COALESCE(SUM(j.credit) - SUM(j.debit), 0)
    FROM journal_entries j JOIN accounts a ON j.account_id = a.id
    WHERE a.account_name LIKE '%ذمم الموردين%' AND j.entry_date <= ?
");
$stmt_qap_syp->execute([$as_of_date]);
$quick_ap_syp = floatval($stmt_qap_syp->fetchColumn());

$quick_ap_usd = 0;
if ($has_foreign_cols) {
    $stmt_qap_usd = $conn->prepare("
        SELECT COALESCE(SUM(j.foreign_credit) - SUM(j.foreign_debit), 0)
        FROM journal_entries j JOIN accounts a ON j.account_id = a.id
        WHERE a.account_name LIKE '%ذمم الموردين%' AND j.entry_date <= ?
    ");
    $stmt_qap_usd->execute([$as_of_date]);
    $quick_ap_usd = floatval($stmt_qap_usd->fetchColumn());
}

// مخزون المكتب تحديداً (بلا مورد) — تقديري بسعر التكلفة الحالي، منفصل عن "المخزون" الإجمالي في
// الميزانية العمومية (الذي يشمل بضاعة الموردين أيضاً ولا يمكن فصله من دفتر الأستاذ وحده)
$stmt_office_inv = $conn->prepare("SELECT COALESCE(SUM(current_quantity * cost_price_usd), 0) FROM products WHERE supplier_id IS NULL");
$stmt_office_inv->execute();
$quick_office_inventory_usd = floatval($stmt_office_inv->fetchColumn());
$exchange_rate_now = getExchangeRateForDate($conn, 'USD', $as_of_date);
$quick_office_inventory_syp = $quick_office_inventory_usd * $exchange_rate_now;

$total_liabilities_and_equity = $total_liabilities + $total_equity;
// مقارنة بالتسامح (tolerance) بدل === الصارمة على أرقام عائمة (float): جمع عشرات/مئات القيود
// عبر SUM() قد ينتج فروقاً دقيقة جداً (أجزاء من القرش) بسبب دقة تمثيل الفاصلة العائمة، فتُظهر ===
// "عدم توازن" زوراً حتى لو كانت الميزانية متوازنة فعلياً لآخر قرش. 0.01 = أقل من نصف قرش سوري.
$is_balanced = abs($total_assets - $total_liabilities_and_equity) < 0.01;
?>
<style>
    @media print {
        .no-print { display: none !important; }
        body * { visibility: hidden; }
        #printArea, #printArea * { visibility: visible; }
        #printArea { position: absolute; left: 0; top: 0; width: 100%; }
    }
    .fs-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .fs-table td { padding: 7px 15px; }
    .fs-table .indent { padding-right: 30px; color: #444; }
    .fs-table .section-header { font-weight: bold; color: #4e73df; background: #f8f9fc; }
    .fs-table .total-row { font-weight: bold; border-top: 2px solid #333; }
</style>

<div class="no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <h2><i class="fas fa-file-invoice"></i> القوائم المالية الرسمية</h2>
    <div style="display:flex; gap:10px; align-items:center;">
        <form method="GET" style="display:flex; gap:8px; align-items:center;">
            <span style="font-size:12px; font-weight:bold;">من:</span>
            <input type="date" name="period_start" value="<?php echo htmlspecialchars($period_start); ?>" style="padding:6px; border:1px solid #ccc; border-radius:4px;">
            <span style="font-size:12px; font-weight:bold;">إلى:</span>
            <input type="date" name="period_end" value="<?php echo htmlspecialchars($period_end); ?>" style="padding:6px; border:1px solid #ccc; border-radius:4px;">
            <input type="hidden" name="as_of_date" value="<?php echo htmlspecialchars($period_end); ?>">
            <button type="submit" style="background:#4e73df; color:white; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:bold;">تطبيق</button>
        </form>
        <button onclick="window.print()" style="background:#1cc88a; color:white; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:bold;"><i class="fas fa-print"></i> طباعة</button>
    </div>
</div>

<div id="printArea">
    <!-- بطاقات سريعة -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:20px;">
        <div style="background:white; border-right:4px solid #1cc88a; padding:15px; border-radius:8px; box-shadow:0 0.1rem 0.75rem rgba(0,0,0,0.05);">
            <div style="color:#888; font-size:12px; font-weight:bold;">الصندوق الرئيسي</div>
            <div style="font-size:20px; font-weight:bold; color:#1cc88a; font-family:monospace; margin-top:5px;"><?php echo number_format($quick_cash, 2); ?> ل.س</div>
        </div>
        <div style="background:white; border-right:4px solid #4e73df; padding:15px; border-radius:8px; box-shadow:0 0.1rem 0.75rem rgba(0,0,0,0.05);">
            <div style="color:#888; font-size:12px; font-weight:bold;">إجمالي ذمم العملاء</div>
            <div style="font-size:20px; font-weight:bold; color:#4e73df; font-family:monospace; margin-top:5px;"><?php echo number_format($quick_ar, 2); ?> ل.س</div>
        </div>
        <div style="background:white; border-right:4px solid #e74a3b; padding:15px; border-radius:8px; box-shadow:0 0.1rem 0.75rem rgba(0,0,0,0.05);">
            <div style="color:#888; font-size:12px; font-weight:bold;" title="بالليرة من الرصيد الأساسي، وبالدولار من القيمة الأجنبية الأصلية للقيود (إن وُجدت)">ذمم الموردين</div>
            <div style="font-size:20px; font-weight:bold; color:#e74a3b; font-family:monospace; margin-top:5px;"><?php echo number_format($quick_ap_syp, 2); ?> ل.س</div>
            <?php if ($has_foreign_cols): ?>
                <div style="font-size:13px; color:#a33636; font-family:monospace; margin-top:3px;">≈ $<?php echo number_format($quick_ap_usd, 2); ?></div>
            <?php endif; ?>
        </div>
        <div style="background:white; border-right:4px solid #8b5cf6; padding:15px; border-radius:8px; box-shadow:0 0.1rem 0.75rem rgba(0,0,0,0.05);">
            <div style="color:#888; font-size:12px; font-weight:bold;" title="أصناف supplier_id فارغ فقط — جرد مكتبي مباشر بلا مورد، بسعر التكلفة الحالي. منفصل عن رقم المخزون الإجمالي في الميزانية (الذي يشمل بضاعة الموردين أيضاً)">مخزون المكتب (الجرد المباشر)</div>
            <div style="font-size:20px; font-weight:bold; color:#8b5cf6; font-family:monospace; margin-top:5px;">$<?php echo number_format($quick_office_inventory_usd, 2); ?></div>
            <div style="font-size:13px; color:#5b3a99; font-family:monospace; margin-top:3px;">≈ <?php echo number_format($quick_office_inventory_syp, 2); ?> ل.س</div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start;">

        <!-- قائمة الدخل -->
        <div style="background:white; border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
            <div style="background:#f8f9fc; padding:15px 20px; border-bottom:2px solid #e3e6f0; text-align:center;">
                <h3 style="margin:0; color:#333;">قائمة الدخل</h3>
                <p style="margin:5px 0 0; font-size:12px; color:#888;">للفترة من <?php echo htmlspecialchars($period_start); ?> إلى <?php echo htmlspecialchars($period_end); ?></p>
            </div>
            <table class="fs-table">
                <tr class="section-header"><td colspan="2">الإيرادات</td></tr>
                <?php if (count($revenue_lines) > 0): foreach ($revenue_lines as $r): ?>
                    <tr><td class="indent"><?php echo htmlspecialchars($r['account_name']); ?></td><td style="text-align:left; font-family:monospace;"><?php echo number_format($r['net_revenue'], 2); ?></td></tr>
                <?php endforeach; else: ?>
                    <tr><td class="indent" colspan="2" style="color:#aaa;">لا توجد إيرادات مسجَّلة ضمن الفترة</td></tr>
                <?php endif; ?>
                <tr class="total-row"><td>إجمالي الإيرادات</td><td style="text-align:left; font-family:monospace; color:#1cc88a;"><?php echo number_format($total_revenue, 2); ?></td></tr>

                <tr class="section-header"><td colspan="2" style="padding-top:15px;">المصروفات</td></tr>
                <?php if (count($expense_lines) > 0): foreach ($expense_lines as $r): ?>
                    <tr><td class="indent"><?php echo htmlspecialchars($r['account_name']); ?></td><td style="text-align:left; font-family:monospace;"><?php echo number_format($r['net_expense'], 2); ?></td></tr>
                <?php endforeach; else: ?>
                    <tr><td class="indent" colspan="2" style="color:#aaa;">لا توجد مصروفات مسجَّلة ضمن الفترة</td></tr>
                <?php endif; ?>
                <tr class="total-row"><td>إجمالي المصروفات</td><td style="text-align:left; font-family:monospace; color:#e74a3b;"><?php echo number_format($total_expense, 2); ?></td></tr>

                <tr class="total-row" style="background:<?php echo $net_income >= 0 ? '#eefaf5' : '#fdf2f0'; ?>; border-top:3px double #333;">
                    <td style="font-size:15px;">صافي <?php echo $net_income >= 0 ? 'الربح' : 'الخسارة'; ?></td>
                    <td style="text-align:left; font-family:monospace; font-size:15px; color:<?php echo $net_income >= 0 ? '#1a8f6b' : '#c0392b'; ?>;"><?php echo number_format($net_income, 2); ?> ل.س</td>
                </tr>
            </table>
        </div>

        <!-- الميزانية العمومية -->
        <div style="background:white; border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
            <div style="background:#f8f9fc; padding:15px 20px; border-bottom:2px solid #e3e6f0; text-align:center;">
                <h3 style="margin:0; color:#333;">الميزانية العمومية</h3>
                <p style="margin:5px 0 0; font-size:12px; color:#888;">كما في تاريخ <?php echo htmlspecialchars($as_of_date); ?></p>
            </div>
            <table class="fs-table">
                <tr class="section-header"><td colspan="2">الأصول</td></tr>
                <?php if (count($asset_lines) > 0): foreach ($asset_lines as $r): ?>
                    <tr><td class="indent"><?php echo htmlspecialchars($r['account_name']); ?></td><td style="text-align:left; font-family:monospace;"><?php echo number_format($r['net_balance'], 2); ?></td></tr>
                <?php endforeach; else: ?>
                    <tr><td class="indent" colspan="2" style="color:#aaa;">لا توجد أصول مسجَّلة</td></tr>
                <?php endif; ?>
                <tr class="total-row"><td>إجمالي الأصول</td><td style="text-align:left; font-family:monospace; color:#2e59d9;"><?php echo number_format($total_assets, 2); ?></td></tr>

                <tr class="section-header"><td colspan="2" style="padding-top:15px;">الخصوم</td></tr>
                <?php if (count($liability_lines) > 0): foreach ($liability_lines as $r): ?>
                    <tr><td class="indent"><?php echo htmlspecialchars($r['account_name']); ?></td><td style="text-align:left; font-family:monospace;"><?php echo number_format($r['net_balance'], 2); ?></td></tr>
                <?php endforeach; else: ?>
                    <tr><td class="indent" colspan="2" style="color:#aaa;">لا توجد خصوم مسجَّلة</td></tr>
                <?php endif; ?>
                <tr class="total-row"><td>إجمالي الخصوم</td><td style="text-align:left; font-family:monospace; color:#e74a3b;"><?php echo number_format($total_liabilities, 2); ?></td></tr>

                <tr class="section-header"><td colspan="2" style="padding-top:15px;">حقوق الملكية</td></tr>
                <?php foreach ($equity_lines as $r): ?>
                    <tr><td class="indent"><?php echo htmlspecialchars($r['account_name']); ?></td><td style="text-align:left; font-family:monospace;"><?php echo number_format($r['net_balance'], 2); ?></td></tr>
                <?php endforeach; ?>
                <tr><td class="indent">الأرباح المحتجزة (تراكمية)</td><td style="text-align:left; font-family:monospace;"><?php echo number_format($retained_earnings, 2); ?></td></tr>
                <tr class="total-row"><td>إجمالي حقوق الملكية</td><td style="text-align:left; font-family:monospace; color:#856404;"><?php echo number_format($total_equity, 2); ?></td></tr>

                <tr class="total-row" style="background:<?php echo $is_balanced ? '#eefaf5' : '#fdf2f0'; ?>; border-top:3px double #333;">
                    <td style="font-size:15px;">إجمالي الخصوم + حقوق الملكية</td>
                    <td style="text-align:left; font-family:monospace; font-size:15px;"><?php echo number_format($total_liabilities_and_equity, 2); ?></td>
                </tr>
            </table>
            <div style="padding:12px 20px; text-align:center; font-size:12.5px;">
                <?php if ($is_balanced): ?>
                    <span style="background:#d4edda; color:#155724; padding:4px 12px; border-radius:20px; font-weight:bold;">✓ الميزانية متوازنة — الأصول = الخصوم + حقوق الملكية</span>
                <?php else: ?>
                    <span style="background:#f8d7da; color:#721c24; padding:4px 12px; border-radius:20px; font-weight:bold;">⚠ فرق في التوازن: <?php echo number_format($total_assets - $total_liabilities_and_equity, 2); ?> ل.س — راجع دفتر الأستاذ</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <p class="no-print" style="font-size:12px; color:#888; margin-top:15px;">
        <i class="fas fa-info-circle"></i> ملاحظة: هذه القوائم تُبنى مباشرة من دفتر الأستاذ العام (journal_entries) بحسب نوع الحساب (Asset/Liability/Equity/Revenue/Expense) — تأكد أن كل حساب في شجرة الحسابات مصنَّف بنوع صحيح (راجع "شجرة الحسابات الذكية") ليظهر في مكانه الصحيح هنا.
    </p>
</div>
<?php include 'footer.php'; ?>