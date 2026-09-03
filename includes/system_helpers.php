<?php
/**
 * دوال مشتركة عبر النظام: إنفاذ قفل الفترات المالية + سجل التدقيق الشامل
 * يُستدعى هذا الملف عبر require_once في أي ملف يحتاج التحقق من إغلاق فترة أو تسجيل حدث تدقيقي.
 */

if (!function_exists('isDateInClosedPeriod')) {
    /**
     * يتحقق مما إذا كان تاريخ معيّن يقع ضمن فترة مالية مغلقة.
     * يُستخدم لمنع الإضافة/التعديل على أي حركة مالية (فاتورة، دفعة، مصروف...) بتاريخ يقع ضمن فترة مقفلة.
     */
    function isDateInClosedPeriod($conn, $date) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM financial_periods WHERE status = 'closed' AND ? BETWEEN start_date AND end_date");
            $stmt->execute([$date]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            // في حال تعذّر التحقق (مثلاً الجدول غير موجود بعد)، لا نُعطِّل العملية بصمت لسبب تقني غير متعلق بإغلاق الفترة
            return false;
        }
    }
}

if (!function_exists('getPeriodLockErrorMessage')) {
    /**
     * رسالة موحّدة تُعرض للمستخدم عند رفض عملية بسبب وقوعها ضمن فترة مالية مغلقة.
     */
    function getPeriodLockErrorMessage($date) {
        return "خطأ: لا يمكن تنفيذ هذه العملية لأن تاريخها ($date) يقع ضمن فترة مالية مغلقة (مؤرشفة). لفتح الفترة مؤقتاً، توجّه إلى صفحة الميزات المتقدمة > الزمن التاريخي وإغلاق الفترات.";
    }
}

if (!function_exists('getCurrentUserName')) {
    /**
     * اسم المستخدم الحالي من الجلسة، مع محاولة عدة مفاتيح شائعة لأن اسم المتغير الفعلي في header.php غير موحّد،
     * وقيمة احتياطية نهائية إن لم يوجد أي منها.
     */
    function getCurrentUserName() {
        return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? 'مدير النظام';
    }
}

// ============================================================
// نظام المستخدمين والصلاحيات (Role-Based Access Control)
// جدول users بسيط: admin (كل الصلاحيات)، accountant (محاسب: قيود+فواتير+حذف ممنوع)، viewer (عرض فقط)
// ============================================================

if (!function_exists('ensureUsersTable')) {
    function ensureUsersTable($conn) {
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                role ENUM('admin','accountant','viewer') NOT NULL DEFAULT 'viewer',
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            // إنشاء حساب مدير افتراضي عند أول تشغيل فقط (اسم المستخدم: admin / كلمة المرور: admin123)
            // يجب تغيير كلمة المرور فوراً من صفحة إدارة المستخدمين بعد أول دخول.
            $count = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($count == 0) {
                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, 'admin')");
                $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'مدير النظام']);
            }
        } catch (Exception $e) {}
    }
}

if (!function_exists('getCurrentUserRole')) {
    /** دور المستخدم الحالي؛ 'admin' افتراضياً إن لم يوجد نظام تسجيل دخول مفعَّل بعد (توافق مع الحالة الحالية للنظام) */
    function getCurrentUserRole() {
        return $_SESSION['user_role'] ?? 'admin';
    }
}

if (!function_exists('requireRole')) {
    /**
     * يوقف تنفيذ الصفحة برسالة صريحة إن لم يكن دور المستخدم الحالي ضمن الأدوار المسموحة.
     * $allowed_roles: مثال ['admin'] أو ['admin', 'accountant']
     */
    function requireRole($conn, array $allowed_roles) {
        $role = getCurrentUserRole();
        if (!in_array($role, $allowed_roles)) {
            logAudit($conn, 'DENIED', 'صلاحيات', "محاولة تنفيذ إجراء يتطلب صلاحية (" . implode('/', $allowed_roles) . ") من مستخدم بدور: $role");
            die("<div style='padding:30px; color:#721c24; background:#f8d7da; border-radius:8px; margin:20px; font-family:sans-serif;'><strong>غير مصرَّح:</strong> هذا الإجراء يتطلب صلاحية " . implode(' أو ', $allowed_roles) . ". دورك الحالي: $role</div>");
        }
    }
}

// ============================================================
// حماية CSRF (Cross-Site Request Forgery)
// ============================================================

