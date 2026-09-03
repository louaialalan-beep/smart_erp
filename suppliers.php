<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

$msg = "";
$error = "";

// فحص/ترحيل دفاعي لنفس الأعمدة المستخدَمة في supplier_view.php (payment_status على الفواتير،
// opening_balance_usd على الموردين) — ضروري هنا لأن هذه الصفحة قد تُفتح أولاً قبل تلك الصفحة.
try {
    $pi_cols_chk2 = $conn->query("SHOW COLUMNS FROM purchase_invoices")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_status', $pi_cols_chk2)) {
        $conn->exec("ALTER TABLE purchase_invoices ADD COLUMN payment_status ENUM('Paid','Unpaid') NOT NULL DEFAULT 'Unpaid'");
    }
    $sup_cols_chk2 = $conn->query("SHOW COLUMNS FROM suppliers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('opening_balance_usd', $sup_cols_chk2)) {
        $conn->exec("ALTER TABLE suppliers ADD COLUMN opening_balance_usd DECIMAL(15,2) DEFAULT 0");
    }
    $sales_cols_chk3 = $conn->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('delivered_at', $sales_cols_chk3)) {
        $conn->exec("ALTER TABLE sales ADD COLUMN delivered_at DATE NULL");
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS supplier_discounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        amount_usd DECIMAL(15,2) NOT NULL,
        discount_date DATE NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) { /* يُتجاهل إن تعذّر */ }

// معالجة إضافة مورد جديد
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_supplier'])) {
    $supplier_name = trim($_POST['supplier_name']);
    $phone = trim($_POST['phone']);
    $currency = trim($_POST['currency']);
    $payment_terms = trim($_POST['payment_terms']);
    $returns_discounts = floatval($_POST['returns_discounts'] ?? 0);

    if (empty($supplier_name)) {
        $error = "خطأ: اسم المورد حقل إجباري.";
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, phone, currency, payment_terms, returns_discounts) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$supplier_name, $phone, $currency, $payment_terms, $returns_discounts]);
            $new_sup_id = $conn->lastInsertId();
            logAudit($conn, 'INSERT', 'الموردين', "إضافة مورد: $supplier_name" . ($returns_discounts > 0 ? " بمردودات/خصومات أولية $" . number_format($returns_discounts, 2) : ""), $new_sup_id);
            $msg = "تم إضافة المورد بنجاح!";
        } catch (PDOException $e) {
            $error = "خطأ في الحفظ: " . $e->getMessage();
        }
    }
}

// معالجة تعديل مورد حالي
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_supplier'])) {
    $id = intval($_POST['supplier_id']);
    $supplier_name = trim($_POST['supplier_name']);
    $phone = trim($_POST['phone']);
    $currency = trim($_POST['currency']);
    $payment_terms = trim($_POST['payment_terms']);
    $returns_discounts = floatval($_POST['returns_discounts'] ?? 0);

    try {
        $stmt_old = $conn->prepare("SELECT returns_discounts FROM suppliers WHERE id = ?");
        $stmt_old->execute([$id]);
        $old_returns = floatval($stmt_old->fetchColumn());

        $stmt = $conn->prepare("UPDATE suppliers SET supplier_name = ?, phone = ?, currency = ?, payment_terms = ?, returns_discounts = ? WHERE id = ?");
        $stmt->execute([$supplier_name, $phone, $currency, $payment_terms, $returns_discounts, $id]);

        $log_details = "تعديل مورد: $supplier_name";
        if ($old_returns != $returns_discounts) {
            $log_details .= " — تغيّرت المردودات/الخصومات من $" . number_format($old_returns, 2) . " إلى $" . number_format($returns_discounts, 2) . " (يؤثر مباشرة على صافي حساب المورد)";
        }
        logAudit($conn, 'UPDATE', 'الموردين', $log_details, $id);

        $msg = "تم تحديث بيانات المورد بنجاح!";
    } catch (PDOException $e) {
        $error = "خطأ في التحديث: " . $e->getMessage();
    }
}

