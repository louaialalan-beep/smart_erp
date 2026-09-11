<?php
session_start();
include 'header.php';

if (!isset($conn)) { die("خطأ: اتصال قاعدة البيانات غير متوفر."); }

// ============================================================
// فلتر الفترة المخصَّص + تاريخ القطع لفواتير الشراء (المنتجات المُشتراة بعد هذا التاريخ فقط تدخل
// في حساب "رأس المال" أدناه — افتراضياً 2026-09-06 كما طُلب، وقابل للتغيير لاحقاً إن لزم)
// ============================================================
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$purchase_cutoff = $_GET['purchase_cutoff'] ?? '2026-09-06';

$error_msg = '';
$capital_delivered_qty = 0; $capital_delivered_usd = 0; $capital_delivered_syp = 0;
$capital_pending_qty = 0; $capital_pending_usd = 0; $capital_pending_syp = 0;
$total_commissions = 0; $total_expenses = 0; $total_payroll = 0;
$total_revenue = 0; $total_revenue_pending = 0;
$expenses_breakdown = [];

try {
    // ============================================================
    // 1) رأس المال: تكلفة (COGS) المنتجات المباعة ضمن الفترة، حصراً للمنتجات التي اشتُريت فعلياً
    // عبر فاتورة شراء بتاريخ بعد تاريخ القطع المحدَّد أعلاه — مقسَّمة إلى "تم التسليم" و"قيد الانتظار"
    // (الخصم من المخزون يحدث فور إصدار الفاتورة بغض النظر عن حالة التسليم، لكن "رأس المال" هنا يُعرَض
    // منفصلاً بحسب الحالة لتمييز ما تحقَّق فعلياً (تم التسليم) عما لا يزال معلَّقاً (قيد الانتظار)).
    $stmt_capital = $conn->prepare("
        SELECT s.delivery_status,
               COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS qty,
               COALESCE(SUM(
                   (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
                   * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)
               ), 0) AS capital_usd,
               COALESCE(SUM(
                   (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
                   * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate
               ), 0) AS capital_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.invoice_date BETWEEN ? AND ?
          AND s.delivery_status IN ('Delivered', 'Pending')
          AND s.invoice_date > ?
        GROUP BY s.delivery_status
    ");
    $stmt_capital->execute([$start_date, $end_date, $purchase_cutoff]);
    foreach ($stmt_capital->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['delivery_status'] === 'Delivered') {
            $capital_delivered_qty = floatval($row['qty']);
            $capital_delivered_usd = floatval($row['capital_usd']);
            $capital_delivered_syp = floatval($row['capital_syp']);
        } else {
            $capital_pending_qty = floatval($row['qty']);
            $capital_pending_usd = floatval($row['capital_usd']);
            $capital_pending_syp = floatval($row['capital_syp']);
        }
    }

    // ============================================================
    // 2) الإيراد: من sale_items مباشرة (بحسب تاريخ التسليم الفعلي ضمن الفترة)، لكن حصراً لفواتير
    // المبيعات التي صدرت (invoice_date) بعد تاريخ القطع المحدَّد — فلتر مباشر على تاريخ إصدار فاتورة
    // البيع نفسها، بلا أي تتبّع لتاريخ شراء المنتج.
    // ============================================================
    $stmt_rev = $conn->prepare("
        SELECT COALESCE(SUM(
            si.total_price_syp - COALESCE((SELECT SUM(sri.total_price_syp) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)
        ), 0)
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.delivery_status = 'Delivered'
          AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
          AND s.invoice_date > ?
    ");
    $stmt_rev->execute([$start_date, $end_date, $purchase_cutoff]);
    $total_revenue = floatval($stmt_rev->fetchColumn());

    // إيراد "قيد الانتظار" (نفس الشروط، لكن لحالة Pending) — لحساب "الشامل" الذي يضيف هذا الجزء
    // غير المُتحقِّق بعد إلى الصورة الكاملة المحتملة
    $stmt_rev_pending = $conn->prepare("
        SELECT COALESCE(SUM(
            si.total_price_syp - COALESCE((SELECT SUM(sri.total_price_syp) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)
        ), 0)
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.delivery_status = 'Pending'
          AND s.invoice_date BETWEEN ? AND ?
          AND s.invoice_date > ?
    ");
    $stmt_rev_pending->execute([$start_date, $end_date, $purchase_cutoff]);
    $total_revenue_pending = floatval($stmt_rev_pending->fetchColumn());

    // ============================================================
    // 3) العمولات — حصراً لفواتير صادرة (invoice_date) بعد تاريخ القطع، نفس معيار الإيراد ورأس المال
    // بالضبط (كان هذا الشرط مفقوداً سابقاً هنا، فكانت تُحتسَب عمولات فواتير قديمة سابقة لتاريخ القطع)
    // ============================================================
    $stmt_comm = $conn->prepare("
        SELECT COALESCE(SUM(s.total_commissions), 0)
        FROM sales s
        WHERE s.delivery_status = 'Delivered'
          AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
          AND s.invoice_date > ?
    ");
    $stmt_comm->execute([$start_date, $end_date, $purchase_cutoff]);
    $total_commissions = floatval($stmt_comm->fetchColumn());

    // ============================================================
    // 4) المصاريف التشغيلية (شاملة الاستحقاقات المتكررة تلقائياً — لأنها تُدرَج في نفس الجدول عند
    // الترحيل، فلا حاجة لاستعلام منفصل: كل استحقاق يومي لإيجار أو رواتب متكررة يُسجَّل هنا أيضاً)
    // ============================================================
    $stmt_exp = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM operational_expenses WHERE expense_date BETWEEN ? AND ?");
    $stmt_exp->execute([$start_date, $end_date]);
    $total_expenses = floatval($stmt_exp->fetchColumn());

    $stmt_exp_details = $conn->prepare("SELECT category, SUM(amount) AS cat_total FROM operational_expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY cat_total DESC");
    $stmt_exp_details->execute([$start_date, $end_date]);
    $expenses_breakdown = $stmt_exp_details->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // 5) الرواتب والأجور (من دفتر اليومية مباشرة — يشمل أيضاً الاستحقاقات اليومية المتكررة لأنها
    // تُرحَّل على نفس هذين الحسابين بالضبط)
    // ============================================================
    $stmt_payroll = $conn->prepare("
        SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name IN ('الرواتب والأجور', 'مصروف حوافز ومكافآت الموظفين')
          AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_payroll->execute([$start_date, $end_date]);
    $total_payroll = floatval($stmt_payroll->fetchColumn());

} catch (Exception $e) {
    $error_msg = "تنبيه: تعذّر حساب بعض المؤشرات — " . $e->getMessage();
}

// صافي الربح = الإيراد - رأس مال المُسلَّم فقط (المُتحقِّق فعلياً) - العمولات - المصاريف - الرواتب
// (رأس مال "قيد الانتظار" يُعرَض للعلم فقط، لا يُخصَم من الربح المُتحقِّق بعد لأنه لم يُسلَّم/يُحقَّق)
$net_profit = $total_revenue - $capital_delivered_syp - $total_commissions - $total_expenses - $total_payroll;

// صافي الأرباح الشامل: يضيف إيراد ورأس مال "قيد الانتظار" إلى الصورة — يمثّل الربح المحتمل الكامل
// لو تم تسليم كل شيء اليوم (وليس المُتحقِّق فعلياً فقط)
$net_profit_comprehensive = ($total_revenue + $total_revenue_pending) - ($capital_delivered_syp + $capital_pending_syp) - $total_commissions - $total_expenses - $total_payroll;
?>

<div style="padding: 20px;">
    <h2 style="color: #2e384d; margin-bottom: 5px;"><i class="fas fa-file-invoice"></i> تقرير رأس المال والأرباح المخصَّص</h2>
    <p style="color: #6c757d; margin: 0 0 20px; font-size: 14px;">تقرير بفترة مخصَّصة: رأس مال المنتجات المباعة (من مشتريات بعد تاريخ محدَّد)، العمولات والمصاريف والرواتب (شاملة الاستحقاقات المتكررة)، وصافي الربح كاملاً.</p>

    <form method="GET" style="background: #fff; padding: 18px 20px; border-radius: 8px; border: 1px solid #e3e6f0; margin-bottom: 25px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
        <div>
            <label style="display: block; font-size: 12px; font-weight: bold; color: #555; margin-bottom: 5px;">من تاريخ:</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" style="padding: 8px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: bold; color: #555; margin-bottom: 5px;">إلى تاريخ:</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" style="padding: 8px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: bold; color: #555; margin-bottom: 5px;">تاريخ القطع (بعده فقط):</label>
            <input type="date" name="purchase_cutoff" value="<?php echo htmlspecialchars($purchase_cutoff); ?>" style="padding: 8px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
            <p style="font-size: 10.5px; color: #888; margin: 4px 0 0; max-width: 220px;">رأس المال والإيراد كلاهما: فقط لفواتير مبيعات صادرة بعد هذا التاريخ.</p>
        </div>
        <button type="submit" style="background: #4e73df; color: white; border: none; padding: 9px 20px; border-radius: 6px; cursor: pointer; font-weight: bold;">تطبيق</button>
    </form>

    <?php if ($error_msg): ?>
        <div style="background: #fff3cd; color: #856404; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- بطاقة رأس المال -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; margin-bottom: 20px;">
        <div style="background: #f3eefe; padding: 15px 20px; border-bottom: 1px solid #e3e6f0;">
            <h3 style="margin: 0; color: #5b3a99; font-size: 16px;"><i class="fas fa-coins"></i> رأس مال المنتجات المباعة (فواتير بيع صادرة بعد <?php echo htmlspecialchars($purchase_cutoff); ?> فقط)</h3>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: #eee;">
            <div style="background: #eafaf1; padding: 18px 20px;">
                <div style="color: #1a8f5f; font-size: 13px; font-weight: bold;">تم التسليم (متحقِّق فعلياً)</div>
                <div style="font-size: 20px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($capital_delivered_qty, 2), '0'), '.'); ?> قطعة</div>
                <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;"><?php echo number_format($capital_delivered_syp, 2); ?> ل.س (≈ $<?php echo number_format($capital_delivered_usd, 2); ?>)</div>
            </div>
            <div style="background: #fff8e6; padding: 18px 20px;">
                <div style="color: #856404; font-size: 13px; font-weight: bold;">قيد الانتظار (غير متحقِّق بعد)</div>
                <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($capital_pending_qty, 2), '0'), '.'); ?> قطعة</div>
                <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;"><?php echo number_format($capital_pending_syp, 2); ?> ل.س (≈ $<?php echo number_format($capital_pending_usd, 2); ?>)</div>
            </div>
        </div>
    </div>

    <!-- بطاقة العمولات والمصاريف والرواتب -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; margin-bottom: 20px;">
        <div style="background: #fdf6e3; padding: 15px 20px; border-bottom: 1px solid #e3e6f0;">
            <h3 style="margin: 0; color: #856404; font-size: 16px;"><i class="fas fa-hand-holding-usd"></i> العمولات والمصاريف والرواتب (شاملة الاستحقاقات المتكررة تلقائياً)</h3>
        </div>
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 10px 20px;">عمولات المندوبين</td><td style="padding: 10px 20px; text-align: left; font-family: monospace; font-weight: bold;"><?php echo number_format($total_commissions, 2); ?> ل.س</td></tr>
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 10px 20px;">إجمالي المصاريف التشغيلية (فورية + استحقاقات متكررة)</td><td style="padding: 10px 20px; text-align: left; font-family: monospace; font-weight: bold;"><?php echo number_format($total_expenses, 2); ?> ل.س</td></tr>
            <?php foreach ($expenses_breakdown as $exp): ?>
                <tr style="border-bottom: 1px solid #f8f8f8;"><td style="padding: 6px 20px 6px 40px; color: #888; font-size: 12.5px;">↳ <?php echo htmlspecialchars($exp['category']); ?></td><td style="padding: 6px 20px; text-align: left; font-family: monospace; color: #888; font-size: 12.5px;"><?php echo number_format($exp['cat_total'], 2); ?></td></tr>
            <?php endforeach; ?>
            <tr><td style="padding: 10px 20px;">الرواتب والأجور والحوافز</td><td style="padding: 10px 20px; text-align: left; font-family: monospace; font-weight: bold;"><?php echo number_format($total_payroll, 2); ?> ل.س</td></tr>
        </table>
    </div>

    <!-- بطاقة صافي الربح بالتفصيل الكامل -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden;">
        <div style="background: #e8f8f2; padding: 15px 20px; border-bottom: 1px solid #e3e6f0;">
            <h3 style="margin: 0; color: #1a7a5e; font-size: 16px;"><i class="fas fa-chart-line"></i> صافي الأرباح — كل ما خُصِم بالتفصيل</h3>
        </div>
        <table style="width: 100%; border-collapse: collapse; font-size: 14.5px; text-align: right;">
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #1cc88a; font-weight: bold;">إجمالي الإيرادات</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #1cc88a; font-weight: bold;">+ <?php echo number_format($total_revenue, 2); ?></td></tr>
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #e74a3b;">ناقص رأس مال المُسلَّم (COGS)</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #e74a3b;">- <?php echo number_format($capital_delivered_syp, 2); ?></td></tr>
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #e74a3b;">ناقص عمولات المندوبين</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #e74a3b;">- <?php echo number_format($total_commissions, 2); ?></td></tr>
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #e74a3b;">ناقص المصاريف التشغيلية (شاملة المتكررة)</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #e74a3b;">- <?php echo number_format($total_expenses, 2); ?></td></tr>
            <tr style="border-bottom: 2px solid #ccc;"><td style="padding: 12px 20px; color: #e74a3b;">ناقص الرواتب والأجور والحوافز</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #e74a3b;">- <?php echo number_format($total_payroll, 2); ?></td></tr>
            <tr style="background: <?php echo $net_profit >= 0 ? '#eefaf5' : '#fdf2f0'; ?>; border-top: 3px double #333;">
                <td style="padding: 15px 20px; font-size: 17px; font-weight: bold;">= صافي <?php echo $net_profit >= 0 ? 'الربح' : 'الخسارة'; ?></td>
                <td style="padding: 15px 20px; text-align: left; font-family: monospace; font-size: 17px; font-weight: bold; color: <?php echo $net_profit >= 0 ? '#1a8f6b' : '#c0392b'; ?>;"><?php echo number_format($net_profit, 2); ?> ل.س</td>
            </tr>
        </table>
        <p style="font-size: 11.5px; color: #999; padding: 12px 20px; margin: 0;">
            <i class="fas fa-info-circle"></i> ملاحظة: رأس مال "قيد الانتظار" (المعروض في البطاقة أعلاه) لا يُخصَم من صافي الربح هنا لأنه لم يتحقَّق بعد (لم يُسلَّم) — يُعرَض للعلم بحجم رأس المال المُجمَّد في فواتير لم تُسلَّم بعد فقط.
        </p>
    </div>

    <!-- بطاقة صافي الأرباح الشامل (يضيف قيد الانتظار إلى الصورة) -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; margin-top: 20px;">
        <div style="background: #eaf1fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0;">
            <h3 style="margin: 0; color: #2c4e9c; font-size: 16px;"><i class="fas fa-layer-group"></i> صافي الأرباح الشامل — يضم "قيد الانتظار" أيضاً (الربح المحتمل الكامل)</h3>
        </div>
        <table style="width: 100%; border-collapse: collapse; font-size: 14.5px; text-align: right;">
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #1cc88a; font-weight: bold;">إجمالي الإيرادات (تم التسليم)</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #1cc88a; font-weight: bold;">+ <?php echo number_format($total_revenue, 2); ?></td></tr>
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #1cc88a;">+ إيرادات قيد الانتظار (غير متحقِّقة بعد)</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #1cc88a;">+ <?php echo number_format($total_revenue_pending, 2); ?></td></tr>
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #e74a3b;">ناقص رأس مال المُسلَّم (COGS)</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #e74a3b;">- <?php echo number_format($capital_delivered_syp, 2); ?></td></tr>
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #e74a3b;">ناقص رأس مال قيد الانتظار</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #e74a3b;">- <?php echo number_format($capital_pending_syp, 2); ?></td></tr>
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #e74a3b;">ناقص عمولات المندوبين</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #e74a3b;">- <?php echo number_format($total_commissions, 2); ?></td></tr>
            <tr style="border-bottom: 1px solid #f1f1f1;"><td style="padding: 12px 20px; color: #e74a3b;">ناقص المصاريف التشغيلية (شاملة المتكررة)</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #e74a3b;">- <?php echo number_format($total_expenses, 2); ?></td></tr>
            <tr style="border-bottom: 2px solid #ccc;"><td style="padding: 12px 20px; color: #e74a3b;">ناقص الرواتب والأجور والحوافز</td><td style="padding: 12px 20px; text-align: left; font-family: monospace; color: #e74a3b;">- <?php echo number_format($total_payroll, 2); ?></td></tr>
            <tr style="background: <?php echo $net_profit_comprehensive >= 0 ? '#eef4fc' : '#fdf2f0'; ?>; border-top: 3px double #333;">
                <td style="padding: 15px 20px; font-size: 17px; font-weight: bold;">= صافي الربح الشامل (المحتمل الكامل)</td>
                <td style="padding: 15px 20px; text-align: left; font-family: monospace; font-size: 17px; font-weight: bold; color: <?php echo $net_profit_comprehensive >= 0 ? '#2c4e9c' : '#c0392b'; ?>;"><?php echo number_format($net_profit_comprehensive, 2); ?> ل.س</td>
            </tr>
        </table>
        <p style="font-size: 11.5px; color: #999; padding: 12px 20px; margin: 0;">
            <i class="fas fa-info-circle"></i> هذا الرقم افتراضي: يفترض أن كل فواتير "قيد الانتظار" ستُسلَّم فعلاً بلا أي تغيير أو إلغاء أو مرتجع — استخدمه كمؤشر للربح المحتمل الكامل، لا كربح مُحقَّق فعلياً بعد.
        </p>
    </div>

</div>

<?php include 'footer.php'; ?>