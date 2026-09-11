<?php
include 'header.php';

// ============================================================
// فلتر الفترة: يومي / أسبوعي (السبت إلى الخميس) / تاريخ معيّن
// ============================================================
$filter_type = $_GET['filter_type'] ?? 'daily';
$today_str = date('Y-m-d');

if ($filter_type === 'weekly') {
    $dow = intval(date('w')); // 0=أحد ... 6=سبت
    $days_since_saturday = ($dow + 1) % 7;
    $start_date = date('Y-m-d', strtotime("-{$days_since_saturday} days"));
    $end_date = date('Y-m-d', strtotime($start_date . ' +5 days'));
} elseif ($filter_type === 'specific' && !empty($_GET['start_date'])) {
    $start_date = $_GET['start_date'];
    $end_date = $_GET['start_date'];
} else {
    $filter_type = 'daily';
    $start_date = $today_str;
    $end_date = $today_str;
}

$dash_error = '';
$dash_revenue = 0; $dash_cogs = 0; $dash_cogs_usd = 0; $dash_commissions = 0; $dash_expenses = 0;
$dash_total_expenses_combined = 0;
$pending = ['distinct_products' => 0, 'total_qty' => 0, 'total_value_syp' => 0, 'total_cost_usd' => 0, 'total_cost_syp' => 0];
$delivered_all = ['distinct_products' => 0, 'total_qty' => 0, 'total_value_syp' => 0, 'total_cost_usd' => 0, 'total_cost_syp' => 0];
$net_profit_dash = 0;

