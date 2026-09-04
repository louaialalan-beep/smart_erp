<?php
include 'header.php';

// ============================================================
// فلتر الفترة (يومي / أسبوعي / شهري / سنوي / مخصص) — نفس نمط الفلاتر المعتمد في باقي صفحات
// النظام (financial_reports.php وغيرها)، لضمان اتساق سلوك الفلترة عبر كل الصفحات.
// ============================================================
$filter_type = $_GET['filter_type'] ?? 'daily';
$today_str = date('Y-m-d');

if ($filter_type === 'daily') {
    $start_date = $today_str;
    $end_date = $today_str;
} elseif ($filter_type === 'weekly') {
    $start_date = date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime('sunday this week'));
} elseif ($filter_type === 'monthly') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
} elseif ($filter_type === 'yearly') {
    $start_date = date('Y-01-01');
    $end_date = date('Y-12-31');
} elseif ($filter_type === 'custom' && !empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $start_date = $_GET['start_date'];
    $end_date = $_GET['end_date'];
} else {
    $filter_type = 'daily';
    $start_date = $today_str;
    $end_date = $today_str;
}

$dash_error = '';
$dash_revenue = 0; $dash_cogs = 0; $dash_cogs_usd = 0; $dash_commissions = 0; $dash_expenses = 0; $dash_shipping = 0;
$dash_payroll = 0; $dash_supplier_discounts = 0; $net_profit_dash = 0;
$office_inv_count = 0; $office_inv_value_usd = 0;
$pending = ['distinct_products' => 0, 'total_qty' => 0, 'total_value_syp' => 0];
$delivered_supplier = ['distinct_products' => 0, 'pieces' => 0, 'value_syp' => 0];
$purchases_dash = ['invoice_count' => 0, 'total_usd' => 0];
$total_supplier_net_balance = 0;
$cash_balance_syp = 0; $office_pieces_sold = 0; $office_profit_syp = 0;

try {
    $cur_count = $conn->query("SELECT COUNT(*) FROM currencies")->fetchColumn();
} catch (Exception $e) {
    $cur_count = 0;
}

// ضمان وجود الجداول التي قد لا تكون زُيارت صفحاتها بعد على هذا الخادم (نفس النمط الدفاعي المعتمد
// في كل صفحات النظام الأخرى)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS sale_item_discounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_item_id INT NOT NULL,
        sale_id INT NOT NULL,
        amount_syp DECIMAL(15,2) NOT NULL,
        discount_date DATE NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) { /* يُتجاهل */ }

