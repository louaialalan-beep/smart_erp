<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/system_helpers.php';
ensureUsersTable($conn);

$error = "";

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verifyCsrfToken();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        logAudit($conn, 'LOGIN', 'تسجيل الدخول', "تسجيل دخول ناجح للمستخدم: " . $user['full_name']);
        header("Location: index.php");
        exit;
    } else {
        $error = "اسم المستخدم أو كلمة المرور غير صحيحة.";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تسجيل الدخول - Smart ERP</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background: #f8f9fc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-box { background: white; padding: 35px; border-radius: 10px; box-shadow: 0 0.5rem 2rem rgba(0,0,0,0.1); width: 350px; }
        h2 { color: #4e73df; text-align: center; margin-top: 0; }
        input { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #4e73df; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; }
        .hint { font-size: 12px; color: #888; text-align: center; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Smart ERP</h2>
        <?php if (isset($_GET['wiped'])): ?><div style="background:#d4edda; color:#155724; padding:10px; border-radius:6px; margin-bottom:15px; font-size:13px;">تم تصفير النظام بالكامل بنجاح. سجِّل الدخول بحساب المدير الافتراضي أدناه.</div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="POST">
<?php csrfField(); ?>
            <input type="text" name="username" placeholder="اسم المستخدم" required autofocus>
            <input type="password" name="password" placeholder="كلمة المرور" required>
            <button type="submit">دخول</button>
        </form>
        <p class="hint">أول تشغيل: admin / admin123 — غيّر كلمة المرور فوراً من إدارة المستخدمين.</p>
    </div>
</body>
</html>