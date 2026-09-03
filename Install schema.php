<?php
/**
 * أداة تثبيت شاملة لكل جداول النظام وشجرة الحسابات الأساسية — Smart ERP
 * تُنشئ كل جدول عبر CREATE TABLE IF NOT EXISTS: آمنة تماماً على قاعدة بيانات تحتوي بيانات فعلاً
 * (لن تحذف أو تُفرِّغ أي جدول موجود)، ومفيدة أيضاً لتثبيت النظام من الصفر على خادم جديد.
 * تُعبِّئ أيضاً شجرة الحسابات الأساسية بالكود والنوع الصحيحين تلقائياً بعد إنشاء الجداول (بنفس منطق
 * زر "إنشاء الحسابات الأساسية تلقائياً" في accounts.php)، فلا يبقى جدول accounts فارغاً بعد التثبيت.
 * ملاحظة مهمة: هذا الملف يُعيد بناء البنية والشجرة الأساسية فقط — لا يستعيد أي بيانات فعلية (فواتير،
 * قيود، أرصدة...). لاستعادة كاملة بلا فقدان بيانات بعد عطل أو تلف، استخدم النسخة الاحتياطية الحقيقية
 * (زر "نسخة احتياطية" في advanced_features.php) بدل هذا الملف.
 * مستقلة عمداً عن header.php (لا تتطلب تسجيل دخول) لأنها قد تُشغَّل قبل وجود جدول users أصلاً.
 * يُنصَح بحذفها أو حماية الوصول إليها بعد الاستخدام الأول على خادم إنتاج فعلي.
 */
require_once __DIR__ . '/db.php';

$msg = "";
$error = "";
$results = [];

// كل جداول النظام مجمَّعة من كل الوحدات (بعضها كان يُنشأ سابقاً بشكل مبعثر داخل كل ملف على حدة)
$schema = [

// ============================================================
// 1) المستخدمون والصلاحيات
// ============================================================
'users' => "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role ENUM('admin','accountant','viewer') NOT NULL DEFAULT 'viewer',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

// ============================================================
// 2) النواة المحاسبية
// ============================================================
'accounts' => "CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_code VARCHAR(30) UNIQUE,
    account_name VARCHAR(150) NOT NULL,
    account_type ENUM('Asset','Liability','Equity','Revenue','Expense') NULL,
    parent_id INT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'journal_entries' => "CREATE TABLE IF NOT EXISTS journal_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_number VARCHAR(100),
    reference VARCHAR(100),
    entry_date DATE NOT NULL,
    description TEXT,
    currency_code VARCHAR(10) DEFAULT 'SYP',
    exchange_rate DECIMAL(15,6) DEFAULT 1,
    foreign_debit DECIMAL(18,4) DEFAULT 0,
    foreign_credit DECIMAL(18,4) DEFAULT 0,
    account_id INT NOT NULL,
    debit DECIMAL(18,2) DEFAULT 0,
    credit DECIMAL(18,2) DEFAULT 0,
    source_module VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entry_number (entry_number),
    INDEX idx_account_id (account_id),
    INDEX idx_entry_date (entry_date)
)",

'financial_periods' => "CREATE TABLE IF NOT EXISTS financial_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('open','closed') DEFAULT 'open',
    closed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'audit_logs' => "CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(100) DEFAULT 'مدير النظام',
    action_type VARCHAR(50) NOT NULL,
    module_name VARCHAR(100) NOT NULL,
    record_id INT DEFAULT 0,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at)
)",

'custom_fields' => "CREATE TABLE IF NOT EXISTS custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_name VARCHAR(50) NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    field_label VARCHAR(150) NOT NULL,
    field_type VARCHAR(50) DEFAULT 'text',
    is_required TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'system_backups' => "CREATE TABLE IF NOT EXISTS system_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    file_size VARCHAR(50),
    backup_type VARCHAR(50) DEFAULT 'automatic',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'system_settings' => "CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
)",

// ============================================================
// 3) العملات وأسعار الصرف
// ============================================================
'currencies' => "CREATE TABLE IF NOT EXISTS currencies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    currency_code VARCHAR(10) NOT NULL UNIQUE,
    currency_name VARCHAR(100) NOT NULL,
    is_base TINYINT(1) DEFAULT 0,
    status TINYINT(1) DEFAULT 1
)",

