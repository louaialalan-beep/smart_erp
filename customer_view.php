<?php
/**
 * ملف عميل مخصَّص — يعرض كل فواتير عميل معيّن (بالاسم، لا بمعرِّف مستقل، لتفادي أي ترحيل بيانات أو
 * تغيير مخطط قاعدة البيانات) مع ملخّص إجمالي: كم دَين مستحق عليه الآن، كم دفع تاريخياً، وكم اشترى
 * بالمجمل. يفتح من رابط مباشر (customer_view.php?name=...) أو عبر البحث في هذه الصفحة نفسها.
 *
 * ميزة "تحصيل دفعة جزئية": هذه الصفحة الوحيدة في النظام التي تدعم تتبّع دفعات جزئية حقيقية (النظام
 * الأساسي في sales.php يدعم فقط "آجل بالكامل" أو "مدفوع بالكامل"). آمنة تماماً لأنها لا تمسّ الاعتراف
 * بالإيراد إطلاقاً — ذاك يبقى مشروطاً بـ payment_status = 'Paid' بالضبط كما هو مصمَّم أصلاً في
 * recognizeSaleRevenue/tryRecognizeRevenue، فيبقى الإيراد مؤجَّلاً حتى تكتمل كل الدفعات 100%، تماماً
 * كسلوك النظام الحالي بلا أي تغيير عليه. الدفعة الجزئية هنا تُخفِّض فقط رصيد "ذمم العملاء" الفعلي
 * (حركة نقدية حقيقية موثَّقة)، وتُحدِّث حالة الدفع لـ'Partial' حتى تكتمل، عندها تتحوَّل لـ'Paid' تلقائياً
 * ويُستدعى الاعتراف بالإيراد بشكل طبيعي كأي دفعة كاملة عادية.
 */
require_once 'header.php';

// ترحيل آمن: عمود تتبّع المدفوع تدريجياً — يُضاف مرة واحدة فقط إن لم يكن موجوداً
try {
    $cols = $conn->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('paid_amount_syp', $cols)) {
        $conn->exec("ALTER TABLE sales ADD COLUMN paid_amount_syp DECIMAL(15,2) DEFAULT 0");
    }
} catch (Exception $e) { }

