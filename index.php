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
$dash_revenue = 0; $dash_cogs = 0; $dash_cogs_usd = 0; $dash_commissions = 0; $dash_expenses = 0; $dash_payroll = 0;
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

    // تصحيح جذري نهائي (حل مشكلة "عدم تطابق التوقيت" من جذورها): كانت القراءة من دفتر اليومية مباشرة
    // بتاريخ *ترحيل كل قيد* (entry_date) — وهذا يشمل قيود عكس COGS عند أي مرتجع أو "إلغاء تأكيد تسليم"،
    // والتي تُرحَّل بتاريخ حدوث العكس نفسه (اليوم)، لا بتاريخ التسليم الأصلي. فأي مرتجع/إلغاء كبير على
    // فاتورة قديمة يظهر كأثر ضخم على ربح يوم واحد بمعزل، رغم أن تكلفتها الأصلية أُحصِيَت بيوم آخر تماماً.
    // الحل: تُنسَب COGS دائماً لتاريخ *التسليم الأصلي* للفاتورة (delivered_at)، وتُطرَح أي كمية أُرجِعت
    // لاحقاً *بغض النظر عن تاريخ الإرجاع نفسه* — فلا يتأثر رقم أي يوم بما يحدث في يوم آخر لاحقاً إطلاقاً.
    // نفس المنهجية المُثبَتة والمُطبَّقة أصلاً في dash_commissions أدناه وfinancial_reports.php بالضبط.
    $stmt_cogs_dash = $conn->prepare("
        SELECT COALESCE(SUM(
            (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
            * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate
        ), 0) AS cogs_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_cogs_dash->execute([$start_date, $end_date]);
    $dash_cogs = floatval($stmt_cogs_dash->fetchColumn());

    // تصحيح جوهري: كان هذا الاستعلام يقرأ foreign_debit/foreign_credit من قيد COGS في اليومية — لكن
    // قيد COGS يُرحَّل دائماً بدالة postJournalLine التي لا تُسجِّل عمود العملة الأجنبية إطلاقاً (تُدرِج
    // 'SYP' فقط)، فكانت النتيجة "0.00$" ثابتة دوماً بغض النظر عن الفترة أو المبيعات الفعلية — وهو بالضبط
    // ما يفسّر "$0.00 ≈" الظاهر تحت بطاقة COGS. الحل: إعادة حساب القيمة بالدولار مباشرة من sale_items
    // (نفس منهجية financial_reports.php المُثبَتة الصحيحة) بدل الاعتماد على عمود غير مُعبَّأ أصلاً.
    // ملاحظة مهمة للإجابة عن "كيف يُحسب COGS مع اختلاف سعر الصرف يومياً؟": كل فاتورة تُثبِّت تكلفة
    // الوحدة بالدولار وقت البيع (sale_items.cost_price_usd_at_sale) وتحوِّلها للّيرة بسعر صرف تلك
    // الفاتورة نفسها (sales.exchange_rate، المثبَّت وقت إصدارها) — فمجموع COGS بالليرة لأي فترة هو
    // فعلياً مجموع مبالغ حُوِّلت كل منها بسعر يوم إصدارها الخاص، وليس بسعر واحد موحَّد للفترة كلها.
    // أما المجموع بالدولار (هنا) فمُحايد تماماً تجاه سعر الصرف أصلاً — كل ما هو مطلوب هو مجموع
    // (الكمية × التكلفة بالدولار وقت البيع) دون أي تحويل عملة على الإطلاق.
    $stmt_cogs_usd_dash2 = $conn->prepare("
        SELECT COALESCE(SUM(
            (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
            * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)
        ), 0)
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_cogs_usd_dash2->execute([$start_date, $end_date]);
    $dash_cogs_usd = floatval($stmt_cogs_usd_dash2->fetchColumn());

    try {
        $stmt_exp_dash = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM operational_expenses WHERE expense_date BETWEEN ? AND ?");
        $stmt_exp_dash->execute([$start_date, $end_date]);
        $dash_expenses = floatval($stmt_exp_dash->fetchColumn());
    } catch (Exception $e) { }

    // تصحيح جوهري: الرواتب والأجور + الحوافز والمكافآت تُرحَّل محاسبياً على حسابين منفصلين في دفتر
    // اليومية (وليس ضمن جدول operational_expenses)، فكانت غائبة تماماً عن هذه البطاقة تحديداً رغم
    // وجودها في كل من قسم "تحليل الإيرادات والمصاريف الشاملة" (sec2) وفي القوائم المالية الرسمية
    // (financial_reports.php) — ما كان يجعل "صافي الربح" هنا أعلى من الحقيقة بقدر أي رواتب/حوافز
    // رُحِّلت خلال نفس الفترة بالضبط. نفس منهجية القراءة المعتمدة في الصفحتين الأخريين تماماً.
    $dash_payroll = 0;
    try {
        $stmt_payroll_dash = $conn->prepare("
            SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
            FROM journal_entries je JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name IN ('الرواتب والأجور', 'مصروف حوافز ومكافآت الموظفين')
              AND je.entry_date BETWEEN ? AND ?
        ");
        $stmt_payroll_dash->execute([$start_date, $end_date]);
        $dash_payroll = floatval($stmt_payroll_dash->fetchColumn());
    } catch (Exception $e) { }

    $dash_total_expenses_combined = $dash_expenses + $dash_payroll;

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

    // تصحيح نهائي بعد توضيح صريح من المستخدم: أجور الشحن المدفوعة فعلياً لشركة التوصيل مصروف حقيقي
    // يجب خصمه من صافي الربح. تُقرَأ الآن من دفتر اليومية مباشرة (حساب "تكاليف الشحن"، وهو حساب من
    // نوع Expense يُرحَّل تلقائياً عند إدخال شحن لأي فاتورة في sales.php) بدل عمود shipping_cost_syp
    // الخام — لتطابق تماماً منهجية COGS/العمولات/الرواتب أعلاه، ولأنها نفس المنهجية التي تعتمدها
    // financial_statements.php أصلاً (تجمع كل حسابات Expense من اليومية بلا استثناء، وهذا الحساب من
    // ضمنها) — فتتطابق كل الصفحات الآن حرفياً بلا أي استثناء لأي منها.
    $dash_shipping = 0;
    try {
        $stmt_ship_dash = $conn->prepare("
            SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
            FROM journal_entries je JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'تكاليف الشحن' AND je.entry_date BETWEEN ? AND ?
        ");
        $stmt_ship_dash->execute([$start_date, $end_date]);
        $dash_shipping = floatval($stmt_ship_dash->fetchColumn());
    } catch (Exception $e) { }
    $dash_total_expenses_combined += $dash_shipping;
    $net_profit_dash = $dash_revenue - $dash_cogs - $dash_commissions - $dash_total_expenses_combined;

} catch (Exception $e) {
    $dash_error = "تنبيه: تعذّر حساب بعض المؤشرات — " . $e->getMessage();
}

$filter_labels = ['daily' => 'اليوم', 'weekly' => 'هذا الأسبوع (سبت-خميس)', 'specific' => 'التاريخ المحدَّد'];

$sec2_start = $_GET['sec2_start'] ?? date('Y-m-01');
$sec2_end   = $_GET['sec2_end'] ?? date('Y-m-t');
$sec2_revenue = 0; $sec2_expenses = 0; $sec2_payroll = 0; $sec2_commissions = 0;
$sec2_shipping = 0; $sec2_supplier_payments = 0; $sec2_net = 0; $sec2_sp_breakdown = [];
$sec2_cogs_syp = 0; $sec2_cogs_usd = 0; $sec2_cogs_vs_payments_diff = 0;
$sec2_fx_loss = 0; $sec2_fx_gain = 0; $sec2_fx_unrealized = 0;
$sec2_owner_withdrawals = 0; $sec2_net_after_withdrawals = 0; $sec2_owner_loan_outstanding = 0; $sec2_cash_available = 0;
$sec2_cash_actual = 0;
$sec2_cash_actual_breakdown = [];
$sec2_absolute_net_profit = 0;
$sec2_cash_out_operational = 0; $sec2_remaining_after_cashout = 0; $sec2_distributable_profit = 0;
$sec2_cash_revenue_pure = 0; $sec2_cash_spent_total = 0; $sec2_pure_cash_profit = 0;
$sec2_cashout_breakdown = [];
$recon_owner_withdrawals_alltime = 0; $recon_inventory_usd = 0; $recon_inventory_syp = 0;
$recon_pending_capital_usd = 0; $recon_pending_capital_syp = 0; $recon_expected_cash = 0; $recon_gap_vs_ledger = 0;
$sec2_pending_capital_usd = 0; $sec2_pending_capital_syp = 0;

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

    // تصحيح نهائي: تُقرَأ الآن من دفتر اليومية (حساب "تكاليف الشحن") بدل عمود shipping_cost_syp الخام،
    // لتطابق منهجية باقي البنود هنا (عمولات/رواتب) ومنهجية financial_statements.php تماماً.
    $stmt_s2_ship = $conn->prepare("
        SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'تكاليف الشحن' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_s2_ship->execute([$sec2_start, $sec2_end]);
    $sec2_shipping = floatval($stmt_s2_ship->fetchColumn());

    // تصحيح جوهري (بناءً على طلب صريح من المستخدم): "صافي الربح" هنا كان يُحسَب بطرح "دفعات الموردين"
    // (حركة نقدية فعلية لسداد التزامات سابقة) بدل "تكلفة البضائع المباعة" الفعلية (COGS) — وهذا خطأ
    // محاسبي جوهري، لأن دفعة مورد قد تخص بضاعة بيعت في فترة سابقة تماماً (أو لم تُبَع بعد إطلاقاً)، بينما
    // COGS هو المقياس الصحيح الوحيد لتكلفة ما بيع فعلاً ضمن هذه الفترة بالذات. نفس المنهجية المُثبَتة
    // المُطبَّقة أصلاً في dash_cogs (البطاقة الأولى) بالضبط: تُنسَب دائماً لتاريخ *التسليم الأصلي*
    // (delivered_at)، وتُطرَح أي كمية أُرجِعت لاحقاً بغض النظر عن تاريخ الإرجاع نفسه.
    $stmt_s2_cogs = $conn->prepare("
        SELECT COALESCE(SUM(
            (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
            * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate
        ), 0) AS cogs_syp,
        COALESCE(SUM(
            (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
            * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)
        ), 0) AS cogs_usd
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_s2_cogs->execute([$sec2_start, $sec2_end]);
    $s2cogs = $stmt_s2_cogs->fetch(PDO::FETCH_ASSOC);
    $sec2_cogs_syp = floatval($s2cogs['cogs_syp']);
    $sec2_cogs_usd = floatval($s2cogs['cogs_usd']);

    // إضافة جوهرية بناءً على طلب صريح من المستخدم ("راجع كل المصاريف وسجّلها"): "صافي الربح" هنا كان
    // يتجاهل فروقات صرف العملة تماماً، رغم أنها بند حقيقي مُرحَّل فعلياً في اليومية — وكانت موجودة أصلاً
    // في financial_reports.php ("صافي الربح الحقيقي") لكن غائبة هنا فقط، فيختلف رقم لوحة التحكم عن رقم
    // التقرير الرسمي لنفس الفترة بالضبط بمقدار هذه الفروقات. نفس المنهجية والحسابات المُثبَتة في
    // financial_reports.php حرفياً:
    // (أ) فروقات محقَّقة فعلياً — تنشأ فقط لحظة سداد دفعة لمورد (خسارة إن صعد الدولار بين تاريخ نشوء
    //     الدَين وتاريخ سداده، ربح إن انخفض) — "فرق المدفوعات للموردين" الذي طلب المستخدم احتسابه بالضبط.
    $stmt_s2_fx_loss = $conn->prepare("
        SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'خسارة فروقات العملة' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_s2_fx_loss->execute([$sec2_start, $sec2_end]);
    $sec2_fx_loss = floatval($stmt_s2_fx_loss->fetchColumn());

    $stmt_s2_fx_gain = $conn->prepare("
        SELECT COALESCE(SUM(je.credit) - SUM(je.debit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'أرباح فروقات العملة' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_s2_fx_gain->execute([$sec2_start, $sec2_end]);
    $sec2_fx_gain = floatval($stmt_s2_fx_gain->fetchColumn());

    // (ب) فروقات غير محقَّقة (IAS 21) — إعادة تقييم كل الذمم المفتوحة للموردين (غير المسدَّدة بعد) بسعر
    //     صرف نهاية هذه الفترة، مقارنةً بمتوسط السعر المرجَّح الذي نشأ عنده كل دَين. نفس استعلام
    //     financial_reports.php بالضبط.
    $sec2_fx_unrealized = 0;
    try {
        $s2_period_end_rate = getExchangeRateForDate($conn, 'USD', $sec2_end);
        $stmt_s2_fx_open = $conn->prepare("
            SELECT s.id, COALESCE(SUM(pi.total_amount_usd), 0) AS outstanding_usd,
                COALESCE(SUM(pi.total_amount_usd * pi.exchange_rate), 0) AS weighted_syp
            FROM suppliers s
            INNER JOIN purchase_invoices pi ON pi.supplier_id = s.id AND pi.payment_status != 'Paid' AND pi.invoice_date <= ?
            GROUP BY s.id
            HAVING outstanding_usd > 0.009
        ");
        $stmt_s2_fx_open->execute([$sec2_end]);
        foreach ($stmt_s2_fx_open->fetchAll(PDO::FETCH_ASSOC) as $fx_row) {
            $out_usd = floatval($fx_row['outstanding_usd']);
            $hist_rate = $out_usd > 0.009 ? (floatval($fx_row['weighted_syp']) / $out_usd) : $s2_period_end_rate;
            $sec2_fx_unrealized += $out_usd * ($s2_period_end_rate - $hist_rate);
        }
    } catch (Exception $e) { }

    // تصحيح جوهري: كان هذا يُعيد حساب المبلغ بالليرة ديناميكياً (المبلغ بالدولار × سعر الصرف "الحالي"
    // المسجَّل الآن لهذا التاريخ في أرشيف العملات) — وهذا مُعرَّض للانحراف عن الحقيقة كلما عُدِّل سعر
    // تاريخي لاحقاً (كما حدث فعلاً)، حتى لو كان القيد المحاسبي الأصلي المُرحَّل صحيحاً ولم يتغيّر إطلاقاً.
    // الآن يُقرَأ المبلغ الفعلي **كما رُحِّل بالضبط لحظة تسجيل الدفعة** من دفتر اليومية نفسه (نفس مصدر
    // "الإقفال اليومي" تماماً) — رقم ثابت لا يتأثر بأي تعديل لاحق على أرشيف أسعار الصرف. مع رجوع احتياطي
    // للحساب الديناميكي القديم فقط إن تعذّر إيجاد القيد الفعلي (بيانات قديمة تالفة، حالة نادرة).
    $stmt_s2_sp = $conn->prepare("
        SELECT sp.id, sp.amount_usd, sp.payment_date, sp.notes, s.supplier_name,
            (SELECT je.credit FROM journal_entries je INNER JOIN accounts a ON je.account_id = a.id
             WHERE je.entry_number = CONCAT('JE-SPAY-', sp.id) AND a.account_name LIKE '%صندوق الرئيسي%' AND je.credit > 0
             LIMIT 1) AS actual_syp_posted
        FROM supplier_payments sp
        INNER JOIN suppliers s ON sp.supplier_id = s.id
        WHERE sp.payment_date BETWEEN ? AND ?
        ORDER BY sp.payment_date ASC, sp.id ASC
    ");
    $stmt_s2_sp->execute([$sec2_start, $sec2_end]);
    foreach ($stmt_s2_sp->fetchAll(PDO::FETCH_ASSOC) as $sp_row) {
        $usd_amt = floatval($sp_row['amount_usd']);
        if ($sp_row['actual_syp_posted'] !== null) {
            $sp_syp = floatval($sp_row['actual_syp_posted']);
            $sp_rate = $usd_amt > 0.009 ? ($sp_syp / $usd_amt) : 0; // السعر الفعلي المُستنتَج من القيد الحقيقي
            $sp_source = 'posted';
        } else {
            // رجوع احتياطي نادر: لا يوجد قيد مطابق (بيانات قديمة جداً سابقة لهذا النظام) — يُعاد الحساب ديناميكياً كما كان سابقاً
            $sp_rate = getExchangeRateForDate($conn, 'USD', $sp_row['payment_date']);
            $sp_syp = $usd_amt * $sp_rate;
            $sp_source = 'estimated';
        }
        $sec2_sp_breakdown[] = [
            'date' => $sp_row['payment_date'], 'supplier' => $sp_row['supplier_name'],
            'usd' => $usd_amt, 'rate' => $sp_rate, 'syp' => $sp_syp, 'notes' => $sp_row['notes'], 'source' => $sp_source,
        ];
        $sec2_supplier_payments += $sp_syp;
    }

    // تصحيح نهائي بعد توضيح صريح: أجور الشحن مصروف حقيقي، تُخصَم الآن من صافي هذا القسم كأي بند آخر.
    // تصحيح جوهري: "دفعات الموردين" أُزيلت من معادلة صافي الربح (ليست مصروفاً محاسبياً، بل حركة نقدية
    // سداد التزام) واستُبدِلت بـ COGS الفعلية — وهي البند الصحيح محاسبياً. "دفعات الموردين" ما زالت
    // تُعرَض بجانب صافي الربح في بطاقة مستقلة (للتدفق النقدي)، وأيضاً في بطاقة "الفرق بين COGS ودفعات
    // الموردين" أدناه لمقارنة الاثنين ببعضهما مباشرة.
    // إضافة جوهرية: فروقات صرف العملة (محقَّقة من سداد دفعات الموردين + غير محقَّقة على الذمم المفتوحة)
    // أصبحت تُحتسَب الآن ضمن صافي الربح — نفس معادلة financial_reports.php ("صافي الربح الحقيقي") حرفياً،
    // فيتطابق رقم لوحة التحكم مع رقم التقرير الرسمي لنفس الفترة بالضبط.
    $sec2_net = $sec2_revenue - ($sec2_cogs_syp + $sec2_expenses + $sec2_payroll + $sec2_commissions + $sec2_shipping
        + $sec2_fx_loss + max(0, $sec2_fx_unrealized)) + $sec2_fx_gain + max(0, -$sec2_fx_unrealized);

    // بطاقة جديدة بناءً على طلب صريح من المستخدم: الفرق بين تكلفة البضائع المباعة فعلياً (COGS) ودفعات
    // الموردين النقدية الفعلية ضمن نفس الفترة. قيمة موجبة = بِعنا بضاعة (COGS) أكبر مما دفعنا فعلياً
    // للموردين ضمن الفترة (الدَّين المتراكم على الموردين يتجه للارتفاع). قيمة سالبة = دفعنا للموردين أكثر
    // من تكلفة ما بيع فعلاً ضمن الفترة (سداد ديون سابقة و/أو شراء مخزون لم يُبَع بعد).
    $sec2_cogs_vs_payments_diff = $sec2_cogs_syp - $sec2_supplier_payments;

    // سحوبات المالك ضمن نفس الفترة — بند توزيع أرباح (Equity)، وليس مصروف تشغيل، فلا يُخصَم من
    // "صافي الأرباح" نفسه؛ يُعرض بجانبه في بطاقة مستقلة "صافي الربح بعد سحوبات الملّاك" فقط لمن يريد
    // معرفة "كم تبقّى فعلياً بعد سحب المالك؟" دون خلط هذا السؤال بسؤال "كم ربح العمل؟".
    // تصحيح جوهري: "قرض للمالك" (ذمة عليه يُعيد سدادها) يُستثنى هنا تماماً — فهو ليس توزيع أرباح نهائياً
    // يُخفِّض حقوق الملكية، بل مجرد أصل (ذمة مدينة) يبقى ضمن ميزانية الشركة. فقط "سحب نهائي" يُحتسَب هنا.
    $sec2_owner_withdrawals = 0;
    try {
        $ow_cols_chk = $conn->query("SHOW COLUMNS FROM owner_withdrawals")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('withdrawal_type', $ow_cols_chk)) {
            $conn->exec("ALTER TABLE owner_withdrawals ADD COLUMN withdrawal_type ENUM('سحب نهائي','قرض للمالك') NOT NULL DEFAULT 'سحب نهائي'");
        }
        $stmt_s2_ow = $conn->prepare("SELECT COALESCE(SUM(amount_syp), 0) FROM owner_withdrawals WHERE withdrawal_date BETWEEN ? AND ? AND withdrawal_type = 'سحب نهائي'");
        $stmt_s2_ow->execute([$sec2_start, $sec2_end]);
        $sec2_owner_withdrawals = floatval($stmt_s2_ow->fetchColumn());
    } catch (Exception $e) { /* الجدول قد لا يكون أُنشئ بعد */ }
    $sec2_net_after_withdrawals = $sec2_net - $sec2_owner_withdrawals;

    // دين المالك المستحق حالياً (رصيد لحظي "الآن"، لا مرتبط بفترة sec2 — لأنه ذمة قائمة وليست حركة
    // فترة) = إجمالي القروض المُسجَّلة (نوع "قرض للمالك") ناقص ما سُدِّد منها فعلياً حتى الآن.
    try {
        $ow_cols_loan_chk = $conn->query("SHOW COLUMNS FROM owner_withdrawals")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('withdrawal_type', $ow_cols_loan_chk)) {
            $conn->exec("ALTER TABLE owner_withdrawals ADD COLUMN withdrawal_type ENUM('سحب نهائي','قرض للمالك') NOT NULL DEFAULT 'سحب نهائي'");
        }
        $stmt_loan_gross = $conn->query("SELECT COALESCE(SUM(amount_syp), 0) FROM owner_withdrawals WHERE withdrawal_type = 'قرض للمالك'");
        $loan_gross = floatval($stmt_loan_gross->fetchColumn());
        $stmt_loan_repaid = $conn->query("
            SELECT COALESCE(SUM(olr.amount_syp), 0) FROM owner_loan_repayments olr
            INNER JOIN owner_withdrawals ow ON olr.withdrawal_id = ow.id
            WHERE ow.withdrawal_type = 'قرض للمالك'
        ");
        $loan_repaid = floatval($stmt_loan_repaid->fetchColumn());
        $sec2_owner_loan_outstanding = $loan_gross - $loan_repaid;
    } catch (Exception $e) { /* الجدول قد لا يكون أُنشئ بعد */ }

    // بطاقة جديدة بناءً على طلب صريح من المستخدم: "كم يجب أن يبقى في الصندوق الآن؟" — الرصيد المتوقَّع
    // فعلياً في الصندوق النقدي (حساب "الصندوق الرئيسي" في شجرة الحسابات)، وهو الحساب الذي تُرحَّل إليه
    // كل حركة نقدية حقيقية في النظام (تحصيل مبيعات، دفع مصاريف، سداد موردين، صرف رواتب، سحوبات المالك،
    // ...إلخ) — فرصيده = صافي كل هذه الحركات منذ بداية النظام حتى الآن. رصيد لحظي "الآن" دائماً (بلا
    // فلتر فترة، تماماً كبطاقة "دين المالك المستحق" أعلاه) لأنه رصيد ميزانية عمومية تراكمي وليس حركة
    // فترة. نفس منهجية daily_closing.php بالضبط (المصدر الرسمي المُثبَت لرصيد الصندوق).
    $sec2_cash_actual = 0;
    try {
        $stmt_s2_cash = $conn->query("
            SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
            FROM journal_entries je JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'الصندوق الرئيسي'
        ");
        $sec2_cash_actual = floatval($stmt_s2_cash->fetchColumn());
    } catch (Exception $e) { }

    // تفصيل حسب المصدر (source_module) لبطاقة "كم يجب أن يبقى في الصندوق الآن" — بناءً على طلب صريح من
    // المستخدم لمراجعة مكوّناتها بنداً بنداً، بدلاً من محاولة اشتقاقها من الإيرادات ناقص بنود محاسبية
    // فترة محدودة (وهو خطأ منهجي: هذا رصيد تراكمي منذ اليوم الأول، لا حركة فترة). صافي (مدين−دائن) لكل
    // فئة، تراكمياً منذ أول قيد وحتى اليوم — موجب = صافي دخول نقد لهذه الفئة، سالب = صافي خروج.
    $sec2_cash_actual_breakdown = [];
    try {
        $stmt_s2_cash_detail = $conn->prepare("
            SELECT COALESCE(je.source_module, 'غير مُصنَّف') AS src,
                   COALESCE(SUM(je.debit) - SUM(je.credit), 0) AS net_amt, COUNT(*) AS cnt
            FROM journal_entries je
            JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'الصندوق الرئيسي'
            GROUP BY COALESCE(je.source_module, 'غير مُصنَّف')
            ORDER BY ABS(net_amt) DESC
        ");
        $stmt_s2_cash_detail->execute();
        $sec2_cash_actual_breakdown = $stmt_s2_cash_detail->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { }

    // بطاقة جديدة بناءً على طلب صريح من المستخدم: "صافي الربح المطلق" — الرقم الوحيد غير القابل للجدل،
    // لأنه لا يعتمد على تعداد يدوي لأسماء حسابات مصاريف محدَّدة (كما تفعل dash_cogs/sec2/financial_reports.php
    // أعلاه، وهي عُرضة لنسيان حساب جديد يُضاف لاحقاً) — بل يجمع مباشرة كل حساب من نوع Revenue وكل حساب من
    // نوع Expense في شجرة الحسابات بأكملها، بلا استثناء واحد، تراكمياً منذ أول قيد في النظام وحتى اليوم.
    // نفس منهجية "الأرباح المحتجزة" (Retained Earnings) في الميزانية العمومية بـfinancial_statements.php
    // بالضبط — وهي المعادلة المحاسبية الأساسية: الأصول = الخصوم + حقوق الملكية (وصافي الربح ضمنها).
    // بند لحظي "الآن" دائماً (بلا فلتر فترة إطلاقاً)، لأن "المطلق" يعني منذ اليوم الأول للنظام، لا فترة محدَّدة.
    $sec2_absolute_net_profit = 0;
    try {
        $stmt_s2_absolute = $conn->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN a.account_type = 'Revenue' THEN je.credit - je.debit ELSE 0 END), 0) AS rev,
                COALESCE(SUM(CASE WHEN a.account_type = 'Expense' THEN je.debit - je.credit ELSE 0 END), 0) AS exp
            FROM journal_entries je
            JOIN accounts a ON je.account_id = a.id
            WHERE a.account_type IN ('Revenue', 'Expense') AND je.entry_date <= ?
        ");
        $stmt_s2_absolute->execute([date('Y-m-d')]);
        $s2abs = $stmt_s2_absolute->fetch(PDO::FETCH_ASSOC);
        $sec2_absolute_net_profit = floatval($s2abs['rev']) - floatval($s2abs['exp']);
    } catch (Exception $e) { }

    // ============================================================
    // قسم جديد بناءً على طلب صريح من المستخدم: "ربح يوزَّع على الشركاء" — بعد توضيحه الصريح على نقطتين:
    // (أ) "الإيرادات" هنا = نفس رقم الإيرادات المحاسبي المعروض أعلاه ($sec2_revenue: فواتير مُسلَّمة
    //     ومُحصَّلة بالكامل)، وليس مجرد النقد الداخل للصندوق.
    // (ب) "كل شيء خرج من الصندوق" = مصاريف التشغيل الفعلية فقط (رواتب، عمولات مدفوعة فعلياً، شحن،
    //     مصاريف تشغيلية) — باستثناء سحوبات المالك وسداد قروضه (نفس التصحيح الأصلي).
    //
    // === تصحيح جوهري لاحق (بعد اكتشاف المستخدم رقماً سالباً ضخماً غير منطقي) ===
    // السبب الحقيقي لم يكن الرصيد الافتتاحي وحده، بل **ازدواج حساب** أعمّ: كانت هذه البطاقة تشمل أيضاً
    // "دفعات الموردين" (source_module = 'Supplier Payment') وأي شراء نقدي مباشر (source_module = 'Purchase')
    // — أي **كل** النقد الخارج لشراء بضاعة، سواء دُفِع فوراً أو سُدِّد لاحقاً كدَين متراكم (رصيد افتتاحي
    // أو فواتير قديمة قبل هذه الفترة). ثم كانت الخطوة التالية تطرح COGS **مرة أخرى** — فتكلفة البضاعة
    // كانت تُخصَم مرتين: مرة ضمن "الخارج من الصندوق" (كامل قيمة الشراء/السداد)، ومرة أخرى كـCOGS (تكلفة
    // الجزء المباع فقط منها). هذا ما ضخَّم الرقم سلباً بشكل غير منطقي، لا علاقة له بتوقيت الرصيد الافتتاحي
    // تحديداً بل بأي دفعة مورد مهما كان مصدرها. الحل: استبعاد كل حركة شراء/سداد موردين من "الخارج من
    // الصندوق" هنا كلياً — فتكلفة البضاعة تُمثَّل بـCOGS فقط (المطابقة الصحيحة محاسبياً لما بِيع فعلياً
    // هذه الفترة)، لا بقيمة الشراء أو السداد النقدي الخام (الذي قد يشمل مخزوناً لم يُبَع بعد، أو ديوناً
    // من فترات سابقة تماماً).
    // ثم: المتبقي = الإيرادات − كل شيء خرج من الصندوق (تشغيلي، بلا شراء/سداد موردين)
    //     ربح يوزَّع على الشركاء = المتبقي − ثمن البضائع المباعة (COGS)
    // ============================================================
    $sec2_cash_out_operational = 0;
    try {
        $stmt_s2_cashout = $conn->prepare("
            SELECT COALESCE(SUM(je.credit), 0)
            FROM journal_entries je
            JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'الصندوق الرئيسي'
              AND je.entry_date BETWEEN ? AND ?
              AND (je.source_module IS NULL OR (
                    je.source_module NOT IN ('Owner Withdrawal', 'Owner Loan Repayment')
                    AND je.source_module NOT LIKE 'Purchase%'
                    AND je.source_module NOT LIKE 'Supplier%'
              ))
        ");
        $stmt_s2_cashout->execute([$sec2_start, $sec2_end]);
        $sec2_cash_out_operational = floatval($stmt_s2_cashout->fetchColumn());
    } catch (Exception $e) { }
    $sec2_remaining_after_cashout = $sec2_revenue - $sec2_cash_out_operational;
    $sec2_distributable_profit = $sec2_remaining_after_cashout - $sec2_cogs_syp;

    // ============================================================
    // بطاقة جديدة بناءً على تصحيح صريح من المستخدم لتعريف "الربح": "الربح = الإيرادات − ما تم صرفه
    // فعلياً"، حيث "الإيرادات" = فقط النقد الذي دخل الصندوق فعلياً من فواتير بِيعت وسُلِّمت (لا الاستحقاق
    // المحاسبي، ولا أي تقييم لمخزون أو مبيعات معلَّقة). هذا أبسط تعريف ممكن للربح — تدفق نقدي صافٍ بحت
    // لهذا الصندوق تحديداً، بلا COGS منفصل وبلا استبعاد شراء/موردين (كل ما صُرِف فعلياً يُطرَح كاملاً،
    // بما فيه شراء البضاعة نفسها) — الاستثناء الوحيد: سحوبات المالك وسداد قروضه (لأنها توزيع/إقراض، لا
    // "صرف" على تشغيل العمل).
    // الإيرادات (نقدي): كل دخول فعلي لحساب "الصندوق الرئيسي" مصدره 'Sales' تحديداً (تحصيل فاتورة بيع،
    // سواء فوراً أو لاحقاً كتحصيل ذمة عميل) — يستثني تلقائياً الجانب الدائن لنفس المصدر (تكلفة الشحن،
    // وهي خروج لا دخول) لأن الفلتر هنا على je.debit > 0 حصراً.
    // ============================================================
    $sec2_cash_revenue_pure = 0;
    try {
        $stmt_s2_cash_rev = $conn->prepare("
            SELECT COALESCE(SUM(je.debit), 0)
            FROM journal_entries je
            JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'الصندوق الرئيسي'
              AND je.entry_date BETWEEN ? AND ?
              AND je.source_module = 'Sales'
              AND je.debit > 0
        ");
        $stmt_s2_cash_rev->execute([$sec2_start, $sec2_end]);
        $sec2_cash_revenue_pure = floatval($stmt_s2_cash_rev->fetchColumn());
    } catch (Exception $e) { }

    $sec2_cash_spent_total = 0;
    try {
        $stmt_s2_cash_spent = $conn->prepare("
            SELECT COALESCE(SUM(je.credit), 0)
            FROM journal_entries je
            JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'الصندوق الرئيسي'
              AND je.entry_date BETWEEN ? AND ?
              AND (je.source_module IS NULL OR je.source_module NOT IN ('Owner Withdrawal', 'Owner Loan Repayment'))
        ");
        $stmt_s2_cash_spent->execute([$sec2_start, $sec2_end]);
        $sec2_cash_spent_total = floatval($stmt_s2_cash_spent->fetchColumn());
    } catch (Exception $e) { }
    $sec2_pure_cash_profit = $sec2_cash_revenue_pure - $sec2_cash_spent_total;

    // تفصيل حسب المصدر (source_module) لبطاقة "كل شيء خرج من الصندوق" — بناءً على طلب صريح من المستخدم
    // ("فصل لي ماهي")، لعرض مكوّنات الرقم شفافياً بدل رقم مجمَّع لا يمكن التحقق منه، ولاكتشاف أي مصدر غير
    // متوقَّع (كإرجاع نقدي لعميل، أو حساب لم يُستبعَد بعد) يُفسِّر أي فرق يراه المستخدم غير منطقي.
    $sec2_cashout_breakdown = [];
    try {
        $stmt_s2_cashout_detail = $conn->prepare("
            SELECT COALESCE(je.source_module, 'غير مُصنَّف') AS src, COALESCE(SUM(je.credit), 0) AS amt, COUNT(*) AS cnt
            FROM journal_entries je
            JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'الصندوق الرئيسي'
              AND je.entry_date BETWEEN ? AND ?
              AND (je.source_module IS NULL OR (
                    je.source_module NOT IN ('Owner Withdrawal', 'Owner Loan Repayment')
                    AND je.source_module NOT LIKE 'Purchase%'
                    AND je.source_module NOT LIKE 'Supplier%'
              ))
              AND je.credit > 0
            GROUP BY COALESCE(je.source_module, 'غير مُصنَّف')
            ORDER BY amt DESC
        ");
        $stmt_s2_cashout_detail->execute([$sec2_start, $sec2_end]);
        $sec2_cashout_breakdown = $stmt_s2_cashout_detail->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { }

    // ============================================================
    // قسم جديد بناءً على طلب صريح من المستخدم: "أين ذهبت هذه الأرباح؟" — تسوية كاملة بين "صافي الربح
    // المطلق" (منذ بداية النظام) ورصيد "الصندوق الآن"، توضح إلى أين تحوَّل كل جزء من الربح: هل بقي
    // نقداً، أم تحوَّل لمخزون (بضاعة لم تُبَع بعد)، أم لرأس مال منتجات مباعة لم تُسلَّم بعد، أم سُحِب من
    // المالك، أم أُقرِض له. كل بند هنا "الآن" دائماً (بلا فلتر فترة، منذ اليوم الأول) لأنه تسوية ميزانية
    // عمومية شاملة، لا حركة فترة محدَّدة.
    // المعادلة: الصندوق المتوقَّع = صافي الربح المطلق − سحوبات المالك (كل التاريخ) − دين المالك المستحق
    //           − قيمة المخزون الحالي (بالتكلفة) − رأس مال المنتجات المباعة قيد التسليم (كل التاريخ)
    // إن تساوى هذا مع "كم يجب أن يبقى في الصندوق الآن" (رصيد اليومية الفعلي)، فالنظام المحاسبي متّسق
    // داخلياً بالكامل. أي فرق متبقٍّ بعدها بينه وبين **النقد الفعلي المعدود يدوياً** هو عجز/زيادة حقيقية
    // خارج نطاق أي حساب برمجي — يتطلب تتبعاً يدوياً يوماً بيوم عبر الإقفال اليومي.
    // ============================================================
    $recon_owner_withdrawals_alltime = 0;
    try {
        $stmt_recon_ow = $conn->query("SELECT COALESCE(SUM(amount_syp), 0) FROM owner_withdrawals WHERE withdrawal_type = 'سحب نهائي'");
        $recon_owner_withdrawals_alltime = floatval($stmt_recon_ow->fetchColumn());
    } catch (Exception $e) { }

    $recon_inventory_usd = 0; $recon_inventory_syp = 0;
    try {
        $stmt_recon_inv = $conn->query("SELECT COALESCE(SUM(current_quantity * cost_price_usd), 0) FROM products");
        $recon_inventory_usd = floatval($stmt_recon_inv->fetchColumn());
        $recon_rate_today = getExchangeRateForDate($conn, 'USD', date('Y-m-d'));
        $recon_inventory_syp = $recon_inventory_usd * $recon_rate_today;
    } catch (Exception $e) { }

    // رأس مال المنتجات المباعة قيد التسليم — رصيد لحظي "الآن" بلا فلتر فترة (كل فاتورة Pending حالياً،
    // بغض النظر متى صدرت)، بخلاف $sec2_pending_capital_* أعلاه المحصورة بفترة sec2 فقط.
    $recon_pending_capital_usd = 0; $recon_pending_capital_syp = 0;
    try {
        $stmt_recon_pending = $conn->query("
            SELECT
                COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)), 0) AS cap_usd,
                COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate), 0) AS cap_syp
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.id
            INNER JOIN products p ON si.product_id = p.id
            WHERE s.delivery_status = 'Pending'
        ");
        $rp = $stmt_recon_pending->fetch(PDO::FETCH_ASSOC);
        $recon_pending_capital_usd = floatval($rp['cap_usd']);
        $recon_pending_capital_syp = floatval($rp['cap_syp']);
    } catch (Exception $e) { }

    $recon_expected_cash = $sec2_absolute_net_profit - $recon_owner_withdrawals_alltime - $sec2_owner_loan_outstanding
        - $recon_inventory_syp - $recon_pending_capital_syp;
    $recon_gap_vs_ledger = $sec2_cash_actual - $recon_expected_cash;

    // بطاقة عملية إضافية: "كم تبقّى فعلياً متاحاً" — تطرح السحوبات النهائية (تخفض الربح فعلاً) وأيضاً
    // القرض المستحق حالياً (لا يخفض الربح محاسبياً، لكنه نقد خرج فعلياً من الصندوق ولم يُسترَد بعد).
    // ملاحظة: القرض هنا "رصيد الآن" لا "قرض هذه الفترة" (نفس رقم بطاقة "دين المالك" أعلاه)، بينما باقي
    // الأرقام مرتبطة بفترة sec2 المحدَّدة — خليط متعمَّد لأن هذا مؤشر عملي وليس بنداً محاسبياً رسمياً.
    $sec2_cash_available = $sec2_net_after_withdrawals - $sec2_owner_loan_outstanding;

    // رأس مال المنتجات قيد الانتظار ضمن نفس الفترة المختارة هنا (من/إلى قابلة للتخصيص بالكامل) —
    // نفس منهجية حساب $ov2['pending_capital_*'] في قسم "مؤشرات مالية إضافية"، لكن بفلتر تاريخ مستقل.
    $sec2_pending_capital_usd = 0;
    $sec2_pending_capital_syp = 0;
    try {
        $stmt_s2_pending = $conn->prepare("
            SELECT
                COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)), 0) AS cap_usd,
                COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate), 0) AS cap_syp
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.id
            INNER JOIN products p ON si.product_id = p.id
            WHERE s.delivery_status = 'Pending' AND s.invoice_date BETWEEN ? AND ?
        ");
        $stmt_s2_pending->execute([$sec2_start, $sec2_end]);
        $s2p = $stmt_s2_pending->fetch(PDO::FETCH_ASSOC);
        $sec2_pending_capital_usd = floatval($s2p['cap_usd']);
        $sec2_pending_capital_syp = floatval($s2p['cap_syp']);
    } catch (Exception $e) { }
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
            ), 0) AS cogs_syp,
            COALESCE(SUM(
                (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
                * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)
            ), 0) AS cogs_usd
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
    // تصحيح جوهري بناءً على طلب صريح من المستخدم: كانت هذه البطاقة تعرض سعر البيع (الإيراد) تحت مُسمّى
    // "القيمة" — وهو مُضلِّل لبطاقة الغرض منها إظهار رأس المال المرتبط بالبضاعة، لا ربحية البيع (المعروضة
    // أصلاً بشكل صحيح في بطاقة "مكسب الجرد المكتبي" المستقلة أدناها). الآن تعرض تكلفة رأس المال الفعلية
    // (COGS) بدل سعر البيع.
    $sec3_sold_value_syp = floatval($s3s['cogs_syp']);
    $sec3_sold_value_usd = floatval($s3s['cogs_usd']);
    $sec3_profit_syp = floatval($s3s['revenue_syp']) - floatval($s3s['cogs_syp']);

    $stmt_s3_delivered = $conn->prepare("
        SELECT COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0)
        FROM sale_items si INNER JOIN sales s ON si.sale_id = s.id INNER JOIN products p ON si.product_id = p.id
        WHERE p.supplier_id IS NULL AND s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_s3_delivered->execute([$sec3_start, $sec3_end]);
    $sec3_delivered_qty = floatval($stmt_s3_delivered->fetchColumn());

    // تصحيح جوهري بناءً على طلب صريح من المستخدم ("يجب أن يعرض قيمة رأس مال البضائع"): كان الاستعلام
    // يستخدم si.total_price_syp (سعر البيع/الإيراد) بدل تكلفة رأس المال الفعلية — يُضخِّم الرقم المعروض
    // بمقدار هامش الربح المتوقَّع على هذه القطع، لا رأس المال المستثمَر فيها فعلياً كما توحي التسمية.
    $stmt_s3_pending = $conn->prepare("
        SELECT COALESCE(SUM(si.quantity), 0) AS qty,
               COALESCE(SUM(si.quantity * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate), 0) AS value_syp,
               COALESCE(SUM(si.quantity * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)), 0) AS value_usd
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

// ============================================================
// قسم مستقل خامس: "نظرة عامة شاملة على النظام" — يجمع كل وحدات البرنامج (المنتجات/المخزون، المبيعات،
// المشتريات، الموردون، المندوبون، المصاريف) في مكان واحد — إجابة مباشرة على "أريد كل معلومات وإحصائيات
// البرنامج من لوحة التحكم". بفلتر "من - إلى" مستقل خاص به فقط، منفصل تماماً عن الفلتر الأول وفلتري
// sec2/sec3 أعلاه — يُطبَّق على مؤشرات "الحركة" (مبيعات/مشتريات/مصاريف ضمن الفترة)، بينما
// تبقى أرصدة "اللحظة الحالية" (المخزون الآن، صافي مستحق الموردين، صافي ذمة المندوبين) دائماً حية بلا فلتر
// لأنها أرصدة تراكمية وليست حركة فترة.
// ============================================================
// تصحيح جوهري بناءً على طلب صريح من المستخدم: "كل قسم له فلتر خاص بتاريخ من إلى" — استُبدِل التنقّل
// الأسبوعي (سبت->خميس) + مفتاح "عرض كل الأوقات" بفلتر "من - إلى" بسيط ومستقل، بنفس نمط sec2/sec3
// تماماً (قيمة افتراضية: الشهر الحالي بالكامل). يُطبَّق هذا الفلتر على قسم "نظرة عامة شاملة على النظام"
// وقسمَي "مؤشرات مالية إضافية" و"صافي حركة الموردين" اللذين يشاركانه نفس المتغيرات ($ov_from/$ov_to).
$ov_start = $_GET['ov_start'] ?? date('Y-m-01');
$ov_end   = $_GET['ov_end'] ?? date('Y-m-t');
$ov_from = $ov_start;
$ov_to   = $ov_end;

$ov_office_items = [];
$ov2_pending_items = [];
$ov = [
    'products_count' => 0, 'products_stock_qty' => 0, 'products_stock_value_usd' => 0, 'categories_count' => 0,
    'products_supplier_count' => 0, 'products_supplier_qty' => 0, 'products_supplier_value_usd' => 0,
    'products_office_count' => 0, 'products_office_qty' => 0, 'products_office_value_usd' => 0,
    'sales_count' => 0, 'sales_total_syp' => 0, 'sales_customers_count' => 0, 'sales_pending_count' => 0, 'sales_delivered_count' => 0,
    'sales_pending_value_syp' => 0, 'sales_delivered_value_syp' => 0,
    'purchases_count' => 0, 'purchases_total_usd' => 0,
    'suppliers_count' => 0, 'suppliers_net_payable_usd' => 0,
    'representatives_count' => 0, 'representatives_net_balance_syp' => 0,
    'expenses_total_syp' => 0,
];
try {
    // رصيد لحظي حالي دائماً (بلا فلتر) — المخزون "الآن" لا "ضمن الأسبوع"
    $stmt_ov_prod = $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(current_quantity), 0) AS q, COALESCE(SUM(current_quantity * cost_price_usd), 0) AS v FROM products");
    $r = $stmt_ov_prod->fetch(PDO::FETCH_ASSOC);
    $ov['products_count'] = intval($r['c']); $ov['products_stock_qty'] = floatval($r['q']); $ov['products_stock_value_usd'] = floatval($r['v']);
    $ov['categories_count'] = intval($conn->query("SELECT COUNT(*) FROM categories")->fetchColumn());

    // تفصيل المخزون: منتجات الموردين (مشتراة) مقابل منتجات الجرد المكتبي (بلا مورد) — نفس تصنيف products.php
    $stmt_ov_prod_src = $conn->query("
        SELECT
            SUM(CASE WHEN supplier_id IS NOT NULL THEN 1 ELSE 0 END) AS sc,
            SUM(CASE WHEN supplier_id IS NOT NULL THEN current_quantity ELSE 0 END) AS sq,
            SUM(CASE WHEN supplier_id IS NOT NULL THEN current_quantity * cost_price_usd ELSE 0 END) AS sv,
            SUM(CASE WHEN supplier_id IS NULL THEN 1 ELSE 0 END) AS oc,
            SUM(CASE WHEN supplier_id IS NULL THEN current_quantity ELSE 0 END) AS oq,
            SUM(CASE WHEN supplier_id IS NULL THEN current_quantity * cost_price_usd ELSE 0 END) AS ov
        FROM products
    ");
    $rs = $stmt_ov_prod_src->fetch(PDO::FETCH_ASSOC);
    $ov['products_supplier_count'] = intval($rs['sc']); $ov['products_supplier_qty'] = floatval($rs['sq']); $ov['products_supplier_value_usd'] = floatval($rs['sv']);
    $ov['products_office_count'] = intval($rs['oc']); $ov['products_office_qty'] = floatval($rs['oq']); $ov['products_office_value_usd'] = floatval($rs['ov']);

    // تفصيل احترافي: قائمة كل صنف جرد مكتبي على حدة (بلا مورد)، للتحقق اليدوي من القيمة الإجمالية
    // صنفاً صنفاً بدل الاكتفاء برقم مجمَّع لا يمكن مراجعته مباشرة من الشاشة نفسها.
    $ov_office_items = [];
    try {
        $stmt_office_items = $conn->query("
            SELECT id, product_name, sku, current_quantity, cost_price_usd
            FROM products WHERE supplier_id IS NULL AND current_quantity > 0
            ORDER BY product_name ASC
        ");
        $ov_office_items = $stmt_office_items->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { }
} catch (Exception $e) { }

try {
    $stmt_ov_sales = $conn->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount_syp), 0) AS t, COUNT(DISTINCT customer_name) AS cu,
        SUM(CASE WHEN delivery_status = 'Pending' THEN 1 ELSE 0 END) AS pend, SUM(CASE WHEN delivery_status = 'Delivered' THEN 1 ELSE 0 END) AS del,
        COALESCE(SUM(CASE WHEN delivery_status = 'Pending' THEN total_amount_syp ELSE 0 END), 0) AS pend_value,
        COALESCE(SUM(CASE WHEN delivery_status = 'Delivered' THEN total_amount_syp ELSE 0 END), 0) AS del_value
        FROM sales WHERE invoice_date BETWEEN ? AND ?");
    $stmt_ov_sales->execute([$ov_from, $ov_to]);
    $r = $stmt_ov_sales->fetch(PDO::FETCH_ASSOC);
    $ov['sales_count'] = intval($r['c']); $ov['sales_total_syp'] = floatval($r['t']); $ov['sales_customers_count'] = intval($r['cu']);
    $ov['sales_pending_count'] = intval($r['pend']); $ov['sales_delivered_count'] = intval($r['del']);
    $ov['sales_pending_value_syp'] = floatval($r['pend_value']); $ov['sales_delivered_value_syp'] = floatval($r['del_value']);
} catch (Exception $e) { }

try {
    $stmt_ov_pur = $conn->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount_usd), 0) AS t FROM purchase_invoices WHERE invoice_date BETWEEN ? AND ?");
    $stmt_ov_pur->execute([$ov_from, $ov_to]);
    $r = $stmt_ov_pur->fetch(PDO::FETCH_ASSOC);
    $ov['purchases_count'] = intval($r['c']); $ov['purchases_total_usd'] = floatval($r['t']);
} catch (Exception $e) { }

try {
    $ov['suppliers_count'] = intval($conn->query("SELECT COUNT(*) FROM suppliers")->fetchColumn());
    // رصيد لحظي حالي دائماً (بلا فلتر) — "من نحن مدينون له الآن" لا "حركة ضمن الأسبوع"
    $sup_purch = floatval($conn->query("SELECT COALESCE(SUM(total_amount_usd), 0) FROM purchase_invoices WHERE payment_status != 'Paid'")->fetchColumn());
    $sup_pay = floatval($conn->query("SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_payments")->fetchColumn());
    $sup_ret = floatval($conn->query("SELECT COALESCE(SUM(pr.total_amount_usd), 0) FROM purchase_returns pr INNER JOIN purchase_invoices pi ON pr.purchase_invoice_id = pi.id WHERE pi.payment_status != 'Paid'")->fetchColumn());
    $sup_disc_manual = floatval($conn->query("SELECT COALESCE(SUM(returns_discounts), 0) FROM suppliers")->fetchColumn());
    $sup_disc_logged = floatval($conn->query("SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_discounts")->fetchColumn());
    $sup_opening = floatval($conn->query("SELECT COALESCE(SUM(opening_balance_usd), 0) FROM suppliers")->fetchColumn());
    $ov['suppliers_net_payable_usd'] = $sup_purch - $sup_pay - $sup_ret - $sup_disc_manual - $sup_disc_logged + $sup_opening;
} catch (Exception $e) { }

try {
    $ov['representatives_count'] = intval($conn->query("SELECT COUNT(*) FROM representatives")->fetchColumn());
    // رصيد لحظي حالي دائماً (بلا فلتر)
    $rep_earned = floatval($conn->query("
        SELECT COALESCE(SUM(s.total_commissions), 0)
            - COALESCE((SELECT SUM(sr.total_commission_reversed) FROM sales_returns sr INNER JOIN sales s2 ON sr.sale_id = s2.id WHERE s2.delivery_status = 'Delivered'), 0)
        FROM sales s WHERE s.delivery_status = 'Delivered'
    ")->fetchColumn());
    $rep_paid = floatval($conn->query("SELECT COALESCE(SUM(amount_syp), 0) FROM representative_payments")->fetchColumn());
    $ov['representatives_net_balance_syp'] = $rep_earned - $rep_paid;
} catch (Exception $e) { }

try {
    $stmt_ov_exp = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM operational_expenses WHERE expense_date BETWEEN ? AND ?");
    $stmt_ov_exp->execute([$ov_from, $ov_to]);
    $ov['expenses_total_syp'] = floatval($stmt_ov_exp->fetchColumn());
} catch (Exception $e) { }

// ============================================================
// قسم مستقل سادس: مؤشرات مالية إضافية ضمن نفس الفترة الأسبوعية المختارة أعلاه (رأس مال المنتجات قيد
// الانتظار، تكلفة البضائع المباعة، إجمالي الإيرادات) بالدولار والليرة السورية معاً، بالإضافة إلى
// إجمالي حساب الموردين بالدولار (رصيد لحظي حالي، بلا فلتر لأنه ذمة وليس حركة فترة).
// ============================================================
$ov2 = ['pending_capital_usd' => 0, 'pending_capital_syp' => 0, 'cogs_usd' => 0, 'cogs_syp' => 0, 'revenue_usd' => 0, 'revenue_syp' => 0];
try {
    // رأس مال المنتجات قيد الانتظار = تكلفة الأصناف المبيعة لكن غير المُسلَّمة بعد (لم تتحول لـCOGS فعلي بعد)
    $stmt_ov2_pending = $conn->prepare("
        SELECT COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)), 0) AS cap_usd,
               COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate), 0) AS cap_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Pending' AND s.invoice_date BETWEEN ? AND ?
    ");
    $stmt_ov2_pending->execute([$ov_from, $ov_to]);
    $r2 = $stmt_ov2_pending->fetch(PDO::FETCH_ASSOC);
    $ov2['pending_capital_usd'] = floatval($r2['cap_usd']); $ov2['pending_capital_syp'] = floatval($r2['cap_syp']);

    // تفصيل احترافي: كل صنف قيد الانتظار على حدة (رقم الفاتورة، الكمية المتبقية، التكلفة، القيمة)
    $ov2_pending_items = [];
    $stmt_ov2_pending_items = $conn->prepare("
        SELECT s.invoice_number, s.invoice_date, p.product_name,
            (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) AS remaining_qty,
            COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) AS unit_cost_usd
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Pending' AND s.invoice_date BETWEEN ? AND ?
        ORDER BY s.invoice_date ASC
    ");
    $stmt_ov2_pending_items->execute([$ov_from, $ov_to]);
    $ov2_pending_items = $stmt_ov2_pending_items->fetchAll(PDO::FETCH_ASSOC);

    // تكلفة البضائع المباعة (COGS) بالليرة: مُنسَبة لتاريخ التسليم الأصلي دائماً (لا تاريخ أي عكس/مرتجع
    // لاحق) — نفس التصحيح الجذري المُطبَّق أعلاه على dash_cogs بالضبط، لضمان اتساق تام بين كل أقسام اللوحة.
    $stmt_ov2_cogs_syp = $conn->prepare("
        SELECT COALESCE(SUM(
            (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
            * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate
        ), 0)
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_ov2_cogs_syp->execute([$ov_from, $ov_to]);
    $ov2['cogs_syp'] = floatval($stmt_ov2_cogs_syp->fetchColumn());

    // تصحيح جوهري: postJournalLine (المُستخدَمة لترحيل قيد COGS) لا تُعبِّئ foreign_debit/foreign_credit
    // إطلاقاً (تُدرِج فقط SYP) — فكانت هذه القيمة "$0.00" ثابتة دوماً. تُحسَب الآن مباشرة من sale_items
    // بالدولار بلا أي تحويل عملة على الإطلاق (التكلفة أصلاً بالدولار)، بنفس منهجية financial_reports.php.
    $stmt_ov2_cogs_usd = $conn->prepare("
        SELECT COALESCE(SUM(
            (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
            * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd)
        ), 0)
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_ov2_cogs_usd->execute([$ov_from, $ov_to]);
    $ov2['cogs_usd'] = floatval($stmt_ov2_cogs_usd->fetchColumn());

    // إجمالي الإيرادات بالليرة: من دفتر اليومية مباشرة (رقم صحيح)
    $stmt_ov2_rev_syp = $conn->prepare("
        SELECT COALESCE(SUM(je.credit) - SUM(je.debit), 0) FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'إيرادات المبيعات' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_ov2_rev_syp->execute([$ov_from, $ov_to]);
    $ov2['revenue_syp'] = floatval($stmt_ov2_rev_syp->fetchColumn());

    // تصحيح جوهري: نفس مشكلة COGS بالضبط — قيد الإيراد لا يحمل قيمة بالدولار في اليومية إطلاقاً، فكانت
    // "$0.00" ثابتة دوماً. تُحسَب الآن مباشرة من sales.total_amount_usd (مُخزَّن أصلاً لكل فاتورة وقت
    // إصدارها) لنفس الفواتير التي تحقّق شرط الاعتراف بالإيراد (مُسلَّمة + مُحصَّلة نقداً بالكامل).
    $stmt_ov2_rev_usd = $conn->prepare("
        SELECT COALESCE(SUM(s.total_amount_usd), 0) FROM sales s
        WHERE s.delivery_status = 'Delivered' AND s.payment_status = 'Paid'
          AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
    ");
    $stmt_ov2_rev_usd->execute([$ov_from, $ov_to]);
    $ov2['revenue_usd'] = floatval($stmt_ov2_rev_usd->fetchColumn());
} catch (Exception $e) { }

// ============================================================
// قسم مستقل سابع: صافي حركة الفترة لكل مورد على حدة (نفس مفهوم عمود "صافي حركة الفترة" في
// suppliers.php)، ضمن نفس الفترة الأسبوعية/الكلية المختارة أعلاه لقسم "نظرة عامة شاملة على النظام".
// ============================================================
// فلتر مستقل جديد بناءً على طلب صريح من المستخدم: "تكلفة البضائع المباعة (COGS) — مُسلَّمة (الفترة)"
// لكل مورد، بنفس مبدأ الفلتر الأول بالضبط (يومي / أسبوعي يبدأ السبت وينتهي الخميس) — مستقل تماماً عن
// فلتر "نظرة عامة شاملة" (ov_from/ov_to) أعلاه، بأسماء GET منفصلة (smv_*) لتفادي أي تعارض بينهما.
$smv_filter_type = $_GET['smv_filter_type'] ?? 'daily';
if ($smv_filter_type === 'weekly') {
    $smv_dow = intval(date('w'));
    $smv_days_since_saturday = ($smv_dow + 1) % 7;
    $smv_start = date('Y-m-d', strtotime("-{$smv_days_since_saturday} days"));
    $smv_end = date('Y-m-d', strtotime($smv_start . ' +5 days'));
} elseif ($smv_filter_type === 'monthly') {
    $smv_start = date('Y-m-01');
    $smv_end = date('Y-m-t');
} else {
    $smv_filter_type = 'daily';
    $smv_start = $today_str;
    $smv_end = $today_str;
}

$ov_supplier_rows = [];
try {
    // تصحيح بناءً على طلب صريح من المستخدم: كل أعمدة الجدول توحَّدت الآن على فلتر smv نفسه (يومي/أسبوعي
    // سبت-خميس) بدل الاعتماد على فلتر "نظرة عامة شاملة" (ov_from/ov_to) المنفصل سابقاً — عمود واحد فقط
    // لكل الجدول، لا فلترين مختلفين بجانب بعضهما كما كان سابقاً.
    $stmt_ov_sup = $conn->prepare("
        SELECT s.id, s.supplier_name,
            COALESCE((SELECT SUM(pi.total_amount_usd) FROM purchase_invoices pi WHERE pi.supplier_id = s.id AND pi.payment_status != 'Paid' AND pi.invoice_date BETWEEN ? AND ?), 0) AS period_purchases,
            COALESCE((SELECT SUM(sp.amount_usd) FROM supplier_payments sp WHERE sp.supplier_id = s.id AND sp.payment_date BETWEEN ? AND ?), 0) AS period_payments,
            COALESCE((SELECT SUM(pr.total_amount_usd) FROM purchase_returns pr INNER JOIN purchase_invoices pi2 ON pr.purchase_invoice_id = pi2.id WHERE pi2.supplier_id = s.id AND pi2.payment_status != 'Paid' AND pr.return_date BETWEEN ? AND ?), 0) AS period_returns,
            COALESCE((SELECT SUM(sd.amount_usd) FROM supplier_discounts sd WHERE sd.supplier_id = s.id AND sd.discount_date BETWEEN ? AND ?), 0) AS period_discounts
        FROM suppliers s
    ");
    $stmt_ov_sup->execute([$smv_start, $smv_end, $smv_start, $smv_end, $smv_start, $smv_end, $smv_start, $smv_end]);
    $ov_supplier_rows_raw = $stmt_ov_sup->fetchAll(PDO::FETCH_ASSOC);

    // إجمالي المشتريات لكل الموردين ضمن نفس فترة smv، لحساب نسبة شراء كل مورد من الإجمالي
    $total_period_purchases = 0;
    foreach ($ov_supplier_rows_raw as $row) { $total_period_purchases += floatval($row['period_purchases']); }

    // COGS مُسلَّمة لكل مورد ضمن فترة smv (يومي/أسبوعي سبت-خميس) — نفس المنهجية الهجينة الدقيقة
    // المُطبَّقة في supplier_view.php: تُقرَأ من استهلاك الدفعات الفعلي (مورّد الدفعة الحقيقي) حيثما
    // وُجد سجل، مع رجوع تلقائي للمنهجية القديمة (تكلفة ممزوجة) فقط للمبيعات السابقة لتفعيل نظام الدفعات.
    $stmt_smv_cogs = $conn->prepare("
        SELECT COALESCE(SUM(v), 0) FROM (
            SELECT sibc.quantity_consumed * sibc.unit_cost_usd AS v
            FROM sale_item_batch_consumption sibc
            JOIN inventory_batches ib ON sibc.batch_id = ib.id
            JOIN sale_items si ON sibc.sale_item_id = si.id
            JOIN sales s ON si.sale_id = s.id
            WHERE ib.supplier_id = ? AND s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
            UNION ALL
            SELECT (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * si.cost_price_usd_at_sale AS v
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN products p ON si.product_id = p.id
            WHERE p.supplier_id = ? AND s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM sale_item_batch_consumption sibc2 WHERE sibc2.sale_item_id = si.id)
        ) t
    ");

    foreach ($ov_supplier_rows_raw as $row) {
        $p_purch = floatval($row['period_purchases']);
        $p_pay = floatval($row['period_payments']);
        $p_ret = floatval($row['period_returns']) + floatval($row['period_discounts']);
        $p_net = $p_purch - $p_pay - $p_ret;
        $p_purchase_share_pct = $total_period_purchases > 0 ? ($p_purch / $total_period_purchases) * 100 : 0;

        $stmt_smv_cogs->execute([$row['id'], $smv_start, $smv_end, $row['id'], $smv_start, $smv_end]);
        $p_cogs_delivered = floatval($stmt_smv_cogs->fetchColumn());

        // نعرض المورد إن كانت له حركة فعلية ضمن فترة smv (مشتريات/دفعات/مردودات أو COGS)
        if (abs($p_purch) > 0.009 || abs($p_pay) > 0.009 || abs($p_ret) > 0.009 || abs($p_cogs_delivered) > 0.009) {
            $ov_supplier_rows[] = ['id' => intval($row['id']), 'name' => $row['supplier_name'], 'purchases' => $p_purch, 'payments' => $p_pay, 'returns' => $p_ret, 'net' => $p_net, 'purchase_share_pct' => $p_purchase_share_pct, 'cogs_delivered' => $p_cogs_delivered];
        }
    }
    usort($ov_supplier_rows, function ($a, $b) { return abs($b['net']) <=> abs($a['net']); });
} catch (Exception $e) { }

// جدول المندوبين — بناءً على طلب صريح من المستخدم: فلتر مستقل تماماً عن فلتر smv الخاص بالموردين، بنفس
// المبدأ (يومي / أسبوعي سبت-خميس)، بأسماء GET منفصلة (rmv_*) لتفادي أي تعارض مع smv أو أي فلتر آخر.
$rmv_filter_type = $_GET['rmv_filter_type'] ?? 'daily';
if ($rmv_filter_type === 'weekly') {
    $rmv_dow = intval(date('w'));
    $rmv_days_since_saturday = ($rmv_dow + 1) % 7;
    $rmv_start = date('Y-m-d', strtotime("-{$rmv_days_since_saturday} days"));
    $rmv_end = date('Y-m-d', strtotime($rmv_start . ' +5 days'));
} elseif ($rmv_filter_type === 'monthly') {
    $rmv_start = date('Y-m-01');
    $rmv_end = date('Y-m-t');
} else {
    $rmv_filter_type = 'daily';
    $rmv_start = $today_str;
    $rmv_end = $today_str;
}

// اسم المندوب، صافي رصيده المستحق (تراكمي كامل، كما في representative_profile.php)، نسبته من إجمالي
// مبيعات النظام خلال فترة rmv (صافٍ إلى صافٍ)، عدد القطع الصافية التي باعها خلال الفترة، وعدد فواتيره
// التي عليها مرتجع خلال الفترة.
$ov_rep_rows = [];
try {
    $total_period_sales_value = 0;
    $stmt_total_period_sales = $conn->prepare("
        SELECT COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * si.unit_price_syp), 0)
        FROM sale_items si INNER JOIN sales s ON si.sale_id = s.id
        WHERE (s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?)
           OR (s.delivery_status != 'Delivered' AND s.invoice_date BETWEEN ? AND ?)
    ");
    $stmt_total_period_sales->execute([$rmv_start, $rmv_end, $rmv_start, $rmv_end]);
    $total_period_sales_value = floatval($stmt_total_period_sales->fetchColumn());

    $stmt_rep_rows = $conn->prepare("
        SELECT r.id, r.name,
            COALESCE((SELECT SUM(rt.amount) FROM representative_transactions rt WHERE rt.representative_id = r.id AND rt.transaction_type = 'deduction'), 0) AS total_deductions,
            COALESCE((SELECT SUM(rp.amount_syp) FROM representative_payments rp WHERE rp.representative_id = r.id), 0) AS total_payments,
            (SELECT COALESCE(SUM(s.total_commissions), 0) - COALESCE((
                SELECT SUM(sr.total_commission_reversed) FROM sales_returns sr INNER JOIN sales s2 ON sr.sale_id = s2.id
                WHERE s2.representative_id = r.id AND s2.delivery_status = 'Delivered'
             ), 0)
             FROM sales s WHERE s.representative_id = r.id AND s.delivery_status = 'Delivered'
            ) AS total_earned_commissions,
            (SELECT COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0)
             FROM sale_items si INNER JOIN sales s2 ON si.sale_id = s2.id
             WHERE s2.representative_id = r.id
               AND ((s2.delivery_status = 'Delivered' AND COALESCE(s2.delivered_at, s2.invoice_date) BETWEEN ? AND ?)
                    OR (s2.delivery_status != 'Delivered' AND s2.invoice_date BETWEEN ? AND ?))
            ) AS period_net_qty,
            (SELECT COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * si.unit_price_syp), 0)
             FROM sale_items si INNER JOIN sales s3 ON si.sale_id = s3.id
             WHERE s3.representative_id = r.id
               AND ((s3.delivery_status = 'Delivered' AND COALESCE(s3.delivered_at, s3.invoice_date) BETWEEN ? AND ?)
                    OR (s3.delivery_status != 'Delivered' AND s3.invoice_date BETWEEN ? AND ?))
            ) AS period_net_value,
            (SELECT COUNT(DISTINCT sr.sale_id) FROM sales_returns sr INNER JOIN sales s4 ON sr.sale_id = s4.id
             WHERE s4.representative_id = r.id AND DATE(sr.created_at) BETWEEN ? AND ?
            ) AS period_returned_invoices_count
        FROM representatives r
    ");
    $stmt_rep_rows->execute([
        $rmv_start, $rmv_end, $rmv_start, $rmv_end,
        $rmv_start, $rmv_end, $rmv_start, $rmv_end,
        $rmv_start, $rmv_end,
    ]);
    foreach ($stmt_rep_rows->fetchAll(PDO::FETCH_ASSOC) as $rr) {
        // صافي الرصيد المستحق التراكمي الكامل — يُقرَأ مباشرة من القيود (استحقاق - مرتجعات) مطروحاً منه
        // الدفعات والخصومات، بنفس صيغة representative_profile.php بالضبط
        $rep_net_balance = floatval($rr['total_earned_commissions']) - floatval($rr['total_payments']) - floatval($rr['total_deductions']);
        $rep_share_pct = $total_period_sales_value > 0 ? (floatval($rr['period_net_value']) / $total_period_sales_value) * 100 : 0;

        if (abs($rep_net_balance) > 0.009 || floatval($rr['period_net_qty']) > 0.009 || intval($rr['period_returned_invoices_count']) > 0) {
            $ov_rep_rows[] = [
                'id' => intval($rr['id']),
                'name' => $rr['name'],
                'net_balance' => $rep_net_balance,
                'share_pct' => $rep_share_pct,
                'net_qty' => floatval($rr['period_net_qty']),
                'returned_invoices_count' => intval($rr['period_returned_invoices_count']),
            ];
        }
    }
    usort($ov_rep_rows, function ($a, $b) { return $b['net_qty'] <=> $a['net_qty']; });

    // إجمالي صف أسفل الجدول — بناءً على طلب صريح من المستخدم
    $ov_rep_totals = ['net_balance' => 0, 'share_pct' => 0, 'net_qty' => 0, 'returned_invoices_count' => 0];
    foreach ($ov_rep_rows as $rr) {
        $ov_rep_totals['net_balance'] += $rr['net_balance'];
        $ov_rep_totals['share_pct'] += $rr['share_pct'];
        $ov_rep_totals['net_qty'] += $rr['net_qty'];
        $ov_rep_totals['returned_invoices_count'] += $rr['returned_invoices_count'];
    }
} catch (Exception $e) { }
?>

<div style="padding: 20px;">
    <div style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); color: white; padding: 25px; border-radius: 10px; margin-bottom: 25px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,0.15);">
        <h1 style="margin: 0 0 10px 0; font-size: 24px;">مرحباً بك، لؤي القبالان</h1>
        <p style="margin: 0; opacity: 0.9; font-size: 14px;">نظام Smart ERP المطور يعمل بكفاءة عالية. يمكنك إدارة الحسابات، العملات، والقيود من القائمة الجانبية.</p>
    </div>

    <!-- شريط تنقّل سريع بين أقسام لوحة التحكم الطويلة -->
    <div class="no-print" style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 10px 15px; margin-bottom: 20px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <span style="font-size:12px; color:#888; font-weight:bold;"><i class="fas fa-compass"></i> انتقال سريع:</span>
        <a href="#section-sec2" style="text-decoration:none; background:#eaf1fc; color:#2c4e9c; padding:5px 12px; border-radius:14px; font-size:12.5px; font-weight:bold;">الإيرادات والمصاريف</a>
        <a href="#section-sec3" style="text-decoration:none; background:#eaf1fc; color:#2c4e9c; padding:5px 12px; border-radius:14px; font-size:12.5px; font-weight:bold;">الجرد المكتبي</a>
        <a href="#section-overview" style="text-decoration:none; background:#eaf1fc; color:#2c4e9c; padding:5px 12px; border-radius:14px; font-size:12.5px; font-weight:bold;">نظرة عامة شاملة</a>
        <a href="#section-financial-extra" style="text-decoration:none; background:#eaf1fc; color:#2c4e9c; padding:5px 12px; border-radius:14px; font-size:12.5px; font-weight:bold;">مؤشرات مالية إضافية</a>
        <a href="#section-supplier-movement" style="text-decoration:none; background:#eaf1fc; color:#2c4e9c; padding:5px 12px; border-radius:14px; font-size:12.5px; font-weight:bold;">حركة الموردين</a>
        <a href="#section-rep-movement" style="text-decoration:none; background:#eaf1fc; color:#2c4e9c; padding:5px 12px; border-radius:14px; font-size:12.5px; font-weight:bold;">المندوبون</a>
        <a href="#section-priority" style="text-decoration:none; background:#eaf1fc; color:#2c4e9c; padding:5px 12px; border-radius:14px; font-size:12.5px; font-weight:bold;">أولوية السداد</a>
        <a href="#section-quick-actions" style="text-decoration:none; background:#eaf1fc; color:#2c4e9c; padding:5px 12px; border-radius:14px; font-size:12.5px; font-weight:bold;">إجراءات سريعة</a>
    </div>

    <?php if ($dash_error): ?>
        <div style="background: #fff3cd; color: #856404; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px;"><?php echo htmlspecialchars($dash_error); ?></div>
    <?php endif; ?>

    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <span style="font-size: 13px; font-weight: bold; color: #555;"><i class="fas fa-filter"></i> الفترة المعروضة:</span>
        <?php
            // تصحيح: الروابط والنموذج أدناه كانت تُعيد بناء رابط ?filter_type=... من الصفر، فتفقد أي
            // فلاتر أخرى مطبَّقة حالياً (فلتر "الجرد المكتبي" sec3_*، وفلتر البطاقة المالية sec2_*) —
            // الآن تُحافَظ كل معاملات GET الأخرى تلقائياً عند التبديل بين يومي/أسبوعي/تاريخ محدَّد.
            $filter1_base_params = $_GET;
            unset($filter1_base_params['filter_type'], $filter1_base_params['start_date']);
        ?>
        <a href="?<?php echo http_build_query(array_merge($filter1_base_params, ['filter_type' => 'daily'])); ?>" style="text-decoration: none;">
            <span style="padding: 7px 16px; border-radius: 5px; font-size: 13px; font-weight: bold; background: <?php echo $filter_type === 'daily' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $filter_type === 'daily' ? '#fff' : '#4e73df'; ?>;">يومي</span>
        </a>
        <a href="?<?php echo http_build_query(array_merge($filter1_base_params, ['filter_type' => 'weekly'])); ?>" style="text-decoration: none;">
            <span style="padding: 7px 16px; border-radius: 5px; font-size: 13px; font-weight: bold; background: <?php echo $filter_type === 'weekly' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $filter_type === 'weekly' ? '#fff' : '#4e73df'; ?>;">أسبوعي (سبت-خميس)</span>
        </a>
        <span style="width: 1px; height: 24px; background: #e3e6f0;"></span>
        <form method="GET" style="display: flex; gap: 8px; align-items: center;">
            <?php foreach ($filter1_base_params as $k => $v) { echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">'; } ?>
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
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-receipt"></i> المصاريف — تشغيلية + متكررة + رواتب + شحن (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace;"><?php echo number_format($dash_total_expenses_combined, 2); ?> ل.س</div>
            <div style="font-size: 10.5px; color: #999; margin-top: 4px;">تشغيلية/متكررة: <?php echo number_format($dash_expenses, 0); ?> | رواتب وحوافز: <?php echo number_format($dash_payroll, 0); ?> | شحن: <?php echo number_format($dash_shipping, 0); ?></div>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #fd7e14; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-handshake"></i> عمولات المندوبين (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #fd7e14; font-family: monospace;"><?php echo number_format($dash_commissions, 2); ?> ل.س</div>
        </div>

        <!-- تصحيح شفافية: تكاليف الشحن كانت تُخصَم فعلياً ضمن معادلة "صافي الربح" دون أي بطاقة تعرضها،
        فيبدو صافي الربح "أقل من المتوقَّع" دون تفسير ظاهر للمستخدم عند جمع باقي البطاقات يدوياً. -->
        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #6f42c1; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;"><i class="fas fa-shipping-fast"></i> تكاليف الشحن (<?php echo $filter_labels[$filter_type]; ?>)</div>
            <div style="font-size: 20px; font-weight: bold; color: #6f42c1; font-family: monospace;"><?php echo number_format($dash_shipping, 2); ?> ل.س</div>
            <div style="font-size: 11px; color: #999; margin-top: 5px;">مصروف حقيقي مُرحَّل باليومية — مُحتسَب ضمن "المصاريف" ومخصوم من صافي الربح</div>
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
            <div style="font-size: 11px; color: #999; margin-top: 5px; line-height: 1.6;">= الإيرادات − COGS − العمولات − المصاريف (رواتب + تشغيلية + شحن)</div>
        </div>

    </div>

    <div id="section-sec2" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
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
                <div style="color: #a33636; font-size: 12.5px; font-weight: bold;">تكلفة البضائع المباعة (COGS)</div>
                <div style="font-size: 19px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_cogs_syp, 2); ?> ل.س</div>
                <div style="font-size: 13px; color: #a33636; font-family: monospace; margin-top: 3px;">≈ $<?php echo number_format($sec2_cogs_usd, 2); ?></div>
            </div>
            <div style="background: #fdecea; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px;">
                <div style="color: #a33636; font-size: 12.5px; font-weight: bold;">إجمالي المصاريف الشاملة (تُحتسَب في صافي الربح)</div>
                <div style="font-size: 19px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_cogs_syp + $sec2_expenses + $sec2_payroll + $sec2_commissions + $sec2_shipping + $sec2_fx_loss + max(0, $sec2_fx_unrealized), 2); ?> ل.س</div>
                <div style="font-size: 10.5px; color: #a33636; margin-top: 4px; line-height: 1.6;">
                    COGS: <?php echo number_format($sec2_cogs_syp, 0); ?> | مصاريف: <?php echo number_format($sec2_expenses, 0); ?> | رواتب: <?php echo number_format($sec2_payroll, 0); ?> |
                    عمولات: <?php echo number_format($sec2_commissions, 0); ?> | شحن: <?php echo number_format($sec2_shipping, 0); ?> |
                    فروقات عملة: <?php echo number_format($sec2_fx_loss + max(0, $sec2_fx_unrealized), 0); ?>
                </div>
            </div>
            <div style="background: <?php echo ($sec2_fx_gain - $sec2_fx_loss - $sec2_fx_unrealized) >= 0 ? '#e8f8f2' : '#fdecea'; ?>; border-right: 4px solid <?php echo ($sec2_fx_gain - $sec2_fx_loss - $sec2_fx_unrealized) >= 0 ? '#1cc88a' : '#e74a3b'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #555; font-size: 12.5px; font-weight: bold;" title="محقَّقة: من سداد دفعات الموردين. غير محقَّقة: إعادة تقييم الذمم المفتوحة للموردين بسعر إغلاق الفترة (IAS 21)">فروقات صرف العملة (دفعات الموردين)</div>
                <div style="font-size: 19px; font-weight: bold; color: <?php echo ($sec2_fx_gain - $sec2_fx_loss - $sec2_fx_unrealized) >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_fx_gain - $sec2_fx_loss - $sec2_fx_unrealized, 2); ?> ل.س</div>
                <div style="font-size: 10.5px; color: #888; margin-top: 4px; line-height: 1.6;">
                    محقَّقة — خسارة: <?php echo number_format($sec2_fx_loss, 0); ?> | محقَّقة — ربح: <?php echo number_format($sec2_fx_gain, 0); ?> | غير محقَّقة: <?php echo number_format($sec2_fx_unrealized, 0); ?>
                </div>
            </div>
            <div style="background: <?php echo $sec2_net >= 0 ? '#e8f8f2' : '#fdecea'; ?>; border-right: 4px solid <?php echo $sec2_net >= 0 ? '#1cc88a' : '#e74a3b'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #555; font-size: 12.5px; font-weight: bold;" title="= الإيرادات − COGS − مصاريف − رواتب − عمولات − شحن − فروقات عملة (خسارة محقَّقة وغير محقَّقة) + فروقات عملة (ربح محقَّق وغير محقَّق) — مطابق تماماً لـ«صافي الربح الحقيقي» في التقارير المالية">صافي الأرباح</div>
                <div style="font-size: 21px; font-weight: bold; color: <?php echo $sec2_net >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_net, 2); ?> ل.س</div>
                <div style="font-size: 10px; color: #999; margin-top: 4px;">= الإيرادات − COGS − مصاريف − رواتب − عمولات − شحن ± فروقات عملة</div>
                <div style="font-size: 9.5px; color: #aaa; margin-top: 3px;">مطابق لـ«صافي الربح الحقيقي» في <a href="financial_reports.php" style="color:#4e73df;">التقارير المالية</a> لنفس الفترة</div>
            </div>
            <div style="background: #f3eefe; border-right: 4px solid #8b5cf6; padding: 15px; border-radius: 6px;">
                <div style="color: #5b3aa8; font-size: 12.5px; font-weight: bold;" title="حركة نقدية فعلية (سداد التزام سابق) — لا تُحتسَب ضمن صافي الربح">دفعات الموردين (نقدية فعلية)</div>
                <div style="font-size: 19px; font-weight: bold; color: #8b5cf6; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_supplier_payments, 2); ?> ل.س</div>
                <div style="font-size: 10px; color: #888; margin-top: 3px;">لا تُخصَم من صافي الربح — تدفق نقدي فقط</div>
            </div>
            <div style="background: <?php echo $sec2_cogs_vs_payments_diff >= 0 ? '#fff8e6' : '#eaf1fc'; ?>; border-right: 4px solid <?php echo $sec2_cogs_vs_payments_diff >= 0 ? '#f6c23e' : '#4e73df'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #555; font-size: 12.5px; font-weight: bold;" title="COGS − دفعات الموردين ضمن نفس الفترة">الفرق: تكلفة البضائع مقابل دفعات الموردين</div>
                <div style="font-size: 21px; font-weight: bold; color: <?php echo $sec2_cogs_vs_payments_diff >= 0 ? '#96751c' : '#2c4e9c'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_cogs_vs_payments_diff, 2); ?> ل.س</div>
                <div style="font-size: 10px; color: #888; margin-top: 4px; line-height: 1.5;">
                    <?php if ($sec2_cogs_vs_payments_diff >= 0): ?>
                        موجب: بِيعت بضاعة (COGS) أكبر مما دُفِع فعلياً للموردين ضمن الفترة — التزام تجاه الموردين يتجه للارتفاع
                    <?php else: ?>
                        سالب: دُفِع للموردين أكثر من تكلفة ما بيع فعلاً ضمن الفترة — سداد ديون سابقة و/أو شراء مخزون جديد
                    <?php endif; ?>
                </div>
            </div>
            <div style="background: #f3eefe; border-right: 4px solid #8b5cf6; padding: 15px; border-radius: 6px;">
                <div style="color: #5b3aa8; font-size: 12.5px; font-weight: bold;">سحوبات المالك (الفترة)</div>
                <div style="font-size: 19px; font-weight: bold; color: #8b5cf6; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_owner_withdrawals, 2); ?> ل.س</div>
                <div style="font-size: 10px; color: #888; margin-top: 3px;"><a href="owner_withdrawals.php" style="color:#8b5cf6; text-decoration:none;">إدارة السحوبات ←</a></div>
            </div>
            <div style="background: <?php echo $sec2_owner_loan_outstanding > 0.009 ? '#fdecea' : '#eafaf1'; ?>; border-right: 4px solid <?php echo $sec2_owner_loan_outstanding > 0.009 ? '#e74a3b' : '#1cc88a'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #a33636; font-size: 12.5px; font-weight: bold;" title="ذمة مدينة على المالك — ليست توزيع أرباح، وليست مصروفاً؛ رصيد لحظي حالي، لا يتغيّر بتغيير الفلتر أعلاه">دين المالك المستحق (الآن)</div>
                <div style="font-size: 19px; font-weight: bold; color: <?php echo $sec2_owner_loan_outstanding > 0.009 ? '#e74a3b' : '#1cc88a'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_owner_loan_outstanding, 2); ?> ل.س</div>
                <div style="font-size: 10px; color: #888; margin-top: 3px;"><a href="owner_withdrawals.php" style="color:#a33636; text-decoration:none;">تسجيل سداد ←</a></div>
            </div>
            <div style="background: <?php echo $sec2_net_after_withdrawals >= 0 ? '#eef1fc' : '#fdecea'; ?>; border-right: 4px solid <?php echo $sec2_net_after_withdrawals >= 0 ? '#4e73df' : '#e74a3b'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #2c4e9c; font-size: 12.5px; font-weight: bold;" title="صافي الأرباح − سحوبات المالك ضمن نفس الفترة">صافي الربح بعد سحوبات الملّاك</div>
                <div style="font-size: 21px; font-weight: bold; color: <?php echo $sec2_net_after_withdrawals >= 0 ? '#4e73df' : '#e74a3b'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_net_after_withdrawals, 2); ?> ل.س</div>
                <div style="font-size: 10px; color: #888; margin-top: 3px;">= صافي الأرباح − سحوبات المالك</div>
            </div>
            <div style="background: <?php echo $sec2_cash_available >= 0 ? '#e8f8f2' : '#fdecea'; ?>; border-right: 4px solid <?php echo $sec2_cash_available >= 0 ? '#1cc88a' : '#e74a3b'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #1a7a5e; font-size: 12.5px; font-weight: bold;" title="مؤشر عملي وليس بنداً محاسبياً رسمياً — يطرح أيضاً القرض المستحق حالياً لأنه نقد خرج فعلياً من الصندوق">صافي الربح بعد كل السحوبات والقروض</div>
                <div style="font-size: 21px; font-weight: bold; color: <?php echo $sec2_cash_available >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_cash_available, 2); ?> ل.س</div>
                <div style="font-size: 10px; color: #888; margin-top: 3px;">= صافي الربح بعد السحوبات − دين المالك المستحق</div>
            </div>
            <div style="background: #fff8e6; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px;">
                <div style="color: #96751c; font-size: 12.5px; font-weight: bold;" title="تكلفة الأصناف المبيعة ضمن الفترة لكنها لم تُسلَّم بعد">رأس مال المنتجات قيد الانتظار</div>
                <div style="font-size: 19px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;">$<?php echo number_format($sec2_pending_capital_usd, 2); ?></div>
                <div style="font-size: 12px; color: #96751c; font-family: monospace; margin-top: 2px;"><?php echo number_format($sec2_pending_capital_syp, 2); ?> ل.س</div>
            </div>
            <div style="background: <?php echo $sec2_cash_actual >= 0 ? '#eafaf1' : '#fdecea'; ?>; border-right: 4px solid <?php echo $sec2_cash_actual >= 0 ? '#1a8f5f' : '#e74a3b'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #1a8f5f; font-size: 12.5px; font-weight: bold;" title="رصيد حساب «الصندوق الرئيسي» في اليومية — صافي كل حركة نقدية حقيقية (تحصيل مبيعات، مصاريف، سداد موردين، رواتب، سحوبات مالك...) منذ بداية النظام حتى الآن. رصيد لحظي دائماً، لا يتغيّر بتغيير فلتر الفترة أعلاه.">كم يجب أن يبقى في الصندوق الآن</div>
                <div style="font-size: 21px; font-weight: bold; color: <?php echo $sec2_cash_actual >= 0 ? '#1a8f5f' : '#e74a3b'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_cash_actual, 2); ?> ل.س</div>
                <div style="font-size: 10px; color: #888; margin-top: 3px;">رصيد لحظي تراكمي منذ البداية — قارنه بالنقد الفعلي في الصندوق لكشف أي عجز أو زيادة</div>
                <div style="font-size: 9.5px; color: #aaa; margin-top: 3px;">نفس رصيد <a href="daily_closing.php" style="color:#1a8f5f;">الإقفال اليومي</a> لتاريخ اليوم</div>
            </div>
            <div style="background: <?php echo $sec2_absolute_net_profit >= 0 ? '#e8f8f2' : '#fdecea'; ?>; border-right: 4px solid <?php echo $sec2_absolute_net_profit >= 0 ? '#1cc88a' : '#e74a3b'; ?>; padding: 15px; border-radius: 6px;">
                <div style="color: #555; font-size: 12.5px; font-weight: bold;" title="مجموع كل حساب Revenue ناقص كل حساب Expense في شجرة الحسابات بأكملها، تراكمياً منذ أول قيد في النظام وحتى اليوم — بلا أي فلتر فترة، وبلا تعداد يدوي لأسماء حسابات محدَّدة (فلا يُنسى أي بند جديد). نفس منهجية «الأرباح المحتجزة» في الميزانية العمومية بـfinancial_statements.php حرفياً.">صافي الربح المطلق (منذ البداية)</div>
                <div style="font-size: 21px; font-weight: bold; color: <?php echo $sec2_absolute_net_profit >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_absolute_net_profit, 2); ?> ل.س</div>
                <div style="font-size: 10px; color: #888; margin-top: 3px;">تراكمي منذ أول يوم للنظام — لا يتأثر بفلتر الفترة أعلاه إطلاقاً</div>
                <div style="font-size: 9.5px; color: #aaa; margin-top: 3px;">مطابق لـ«الأرباح المحتجزة» في <a href="financial_statements.php" style="color:#1cc88a;">الميزانية العمومية</a> لتاريخ اليوم</div>
            </div>
        </div>

        <?php if (count($sec2_cash_actual_breakdown) > 0): ?>
        <div style="margin-top:15px;">
            <button type="button" onclick="var t=document.getElementById('sec2CashBalanceDetail'); t.style.display = t.style.display==='none' ? 'block' : 'none';" style="background:#eafaf1; color:#1a8f5f; border:none; padding:7px 14px; border-radius:5px; cursor:pointer; font-size:12.5px; font-weight:bold;">
                <i class="fas fa-list"></i> تفصيل "كم يجب أن يبقى في الصندوق الآن" حسب المصدر — منذ اليوم الأول (<?php echo count($sec2_cash_actual_breakdown); ?> فئة)
            </button>
            <div id="sec2CashBalanceDetail" style="display:none; margin-top:10px; overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12.5px; text-align:right;">
                    <thead>
                        <tr style="background:#f8f9fc; border-bottom:2px solid #e3e6f0; color:#555;">
                            <th style="padding:7px 12px;">المصدر (source_module)</th>
                            <th style="padding:7px 12px;">عدد القيود</th>
                            <th style="padding:7px 12px;">الصافي (مدين−دائن) — تراكمي منذ البداية</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sec2_cash_actual_breakdown as $cab): $cab_amt = floatval($cab['net_amt']); ?>
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:6px 12px; font-family:monospace;"><?php echo htmlspecialchars($cab['src']); ?></td>
                            <td style="padding:6px 12px; font-family:monospace; color:#888;"><?php echo intval($cab['cnt']); ?></td>
                            <td style="padding:6px 12px; font-family:monospace; font-weight:bold; color:<?php echo $cab_amt >= 0 ? '#1a8f5f' : '#e74a3b'; ?>;"><?php echo number_format($cab_amt, 2); ?> ل.س</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8f9fc; border-top:2px solid #e3e6f0; font-weight:bold;">
                            <td colspan="2" style="padding:7px 12px;">الإجمالي = رصيد الصندوق الآن</td>
                            <td style="padding:7px 12px; font-family:monospace;"><?php echo number_format($sec2_cash_actual, 2); ?> ل.س</td>
                        </tr>
                    </tfoot>
                </table>
                <p style="font-size:11px; color:#999; margin-top:6px;"><i class="fas fa-info-circle"></i> موجب = صافي نقد دخل الصندوق من هذه الفئة تراكمياً (مثل تحصيل المبيعات). سالب = صافي نقد خرج (مصاريف، رواتب، شراء بضاعة، دفعات موردين، سحوبات مالك...). هذا الجدول <b>لا يتأثر بفلتر الفترة أعلاه إطلاقاً</b> — تراكمي من أول قيد في النظام وحتى اليوم، تماماً كبطاقة "كم يجب أن يبقى في الصندوق" نفسها.</p>
            </div>
        </div>
        <?php endif; ?>

        <div style="margin-top: 20px; padding: 15px 18px; background: #eafaf1; border: 2px solid #1a8f5f; border-radius: 8px;">
            <div style="font-size: 13.5px; font-weight: bold; color: #1a5c3f; margin-bottom: 4px;"><i class="fas fa-coins"></i> الربح الفعلي (نقدي بحت) — حسب تعريفك بالضبط</div>
            <div style="font-size: 11px; color: #1a5c3f; margin-bottom: 12px; line-height: 1.6;">الربح = الإيرادات (نقد دخل الصندوق فعلياً من فواتير بِيعت وسُلِّمت فقط) − كل ما صُرِف فعلياً من الصندوق ضمن نفس الفترة. لا COGS منفصلاً، لا تقييم مخزون، لا استحقاق محاسبي — تدفق نقدي صافٍ فقط، تماماً كما طلبت.</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
                <div style="background: white; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px;">
                    <div style="color: #2c4e9c; font-size: 12.5px; font-weight: bold;" title="نقد دخل فعلياً لحساب الصندوق مصدره 'Sales' حصراً — تحصيل فاتورة بيع، فوراً أو لاحقاً">الإيرادات (نقد دخل الصندوق فعلياً)</div>
                    <div style="font-size: 20px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_cash_revenue_pure, 2); ?> ل.س</div>
                </div>
                <div style="background: white; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px;">
                    <div style="color: #a33636; font-size: 12.5px; font-weight: bold;" title="كل نقد خرج فعلياً من الصندوق ضمن الفترة — شراء بضاعة، دفعات موردين، مصاريف، رواتب، عمولات مدفوعة، شحن. الاستثناء الوحيد: سحوبات المالك وسداد قروضه">كل ما صُرِف فعلياً</div>
                    <div style="font-size: 20px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_cash_spent_total, 2); ?> ل.س</div>
                    <div style="font-size: 10px; color: #888; margin-top: 3px;">يستثني فقط سحوبات المالك وسداد القرض</div>
                </div>
                <div style="background: <?php echo $sec2_pure_cash_profit >= 0 ? '#1cc88a' : '#e74a3b'; ?>; border-right: 4px solid <?php echo $sec2_pure_cash_profit >= 0 ? '#0e7a4c' : '#a33636'; ?>; padding: 15px; border-radius: 6px;">
                    <div style="color: white; font-size: 12.5px; font-weight: bold;">= الربح الفعلي</div>
                    <div style="font-size: 24px; font-weight: bold; color: white; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_pure_cash_profit, 2); ?> ل.س</div>
                    <div style="font-size: 10px; color: rgba(255,255,255,0.85); margin-top: 3px;">= الإيرادات النقدية − كل ما صُرِف</div>
                </div>
            </div>
        </div>

        <div style="margin-top: 20px; padding: 15px 18px; background: #f8f9fc; border: 1px dashed #c9cfe0; border-radius: 8px;">
            <div style="font-size: 13px; font-weight: bold; color: #3a3b45; margin-bottom: 12px;"><i class="fas fa-handshake"></i> ربح يوزَّع على الشركاء (سلسلة حساب مستقلة، ضمن الفترة المحدَّدة أعلاه)</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
                <div style="background: white; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px;">
                    <div style="color: #a33636; font-size: 12.5px; font-weight: bold;" title="رواتب، عمولات مدفوعة فعلياً، شحن، مصاريف تشغيلية — يستثني عمداً سحوبات المالك وسداد قروضه، وكذلك أي شراء بضاعة/دفعة مورد (تُمثَّلها COGS في البطاقة التالية، فلا تُحتسَب مرتين)">كل شيء خرج من الصندوق (تشغيلي، بلا شراء/موردين)</div>
                    <div style="font-size: 19px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_cash_out_operational, 2); ?> ل.س</div>
                    <div style="font-size: 10px; color: #888; margin-top: 3px;">يستثني سحوبات المالك، سداد القرض، وكل شراء/دفعة مورد (تمثَّلها COGS أدناه بدلاً من ازدواج حسابها)</div>
                </div>
                <div style="background: white; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px;">
                    <div style="color: #2c4e9c; font-size: 12.5px; font-weight: bold;" title="الإيرادات − كل شيء خرج من الصندوق (تشغيلي)">المتبقي</div>
                    <div style="font-size: 19px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_remaining_after_cashout, 2); ?> ل.س</div>
                    <div style="font-size: 10px; color: #888; margin-top: 3px;">= الإيرادات − التشغيلي الخارج من الصندوق</div>
                </div>
                <div style="background: white; border-right: 4px solid #a33636; padding: 15px; border-radius: 6px;">
                    <div style="color: #a33636; font-size: 12.5px; font-weight: bold;">ثمن بضائع مباعة (COGS)</div>
                    <div style="font-size: 19px; font-weight: bold; color: #a33636; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_cogs_syp, 2); ?> ل.س</div>
                    <div style="font-size: 10px; color: #888; margin-top: 3px;">يُجنَّب من "المتبقي" لتغطية تكلفة ما بِيع</div>
                </div>
                <div style="background: <?php echo $sec2_distributable_profit >= 0 ? '#e8f8f2' : '#fdecea'; ?>; border-right: 4px solid <?php echo $sec2_distributable_profit >= 0 ? '#1cc88a' : '#e74a3b'; ?>; padding: 15px; border-radius: 6px;">
                    <div style="color: #555; font-size: 12.5px; font-weight: bold;" title="= المتبقي − ثمن بضائع مباعة (COGS)">ربح يوزَّع على الشركاء</div>
                    <div style="font-size: 22px; font-weight: bold; color: <?php echo $sec2_distributable_profit >= 0 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($sec2_distributable_profit, 2); ?> ل.س</div>
                    <div style="font-size: 10px; color: #888; margin-top: 3px;">= المتبقي − ثمن البضائع المباعة</div>
                </div>
            </div>
            <p style="font-size: 11px; color: #999; margin: 12px 0 0;">
                <i class="fas fa-info-circle"></i> هذا مؤشر عملي مبنيّ على طلبك تحديداً، وليس بنداً محاسبياً رسمياً (لن تجده في التقارير المالية). "الخارج من الصندوق" هنا يستثني عمداً كل شراء بضاعة ودفعة مورد (سواء فورية أو سداد دَين قديم/رصيد افتتاحي) — لأن COGS يمثّل تكلفة البضاعة المباعة فعلياً بدقة أكبر من قيمة الشراء أو السداد الخام (الذي قد يشمل مخزوناً لم يُبَع بعد أو ديوناً من فترات سابقة)، فتفادينا بذلك احتسابها مرتين.
            </p>

            <?php if (count($sec2_cashout_breakdown) > 0): ?>
            <div style="margin-top:12px;">
                <button type="button" onclick="var t=document.getElementById('sec2CashoutDetail'); t.style.display = t.style.display==='none' ? 'block' : 'none';" style="background:#eef1f9; color:#4e73df; border:none; padding:7px 14px; border-radius:5px; cursor:pointer; font-size:12.5px; font-weight:bold;">
                    <i class="fas fa-list"></i> تفصيل "كل شيء خرج من الصندوق" حسب المصدر (<?php echo count($sec2_cashout_breakdown); ?> فئة)
                </button>
                <div id="sec2CashoutDetail" style="display:none; margin-top:10px; overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:12.5px; text-align:right;">
                        <thead>
                            <tr style="background:#f8f9fc; border-bottom:2px solid #e3e6f0; color:#555;">
                                <th style="padding:7px 12px;">المصدر (source_module)</th>
                                <th style="padding:7px 12px;">عدد القيود</th>
                                <th style="padding:7px 12px;">المجموع (SYP)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sec2_cashout_breakdown as $cob): ?>
                            <tr style="border-bottom:1px solid #f1f1f1;">
                                <td style="padding:6px 12px; font-family:monospace;"><?php echo htmlspecialchars($cob['src']); ?></td>
                                <td style="padding:6px 12px; font-family:monospace; color:#888;"><?php echo intval($cob['cnt']); ?></td>
                                <td style="padding:6px 12px; font-family:monospace; font-weight:bold; color:#e74a3b;"><?php echo number_format($cob['amt'], 2); ?> ل.س</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8f9fc; border-top:2px solid #e3e6f0; font-weight:bold;">
                                <td colspan="2" style="padding:7px 12px;">الإجمالي</td>
                                <td style="padding:7px 12px; font-family:monospace;"><?php echo number_format($sec2_cash_out_operational, 2); ?> ل.س</td>
                            </tr>
                        </tfoot>
                    </table>
                    <p style="font-size:11px; color:#999; margin-top:6px;"><i class="fas fa-info-circle"></i> كل صف هنا هو مصدر القيد الفعلي (<code>source_module</code>) كما سجَّلته الوحدة التي رحَّلته — إذا رأيت فئة غير متوقَّعة هنا (كإرجاع نقدي لعميل مثلاً)، هذا بالضبط ما يفسِّر أي فرق عن توقّعك.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div style="margin-top: 20px; padding: 15px 18px; background: #f8f9fc; border: 1px dashed #c9cfe0; border-radius: 8px;">
            <div style="font-size: 13px; font-weight: bold; color: #3a3b45; margin-bottom: 12px;"><i class="fas fa-route"></i> تسوية الربح مقابل الصندوق — "أين ذهبت الأرباح؟" (منذ بداية النظام، رصيد لحظي دائماً — لا يتأثر بفلتر الفترة أعلاه)</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                <div style="background: white; border-right: 4px solid #1cc88a; padding: 12px 15px; border-radius: 6px;">
                    <div style="color: #1a8f5f; font-size: 12px; font-weight: bold;">صافي الربح المطلق</div>
                    <div style="font-size: 17px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 4px;"><?php echo number_format($sec2_absolute_net_profit, 2); ?> ل.س</div>
                </div>
                <div style="background: white; border-right: 4px solid #8b5cf6; padding: 12px 15px; border-radius: 6px;">
                    <div style="color: #5b3aa8; font-size: 12px; font-weight: bold;" title="سحوبات نهائية فقط، كل التاريخ (بلا فلتر فترة)">− سحوبات المالك (كل التاريخ)</div>
                    <div style="font-size: 17px; font-weight: bold; color: #8b5cf6; font-family: monospace; margin-top: 4px;"><?php echo number_format($recon_owner_withdrawals_alltime, 2); ?> ل.س</div>
                </div>
                <div style="background: white; border-right: 4px solid #e74a3b; padding: 12px 15px; border-radius: 6px;">
                    <div style="color: #a33636; font-size: 12px; font-weight: bold;" title="رصيد لحظي حالي">− دين المالك المستحق (الآن)</div>
                    <div style="font-size: 17px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 4px;"><?php echo number_format($sec2_owner_loan_outstanding, 2); ?> ل.س</div>
                </div>
                <div style="background: white; border-right: 4px solid #f6c23e; padding: 12px 15px; border-radius: 6px;">
                    <div style="color: #96751c; font-size: 12px; font-weight: bold;" title="بضاعة موجودة بالمخزون الآن ولم تُبَع بعد — مالها لم يُفقَد، بل تحوَّل من نقد لمخزون">− قيمة المخزون الحالي (بالتكلفة)</div>
                    <div style="font-size: 17px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 4px;">$<?php echo number_format($recon_inventory_usd, 2); ?></div>
                    <div style="font-size: 11px; color: #96751c; font-family: monospace; margin-top: 2px;"><?php echo number_format($recon_inventory_syp, 2); ?> ل.س</div>
                </div>
                <div style="background: white; border-right: 4px solid #6f42c1; padding: 12px 15px; border-radius: 6px;">
                    <div style="color: #4a2d8c; font-size: 12px; font-weight: bold;" title="بضاعة بِيعت (خرجت من المخزون) لكن لم تُسلَّم بعد — ثمنها لم يُحصَّل نقداً بعد فعلياً">− رأس مال مبيعات قيد التسليم</div>
                    <div style="font-size: 17px; font-weight: bold; color: #6f42c1; font-family: monospace; margin-top: 4px;">$<?php echo number_format($recon_pending_capital_usd, 2); ?></div>
                    <div style="font-size: 11px; color: #4a2d8c; font-family: monospace; margin-top: 2px;"><?php echo number_format($recon_pending_capital_syp, 2); ?> ل.س</div>
                </div>
                <div style="background: <?php echo abs($recon_gap_vs_ledger) < 100 ? '#e8f8f2' : '#fdecea'; ?>; border-right: 4px solid <?php echo abs($recon_gap_vs_ledger) < 100 ? '#1cc88a' : '#e74a3b'; ?>; padding: 12px 15px; border-radius: 6px;">
                    <div style="color: #555; font-size: 12px; font-weight: bold;" title="صافي الربح المطلق − سحوبات المالك − دين المالك − قيمة المخزون − رأس مال قيد التسليم">= الصندوق المتوقَّع من التسوية</div>
                    <div style="font-size: 18px; font-weight: bold; color: <?php echo abs($recon_gap_vs_ledger) < 100 ? '#1cc88a' : '#e74a3b'; ?>; font-family: monospace; margin-top: 4px;"><?php echo number_format($recon_expected_cash, 2); ?> ل.س</div>
                    <div style="font-size: 10px; color: #888; margin-top: 3px;">قارنه برصيد الصندوق الفعلي أعلاه (<?php echo number_format($sec2_cash_actual, 2); ?> ل.س)</div>
                </div>
            </div>

            <?php
                // جدول توزيع نسبي: أين يقيم كل جزء من "صافي الربح المطلق" حالياً — يوضّح بشكل لا لبس فيه
                // أن المخزون ورأس المال قيد التسليم "أموال مجمَّدة" (لم تُفقَد، ولا تُخصَم من الربح نفسه
                // في أي مكان بلوحة التحكم) لا "خسارة"، تماماً كما طلب المستخدم توضيحه.
                $recon_base = $sec2_absolute_net_profit > 0.01 ? $sec2_absolute_net_profit : 1;
                $recon_rows = [
                    ['label' => 'نقد فعلي في الصندوق الآن', 'value' => $sec2_cash_actual, 'color' => '#1cc88a', 'note' => 'متاح فوراً'],
                    ['label' => 'مجمَّد في المخزون الحالي (بضاعة لم تُبَع بعد)', 'value' => $recon_inventory_syp, 'color' => '#f6c23e', 'note' => 'سيعود نقداً عند البيع'],
                    ['label' => 'مجمَّد في مبيعات قيد التسليم (بِيعت، لم تُحصَّل بعد)', 'value' => $recon_pending_capital_syp, 'color' => '#6f42c1', 'note' => 'سيعود نقداً عند التسليم/التحصيل'],
                    ['label' => 'سحوبات المالك (كل التاريخ)', 'value' => $recon_owner_withdrawals_alltime, 'color' => '#8b5cf6', 'note' => 'خرج نهائياً، لن يعود'],
                    ['label' => 'دين المالك المستحق (الآن)', 'value' => $sec2_owner_loan_outstanding, 'color' => '#e74a3b', 'note' => 'سيعود عند السداد'],
                ];
            ?>
            <div style="margin-top: 15px; background: white; border-radius: 6px; padding: 12px 15px; border: 1px solid #eee;">
                <div style="font-size: 12px; font-weight: bold; color: #555; margin-bottom: 8px;">توزيع الربح المطلق (<?php echo number_format($sec2_absolute_net_profit, 2); ?> ل.س) — أين يقيم كل جزء منه الآن</div>
                <?php foreach ($recon_rows as $rr): $pct = ($rr['value'] / $recon_base) * 100; ?>
                <div style="margin-bottom: 8px;">
                    <div style="display:flex; justify-content:space-between; font-size:11.5px; color:#555; margin-bottom:3px;">
                        <span><?php echo $rr['label']; ?> <span style="color:#aaa; font-size:10px;">(<?php echo $rr['note']; ?>)</span></span>
                        <span style="font-family:monospace; font-weight:bold;"><?php echo number_format($rr['value'], 2); ?> ل.س — <?php echo number_format($pct, 1); ?>٪</span>
                    </div>
                    <div style="background:#f1f1f1; border-radius:4px; height:8px; overflow:hidden;">
                        <div style="background:<?php echo $rr['color']; ?>; width:<?php echo max(0, min(100, $pct)); ?>%; height:100%;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <p style="font-size: 10.5px; color: #999; margin: 8px 0 0;">
                    <i class="fas fa-info-circle"></i> المخزون ورأس مال المبيعات قيد التسليم <b>أموال مجمَّدة لا خسارة</b> — لا تُخصَم من "صافي الأرباح" ولا "صافي الربح المطلق" في أي بطاقة بلوحة التحكم، وستتحوَّل لنقد فعلي تلقائياً فور بيع المخزون أو تسليم/تحصيل المبيعات المعلَّقة.
                </p>
            </div>
            <p style="font-size: 11px; color: #999; margin: 12px 0 0; line-height: 1.7;">
                <i class="fas fa-info-circle"></i> <b>كيف تقرأ هذا القسم:</b> الفرق بين "الصندوق المتوقَّع من التسوية" هنا ورصيد "كم يجب أن يبقى في الصندوق الآن" أعلاه
                (<?php $g = $recon_gap_vs_ledger; ?>
                <?php if (abs($g) < 100): ?>
                    متطابقان تقريباً (فرق <?php echo number_format(abs($g), 2); ?> ل.س فقط) — النظام المحاسبي متّسق داخلياً بالكامل.
                <?php else: ?>
                    <b>غير متطابقَين (فرق <?php echo number_format(abs($g), 2); ?> ل.س)</b> — يعني وجود قيد محاسبي ناقص أو مكرَّر في مكان ما (تحقق من القيود اليدوية أو التعديلات المباشرة على قاعدة البيانات).
                <?php endif; ?>)
                هو أول شيء تتحقق منه. <b>أما الفرق بين هذا الرقم والنقد الفعلي المعدود يدوياً في الدرج، فلا يستطيع أي حساب برمجي تفسيره</b> — إما مصروف حقيقي لم يُسجَّل في النظام إطلاقاً، أو خطأ عد، أو نقص فعلي. الطريقة الوحيدة لتحديد متى نشأ الفرق بالضبط: افتح <a href="daily_closing.php" style="color:#4e73df;">الإقفال اليومي</a> وراجعه يوماً بيوم بدءاً من 29/08/2026 (يوم بدء العمل)، وقارن الرصيد الختامي المتوقَّع لكل يوم بما كان موجوداً فعلياً في الدرج في نهايته — اليوم الذي يظهر فيه أول فرق هو مكان المشكلة بالضبط.
            </p>
        </div>
        <p style="font-size: 11.5px; color: #999; margin: 12px 0 0;">
            <i class="fas fa-info-circle"></i> ملاحظة: "دفعات الموردين" هنا حركة نقدية فعلية (سداد التزام سابق)، وليست مصروفاً محاسبياً بالمعنى الدقيق (لا تُحتسَب في "صافي الربح" الرسمي بالقوائم المالية) — أُدرِجت هنا بناءً على طلبك لتحليل التدفق النقدي الفعلي فقط. "سحوبات المالك" أيضاً ليست مصروف تشغيل (توزيع أرباح)، ولذلك لا تُخصَم من "صافي الأرباح" نفسه — فقط من بطاقة "صافي الربح بعد سحوبات الملّاك" المستقلة.
        </p>

        <?php if (count($sec2_sp_breakdown) > 0): ?>
        <div style="margin-top:15px;">
            <button type="button" onclick="var t=document.getElementById('sec2SpDetail'); t.style.display = t.style.display==='none' ? 'block' : 'none';" style="background:#eef1f9; color:#4e73df; border:none; padding:7px 14px; border-radius:5px; cursor:pointer; font-size:12.5px; font-weight:bold;">
                <i class="fas fa-list"></i> عرض تفصيل كل دفعة مورد على حدة (<?php echo count($sec2_sp_breakdown); ?> دفعة) — المبلغ كما رُحِّل فعلياً بدفتر اليومية
            </button>
            <div id="sec2SpDetail" style="display:none; margin-top:10px; overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12.5px; text-align:right;">
                    <thead>
                        <tr style="background:#f8f9fc; border-bottom:2px solid #e3e6f0; color:#555;">
                            <th style="padding:7px 12px;">التاريخ</th>
                            <th style="padding:7px 12px;">المورد</th>
                            <th style="padding:7px 12px;">المبلغ (USD)</th>
                            <th style="padding:7px 12px;">السعر الفعلي وقت الدفع</th>
                            <th style="padding:7px 12px;">الناتج (SYP) — كما رُحِّل فعلياً</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sec2_sp_breakdown as $spb): ?>
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:6px 12px; font-family:monospace;"><?php echo htmlspecialchars($spb['date']); ?></td>
                            <td style="padding:6px 12px;"><?php echo htmlspecialchars($spb['supplier']); ?><?php if (!empty($spb['notes'])): ?><div style="font-size:10.5px; color:#999;"><?php echo htmlspecialchars($spb['notes']); ?></div><?php endif; ?></td>
                            <td style="padding:6px 12px; font-family:monospace; color:#e74a3b;">$<?php echo number_format($spb['usd'], 2); ?></td>
                            <td style="padding:6px 12px; font-family:monospace; color:#6f42c1;">
                                <?php echo number_format($spb['rate'], 2); ?>
                                <?php if ($spb['source'] === 'estimated'): ?><span title="لا يوجد قيد مطابق — تقدير ديناميكي من أرشيف أسعار الصرف الحالي" style="color:#f6c23e; font-size:10.5px;"> (تقديري⚠)</span><?php endif; ?>
                            </td>
                            <td style="padding:6px 12px; font-family:monospace; font-weight:bold; color:#333;"><?php echo number_format($spb['syp'], 2); ?> ل.س</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8f9fc; border-top:2px solid #e3e6f0; font-weight:bold;">
                            <td colspan="4" style="padding:7px 12px;">الإجمالي</td>
                            <td style="padding:7px 12px; font-family:monospace;"><?php echo number_format($sec2_supplier_payments, 2); ?> ل.س</td>
                        </tr>
                    </tfoot>
                </table>
                <p style="font-size:11px; color:#999; margin-top:6px;"><i class="fas fa-info-circle"></i> "الناتج (SYP)" هو المبلغ الفعلي كما رُحِّل بالضبط لحظة تسجيل كل دفعة (نفس مصدر <a href="daily_closing.php" style="color:#4e73df;">الإقفال اليومي</a>) — لا يتغيّر أبداً حتى لو عُدِّل سعر الصرف التاريخي لاحقاً في <a href="currencies.php" style="color:#4e73df;">صفحة العملات</a>. الصفوف المُعلَّمة "تقديري⚠" فقط هي التي لا يوجد لها قيد فعلي مطابق (بيانات قديمة جداً)، وتُقدَّر ديناميكياً من الأرشيف الحالي.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div id="section-sec3" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
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
                <div style="font-size: 12px; color: #666; font-family: monospace; margin-right: 10px;" title="تكلفة رأس المال (COGS)، لا سعر البيع">القيمة (تكلفة): <?php echo number_format($sec3_sold_value_syp, 2); ?> ل.س (≈ $<?php echo number_format($sec3_sold_value_usd, 2); ?>)</div>
                <div style="font-size: 16px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 8px;">قيد الانتظار: <?php echo rtrim(rtrim(number_format($sec3_pending_qty, 2), '0'), '.'); ?></div>
                <div style="font-size: 12px; color: #666; font-family: monospace; margin-right: 10px;" title="تكلفة رأس المال (COGS)، لا سعر البيع">القيمة (تكلفة): <?php echo number_format($sec3_pending_value_syp, 2); ?> ل.س (≈ $<?php echo number_format($sec3_pending_value_usd, 2); ?>)</div>
            </div>

        </div>
    </div>

    <div id="section-overview" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <i class="fas fa-th-large"></i> نظرة عامة شاملة على النظام
        </h3>

        <!-- فلتر مستقل بتاريخ من-إلى خاص بهذا القسم فقط (ويشترك معه قسما "مؤشرات مالية إضافية" و"صافي
        حركة الموردين" أدناه) — أرصدة "الآن" (المخزون/الموردين/المندوبين) لا تتأثر به، فهي رصيد لحظي دائماً -->
        <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 15px 0; background: #f8f9fc; border: 1px solid #edf0f7; border-radius: 8px; padding: 10px 15px;">
            <?php foreach ($_GET as $k => $v) { if (strpos($k, 'ov_') !== 0) echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">'; } ?>
            <label style="font-size: 13px; font-weight: bold; color: #555;">من:</label>
            <input type="date" name="ov_start" value="<?php echo htmlspecialchars($ov_start); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <label style="font-size: 13px; font-weight: bold; color: #555;">إلى:</label>
            <input type="date" name="ov_end" value="<?php echo htmlspecialchars($ov_end); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 7px 16px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold;">تطبيق</button>
            <span style="font-size: 12.5px; color: #888; margin-right: auto;">يشمل: نظرة عامة شاملة + مؤشرات مالية إضافية + صافي حركة الموردين</span>
        </form>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 15px; margin-top: 15px;">

            <div style="background: #eaf1fc; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px; height:100%;">
                <a href="products.php" style="text-decoration:none;">
                    <div style="color: #2c4e9c; font-size: 13px; font-weight: bold;"><i class="fas fa-boxes"></i> المنتجات والمخزون <span style="font-weight:normal; font-size:10.5px;">(الآن)</span></div>
                    <div style="font-size: 21px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo intval($ov['products_count']); ?> صنف <span style="font-size:11px; font-weight:normal; color:#2c4e9c;">(<?php echo intval($ov['categories_count']); ?> تصنيف)</span></div>
                </a>
                <table style="width:100%; margin-top:8px; font-size:11.5px; color:#2c4e9c; border-collapse:collapse;">
                    <tr style="border-top:1px solid rgba(78,115,223,0.15);">
                        <td style="padding:3px 0; font-weight:bold;">عامة</td>
                        <td style="padding:3px 0; text-align:left; font-family:monospace;"><?php echo rtrim(rtrim(number_format($ov['products_stock_qty'], 2), '0'), '.'); ?> قطعة — $<?php echo number_format($ov['products_stock_value_usd'], 2); ?></td>
                    </tr>
                    <tr style="border-top:1px solid rgba(78,115,223,0.15);">
                        <td style="padding:3px 0;"><i class="fas fa-truck" style="font-size:10px;"></i> الموردين</td>
                        <td style="padding:3px 0; text-align:left; font-family:monospace;"><?php echo rtrim(rtrim(number_format($ov['products_supplier_qty'], 2), '0'), '.'); ?> قطعة — $<?php echo number_format($ov['products_supplier_value_usd'], 2); ?></td>
                    </tr>
                    <tr style="border-top:1px solid rgba(78,115,223,0.15);">
                        <td style="padding:3px 0;"><i class="fas fa-warehouse" style="font-size:10px;"></i> الجرد المكتبي</td>
                        <td style="padding:3px 0; text-align:left; font-family:monospace;"><?php echo rtrim(rtrim(number_format($ov['products_office_qty'], 2), '0'), '.'); ?> قطعة — $<?php echo number_format($ov['products_office_value_usd'], 2); ?></td>
                    </tr>
                </table>
                <?php if (count($ov_office_items) > 0): ?>
                <div style="margin-top:8px;">
                    <button type="button" onclick="var t=document.getElementById('ovOfficeDetail'); t.style.display = t.style.display==='none' ? 'block' : 'none';" style="background:#dde6fa; color:#2c4e9c; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:10.5px; font-weight:bold; width:100%;">
                        <i class="fas fa-list"></i> تفصيل احترافي لكل صنف مكتبي (<?php echo count($ov_office_items); ?>)
                    </button>
                    <div id="ovOfficeDetail" style="display:none; margin-top:8px; max-height:220px; overflow-y:auto; background:#fff; border-radius:5px; padding:6px;">
                        <table style="width:100%; font-size:10.5px; border-collapse:collapse;">
                            <thead>
                                <tr style="color:#888; border-bottom:1px solid #eee;">
                                    <th style="text-align:right; padding:3px 4px; font-weight:bold;">الصنف</th>
                                    <th style="text-align:left; padding:3px 4px; font-weight:bold;">الكمية</th>
                                    <th style="text-align:left; padding:3px 4px; font-weight:bold;">التكلفة</th>
                                    <th style="text-align:left; padding:3px 4px; font-weight:bold;">القيمة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ov_office_items as $oi):
                                    $oi_qty = floatval($oi['current_quantity']);
                                    $oi_cost = floatval($oi['cost_price_usd']);
                                    $oi_val = $oi_qty * $oi_cost;
                                ?>
                                <tr style="border-top:1px solid #f5f5f5;">
                                    <td style="padding:3px 4px; color:#333;"><?php echo htmlspecialchars($oi['product_name']); ?><div style="color:#aaa; font-size:9.5px;"><?php echo htmlspecialchars($oi['sku']); ?></div></td>
                                    <td style="padding:3px 4px; text-align:left; font-family:monospace;"><?php echo rtrim(rtrim(number_format($oi_qty, 2), '0'), '.'); ?></td>
                                    <td style="padding:3px 4px; text-align:left; font-family:monospace;">$<?php echo number_format($oi_cost, 2); ?></td>
                                    <td style="padding:3px 4px; text-align:left; font-family:monospace; font-weight:bold;">$<?php echo number_format($oi_val, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="border-top:2px solid #ddd; font-weight:bold;">
                                    <td colspan="3" style="padding:4px;">الإجمالي</td>
                                    <td style="padding:4px; text-align:left; font-family:monospace;">$<?php echo number_format($ov['products_office_value_usd'], 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <a href="sales.php" style="text-decoration:none;">
                <div style="background: #eafaf1; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 6px; height:100%;">
                    <div style="color: #1a8f5f; font-size: 13px; font-weight: bold;"><i class="fas fa-file-invoice-dollar"></i> المبيعات وفواتير العملاء <span style="font-weight:normal; font-size:10.5px;">(الفترة)</span></div>
                    <div style="font-size: 21px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;"><?php echo intval($ov['sales_count']); ?> فاتورة <span style="font-size:11px; font-weight:normal; color:#1a8f5f;">(<?php echo intval($ov['sales_customers_count']); ?> عميل)</span></div>
                    <table style="width:100%; margin-top:8px; font-size:11.5px; color:#1a8f5f; border-collapse:collapse;">
                        <tr style="border-top:1px solid rgba(28,200,138,0.2);">
                            <td style="padding:3px 0; font-weight:bold;">عامة</td>
                            <td style="padding:3px 0; text-align:left; font-family:monospace;"><?php echo number_format($ov['sales_total_syp'], 2); ?> ل.س</td>
                        </tr>
                        <tr style="border-top:1px solid rgba(28,200,138,0.2);">
                            <td style="padding:3px 0;"><i class="fas fa-clock" style="font-size:10px;"></i> قيد الانتظار (<?php echo intval($ov['sales_pending_count']); ?>)</td>
                            <td style="padding:3px 0; text-align:left; font-family:monospace;"><?php echo number_format($ov['sales_pending_value_syp'], 2); ?> ل.س</td>
                        </tr>
                        <tr style="border-top:1px solid rgba(28,200,138,0.2);">
                            <td style="padding:3px 0;"><i class="fas fa-check-circle" style="font-size:10px;"></i> مُسلَّمة (<?php echo intval($ov['sales_delivered_count']); ?>)</td>
                            <td style="padding:3px 0; text-align:left; font-family:monospace;"><?php echo number_format($ov['sales_delivered_value_syp'], 2); ?> ل.س</td>
                        </tr>
                    </table>
                </div>
            </a>

            <a href="Purchases.php" style="text-decoration:none;">
                <div style="background: #fdf6ec; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px; height:100%;">
                    <div style="color: #96751c; font-size: 13px; font-weight: bold;"><i class="fas fa-truck-loading"></i> فواتير الشراء من الموردين <span style="font-weight:normal; font-size:10.5px;">(الفترة)</span></div>
                    <div style="font-size: 21px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;"><?php echo intval($ov['purchases_count']); ?> فاتورة</div>
                    <div style="font-size: 12px; color: #96751c; font-family: monospace; margin-top: 3px;">$<?php echo number_format($ov['purchases_total_usd'], 2); ?></div>
                </div>
            </a>

            <a href="suppliers.php" style="text-decoration:none;">
                <div style="background: #fdecea; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px; height:100%;">
                    <div style="color: #a33636; font-size: 13px; font-weight: bold;"><i class="fas fa-truck"></i> الموردون <span style="font-weight:normal; font-size:10.5px;">(الآن)</span></div>
                    <div style="font-size: 21px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;"><?php echo intval($ov['suppliers_count']); ?> مورد</div>
                    <div style="font-size: 12px; color: #a33636; font-family: monospace; margin-top: 3px;">صافي المستحق لهم: $<?php echo number_format($ov['suppliers_net_payable_usd'], 2); ?></div>
                </div>
            </a>

            <a href="representatives.php" style="text-decoration:none;">
                <div style="background: #f3eefe; border-right: 4px solid #8b5cf6; padding: 15px; border-radius: 6px; height:100%;">
                    <div style="color: #5b3aa8; font-size: 13px; font-weight: bold;"><i class="fas fa-user-tie"></i> المندوبون والعمولات <span style="font-weight:normal; font-size:10.5px;">(الآن)</span></div>
                    <div style="font-size: 21px; font-weight: bold; color: #8b5cf6; font-family: monospace; margin-top: 5px;"><?php echo intval($ov['representatives_count']); ?> مندوب</div>
                    <div style="font-size: 12px; color: #5b3aa8; font-family: monospace; margin-top: 3px;">صافي الذمة المتبقية: <?php echo number_format($ov['representatives_net_balance_syp'], 2); ?> ل.س</div>
                </div>
            </a>

            <a href="expenses.php" style="text-decoration:none;">
                <div style="background: #eef1f9; border-right: 4px solid #6c757d; padding: 15px; border-radius: 6px; height:100%;">
                    <div style="color: #495057; font-size: 13px; font-weight: bold;"><i class="fas fa-receipt"></i> المصاريف التشغيلية <span style="font-weight:normal; font-size:10.5px;">(الفترة)</span></div>
                    <div style="font-size: 21px; font-weight: bold; color: #6c757d; font-family: monospace; margin-top: 5px;"><?php echo number_format($ov['expenses_total_syp'], 2); ?> ل.س</div>
                    <div style="font-size: 11px; color: #888; margin-top: 3px;">ضمن الفترة المختارة أعلاه</div>
                </div>
            </a>

        </div>
        <p style="font-size: 11px; color: #999; margin: 12px 0 0;"><i class="fas fa-info-circle"></i> كل بطاقة تفتح صفحة الوحدة المرتبطة بها مباشرةً للاطلاع على التفاصيل الكاملة. البطاقات المُعلَّمة بـ"الآن" أرصدة لحظية لا تتأثر بالفلتر الأسبوعي أعلاه؛ المُعلَّمة بـ"الفترة" تتبع الأسبوع المختار.</p>
    </div>

    <!-- مؤشرات مالية إضافية ضمن نفس فترة "من-إلى" المختارة أعلاه في قسم النظرة الشاملة -->
    <div id="section-financial-extra" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <i class="fas fa-coins"></i> مؤشرات مالية إضافية <span style="font-size:11.5px; color:#999; font-weight:normal;">(<?php echo htmlspecialchars($ov_from) . ' إلى ' . htmlspecialchars($ov_to); ?>)</span>
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 15px; margin-top: 15px;">
            <div style="background: #fff8e6; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px;">
                <div style="color: #96751c; font-size: 13px; font-weight: bold;" title="تكلفة الأصناف المبيعة لكنها لم تُسلَّم بعد ضمن الفترة">رأس مال المنتجات قيد الانتظار</div>
                <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;">$<?php echo number_format($ov2['pending_capital_usd'], 2); ?></div>
                <div style="font-size: 13px; color: #96751c; font-family: monospace; margin-top: 2px;"><?php echo number_format($ov2['pending_capital_syp'], 2); ?> ل.س</div>
                <?php if (count($ov2_pending_items) > 0): ?>
                <div style="margin-top:8px;">
                    <button type="button" onclick="var t=document.getElementById('ov2PendingDetail'); t.style.display = t.style.display==='none' ? 'block' : 'none';" style="background:#fdf0c9; color:#96751c; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:10.5px; font-weight:bold; width:100%;">
                        <i class="fas fa-list"></i> تفصيل احترافي لكل صنف قيد الانتظار (<?php echo count($ov2_pending_items); ?>)
                    </button>
                    <div id="ov2PendingDetail" style="display:none; margin-top:8px; max-height:220px; overflow-y:auto; background:#fff; border-radius:5px; padding:6px;">
                        <table style="width:100%; font-size:10.5px; border-collapse:collapse;">
                            <thead>
                                <tr style="color:#888; border-bottom:1px solid #eee;">
                                    <th style="text-align:right; padding:3px 4px; font-weight:bold;">الفاتورة</th>
                                    <th style="text-align:right; padding:3px 4px; font-weight:bold;">الصنف</th>
                                    <th style="text-align:left; padding:3px 4px; font-weight:bold;">الكمية</th>
                                    <th style="text-align:left; padding:3px 4px; font-weight:bold;">القيمة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ov2_pending_items as $pi):
                                    $pi_qty = floatval($pi['remaining_qty']);
                                    $pi_cost = floatval($pi['unit_cost_usd']);
                                    $pi_val = $pi_qty * $pi_cost;
                                    if ($pi_qty <= 0) { continue; }
                                ?>
                                <tr style="border-top:1px solid #f5f5f5;">
                                    <td style="padding:3px 4px; color:#666; font-family:monospace;"><?php echo htmlspecialchars($pi['invoice_number']); ?><div style="color:#aaa; font-size:9.5px;"><?php echo htmlspecialchars($pi['invoice_date']); ?></div></td>
                                    <td style="padding:3px 4px; color:#333;"><?php echo htmlspecialchars($pi['product_name']); ?></td>
                                    <td style="padding:3px 4px; text-align:left; font-family:monospace;"><?php echo rtrim(rtrim(number_format($pi_qty, 2), '0'), '.'); ?></td>
                                    <td style="padding:3px 4px; text-align:left; font-family:monospace; font-weight:bold;">$<?php echo number_format($pi_val, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="border-top:2px solid #ddd; font-weight:bold;">
                                    <td colspan="3" style="padding:4px;">الإجمالي</td>
                                    <td style="padding:4px; text-align:left; font-family:monospace;">$<?php echo number_format($ov2['pending_capital_usd'], 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div style="background: #fdecea; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px;">
                <div style="color: #a33636; font-size: 13px; font-weight: bold;" title="مصروف حقيقي مُرحَّل فعلياً في اليومية ضمن الفترة">تكلفة البضائع المباعة (COGS)</div>
                <div style="font-size: 20px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;">$<?php echo number_format($ov2['cogs_usd'], 2); ?></div>
                <div style="font-size: 13px; color: #a33636; font-family: monospace; margin-top: 2px;"><?php echo number_format($ov2['cogs_syp'], 2); ?> ل.س</div>
            </div>
            <div style="background: #eafaf1; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 6px;">
                <div style="color: #1a8f5f; font-size: 13px; font-weight: bold;">إجمالي الإيرادات</div>
                <div style="font-size: 20px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;">$<?php echo number_format($ov2['revenue_usd'], 2); ?></div>
                <div style="font-size: 13px; color: #1a8f5f; font-family: monospace; margin-top: 2px;"><?php echo number_format($ov2['revenue_syp'], 2); ?> ل.س</div>
            </div>
            <div style="background: #eaf1fc; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px;">
                <div style="color: #2c4e9c; font-size: 13px; font-weight: bold;" title="ذمة لحظية حالية، بلا فلتر فترة">إجمالي حساب الموردين</div>
                <div style="font-size: 20px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;">$<?php echo number_format($ov['suppliers_net_payable_usd'], 2); ?></div>
                <div style="font-size: 11px; color: #888; margin-top: 3px;">الآن — بلا فلتر</div>
            </div>
        </div>
    </div>

    <!-- صافي حركة الفترة لكل مورد على حدة — فلتر واحد موحَّد لكل الأعمدة (يومي/أسبوعي سبت-خميس) -->
    <div id="section-supplier-movement" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <h3 style="margin: 0; color: #3a3b45; font-size: 16px;">
                <i class="fas fa-truck"></i> صافي حركة الفترة لكل مورد <span style="font-size:11.5px; color:#999; font-weight:normal;">(<?php echo htmlspecialchars($smv_start) . ' إلى ' . htmlspecialchars($smv_end); ?>)</span>
            </h3>
            <input type="text" id="ovSupplierSearch" onkeyup="filterOvSuppliers()" placeholder="بحث عن مورد..." style="padding:7px 12px; border:1px solid #ccc; border-radius:5px; font-size:13px; min-width:200px;">
        </div>

        <!-- فلتر موحَّد للجدول كاملاً: يومي / أسبوعي سبت-خميس -->
        <div style="display:flex; align-items:center; gap:10px; margin-top:12px; flex-wrap:wrap;">
            <span style="font-size:12.5px; color:#777; font-weight:bold;">فلتر الجدول:</span>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['smv_filter_type' => 'daily'])); ?>" style="text-decoration: none;">
                <span style="padding: 5px 14px; border-radius: 5px; font-size: 12.5px; font-weight: bold; background: <?php echo $smv_filter_type === 'daily' ? '#e74a3b' : '#f1f3f9'; ?>; color: <?php echo $smv_filter_type === 'daily' ? '#fff' : '#e74a3b'; ?>;">يومي</span>
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['smv_filter_type' => 'weekly'])); ?>" style="text-decoration: none;">
                <span style="padding: 5px 14px; border-radius: 5px; font-size: 12.5px; font-weight: bold; background: <?php echo $smv_filter_type === 'weekly' ? '#e74a3b' : '#f1f3f9'; ?>; color: <?php echo $smv_filter_type === 'weekly' ? '#fff' : '#e74a3b'; ?>;">أسبوعي (سبت-خميس)</span>
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['smv_filter_type' => 'monthly'])); ?>" style="text-decoration: none;">
                <span style="padding: 5px 14px; border-radius: 5px; font-size: 12.5px; font-weight: bold; background: <?php echo $smv_filter_type === 'monthly' ? '#e74a3b' : '#f1f3f9'; ?>; color: <?php echo $smv_filter_type === 'monthly' ? '#fff' : '#e74a3b'; ?>;">شهري</span>
            </a>
            <span style="font-size:11.5px; color:#999;">(<?php echo htmlspecialchars($smv_start) . ' إلى ' . htmlspecialchars($smv_end); ?>)</span>
        </div>

        <?php if (count($ov_supplier_rows) > 0): ?>
        <div style="overflow-x:auto; margin-top:12px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
                <thead>
                    <tr style="background: #f8f9fc; border-bottom: 2px solid #e3e6f0; color: #555;">
                        <th style="padding: 8px 15px;">المورد</th>
                        <th style="padding: 8px 15px; color:#e74a3b;">المشتريات (الفترة)</th>
                        <th style="padding: 8px 15px; color:#6f42c1;" title="نسبة مشتريات هذا المورد من إجمالي مشتريات كل الموردين ضمن نفس الفترة">نسبة الشراء</th>
                        <th style="padding: 8px 15px; color:#1cc88a;">المدفوعات (الفترة)</th>
                        <th style="padding: 8px 15px; color:#f6c23e;">المردودات/الخصم (الفترة)</th>
                        <th style="padding: 8px 15px; color:#2e59d9;">صافي حركة الفترة</th>
                        <th style="padding: 8px 15px; color:#a33636;" title="تكلفة البضائع المباعة المُسلَّمة فعلياً ضمن نفس فلتر الجدول — منسوبة لمورّد الدفعة الفعلي">COGS مُسلَّمة (<?php echo $smv_filter_type === 'daily' ? 'اليوم' : ($smv_filter_type === 'monthly' ? 'الشهر' : 'الأسبوع'); ?>)</th>
                        <th style="padding: 8px 15px; text-align:center;">إجراء</th>
                    </tr>
                </thead>
                <tbody id="ovSupplierTbody">
                    <?php foreach ($ov_supplier_rows as $osr): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;" data-name="<?php echo htmlspecialchars(mb_strtolower($osr['name'])); ?>">
                            <td style="padding: 8px 15px; font-weight: 600;"><?php echo htmlspecialchars($osr['name']); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; color:#e74a3b;">$<?php echo number_format($osr['purchases'], 2); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; color:#6f42c1;"><?php echo number_format($osr['purchase_share_pct'], 1); ?>٪</td>
                            <td style="padding: 8px 15px; font-family: monospace; color:#1cc88a;">$<?php echo number_format($osr['payments'], 2); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; color:#f6c23e;">$<?php echo number_format($osr['returns'], 2); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color:#2e59d9;">$<?php echo number_format($osr['net'], 2); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color:#a33636;">$<?php echo number_format($osr['cogs_delivered'], 2); ?></td>
                            <td style="padding: 8px 15px; text-align:center;">
                                <a href="supplier_view.php?id=<?php echo $osr['id']; ?>" style="background:#4e73df; color:white; padding:4px 12px; border-radius:4px; text-decoration:none; font-size:12px; font-weight:bold;">التفاصيل</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="font-size: 11px; color: #999; margin: 10px 0 0;"><i class="fas fa-info-circle"></i> كل أعمدة هذا الجدول (المشتريات، نسبة الشراء، المدفوعات، المردودات، صافي الحركة، وCOGS) مرتبطة الآن بفلتر واحد موحَّد أعلاه (يومي/أسبوعي سبت-خميس).</p>
        <p id="ovSupplierNoResults" style="display:none; padding: 20px; text-align: center; color: #777;">لا يوجد مورد مطابق للبحث.</p>
        <script>
            function filterOvSuppliers() {
                var q = document.getElementById('ovSupplierSearch').value.trim().toLowerCase();
                var rows = document.querySelectorAll('#ovSupplierTbody tr');
                var visibleCount = 0;
                rows.forEach(function (row) {
                    var match = !q || row.getAttribute('data-name').indexOf(q) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) visibleCount++;
                });
                document.getElementById('ovSupplierNoResults').style.display = visibleCount === 0 ? 'block' : 'none';
            }
        </script>
        <?php else: ?>
            <p style="padding: 20px; text-align: center; color: #777; margin-top:10px;">لا توجد حركة مسجَّلة لأي مورد ضمن الفترة المختارة.</p>
        <?php endif; ?>
        <p style="font-size: 11px; color: #999; margin: 12px 0 0;"><i class="fas fa-info-circle"></i> يُعرض هنا فقط الموردون الذين لديهم حركة فعلية (شراء/دفع/مردود) ضمن الفترة المختارة أعلاه. للرصيد التراكمي المستحق فعلياً لكل مورد، راجع <a href="suppliers.php" style="color:#4e73df; font-weight:bold;">صفحة الموردين</a>.</p>
    </div>

    <!-- جدول المندوبين — بناءً على طلب صريح من المستخدم: فلتر مستقل تماماً عن قسم الموردين -->
    <div id="section-rep-movement" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <h3 style="margin: 0; color: #3a3b45; font-size: 16px;">
                <i class="fas fa-user-tie"></i> المندوبون <span style="font-size:11.5px; color:#999; font-weight:normal;">(<?php echo htmlspecialchars($rmv_start) . ' إلى ' . htmlspecialchars($rmv_end); ?>)</span>
            </h3>
            <input type="text" id="ovRepSearch" onkeyup="filterOvReps()" placeholder="بحث عن مندوب..." style="padding:7px 12px; border:1px solid #ccc; border-radius:5px; font-size:13px; min-width:200px;">
        </div>

        <!-- فلتر مستقل خاص بهذا القسم وحده — بلا أي صلة بفلتر جدول الموردين -->
        <div style="display:flex; align-items:center; gap:10px; margin-top:12px; flex-wrap:wrap;">
            <span style="font-size:12.5px; color:#777; font-weight:bold;">فلتر المندوبين:</span>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['rmv_filter_type' => 'daily'])); ?>#section-rep-movement" style="text-decoration: none;">
                <span style="padding: 5px 14px; border-radius: 5px; font-size: 12.5px; font-weight: bold; background: <?php echo $rmv_filter_type === 'daily' ? '#e74a3b' : '#f1f3f9'; ?>; color: <?php echo $rmv_filter_type === 'daily' ? '#fff' : '#e74a3b'; ?>;">يومي</span>
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['rmv_filter_type' => 'weekly'])); ?>#section-rep-movement" style="text-decoration: none;">
                <span style="padding: 5px 14px; border-radius: 5px; font-size: 12.5px; font-weight: bold; background: <?php echo $rmv_filter_type === 'weekly' ? '#e74a3b' : '#f1f3f9'; ?>; color: <?php echo $rmv_filter_type === 'weekly' ? '#fff' : '#e74a3b'; ?>;">أسبوعي (سبت-خميس)</span>
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['rmv_filter_type' => 'monthly'])); ?>#section-rep-movement" style="text-decoration: none;">
                <span style="padding: 5px 14px; border-radius: 5px; font-size: 12.5px; font-weight: bold; background: <?php echo $rmv_filter_type === 'monthly' ? '#e74a3b' : '#f1f3f9'; ?>; color: <?php echo $rmv_filter_type === 'monthly' ? '#fff' : '#e74a3b'; ?>;">شهري</span>
            </a>
            <span style="font-size:11.5px; color:#999;">(<?php echo htmlspecialchars($rmv_start) . ' إلى ' . htmlspecialchars($rmv_end); ?>)</span>
        </div>

        <?php if (count($ov_rep_rows) > 0): ?>
        <div style="overflow-x:auto; margin-top:12px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
                <thead>
                    <tr style="background: #f8f9fc; border-bottom: 2px solid #e3e6f0; color: #555;">
                        <th style="padding: 8px 15px;">المندوب</th>
                        <th style="padding: 8px 15px; color:#f6c23e;" title="تراكمي كامل منذ البداية، لا مرتبط بفلتر الفترة">صافي الرصيد المستحق</th>
                        <th style="padding: 8px 15px; color:#6f42c1;" title="حصته من إجمالي مبيعات النظام (كل المندوبين + بلا مندوب) خلال الفترة، صافياً بعد المرتجع من الطرفين">نسبته من إجمالي مبيعاتك</th>
                        <th style="padding: 8px 15px; color:#4e73df;">عدد القطع المباعة (صافٍ)</th>
                        <th style="padding: 8px 15px; color:#e74a3b;">عدد الفواتير المرتجعة</th>
                        <th style="padding: 8px 15px; text-align:center;">إجراء</th>
                    </tr>
                </thead>
                <tbody id="ovRepTbody">
                    <?php foreach ($ov_rep_rows as $rr): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;" data-name="<?php echo htmlspecialchars(mb_strtolower($rr['name'])); ?>">
                            <td style="padding: 8px 15px; font-weight: 600;"><?php echo htmlspecialchars($rr['name']); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color:#f6c23e;"><?php echo number_format($rr['net_balance'], 2); ?> ل.س</td>
                            <td style="padding: 8px 15px; font-family: monospace; color:#6f42c1;"><?php echo number_format($rr['share_pct'], 1); ?>٪</td>
                            <td style="padding: 8px 15px; font-family: monospace; color:#4e73df;"><?php echo rtrim(rtrim(number_format($rr['net_qty'], 2), '0'), '.'); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; color:<?php echo $rr['returned_invoices_count'] > 0 ? '#e74a3b' : '#888'; ?>;"><?php echo intval($rr['returned_invoices_count']); ?></td>
                            <td style="padding: 8px 15px; text-align:center;">
                                <a href="representative_profile.php?id=<?php echo intval($rr['id']); ?>" style="background:#4e73df; color:white; padding:4px 12px; border-radius:4px; text-decoration:none; font-size:12px; font-weight:bold;">التفاصيل</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f8f9fc; border-top: 2px solid #e3e6f0; font-weight: bold;">
                        <td style="padding: 8px 15px;">الإجمالي</td>
                        <td style="padding: 8px 15px; font-family: monospace; color:#f6c23e;"><?php echo number_format($ov_rep_totals['net_balance'], 2); ?> ل.س</td>
                        <td style="padding: 8px 15px; font-family: monospace; color:#6f42c1;"><?php echo number_format($ov_rep_totals['share_pct'], 1); ?>٪</td>
                        <td style="padding: 8px 15px; font-family: monospace; color:#4e73df;"><?php echo rtrim(rtrim(number_format($ov_rep_totals['net_qty'], 2), '0'), '.'); ?></td>
                        <td style="padding: 8px 15px; font-family: monospace; color:#e74a3b;"><?php echo intval($ov_rep_totals['returned_invoices_count']); ?></td>
                        <td style="padding: 8px 15px;"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p id="ovRepNoResults" style="display:none; padding: 20px; text-align: center; color: #777;">لا يوجد مندوب مطابق للبحث.</p>
        <script>
            function filterOvReps() {
                var q = document.getElementById('ovRepSearch').value.trim().toLowerCase();
                var rows = document.querySelectorAll('#ovRepTbody tr');
                var visibleCount = 0;
                rows.forEach(function (row) {
                    var match = !q || row.getAttribute('data-name').indexOf(q) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) visibleCount++;
                });
                document.getElementById('ovRepNoResults').style.display = visibleCount === 0 ? 'block' : 'none';
            }
        </script>
        <?php else: ?>
            <p style="padding: 20px; text-align: center; color: #777; margin-top:10px;">لا يوجد مندوب له رصيد مستحق أو حركة مبيعات ضمن الفترة المختارة.</p>
        <?php endif; ?>
    </div>

    <?php if (count($supplier_priority_list) > 0): ?>
    <div id="section-priority" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
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

    <div id="section-quick-actions" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">إجراءات سريعة</h3>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
            <a href="currencies.php" style="background: #4e73df; color: white; padding: 10px 15px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;">إدارة العملات وأسعار الصرف</a>
            <a href="journal.php" style="background: #1cc88a; color: white; padding: 10px 15px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;">دفتر اليومية الشامل</a>
            <a href="financial_statements.php" style="background: #6f42c1; color: white; padding: 10px 15px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;">القوائم المالية الرسمية</a>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>