try {
    // ============================================================
    // 1) الجرد المكتبي: عدد الأصناف (بلا مورد) + قيمتهم الحالية (رصيد لحظي، لا يعتمد على فترة
    // الفلتر لأنه "رصيد" وليس "حركة" — مطابق تماماً لبطاقة "مخزون المكتب" في القوائم المالية الرسمية)
    // ============================================================
    $stmt_office = $conn->query("
        SELECT COUNT(*) AS cnt, COALESCE(SUM(current_quantity * cost_price_usd), 0) AS value_usd
        FROM products WHERE supplier_id IS NULL
    ");
    $office_inv = $stmt_office->fetch(PDO::FETCH_ASSOC);
    $office_inv_count = intval($office_inv['cnt']);
    $office_inv_value_usd = floatval($office_inv['value_usd']);

    // ============================================================
    // 2) المنتجات "قيد الانتظار": عدد سطور الأصناف (وقيمتها) ضمن فواتير مبيعات لم تُسلَّم بعد،
    // بتاريخ إصدار ضمن الفترة المحددة أعلاه.
    // ============================================================
    $stmt_pending = $conn->prepare("
        SELECT COUNT(DISTINCT si.product_id) AS distinct_products,
               COALESCE(SUM(si.quantity), 0) AS total_qty,
               COALESCE(SUM(si.total_price_syp), 0) AS total_value_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.delivery_status = 'Pending' AND s.invoice_date BETWEEN ? AND ?
    ");
    $stmt_pending->execute([$start_date, $end_date]);
    $pending = $stmt_pending->fetch(PDO::FETCH_ASSOC);

    // ============================================================
    // 3) رصيد الصندوق الرئيسي — كما هو حتى نهاية تاريخ الفترة المحددة (رصيد تراكمي، بنفس منطق
    // daily_closing.php وfinancial_statements.php تماماً)
    // ============================================================
    $cash_balance_syp = 0;
    $stmt_cash_acc = $conn->query("SELECT id FROM accounts WHERE account_name LIKE '%صندوق الرئيسي%' OR account_name LIKE '%صندوق%' OR account_name LIKE '%نقد%' ORDER BY id ASC LIMIT 1");
    $cash_account_id = $stmt_cash_acc->fetchColumn();
    if ($cash_account_id) {
        $stmt_cash_bal = $conn->prepare("SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) FROM journal_entries WHERE account_id = ? AND entry_date <= ?");
        $stmt_cash_bal->execute([$cash_account_id, $end_date]);
        $cash_balance_syp = floatval($stmt_cash_bal->fetchColumn());
    }

    // ============================================================
    // 4) المكسب من منتجات الجرد المكتبي تحديداً (بلا مورد) — إيراد ناقص COGS، لفواتير مُسلَّمة فعلياً
    // ضمن الفترة (بتاريخ delivered_at)، مع عدد القطع المباعة من هذه الأصناف تحديداً.
    // ============================================================
    $stmt_office_profit = $conn->prepare("
        SELECT COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS pieces_sold,
               COALESCE(SUM(si.total_price_syp - COALESCE((SELECT SUM(sri.total_price_syp) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS revenue_syp,
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
    $stmt_office_profit->execute([$start_date, $end_date]);
    $office_profit_row = $stmt_office_profit->fetch(PDO::FETCH_ASSOC);
    $office_pieces_sold = floatval($office_profit_row['pieces_sold']);
    $office_profit_syp = floatval($office_profit_row['revenue_syp']) - floatval($office_profit_row['cogs_syp']);

    // ============================================================
    // 5) صافي الربح الإجمالي للفترة (نفس منهجية financial_reports.php بالضبط: إيراد صافٍ بعد
    // المرتجعات والخصومات، ناقص COGS، ناقص العمولات، ناقص المصاريف التشغيلية، ناقص الشحن)
    // ============================================================
    $stmt_rev_dash = $conn->prepare("
        SELECT COALESCE(SUM(je.credit) - SUM(je.debit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'إيرادات المبيعات' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_rev_dash->execute([$start_date, $end_date]);
    $dash_revenue = floatval($stmt_rev_dash->fetchColumn());

    $stmt_cogs_dash = $conn->prepare("
        SELECT COALESCE(SUM(
            (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
            * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate
        ), 0)
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_cogs_dash->execute([$start_date, $end_date]);
    $dash_cogs = floatval($stmt_cogs_dash->fetchColumn());

    // تكلفة البضائع المباعة (COGS) ما يعادلها بالدولار — من نفس الأصناف، بالتكلفة الدولارية الأصلية
    // بلا تحويل لليرة (القيمة الحقيقية الثابتة، لا تتأثر بتقلب سعر الصرف بين تاريخ الشراء والبيع)
    $stmt_cogs_usd_dash = $conn->prepare("
        SELECT COALESCE(SUM(
            (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
            * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)
        ), 0)
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_cogs_usd_dash->execute([$start_date, $end_date]);
    $dash_cogs_usd = floatval($stmt_cogs_usd_dash->fetchColumn());

    // منتجات "تم التسليم" باستثناء الجرد المكتبي تحديداً (منتجات المورّدين فقط) — عدد القطع الصافي
    // (بعد خصم المرتجع) وقيمتها الصافية، ضمن الفترة، لأن "مكسب الجرد المكتبي" يغطي منتجات المكتب
    // بالفعل في بطاقة مستقلة فلا داعي لتكرارها هنا.
    $stmt_delivered_supplier = $conn->prepare("
        SELECT COUNT(DISTINCT si.product_id) AS distinct_products,
               COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS pieces,
               COALESCE(SUM(si.total_price_syp - COALESCE((SELECT SUM(sri.total_price_syp) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS value_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE p.supplier_id IS NOT NULL
          AND s.delivery_status = 'Delivered'
          AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_delivered_supplier->execute([$start_date, $end_date]);
    $delivered_supplier = $stmt_delivered_supplier->fetch(PDO::FETCH_ASSOC);

    // إجمالي المشتريات من الموردين ضمن الفترة (كل الفواتير، نقداً أو آجل — إجمالي حجم التوريد الفعلي،
    // بخلاف "ذمم الموردين" التي تستبعد النقدية عمداً لأنها لا تُنشئ التزاماً)
    $stmt_purchases_dash = $conn->prepare("
        SELECT COUNT(*) AS invoice_count, COALESCE(SUM(total_amount_usd), 0) AS total_usd
        FROM purchase_invoices WHERE invoice_date BETWEEN ? AND ?
    ");
    $stmt_purchases_dash->execute([$start_date, $end_date]);
    $purchases_dash = $stmt_purchases_dash->fetch(PDO::FETCH_ASSOC);

    // إجمالي صافي أرصدة كل الموردين مجتمعين — رصيد لحظي حالي (بلا فلتر فترة)، بنفس معادلة suppliers.php
    // بالضبط، محسوباً كمجموع SQL واحد بدل حلقة PHP لكل مورد.
    $stmt_sup_balance_dash = $conn->query("
        SELECT COALESCE(SUM(
            (
                (SELECT COALESCE(SUM(pii.total_cost_usd), 0) FROM purchase_invoice_items pii
                    INNER JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
                    WHERE pi.supplier_id = s.id AND pi.payment_status != 'Paid')
                + (SELECT COALESCE(SUM(GREATEST(0, p.purchased_quantity - COALESCE((SELECT SUM(pii2.quantity) FROM purchase_invoice_items pii2 WHERE pii2.product_id = p.id), 0)) * p.cost_price_usd), 0)
                    FROM products p WHERE p.supplier_id = s.id)
            )
            - (SELECT COALESCE(SUM(sp.amount_usd), 0) FROM supplier_payments sp WHERE sp.supplier_id = s.id)
            - (COALESCE(s.returns_discounts, 0)
                + (SELECT COALESCE(SUM(pr.total_amount_usd), 0) FROM purchase_returns pr
                    INNER JOIN purchase_invoices pi3 ON pr.purchase_invoice_id = pi3.id
                    WHERE pi3.supplier_id = s.id AND pi3.payment_status != 'Paid')
                + (SELECT COALESCE(SUM(sd.amount_usd), 0) FROM supplier_discounts sd WHERE sd.supplier_id = s.id))
            + COALESCE(s.opening_balance_usd, 0)
        ), 0) AS grand_net_balance
        FROM suppliers s
    ");
    $total_supplier_net_balance = floatval($stmt_sup_balance_dash->fetchColumn());

    $stmt_comm_dash = $conn->prepare("
        SELECT COALESCE(SUM(s.total_commissions), 0)
            - COALESCE((SELECT SUM(sr.total_commission_reversed) FROM sales_returns sr JOIN sales s2 ON sr.sale_id = s2.id
                        WHERE s2.delivery_status = 'Delivered' AND COALESCE(s2.delivered_at, s2.invoice_date) BETWEEN ? AND ?), 0)
        FROM sales s
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_comm_dash->execute([$start_date, $end_date, $start_date, $end_date]);
    $dash_commissions = floatval($stmt_comm_dash->fetchColumn());

    $dash_expenses = 0;
    try {
        $stmt_exp_dash = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM operational_expenses WHERE expense_date BETWEEN ? AND ?");
        $stmt_exp_dash->execute([$start_date, $end_date]);
        $dash_expenses = floatval($stmt_exp_dash->fetchColumn());
    } catch (Exception $e) { /* الجدول قد لا يكون موجوداً بعد */ }

    $dash_shipping = 0;
    try {
        $stmt_ship_dash = $conn->prepare("SELECT COALESCE(SUM(shipping_cost_syp), 0) FROM sales WHERE invoice_date BETWEEN ? AND ?");
        $stmt_ship_dash->execute([$start_date, $end_date]);
        $dash_shipping = floatval($stmt_ship_dash->fetchColumn());
    } catch (Exception $e) { /* العمود قد لا يكون موجوداً بعد */ }

    // رواتب وحوافز + خصومات مكتسبة من الموردين — من دفتر اليومية مباشرة، نفس مصدر القوائم الرسمية
    // ونفس منهجية financial_reports.php المُحدَّثة، لضمان تطابق صافي الربح حرفياً عبر كل الصفحات.
    $dash_payroll = 0;
    $dash_supplier_discounts = 0;
    try {
        $stmt_payroll_dash = $conn->prepare("
            SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
            FROM journal_entries je JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name IN ('الرواتب والأجور', 'مصروف حوافز ومكافآت الموظفين')
              AND je.entry_date BETWEEN ? AND ?
        ");
        $stmt_payroll_dash->execute([$start_date, $end_date]);
        $dash_payroll = floatval($stmt_payroll_dash->fetchColumn());

        $stmt_sd_dash = $conn->prepare("
            SELECT COALESCE(SUM(je.credit) - SUM(je.debit), 0)
            FROM journal_entries je JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'خصومات مكتسبة من الموردين' AND je.entry_date BETWEEN ? AND ?
        ");
        $stmt_sd_dash->execute([$start_date, $end_date]);
        $dash_supplier_discounts = floatval($stmt_sd_dash->fetchColumn());
    } catch (Exception $e) { /* يُتجاهل إن تعذّر */ }

    $net_profit_dash = $dash_revenue - $dash_cogs - $dash_commissions - $dash_expenses - $dash_shipping - $dash_payroll + $dash_supplier_discounts;

} catch (Exception $e) {
    $dash_error = "تنبيه: تعذّر حساب بعض المؤشرات — " . $e->getMessage();
}

$filter_labels = ['daily' => 'اليوم', 'weekly' => 'هذا الأسبوع', 'monthly' => 'هذا الشهر', 'yearly' => 'هذه السنة', 'custom' => 'فترة مخصصة'];
?>

<div style="padding: 20px;">
    <!-- ترحيب بمدير النظام -->
    <div style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); color: white; padding: 25px; border-radius: 10px; margin-bottom: 25px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,0.15);">
        <h1 style="margin: 0 0 10px 0; font-size: 24px;">مرحباً بك، لؤي القبالان</h1>
        <p style="margin: 0; opacity: 0.9; font-size: 14px;">نظام Smart ERP المطور يعمل بكفاءة عالية. يمكنك إدارة الحسابات، العملات، والقيود من القائمة الجانبية.</p>
    </div>

    <?php if ($dash_error): ?>
        <div style="background: #fff3cd; color: #856404; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($dash_error); ?></div>
    <?php endif; ?>

    <!-- شريط فلتر الفترة -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <span style="font-size: 13px; font-weight: bold; color: #555;"><i class="fas fa-filter"></i> الفترة المعروضة:</span>
        <?php foreach (['daily' => 'يومي', 'weekly' => 'أسبوعي', 'monthly' => 'شهري', 'yearly' => 'سنوي'] as $ft => $label): ?>
            <a href="?filter_type=<?php echo $ft; ?>" style="text-decoration: none;">
                <span style="padding: 7px 16px; border-radius: 5px; font-size: 13px; font-weight: bold; background: <?php echo $filter_type === $ft ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $filter_type === $ft ? '#fff' : '#4e73df'; ?>;"><?php echo $label; ?></span>
            </a>
        <?php endforeach; ?>
        <form method="GET" style="display: flex; gap: 8px; align-items: center;">
            <input type="hidden" name="filter_type" value="custom">
            <input type="date" name="start_date" value="<?php echo $filter_type === 'custom' ? htmlspecialchars($start_date) : ''; ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <span style="color: #888;">إلى</span>
            <input type="date" name="end_date" value="<?php echo $filter_type === 'custom' ? htmlspecialchars($end_date) : ''; ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <button type="submit" style="background: #6f42c1; color: white; border: none; padding: 7px 14px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold;">تطبيق</button>
        </form>
        <span style="font-size: 12.5px; color: #888; margin-right: auto;">(<?php echo htmlspecialchars($start_date); ?> إلى <?php echo htmlspecialchars($end_date); ?>)</span>
    </div>

    <!-- بطاقات المؤشرات المطلوبة -->
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

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #20c997; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-truck"></i> منتجات تم التسليم — من الموردين (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #20c997; font-family: monospace;"><?php echo intval($delivered_supplier['distinct_products']); ?> صنف / <?php echo rtrim(rtrim(number_format($delivered_supplier['pieces'], 2), '0'), '.'); ?> قطعة</div>
            <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;">القيمة: <?php echo number_format($delivered_supplier['value_syp'], 2); ?> ل.س</div>
            <div style="font-size: 11px; color: #999; margin-top: 2px;">باستثناء الجرد المكتبي (مُحتسَب في بطاقة منفصلة)</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #fd7e14; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-truck-loading"></i> إجمالي المشتريات من الموردين (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #fd7e14; font-family: monospace;">$<?php echo number_format($purchases_dash['total_usd'], 2); ?></div>
            <div style="font-size: 12px; color: #999; margin-top: 3px;"><?php echo intval($purchases_dash['invoice_count']); ?> فاتورة شراء</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #dc3545; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-file-invoice-dollar"></i> إجمالي صافي أرصدة كل الموردين (رصيد حالي)</div>
            <div style="font-size: 20px; font-weight: bold; color: #dc3545; font-family: monospace;">$<?php echo number_format($total_supplier_net_balance, 2); ?></div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #f6c23e; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-handshake"></i> العمولات والمصاريف والرواتب (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace;"><?php echo number_format($dash_commissions + $dash_expenses + $dash_shipping + $dash_payroll, 2); ?> ل.س</div>
            <div style="font-size: 11px; color: #888; margin-top: 3px;">(عمولات: <?php echo number_format($dash_commissions, 0); ?> | مصاريف: <?php echo number_format($dash_expenses, 0); ?> | شحن: <?php echo number_format($dash_shipping, 0); ?> | رواتب: <?php echo number_format($dash_payroll, 0); ?>)</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #8b5cf6; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-warehouse"></i> منتجات الجرد المكتبي (رصيد حالي)</div>
            <div style="font-size: 20px; font-weight: bold; color: #8b5cf6; font-family: monospace;"><?php echo $office_inv_count; ?> صنف</div>
            <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;">القيمة: $<?php echo number_format($office_inv_value_usd, 2); ?></div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #f6c23e; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-clock"></i> منتجات قيد الانتظار (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace;"><?php echo intval($pending['distinct_products']); ?> صنف / <?php echo rtrim(rtrim(number_format($pending['total_qty'], 2), '0'), '.'); ?> قطعة</div>
            <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;">القيمة: <?php echo number_format($pending['total_value_syp'], 2); ?> ل.س</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #1cc88a; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-cash-register"></i> رصيد الصندوق الرئيسي</div>
            <div style="font-size: 20px; font-weight: bold; color: #1cc88a; font-family: monospace;"><?php echo number_format($cash_balance_syp, 2); ?> ل.س</div>
            <div style="font-size: 12px; color: #999; margin-top: 3px;">كما في <?php echo htmlspecialchars($end_date); ?></div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #4e73df; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-box-open"></i> مكسب الجرد المكتبي (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: <?php echo $office_profit_syp >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace;"><?php echo number_format($office_profit_syp, 2); ?> ل.س</div>
            <div style="font-size: 13px; color: #666; font-family: monospace; margin-top: 3px;"><?php echo rtrim(rtrim(number_format($office_pieces_sold, 2), '0'), '.'); ?> قطعة مباعة</div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #e74a3b; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-chart-line"></i> صافي الربح (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: <?php echo $net_profit_dash >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace;"><?php echo number_format($net_profit_dash, 2); ?> ل.س</div>
        </div>

    </div>

    <!-- قسم الاختصارات السريعة -->
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