$payment_msg = '';
$payment_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    requireRole($conn, ['admin', 'accountant']);
    $pay_sale_id = intval($_POST['sale_id'] ?? 0);
    $pay_amount = floatval($_POST['payment_amount_syp'] ?? 0);
    $pay_date = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');

    try {
        if ($pay_amount <= 0) throw new Exception("مبلغ الدفعة يجب أن يكون أكبر من صفر.");

        $conn->beginTransaction();
        $stmt_s = $conn->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
        try { $stmt_s->execute([$pay_sale_id]); }
        catch (Exception $e) { $stmt_s = $conn->prepare("SELECT * FROM sales WHERE id = ?"); $stmt_s->execute([$pay_sale_id]); }
        $pay_sale = $stmt_s->fetch(PDO::FETCH_ASSOC);
        if (!$pay_sale) throw new Exception("الفاتورة غير موجودة.");
        if ($pay_sale['payment_status'] === 'Paid') throw new Exception("هذه الفاتورة مدفوعة بالكامل أصلاً.");

        // الصافي المستحق = إجمالي الفاتورة ناقص أي مرتجع أو خصم مسجَّل عليها (لا يُحصَّل ما أُرجِع)
        $stmt_net_adj = $conn->prepare("
            SELECT COALESCE((SELECT SUM(total_amount_syp) FROM sales_returns WHERE sale_id = ?), 0)
                 + COALESCE((SELECT SUM(amount_syp) FROM sale_item_discounts WHERE sale_id = ?), 0)
        ");
        $stmt_net_adj->execute([$pay_sale_id, $pay_sale_id]);
        $net_invoice_total = floatval($pay_sale['total_amount_syp']) - floatval($stmt_net_adj->fetchColumn());

        $already_paid = floatval($pay_sale['paid_amount_syp'] ?? 0);
        $remaining = $net_invoice_total - $already_paid;
        if ($pay_amount > $remaining + 0.01) {
            throw new Exception("المبلغ المُدخَل (" . number_format($pay_amount, 2) . ") أكبر من المتبقي فعلياً على هذه الفاتورة (" . number_format($remaining, 2) . " ل.س).");
        }

        $new_paid = $already_paid + $pay_amount;
        $now_fully_paid = ($new_paid >= $net_invoice_total - 0.01);
        $new_status = $now_fully_paid ? 'Paid' : 'Partial';

        $conn->prepare("UPDATE sales SET paid_amount_syp = ?, payment_status = ? WHERE id = ?")
             ->execute([$new_paid, $new_status, $pay_sale_id]);

        // القيد المحاسبي: مدين الصندوق (نقد دخل فعلياً) / دائن ذمم العملاء (الالتزام ينخفض بنفس المقدار)
        $ar_account_id = findOrCreateAccount($conn, ['ذمم العملاء', 'ذمم عملاء', 'accounts receivable'], 'ذمم العملاء', 'Asset');
        $cash_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي', 'Asset');
        $pay_entry_num = "JE-" . $pay_sale['invoice_number'] . "-PARTIALPAY-" . time();
        $pay_desc = "دفعة " . ($now_fully_paid ? "أخيرة (تُكمِل السداد بالكامل)" : "جزئية") . " من العميل: " . $pay_sale['customer_name'] . " على فاتورة " . $pay_sale['invoice_number'];
        postJournalLine($conn, $cash_account_id, $pay_amount, 0, $pay_entry_num, $pay_date, $pay_desc, 'Customer Partial Payment');
        postJournalLine($conn, $ar_account_id, 0, $pay_amount, $pay_entry_num, $pay_date, $pay_desc, 'Customer Partial Payment');

        // إن اكتمل السداد 100% الآن، يُستدعى الاعتراف بالإيراد الطبيعي (يعمل فقط إن كانت الفاتورة
        // "مُسلَّمة" أيضاً — بالضبط نفس الشرط الأصلي، بلا أي تعديل عليه)
        if ($now_fully_paid) {
            tryRecognizeRevenue($conn, $pay_sale_id);
        }

        $conn->commit();
        logAudit($conn, 'UPDATE', 'دفعات العملاء', "دفعة " . number_format($pay_amount, 2) . " ل.س من " . $pay_sale['customer_name'] . " على فاتورة " . $pay_sale['invoice_number'], $pay_sale_id);
        $payment_msg = "تم تسجيل الدفعة بنجاح" . ($now_fully_paid ? " — اكتمل سداد الفاتورة بالكامل الآن." : " (دفعة جزئية، تبقّى " . number_format($net_invoice_total - $new_paid, 2) . " ل.س).");
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        $payment_error = $e->getMessage();
    }
}

$search_name = trim($_GET['name'] ?? $_POST['customer_name_redirect'] ?? '');

// فترة اختيارية لإحصائيات "خلال المدة" (عدد القطع، نسبة الشراء من إجمالي المبيعات، نسبة المرتجع) — لا
// تؤثر على الملخّص الإجمالي ولا قائمة الفواتير أدناه (تلك تبقى تاريخ العميل الكامل كما هي دائماً).
$period_from = trim($_GET['period_from'] ?? '');
$period_to = trim($_GET['period_to'] ?? '');
if ($period_from === '' || $period_to === '') {
    $period_from = date('Y-m-01'); // افتراضياً: الشهر الحالي
    $period_to = date('Y-m-d');
}

$summary = null;
$invoices = [];
$period_stats = null;