// ============================================================
// فلتر يومي/أسبوعي لحركة الموردين مجتمعين (إجمالي المشتريات/المدفوعات/المردودات/صافي حركة الفترة)
// ============================================================
$sf_period = $_GET['sf_period'] ?? 'today';
$today_str2 = date('Y-m-d');
if ($sf_period === 'week') {
    $sf_from = date('Y-m-d', strtotime('monday this week'));
    $sf_to = date('Y-m-d', strtotime('sunday this week'));
} elseif ($sf_period === 'custom' && !empty($_GET['sf_from']) && !empty($_GET['sf_to'])) {
    $sf_from = $_GET['sf_from'];
    $sf_to = $_GET['sf_to'];
} else {
    $sf_period = 'today';
    $sf_from = $today_str2;
    $sf_to = $today_str2;
}

// تصحيح: توحيد منهجية بطاقات الفلتر مع منهجية الجدول أدناه تماماً — كانت بطاقات الفترة تشمل الفواتير
// النقدية (Paid) ضمن "إجمالي المشتريات" (رغم أنها لا تُنشئ التزاماً وتُستبعَد دائماً في الجدول)، ولا
// تشمل الخصومات المُسجَّلة (supplier_discounts) ضمن "المردودات/الخصم" — ما كان يجعل الرقمين مختلفين
// عن نظيريهما في الجدول لنفس الفترة، رغم أنهما يُفترَض أن يقيسا نفس الشيء بمنهجية موحَّدة.
$stmt_sf_purchases = $conn->prepare("
    SELECT COALESCE(SUM(pi.total_amount_usd), 0)
    FROM purchase_invoices pi
    WHERE pi.invoice_date BETWEEN ? AND ? AND pi.payment_status != 'Paid'
");
$stmt_sf_purchases->execute([$sf_from, $sf_to]);
$sf_total_purchases = floatval($stmt_sf_purchases->fetchColumn());

$stmt_sf_payments = $conn->prepare("SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_payments WHERE payment_date BETWEEN ? AND ?");
$stmt_sf_payments->execute([$sf_from, $sf_to]);
$sf_total_payments = floatval($stmt_sf_payments->fetchColumn());

$stmt_sf_returns = $conn->prepare("
    SELECT COALESCE(SUM(pr.total_amount_usd), 0)
    FROM purchase_returns pr
    INNER JOIN purchase_invoices pi ON pr.purchase_invoice_id = pi.id
    WHERE pr.return_date BETWEEN ? AND ? AND pi.payment_status != 'Paid'
");
$stmt_sf_returns->execute([$sf_from, $sf_to]);
$sf_total_purchase_returns_only = floatval($stmt_sf_returns->fetchColumn());

$stmt_sf_disc = $conn->prepare("SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_discounts WHERE discount_date BETWEEN ? AND ?");
$stmt_sf_disc->execute([$sf_from, $sf_to]);
$sf_total_discounts_logged = floatval($stmt_sf_disc->fetchColumn());

$sf_total_returns = $sf_total_purchase_returns_only + $sf_total_discounts_logged;

// صافي حركة الفترة (وليس الرصيد التراكمي المستحق — ذاك يظهر لكل مورد على حدة في الجدول أدناه)
$sf_net_movement = $sf_total_purchases - $sf_total_payments - $sf_total_returns;

// تكلفة البضائع المباعة (COGS) — مُسلَّمة فعلياً، لكل الموردين مجتمعين ضمن نفس الفترة (مصروف حقيقي
// مُرحَّل بالفعل في اليومية، محسوب بتاريخ التسليم الفعلي delivered_at وليس تاريخ إصدار الفاتورة)
$stmt_sf_cogs = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity * si.cost_price_usd_at_sale), 0)
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    WHERE s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
");
$stmt_sf_cogs->execute([$sf_from, $sf_to]);
$sf_total_cogs_delivered = floatval($stmt_sf_cogs->fetchColumn());

