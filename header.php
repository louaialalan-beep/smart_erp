<?php
/**
 * الهوية البصرية وملف الاتصال - النظام المحاسبي Smart ERP (Zoho Style)
 */

// تصحيح حاسم: session_start() يجب أن يكون أول سطر تنفيذي مطلقاً، قبل أي include. لو احتوى db.php
// أو functions.php أو أي ملف آخر أي بايت وحيد يُطبَع (سطر فارغ أو مسافة بعد وسم إغلاق PHP مثلاً) قبل استدعاء
// session_start()، تفشل الجلسة في إرسال كوكيها بصمت (PHP لا يرمي خطأ ظاهراً)، فتحصل كل صفحة على
// جلسة جديدة فارغة في كل طلب — وهذا يُفسِّر رفض CSRF المستمر بغض النظر عن أي نموذج محدد.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// وضع تشخيص CSRF مؤقت: يُخزَّن في الجلسة بدل الاعتماد على استمرار ?csrf_debug=1 في الرابط،
// لأن نماذج POST بلا action صريح تُسقط سلسلة الاستعلام تلقائياً عند الإرسال في أغلب المتصفحات.
if (isset($_GET['csrf_debug'])) {
    $_SESSION['csrf_debug_mode'] = true;
}
if (isset($_GET['csrf_debug_off'])) {
    unset($_SESSION['csrf_debug_mode']);
}

// تضمين ملفات الاتصال والدوال الأساسية
include_once 'db.php';
include_once 'functions.php';
include_once __DIR__ . '/includes/system_helpers.php';

// حراسة تسجيل الدخول: أي صفحة تُضمِّن header.php تتطلب جلسة مسجَّلة، فيما عدا login.php نفسها
ensureUsersTable($conn);
$current_script = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id']) && $current_script !== 'login.php') {
    header("Location: login.php");
    exit;
}

// حماية CSRF مركزية: يتحقق من كل طلب POST في كل صفحة تُضمِّن header.php قبل أن يصل التنفيذ
// لأي معالج POST داخل الصفحة نفسها — حماية شاملة بنقطة واحدة بدل تعديل كل ملف يدوياً.
verifyCsrfToken();

$logged_in_full_name = $_SESSION['full_name'] ?? 'مستخدم';
$logged_in_role = $_SESSION['user_role'] ?? 'viewer';
$role_display_labels = ['admin' => 'مدير النظام', 'accountant' => 'محاسب', 'viewer' => 'مستعرض فقط'];
$logged_in_role_label = $role_display_labels[$logged_in_role] ?? $logged_in_role;
// أول حرفين من الاسم لعرضهما في الأفاتار الدائري
$name_parts = explode(' ', trim($logged_in_full_name));
$avatar_initials = mb_substr($name_parts[0] ?? '', 0, 1) . (isset($name_parts[1]) ? mb_substr($name_parts[1], 0, 1) : '');

// تعيين القيم الافتراضية لاسم الشركة والشعار
$company_name = 'النظام المحاسبي المتطور';
$company_logo = '';

