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
$total_shipping = 0;
$total_payroll = 0;
$total_fx_loss = 0;
$total_fx_gain = 0;
$total_fx_unrealized = 0;
$fx_unrealized_breakdown = [];
$total_supplier_discounts = 0;
$net_profit = 0;
$unmatched_count = 0;
$unmatched_cogs_syp = 0;
$unmatched_commissions_syp = 0;
$unmatched_total_syp = 0;
$total_supplier_payables_usd = 0;
$total_supplier_payables_syp = 0;
$expenses_breakdown = [];
$error_msg = '';

// إنشاء جدول خصومات أصناف المبيعات دفاعياً هنا أيضاً (وليس فقط في sales.php) — هذه الصفحة قد
// تُفتَح قبل أي زيارة لصفحة المبيعات، والاستعلام أدناه يعتمد على وجود الجدول مسبقاً.
$conn->exec("CREATE TABLE IF NOT EXISTS sale_item_discounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_item_id INT NOT NULL,
    sale_id INT NOT NULL,
    amount_syp DECIMAL(15,2) NOT NULL,
    discount_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// فحص/ترحيل دفاعي لعمود حالة الدفع في فواتير الشراء (مطابق لنفس الفحص في Purchases.php وsupplier_view.php)
// ضروري هنا لأن استعلام ذمم الموردين أدناه يشترط هذا العمود مباشرة في جملة WHERE.
try {
    $pi_cols_chk = $conn->query("SHOW COLUMNS FROM purchase_invoices")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_status', $pi_cols_chk)) {
        $conn->exec("ALTER TABLE purchase_invoices ADD COLUMN payment_status ENUM('Paid','Unpaid') NOT NULL DEFAULT 'Unpaid'");
    }
} catch (Exception $e) { /* يُتجاهل إن تعذّر (مثلاً الجدول نفسه غير موجود بعد) */ }

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
    // ضمان وجود عمود تاريخ التسليم الفعلي (نفس العمود المُستخدَم في sales.php)
    $sales_cols_chk_frx = $conn->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('delivered_at', $sales_cols_chk_frx)) {
        $conn->exec("ALTER TABLE sales ADD COLUMN delivered_at DATE NULL");
    }

    // أ) إجمالي الإيرادات: فقط الفواتير المسلَّمة فعلياً (نفس قاعدة الترحيل الذكي المعتمدة في كامل النظام)
    // تصحيح: خصم قيمة أي مرتجع حدث على هذه الفواتير — وإلا يبقى الإيراد المعروض هنا أعلى من الإيراد
    // الحقيقي المُسجَّل في القيود (والذي يُخفَّض فعلياً بقيد عكسي عند كل مرتجع في sales.php)
    // === تحديث: الإيراد يُقرَأ الآن مباشرة من دفتر اليومية (حساب "إيرادات المبيعات")، بتاريخ ترحيل
    // القيد الفعلي (entry_date) — نفس مصدر القوائم المالية الرسمية بالضبط. هذا ضروري الآن لأن توقيت
    // الاعتراف بالإيراد أصبح يعتمد على أيهما يكتمل لاحقاً (التسليم أو التحصيل النقدي)، فلا يمكن استنتاجه
    // بموثوقية من invoice_date أو delivered_at وحدهما في جدول sales — القيد نفسه هو المصدر الوحيد الدقيق.
    $stmt_rev = $conn->prepare("
        SELECT COALESCE(SUM(je.credit) - SUM(je.debit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'إيرادات المبيعات' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_rev->execute([$start_date, $end_date]);
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
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
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
            WHERE s2.delivery_status = 'Delivered' AND COALESCE(s2.delivered_at, s2.invoice_date) BETWEEN ? AND ?
        ), 0) AS net_commissions
        FROM sales s
        WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
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

    // ج-2) تكلفة الشحن: بند مستقل ضمن "العمولات والمصاريف" — تُقرَأ الآن من دفتر اليومية (حساب "تكاليف
    // الشحن"، مصروف حقيقي يُرحَّل تلقائياً في sales.php فور إدخال شحن لأي فاتورة) بدل عمود
    // shipping_cost_syp الخام، لتطابق منهجية باقي بنود هذا التقرير تماماً.
    $stmt_ship = $conn->prepare("
        SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
        FROM journal_entries je JOIN accounts a ON je.account_id = a.id
        WHERE a.account_name = 'تكاليف الشحن' AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt_ship->execute([$start_date, $end_date]);
    $total_shipping = floatval($stmt_ship->fetchColumn());

    // هـ-2) رواتب وأجور + حوافز ومكافآت + خصومات مكتسبة من الموردين — تُضاف الآن حتى تصبح بطاقة
    // "صافي الربح الحقيقي" مطابقة تماماً لصافي الربح في القوائم المالية الرسمية (financial_statements.php).
    // تُقرَأ من دفتر اليومية مباشرة (وليس جدول منفصل) لأن الرواتب تُرحَّل بمسيرات شهرية بلا تاريخ يومي
    // واضح للفلترة، والقيد المحاسبي (entry_date) هو المصدر الوحيد الموثوق لتاريخ الأثر الفعلي — وهو
    // نفس المصدر الذي تعتمده القوائم الرسمية أصلاً، فتُضمَن المطابقة الحرفية.
    $total_payroll = 0;
    try {
        $stmt_payroll = $conn->prepare("
            SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
            FROM journal_entries je JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name IN ('الرواتب والأجور', 'مصروف حوافز ومكافآت الموظفين')
              AND je.entry_date BETWEEN ? AND ?
        ");
        $stmt_payroll->execute([$start_date, $end_date]);
        $total_payroll = floatval($stmt_payroll->fetchColumn());
    } catch (Exception $e) { /* يُتجاهل إن تعذّر (حسابات لم تُنشأ بعد) */ }

    // و-2) فروقات صرف العملة المُحقَّقة فعلياً (من سداد دفعات الموردين) — خسارة حقيقية حين يصعد الدولار
    // بين تاريخ نشوء الدَين وتاريخ سداده، أو ربح حين ينخفض. تُقرَأ من الحسابين المخصَّصين لهما في
    // supplier_view.php عند كل دفعة (انظر التصحيح هناك)، ضمن الفترة المحدَّدة لهذا التقرير.
    $total_fx_loss = 0;
    $total_fx_gain = 0;
    try {
        $stmt_fx_loss = $conn->prepare("
            SELECT COALESCE(SUM(je.debit) - SUM(je.credit), 0)
            FROM journal_entries je JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'خسارة فروقات العملة' AND je.entry_date BETWEEN ? AND ?
        ");
        $stmt_fx_loss->execute([$start_date, $end_date]);
        $total_fx_loss = floatval($stmt_fx_loss->fetchColumn());

        $stmt_fx_gain = $conn->prepare("
            SELECT COALESCE(SUM(je.credit) - SUM(je.debit), 0)
            FROM journal_entries je JOIN accounts a ON je.account_id = a.id
            WHERE a.account_name = 'أرباح فروقات العملة' AND je.entry_date BETWEEN ? AND ?
        ");
        $stmt_fx_gain->execute([$start_date, $end_date]);
        $total_fx_gain = floatval($stmt_fx_gain->fetchColumn());
    } catch (Exception $e) { /* يُتجاهل إن تعذّر (لا توجد دفعات موردين بعد بالتصحيح الجديد) */ }

    // و-3) فروقات صرف غير محقَّقة (IAS 21) — إعادة تقييم كل الذمم المفتوحة للموردين (غير المسدَّدة بعد)
    // بسعر صرف نهاية هذه الفترة تحديداً، مقارنةً بمتوسط السعر المرجَّح الذي نشأ عنده كل دَين. هذا يعكس
    // الأثر الحقيقي لتقلّب الدولار على الالتزامات القائمة، حتى قبل سدادها فعلياً — بنفس منهجية فروقات
    // الصرف المُحقَّقة عند الدفع في supplier_view.php تماماً، لكن مطبَّقة هنا على الرصيد المتبقي غير
    // المدفوع، بسعر إغلاق الفترة بدل سعر يوم الدفع الفعلي.
    $total_fx_unrealized = 0;
    $fx_unrealized_breakdown = [];
    try {
        $period_end_rate = getExchangeRateForDate($conn, 'USD', $end_date);
        $stmt_fx_open = $conn->prepare("
            SELECT s.id, s.supplier_name,
                COALESCE(SUM(pi.total_amount_usd), 0) AS outstanding_usd,
                COALESCE(SUM(pi.total_amount_usd * pi.exchange_rate), 0) AS weighted_syp
            FROM suppliers s
            INNER JOIN purchase_invoices pi ON pi.supplier_id = s.id AND pi.payment_status != 'Paid' AND pi.invoice_date <= ?
            GROUP BY s.id, s.supplier_name
            HAVING outstanding_usd > 0.009
        ");
        $stmt_fx_open->execute([$end_date]);
        foreach ($stmt_fx_open->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out_usd = floatval($row['outstanding_usd']);
            $hist_rate = $out_usd > 0.009 ? (floatval($row['weighted_syp']) / $out_usd) : $period_end_rate;
            $unrealized = $out_usd * ($period_end_rate - $hist_rate); // موجب = خسارة غير محقَّقة (صعد الدولار) | سالب = ربح غير محقَّق
            if (abs($unrealized) > 0.5) {
                $fx_unrealized_breakdown[] = ['name' => $row['supplier_name'], 'usd' => $out_usd, 'hist_rate' => $hist_rate, 'diff' => $unrealized];
            }
            $total_fx_unrealized += $unrealized;
        }
    } catch (Exception $e) { }

    // هـ) صافي الربح الحقيقي — يطابق الآن صافي الربح في القوائم المالية الرسمية تماماً
    // تصحيح: "خصومات مكتسبة من الموردين" لم تعد تُضاف هنا إطلاقاً — بناءً على توضيح صريح: هذا المبلغ
    // لا يدخل الصندوق فعلياً، بل يقتصر أثره على تخفيض ذمم الموردين فقط (بند ميزانية عمومية بحت، وليس
    // إيراداً). أُعيد تصنيف الحساب نفسه من Revenue إلى Asset في شجرة الحسابات لهذا السبب بالضبط.
    // تصحيح نهائي بعد توضيح صريح: أجور الشحن مصروف حقيقي، تُخصَم الآن من صافي الربح كأي بند آخر.
    // تصحيح إضافي (IAS 21): فروقات الصرف غير المحقَّقة على الذمم المفتوحة تُخصَم/تُضاف الآن أيضاً —
    // المعيار الدولي يطلب الاعتراف الفوري بأثر إعادة التقييم على البنود النقدية كل فترة، لا تأجيله.
    $net_profit = $total_revenue - ($total_cogs_syp + $total_commissions + $total_expenses + $total_shipping + $total_payroll + $total_fx_loss + max(0, $total_fx_unrealized)) + $total_fx_gain + max(0, -$total_fx_unrealized);

    // ============================================================
    // تشخيص جوهري: عدم تطابق توقيت الاعتراف — COGS/العمولة تُرحَّلان فوراً لحظة التسليم، بينما الإيراد
    // نفسه لا يُعترَف به إلا عند اكتمال التسليم والتحصيل الكامل معاً. فأي فاتورة سُلِّمت ضمن هذه الفترة
    // لكنها لم تُحصَّل بالكامل بعد، تظهر تكلفتها هنا ضمن COGS أعلاه بلا أي إيراد مقابل لها — يُشوِّه صافي
    // الربح المعروض لهذه الفترة تحديداً (يقلّله هنا، ويضخّم فترة التحصيل لاحقاً). نحسب حجم هذا الأثر
    // بدقة لعرضه كتنبيه صريح بدل أن يبقى مخفياً داخل الرقم الإجمالي.
    // ============================================================
    $unmatched_count = 0;
    $unmatched_cogs_syp = 0;
    $unmatched_commissions_syp = 0;
    try {
        $stmt_unmatched = $conn->prepare("
            SELECT
                COUNT(DISTINCT s.id) AS cnt,
                COALESCE(SUM(
                    (si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0))
                    * COALESCE(si.cost_price_usd_at_sale, p.cost_price_usd) * s.exchange_rate
                ), 0) AS cogs_syp
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN products p ON si.product_id = p.id
            WHERE s.delivery_status = 'Delivered' AND s.payment_status != 'Paid'
              AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
        ");
        $stmt_unmatched->execute([$start_date, $end_date]);
        $um = $stmt_unmatched->fetch(PDO::FETCH_ASSOC);
        $unmatched_count = intval($um['cnt']);
        $unmatched_cogs_syp = floatval($um['cogs_syp']);

        $stmt_unmatched_comm = $conn->prepare("
            SELECT COALESCE(SUM(s.total_commissions), 0) - COALESCE((
                SELECT SUM(sr.total_commission_reversed) FROM sales_returns sr WHERE sr.sale_id = s.id
            ), 0)
            FROM sales s
            WHERE s.delivery_status = 'Delivered' AND s.payment_status != 'Paid'
              AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
        ");
        $stmt_unmatched_comm->execute([$start_date, $end_date]);
        $unmatched_commissions_syp = floatval($stmt_unmatched_comm->fetchColumn());
    } catch (Exception $e) { }
    $unmatched_total_syp = $unmatched_cogs_syp + $unmatched_commissions_syp;

    // و) ذمم الموردين والخصوم: لا يوجد رصيد مخزَّن، يُحسب لحظياً بنفس منطق supplier_view.php
    // (إجمالي المشتريات - إجمالي المدفوعات - المردودات/الخصومات)، وهو رصيد إجمالي حالي غير مرتبط
    // بفترة التقرير الزمنية لأنه التزام قائم لحظة عرض التقرير.
    // تصحيح: تمت مواءمة هذا الاستعلام مع منطق supplier_view.php تماماً بعد نقطتين كانتا ناقصتين هنا:
    // 1) المشتريات كانت تُحسب فقط من products.purchased_quantity * cost_price_usd (قيمة حالية متغيرة
    //    مع كل عملية شراء لاحقة)، بدل الاعتماد على purchase_invoice_items (القيمة الفعلية وقت كل فاتورة)
    //    مع احتساب الفارق القديم غير المُغطَّى بفواتير فقط كرصيد تكميلي (نفس أسلوب supplier_view.php).
    // 2) مرتجعات المشتريات الفعلية (جدول purchase_returns) لم تكن تُطرَح إطلاقاً من الذمة.
    // 3) فواتير "نقداً" وأي مرتجع عليها مُستبعدان الآن من هذا الحساب بالكامل — لا يُضافان للذمة
    //    ولا يُطرَحان منها، لأنهما لا يُنشئان أي التزام تجاه المورد أصلاً (استرداد نقدي مباشر).
    $stmt_sup = $conn->query("
        SELECT COALESCE(SUM(
            (SELECT COALESCE(SUM(pii.total_cost_usd), 0)
                FROM purchase_invoice_items pii
                INNER JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
                WHERE pi.supplier_id = s.id AND pi.payment_status != 'Paid')
            + (SELECT COALESCE(SUM(
                    GREATEST(0, p.purchased_quantity - COALESCE((SELECT SUM(pii2.quantity) FROM purchase_invoice_items pii2 WHERE pii2.product_id = p.id), 0))
                    * p.cost_price_usd
                ), 0)
                FROM products p WHERE p.supplier_id = s.id)
            - (SELECT COALESCE(SUM(sp.amount_usd), 0) FROM supplier_payments sp WHERE sp.supplier_id = s.id)
            - COALESCE(s.returns_discounts, 0)
            - (SELECT COALESCE(SUM(pr.total_amount_usd), 0)
                FROM purchase_returns pr
                INNER JOIN purchase_invoices pi2 ON pr.purchase_invoice_id = pi2.id
                WHERE pi2.supplier_id = s.id AND pi2.payment_status != 'Paid')
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
        <span style="color: #6c757d; font-size: 13px; font-weight: bold;"><i class="fas fa-handshake"></i> العمولات والمصاريف والرواتب</span>
        <h3 style="color: #f6c23e; margin: 8px 0 0; font-family: monospace; font-size: 22px;"><?php echo number_format($total_commissions + $total_expenses + $total_shipping + $total_payroll, 2); ?> <span style="font-size: 12px;">ل.س</span></h3>
        <span style="font-size: 11px; color: #888;">(عمولات: <?php echo number_format($total_commissions, 0); ?> | مصاريف: <?php echo number_format($total_expenses, 0); ?> | شحن: <?php echo number_format($total_shipping, 0); ?> | رواتب وحوافز: <?php echo number_format($total_payroll, 0); ?>)</span>

    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid <?php echo ($total_fx_loss - $total_fx_gain) >= 0 ? '#e74a3b' : '#1cc88a'; ?>; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <span style="color: #6c757d; font-size: 13px; font-weight: bold;"><i class="fas fa-exchange-alt"></i> صافي فروقات العملة (مُحقَّق)</span>
        <h3 style="color: <?php echo ($total_fx_loss - $total_fx_gain) >= 0 ? '#e74a3b' : '#1cc88a'; ?>; margin: 8px 0 0; font-family: monospace; font-size: 22px;"><?php echo number_format($total_fx_loss - $total_fx_gain, 2); ?> <span style="font-size: 12px;">ل.س</span></h3>
        <span style="font-size: 11px; color: #888;">(خسارة: <?php echo number_format($total_fx_loss, 0); ?> | ربح: <?php echo number_format($total_fx_gain, 0); ?>) — من سداد دفعات الموردين فقط</span>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid <?php echo $total_fx_unrealized >= 0 ? '#e74a3b' : '#1cc88a'; ?>; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <span style="color: #6c757d; font-size: 13px; font-weight: bold;"><i class="fas fa-balance-scale-right"></i> فروقات صرف غير محقَّقة (IAS 21)</span>
        <h3 style="color: <?php echo $total_fx_unrealized >= 0 ? '#e74a3b' : '#1cc88a'; ?>; margin: 8px 0 0; font-family: monospace; font-size: 22px;"><?php echo number_format($total_fx_unrealized, 2); ?> <span style="font-size: 12px;">ل.س</span></h3>
        <span style="font-size: 11px; color: #888;" title="إعادة تقييم كل ذمم الموردين المفتوحة بسعر نهاية هذه الفترة">إعادة تقييم الذمم المفتوحة بسعر <?php echo number_format($period_end_rate ?? 0, 2); ?> (نهاية الفترة)</span>
        <?php if (count($fx_unrealized_breakdown) > 0): ?>
        <div style="margin-top:8px;">
            <button type="button" onclick="var t=document.getElementById('fxUnrealDetail'); t.style.display = t.style.display==='none' ? 'block' : 'none';" style="background:#eef1f9; color:#4e73df; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:11px;">تفصيل حسب المورد</button>
            <div id="fxUnrealDetail" style="display:none; margin-top:8px; font-size:11.5px;">
                <?php foreach ($fx_unrealized_breakdown as $fub): ?>
                    <div style="display:flex; justify-content:space-between; padding:3px 0; border-top:1px solid #f1f1f1;">
                        <span><?php echo htmlspecialchars($fub['name']); ?> ($<?php echo number_format($fub['usd'], 0); ?> @ <?php echo number_format($fub['hist_rate'], 2); ?>)</span>
                        <span style="font-family:monospace; color:<?php echo $fub['diff'] >= 0 ? '#e74a3b' : '#1cc88a'; ?>; font-weight:bold;"><?php echo number_format($fub['diff'], 2); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #1cc88a; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <span style="color: #6c757d; font-size: 13px; font-weight: bold;"><i class="fas fa-chart-pie"></i> صافي الربح الحقيقي</span>
        <h3 style="color: #1cc88a; margin: 8px 0 0; font-family: monospace; font-size: 22px;"><?php echo number_format($net_profit, 2); ?> <span style="font-size: 12px;">ل.س</span></h3>
    </div>

</div>

<?php if ($unmatched_count > 0): ?>
<!-- تنبيه عدم تطابق التوقيت: فواتير مُسلَّمة ضمن الفترة لكن إيرادها لم يُعترَف به بعد (غير مُحصَّلة بالكامل) -->
<div style="background: #fff8e6; border: 1px solid #f6dfa3; border-radius: 8px; padding: 15px 20px; margin-bottom: 25px;">
    <div style="font-weight: bold; color: #856404; font-size: 14px;"><i class="fas fa-exclamation-triangle"></i> تنبيه: صافي الربح أعلاه لهذه الفترة قد يكون أقل من الحقيقة مؤقتاً</div>
    <div style="font-size: 13px; color: #856404; margin-top: 6px; line-height: 1.7;">
        يوجد <b><?php echo $unmatched_count; ?> فاتورة</b> سُلِّمت ضمن هذه الفترة لكنها <b>لم تُحصَّل بالكامل بعد</b>، فلم يُعترَف بإيرادها إطلاقاً (سيظهر لاحقاً ضمن فترة تحصيلها الفعلي) — بينما تكلفتها وعمولتها <b>خُصمتا بالفعل الآن</b> ضمن الأرقام أعلاه. القيمة "اليتيمة" (تكلفة + عمولة بلا إيراد مقابل حتى الآن): <b><?php echo number_format($unmatched_total_syp, 2); ?> ل.س</b>
        (تكلفة: <?php echo number_format($unmatched_cogs_syp, 2); ?> | عمولة: <?php echo number_format($unmatched_commissions_syp, 2); ?>).
    </div>
    <div style="font-size: 12px; color: #a3730f; margin-top: 6px;">
        بعبارة أخرى: لو أُضيف هذا المبلغ مؤقتاً لصافي الربح أعلاه، لحصلت على تقدير أقرب لـ"الربح الحقيقي لنشاط هذه الفترة" (بافتراض التحصيل لاحقاً بنفس القيمة تقريباً) = <b><?php echo number_format($net_profit + $unmatched_total_syp, 2); ?> ل.س</b>.
        هذا الفارق سيختفي تلقائياً عند تحصيل هذه الفواتير (سيظهر إيرادها حينها في فترة التحصيل).
    </div>
</div>
<?php endif; ?>

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