// إجمالي الرصيد الافتتاحي لكل الموردين مجتمعين — رقم إجمالي دائم بلا فلتر زمني (ليس حركة فترة، بل
// رصيد ثابت مُدخَل مرة واحدة لكل مورد)
$stmt_sf_opening = $conn->prepare("SELECT COALESCE(SUM(opening_balance_usd), 0) FROM suppliers");
$stmt_sf_opening->execute();
$sf_total_opening_balance = floatval($stmt_sf_opening->fetchColumn());

// استعلام جلب الموردين مع الحسابات التلقائية
// تصحيح شامل: كانت هذه القائمة تستخدم منهجاً قديماً مختلفاً تماماً عن supplier_view.php (المبني على
// products.purchased_quantity فقط، بلا استبعاد الفواتير النقدية، وبلا طرح مرتجعات المشتريات، وبلا
// الرصيد الافتتاحي) — ما كان يجعل "صافي الحساب" هنا يختلف عن الملف التفصيلي لنفس المورد. الآن مطابق
// تماماً لنفس منهج supplier_view.php: فواتير الشراء غير النقدية (purchase_invoice_items) + رصيد تكميلي
// للكميات القديمة غير المُغطاة بفواتير، ناقص مرتجعات الفواتير غير النقدية، زائد الرصيد الافتتاحي.
$sql = "SELECT s.*, 
        (
            (SELECT COALESCE(SUM(pii.total_cost_usd), 0)
                FROM purchase_invoice_items pii
                INNER JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
                WHERE pi.supplier_id = s.id AND pi.payment_status != 'Paid')
            + (SELECT COALESCE(SUM(
                    GREATEST(0, p.purchased_quantity - COALESCE((SELECT SUM(pii2.quantity) FROM purchase_invoice_items pii2 WHERE pii2.product_id = p.id), 0))
                    * p.cost_price_usd
                ), 0)
                FROM products p WHERE p.supplier_id = s.id)
        ) AS total_purchases,
        (SELECT COALESCE(SUM(sp.amount_usd), 0) FROM supplier_payments sp WHERE sp.supplier_id = s.id) AS total_payments,
        (SELECT COALESCE(SUM(pr.total_amount_usd), 0)
            FROM purchase_returns pr
            INNER JOIN purchase_invoices pi3 ON pr.purchase_invoice_id = pi3.id
            WHERE pi3.supplier_id = s.id AND pi3.payment_status != 'Paid') AS total_purchase_returns,
        (SELECT COALESCE(SUM(sd.amount_usd), 0) FROM supplier_discounts sd WHERE sd.supplier_id = s.id) AS total_discounts_logged
        FROM suppliers s 
        ORDER BY s.id DESC";