'exchange_rates' => "CREATE TABLE IF NOT EXISTS exchange_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    currency_code VARCHAR(10) NOT NULL,
    rate_date DATE NOT NULL,
    exchange_rate DECIMAL(15,6) NOT NULL,
    INDEX idx_currency_date (currency_code, rate_date)
)",

// ============================================================
// 4) المخزون والمنتجات والموردون
// ============================================================
'categories' => "CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(150) NOT NULL
)",

'suppliers' => "CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL,
    phone VARCHAR(50),
    currency VARCHAR(10) DEFAULT 'USD',
    payment_terms VARCHAR(150),
    returns_discounts DECIMAL(15,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'supplier_payments' => "CREATE TABLE IF NOT EXISTS supplier_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    amount_usd DECIMAL(15,2) NOT NULL,
    payment_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_supplier_id (supplier_id)
)",

'products' => "CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(200) NOT NULL,
    sku VARCHAR(100) NOT NULL UNIQUE,
    category_id INT NULL,
    cost_price_usd DECIMAL(15,4) DEFAULT 0,
    wholesale_price_syp DECIMAL(15,2) DEFAULT 0,
    retail_price_syp DECIMAL(15,2) DEFAULT 0,
    special_price_syp DECIMAL(15,2) DEFAULT 0,
    base_unit VARCHAR(50) DEFAULT 'قطعة',
    packing_unit VARCHAR(100),
    current_quantity DECIMAL(15,4) DEFAULT 0,
    purchased_quantity DECIMAL(15,4) DEFAULT 0,
    supplier_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_supplier_id (supplier_id),
    INDEX idx_category_id (category_id)
)",

'purchase_invoices' => "CREATE TABLE IF NOT EXISTS purchase_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL,
    supplier_id INT NOT NULL,
    exchange_rate DECIMAL(15,4) DEFAULT 1,
    total_amount_usd DECIMAL(15,2) DEFAULT 0,
    payment_status ENUM('Paid','Unpaid') NOT NULL DEFAULT 'Unpaid',
    invoice_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_supplier_id (supplier_id)
)",

'purchase_invoice_items' => "CREATE TABLE IF NOT EXISTS purchase_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_invoice_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    unit_cost_usd DECIMAL(15,4) NOT NULL,
    total_cost_usd DECIMAL(15,2) NOT NULL,
    INDEX idx_purchase_invoice_id (purchase_invoice_id),
    INDEX idx_product_id (product_id)
)",

'purchase_returns' => "CREATE TABLE IF NOT EXISTS purchase_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_invoice_id INT NOT NULL,
    return_date DATE NOT NULL,
    total_amount_usd DECIMAL(15,2) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'purchase_return_items' => "CREATE TABLE IF NOT EXISTS purchase_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_return_id INT NOT NULL,
    purchase_invoice_item_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    unit_cost_usd DECIMAL(15,4) NOT NULL,
    total_cost_usd DECIMAL(15,2) NOT NULL
)",

// ============================================================
// 5) المبيعات والمندوبون
// ============================================================
'representatives' => "CREATE TABLE IF NOT EXISTS representatives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(50),
    email VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'representative_payments' => "CREATE TABLE IF NOT EXISTS representative_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    representative_id INT NOT NULL,
    amount_syp DECIMAL(15,2) NOT NULL,
    notes TEXT,
    payment_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_representative_id (representative_id)
)",

'representative_transactions' => "CREATE TABLE IF NOT EXISTS representative_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    representative_id INT NOT NULL,
    transaction_type VARCHAR(50) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    notes TEXT,
    transaction_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_representative_id (representative_id)
)",

'sales' => "CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    customer_name VARCHAR(150) NOT NULL,
    representative_id INT NULL,
    exchange_rate DECIMAL(15,4) DEFAULT 1,
    total_amount_syp DECIMAL(15,2) DEFAULT 0,
    total_amount_usd DECIMAL(15,2) DEFAULT 0,
    total_commissions DECIMAL(15,2) DEFAULT 0,
    payment_status ENUM('Paid','Unpaid','Partial') DEFAULT 'Unpaid',
    delivery_status ENUM('Delivered','Pending','Deferred') DEFAULT 'Pending',
    invoice_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_representative_id (representative_id),
    INDEX idx_invoice_date (invoice_date),
    INDEX idx_delivery_status (delivery_status)
)",

