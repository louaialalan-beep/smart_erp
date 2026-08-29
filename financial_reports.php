<?php
/**
 * التقارير المالية والتحليلية المتقدمة - Smart ERP
 */
session_start();
include 'header.php';

if (!isset($conn)) {
    die("خطأ: اتصال قاعدة البيانات غير متوفر.");
}

// 1. تهيئة المتغيرات المالية افتراضياً لمنع أي تحذيرات (Undefined Variable)
$total_revenue = 0;
$total_cogs_syp = 0;
$total_cogs_usd = 0;
$total_commissions = 0;
$total_expenses = 0;
$net_profit = 0;
$total_supplier_payables_usd = 0;
$total_supplier_payables_syp = 0;
$expenses_breakdown = [];
$error_msg = '';

// 2. إعداد الفلاتر الزمنية (يومي، أسبوعي، شهري، مخصص)
$filter_type = $_GET['filter_type'] ?? 'monthly';
$start_date  = $_GET['start_date'] ?? date('Y-m-01');
$end_date    = $_GET['end_date'] ?? date('Y-m-t');

if ($filter_type == 'daily') {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
} elseif ($filter_type == 'weekly') {
    $start_date = date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime('sunday this week'));
} elseif ($filter_type == 'monthly') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
}

// 3. استخراج البيانات من البنية الفعلية للجداول (مطابقة لـ sales.php وexpenses.php وsupplier_view.php)
try {
    // أ) إجمالي الإيرادات: فقط الفواتير المسلَّمة فعلياً (نفس قاعدة الترحيل الذكي المعتمدة في كامل النظام)
    // تصحيح: خصم قيمة أي مرتجع حدث على هذه الفواتير — وإلا يبقى الإيراد المعروض هنا أعلى من الإيراد
    // الحقيقي المُسجَّل في القيود (والذي يُخفَّض فعلياً بقيد عكسي عند كل مرتجع في sales.php)
    $stmt_rev = $conn->prepare("
        SELECT COALESCE(SUM(s.total_amount_syp), 0) - COALESCE((
            SELECT SUM(sr.total_amount_syp)
            FROM sales_returns sr
            JOIN sales s2 ON sr.sale_id = s2.id
            WHERE s2.delivery_status = 'Delivered' AND s2.invoice_date BETWEEN ? AND ?
        ), 0) AS net_revenue
        FROM sales s
        WHERE s.delivery_status = 'Delivered' AND s.invoice_date BETWEEN ? AND ?
    ");
    $stmt_rev->execute([$start_date, $end_date, $start_date, $end_date]);
    $total_revenue = floatval($stmt_rev->fetchColumn());

    // ب) تكلفة البضائع المباعة (COGS): تصحيح جوهري لدقة تاريخية حقيقية —
    // تُستخدم الآن sale_items.cost_price_usd_at_sale (التكلفة المثبتة *وقت البيع بالضبط*)
    // بدل products.cost_price_usd (التكلفة *الحالية*، التي لو تغيّرت لاحقاً كانت تُغيِّر تقارير أرباح
    // أشهر ماضية بأثر رجعي خطأً). للفواتير القديمة السابقة لهذا التصحيح (العمود لديها NULL)، يُستخدم
    // COALESCE للتراجع إلى تكلفة المنتج الحالية كأفضل تقدير متاح.
    //
    // تصحيح إضافي جوهري: الكمية المُحتسَبة تُخصَم منها أي كمية أُرجِعت لاحقاً من نفس السطر
    // (sale_items.quantity - المُرجَع من sales_return_items لهذا السطر تحديداً)، وإلا يبقى COGS
    // محتسَباً على الكمية الأصلية المباعة بالكامل حتى لو أُعيد جزء منها فعلياً للمخزون — وهو ما كان
    // يجعل هذا التقرير يفوق قيمة COGS الحقيقية بينما القوائم الرسمية (المبنية من القيود الفعلية،
    // ومنها قيد عكس COGS عند كل مرتجع) تُظهر الرقم الصحيح.
    $stmt_cogs = $conn->prepare("
        SELECT 
            COALESCE(SUM(
                (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
                * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate
            ), 0) AS total_cogs_syp,
            COALESCE(SUM(
                (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
                * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)
            ), 0) AS total_cogs_usd
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Delivered' AND s.invoice_date BETWEEN ? AND ?
    ");
    $stmt_cogs->execute([$start_date, $end_date]);
    $cogs_data = $stmt_cogs->fetch(PDO::FETCH_ASSOC);
    $total_cogs_syp = floatval($cogs_data['total_cogs_syp']);
    $total_cogs_usd = floatval($cogs_data['total_cogs_usd']);

    // ج) العمولات: total_commissions (جمع) هو العمود الفعلي، بنفس شرط التسليم
    // تصحيح: خصم أي عمولة عُكِسَت فعلياً بسبب مرتجع جزئي/كامل على هذه الفاتورة (نفس منطق COGS أعلاه)
    $stmt_comm = $conn->prepare("
        SELECT COALESCE(SUM(s.total_commissions), 0) - COALESCE((
            SELECT SUM(sr.total_commission_reversed)
            FROM sales_returns sr
            JOIN sales s2 ON sr.sale_id = s2.id
            WHERE s2.delivery_status = 'Delivered' AND s2.invoice_date BETWEEN ? AND ?
        ), 0) AS net_commissions
        FROM sales s
        WHERE s.delivery_status = 'Delivered' AND s.invoice_date BETWEEN ? AND ?
    ");
    $stmt_comm->execute([$start_date, $end_date, $start_date, $end_date]);
    $total_commissions = floatval($stmt_comm->fetchColumn());

    // د) المصاريف التشغيلية: من الجدول الفعلي operational_expenses (وليس جدول expenses موازٍ فارغ)
    $stmt_exp = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM operational_expenses WHERE expense_date BETWEEN ? AND ?");
    $stmt_exp->execute([$start_date, $end_date]);
    $total_expenses = floatval($stmt_exp->fetchColumn());

    $stmt_exp_details = $conn->prepare("SELECT category AS expense_category, SUM(amount) AS cat_total FROM operational_expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY cat_total DESC");
    $stmt_exp_details->execute([$start_date, $end_date]);
    $expenses_breakdown = $stmt_exp_details->fetchAll(PDO::FETCH_ASSOC);

    // هـ) صافي الربح الحقيقي
    $net_profit = $total_revenue - ($total_cogs_syp + $total_commissions + $total_expenses);

    // و) ذمم الموردين والخصوم: لا يوجد رصيد مخزَّن، يُحسب لحظياً بنفس منطق supplier_view.php
    // (إجمالي المشتريات - إجمالي المدفوعات - المردودات/الخصومات)، وهو رصيد إجمالي حالي غير مرتبط
    // بفترة التقرير الزمنية لأنه التزام قائم لحظة عرض التقرير.
    $stmt_sup = $conn->query("
        SELECT COALESCE(SUM(
            (SELECT COALESCE(SUM(p.purchased_quantity * p.cost_price_usd), 0) FROM products p WHERE p.supplier_id = s.id)
            - (SELECT COALESCE(SUM(sp.amount_usd), 0) FROM supplier_payments sp WHERE sp.supplier_id = s.id)
            - COALESCE(s.returns_discounts, 0)
        ), 0) AS total_payables_usd
        FROM suppliers s
    ");
    $total_supplier_payables_usd = floatval($stmt_sup->fetchColumn());

    // تحويل ذمم الموردين (بالدولار) إلى ما يعادلها بالليرة باستخدام أحدث سعر صرف معتمد (نفس آلية sales.php)
    $exchange_rate_now = 15000;
    try {
        $stmt_rate = $conn->query("SELECT exchange_rate FROM exchange_rates WHERE currency_code = 'USD' ORDER BY rate_date DESC, id DESC LIMIT 1");
        $fetched_rate = $stmt_rate->fetchColumn();
        if ($fetched_rate && $fetched_rate > 0) { $exchange_rate_now = $fetched_rate; }
    } catch (Exception $e) { /* الاعتماد على القيمة الافتراضية عند التعذر */ }
    $total_supplier_payables_syp = $total_supplier_payables_usd * $exchange_rate_now;

} catch (Exception $e) {
    $error_msg = "تنبيه في مطابقة بعض حقول قاعدة البيانات: " . $e->getMessage();
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="color: #2e384d; margin-bottom: 5px;"><i class="fas fa-chart-line"></i> التقارير المالية والتحليلية المتقدمة</h2>
        <p style="color: #6c757d; margin: 0; font-size: 14px;">لوحة الأرباح الشاملة، حسابات COGS بالدولار التاريخي، ميزان المراجعة، وذمم الموردين.</p>
    </div>
    <div>
        <button onclick="window.print();" style="background: #4e73df; color: white; padding: 10px 18px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <i class="fas fa-print"></i> طباعة التقرير المالي
        </button>
    </div>
</div>

<!-- شريط الفلترة الزمنية المرنة -->
<div style="background: #fff; padding: 15px 20px; border-radius: 8px; border: 1px solid #e3e6f0; margin-bottom: 25px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
        <div>
            <label style="display: block; font-size: 12px; font-weight: bold; color: #555; margin-bottom: 5px;">فترة التقرير:</label>
            <select name="filter_type" id="filter_type" onchange="toggleCustomDates(this.value)" style="padding: 8px 12px; border: 1px solid #d1d3e2; border-radius: 6px; font-size: 14px;">
                <option value="daily" <?php echo ($filter_type == 'daily') ? 'selected' : ''; ?>>اليوم</option>
                <option value="weekly" <?php echo ($filter_type == 'weekly') ? 'selected' : ''; ?>>هذا الأسبوع</option>
                <option value="monthly" <?php echo ($filter_type == 'monthly') ? 'selected' : ''; ?>>هذا الشهر</option>
                <option value="custom" <?php echo ($filter_type == 'custom') ? 'selected' : ''; ?>>فترة مخصصة</option>
            </select>
        </div>
        <div id="custom_start_div" style="display: <?php echo ($filter_type == 'custom') ? 'block' : 'none'; ?>;">
            <label style="display: block; font-size: 12px; font-weight: bold; color: #555; margin-bottom: 5px;">من تاريخ:</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" style="padding: 7px 10px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
        </div>
        <div id="custom_end_div" style="display: <?php echo ($filter_type == 'custom') ? 'block' : 'none'; ?>;">
            <label style="display: block; font-size: 12px; font-weight: bold; color: #555; margin-bottom: 5px;">إلى تاريخ:</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" style="padding: 7px 10px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
        </div>
        <div>
            <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px;">
                <i class="fas fa-filter"></i> تطبيق الفلتر
            </button>
        </div>
    </form>
</div>

<?php if (!empty($error_msg)): ?>
    <div style="background: #fff3cd; color: #856404; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #ffeeba;">
        <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
    </div>
<?php endif; ?>

<!-- لوحة الأرباح والملخص المالي الرئيسي -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 20px; margin-bottom: 30px;">
    
    <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #4e73df; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <span style="color: #6c757d; font-size: 13px; font-weight: bold;"><i class="fas fa-cash-register"></i> إجمالي الإيرادات</span>
        <h3 style="color: #4e73df; margin: 8px 0 0; font-family: monospace; font-size: 22px;"><?php echo number_format($total_revenue, 2); ?> <span style="font-size: 12px;">ل.س</span></h3>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #e74a3b; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <span style="color: #6c757d; font-size: 13px; font-weight: bold;"><i class="fas fa-boxes"></i> تكلفة البضائع (COGS)</span>
        <h3 style="color: #e74a3b; margin: 8px 0 0; font-family: monospace; font-size: 20px;"><?php echo number_format($total_cogs_syp, 2); ?> <span style="font-size: 12px;">ل.س</span></h3>
        <span style="font-size: 11px; color: #888; font-family: monospace;">(<?php echo number_format($total_cogs_usd, 2); ?> $ بسعر الصرف التاريخي لكل فاتورة)</span>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #f6c23e; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <span style="color: #6c757d; font-size: 13px; font-weight: bold;"><i class="fas fa-handshake"></i> العمولات والمصاريف</span>
        <h3 style="color: #f6c23e; margin: 8px 0 0; font-family: monospace; font-size: 22px;"><?php echo number_format($total_commissions + $total_expenses, 2); ?> <span style="font-size: 12px;">ل.س</span></h3>
        <span style="font-size: 11px; color: #888;">(عمولات: <?php echo number_format($total_commissions, 0); ?> | مصاريف: <?php echo number_format($total_expenses, 0); ?>)</span>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #1cc88a; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <span style="color: #6c757d; font-size: 13px; font-weight: bold;"><i class="fas fa-chart-pie"></i> صافي الربح الحقيقي</span>
        <h3 style="color: #1cc88a; margin: 8px 0 0; font-family: monospace; font-size: 22px;"><?php echo number_format($net_profit, 2); ?> <span style="font-size: 12px;">ل.س</span></h3>
    </div>

</div>

<!-- تفاصيل المصاريف التشغيلية وذمم الموردين -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px;">
    
    <!-- تفاصيل المصاريف التشغيلية للفترة -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0;">
            <h3 style="margin: 0; color: #4e73df; font-size: 16px;"><i class="fas fa-receipt"></i> تفاصيل المصاريف التشغيلية حسب التصنيف</h3>
        </div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
                <thead>
                    <tr style="background: #fdfdfe; color: #555; border-bottom: 2px solid #e3e6f0;">
                        <th style="padding: 12px 15px;">تصنيف المصروف</th>
                        <th style="padding: 12px 15px;">المبلغ الإجمالي (ل.س)</th>
                        <th style="padding: 12px 15px; text-align: left;">النسبة من إجمالي المصاريف</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($expenses_breakdown)): ?>
                        <?php foreach ($expenses_breakdown as $exp): 
                            $percentage = ($total_expenses > 0) ? ($exp['cat_total'] / $total_expenses) * 100 : 0;
                        ?>
                            <tr style="border-bottom: 1px solid #f1f1f1;">
                                <td style="padding: 12px 15px; font-weight: bold; color: #333;"><?php echo htmlspecialchars($exp['expense_category']); ?></td>
                                <td style="padding: 12px 15px; font-family: monospace; color: #e74a3b; font-weight: bold;"><?php echo number_format($exp['cat_total'], 2); ?> ل.س</td>
                                <td style="padding: 12px 15px; font-family: monospace; text-align: left; color: #666;"><?php echo number_format($percentage, 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="padding: 30px; text-align: center; color: #777;">لا توجد مصاريف تشغيلية مسجلة في هذه الفترة.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- إجمالي الخصوم وذمم الموردين -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0;">
            <h3 style="margin: 0; color: #e74a3b; font-size: 16px;"><i class="fas fa-file-invoice-dollar"></i> الخصوم وذمم الموردين</h3>
        </div>
        <div style="padding: 25px; text-align: center;">
            <span style="color: #6c757d; font-size: 14px; font-weight: bold; display: block; margin-bottom: 10px;">إجمالي الالتزامات المستحقة للموردين (رصيد حالي)</span>
            <h2 style="color: #e74a3b; font-family: monospace; margin: 0 0 5px; font-size: 26px;">$<?php echo number_format($total_supplier_payables_usd, 2); ?></h2>
            <span style="font-size: 12px; color: #888; font-family: monospace;">≈ <?php echo number_format($total_supplier_payables_syp, 2); ?> ل.س</span>
            <hr style="border: none; border-top: 1px solid #eee; margin: 15px 0;">
            <a href="suppliers.php" style="background: #4e73df; color: white; padding: 8px 15px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; display: inline-block;">
                <i class="fas fa-users"></i> إدارة حسابات الموردين
            </a>
        </div>
    </div>

</div>

<!-- ميزان المراجعة للأرصدة (Trial Balance Summary) -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08); margin-bottom: 30px;">
    <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0;">
        <h3 style="margin: 0; color: #2e384d; font-size: 16px;"><i class="fas fa-balance-scale"></i> ميزان المراجعة المختصر للأرصدة</h3>
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; color: #555; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">رقم الحساب / المجموعة</th>
                    <th style="padding: 12px 15px;">اسم الحساب الرئيسي</th>
                    <th style="padding: 12px 15px; text-align: left;">مدين (ل.س)</th>
                    <th style="padding: 12px 15px; text-align: left;">دائن (ل.س)</th>
                </tr>
            </thead>
            <tbody>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 12px 15px; font-family: monospace; color: #666;">101</td>
                    <td style="padding: 12px 15px; font-weight: bold;">إجمالي الإيرادات والمبيعات</td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left;">-</td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left; color: #1cc88a; font-weight: bold;"><?php echo number_format($total_revenue, 2); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 12px 15px; font-family: monospace; color: #666;">201</td>
                    <td style="padding: 12px 15px; font-weight: bold;">ذمم الموردين والخصوم</td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left;">-</td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left; color: #e74a3b; font-weight: bold;"><?php echo number_format($total_supplier_payables_syp, 2); ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 12px 15px; font-family: monospace; color: #666;">301</td>
                    <td style="padding: 12px 15px; font-weight: bold;">تكلفة البضائع المباعة (COGS)</td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left; color: #e74a3b; font-weight: bold;"><?php echo number_format($total_cogs_syp, 2); ?></td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left;">-</td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 12px 15px; font-family: monospace; color: #666;">302</td>
                    <td style="padding: 12px 15px; font-weight: bold;">العمولات التشغيلية للمندوبين</td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left; color: #e74a3b; font-weight: bold;"><?php echo number_format($total_commissions, 2); ?></td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left;">-</td>
                </tr>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 12px 15px; font-family: monospace; color: #666;">303</td>
                    <td style="padding: 12px 15px; font-weight: bold;">المصاريف التشغيلية العامة</td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left; color: #e74a3b; font-weight: bold;"><?php echo number_format($total_expenses, 2); ?></td>
                    <td style="padding: 12px 15px; font-family: monospace; text-align: left;">-</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    function toggleCustomDates(val) {
        const startDiv = document.getElementById('custom_start_div');
        const endDiv = document.getElementById('custom_end_div');
        if (val === 'custom') {
            startDiv.style.display = 'block';
            endDiv.style.display = 'block';
        } else {
            startDiv.style.display = 'none';
            endDiv.style.display = 'none';
        }
    }
</script>

<?php include 'footer.php'; ?>