try {
    $stmt_rev_dash = $conn->prepare("
        SELECT COALESCE(SUM(je.credit) - SUM(je.debit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'إيرادات المبيعات' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_rev_dash->execute([$start_date, $end_date]);
    $dash_revenue = floatval($stmt_rev_dash->fetchColumn());

    // تصحيح: القراءة الآن مباشرة من دفتر اليومية (حساب "تكلفة البضائع المباعة") بدل إعادة الحساب من
    // الصفر انطلاقاً من sale_items.cost_price_usd_at_sale — لأن الأخيرة تتجاهل أي قيود تصحيح تاريخية
    // لاحقة (مثل قيود "JE-COGSFIX-*" من تصحيح المتوسط المرجَّح السابق)، فتُنتج رقماً أقل من الحقيقة.
    // هذا يطابق الآن منهجية financial_statements.php بالضبط، فيتطابق الرقمان في كلتا الصفحتين دائماً.
    $stmt_cogs_dash = $conn->prepare("
        SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0) AS cogs_syp
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'تكلفة البضائع المباعة (COGS)' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_cogs_dash->execute([$start_date, $end_date]);
    $dash_cogs = floatval($stmt_cogs_dash->fetchColumn());

    $stmt_cogs_usd_dash2 = $conn->prepare("
        SELECT COALESCE(SUM(je.foreign_debit) - SUM(je.foreign_credit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'تكلفة البضائع المباعة (COGS)' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_cogs_usd_dash2->execute([$start_date, $end_date]);
    $dash_cogs_usd = floatval($stmt_cogs_usd_dash2->fetchColumn());

    try {
        $stmt_exp_dash = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM operational_expenses WHERE expense_date BETWEEN ? AND ?");
        $stmt_exp_dash->execute([$start_date, $end_date]);
        $dash_expenses = floatval($stmt_exp_dash->fetchColumn());
    } catch (Exception $e) { }
    $dash_total_expenses_combined = $dash_expenses;

    $stmt_comm_dash = $conn->prepare("
        SELECT COALESCE(SUM(s.total_commissions), 0)
            - COALESCE((SELECT SUM(sr.total_commission_reversed) FROM sales_returns sr JOIN sales s2 ON sr.sale_id = s2.id
                        WHERE s2.delivery_status = 'Delivered' AND COALESCE(s2.delivered_at, s2.invoice_date) BETWEEN ? AND ?), 0)
        FROM sales s
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_comm_dash->execute([$start_date, $end_date, $start_date, $end_date]);
    $dash_commissions = floatval($stmt_comm_dash->fetchColumn());

    $stmt_pending = $conn->prepare("
        SELECT COUNT(DISTINCT si.product_id) AS distinct_products,
               COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS total_qty,
               COALESCE(SUM(si.total_price_syp - COALESCE((SELECT SUM(sri.total_price_syp) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS total_value_syp,
               COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)), 0) AS total_cost_usd,
               COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate), 0) AS total_cost_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Pending' AND s.invoice_date BETWEEN ? AND ?
    ");
    $stmt_pending->execute([$start_date, $end_date]);
    $pending = $stmt_pending->fetch(PDO::FETCH_ASSOC);

    $stmt_delivered_all = $conn->prepare("
        SELECT COUNT(DISTINCT si.product_id) AS distinct_products,
               COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS total_qty,
               COALESCE(SUM(si.total_price_syp - COALESCE((SELECT SUM(sri.total_price_syp) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS total_value_syp,
               COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)), 0) AS total_cost_usd,
               COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate), 0) AS total_cost_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_delivered_all->execute([$start_date, $end_date]);
    $delivered_all = $stmt_delivered_all->fetch(PDO::FETCH_ASSOC);

    // تصحيح: تكاليف الشحن كانت مفقودة سهواً من معادلة صافي الربح عند إعادة تصميم اللوحة بالبطاقات
    // السبع — لا بطاقة خاصة لها، لكنها تبقى مصروفاً حقيقياً يجب خصمه دائماً، تماشياً مع
    // financial_statements.php بالضبط.
    $dash_shipping = 0;
    try {
        $stmt_ship_dash = $conn->prepare("SELECT COALESCE(SUM(shipping_cost_syp), 0) FROM sales WHERE invoice_date BETWEEN ? AND ?");
        $stmt_ship_dash->execute([$start_date, $end_date]);
        $dash_shipping = floatval($stmt_ship_dash->fetchColumn());
    } catch (Exception $e) { }

    $net_profit_dash = $dash_revenue - $dash_cogs - $dash_commissions - $dash_total_expenses_combined - $dash_shipping;

} catch (Exception $e) {
    $dash_error = "تنبيه: تعذّر حساب بعض المؤشرات — " . $e->getMessage();
}

$filter_labels = ['daily' => 'اليوم', 'weekly' => 'هذا الأسبوع (سبت-خميس)', 'specific' => 'التاريخ المحدَّد'];

$sec2_start = $_GET['sec2_start'] ?? date('Y-m-01');
$sec2_end   = $_GET['sec2_end'] ?? date('Y-m-t');
$sec2_revenue = 0; $sec2_expenses = 0; $sec2_payroll = 0; $sec2_commissions = 0;
$sec2_shipping = 0; $sec2_supplier_payments = 0; $sec2_net = 0;

try {
    $stmt_s2_rev = $conn->prepare("
        SELECT COALESCE(SUM(je.credit) - SUM(je.debit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'إيرادات المبيعات' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_s2_rev->execute([$sec2_start, $sec2_end]);
    $sec2_revenue = floatval($stmt_s2_rev->fetchColumn());

    $stmt_s2_exp = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM operational_expenses WHERE expense_date BETWEEN ? AND ?");
    $stmt_s2_exp->execute([$sec2_start, $sec2_end]);
    $sec2_expenses = floatval($stmt_s2_exp->fetchColumn());

    $stmt_s2_payroll = $conn->prepare("
        SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name IN ('الرواتب والأجور', 'مصروف حوافز ومكافآت الموظفين')
          AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_s2_payroll->execute([$sec2_start, $sec2_end]);
    $sec2_payroll = floatval($stmt_s2_payroll->fetchColumn());

    $stmt_s2_comm = $conn->prepare("
        SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'مصروف عمولات المندوبين' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_s2_comm->execute([$sec2_start, $sec2_end]);
    $sec2_commissions = floatval($stmt_s2_comm->fetchColumn());

    $stmt_s2_ship = $conn->prepare("SELECT COALESCE(SUM(shipping_cost_syp), 0) FROM sales WHERE invoice_date BETWEEN ? AND ?");
    $stmt_s2_ship->execute([$sec2_start, $sec2_end]);
    $sec2_shipping = floatval($stmt_s2_ship->fetchColumn());

    $stmt_s2_sp = $conn->prepare("
        SELECT sp.amount_usd, sp.payment_date FROM supplier_payments sp
        WHERE sp.payment_date BETWEEN ? AND ?
    ");
    $stmt_s2_sp->execute([$sec2_start, $sec2_end]);
    foreach ($stmt_s2_sp->fetchAll(PDO::FETCH_ASSOC) as $sp_row) {
        $sp_rate = getExchangeRateForDate($conn, 'USD', $sp_row['payment_date']);
        $sec2_supplier_payments += floatval($sp_row['amount_usd']) * $sp_rate;
    }

    $sec2_net = $sec2_revenue - ($sec2_expenses + $sec2_payroll + $sec2_commissions + $sec2_shipping + $sec2_supplier_payments);
} catch (Exception $e) { }

$sec3_start = $_GET['sec3_start'] ?? date('Y-m-01');
$sec3_end   = $_GET['sec3_end'] ?? date('Y-m-t');

$sec3_lifetime_usd = 0;
$sec3_current_qty = 0; $sec3_current_usd = 0;
$sec3_sold_qty = 0; $sec3_sold_value_syp = 0; $sec3_sold_value_usd = 0;
$sec3_profit_syp = 0;
$sec3_delivered_qty = 0; $sec3_pending_qty = 0; $sec3_pending_value_syp = 0; $sec3_pending_value_usd = 0;

try {
    $stmt_s3_lifetime = $conn->query("
        SELECT
            COALESCE(SUM(p.current_quantity * p.cost_price_usd), 0)
            +
            COALESCE(SUM(
                (SELECT COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0)
                 FROM sale_items si WHERE si.product_id = p.id)
                * p.cost_price_usd
            ), 0) AS lifetime_total
        FROM products p WHERE p.supplier_id IS NULL
    ");
    $sec3_lifetime_usd = floatval($stmt_s3_lifetime->fetchColumn());

    $stmt_s3_current = $conn->query("
        SELECT COALESCE(SUM(current_quantity), 0) AS qty, COALESCE(SUM(current_quantity * cost_price_usd), 0) AS usd
        FROM products WHERE supplier_id IS NULL
    ");
    $s3c = $stmt_s3_current->fetch(PDO::FETCH_ASSOC);
    $sec3_current_qty = floatval($s3c['qty']);
    $sec3_current_usd = floatval($s3c['usd']);

    $stmt_s3_sales = $conn->prepare("
        SELECT
            COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS pieces_sold,
            COALESCE(SUM(si.total_price_syp - COALESCE((SELECT SUM(sri.total_price_syp) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS revenue_syp,
            COALESCE(SUM(
                (si.total_price_syp - COALESCE((SELECT SUM(sri.total_price_syp) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) / NULLIF(s.exchange_rate, 0)
            ), 0) AS revenue_usd,
            COALESCE(SUM(
                (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
                * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate
            ), 0) AS cogs_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE p.supplier_id IS NULL
          AND s.delivery_status = 'Delivered'
          AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_s3_sales->execute([$sec3_start, $sec3_end]);
    $s3s = $stmt_s3_sales->fetch(PDO::FETCH_ASSOC);
    $sec3_sold_qty = floatval($s3s['pieces_sold']);
    $sec3_sold_value_syp = floatval($s3s['revenue_syp']);
    $sec3_sold_value_usd = floatval($s3s['revenue_usd']);
    $sec3_profit_syp = floatval($s3s['revenue_syp']) - floatval($s3s['cogs_syp']);

    $stmt_s3_delivered = $conn->prepare("
        SELECT COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0)
        FROM sale_items si INNER JOIN sales s ON si.sale_id = s.id INNER JOIN products p ON si.product_id = p.id
        WHERE p.supplier_id IS NULL AND s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_s3_delivered->execute([$sec3_start, $sec3_end]);
    $sec3_delivered_qty = floatval($stmt_s3_delivered->fetchColumn());

    $stmt_s3_pending = $conn->prepare("
        SELECT COALESCE(SUM(si.quantity), 0) AS qty,
               COALESCE(SUM(si.total_price_syp), 0) AS value_syp,
               COALESCE(SUM(si.total_price_syp / NULLIF(s.exchange_rate, 0)), 0) AS value_usd
        FROM sale_items si INNER JOIN sales s ON si.sale_id = s.id INNER JOIN products p ON si.product_id = p.id
        WHERE p.supplier_id IS NULL AND s.delivery_status = 'Pending' AND s.invoice_date BETWEEN ? AND ?
    ");
    $stmt_s3_pending->execute([$sec3_start, $sec3_end]);
    $s3p = $stmt_s3_pending->fetch(PDO::FETCH_ASSOC);
    $sec3_pending_qty = floatval($s3p['qty']);
    $sec3_pending_value_syp = floatval($s3p['value_syp']);
    $sec3_pending_value_usd = floatval($s3p['value_usd']);
} catch (Exception $e) { }

// ============================================================
// قسم مستقل رابع: أولوية سداد الموردين — ترتيب كل مورد حسب صافي المبلغ المستحق له فعلياً (الأعلى
// أولاً)، لمساعدتك على تحديد من تسدِّد له أولاً بناءً على حجم الالتزام تجاهه. رصيد لحظي حالي دائماً
// (لا فلتر فترة، لأنه "من نحن مدينون له الآن" لا "حركة ضمن فترة").
// ============================================================
$supplier_priority_list = [];
try {
    $stmt_sup_priority = $conn->query("
        SELECT s.id, s.supplier_name,
            (
                COALESCE((SELECT SUM(pii.total_cost_usd) FROM purchase_invoice_items pii
                    INNER JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
                    WHERE pi.supplier_id = s.id AND pi.payment_status != 'Paid'), 0)
              + COALESCE((SELECT SUM(GREATEST(0, p.purchased_quantity - COALESCE((SELECT SUM(pii2.quantity) FROM purchase_invoice_items pii2 WHERE pii2.product_id = p.id), 0)) * p.cost_price_usd), 0)
                    FROM products p WHERE p.supplier_id = s.id)
              - COALESCE((SELECT SUM(sp.amount_usd) FROM supplier_payments sp WHERE sp.supplier_id = s.id), 0)
              - (COALESCE(s.returns_discounts, 0)
                    + COALESCE((SELECT SUM(pr.total_amount_usd) FROM purchase_returns pr
                        INNER JOIN purchase_invoices pi3 ON pr.purchase_invoice_id = pi3.id
                        WHERE pi3.supplier_id = s.id AND pi3.payment_status != 'Paid'), 0)
                    + COALESCE((SELECT SUM(sd.amount_usd) FROM supplier_discounts sd WHERE sd.supplier_id = s.id), 0))
              + COALESCE(s.opening_balance_usd, 0)
            ) AS net_balance_usd
        FROM suppliers s
        HAVING net_balance_usd > 0.01
        ORDER BY net_balance_usd DESC
        LIMIT 10
    ");
    $supplier_priority_list = $stmt_sup_priority->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* يُتجاهل إن تعذّر */ }
?>

<div style="padding: 20px;">
    <div style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); color: white; padding: 25px; border-radius: 10px; margin-bottom: 25px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,0.15);">
        <h1 style="margin: 0 0 10px 0; font-size: 24px;">مرحباً بك، لؤي القبالان</h1>
        <p style="margin: 0; opacity: 0.9; font-size: 14px;">نظام Smart ERP المطور يعمل بكفاءة عالية. يمكنك إدارة الحسابات، العملات، والقيود من القائمة الجانبية.</p>
    </div>

    <?php if ($dash_error): ?>
        <div style="background: #fff3cd; color: #856404; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($dash_error); ?></div>
    <?php endif; ?>

    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <span style="font-size: 13px; font-weight: bold; color: #555;"><i class="fas fa-filter"></i> الفترة المعروضة:</span>
        <a href="?filter_type=daily" style="text-decoration: none;">
            <span style="padding: 7px 16px; border-radius: 5px; font-size: 13px; font-weight: bold; background: <?php echo $filter_type === 'daily' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $filter_type === 'daily' ? '#fff' : '#4e73df'; ?>;">يومي</span>
        </a>
        <a href="?filter_type=weekly" style="text-decoration: none;">
            <span style="padding: 7px 16px; border-radius: 5px; font-size: 13px; font-weight: bold; background: <?php echo $filter_type === 'weekly' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $filter_type === 'weekly' ? '#fff' : '#4e73df'; ?>;">أسبوعي (سبت-خميس)</span>
        </a>
        <span style="width: 1px; height: 24px; background: #e3e6f0;"></span>
        <form method="GET" style="display: flex; gap: 8px; align-items: center;">
            <input type="hidden" name="filter_type" value="specific">
            <label style="font-size: 13px; color: #555;">تاريخ معيّن:</label>
            <input type="date" name="start_date" value="<?php echo $filter_type === 'specific' ? htmlspecialchars($start_date) : ''; ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <button type="submit" style="background: #6f42c1; color: white; border: none; padding: 7px 14px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold;">تطبيق</button>
        </form>
        <span style="font-size: 12.5px; color: #888; margin-right: auto;">(<?php echo htmlspecialchars($start_date); ?> إلى <?php echo htmlspecialchars($end_date); ?>)</span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 20px; margin-bottom: 25px;">

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #4e73df; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-cash-register"></i> إجمالي الإيرادات (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #4e73df; font-family: monospace;"><?php echo number_format($dash_revenue, 2); ?> ل.س</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #e74a3b; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-boxes"></i> تكلفة البضائع - COGS (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #e74a3b; font-family: monospace;"><?php echo number_format($dash_cogs, 2); ?> ل.س</div>
            <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;">≈ $<?php echo number_format($dash_cogs_usd, 2); ?></div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #f6c23e; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-receipt"></i> المصاريف — تشغيلية + متكررة (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace;"><?php echo number_format($dash_total_expenses_combined, 2); ?> ل.س</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #fd7e14; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-handshake"></i> عمولات المندوبين (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #fd7e14; font-family: monospace;"><?php echo number_format($dash_commissions, 2); ?> ل.س</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #856404; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-clock"></i> منتجات قيد الانتظار (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #856404; font-family: monospace;"><?php echo intval($pending['distinct_products']); ?> صنف / <?php echo rtrim(rtrim(number_format($pending['total_qty'], 2), '0'), '.'); ?> قطعة</div>
            <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;">قيمة البيع: <?php echo number_format($pending['total_value_syp'], 2); ?> ل.س</div>
            <div style="font-size: 13px; color: #e74a3b; font-family: monospace; margin-top: 2px;">رأس المال: <?php echo number_format($pending['total_cost_syp'], 2); ?> ل.س (≈ $<?php echo number_format($pending['total_cost_usd'], 2); ?>)</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #1cc88a; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-truck"></i> منتجات تم تسليمها (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #1cc88a; font-family: monospace;"><?php echo intval($delivered_all['distinct_products']); ?> صنف / <?php echo rtrim(rtrim(number_format($delivered_all['total_qty'], 2), '0'), '.'); ?> قطعة</div>
            <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;">قيمة البيع: <?php echo number_format($delivered_all['total_value_syp'], 2); ?> ل.س</div>
            <div style="font-size: 13px; color: #e74a3b; font-family: monospace; margin-top: 2px;">رأس المال: <?php echo number_format($delivered_all['total_cost_syp'], 2); ?> ل.س (≈ $<?php echo number_format($delivered_all['total_cost_usd'], 2); ?>)</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #e74a3b; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-chart-line"></i> صافي الربح (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: <?php echo $net_profit_dash >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace;"><?php echo number_format($net_profit_dash, 2); ?> ل.س</div>
        </div>

    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <i class="fas fa-balance-scale"></i> تحليل الإيرادات والمصاريف الشاملة (فلتر مستقل بتاريخ مخصَّص)
        </h3>
        <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 15px 0;">
            <?php foreach ($_GET as $k => $v) { if (strpos($k, 'sec2_') !== 0) echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">'; } ?>
            <label style="font-size: 13px; font-weight: bold; color: #555;">من:</label>
            <input type="date" name="sec2_start" value="<?php echo htmlspecialchars($sec2_start); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <label style="font-size: 13px; font-weight: bold; color: #555;">إلى:</label>
            <input type="date" name="sec2_end" value="<?php echo htmlspecialchars($sec2_end); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <button type="submit" style="background: #6f42c1; color: white; border: none; padding: 7px 16px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold;">تطبيق</button>
        </form>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
            <div style="background: #eaf1fc; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px;">
                <div style="color: #2c4e9c; font-size: 12.5px; font-weight: bold;">إجمالي الإيرادات</div>
                <div style="font-size: 19px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_revenue, 2); ?> ل.س</div>
            </div>
            <div style="background: #fdecea; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px;">
                <div style="color: #a33636; font-size: 12.5px; font-weight: bold;">إجمالي المصاريف الشاملة</div>
                <div style="font-size: 19px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_expenses + $sec2_payroll + $sec2_commissions + $sec2_shipping + $sec2_supplier_payments, 2); ?> ل.س</div>
                <div style="font-size: 10.5px; color: #a33636; margin-top: 4px; line-height: 1.6;">
                    مصاريف: <?php echo number_format($sec2_expenses, 0); ?> | رواتب: <?php echo number_format($sec2_payroll, 0); ?> |
                    عمولات: <?php echo number_format($sec2_commissions, 0); ?> | شحن: <?php echo number_format($sec2_shipping, 0); ?> |
                    دفعات موردين: <?php echo number_format($sec2_supplier_payments, 0); ?>
                </div>
            </div>
            <div style="background: <?php echo $sec2_net >= 0 ? '#e8f8f2' : '#fdecea'; ?>; border-right: 4px solid <?php echo $sec2_net >= 0 ? '#1cc88a' : '#e74a3b'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #555; font-size: 12.5px; font-weight: bold;">صافي الأرباح</div>
                <div style="font-size: 21px; font-weight: bold; color: <?php echo $sec2_net >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_net, 2); ?> ل.س</div>
            </div>
        </div>
        <p style="font-size: 11.5px; color: #999; margin: 12px 0 0;">
            <i class="fas fa-info-circle"></i> ملاحظة: "دفعات الموردين" هنا حركة نقدية فعلية (سداد التزام سابق)، وليست مصروفاً محاسبياً بالمعنى الدقيق (لا تُحتسَب في "صافي الربح" الرسمي بالقوائم المالية) — أُدرِجت هنا بناءً على طلبك لتحليل التدفق النقدي الفعلي فقط.
        </p>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <i class="fas fa-warehouse"></i> الجرد المكتبي (فلتر مستقل بتاريخ مخصَّص)
        </h3>
        <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 15px 0;">
            <?php foreach ($_GET as $k => $v) { if (strpos($k, 'sec3_') !== 0) echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">'; } ?>
            <label style="font-size: 13px; font-weight: bold; color: #555;">من:</label>
            <input type="date" name="sec3_start" value="<?php echo htmlspecialchars($sec3_start); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <label style="font-size: 13px; font-weight: bold; color: #555;">إلى:</label>
            <input type="date" name="sec3_end" value="<?php echo htmlspecialchars($sec3_end); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <button type="submit" style="background: #8b5cf6; color: white; border: none; padding: 7px 16px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold;">تطبيق</button>
        </form>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">

            <div style="background: #f3eefe; border-right: 4px solid #8b5cf6; padding: 15px; border-radius: 6px;">
                <div style="color: #5b3aa8; font-size: 12px; font-weight: bold;">الرصيد الأساسي التراكمي (لا ينقص أبداً)</div>
                <div style="font-size: 19px; font-weight: bold; color: #8b5cf6; font-family: monospace; margin-top: 5px;">$<?php echo number_format($sec3_lifetime_usd, 2); ?></div>
                <div style="font-size: 10.5px; color: #5b3aa8; margin-top: 3px;">مجموع كل ما دخل الجرد المكتبي تاريخياً — لا يتأثر بالمبيعات</div>
            </div>

            <div style="background: #eaf1fc; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px;">
                <div style="color: #2c4e9c; font-size: 12px; font-weight: bold;">الرصيد الحالي (رصيد لحظي)</div>
                <div style="font-size: 19px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($sec3_current_qty, 2), '0'), '.'); ?> قطعة</div>
                <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;">القيمة: $<?php echo number_format($sec3_current_usd, 2); ?></div>
            </div>

            <div style="background: #e8f8f2; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 6px;">
                <div style="color: #1a7a5e; font-size: 12px; font-weight: bold;">مبيعات الجرد المكتبي (ضمن الفترة)</div>
                <div style="font-size: 19px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($sec3_sold_qty, 2), '0'), '.'); ?> قطعة</div>
                <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;">القيمة: <?php echo number_format($sec3_sold_value_syp, 2); ?> ل.س</div>
                <div style="font-size: 13px; color: #666; font-family: monospace;">≈ $<?php echo number_format($sec3_sold_value_usd, 2); ?></div>
            </div>

            <div style="background: <?php echo $sec3_profit_syp >= 0 ? '#e8f8f2' : '#fdecea'; ?>; border-right: 4px solid <?php echo $sec3_profit_syp >= 0 ? '#1cc88a' : '#e74a3b'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #555; font-size: 12px; font-weight: bold;">مكسب الجرد المكتبي (ضمن الفترة)</div>
                <div style="font-size: 19px; font-weight: bold; color: <?php echo $sec3_profit_syp >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec3_profit_syp, 2); ?> ل.س</div>
            </div>

            <div style="background: #fff8e6; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px;">
                <div style="color: #856404; font-size: 12px; font-weight: bold;">قطع مُسلَّمة مقابل قيد الانتظار (ضمن الفترة)</div>
                <div style="font-size: 16px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;">مُسلَّمة: <?php echo rtrim(rtrim(number_format($sec3_delivered_qty, 2), '0'), '.'); ?></div>
                <div style="font-size: 12px; color: #666; font-family: monospace; margin-right: 10px;">القيمة: <?php echo number_format($sec3_sold_value_syp, 2); ?> ل.س (≈ $<?php echo number_format($sec3_sold_value_usd, 2); ?>)</div>
                <div style="font-size: 16px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 8px;">قيد الانتظار: <?php echo rtrim(rtrim(number_format($sec3_pending_qty, 2), '0'), '.'); ?></div>
                <div style="font-size: 12px; color: #666; font-family: monospace; margin-right: 10px;">القيمة: <?php echo number_format($sec3_pending_value_syp, 2); ?> ل.س (≈ $<?php echo number_format($sec3_pending_value_usd, 2); ?>)</div>
            </div>

        </div>
    </div>

    <?php if (count($supplier_priority_list) > 0): ?>
    <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <i class="fas fa-hand-holding-usd"></i> أولوية سداد الموردين (الأعلى استحقاقاً أولاً — رصيد حالي)
        </h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right; margin-top: 10px;">
            <thead>
                <tr style="background: #f8f9fc; border-bottom: 2px solid #e3e6f0; color: #555;">
                    <th style="padding: 8px 15px; width: 40px;">#</th>
                    <th style="padding: 8px 15px;">المورد</th>
                    <th style="padding: 8px 15px;">المبلغ المستحق (USD)</th>
                    <th style="padding: 8px 15px; text-align: center;">إجراء</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($supplier_priority_list as $idx => $sp): ?>
                    <tr style="border-bottom: 1px solid #f1f1f1; <?php echo $idx === 0 ? 'background:#fdecea;' : ''; ?>">
                        <td style="padding: 8px 15px; font-weight: bold; color: <?php echo $idx === 0 ? '#e74a3b' : '#888'; ?>;"><?php echo $idx + 1; ?></td>
                        <td style="padding: 8px 15px; font-weight: 600;"><?php echo htmlspecialchars($sp['supplier_name']); ?><?php if ($idx === 0): ?> <span style="background:#e74a3b; color:white; font-size:10.5px; padding:2px 8px; border-radius:10px; margin-right:6px;">الأعلى أولوية</span><?php endif; ?></td>
                        <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color: #e74a3b;">$<?php echo number_format($sp['net_balance_usd'], 2); ?></td>
                        <td style="padding: 8px 15px; text-align: center;">
                            <a href="supplier_view.php?id=<?php echo intval($sp['id']); ?>" style="background: #4e73df; color: white; padding: 5px 12px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold;">سداد دفعة</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size: 11px; color: #999; margin: 10px 0 0;"><i class="fas fa-info-circle"></i> مرتَّبة حسب صافي المبلغ المستحق فعلياً لكل مورد (مشتريات آجلة - مدفوعات - خصومات/مرتجعات + رصيد افتتاحي) — رصيد لحظي حالي دائماً.</p>
    </div>
    <?php endif; ?>

    <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">إجراءات سريعة</h3>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
            <a href="currencies.php" style="background: #4e73df; color: white; padding: 10px 15px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;">إدارة العملات وأسعار الصرف</a>
            <a href="journal.php" style="background: #1cc88a; color: white; padding: 10px 15px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;">دفتر اليومية الشامل</a>
            <a href="financial_statements.php" style="background: #6f42c1; color: white; padding: 10px 15px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;">القوائم المالية الرسمية</a>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>