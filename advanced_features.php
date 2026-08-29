<?php
/**
 * الميزات التقنية والهندسية المتقدمة - Smart ERP
 * (سجل التدقيق، الحقول المخصصة، الزمن التاريخي وإغلاق الفترات، النسخ الاحتياطي الحقيقي)
 */
session_start();
ob_start(); // تخزين الإخراج مؤقتاً — ضروري لأن تحميل النسخة الاحتياطية يحتاج إرسال header() بعد أن يكون header.php قد طبع HTML مسبقاً
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

if (!isset($conn)) {
    die("خطأ: اتصال قاعدة البيانات غير متوفر.");
}

$success_msg = '';
$error_msg = '';

// ملاحظة: isDateInClosedPeriod() و logAudit() و getCurrentUserName() لم تعد مُعرَّفة هنا محلياً —
// أصبحت موحّدة في includes/system_helpers.php ومُستخدمة من هذا الملف وكل الملفات الستة الأخرى
// (sales.php, supplier_view.php, representative_profile.php, expenses.php, hr_payroll_advanced.php, accounts.php)

// 1. إنشاء الجداول التلقائية اللازمة للميزات المتقدمة إن لم تكن موجودة
try {
    // جدول سجل التدقيق الشامل
    $conn->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_name VARCHAR(100) DEFAULT 'مدير النظام',
        action_type VARCHAR(50) NOT NULL, -- (INSERT, UPDATE, DELETE, LOGIN)
        module_name VARCHAR(100) NOT NULL,
        record_id INT DEFAULT 0,
        details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // جدول الحقول المخصصة المرنة
    $conn->exec("CREATE TABLE IF NOT EXISTS custom_fields (
        id INT AUTO_INCREMENT PRIMARY KEY,
        module_name VARCHAR(50) NOT NULL,
        field_name VARCHAR(100) NOT NULL,
        field_label VARCHAR(150) NOT NULL,
        field_type VARCHAR(50) DEFAULT 'text',
        is_required TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // جدول الفترات المالية والزمن التاريخي (إغلاق الفترات)
    $conn->exec("CREATE TABLE IF NOT EXISTS financial_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        period_name VARCHAR(100) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status ENUM('open', 'closed') DEFAULT 'open',
        closed_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // جدول سجل النسخ الاحتياطي
    $conn->exec("CREATE TABLE IF NOT EXISTS system_backups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL,
        file_size VARCHAR(50),
        backup_type VARCHAR(50) DEFAULT 'automatic',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // تجاهل الأخطاء البسيطة في حال الصلاحيات
}

// 2. معالجة الطلبات (إضافة حقل مخصص، إضافة/إغلاق فترة مالية، أو إنشاء نسخة احتياطية حقيقية)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_custom_field') {
        $module = trim($_POST['module_name']);
        $name   = trim($_POST['field_name']);
        $label  = trim($_POST['field_label']);
        $type   = $_POST['field_type'];
        $req    = isset($_POST['is_required']) ? 1 : 0;

        if (!empty($module) && !empty($name) && !empty($label)) {
            try {
                $stmt = $conn->prepare("INSERT INTO custom_fields (module_name, field_name, field_label, field_type, is_required) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$module, $name, $label, $type, $req]);
                
                // تسجيل في سجل التدقيق
                logAudit($conn, 'INSERT', 'Custom Fields', "إضافة حقل مخصص جديد باسم: $label في موديول $module");

                $success_msg = "تم إضافة الحقل المخصص بنجاح! (تنبيه: هذا الحقل لن يظهر فعلياً في نماذج الموديول المستهدف إلا بعد ربطه برمجياً هناك.)";
            } catch (Exception $e) {
                $error_msg = "خطأ أثناء إضافة الحقل: " . $e->getMessage();
            }
        } else {
            $error_msg = "يرجى تعبئة كافة الحقول المطلوبة للحقل المخصص.";
        }
    }

    // إضافة فترة مالية جديدة (كانت غائبة تماماً؛ الجدول كان يبقى فارغاً للأبد بدونها)
    if (isset($_POST['action']) && $_POST['action'] === 'add_period') {
        $period_name = trim($_POST['period_name']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];

        if (!empty($period_name) && !empty($start_date) && !empty($end_date) && $start_date <= $end_date) {
            try {
                $stmt = $conn->prepare("INSERT INTO financial_periods (period_name, start_date, end_date, status) VALUES (?, ?, ?, 'open')");
                $stmt->execute([$period_name, $start_date, $end_date]);

                $log = $conn->lastInsertId();
                logAudit($conn, 'INSERT', 'Financial Periods', "إنشاء فترة مالية جديدة: $period_name ($start_date إلى $end_date)", $log);

                $success_msg = "تم إنشاء الفترة المالية بنجاح.";
            } catch (Exception $e) {
                $error_msg = "خطأ أثناء إنشاء الفترة المالية: " . $e->getMessage();
            }
        } else {
            $error_msg = "يرجى إدخال اسم الفترة وتاريخ بداية أصغر من أو يساوي تاريخ النهاية.";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'toggle_period') {
        requireRole($conn, ['admin']);
        $period_id = intval($_POST['period_id']);
        $new_status = $_POST['new_status'];
        try {
            $stmt = $conn->prepare("UPDATE financial_periods SET status = ?, closed_at = " . ($new_status == 'closed' ? 'NOW()' : 'NULL') . " WHERE id = ?");
            $stmt->execute([$new_status, $period_id]);

            if ($stmt->rowCount() > 0) {
                logAudit($conn, 'UPDATE', 'Financial Periods', ($new_status == 'closed' ? "إغلاق" : "فتح") . " الفترة المالية رقم #$period_id", $period_id);
                $success_msg = "تم تحديث حالة الفترة المالية بنجاح.";
            } else {
                $error_msg = "لم يتم العثور على هذه الفترة المالية لتحديثها.";
            }
        } catch (Exception $e) {
            $error_msg = "خطأ في تحديث الفترة المالية.";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'create_backup') {
        requireRole($conn, ['admin']);
        // توليد نسخة احتياطية حقيقية لقاعدة البيانات وتصديرها كملف SQL
        $backup_filename = "vnrd_erp_backup_" . date('Y-m-d_H-i-s') . ".sql";
        $sql_content = "-- VNRD Smart ERP Full Database Backup\n";
        $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        try {
            $tables = [];
            $stmt = $conn->query("SHOW TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            
            foreach ($tables as $table) {
                // استخراج هيكل الجدول (DDL)
                $res = $conn->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
                $sql_content .= "\n\n" . $res[1] . ";\n\n";
                
                // استخراج بيانات الجدول (DML)
                $rows_stmt = $conn->query("SELECT * FROM `$table`");
                while ($row = $rows_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $keys = array_keys($row);
                    $values = array_values($row);
                    $escaped_values = array_map(function($val) use ($conn) {
                        return is_null($val) ? 'NULL' : $conn->quote($val);
                    }, $values);
                    
                    $sql_content .= "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $escaped_values) . ");\n";
                }
            }
            
            $bytes = strlen($sql_content);
            $file_size = ($bytes >= 1048576) ? round($bytes / 1048576, 2) . " MB" : round($bytes / 1024, 2) . " KB";
            
            // تسجيل العملية في جدول السجلات
            $log_stmt = $conn->prepare("INSERT INTO system_backups (file_name, file_size, backup_type) VALUES (?, ?, 'manual')");
            $log_stmt->execute([$backup_filename, $file_size]);

            logAudit($conn, 'INSERT', 'System Backup', "إنشاء نسخة احتياطية يدوية: $backup_filename ($file_size)");

            // تصحيح: تفريغ أي إخراج مخزَّن من header.php قبل إرسال ترويسات التحميل، وإلا يفشل التحميل
            // أو يُلحق كود HTML بملف الـ SQL (نفس مشكلة headers already sent التي عولجت سابقاً)
            ob_end_clean();
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $backup_filename . '"');
            header('Content-Length: ' . strlen($sql_content));
            echo $sql_content;
            exit;

        } catch (Exception $e) {
            $error_msg = "خطأ في إنشاء النسخة الاحتياطية الحقيقية: " . $e->getMessage();
        }
    }
}

// 3. جلب البيانات للعرض
$audit_logs = [];
$custom_fields = [];
$periods = [];
$backups = [];

try {
    $audit_logs = $conn->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    $custom_fields = $conn->query("SELECT * FROM custom_fields ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $periods = $conn->query("SELECT * FROM financial_periods ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
    $backups = $conn->query("SELECT * FROM system_backups ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="color: #2e384d; margin-bottom: 5px;"><i class="fas fa-cogs"></i> الميزات التقنية والهندسية المتقدمة</h2>
        <p style="color: #6c757d; margin: 0; font-size: 14px;">سجل التدقيق الشامل، الحقول المخصصة، الزمن التاريخي، والنسخ الاحتياطي الفعلي.</p>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_msg); ?>
    </div>
<?php endif; ?>

<div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; color: #0c5460; font-size: 13px;">
    <i class="fas fa-info-circle"></i> <strong>تنبيه توافق:</strong> إغلاق فترة مالية هنا يُسجِّل حالتها فقط. منع التعديل الفعلي على الفواتير/المصاريف الواقعة ضمن فترة مغلقة يحتاج ربطاً إضافياً في كل شاشة إدخال (المبيعات، المصاريف، الرواتب...) لم يُضَف بعد.
</div>

<!-- شبكة الأقسام المتقدمة -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
    
    <!-- 1. الحقول المخصصة المرنة (Custom Fields) -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: #4e73df; font-size: 16px;"><i class="fas fa-puzzle-piece"></i> الحقول المخصصة المرنة</h3>
            <span style="font-size: 11px; color: #856404; background: #fff3cd; padding: 3px 8px; border-radius: 4px;" title="التعريف هنا لا يعرضها تلقائياً في نماذج الموديول">تعريف فقط، غير مربوطة بعد</span>
        </div>
        <div style="padding: 20px;">
            <form method="POST" action="">
<?php csrfField(); ?>
                <input type="hidden" name="action" value="add_custom_field">
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: bold; margin-bottom: 5px;">الموديول المستهدف:</label>
                    <select name="module_name" style="width: 100%; padding: 8px; border: 1px solid #d1d3e2; border-radius: 6px;">
                        <option value="sales">المبيعات والفواتير (Sales)</option>
                        <option value="customers">العملاء (Customers)</option>
                        <option value="suppliers">الموردين (Suppliers)</option>
                        <option value="products">المنتجات والمستودعات (Products)</option>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: bold; margin-bottom: 5px;">اسم الحقل البرمجي (Key):</label>
                        <input type="text" name="field_name" placeholder="e.g. tax_number" required style="width: 100%; padding: 7px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: bold; margin-bottom: 5px;">عنوان الحقل (Label):</label>
                        <input type="text" name="field_label" placeholder="الرقم الضريبي" required style="width: 100%; padding: 7px; border: 1px solid #d1d3e2; border-radius: 6px;">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: bold; margin-bottom: 5px;">نوع الحقل:</label>
                        <select name="field_type" style="width: 100%; padding: 8px; border: 1px solid #d1d3e2; border-radius: 6px;">
                            <option value="text">نص (Text)</option>
                            <option value="number">رقمي (Number)</option>
                            <option value="date">تاريخ (Date)</option>
                            <option value="select">قائمة منسدلة (Select)</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; padding-top: 25px;">
                        <label style="font-size: 13px; font-weight: bold; cursor: pointer;">
                            <input type="checkbox" name="is_required" value="1"> حقل إلزامي (Required)
                        </label>
                    </div>
                </div>
                <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">
                    <i class="fas fa-plus"></i> إضافة الحقل المخصص
                </button>
            </form>

            <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
            
            <h4 style="font-size: 14px; color: #333; margin-bottom: 10px;">الحقول المخصصة المعرَّفة حالياً:</h4>
            <div style="max-height: 150px; overflow-y: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: right;">
                    <thead>
                        <tr style="background: #fdfdfe; border-bottom: 1px solid #ddd;">
                            <th style="padding: 6px;">الموديول</th>
                            <th style="padding: 6px;">العنوان</th>
                            <th style="padding: 6px;">النوع</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($custom_fields)): ?>
                            <?php foreach ($custom_fields as $cf): ?>
                                <tr style="border-bottom: 1px solid #f1f1f1;">
                                    <td style="padding: 6px; color: #555;"><?php echo htmlspecialchars($cf['module_name']); ?></td>
                                    <td style="padding: 6px; font-weight: bold;"><?php echo htmlspecialchars($cf['field_label']); ?></td>
                                    <td style="padding: 6px; font-family: monospace; color: #007bff;"><?php echo htmlspecialchars($cf['field_type']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="padding: 10px; text-align: center; color: #777;">لا توجد حقول مخصصة مضافة.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 2. الزمن التاريخي وإغلاق الفترات (Historical Navigation & Closing) -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
        <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: #1cc88a; font-size: 16px;"><i class="fas fa-history"></i> الزمن التاريخي وإغلاق الفترات</h3>
            <button type="button" onclick="document.getElementById('addPeriodForm').style.display = document.getElementById('addPeriodForm').style.display === 'none' ? 'block' : 'none';" style="font-size: 11px; background: #1cc88a; color: white; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer;">
                <i class="fas fa-plus"></i> فترة جديدة
            </button>
        </div>
        <div style="padding: 20px;">
            <p style="font-size: 13px; color: #666; margin-top: 0;">إمكانية قفل الأشهُر والسنوات المالية السابقة لتوثيق حالتها (تنبيه: منع التعديل الفعلي يحتاج ربطاً إضافياً — راجع التنبيه أعلى الصفحة).</p>

            <!-- نموذج إضافة فترة مالية جديدة (مخفي افتراضياً) -->
            <form method="POST" id="addPeriodForm" style="display: none; background: #f8f9fc; padding: 12px; border-radius: 6px; margin-bottom: 15px; border: 1px solid #e3e6f0;">
<?php csrfField(); ?>
                <input type="hidden" name="action" value="add_period">
                <div style="margin-bottom: 8px;">
                    <label style="display: block; font-size: 12px; font-weight: bold; margin-bottom: 3px;">اسم الفترة:</label>
                    <input type="text" name="period_name" required placeholder="مثال: يناير 2026" style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: bold; margin-bottom: 3px;">من تاريخ:</label>
                        <input type="date" name="start_date" required style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 12px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: bold; margin-bottom: 3px;">إلى تاريخ:</label>
                        <input type="date" name="end_date" required style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 12px;">
                    </div>
                </div>
                <button type="submit" style="background: #4e73df; color: white; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">حفظ الفترة</button>
            </form>
            
            <div style="max-height: 250px; overflow-y: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: right;">
                    <thead>
                        <tr style="background: #fdfdfe; border-bottom: 1px solid #ddd;">
                            <th style="padding: 8px;">الفترة المالية</th>
                            <th style="padding: 8px;">من تاريخ</th>
                            <th style="padding: 8px;">إلى تاريخ</th>
                            <th style="padding: 8px; text-align: center;">الحالة</th>
                            <th style="padding: 8px; text-align: center;">الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($periods)): ?>
                            <tr><td colspan="5" style="padding: 15px; text-align: center; color: #777;">لا توجد فترات مالية معرَّفة بعد. اضغط "فترة جديدة" أعلاه لإنشاء أول فترة.</td></tr>
                        <?php else: ?>
                            <?php foreach ($periods as $per): ?>
                                <tr style="border-bottom: 1px solid #f1f1f1;">
                                    <td style="padding: 8px; font-weight: bold;"><?php echo htmlspecialchars($per['period_name']); ?></td>
                                    <td style="padding: 8px; font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($per['start_date']); ?></td>
                                    <td style="padding: 8px; font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($per['end_date']); ?></td>
                                    <td style="padding: 8px; text-align: center;">
                                        <?php if ($per['status'] == 'closed'): ?>
                                            <span style="background: #f8d7da; color: #721c24; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">مغلقة (مؤرشفة)</span>
                                        <?php else: ?>
                                            <span style="background: #d4edda; color: #155724; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">مفتوحة</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 8px; text-align: center;">
                                        <form method="POST" style="display:inline;">
<?php csrfField(); ?>
                                            <input type="hidden" name="action" value="toggle_period">
                                            <input type="hidden" name="period_id" value="<?php echo $per['id']; ?>">
                                            <?php if ($per['status'] == 'closed'): ?>
                                                <input type="hidden" name="new_status" value="open">
                                                <button type="submit" style="background: #ffc107; border: none; padding: 3px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;" title="فتح الفترة">فتح</button>
                                            <?php else: ?>
                                                <input type="hidden" name="new_status" value="closed">
                                                <button type="submit" style="background: #dc3545; color: white; border: none; padding: 3px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;" title="قفل وإغلاق الفترة">قفل</button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- 3. سجل التدقيق الشامل (Audit Trail) -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08); margin-bottom: 30px;">
    <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; color: #e74a3b; font-size: 16px;"><i class="fas fa-shield-alt"></i> سجل التدقيق الشامل (Audit Trail)</h3>
        <span style="font-size: 11px; color: #856404; background: #fff3cd; padding: 3px 8px; border-radius: 4px;" title="حالياً يسجّل فقط عمليات هذه الصفحة (حقول مخصصة، فترات مالية، نسخ احتياطي)">يغطي هذه الصفحة فقط حالياً</span>
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; color: #555; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">المستخدم</th>
                    <th style="padding: 12px 15px;">نوع الحدث</th>
                    <th style="padding: 12px 15px;">الموديول</th>
                    <th style="padding: 12px 15px;">التفاصيل والوصف</th>
                    <th style="padding: 12px 15px; text-align: left;">التاريخ والوقت</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($audit_logs)): ?>
                    <?php foreach ($audit_logs as $log): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-weight: bold; color: #333;"><?php echo htmlspecialchars($log['user_name']); ?></td>
                            <td style="padding: 10px 15px;">
                                <span style="background: #eef1f6; color: #333; padding: 2px 8px; border-radius: 4px; font-family: monospace; font-size: 11px; font-weight: bold;">
                                    <?php echo htmlspecialchars($log['action_type']); ?>
                                </span>
                            </td>
                            <td style="padding: 10px 15px; color: #4e73df; font-weight: bold;"><?php echo htmlspecialchars($log['module_name']); ?></td>
                            <td style="padding: 10px 15px; color: #555;"><?php echo htmlspecialchars($log['details']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; text-align: left; font-size: 12px; color: #888;"><?php echo htmlspecialchars($log['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="padding: 20px; text-align: center; color: #777;">لا توجد أحداث مسجَّلة في سجل التدقيق حتى الآن.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 4. النسخ الاحتياطي التلقائي واليدوي (Automated Backups) -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08); margin-bottom: 30px;">
    <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; color: #f6c23e; font-size: 16px;"><i class="fas fa-database"></i> النسخ الاحتياطي التلقائي واليدوي</h3>
        <form method="POST" style="margin: 0;">
<?php csrfField(); ?>
            <input type="hidden" name="action" value="create_backup">
            <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 7px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">
                <i class="fas fa-download"></i> إنشاء وتنزيل نسخة احتياطية الآن
            </button>
        </form>
    </div>
    <div style="padding: 20px;">
        <p style="font-size: 13px; color: #666; margin-top: 0;">النظام يقوم بتوليد نسخة `.sql` حقيقية لقاعدة البيانات وتحميلها فوراً لجهازك مع تسجيل العملية في الجدول.</p>
        
        <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: right; margin-top: 15px;">
            <thead>
                <tr style="background: #fdfdfe; border-bottom: 1px solid #ddd;">
                    <th style="padding: 8px;">اسم ملف النسخة الاحتياطية</th>
                    <th style="padding: 8px;">حجم الملف</th>
                    <th style="padding: 8px;">نوع النسخة</th>
                    <th style="padding: 8px; text-align: left;">تاريخ الإنشاء والتوقيت</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($backups)): ?>
                    <?php foreach ($backups as $bk): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 8px; font-family: monospace; font-weight: bold; color: #333;"><?php echo htmlspecialchars($bk['file_name']); ?></td>
                            <td style="padding: 8px; color: #555;"><?php echo htmlspecialchars($bk['file_size']); ?></td>
                            <td style="padding: 8px;">
                                <span style="background: #eef1f6; padding: 2px 6px; border-radius: 4px; font-size: 11px;"><?php echo htmlspecialchars($bk['backup_type']); ?></span>
                            </td>
                            <td style="padding: 8px; font-family: monospace; text-align: left; font-size: 12px; color: #888;"><?php echo htmlspecialchars($bk['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="padding: 20px; text-align: center; color: #777;">لم يتم إنشاء أي نسخة احتياطية بعد.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>