'sale_items' => "CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    unit_price_syp DECIMAL(15,2) NOT NULL,
    total_price_syp DECIMAL(15,2) NOT NULL,
    cost_price_usd_at_sale DECIMAL(15,4) NULL,
    commission_per_unit DECIMAL(15,4) NULL,
    INDEX idx_sale_id (sale_id),
    INDEX idx_product_id (product_id)
)",

'sales_returns' => "CREATE TABLE IF NOT EXISTS sales_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    return_date DATE NOT NULL,
    total_amount_syp DECIMAL(15,2) NOT NULL,
    total_commission_reversed DECIMAL(15,2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sale_id (sale_id)
)",

'sales_return_items' => "CREATE TABLE IF NOT EXISTS sales_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sales_return_id INT NOT NULL,
    sale_item_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    unit_price_syp DECIMAL(15,2) NOT NULL,
    total_price_syp DECIMAL(15,2) NOT NULL,
    INDEX idx_sale_item_id (sale_item_id)
)",

// ============================================================
// 6) الموارد البشرية والرواتب
// ============================================================
'employees' => "CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(50) UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    national_id VARCHAR(50) UNIQUE,
    gender ENUM('male','female') DEFAULT 'male',
    date_of_birth DATE NULL,
    nationality VARCHAR(100),
    marital_status VARCHAR(30),
    department VARCHAR(100),
    position VARCHAR(100),
    employment_type VARCHAR(30) DEFAULT 'full_time',
    hire_date DATE NULL,
    probation_end_date DATE NULL,
    status VARCHAR(30) DEFAULT 'active',
    base_salary DECIMAL(15,2) DEFAULT 0,
    payment_method VARCHAR(30) DEFAULT 'bank_transfer',
    bank_name VARCHAR(150),
    bank_account VARCHAR(100),
    iban VARCHAR(100),
    phone VARCHAR(50),
    work_email VARCHAR(150),
    address TEXT,
    emergency_contact_name VARCHAR(150),
    emergency_contact_phone VARCHAR(50),
    emergency_relation VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'employee_advances' => "CREATE TABLE IF NOT EXISTS employee_advances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL,
    installments_count INT NOT NULL,
    monthly_installment DECIMAL(15,2) NOT NULL,
    start_month VARCHAR(7) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee_id (employee_id)
)",

'advance_installments' => "CREATE TABLE IF NOT EXISTS advance_installments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    advance_id INT NOT NULL,
    employee_id INT NOT NULL,
    installment_month VARCHAR(7) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    is_paid TINYINT(1) DEFAULT 0,
    INDEX idx_advance_id (advance_id),
    INDEX idx_employee_month (employee_id, installment_month)
)",

'employee_variable_items' => "CREATE TABLE IF NOT EXISTS employee_variable_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    item_type ENUM('penalty','bonus') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    target_month VARCHAR(7) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee_month (employee_id, target_month)
)",

'payroll_runs' => "CREATE TABLE IF NOT EXISTS payroll_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    salary_month VARCHAR(7) NOT NULL UNIQUE,
    total_payroll_amount DECIMAL(15,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'payroll_details' => "CREATE TABLE IF NOT EXISTS payroll_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id INT NOT NULL,
    employee_id INT NOT NULL,
    base_salary DECIMAL(15,2) DEFAULT 0,
    deducted_advances DECIMAL(15,2) DEFAULT 0,
    penalties DECIMAL(15,2) DEFAULT 0,
    bonuses DECIMAL(15,2) DEFAULT 0,
    net_salary DECIMAL(15,2) DEFAULT 0,
    INDEX idx_payroll_run_id (payroll_run_id),
    INDEX idx_employee_id (employee_id)
)",

// ============================================================
// 7) المصاريف التشغيلية
// ============================================================
'operational_expenses' => "CREATE TABLE IF NOT EXISTS operational_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(150) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    cost_center VARCHAR(150),
    expense_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expense_date (expense_date)
)",

'recurring_expense_templates' => "CREATE TABLE IF NOT EXISTS recurring_expense_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    cost_center VARCHAR(100),
    monthly_amount DECIMAL(15,2) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'expense_accruals' => "CREATE TABLE IF NOT EXISTS expense_accruals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    accrual_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_template_day (template_id, accrual_date)
)",

];