if ($search_name !== '') {
    // إحصائيات الفترة: عدد القطع الصافي (المُسلَّمة، بعد طرح المرتجع)، قيمتها، نسبتها من إجمالي مبيعات
    // النظام كله (كل العملاء) خلال نفس الفترة، وعدد/قيمة القطع المُرجَعة ونسبتها من قطع هذا العميل.
    $stmt_period = $conn->prepare("
        SELECT
            COALESCE(SUM(si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS net_qty,
            COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * si.unit_price_syp), 0) AS net_value_syp,
            COALESCE(SUM(COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)), 0) AS returned_qty,
            COALESCE(SUM(COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0) * si.unit_price_syp), 0) AS returned_value_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.customer_name = ?
          AND ((s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?)
               OR (s.delivery_status != 'Delivered' AND s.invoice_date BETWEEN ? AND ?))
    ");
    $stmt_period->execute([$search_name, $period_from, $period_to, $period_from, $period_to]);
    $period_stats = $stmt_period->fetch(PDO::FETCH_ASSOC);

    // إجمالي مبيعات النظام كله (كل العملاء) خلال نفس الفترة، لحساب نسبة هذا العميل من الإجمالي
    $stmt_total_sales = $conn->prepare("
        SELECT COALESCE(SUM((si.quantity - COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0)) * si.unit_price_syp), 0) AS total_value_syp
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE (s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?)
           OR (s.delivery_status != 'Delivered' AND s.invoice_date BETWEEN ? AND ?)
    ");
    $stmt_total_sales->execute([$period_from, $period_to, $period_from, $period_to]);
    $period_stats['system_total_value_syp'] = floatval($stmt_total_sales->fetchColumn());
    $period_stats['customer_share_pct'] = $period_stats['system_total_value_syp'] > 0
        ? ($period_stats['net_value_syp'] / $period_stats['system_total_value_syp']) * 100 : 0;
    $period_stats['return_pct'] = ($period_stats['net_qty'] + $period_stats['returned_qty']) > 0
        ? ($period_stats['returned_qty'] / ($period_stats['net_qty'] + $period_stats['returned_qty'])) * 100 : 0;
}