// جلب الإعدادات من جدول system_settings بالطريقة الصحيحة (Key-Value)
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($stmt) {
        $settings_map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($settings_map['company_name'])) {
            $company_name = $settings_map['company_name'];
        }
        if (!empty($settings_map['company_logo'])) {
            $company_logo = $settings_map['company_logo'];
        }
    }
} catch (Exception $e) {
    // في حال عدم توفر الجدول أو خطأ في الاتصال
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($company_name); ?> - النظام المحاسبي</title>
    <!-- استيراد خط Tajawal للعربية وخط Montserrat الأجنبي المميز -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800&family=Tajawal:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --zoho-sidebar-bg: #192231;
            --zoho-sidebar-hover: #26354a;
            --zoho-primary: #0065ff;
            --zoho-accent: #00c48c;
            --zoho-bg: #f4f6f9;
            --zoho-card-bg: #ffffff;
            --zoho-text-main: #333333;
            --zoho-text-muted: #6b7280;
            --zoho-border: #e2e8f0;
            --zoho-radius: 8px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Tajawal', sans-serif;
        }

        body {
            background-color: var(--zoho-bg);
            color: var(--zoho-text-main);
            display: flex;
            height: 100vh;
            overflow: hidden;
            font-size: 14px;
        }

        aside {
            width: 270px;
            background-color: var(--zoho-sidebar-bg);
            color: #fff;
            display: flex;
            flex-direction: column;
            height: 100%;
            box-shadow: 3px 0 10px rgba(0,0,0,0.05);
            z-index: 100;
        }

        .sidebar-brand {
            padding: 15px 20px;
            background: #121924;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            min-height: 65px;
        }

        .sidebar-brand img {
            max-height: 38px;
            max-width: 100%;
            object-fit: contain;
        }

        .sidebar-brand i {
            color: var(--zoho-primary);
            font-size: 22px;
        }

        /* تنسيق اسم البرنامج الثابت في القائمة الجانبية بخط أجنبي مميز */
        .sidebar-brand .brand-title {
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            font-size: 22px;
            color: #ffffff;
            letter-spacing: 1.5px;
        }

        .sidebar-menu {
            list-style: none;
            overflow-y: auto;
            flex: 1;
            padding: 15px 10px;
        }

        .sidebar-menu::-webkit-scrollbar {
            width: 5px;
        }
        .sidebar-menu::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
        }

        .menu-category {
            font-size: 11px;
            text-transform: uppercase;
            color: #94a3b8;
            padding: 10px 15px 5px 15px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 15px;
            color: #cbd5e1;
            text-decoration: none;
            border-radius: var(--zoho-radius);
            margin-bottom: 3px;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .sidebar-menu li a:hover, .sidebar-menu li.active a {
            background-color: var(--zoho-sidebar-hover);
            color: #ffffff;
        }

        .sidebar-menu li a i {
            font-size: 16px;
            width: 20px;
            text-align: center;
            color: #94a3b8;
        }

        .sidebar-menu li.active a i, .sidebar-menu li a:hover i {
            color: var(--zoho-primary);
        }

        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }

        header.top-navbar {
            height: 65px;
            background: var(--zoho-card-bg);
            border-bottom: 1px solid var(--zoho-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .user-profile-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            background: var(--zoho-primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .logout-link {
            color: #e74a3b;
            font-size: 12px;
            text-decoration: none;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 6px 10px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .logout-link:hover { background: #fdecea; }

        main.content-area {
            flex: 1;
            overflow-y: auto;
            padding: 25px;
            background-color: var(--zoho-bg);
        }

        .zoho-card {
            background: var(--zoho-card-bg);
            border: 1px solid var(--zoho-border);
            border-radius: var(--zoho-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .zoho-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--zoho-border);
        }

        .zoho-card-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .zoho-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }

        .zoho-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            padding: 12px 15px;
            border-bottom: 2px solid var(--zoho-border);
            text-align: right;
        }

        .zoho-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--zoho-border);
            color: #334155;
        }

        .zoho-table tr:hover {
            background-color: #f8fafc;
        }

        .zoho-footer {
            background: var(--zoho-card-bg);
            border-top: 1px solid var(--zoho-border);
            padding: 12px 25px;
            color: var(--zoho-text-muted);
            font-size: 13px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: auto;
        }
        .zoho-footer strong {
            color: var(--zoho-primary);
        }

        /* أقسام قابلة للطي في القائمة الجانبية */
        .menu-category {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
            transition: color 0.2s;
        }
        .menu-category:hover { color: #cbd5e1; }
        .menu-category .toggle-arrow {
            font-size: 10px;
            transition: transform 0.25s ease;
        }
        .menu-category.collapsed .toggle-arrow {
            transform: rotate(-90deg);
        }
        .menu-group {
            overflow: hidden;
            max-height: 500px;
            transition: max-height 0.3s ease;
        }
        .menu-group.collapsed {
            max-height: 0;
        }
    </style>
</head>
<body>

    <aside>
        <div class="sidebar-brand">
            <?php if (!empty($company_logo)): ?>
                <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="VNRD Logo">
            <?php else: ?>
                <i class="fa-solid fa-cube"></i>
                <span class="brand-title">VNRD</span>
            <?php endif; ?>
        </div>
        <ul class="sidebar-menu">
            <li>
                <a href="index.php"><i class="fa-solid fa-chart-pie"></i> لوحة التحكم</a>
            </li>

            <div class="menu-category" data-target="grp-1">النواة المحاسبية والمالية <i class="fa-solid fa-chevron-down toggle-arrow"></i></div>
            <div class="menu-group" id="grp-1">
            <li>
                <a href="accounts.php"><i class="fa-solid fa-sitemap"></i> شجرة الحسابات الذكية</a>
            </li>
            <li>
                <a href="journal.php"><i class="fa-solid fa-book-journal-whills"></i> دفتر اليومية الشامل</a>
            </li>
            <li>
                <a href="ledger.php"><i class="fa-solid fa-scale-balanced"></i> دفتر الأستاذ وميزان المراجعة</a>
            </li>
            <li>
                <a href="currencies.php"><i class="fa-solid fa-coins"></i> محرك العملات والصرف</a>
            </li>
            </div>

            <div class="menu-category" data-target="grp-2">إدارة المخزون والموردين <i class="fa-solid fa-chevron-down toggle-arrow"></i></div>
            <div class="menu-group" id="grp-2">
            <li>
                <a href="products.php"><i class="fa-solid fa-boxes-stacked"></i> المنتجات والمخزون</a>
            </li>
            <li>
                <a href="suppliers.php"><i class="fa-solid fa-truck-field"></i> الموردين والربط البرمجي</a>
            </li>
            <li>
                <a href="purchases.php"><i class="fa-solid fa-truck-loading"></i> فواتير الشراء</a>
            </li>
            </div>

            <div class="menu-category" data-target="grp-3">المبيعات والمندوبين <i class="fa-solid fa-chevron-down toggle-arrow"></i></div>
            <div class="menu-group" id="grp-3">
            <li>
                <a href="sales.php"><i class="fa-solid fa-file-invoice-dollar"></i> موديول المبيعات</a>
            </li>
            <li>
                <a href="representatives.php"><i class="fa-solid fa-user-tie"></i> المندوبين والعمولات</a>
            </li>
            </div>

            <div class="menu-category" data-target="grp-4">الموارد البشرية والمصاريف <i class="fa-solid fa-chevron-down toggle-arrow"></i></div>
            <div class="menu-group" id="grp-4">
            <li>
                <a href="hr_payroll_advanced.php"><i class="fa-solid fa-users-gear"></i> الموظفين والرواتب</a>
            </li>
            <li>
                <a href="expenses.php"><i class="fa-solid fa-wallet"></i> المصاريف التشغيلية</a>
            </li>
            </div>

            <div class="menu-category" data-target="grp-5">التقارير والميزات المتقدمة <i class="fa-solid fa-chevron-down toggle-arrow"></i></div>
            <div class="menu-group" id="grp-5">
            <li>
                <a href="financial_statements.php"><i class="fa-solid fa-file-invoice"></i> القوائم المالية الرسمية</a>
            </li>
            <li>
                <a href="daily_closing.php"><i class="fa-solid fa-cash-register"></i> الإقفال اليومي للصندوق</a>
            </li>
            <li>
                <a href="financial_reports.php"><i class="fa-solid fa-chart-line"></i> التقارير والأرباح (COGS)</a>
            </li>
            <li>
                <a href="advanced_features.php"><i class="fa-solid fa-shield-halved"></i> سجل التدقيق والزمن التاريخي</a>
            </li>
            <?php if ($logged_in_role === 'admin'): ?>
            <li>
                <a href="users.php"><i class="fa-solid fa-users-cog"></i> المستخدمين والصلاحيات</a>
            </li>
            <?php endif; ?>
            <li>
                <a href="settings.php"><i class="fa-solid fa-gear"></i> الإعدادات العامة</a>
            </li>
            </div>
        </ul>
    </aside>

    <div class="main-wrapper">
        <header class="top-navbar">
            <div style="font-size: 16px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                <?php if (!empty($company_logo)): ?>
                    <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="Logo" style="height: 30px; object-fit: contain;">
                <?php else: ?>
                    <i class="fa-solid fa-building-columns" style="color: var(--zoho-primary);"></i>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($company_name); ?> - النظام المحاسبي المتطور</span>
            </div>
            <div class="user-profile-info">
                <a href="login.php?logout=1" class="logout-link" title="تسجيل الخروج"><i class="fa-solid fa-right-from-bracket"></i> خروج</a>
                <div style="text-align: left;">
                    <div style="font-weight: 700; font-size: 13px; color: #1e293b;"><?php echo htmlspecialchars($logged_in_full_name); ?></div>
                    <div style="font-size: 11px; color: var(--zoho-text-muted);"><?php echo htmlspecialchars($logged_in_role_label); ?></div>
                </div>
                <div class="user-avatar"><?php echo htmlspecialchars($avatar_initials ?: '؟'); ?></div>
            </div>
        </header>

        <script>
            (function () {
                var categories = document.querySelectorAll('.menu-category');
                var currentPage = location.pathname.split('/').pop();

                categories.forEach(function (cat) {
                    var targetId = cat.getAttribute('data-target');
                    var group = document.getElementById(targetId);
                    var storageKey = 'sidebar_' + targetId;

                    // القسم يبقى مفتوحاً تلقائياً إن كانت الصفحة الحالية بداخله، حتى لو كان مطوياً سابقاً
                    var containsCurrentPage = group.querySelector('a[href="' + currentPage + '"]') !== null;
                    var savedState = localStorage.getItem(storageKey);
                    var shouldCollapse = containsCurrentPage ? false : (savedState === 'collapsed');

                    if (shouldCollapse) {
                        group.classList.add('collapsed');
                        cat.classList.add('collapsed');
                    }

                    cat.addEventListener('click', function () {
                        var isCollapsed = group.classList.toggle('collapsed');
                        cat.classList.toggle('collapsed', isCollapsed);
                        localStorage.setItem(storageKey, isCollapsed ? 'collapsed' : 'open');
                    });
                });
            })();
        </script>

        <main class="content-area">