// ============================================================
// شجرة الحسابات الأساسية — نفس القائمة الحرفية المستخدمة في accounts.php (زر "إنشاء الحسابات
// الأساسية تلقائياً"). كانت هذه الشجرة تُعبَّأ فقط عبر تلك الصفحة كخطوة يدوية منفصلة بعد التثبيت؛
// أُدرجت هنا أيضاً حتى يُعيد هذا الملف بناء الشجرة بكودها ونوعها الصحيحين تلقائياً ضمن خطوة واحدة،
// بدل ترك جدول accounts فارغاً وتعريض النظام لنفس مشكلة "الحسابات المُنشأة تلقائياً بلا نوع أو كود"
// عند أول عملية بيع/شراء تُشغَّل بعد التثبيت مباشرة.
$account_seed_list = [
    ['name' => 'الصندوق الرئيسي',              'type' => 'Asset',     'code' => '1111'],
    ['name' => 'ذمم العملاء',                   'type' => 'Asset',     'code' => '1121'],
    ['name' => 'سلف الموظفين',                  'type' => 'Asset',     'code' => '1131'],
    ['name' => 'المخزون',                       'type' => 'Asset',     'code' => '1141'],
    ['name' => 'إيرادات مؤجلة',                  'type' => 'Liability', 'code' => '2141'],
    ['name' => 'إيرادات المبيعات',              'type' => 'Revenue',   'code' => '4111'],
    ['name' => 'عمولات المندوبين المستحقة',     'type' => 'Liability', 'code' => '2111'],
    ['name' => 'ذمم الموردين',                  'type' => 'Liability', 'code' => '2121'],
    ['name' => 'مصروفات مستحقة الدفع',          'type' => 'Liability', 'code' => '2131'],
    ['name' => 'الرواتب والأجور',                'type' => 'Expense',   'code' => '5111'],
    ['name' => 'مصروف حوافز ومكافآت الموظفين',   'type' => 'Expense',   'code' => '5112'],
    ['name' => 'جزاءات وخصومات الموظفين',        'type' => 'Revenue',   'code' => '4121'],
    ['name' => 'تكلفة البضائع المباعة (COGS)',  'type' => 'Expense',   'code' => '5121'],
    ['name' => 'مصروف عمولات المندوبين',        'type' => 'Expense',   'code' => '5131'],
];

function installGetNextAvailableCode($conn, $preferred_code) {
    $code = $preferred_code;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
    for ($i = 0; $i < 50; $i++) {
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() == 0) { return $code; }
        $code = (string)(intval($code) + 1);
    }
    return $preferred_code . '-' . substr(uniqid(), -4);
}