if (!function_exists('generateCsrfToken')) {
    /** يُنشئ رمزاً عشوائياً آمناً لكل جلسة (مرة واحدة فقط)، ويُعيد نفس الرمز في الطلبات اللاحقة */
    function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrfField')) {
    /** يُطبع حقلاً مخفياً يحمل رمز CSRF — يُستدعى داخل كل <form method="POST"> */
    function csrfField() {
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
    }
}

if (!function_exists('verifyCsrfToken')) {
    /**
     * يتحقق من تطابق رمز CSRF المُرسَل مع الجلسة لكل طلب POST. يُستدعى مركزياً من header.php
     * فيحمي كل معالجات POST عبر النظام دفعة واحدة دون الحاجة لتعديل كل ملف يدوياً.
     */
    function verifyCsrfToken() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $submitted = $_POST['csrf_token'] ?? '';
            if (empty($submitted) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
                http_response_code(403);
                die("<div style='padding:30px; background:#f8d7da; color:#721c24; border-radius:8px; margin:30px auto; max-width:600px; font-family:Tahoma, sans-serif; text-align:center;'>
                    <h3 style='margin-top:0;'><i class='fas fa-shield-alt'></i> طلب غير موثوق (CSRF)</h3>
                    <p>انتهت صلاحية الجلسة أو أن الطلب لم يصدر من نموذج صفحة صالح. يرجى إعادة تحميل الصفحة والمحاولة مجدداً.</p>
                    <a href='javascript:history.back()' style='color:#721c24; font-weight:bold;'>عودة</a>
                    </div>");
            }
        }
    }
}

if (!function_exists('logAudit')) {
    /**
     * تسجيل حدث في سجل التدقيق الشامل (audit_logs).
     * $action_type: 'INSERT' | 'UPDATE' | 'DELETE' | 'LOGIN'
     * $module_name: اسم الوحدة بالعربية (مثال: 'فواتير المبيعات', 'دفعات المندوبين'...)
     * $details: وصف نصي واضح للحدث
     * $record_id: معرف السجل المتأثر (اختياري)
     */
    function logAudit($conn, string $action_type, string $module_name, string $details, int $record_id = 0) {
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_name VARCHAR(100) DEFAULT 'مدير النظام',
                action_type VARCHAR(50) NOT NULL,
                module_name VARCHAR(100) NOT NULL,
                record_id INT DEFAULT 0,
                details TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $stmt = $conn->prepare("INSERT INTO audit_logs (user_name, action_type, module_name, record_id, details) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([getCurrentUserName(), $action_type, $module_name, $record_id, $details]);
        } catch (Exception $e) {
            // تسجيل التدقيق لا يجب أن يُسقط العملية الأساسية أبداً إن فشل لأي سبب
        }
    }
}

// ============================================================
// الاعتراف بالإيراد عند التسليم (Revenue Recognition at Delivery)
// ============================================================
// إصلاح معماري جوهري: الإيراد وCOGS وعمولة المندوب يُعترَف بها الآن معاً في نفس اللحظة المحاسبية
// (لحظة تأكيد التسليم الفعلي)، وليس عند مجرد إصدار الفاتورة — تماشياً مع معيار IFRS 15 / ASC 606
// (الاعتراف بالإيراد عند انتقال السيطرة على البضاعة للعميل)، ولأن الفاتورة في هذا النظام قد
// تبقى "قيد الانتظار" لفترة، وقد تُرتجَع بالكامل قبل التسليم أصلاً.

if (!function_exists('findOrCreateAccount')) {
    function findOrCreateAccount($conn, array $keywords, string $fallback_name) {
        try {
            $stmt_cols = $conn->query("SHOW COLUMNS FROM accounts");
            $cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);
            $name_col = null;
            foreach (['name', 'account_name', 'title', 'name_ar', 'acc_name'] as $c) {
                if (in_array($c, $cols)) { $name_col = $c; break; }
            }
            if ($name_col && count($keywords) > 0) {
                $conditions = implode(' OR ', array_fill(0, count($keywords), "`{$name_col}` LIKE ?"));
                $params = array_map(fn($k) => "%{$k}%", $keywords);
                $stmt = $conn->prepare("SELECT id FROM accounts WHERE {$conditions} ORDER BY id ASC LIMIT 1");
                $stmt->execute($params);
                $acc_id = $stmt->fetchColumn();
                if ($acc_id) return $acc_id;
            }
            $target_col = $name_col ?: ($cols[1] ?? 'name');
            $conn->exec("INSERT INTO accounts (`{$target_col}`) VALUES (" . $conn->quote($fallback_name) . ")");
            return $conn->lastInsertId();
        } catch (Exception $e) { return null; }
    }
}

