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
$sec2_owner_withdrawals = 0; $sec2_net_after_withdrawals = 0; $sec2_owner_loan_outstanding = 0; $sec2_cash_available = 0;
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
    $sec2_net = $sec2_revenue - ($sec2_expenses + $sec2_payroll + $sec2_commissions + $sec2_shipping + $sec2_supplier_payments);

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

// ============================================================
// قسم مستقل خامس: "نظرة عامة شاملة على النظام" — يجمع كل وحدات البرنامج (المنتجات/المخزون، المبيعات،
// المشتريات، الموردون، المندوبون، المصاريف) في مكان واحد — إجابة مباشرة على "أريد كل معلومات وإحصائيات
// البرنامج من لوحة التحكم". الآن بفلتر أسبوعي مستقل (سبت -> خميس) خاص به فقط، منفصل تماماً عن الفلتر
// الأول وفلتري sec2/sec3 أعلاه — يُطبَّق على مؤشرات "الحركة" (مبيعات/مشتريات/مصاريف ضمن الفترة)، بينما
// تبقى أرصدة "اللحظة الحالية" (المخزون الآن، صافي مستحق الموردين، صافي ذمة المندوبين) دائماً حية بلا فلتر
// لأنها أرصدة تراكمية وليست حركة فترة.
// ============================================================
$ov_has_filter_param = isset($_GET['ov_ref']) || isset($_GET['ov_all']);
$ov_view_all = $ov_has_filter_param ? (isset($_GET['ov_all']) && $_GET['ov_all'] == '1') : true;
$ov_ref_date = $_GET['ov_ref'] ?? date('Y-m-d');
try {
    $ov_ref_dt = new DateTime($ov_ref_date);
} catch (Exception $e) {
    $ov_ref_dt = new DateTime();
}
$ov_dow = (int)$ov_ref_dt->format('N'); // 1=اثنين ... 7=أحد
$ov_days_since_saturday = ($ov_dow - 6 + 7) % 7; // السبت = 6
$ov_week_start_dt = (clone $ov_ref_dt)->modify("-{$ov_days_since_saturday} days");
$ov_week_end_dt = (clone $ov_week_start_dt)->modify("+5 days");
$ov_week_start = $ov_week_start_dt->format('Y-m-d');
$ov_week_end = $ov_week_end_dt->format('Y-m-d');
$ov_prev_week_ref = (clone $ov_week_start_dt)->modify('-1 day')->format('Y-m-d');
$ov_next_week_ref = (clone $ov_week_end_dt)->modify('+1 day')->format('Y-m-d');
$ov_from = $ov_view_all ? '2000-01-01' : $ov_week_start;
$ov_to   = $ov_view_all ? '2100-12-31' : $ov_week_end;

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
$ov_supplier_rows = [];
try {
    $stmt_ov_sup = $conn->prepare("
        SELECT s.id, s.supplier_name,
            COALESCE((SELECT SUM(pi.total_amount_usd) FROM purchase_invoices pi WHERE pi.supplier_id = s.id AND pi.payment_status != 'Paid' AND pi.invoice_date BETWEEN ? AND ?), 0) AS period_purchases,
            COALESCE((SELECT SUM(sp.amount_usd) FROM supplier_payments sp WHERE sp.supplier_id = s.id AND sp.payment_date BETWEEN ? AND ?), 0) AS period_payments,
            COALESCE((SELECT SUM(pr.total_amount_usd) FROM purchase_returns pr INNER JOIN purchase_invoices pi2 ON pr.purchase_invoice_id = pi2.id WHERE pi2.supplier_id = s.id AND pi2.payment_status != 'Paid' AND pr.return_date BETWEEN ? AND ?), 0) AS period_returns,
            COALESCE((SELECT SUM(sd.amount_usd) FROM supplier_discounts sd WHERE sd.supplier_id = s.id AND sd.discount_date BETWEEN ? AND ?), 0) AS period_discounts
        FROM suppliers s
    ");
    $stmt_ov_sup->execute([$ov_from, $ov_to, $ov_from, $ov_to, $ov_from, $ov_to, $ov_from, $ov_to]);
    foreach ($stmt_ov_sup->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $p_purch = floatval($row['period_purchases']);
        $p_pay = floatval($row['period_payments']);
        $p_ret = floatval($row['period_returns']) + floatval($row['period_discounts']);
        $p_net = $p_purch - $p_pay - $p_ret;
        // نعرض فقط الموردين الذين لديهم حركة فعلية ضمن الفترة (تفادياً لتعداد طويل بلا فائدة)
        if (abs($p_purch) > 0.009 || abs($p_pay) > 0.009 || abs($p_ret) > 0.009) {
            $ov_supplier_rows[] = ['id' => intval($row['id']), 'name' => $row['supplier_name'], 'purchases' => $p_purch, 'payments' => $p_pay, 'returns' => $p_ret, 'net' => $p_net];
        }
    }
    usort($ov_supplier_rows, function ($a, $b) { return abs($b['net']) <=> abs($a['net']); });
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
                <div style="font-size: 12px; color: #666; font-family: monospace; margin-right: 10px;">القيمة: <?php echo number_format($sec3_sold_value_syp, 2); ?> ل.س (≈ $<?php echo number_format($sec3_sold_value_usd, 2); ?>)</div>
                <div style="font-size: 16px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 8px;">قيد الانتظار: <?php echo rtrim(rtrim(number_format($sec3_pending_qty, 2), '0'), '.'); ?></div>
                <div style="font-size: 12px; color: #666; font-family: monospace; margin-right: 10px;">القيمة: <?php echo number_format($sec3_pending_value_syp, 2); ?> ل.س (≈ $<?php echo number_format($sec3_pending_value_usd, 2); ?>)</div>
            </div>

        </div>
    </div>

    <div id="section-overview" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <i class="fas fa-th-large"></i> نظرة عامة شاملة على النظام
        </h3>

        <!-- شريط تنقّل أسبوعي مستقل (سبت -> خميس) خاص بهذا القسم فقط — أرصدة "الآن" (المخزون/الموردين/المندوبين) لا تتأثر به -->
        <div class="no-print" style="background: #f8f9fc; border: 1px solid #edf0f7; border-radius: 8px; padding: 10px 15px; margin: 15px 0; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['ov_ref' => $ov_prev_week_ref, 'ov_all' => null])); ?>" style="text-decoration:none; background:#eef1f9; color:#4e73df; padding:6px 12px; border-radius:5px; font-weight:bold; font-size:12.5px;">
                <i class="fas fa-chevron-right"></i> الأسبوع السابق
            </a>
            <div style="font-weight:bold; color:#333; font-size:13px;">
                <?php if ($ov_view_all): ?>
                    كل الأوقات
                <?php else: ?>
                    من <span style="font-family:monospace; color:#2e59d9;"><?php echo htmlspecialchars($ov_week_start); ?></span> (سبت)
                    إلى <span style="font-family:monospace; color:#2e59d9;"><?php echo htmlspecialchars($ov_week_end); ?></span> (خميس)
                <?php endif; ?>
            </div>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['ov_ref' => $ov_next_week_ref, 'ov_all' => null])); ?>" style="text-decoration:none; background:#eef1f9; color:#4e73df; padding:6px 12px; border-radius:5px; font-weight:bold; font-size:12.5px;">
                الأسبوع التالي <i class="fas fa-chevron-left"></i>
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['ov_ref' => date('Y-m-d'), 'ov_all' => null])); ?>" style="text-decoration:none; background:#eafaf1; color:#1a8f5f; padding:6px 12px; border-radius:5px; font-weight:bold; font-size:12.5px;">الأسبوع الحالي</a>
            <form method="GET" style="display:flex; align-items:center; gap:6px;">
                <?php foreach ($_GET as $k => $v) { if ($k !== 'ov_ref' && $k !== 'ov_all') echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">'; } ?>
                <input type="date" name="ov_ref" value="<?php echo htmlspecialchars($ov_ref_date); ?>" style="padding:6px; border:1px solid #ccc; border-radius:4px; font-family:monospace; font-size:12.5px;">
                <button type="submit" style="background:#4e73df; color:white; border:none; padding:6px 14px; border-radius:5px; cursor:pointer; font-size:12.5px; font-weight:bold;">اذهب</button>
            </form>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['ov_all' => $ov_view_all ? null : '1'])); ?>" style="text-decoration:none; margin-right:auto; background:<?php echo $ov_view_all ? '#4e73df' : '#f1f3f9'; ?>; color:<?php echo $ov_view_all ? '#fff' : '#4e73df'; ?>; padding:6px 14px; border-radius:5px; font-weight:bold; font-size:12.5px;">
                <?php echo $ov_view_all ? 'العودة للعرض الأسبوعي' : 'عرض كل الأوقات'; ?>
            </a>
        </div>

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

    <!-- مؤشرات مالية إضافية ضمن نفس الفترة الأسبوعية المختارة أعلاه -->
    <div id="section-financial-extra" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <i class="fas fa-coins"></i> مؤشرات مالية إضافية <span style="font-size:11.5px; color:#999; font-weight:normal;">(<?php echo $ov_view_all ? 'كل الأوقات' : $ov_week_start . ' إلى ' . $ov_week_end; ?>)</span>
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

    <!-- صافي حركة الفترة لكل مورد على حدة، ضمن نفس الفترة المختارة أعلاه لقسم "نظرة عامة شاملة على النظام" -->
    <div id="section-supplier-movement" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08); margin-bottom: 25px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <h3 style="margin: 0; color: #3a3b45; font-size: 16px;">
                <i class="fas fa-truck"></i> صافي حركة الفترة لكل مورد <span style="font-size:11.5px; color:#999; font-weight:normal;">(<?php echo $ov_view_all ? 'كل الأوقات' : $ov_week_start . ' إلى ' . $ov_week_end; ?>)</span>
            </h3>
            <input type="text" id="ovSupplierSearch" onkeyup="filterOvSuppliers()" placeholder="بحث عن مورد..." style="padding:7px 12px; border:1px solid #ccc; border-radius:5px; font-size:13px; min-width:200px;">
        </div>
        <?php if (count($ov_supplier_rows) > 0): ?>
        <div style="overflow-x:auto; margin-top:12px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
                <thead>
                    <tr style="background: #f8f9fc; border-bottom: 2px solid #e3e6f0; color: #555;">
                        <th style="padding: 8px 15px;">المورد</th>
                        <th style="padding: 8px 15px; color:#e74a3b;">المشتريات (الفترة)</th>
                        <th style="padding: 8px 15px; color:#1cc88a;">المدفوعات (الفترة)</th>
                        <th style="padding: 8px 15px; color:#f6c23e;">المردودات/الخصم (الفترة)</th>
                        <th style="padding: 8px 15px; color:#2e59d9;">صافي حركة الفترة</th>
                        <th style="padding: 8px 15px; text-align:center;">إجراء</th>
                    </tr>
                </thead>
                <tbody id="ovSupplierTbody">
                    <?php foreach ($ov_supplier_rows as $osr): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;" data-name="<?php echo htmlspecialchars(mb_strtolower($osr['name'])); ?>">
                            <td style="padding: 8px 15px; font-weight: 600;"><?php echo htmlspecialchars($osr['name']); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; color:#e74a3b;">$<?php echo number_format($osr['purchases'], 2); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; color:#1cc88a;">$<?php echo number_format($osr['payments'], 2); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; color:#f6c23e;">$<?php echo number_format($osr['returns'], 2); ?></td>
                            <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color:#2e59d9;">$<?php echo number_format($osr['net'], 2); ?></td>
                            <td style="padding: 8px 15px; text-align:center;">
                                <a href="supplier_view.php?id=<?php echo $osr['id']; ?>" style="background:#4e73df; color:white; padding:4px 12px; border-radius:4px; text-decoration:none; font-size:12px; font-weight:bold;">التفاصيل</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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