if ($search_name !== '') {
    // ملخّص إجمالي لهذا العميل: عدد الفواتير، الإجمالي، المدفوع، الآجل، المُسلَّم، قيد الانتظار
    $stmt_summary = $conn->prepare("
        SELECT
            COUNT(*) AS invoices_count,
            COALESCE(SUM(net_total), 0) AS total_all_syp,
            COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN net_total ELSE LEAST(COALESCE(paid_amount_syp, 0), GREATEST(net_total, 0)) END), 0) AS total_paid_syp,
            COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN 0 ELSE GREATEST(0, net_total - COALESCE(paid_amount_syp, 0)) END), 0) AS total_unpaid_syp,
            COALESCE(SUM(CASE WHEN delivery_status = 'Delivered' THEN 1 ELSE 0 END), 0) AS delivered_count,
            COALESCE(SUM(CASE WHEN delivery_status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_count
        FROM (
            SELECT s.*,
                   (s.total_amount_syp
                    - COALESCE((SELECT SUM(sr.total_amount_syp) FROM sales_returns sr WHERE sr.sale_id = s.id), 0)
                    - COALESCE((SELECT SUM(sid.amount_syp) FROM sale_item_discounts sid WHERE sid.sale_id = s.id), 0)) AS net_total
            FROM sales s
            WHERE s.customer_name = ?
        ) t
    ");
    $stmt_summary->execute([$search_name]);
    $summary = $stmt_summary->fetch(PDO::FETCH_ASSOC);

    // تنبيه تشابه أسماء: عملاء بأسماء قريبة جداً (قد تكون نفس الشخص بتهجئة مختلفة، كما اكتشفنا سابقاً
    // مع "نورة" مقابل "نورا") — عرضها بشفافية بدل إخفائها، ليقرر المستخدم بنفسه إن كانت نفس الشخص فعلاً
    $similar_stmt = $conn->prepare("
        SELECT customer_name, COUNT(*) AS cnt FROM sales
        WHERE customer_name != ? AND (customer_name LIKE ? OR ? LIKE CONCAT('%', SUBSTRING(customer_name, 1, 4), '%'))
        GROUP BY customer_name ORDER BY cnt DESC LIMIT 5
    ");
    $name_prefix = mb_substr($search_name, 0, 4);
    $similar_stmt->execute([$search_name, '%' . $name_prefix . '%', $search_name]);
    $similar_names = $similar_stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt_inv = $conn->prepare("
        SELECT id, invoice_number, invoice_date, delivery_status, payment_status, total_amount_syp, total_amount_usd,
               delivery_type, shipping_cost_syp, delivered_at, created_at, COALESCE(paid_amount_syp, 0) AS paid_amount_syp,
               (total_amount_syp
                - COALESCE((SELECT SUM(sr.total_amount_syp) FROM sales_returns sr WHERE sr.sale_id = sales.id), 0)
                - COALESCE((SELECT SUM(sid.amount_syp) FROM sale_item_discounts sid WHERE sid.sale_id = sales.id), 0)) AS net_total_syp
        FROM sales
        WHERE customer_name = ?
        ORDER BY invoice_date DESC, id DESC
    ");
    $stmt_inv->execute([$search_name]);
    $invoices = $stmt_inv->fetchAll(PDO::FETCH_ASSOC);
}

$delivery_labels = ['Delivered' => 'مُسلَّمة', 'Pending' => 'قيد الانتظار', 'Deferred' => 'مؤجَّلة'];
$delivery_colors = ['Delivered' => '#1cc88a', 'Pending' => '#f6c23e', 'Deferred' => '#858796'];
$payment_labels = ['Paid' => 'نقداً في الصندوق', 'Unpaid' => 'آجل / ذمم عملاء', 'Partial' => 'دفعة جزئية'];
$payment_colors = ['Paid' => '#1cc88a', 'Unpaid' => '#e74a3b', 'Partial' => '#4e73df'];
?>

<div style="max-width: 1100px; margin: 0 auto; padding: 20px;">
    <h2 style="margin-bottom: 5px;"><i class="fas fa-user"></i> ملف عميل</h2>
    <p style="color: #777; margin-top: 0;">كل فواتير عميل معيّن، إجمالي دَينه الحالي، وما دفعه تاريخياً — بحثاً بالاسم كما سُجِّل بالضبط في فواتيره.</p>

    <form method="GET" style="display: flex; gap: 10px; align-items: center; margin-bottom: 20px; background: white; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0;">
        <input type="text" name="name" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="اكتب اسم العميل بالضبط كما يظهر في فواتيره..." required
               style="flex: 1; padding: 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 14px;">
        <button type="submit" style="background: #4e73df; color: white; border: none; padding: 10px 22px; border-radius: 5px; font-weight: bold; cursor: pointer;">
            <i class="fas fa-search"></i> بحث
        </button>
    </form>

    <?php if ($search_name !== ''): ?>
    <form method="GET" style="display: flex; gap: 10px; align-items: center; margin-bottom: 20px; background: white; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0; flex-wrap: wrap;">
        <input type="hidden" name="name" value="<?php echo htmlspecialchars($search_name); ?>">
        <label style="font-size: 13px; font-weight: bold; color: #555;">إحصائيات خلال الفترة من:</label>
        <input type="date" name="period_from" value="<?php echo htmlspecialchars($period_from); ?>" style="padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
        <label style="font-size: 13px; font-weight: bold; color: #555;">إلى:</label>
        <input type="date" name="period_to" value="<?php echo htmlspecialchars($period_to); ?>" style="padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
        <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 9px 20px; border-radius: 5px; font-weight: bold; cursor: pointer;">
            <i class="fas fa-filter"></i> تطبيق
        </button>
        <span style="font-size: 11.5px; color: #999;">لا تؤثر على الملخّص الإجمالي أو قائمة الفواتير أدناه — فقط على بطاقات "خلال الفترة".</span>
    </form>
    <?php endif; ?>

    <?php if ($payment_msg): ?>
        <div style="background: #e8f8f2; border: 1px solid #1cc88a; color: #1a8f5f; padding: 12px 15px; border-radius: 8px; margin-bottom: 15px; font-weight: bold;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($payment_msg); ?>
        </div>
    <?php endif; ?>
    <?php if ($payment_error): ?>
        <div style="background: #fdecea; border: 1px solid #e74a3b; color: #a33636; padding: 12px 15px; border-radius: 8px; margin-bottom: 15px; font-weight: bold;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($payment_error); ?>
        </div>
    <?php endif; ?>

    <?php if ($search_name !== '' && $summary && intval($summary['invoices_count']) > 0): ?>

        <?php if (count($similar_names) > 0): ?>
        <div style="background: #fff8e6; border: 1px solid #f6c23e; border-radius: 8px; padding: 12px 15px; margin-bottom: 15px; font-size: 13px;">
            <i class="fas fa-exclamation-triangle" style="color: #96751c;"></i>
            <b>تنبيه تشابه أسماء:</b> وجدت أسماء عملاء أخرى قريبة من "<?php echo htmlspecialchars($search_name); ?>" — تحقق إن كانت نفس الشخص بتهجئة مختلفة (فواتيره حينها موزَّعة ولن تظهر هنا):
            <?php foreach ($similar_names as $sn): ?>
                <a href="?name=<?php echo urlencode($sn['customer_name']); ?>" style="display:inline-block; margin: 4px 6px; background:#fff; border:1px solid #f6c23e; padding:3px 10px; border-radius:12px; text-decoration:none; color:#96751c; font-weight:bold;">
                    <?php echo htmlspecialchars($sn['customer_name']); ?> (<?php echo intval($sn['cnt']); ?>)
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px;">
            <div style="background: white; border-right: 4px solid #4e73df; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0;">
                <div style="color: #666; font-size: 12.5px; font-weight: bold;">عدد الفواتير</div>
                <div style="font-size: 22px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo intval($summary['invoices_count']); ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 3px;">مُسلَّمة: <?php echo intval($summary['delivered_count']); ?> | قيد الانتظار: <?php echo intval($summary['pending_count']); ?></div>
            </div>
            <div style="background: white; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0;">
                <div style="color: #666; font-size: 12.5px; font-weight: bold;">إجمالي كل الفواتير</div>
                <div style="font-size: 20px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;"><?php echo number_format($summary['total_all_syp'], 2); ?> ل.س</div>
            </div>
            <div style="background: #e8f8f2; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0;">
                <div style="color: #1a8f5f; font-size: 12.5px; font-weight: bold;">إجمالي ما دفعه فعلياً</div>
                <div style="font-size: 20px; font-weight: bold; color: #1a8f5f; font-family: monospace; margin-top: 5px;"><?php echo number_format($summary['total_paid_syp'], 2); ?> ل.س</div>
            </div>
            <div style="background: <?php echo floatval($summary['total_unpaid_syp']) > 0 ? '#fdecea' : '#e8f8f2'; ?>; border-right: 4px solid <?php echo floatval($summary['total_unpaid_syp']) > 0 ? '#e74a3b' : '#1cc88a'; ?>; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0;">
                <div style="color: #a33636; font-size: 12.5px; font-weight: bold;">إجمالي الدَّين المستحق الآن</div>
                <div style="font-size: 22px; font-weight: bold; color: <?php echo floatval($summary['total_unpaid_syp']) > 0 ? '#e74a3b' : '#1a8f5f'; ?>; font-family: monospace; margin-top: 5px;"><?php echo number_format($summary['total_unpaid_syp'], 2); ?> ل.س</div>
                <div style="font-size: 11px; color: #888; margin-top: 3px;">مجموع كل الفواتير "آجلة" أو "دفعة جزئية" حالياً</div>
            </div>
        </div>

        <?php if ($period_stats): ?>
        <div style="margin-bottom: 25px;">
            <h3 style="font-size: 15px; color: #3a3b45; margin-bottom: 10px;"><i class="fas fa-chart-pie"></i> خلال الفترة (<?php echo htmlspecialchars($period_from); ?> إلى <?php echo htmlspecialchars($period_to); ?>)</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                <div style="background: white; border-right: 4px solid #4e73df; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0;">
                    <div style="color: #666; font-size: 12.5px; font-weight: bold;">عدد القطع المشتراة (صافٍ)</div>
                    <div style="font-size: 20px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($period_stats['net_qty'], 2), '0'), '.'); ?></div>
                    <div style="font-size: 11px; color: #999; margin-top: 3px;">بقيمة <?php echo number_format($period_stats['net_value_syp'], 2); ?> ل.س</div>
                </div>
                <div style="background: white; border-right: 4px solid #6f42c1; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0;">
                    <div style="color: #666; font-size: 12.5px; font-weight: bold;">نسبته من إجمالي مبيعاتك</div>
                    <div style="font-size: 20px; font-weight: bold; color: #6f42c1; font-family: monospace; margin-top: 5px;"><?php echo number_format($period_stats['customer_share_pct'], 2); ?>٪</div>
                    <div style="font-size: 11px; color: #999; margin-top: 3px;">من إجمالي <?php echo number_format($period_stats['system_total_value_syp'], 2); ?> ل.س (كل العملاء)</div>
                </div>
                <div style="background: <?php echo floatval($period_stats['returned_qty']) > 0 ? '#fdecea' : 'white'; ?>; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0;">
                    <div style="color: #a33636; font-size: 12.5px; font-weight: bold;">القطع المُرجَعة</div>
                    <div style="font-size: 20px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($period_stats['returned_qty'], 2), '0'), '.'); ?></div>
                    <div style="font-size: 11px; color: #888; margin-top: 3px;">بقيمة <?php echo number_format($period_stats['returned_value_syp'], 2); ?> ل.س</div>
                </div>
                <div style="background: <?php echo $period_stats['return_pct'] > 20 ? '#fdecea' : 'white'; ?>; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 8px; border: 1px solid #e3e6f0;">
                    <div style="color: #96751c; font-size: 12.5px; font-weight: bold;">نسبة المرتجع</div>
                    <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;"><?php echo number_format($period_stats['return_pct'], 2); ?>٪</div>
                    <div style="font-size: 11px; color: #888; margin-top: 3px;">من إجمالي ما اشتراه خلال الفترة</div>
                </div>
            </div>
            <p style="font-size: 11px; color: #999; margin-top: 8px;"><i class="fas fa-info-circle"></i> هذه البطاقات وحدها مرتبطة بفلتر الفترة أعلاه — الملخّص الإجمالي وقائمة الفواتير يبقيان لكامل تاريخ العميل دائماً.</p>
        </div>
        <?php endif; ?>

        <div style="background: white; border-radius: 8px; border: 1px solid #e3e6f0; overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
                <thead>
                    <tr style="background: #f8f9fc; border-bottom: 2px solid #e3e6f0; color: #555;">
                        <th style="padding: 10px 15px;">رقم الفاتورة</th>
                        <th style="padding: 10px 15px;">التاريخ</th>
                        <th style="padding: 10px 15px;">الإجمالي</th>
                        <th style="padding: 10px 15px; color:#a33636;">المتبقي</th>
                        <th style="padding: 10px 15px;">حالة التسليم</th>
                        <th style="padding: 10px 15px;">حالة الدفع</th>
                        <th style="padding: 10px 15px; text-align: center; min-width: 260px;">تحصيل دفعة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv):
                        $remaining_syp = floatval($inv['net_total_syp']) - floatval($inv['paid_amount_syp']);
                    ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 9px 15px; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                            <td style="padding: 9px 15px; font-family: monospace;"><?php echo htmlspecialchars($inv['invoice_date']); ?></td>
                            <td style="padding: 9px 15px; font-family: monospace;"><?php echo number_format($inv['total_amount_syp'], 2); ?> ل.س <span style="color:#999;">($<?php echo number_format($inv['total_amount_usd'], 2); ?>)</span>
                                <?php if (abs(floatval($inv['total_amount_syp']) - floatval($inv['net_total_syp'])) > 0.01): ?>
                                    <div style="font-size: 10.5px; color: #96751c;">الصافي بعد مرتجع/خصم: <?php echo number_format($inv['net_total_syp'], 2); ?> ل.س</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 9px 15px; font-family: monospace; font-weight: bold; color: <?php echo $remaining_syp > 0.01 ? '#e74a3b' : '#1cc88a'; ?>;">
                                <?php echo number_format(max(0, $remaining_syp), 2); ?> ل.س
                                <?php if (floatval($inv['paid_amount_syp']) > 0 && $remaining_syp > 0.01): ?>
                                    <div style="font-size: 10px; color: #888; font-weight: normal;">دُفِع سابقاً: <?php echo number_format($inv['paid_amount_syp'], 2); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 9px 15px;">
                                <span style="background: <?php echo $delivery_colors[$inv['delivery_status']] ?? '#ccc'; ?>1a; color: <?php echo $delivery_colors[$inv['delivery_status']] ?? '#666'; ?>; padding: 3px 10px; border-radius: 10px; font-size: 12px; font-weight: bold;">
                                    <?php echo $delivery_labels[$inv['delivery_status']] ?? $inv['delivery_status']; ?>
                                </span>
                            </td>
                            <td style="padding: 9px 15px;">
                                <span style="background: <?php echo $payment_colors[$inv['payment_status']] ?? '#ccc'; ?>1a; color: <?php echo $payment_colors[$inv['payment_status']] ?? '#666'; ?>; padding: 3px 10px; border-radius: 10px; font-size: 12px; font-weight: bold;">
                                    <?php echo $payment_labels[$inv['payment_status']] ?? $inv['payment_status']; ?>
                                </span>
                            </td>
                            <td style="padding: 9px 15px; text-align: center;">
                                <?php if ($inv['payment_status'] !== 'Paid'): ?>
                                    <form method="POST" style="display: flex; gap: 5px; align-items: center; justify-content: center;" onsubmit="var b=this.querySelector('button'); if(b.disabled) return false; b.disabled=true; b.innerText='...'; return true;">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="record_payment" value="1">
                                        <input type="hidden" name="sale_id" value="<?php echo intval($inv['id']); ?>">
                                        <input type="number" step="0.01" min="0.01" max="<?php echo max(0, $remaining_syp); ?>" name="payment_amount_syp" required placeholder="المبلغ ل.س" style="width: 100px; padding: 5px 7px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 12px;">
                                        <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" title="تاريخ الدفع الفعلي" style="padding: 5px 4px; border: 1px solid #ccc; border-radius: 4px; font-size: 11px; width: 118px;">
                                        <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 6px 12px; border-radius: 5px; font-size: 12px; font-weight: bold; cursor: pointer; white-space: nowrap;">
                                            <i class="fas fa-hand-holding-usd"></i> تسجيل
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="sales.php?list_search=<?php echo urlencode($inv['invoice_number']); ?>" style="background: #f1f3f9; color: #4e73df; padding: 5px 14px; border-radius: 5px; text-decoration: none; font-size: 12px; font-weight: bold;">
                                        عرض
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="font-size: 11.5px; color: #999; margin-top: 12px;">
            <i class="fas fa-info-circle"></i> "تحصيل دفعة" يُسجِّل المبلغ فوراً هنا (يدعم دفعات جزئية متعددة على نفس الفاتورة). الإيراد يبقى مؤجَّلاً محاسبياً حتى تكتمل كل الدفعة 100%، بالضبط كسلوك النظام الأصلي.
        </p>

    <?php elseif ($search_name !== ''): ?>
        <div style="background: white; border-radius: 8px; border: 1px solid #e3e6f0; padding: 40px; text-align: center; color: #999;">
            <i class="fas fa-user-slash" style="font-size: 30px; margin-bottom: 10px; display: block;"></i>
            لا توجد أي فاتورة بهذا الاسم بالضبط: "<?php echo htmlspecialchars($search_name); ?>"
            <br><span style="font-size: 12px;">تحقق من التهجئة الدقيقة كما سُجِّلت في الفاتورة الأصلية (مسافات، همزات، تاء مربوطة...).</span>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>