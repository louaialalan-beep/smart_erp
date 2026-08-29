<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

$msg = "";
$error = "";

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

// استعلام جلب الموردين مع الحسابات التلقائية
// تصحيح: استُخدم purchased_quantity (الكمية الأصلية المشتراة) بدلاً من current_quantity
// (الكمية المتبقية بالمخزون) لتطابق نفس منطق الحساب المستخدم في supplier_view.php تماماً.
// اعتماد current_quantity هنا كان يجعل "إجمالي المشتريات" في هذه القائمة يتناقص مع كل عملية بيع
// ويختلف عن القيمة الصحيحة الثابتة الظاهرة في الملف التفصيلي لنفس المورد.
$sql = "SELECT s.*, 
        (SELECT COALESCE(SUM(COALESCE(p.purchased_quantity, p.current_quantity) * p.cost_price_usd), 0) FROM products p WHERE p.supplier_id = s.id) AS total_purchases,
        (SELECT COALESCE(SUM(sp.amount_usd), 0) FROM supplier_payments sp WHERE sp.supplier_id = s.id) AS total_payments
        FROM suppliers s 
        ORDER BY s.id DESC";
$suppliers = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
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
                    <th style="padding: 12px 15px; color: #2e59d9;">صافي الحساب</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($suppliers) > 0): ?>
                    <?php foreach ($suppliers as $sup): 
                        // صافي الحساب = إجمالي المشتريات - إجمالي المدفوعات - المردودات والخصومات
                        $net_balance = $sup['total_purchases'] - $sup['total_payments'] - $sup['returns_discounts'];
                    ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 12px 15px; font-weight: 600; color: #333;"><?php echo htmlspecialchars($sup['supplier_name']); ?></td>
                            <td style="padding: 12px 15px; color: #555; font-family: monospace;"><?php echo htmlspecialchars($sup['phone'] ?: 'غير متوفر'); ?></td>
                            <td style="padding: 12px 15px; font-weight: bold; color: #4e73df;"><?php echo htmlspecialchars($sup['currency']); ?></td>
                            <td style="padding: 12px 15px; color: #666; font-size: 13px;"><?php echo htmlspecialchars($sup['payment_terms']); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #e74a3b; font-weight: bold;">$<?php echo number_format($sup['total_purchases'], 2); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #1cc88a; font-weight: bold;">$<?php echo number_format($sup['total_payments'], 2); ?></td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #f6c23e; font-weight: bold;">$<?php echo number_format($sup['returns_discounts'], 2); ?></td>
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
                        <td colspan="9" style="padding: 30px; text-align: center; color: #777;">لا توجد أي موردين مسجلين حالياً.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
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