if (!function_exists('postJournalLine')) {
    function postJournalLine($conn, $account_id, $debit, $credit, $entry_number, $entry_date, $description, $source_module) {
        $conn->prepare("INSERT INTO journal_entries (account_id, entry_date, description, debit, credit, entry_number, currency_code, source_module) VALUES (?, ?, ?, ?, ?, ?, 'SYP', ?)")
             ->execute([$account_id, $entry_date, $description, $debit, $credit, $entry_number, $source_module]);
    }
}

if (!function_exists('recognizeSaleRevenue')) {
    /**
     * يُعترَف بالإيراد الحقيقي + COGS + استحقاق العمولة لفاتورة عند تسليمها فعلياً.
     * دالة آمنة للاستدعاء المتكرر (Idempotent) — إن كانت القيود موجودة بالفعل، لا تُكرِّرها.
     * تُستدعى من: sales.php عند إصدار فاتورة بحالة Delivered مباشرة، وrepresentative_profile.php
     * عند تأكيد تسليم فاتورة كانت Pending سابقاً.
     */
    function recognizeSaleRevenue($conn, $sale_id) {
        $stmt = $conn->prepare("SELECT * FROM sales WHERE id = ?");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale) return;

        $cogs_entry_num = "JE-" . $sale['invoice_number'] . "-COGS";
        $check = $conn->prepare("SELECT COUNT(*) FROM journal_entries WHERE entry_number = ?");
        $check->execute([$cogs_entry_num]);
        if ($check->fetchColumn() > 0) return; // مُعترَف به بالفعل — لا تكرار

        $today = date('Y-m-d');

        // تصحيح: تُسجَّل الآن تاريخ التسليم الفعلي (لحظة تأكيد التسليم، وليس تاريخ إصدار الفاتورة الأصلي)
        // في عمود مستقل، حتى تعتمد عليه التقارير/الإحصائيات المبنية على "متى سُلِّمت البضاعة فعلياً" —
        // القيود المحاسبية نفسها كانت تُرحَّل بتاريخ اليوم أصلاً (لا تغيير هناك)، لكن لم يكن هناك عمود
        // مخزَّن يعكس هذا التاريخ على مستوى الفاتورة نفسها لأغراض التقارير والفلاتر.
        try {
            $sales_cols_rsr = $conn->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('delivered_at', $sales_cols_rsr)) {
                $conn->exec("ALTER TABLE sales ADD COLUMN delivered_at DATE NULL");
            }
        } catch (Exception $e) { /* يُتجاهل إن تعذّر */ }
        $conn->prepare("UPDATE sales SET delivered_at = ? WHERE id = ?")->execute([$today, $sale_id]);

        // تصحيح: الكلمة المفتاحية 'إيراد' (بلا "ات") كانت تُطابق أيضاً "إيرادات مؤجلة" كسلسلة فرعية
        // (كلاهما يبدأ بـ"إيرادات")، فيرجع revenue_id لنفس حساب deferred_id عند وجود الأخير بمعرّف أصغر
        // — ما يجعل كلا طرفي قيد الاعتراف بالإيراد يُرحَّلان لنفس الحساب فتتحيّد قيمتهما لصفر.
        $revenue_id = findOrCreateAccount($conn, ['إيرادات المبيعات', 'مبيعات', 'sales revenue'], 'إيرادات المبيعات');
        $deferred_id = findOrCreateAccount($conn, ['إيرادات مؤجلة', 'مؤجل', 'deferred'], 'إيرادات مؤجلة');

        // إعادة تصنيف: إن كانت الفاتورة أُصدِرت أصلاً كـ"مؤجلة"، حوِّل مبلغها من التزام (إيراد مؤجل)
        // إلى إيراد حقيقي الآن. إن كانت أُصدِرت مباشرة كـ"مُسلَّمة"، لا يوجد قيد مؤجل لعكسه (يُتخطى بأمان).
        $main_entry_num = "JE-" . $sale['invoice_number'];
        $stmt_main = $conn->prepare("SELECT id FROM journal_entries WHERE entry_number = ? AND account_id = ? AND credit > 0");
        $stmt_main->execute([$main_entry_num, $deferred_id]);
        if ($stmt_main->fetchColumn()) {
            $reclass_num = $main_entry_num . "-RECLASS";
            $desc = "الاعتراف بالإيراد عند تأكيد التسليم لفاتورة: " . $sale['invoice_number'];
            postJournalLine($conn, $deferred_id, floatval($sale['total_amount_syp']), 0, $reclass_num, $today, $desc, 'Revenue Recognition');
            postJournalLine($conn, $revenue_id, 0, floatval($sale['total_amount_syp']), $reclass_num, $today, $desc, 'Revenue Recognition');
        }

        // قيد COGS
        $stmt_items = $conn->prepare("SELECT si.*, p.cost_price_usd FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
        $stmt_items->execute([$sale_id]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        $total_cogs = 0;
        foreach ($items as $it) {
            $cost = $it['cost_price_usd_at_sale'] !== null ? floatval($it['cost_price_usd_at_sale']) : floatval($it['cost_price_usd']);
            $total_cogs += floatval($it['quantity']) * $cost * floatval($sale['exchange_rate']);
        }
        if ($total_cogs > 0) {
            $cogs_exp = findOrCreateAccount($conn, ['تكلفة البضاعة', 'تكلفة البضائع', 'cogs'], 'تكلفة البضائع المباعة (COGS)');
            $inv = findOrCreateAccount($conn, ['مخزون', 'بضاعة', 'inventory'], 'المخزون');
            if ($cogs_exp && $inv) {
                $desc = "تكلفة البضاعة المباعة عند تأكيد تسليم فاتورة: " . $sale['invoice_number'];
                postJournalLine($conn, $cogs_exp, $total_cogs, 0, $cogs_entry_num, $today, $desc, 'Sales COGS');
                postJournalLine($conn, $inv, 0, $total_cogs, $cogs_entry_num, $today, $desc, 'Sales COGS');
            }
        }

        // قيد استحقاق العمولة
        if ($sale['representative_id'] && floatval($sale['total_commissions']) > 0) {
            $comm_entry_num = "JE-" . $sale['invoice_number'] . "-COMM";
            $comm_exp = findOrCreateAccount($conn, ['مصروف عمولات', 'عمولات مندوبين'], 'مصروف عمولات المندوبين');
            $comm_pay = findOrCreateAccount($conn, ['عمولات', 'مندوب'], 'عمولات المندوبين المستحقة');
            if ($comm_exp && $comm_pay) {
                $desc = "استحقاق عمولة عند تأكيد تسليم فاتورة: " . $sale['invoice_number'];
                postJournalLine($conn, $comm_exp, floatval($sale['total_commissions']), 0, $comm_entry_num, $today, $desc, 'Commission Accrual');
                postJournalLine($conn, $comm_pay, 0, floatval($sale['total_commissions']), $comm_entry_num, $today, $desc, 'Commission Accrual');
            }
        }
    }
}