$suppliers = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// إجمالي صافي أرصدة كل الموردين مجتمعين — يُحسب مسبقاً هنا لعرضه كبطاقة، بنفس معادلة صافي الحساب
// المستخدمة لكل مورد على حدة أدناه في الجدول. هذا الرقم هو ما يجب أن يطابق "ذمم الموردين" في
// القوائم المالية الرسمية (financial_statements.php) إن كانت كل الحسابات متسقة.
$grand_total_net_balance = 0;
$grand_total_purchases_col = 0;
$grand_total_payments_col = 0;
$grand_total_returns_col = 0;
$grand_total_opening_col = 0;
foreach ($suppliers as $sup_pre) {
    $pre_opening = floatval($sup_pre['opening_balance_usd'] ?? 0);
    $pre_returns = floatval($sup_pre['returns_discounts']) + floatval($sup_pre['total_purchase_returns']) + floatval($sup_pre['total_discounts_logged']);
    $grand_total_net_balance += $sup_pre['total_purchases'] - $sup_pre['total_payments'] - $pre_returns + $pre_opening;
    $grand_total_purchases_col += floatval($sup_pre['total_purchases']);
    $grand_total_payments_col += floatval($sup_pre['total_payments']);
    $grand_total_returns_col += $pre_returns;
    $grand_total_opening_col += $pre_opening;
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2>إدارة الموردين والارتباط المالي (Suppliers Module)</h2>
        <p style="color: #666; margin: 0;">إدارة الموردين، متابعة المشتريات، المدفوعات، المردودات، وصافي الحسابات بالدولار.</p>
    </div>
    <div>
        <button onclick="openAddModal()" style="background: #1cc88a; color: white; padding: 9px 18px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold;">
            <i class="fas fa-plus"></i> إضافة مورد جديد
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $msg; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- فلتر حركة الموردين اليومي/الأسبوعي -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 15px;">
        <h3 style="margin: 0; color: #4e73df; font-size: 16px;"><i class="fas fa-chart-line"></i> حركة الموردين حسب الفترة (كل الموردين مجتمعين)</h3>
        <form method="GET" action="" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="?sf_period=today" style="text-decoration: none;">
                <span style="padding: 7px 14px; border-radius: 5px; font-size: 13px; font-weight: bold; cursor: pointer; background: <?php echo $sf_period === 'today' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $sf_period === 'today' ? '#fff' : '#4e73df'; ?>;">اليوم</span>
            </a>
            <a href="?sf_period=week" style="text-decoration: none;">
                <span style="padding: 7px 14px; border-radius: 5px; font-size: 13px; font-weight: bold; cursor: pointer; background: <?php echo $sf_period === 'week' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $sf_period === 'week' ? '#fff' : '#4e73df'; ?>;">هذا الأسبوع</span>
            </a>
            <input type="hidden" name="sf_period" value="custom">
            <input type="date" name="sf_from" value="<?php echo htmlspecialchars($sf_from); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <span style="color: #888;">إلى</span>
            <input type="date" name="sf_to" value="<?php echo htmlspecialchars($sf_to); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
            <button type="submit" style="background: #6f42c1; color: white; border: none; padding: 7px 14px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold;">تطبيق</button>
        </form>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
        <div style="background: #fdecea; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px;">
            <div style="color: #a33636; font-size: 13px; font-weight: bold;">إجمالي المشتريات</div>
            <div style="font-size: 22px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;">$<?php echo number_format($sf_total_purchases, 2); ?></div>
        </div>
        <div style="background: #eafaf1; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 6px;">
            <div style="color: #1a8f5f; font-size: 13px; font-weight: bold;">إجمالي المدفوعات</div>
            <div style="font-size: 22px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;">$<?php echo number_format($sf_total_payments, 2); ?></div>
        </div>
        <div style="background: #fff8e6; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px;">
            <div style="color: #96751c; font-size: 13px; font-weight: bold;" title="مرتجعات فواتير غير نقدية + خصومات مُسجَّلة ضمن هذه الفترة">المردودات / الخصم</div>
            <div style="font-size: 22px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;">$<?php echo number_format($sf_total_returns, 2); ?></div>
        </div>
        <div style="background: #eef1fc; border-right: 4px solid #2e59d9; padding: 15px; border-radius: 6px;">
            <div style="color: #2e59d9; font-size: 13px; font-weight: bold;">صافي حركة الفترة</div>
            <div style="font-size: 22px; font-weight: bold; color: #2e59d9; font-family: monospace; margin-top: 5px;">$<?php echo number_format($sf_net_movement, 2); ?></div>
        </div>
        <div style="background: #fdecea; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px;">
            <div style="color: #a33636; font-size: 13px; font-weight: bold;" title="مصروف حقيقي مُرحَّل فعلياً في اليومية، لكل الموردين مجتمعين">تكلفة البضائع المباعة (COGS) — مُسلَّمة</div>
            <div style="font-size: 22px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;">$<?php echo number_format($sf_total_cogs_delivered, 2); ?></div>
        </div>
        <div style="background: #f3eefc; border-right: 4px solid #6f42c1; padding: 15px; border-radius: 6px;">
            <div style="color: #4a2f7a; font-size: 13px; font-weight: bold;" title="رصيد إجمالي ثابت لكل الموردين مجتمعين — ليس حركة ضمن الفترة المحددة أعلاه">إجمالي الرصيد الافتتاحي (كل الموردين)</div>
            <div style="font-size: 22px; font-weight: bold; color: #6f42c1; font-family: monospace; margin-top: 5px;">$<?php echo number_format($sf_total_opening_balance, 2); ?></div>
        </div>
        <div style="background: #eaf1fc; border-right: 4px solid #2e59d9; padding: 15px; border-radius: 6px;">
            <div style="color: #2c4e9c; font-size: 13px; font-weight: bold;" title="مجموع صافي الحساب لكل الموردين — يجب أن يطابق بطاقة «ذمم الموردين» في القوائم المالية الرسمية">إجمالي صافي أرصدة كل الموردين</div>
            <div style="font-size: 22px; font-weight: bold; color: #2e59d9; font-family: monospace; margin-top: 5px;">$<?php echo number_format($grand_total_net_balance, 2); ?></div>
        </div>
    </div>
    <p style="color: #999; font-size: 12px; margin: 12px 0 0 0;">هذه أرقام <strong>حركة الفترة المحددة فقط</strong> (فواتير/دفعات/مرتجعات بتاريخ ضمن الفترة) لكل الموردين مجتمعين — وليست الرصيد التراكمي المستحق حالياً، والذي يظهر بشكل صحيح لكل مورد على حدة في عمود "صافي الحساب" بالجدول أدناه.</p>
</div>

<!-- جدول الموردين الرئيسي -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #4e73df; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">اسم المورد</th>
                    <th style="padding: 12px 15px;">رقم الهاتف</th>
                    <th style="padding: 12px 15px;">العملة</th>
                    <th style="padding: 12px 15px;">شروط السداد</th>
                    <th style="padding: 12px 15px; color: #e74a3b;">إجمالي المشتريات</th>
                    <th style="padding: 12px 15px; color: #1cc88a;">إجمالي المدفوعات</th>
                    <th style="padding: 12px 15px; color: #f6c23e;">المردودات / الخصم</th>
                    <th style="padding: 12px 15px; color: #6f42c1;">الرصيد الافتتاحي</th>
                    <th style="padding: 12px 15px; color: #2e59d9;">صافي الحساب الباقي</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($suppliers) > 0): ?>
                    <?php foreach ($suppliers as $sup): 
                        // صافي الحساب = إجمالي المشتريات - إجمالي المدفوعات - المردودات والخصومات اليدوية
                        // - مرتجعات المشتريات الفعلية + الرصيد الافتتاحي — نفس معادلة supplier_view.php بالضبط
                        $sup_opening_balance = floatval($sup['opening_balance_usd'] ?? 0);
                        $sup_total_returns = floatval($sup['returns_discounts']) + floatval($sup['total_purchase_returns']) + floatval($sup['total_discounts_logged']);
                        $net_balance = $sup['total_purchases'] - $sup['total_payments'] - $sup_total_returns + $sup_opening_balance;
                    ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 12px 15px; font-weight: 600; color: #333;"><?php echo htmlspecialchars($sup['supplier_name']); ?></td>
                            <td style="padding: 12px 15px; color: #555; font-family: monospace;"><?php echo htmlspecialchars($sup['phone'] ?: 'غير متوفر'); ?></td>
                            <td style="padding: 12px 15px; font-weight: bold; color: #4e73df;"><?php echo htmlspecialchars($sup['currency']); ?></td>
                            <td style="padding: 12px 15px; color: #666; font-size: 13px;"><?php echo htmlspecialchars($sup['payment_terms']); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #e74a3b; font-weight: bold;">$<?php echo number_format($sup['total_purchases'], 2); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #1cc88a; font-weight: bold;">$<?php echo number_format($sup['total_payments'], 2); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #f6c23e; font-weight: bold;" title="خصومات يدوية: $<?php echo number_format($sup['returns_discounts'], 2); ?> + خصومات مُسجَّلة: $<?php echo number_format($sup['total_discounts_logged'], 2); ?> + مرتجعات فعلية: $<?php echo number_format($sup['total_purchase_returns'], 2); ?>">$<?php echo number_format($sup_total_returns, 2); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #6f42c1; font-weight: bold;">$<?php echo number_format($sup_opening_balance, 2); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #2e59d9; font-weight: bold;">$<?php echo number_format($net_balance, 2); ?></td>
                            <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                                <a href="supplier_view.php?id=<?php echo $sup['id']; ?>" style="background: #4e73df; color: white; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold; margin-left: 4px;">
                                    <i class="fas fa-eye"></i> الملف التفصيلي
                                </a>
                                <button onclick='openEditModal(<?php echo json_encode($sup); ?>)' style="background: #f6c23e; color: white; padding: 5px 10px; border-radius: 4px; border: none; cursor: pointer; font-size: 12px; font-weight: bold;">
                                    <i class="fas fa-edit"></i> تعديل
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" style="padding: 30px; text-align: center; color: #777;">لا توجد أي موردين مسجلين حالياً.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <?php if (count($suppliers) > 0): ?>
            <tfoot>
                <tr style="background: #f8f9fc; border-top: 2px solid #e3e6f0; font-weight: bold;">
                    <td style="padding: 12px 15px;" colspan="4">الإجمالي (كل الموردين)</td>
                    <td style="padding: 12px 15px; font-family: monospace; color: #e74a3b;">$<?php echo number_format($grand_total_purchases_col, 2); ?></td>
                    <td style="padding: 12px 15px; font-family: monospace; color: #1cc88a;">$<?php echo number_format($grand_total_payments_col, 2); ?></td>
                    <td style="padding: 12px 15px; font-family: monospace; color: #f6c23e;">$<?php echo number_format($grand_total_returns_col, 2); ?></td>
                    <td style="padding: 12px 15px; font-family: monospace; color: #6f42c1;">$<?php echo number_format($grand_total_opening_col, 2); ?></td>
                    <td style="padding: 12px 15px; font-family: monospace; color: #2e59d9;">$<?php echo number_format($grand_total_net_balance, 2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Modal لإضافة أو تعديل مورد -->
<div id="supplierModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 500px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 id="modalTitle" style="margin: 0; color: #4e73df;">إضافة مورد جديد</h3>
            <button onclick="closeModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="supplier_id" id="supplier_id">
            <input type="hidden" name="add_supplier" id="form_action_add" value="1">

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">اسم المورد أو الشركة:</label>
                <input type="text" name="supplier_name" id="supplier_name" required placeholder="مثال: شركة التوريدات..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">رقم الهاتف:</label>
                <input type="text" name="phone" id="phone" placeholder="09xxxxxxxx" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">العملة المعتمدة:</label>
                <select name="currency" id="currency" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="USD">دولار أمريكي (USD)</option>
                </select>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">شروط السداد:</label>
                <input type="text" name="payment_terms" id="payment_terms" value="نقداً / 30 يوم" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">المردودات / الخصومات المكتسبة (USD):</label>
                <input type="number" step="0.0001" name="returns_discounts" id="returns_discounts" value="0" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">
                <button type="button" onclick="closeModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" id="saveButton" style="background: #1cc88a; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ المورد</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddModal() {
        document.getElementById('modalTitle').innerText = 'إضافة مورد جديد';
        document.getElementById('supplier_id').value = '';
        document.getElementById('supplier_name').value = '';
        document.getElementById('phone').value = '';
        document.getElementById('payment_terms').value = 'نقداً / 30 يوم';
        document.getElementById('returns_discounts').value = '0';
        document.getElementById('form_action_add').name = 'add_supplier';
        document.getElementById('saveButton').style.backgroundColor = '#1cc88a';
        document.getElementById('supplierModal').style.display = 'flex';
    }

    function openEditModal(sup) {
        document.getElementById('modalTitle').innerText = 'تعديل بيانات المورد: ' + sup.supplier_name;
        document.getElementById('supplier_id').value = sup.id;
        document.getElementById('supplier_name').value = sup.supplier_name;
        document.getElementById('phone').value = sup.phone;
        document.getElementById('currency').value = sup.currency;
        document.getElementById('payment_terms').value = sup.payment_terms;
        document.getElementById('returns_discounts').value = sup.returns_discounts;
        document.getElementById('form_action_add').name = 'edit_supplier';
        document.getElementById('saveButton').style.backgroundColor = '#f6c23e';
        document.getElementById('supplierModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('supplierModal').style.display = 'none';
    }
</script>

<?php include 'footer.php'; ?>