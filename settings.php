<?php
include 'header.php';
$msg = ""; $error = "";

// معالجة تحديث الإعدادات
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_settings'])) {
    $company_name = trim($_POST['company_name']);
    $opening_date = $_POST['opening_date'];
    $contact_info = trim($_POST['contact_info']);
    $system_language = trim($_POST['system_language']);

    try {
        // تحديث البيانات النصية في جدول system_settings
        $conn->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('company_name', ?)")->execute([$company_name]);
        $conn->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('opening_date', ?)")->execute([$opening_date]);
        $conn->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('contact_info', ?)")->execute([$contact_info]);
        $conn->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('system_language', ?)")->execute([$system_language]);
        
        // معالجة رفع الشعار (Logo) إذا قام المستخدم باختيار ملف
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/';
            // إنشاء مجلد uploads تلقائياً إن لم يكن موجوداً
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = $upload_dir . 'logo_' . time() . '.' . $file_extension;
                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $new_filename)) {
                    $conn->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('company_logo', ?)")->execute([$new_filename]);
                }
            } else {
                throw new Exception("صيغة الملف المرفوع غير مسموح بها. يرجى اختيار صورة صحيحة (JPG, PNG, WEBP).");
            }
        }
        
        $msg = "تم تحديث إعدادات النظام والشعار بنجاح!";
    } catch (Exception $e) { 
        $error = "خطأ: " . $e->getMessage(); 
    }
}

$settings_raw = $conn->query("SELECT * FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<div style="margin-bottom: 20px;">
    <h2>إعدادات النظام العامة والهوية</h2>
    <p style="color: #666; margin: 0;">تخصيص بيانات المؤسسة، شعار النظام، التاريخ الافتتاحي، وتفضيلات النظام الأساسية.</p>
</div>

<?php if ($msg): ?><div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $msg; ?></div><?php endif; ?>
<?php if ($error): ?><div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $error; ?></div><?php endif; ?>

<div style="background: white; border: 1px solid #e3e6f0; border-radius: 8px; padding: 25px; max-width: 700px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <!-- ملاحظة هامة: تمت إضافة enctype="multipart/form-data" لتمكين رفع الملفات والصور -->
    <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="update_settings" value="1">

        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">اسم الشركة / المؤسسة:</label>
            <input type="text" name="company_name" value="<?php echo htmlspecialchars($settings_raw['company_name'] ?? ''); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
        </div>

        <!-- حقل رفع الشعار (Logo) ومعاينة الشعار الحالي -->
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">شعار النظام (Logo):</label>
            <input type="file" name="company_logo" accept="image/*" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background: #fdfdfd;">
            <?php if (!empty($settings_raw['company_logo'])): ?>
                <div style="margin-top: 10px; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 12px; color: #666;">الشعار الحالي المعروض:</span>
                    <img src="<?php echo htmlspecialchars($settings_raw['company_logo']); ?>" alt="Company Logo" style="height: 45px; object-fit: contain; border: 1px solid #ddd; padding: 3px; border-radius: 4px; background: #fff;">
                </div>
            <?php endif; ?>
        </div>

        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">التاريخ الافتتاحي للنظام:</label>
            <input type="date" name="opening_date" value="<?php echo htmlspecialchars($settings_raw['opening_date'] ?? ''); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
        </div>

        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">معلومات التواصل وعنوان الشركة:</label>
            <input type="text" name="contact_info" value="<?php echo htmlspecialchars($settings_raw['contact_info'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">لغة النظام الأساسية:</label>
            <select name="system_language" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="العربية" <?php echo (($settings_raw['system_language'] ?? '') == 'العربية') ? 'selected' : ''; ?>>العربية (Arabic)</option>
                <option value="الإنجليزية" <?php echo (($settings_raw['system_language'] ?? '') == 'الإنجليزية') ? 'selected' : ''; ?>>الإنجليزية (English)</option>
            </select>
        </div>

        <button type="submit" style="background: #4e73df; color: white; border: none; padding: 9px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ التعديلات والإعدادات</button>
    </form>
</div>

<?php include 'footer.php'; ?>