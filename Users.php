<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';
ensureUsersTable($conn);

requireRole($conn, ['admin']);

$msg = ""; $error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];

    if (!empty($username) && strlen($password) >= 6 && !empty($full_name)) {
        try {
            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full_name, $role]);
            logAudit($conn, 'INSERT', 'إدارة المستخدمين', "إضافة مستخدم جديد: $full_name ($username) بدور: $role");
            $msg = "تمت إضافة المستخدم بنجاح.";
        } catch (Exception $e) {
            $error = "خطأ: اسم المستخدم موجود مسبقاً أو حدثت مشكلة.";
        }
    } else {
        $error = "يرجى إدخال بيانات صحيحة (كلمة المرور 6 أحرف على الأقل).";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_active'])) {
    $uid = intval($_POST['user_id']);
    $new_status = intval($_POST['new_status']);
    if ($uid === intval($_SESSION['user_id'] ?? 0)) {
        $error = "لا يمكنك تعطيل حسابك الخاص.";
    } else {
        $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$new_status, $uid]);
        logAudit($conn, 'UPDATE', 'إدارة المستخدمين', "تغيير حالة المستخدم #$uid إلى: " . ($new_status ? 'مفعَّل' : 'معطَّل'));
        $msg = "تم تحديث حالة المستخدم.";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $uid = intval($_POST['user_id']);
    $new_password = $_POST['new_password'];
    if (strlen($new_password) >= 6) {
        $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new_password, PASSWORD_DEFAULT), $uid]);
        logAudit($conn, 'UPDATE', 'إدارة المستخدمين', "إعادة تعيين كلمة مرور المستخدم #$uid");
        $msg = "تم تغيير كلمة المرور.";
    } else {
        $error = "كلمة المرور يجب أن تكون 6 أحرف على الأقل.";
    }
}

$users_list = $conn->query("SELECT * FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$role_labels = ['admin' => 'مدير كامل الصلاحيات', 'accountant' => 'محاسب', 'viewer' => 'مستعرض فقط'];
?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2><i class="fas fa-users-cog"></i> إدارة المستخدمين والصلاحيات</h2>
    <button onclick="openModal()" style="background: #4e73df; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold;"><i class="fas fa-plus"></i> مستخدم جديد</button>
</div>

<?php if ($msg): ?><div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<?php if ($error): ?><div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; color: #0c5460; font-size: 13px;">
    <strong>الأدوار:</strong> مدير (كل الصلاحيات، بما فيها حذف الحسابات وإدارة المستخدمين وقفل الفترات) — محاسب (قيود وفواتير ودفعات، بلا حذف حسابات أو إدارة مستخدمين) — مستعرض (عرض فقط).
</div>

<div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden;">
    <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
        <thead>
            <tr style="background: #f8f9fc; border-bottom: 2px solid #e3e6f0;">
                <th style="padding: 10px 15px;">اسم المستخدم</th>
                <th style="padding: 10px 15px;">الاسم الكامل</th>
                <th style="padding: 10px 15px;">الدور</th>
                <th style="padding: 10px 15px; text-align: center;">الحالة</th>
                <th style="padding: 10px 15px; text-align: center;">الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users_list as $u): ?>
                <tr style="border-bottom: 1px solid #f1f1f1;">
                    <td style="padding: 10px 15px; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($u['username']); ?></td>
                    <td style="padding: 10px 15px;"><?php echo htmlspecialchars($u['full_name']); ?></td>
                    <td style="padding: 10px 15px;"><?php echo htmlspecialchars($role_labels[$u['role']] ?? $u['role']); ?></td>
                    <td style="padding: 10px 15px; text-align: center;">
                        <?php if ($u['is_active']): ?><span style="background:#d4edda;color:#155724;padding:3px 8px;border-radius:4px;font-size:11px;">مفعَّل</span>
                        <?php else: ?><span style="background:#f8d7da;color:#721c24;padding:3px 8px;border-radius:4px;font-size:11px;">معطَّل</span><?php endif; ?>
                    </td>
                    <td style="padding: 10px 15px; text-align: center; white-space: nowrap;">
                        <form method="POST" style="display:inline;">
<?php csrfField(); ?>
                            <input type="hidden" name="toggle_active" value="1">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <input type="hidden" name="new_status" value="<?php echo $u['is_active'] ? 0 : 1; ?>">
                            <button type="submit" style="background:#f6c23e;color:white;border:none;padding:4px 10px;border-radius:4px;font-size:11px;cursor:pointer;"><?php echo $u['is_active'] ? 'تعطيل' : 'تفعيل'; ?></button>
                        </form>
                        <button onclick="openResetModal(<?php echo $u['id']; ?>)" style="background:#6c757d;color:white;border:none;padding:4px 10px;border-radius:4px;font-size:11px;cursor:pointer;">كلمة مرور</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="userModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
    <div style="background:white; width:400px; padding:25px; border-radius:8px;">
        <h3 style="margin-top:0;">مستخدم جديد</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="add_user" value="1">
            <div style="margin-bottom:10px;"><label>اسم المستخدم:</label><input type="text" name="username" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;"></div>
            <div style="margin-bottom:10px;"><label>الاسم الكامل:</label><input type="text" name="full_name" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;"></div>
            <div style="margin-bottom:10px;"><label>كلمة المرور:</label><input type="password" name="password" required minlength="6" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;"></div>
            <div style="margin-bottom:15px;"><label>الدور:</label>
                <select name="role" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
                    <option value="viewer">مستعرض فقط</option>
                    <option value="accountant">محاسب</option>
                    <option value="admin">مدير كامل الصلاحيات</option>
                </select>
            </div>
            <div style="text-align:left;"><button type="button" onclick="closeModal()" style="background:none;border:none;padding:8px;cursor:pointer;">إلغاء</button><button type="submit" style="background:#4e73df;color:white;border:none;padding:8px 15px;border-radius:4px;cursor:pointer;">حفظ</button></div>
        </form>
    </div>
</div>

<div id="resetModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
    <div style="background:white; width:350px; padding:25px; border-radius:8px;">
        <h3 style="margin-top:0;">إعادة تعيين كلمة المرور</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="user_id" id="reset_user_id">
            <div style="margin-bottom:15px;"><label>كلمة المرور الجديدة:</label><input type="password" name="new_password" required minlength="6" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;"></div>
            <div style="text-align:left;"><button type="button" onclick="closeResetModal()" style="background:none;border:none;padding:8px;cursor:pointer;">إلغاء</button><button type="submit" style="background:#6c757d;color:white;border:none;padding:8px 15px;border-radius:4px;cursor:pointer;">تغيير</button></div>
        </form>
    </div>
</div>

<script>
    function openModal() { document.getElementById('userModal').style.display = 'flex'; }
    function closeModal() { document.getElementById('userModal').style.display = 'none'; }
    function openResetModal(id) { document.getElementById('reset_user_id').value = id; document.getElementById('resetModal').style.display = 'flex'; }
    function closeResetModal() { document.getElementById('resetModal').style.display = 'none'; }
</script>
<?php include 'footer.php'; ?>