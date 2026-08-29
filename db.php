<?php
$host = 'localhost';
$db_name = 'my_new_erp_db';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $exception) {
    // تسجيل التفاصيل الكاملة في سجل الخادم فقط (غير مرئية للمستخدم)
    error_log("DB Connection Failed: " . $exception->getMessage());
    // رسالة عامة آمنة للمستخدم النهائي
    die("عذراً، حدث خطأ في الاتصال بالنظام. يرجى المحاولة لاحقاً أو التواصل مع الدعم الفني.");
}
?>