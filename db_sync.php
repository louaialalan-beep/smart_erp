<?php
/**
 * Auto DB & Code Checker Engine
 * يقوم بقراءة الكود ومقارنته بقاعدة البيانات وإنشاء الجداول والحقول الناقصة
 */

$host = 'localhost';
$db   = 'my_new_erp_db'; // اسم قاعدة البيانات الخاصة بك
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// 1. تحديد كافة الجداول المطلوبة وهيكليتها البرمجية
$schemaDefinition = [
    'accounts' => [
        'id' => 'INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'account_code' => 'VARCHAR(50) NOT NULL UNIQUE',
        'account_name' => 'VARCHAR(150) NOT NULL',
        'account_type' => "ENUM('Asset','Liability','Equity','Revenue','Expense') NOT NULL",
        'parent_id' => 'INT(11) DEFAULT NULL',
        'is_active' => 'TINYINT(1) DEFAULT 1',
        'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
    ],
    'users' => [
        'id' => 'INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'username' => 'VARCHAR(100) NOT NULL UNIQUE',
        'password' => 'VARCHAR(255) NOT NULL',
        'email' => 'VARCHAR(150) NULL',
        'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
    ],
    'logs' => [
        'id' => 'INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'user_id' => 'INT(11) NOT NULL',
        'action' => 'VARCHAR(255) NOT NULL',
        'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
    ]
];

// 2. قراءة ملفات الكود ومسحها ضوئياً لاكتشاف أي أسماء جداول مجدولة في الاستعلامات
function scanCodebaseForTables($directory) {
    $foundTables = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());
            // البحث عن عبارات SELECT, INSERT, UPDATE, FROM
            preg_match_all('/(?:FROM|JOIN|INSERT INTO|UPDATE)\s+`?([a-zA-Z0-9_]+)`?/i', $content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $table) {
                    $foundTables[] = strtolower($table);
                }
            }
        }
    }
    return array_unique($foundTables);
}

// 3. فحص قاعدة البيانات وتطبيق التغييرات الناقصة
echo "=== بدء فحص قاعدة البيانات والمشروع ===<br>";

// جلب الجداول الحالية في قاعدة البيانات
$existingTablesQuery = $pdo->query("SHOW TABLES");
$existingTables = $existingTablesQuery->fetchAll(PDO::FETCH_COLUMN);

foreach ($schemaDefinition as $tableName => $columns) {
    // حالة 1: الجدول غير موجود نهائياً -> إنشاؤه
    if (!in_array($tableName, $existingTables)) {
        $columnSql = [];
        foreach ($columns as $colName => $colDef) {
            $columnSql[] = "`$colName` $colDef";
        }
        $sql = "CREATE TABLE `$tableName` (" . implode(', ', $columnSql) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $pdo->exec($sql);
        echo "[+] تم إنشاء الجدول الناقص: <strong>$tableName</strong><br>";
    } else {
        // حالة 2: الجدول موجود -> فحص الحقول الناقصة داخله
        $existingColumnsQuery = $pdo->query("DESCRIBE `$tableName` ");
        $existingColumns = $existingColumnsQuery->fetchAll(PDO::FETCH_COLUMN);

        foreach ($columns as $colName => $colDef) {
            if (!in_array($colName, $existingColumns)) {
                // إزالة PRIMARY KEY أو UNIQUE من التعريف عند إضافة عمود لجدول قائم لتفادي أخطاء السنتكس
                $cleanColDef = preg_replace('/(PRIMARY KEY|UNIQUE)/i', '', $colDef);
                $sql = "ALTER TABLE `$tableName` ADD `$colName` $cleanColDef;";
                $pdo->exec($sql);
                echo "[+] تم إضافة الحقل الناقص <strong>$colName</strong> إلى الجدول <strong>$tableName</strong><br>";
            }
        }
    }
}

echo "=== اكتمل الفحص وتحديث قاعدة البيانات بنجاح ===";
?>