if (!function_exists('deferSaleRevenue')) {
    /**
     * يعكس الاعتراف بالإيراد/COGS/العمولة عند إلغاء تأكيد تسليم فاتورة (إعادتها لحالة "قيد الانتظار").
     * آمنة للاستدعاء المتكرر — إن كانت القيود معكوسة بالفعل، لا تُكرِّر العكس.
     */
    function deferSaleRevenue($conn, $sale_id) {
        $stmt = $conn->prepare("SELECT * FROM sales WHERE id = ?");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale) return;

        // إلغاء تاريخ التسليم الفعلي المسجَّل — لم تعد الفاتورة "مُسلَّمة" فعلياً
        try {
            $conn->exec("UPDATE sales SET delivered_at = NULL WHERE id = " . intval($sale_id));
        } catch (Exception $e) { /* يُتجاهل إن كان العمود غير موجود بعد لأي سبب */ }

        $today = date('Y-m-d');
        foreach (['-COGS', '-COMM', '-RECLASS'] as $suffix) {
            $entry_num = "JE-" . $sale['invoice_number'] . $suffix;
            $undo_num = $entry_num . "-UNDO";

            $check = $conn->prepare("SELECT COUNT(*) FROM journal_entries WHERE entry_number = ?");
            $check->execute([$undo_num]);
            if ($check->fetchColumn() > 0) continue; // مُعكوس بالفعل

            $stmt_lines = $conn->prepare("SELECT account_id, debit, credit FROM journal_entries WHERE entry_number = ?");
            $stmt_lines->execute([$entry_num]);
            $lines = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);
            if (count($lines) == 0) continue;

            $desc = "عكس تلقائي عند إلغاء تأكيد تسليم فاتورة: " . $sale['invoice_number'];
            foreach ($lines as $line) {
                postJournalLine($conn, $line['account_id'], floatval($line['credit']), floatval($line['debit']), $undo_num, $today, $desc, 'Delivery Reversal');
            }
        }
    }
}