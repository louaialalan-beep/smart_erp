<?php
/**
 * إدارة المندوبين والعمولات - Smart ERP
 */
session_start();
include 'header.php';

if (!isset($conn)) {
    die("خطأ: اتصال قاعدة البيانات غير متوفر.");
}

$conn->exec("CREATE TABLE IF NOT EXISTS representatives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(50),
    email VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$msg = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_representative'])) {
    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!empty($name)) {
        try {
            $stmt = $conn->prepare("INSERT INTO representatives (name, phone, email, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $email, $notes]);
            $msg = "تمت إضافة المندوب بنجاح!";
        } catch (Exception $e) {
            $error = "خطأ أثناء الحفظ: " . $e->getMessage();
        }
    } else {
        $error = "اسم المندوب حقل إلزامي.";
    }
}

// تصحيح: total_commissions (وليس total_commission) هو العمود الفعلي في جدول sales
// وقيمة delivery_status المخزنة هي 'Delivered' (بحرف D كبير حسب تعريف ENUM الفعلي في القاعدة)
// وليست 'delivered' بأحرف صغيرة كما كان سابقاً، ما كان يجعل النتيجة صفراً دائماً.
// تصحيح جوهري: كان هذا العمود يعرض SUM(total_commissions) الخام فقط — إجمالي كل عمولة كُسِبت
// تاريخياً — بينما يوحي اسمه "المستحقة" بأنه المتبقي غير المدفوع. الملف التفصيلي (representative_profile.php)
// يطرح منها العمولات المعكوسة بسبب المرتجعات (sales_returns.total_commission_reversed) والدفعات
// المسددة فعلياً (representative_payments) — والآن هذه القائمة تستخدم نفس المعادلة بالضبط، فلا يظهر
// رقم متضخم لمندوب سُدِّدت عمولاته بالفعل (نفس الإصلاح المطبَّق سابقاً بين suppliers.php وsupplier_view.php).
$stmt = $conn->query("SELECT r.*, 
    (SELECT COUNT(s.id) FROM sales s WHERE s.representative_id = r.id) as total_sales_count,
    (
        COALESCE((SELECT SUM(s.total_commissions) FROM sales s WHERE s.representative_id = r.id AND s.delivery_status = 'Delivered'), 0)
        - COALESCE((
            SELECT SUM(sr.total_commission_reversed)
            FROM sales_returns sr
            INNER JOIN sales s2 ON sr.sale_id = s2.id
            WHERE s2.representative_id = r.id AND s2.delivery_status = 'Delivered'
        ), 0)
        - COALESCE((SELECT SUM(rp.amount_syp) FROM representative_payments rp WHERE rp.representative_id = r.id), 0)
    ) as total_commissions
    FROM representatives r ORDER BY r.id DESC");
$representatives = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="color: #2e384d; margin: 0;"><i class="fas fa-user-tie" style="color: var(--zoho-primary);"></i> إدارة المندوبين والعمولات</h2>
        <p style="color: #6b7280; font-size: 13px; margin-top: 5px;">إضافة ومتابعة المندوبين وحساباتهم وعمولاتهم بدقة.</p>
    </div>
    <div>
        <button onclick="openModal()" style="background: var(--zoho-primary); color: white; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <i class="fas fa-user-plus"></i> إضافة مندوب جديد
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="zoho-card">
    <div class="zoho-card-header">
        <h3><i class="fas fa-list"></i> قائمة المندوبين المسجلين</h3>
    </div>
    <div style="overflow-x: auto;">
        <table class="zoho-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>اسم المندوب</th>
                    <th>رقم الهاتف</th>
                    <th>البريد الإلكتروني</th>
                    <th>عدد الفواتير</th>
                    <th>إجمالي العمولات المستحقة</th>
                    <th style="text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($representatives) > 0): ?>
                    <?php foreach ($representatives as $index => $rep): ?>
                        <tr>
                            <td style="font-family: monospace;"><?php echo $index + 1; ?></td>
                            <td style="font-weight: bold; color: #1e293b;"><?php echo htmlspecialchars($rep['name']); ?></td>
                            <td style="font-family: monospace;"><?php echo htmlspecialchars($rep['phone'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($rep['email'] ?: '-'); ?></td>
                            <td style="font-family: monospace;"><?php echo $rep['total_sales_count']; ?></td>
                            <td style="font-family: monospace; color: var(--zoho-accent); font-weight: bold;"><?php echo number_format($rep['total_commissions'], 2); ?> ل.س</td>
                            <td style="text-align: center;">
                                <a href="representative_profile.php?id=<?php echo $rep['id']; ?>" style="background: var(--zoho-primary); color: white; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold; display: inline-block;">
                                    <i class="fas fa-file-invoice-dollar"></i> كشف الحساب والعمولات
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="padding: 30px; text-align: center; color: #777;">لا يوجد مندوبون مسجلون حتى الآن. قم بإضافة مندوب جديد للبدء.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- نافذة إضافة مندوب -->
<div id="repModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1050; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 5px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--zoho-border); padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: var(--zoho-primary); font-size: 16px;"><i class="fas fa-user-plus"></i> إضافة مندوب جديد</h3>
            <button onclick="closeModal()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #888;">&times;</button>
        </div>
        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_representative" value="1">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">اسم المندوب: <span style="color: red;">*</span></label>
                <input type="text" name="name" required placeholder="الاسم الكامل" style="width: 100%; padding: 9px; border: 1px solid var(--zoho-border); border-radius: 6px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">رقم الهاتف:</label>
                <input type="text" name="phone" placeholder="09xxxxxxxx" style="width: 100%; padding: 9px; border: 1px solid var(--zoho-border); border-radius: 6px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">البريد الإلكتروني:</label>
                <input type="email" name="email" placeholder="email@domain.com" style="width: 100%; padding: 9px; border: 1px solid var(--zoho-border); border-radius: 6px;">
            </div>
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">ملاحظات:</label>
                <textarea name="notes" placeholder="ملاحظات إضافية..." style="width: 100%; padding: 9px; border: 1px solid var(--zoho-border); border-radius: 6px; height: 70px;"></textarea>
            </div>
            <div style="text-align: left; border-top: 1px solid var(--zoho-border); padding-top: 15px;">
                <button type="button" onclick="closeModal()" style="background: #e2e8f0; color: #333; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; margin-left: 8px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: var(--zoho-primary); color: white; border: none; padding: 9px 20px; border-radius: 6px; cursor: pointer; font-weight: bold;">حفظ المندوب</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() { document.getElementById('repModal').style.display = 'flex'; }
    function closeModal() { document.getElementById('repModal').style.display = 'none'; }
</script>

<?php include 'footer.php'; ?>