// ============================================================
// التنفيذ: إنشاء كل جدول، مع تسجيل النتيجة لكل واحد على حدة
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['run_install'])) {
    foreach ($schema as $table_name => $ddl) {
        try {
            $existed_before = in_array($table_name, $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
            $conn->exec($ddl);
            $results[$table_name] = $existed_before ? 'موجود مسبقاً (لم يُغيَّر)' : 'أُنشئ الآن ✓';
        } catch (Exception $e) {
            $results[$table_name] = 'خطأ: ' . $e->getMessage();
        }
    }
    $msg = "تم تنفيذ فحص/إنشاء " . count($schema) . " جدولاً.";

    // تعبئة/إكمال شجرة الحسابات الأساسية تلقائياً — بنفس منطق زر "إنشاء الحسابات الأساسية تلقائياً"
    // في accounts.php حرفياً، حتى لا يبقى جدول accounts فارغاً بعد هذا التثبيت.
    $accounts_created = 0; $accounts_completed = 0; $accounts_skipped = 0;
    try {
        foreach ($account_seed_list as $item) {
            $stmt_find = $conn->prepare("SELECT id, account_code, account_type FROM accounts WHERE account_name = ? LIMIT 1");
            $stmt_find->execute([$item['name']]);
            $existing = $stmt_find->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if (empty($existing['account_code']) || empty($existing['account_type'])) {
                    $code = !empty($existing['account_code']) ? $existing['account_code'] : installGetNextAvailableCode($conn, $item['code']);
                    $type = !empty($existing['account_type']) ? $existing['account_type'] : $item['type'];
                    $conn->prepare("UPDATE accounts SET account_code = ?, account_type = ? WHERE id = ?")->execute([$code, $type, $existing['id']]);
                    $accounts_completed++;
                } else {
                    $accounts_skipped++;
                }
            } else {
                $code = installGetNextAvailableCode($conn, $item['code']);
                $conn->prepare("INSERT INTO accounts (account_code, account_name, account_type) VALUES (?, ?, ?)")->execute([$code, $item['name'], $item['type']]);
                $accounts_created++;
            }
        }
        $msg .= " كما تمت تعبئة شجرة الحسابات: إنشاء {$accounts_created} حساب جديد، إكمال {$accounts_completed} حساب ناقص، وتخطي {$accounts_skipped} حساب مكتمل مسبقاً.";
    } catch (Exception $e) {
        $msg .= " (تعذّرت تعبئة شجرة الحسابات: " . htmlspecialchars($e->getMessage()) . " — يمكن تشغيلها يدوياً لاحقاً من صفحة شجرة الحسابات)";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تثبيت شامل لقاعدة البيانات - Smart ERP</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background: #f4f6f9; padding: 30px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h2 { color: #4e73df; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        th, td { padding: 8px 12px; text-align: right; border-bottom: 1px solid #eee; }
        th { background: #f8f9fc; }
        button { background: #1cc88a; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 15px; }
        .warn { background: #fff3cd; color: #856404; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .ok { color: #1cc88a; font-weight: bold; }
        .existed { color: #888; }
        .err { color: #e74a3b; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <h2>🛠 تثبيت شامل لكل جداول النظام وشجرة الحسابات (<?php echo count($schema); ?> جدولاً + <?php echo count($account_seed_list); ?> حساباً أساسياً)</h2>
    <div class="warn">
        ⚠ هذه الأداة <strong>آمنة على البيانات الموجودة</strong> — تستخدم <code>CREATE TABLE IF NOT EXISTS</code>
        فلا تحذف ولا تُفرِّغ أي جدول موجود فعلاً بأي بيانات، وتُكمل فقط الحسابات الأساسية الناقصة (بلا كود
        أو نوع) دون تكرارها إن كانت موجودة ومكتملة أصلاً. تُنشئ فقط الجداول والحسابات <strong>الغائبة</strong>.
        <br>هذا الملف يُعيد <strong>البنية والشجرة الأساسية فقط</strong> — لا يستعيد أي بيانات (فواتير، قيود،
        أرصدة). للاستعادة الكاملة بلا فقدان بيانات، استخدم زر "نسخة احتياطية" في الميزات المتقدمة بدل هذا الملف.
        يُنصَح بحذف هذا الملف من الخادم بعد الاستخدام الأول.
    </div>

    <?php if ($msg): ?><div style="background:#d4edda; color:#155724; padding:12px; border-radius:6px; margin-bottom:15px;"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <?php if (!empty($results)): ?>
        <table>
            <thead><tr><th>الجدول</th><th>النتيجة</th></tr></thead>
            <tbody>
                <?php foreach ($results as $table => $status): ?>
                    <tr>
                        <td style="font-family:monospace;"><?php echo htmlspecialchars($table); ?></td>
                        <td class="<?php echo str_contains($status, 'خطأ') ? 'err' : (str_contains($status, 'أُنشئ') ? 'ok' : 'existed'); ?>">
                            <?php echo htmlspecialchars($status); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>سيتم فحص/إنشاء الجداول التالية:</p>
        <ul style="columns: 3; font-size: 13px; font-family: monospace; color: #555;">
            <?php foreach (array_keys($schema) as $t): ?><li><?php echo htmlspecialchars($t); ?></li><?php endforeach; ?>
        </ul>
        <p>وتعبئة/إكمال شجرة الحسابات الأساسية التالية (بكودها ونوعها الصحيحين):</p>
        <ul style="columns: 3; font-size: 13px; color: #555;">
            <?php foreach ($account_seed_list as $acc): ?><li><?php echo htmlspecialchars($acc['code'] . ' - ' . $acc['name']); ?></li><?php endforeach; ?>
        </ul>
        <form method="POST">
            <input type="hidden" name="run_install" value="1">
            <button type="submit">تشغيل التثبيت الشامل الآن</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>