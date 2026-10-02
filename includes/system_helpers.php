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

if (!function_exists('isRecentDuplicateSubmission')) {
    /**
     * حماية باك-إند من تكرار الإرسال (Double Submission): تتحقق مما إذا كان قد أُدرِج صف مطابق تماماً
     * لنفس الحقول المفتاحية (مبلغ + عميل/مورد/موظف + ...) في نفس الجدول خلال آخر $seconds ثوانٍ فقط.
     * تُستدعى مباشرة قبل أي INSERT لحركة مالية جديدة (فاتورة بيع، فاتورة شراء، مصروف، دفعة مورد، سحب
     * مالك، دفعة مندوب، سلفة/مسيّر رواتب). $conditions مصفوفة ['عمود' => قيمة] لكل الحقول المفتاحية التي
     * يجب أن تتطابق بالضبط لاعتبار الصف تكراراً — لا تُدرِج عمود created_at أو id ضمنها.
     * تعيد true فوراً إذا وُجِد تكرار (يجب حينها إيقاف العملية ورفضها)، أو false للسماح بالمتابعة.
     */
    function isRecentDuplicateSubmission($conn, $table, array $conditions, $seconds = 5) {
        if (empty($conditions)) return false;
        try {
            $where = [];
            $params = [];
            foreach ($conditions as $col => $val) {
                $where[] = "`$col` = ?";
                $params[] = $val;
            }
            $params[] = $seconds;
            $sql = "SELECT COUNT(*) FROM `$table` WHERE " . implode(' AND ', $where)
                 . " AND created_at >= (NOW() - INTERVAL ? SECOND)";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            // في حال تعذّر التحقق (مثلاً عمود created_at غير موجود بعد في جدول قديم)، لا نمنع العملية
            // بصمت لسبب تقني غير متعلق بالتكرار الفعلي — أفضل من حجب عملية مشروعة بالخطأ.
            return false;
        }
    }
}

if (!function_exists('getDuplicateSubmissionErrorMessage')) {
    /** رسالة موحّدة تُعرض للمستخدم عند رفض عملية بسبب اكتشاف تكرار إرسال فوري (ضغطة مزدوجة على الحفظ). */
    function getDuplicateSubmissionErrorMessage() {
        return "تنبيه: تم رصد عملية مطابقة تماماً أُدخِلت للتو (خلال الثواني القليلة الماضية). إن كنت تقصد إدخال عمليتين منفصلتين بنفس القيم فعلاً، انتظر قليلاً ثم أعد المحاولة. إن كانت ضغطة مزدوجة غير مقصودة على زر الحفظ، لا حاجة لأي إجراء — العملية الأولى مُسجَّلة بالفعل ولم تُكرَّر.";
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
    function findOrCreateAccount($conn, array $keywords, string $fallback_name, ?string $account_type = null) {
        try {
            $stmt_cols = $conn->query("SHOW COLUMNS FROM accounts");
            $cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);
            $name_col = null;
            foreach (['name', 'account_name', 'title', 'name_ar', 'acc_name'] as $c) {
                if (in_array($c, $cols)) { $name_col = $c; break; }
            }
            // === تصحيح جذري (خلل حقيقي مكتشَف بالبيانات الفعلية): كان البحث يطابق أي حساب يحتوي إحدى
            // الكلمات المفتاحية في اسمه بغض النظر عن نوعه (account_type) — فكلمة عامة كـ"بضاعة" قد
            // تطابق بالخطأ حساب ذمم مورد (Liability) اسمه يحوي هذه الكلمة، بدل إنشاء/استخدام حساب
            // "المخزون" (Asset) المقصود فعلياً. النتيجة: قيود جرد مكتبي بأكملها تُرحَّل بصمت لحساب مورد
            // غير ذي صلة إطلاقاً، فيُلوِّث رصيده وتقاريره دون أي تنبيه. الآن يُضاف شرط account_type لنفس
            // استعلام البحث أيضاً (لا فقط عند الإنشاء) متى ما مُرِّر — فلا يُطابَق إلا حساب من النوع
            // الصحيح فعلاً، حتى لو تشابه اسمه اتفاقاً مع حساب من نوع آخر تماماً.
            if ($name_col && count($keywords) > 0) {
                $conditions = implode(' OR ', array_fill(0, count($keywords), "`{$name_col}` LIKE ?"));
                $params = array_map(fn($k) => "%{$k}%", $keywords);
                $type_clause = '';
                if ($account_type !== null && in_array('account_type', $cols)) {
                    $type_clause = " AND account_type = ?";
                    $params[] = $account_type;
                }
                $stmt = $conn->prepare("SELECT id FROM accounts WHERE ({$conditions}){$type_clause} ORDER BY id ASC LIMIT 1");
                $stmt->execute($params);
                $acc_id = $stmt->fetchColumn();
                if ($acc_id) return $acc_id;
            }
            $target_col = $name_col ?: ($cols[1] ?? 'name');

            // === تصحيح جذري: إن كان العمود account_code موجوداً وفريداً (UNIQUE)، يجب توليد قيمة
            // فعلية وفريدة له دائماً — وإلا يفشل الإدراج بصمت (Exception تُبتلَع أدناه) لأي حساب ثانٍ
            // يُنشأ بلا رمز، فتُعيد الدالة null والقيد المحاسبي بأكمله يختفي بصمت تام دون أي تنبيه.
            $ins_cols = [$target_col]; $ins_vals = [$fallback_name];
            if (in_array('account_code', $cols)) {
                $code = (string) (100000 + random_int(0, 899999));
                $stmt_dup = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
                for ($i = 0; $i < 10; $i++) {
                    $stmt_dup->execute([$code]);
                    if ($stmt_dup->fetchColumn() == 0) { break; }
                    $code = (string) (100000 + random_int(0, 899999));
                }
                $ins_cols[] = 'account_code'; $ins_vals[] = $code;
            }
            if ($account_type !== null && in_array('account_type', $cols)) {
                $ins_cols[] = 'account_type'; $ins_vals[] = $account_type;
            }
            $placeholders = implode(',', array_fill(0, count($ins_cols), '?'));
            $stmt_ins = $conn->prepare("INSERT INTO accounts (`" . implode('`,`', $ins_cols) . "`) VALUES ({$placeholders})");
            $stmt_ins->execute($ins_vals);
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
    /**
     * === تحديث سياسة جوهري ===
     * الإيراد الحقيقي ("إيرادات المبيعات") لا يُعترَف به إلا عند تحقّق شرطين معاً في آنٍ واحد:
     * التسليم الفعلي (delivery_status = 'Delivered') والتحصيل النقدي الفعلي (payment_status = 'Paid').
     * أي حالة أخرى (قيد الانتظار، أو مُسلَّمة لكن آجلة، أو مدفوعة مقدَّماً لكن لم تُسلَّم بعد) تبقى في
     * "إيرادات مؤجلة" (التزام مؤقت) حتى يتحقق الشرطان معاً. هذه الدالة تُستدعى من نقطتين مستقلتين:
     * عند تأكيد التسليم، وعند تحصيل الدفعة نقداً — فتُنفِّذ إعادة التصنيف فقط عندما يكتمل آخر شرط ناقص.
     */
    function tryRecognizeRevenue($conn, $sale_id) {
        $stmt = $conn->prepare("SELECT * FROM sales WHERE id = ?");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale) return;
        if ($sale['delivery_status'] !== 'Delivered' || $sale['payment_status'] !== 'Paid') return; // لم يكتمل الشرطان بعد

        $today = date('Y-m-d');
        $revenue_id = findOrCreateAccount($conn, ['إيرادات المبيعات', 'مبيعات', 'sales revenue'], 'إيرادات المبيعات', 'Revenue');
        $deferred_id = findOrCreateAccount($conn, ['إيرادات مؤجلة', 'مؤجل', 'deferred'], 'إيرادات مؤجلة', 'Liability');

        $main_entry_num = "JE-" . $sale['invoice_number'];
        $stmt_main = $conn->prepare("SELECT id FROM journal_entries WHERE entry_number = ? AND account_id = ? AND credit > 0");
        $stmt_main->execute([$main_entry_num, $deferred_id]);
        if (!$stmt_main->fetchColumn()) return; // إما مُعترَف به فعلاً أو لم يُرحَّل كمؤجَّل أصلاً

        $reclass_num = $main_entry_num . "-RECLASS";
        $stmt_already = $conn->prepare("SELECT COUNT(*) FROM journal_entries WHERE entry_number = ?");
        $stmt_already->execute([$reclass_num]);
        if ($stmt_already->fetchColumn() > 0) return; // مُعترَف به بالفعل — لا تكرار

        // الصافي بعد خصم أي مرتجع/خصم وقع بينما كانت الفاتورة لا تزال مؤجَّلة
        $stmt_prior_returns = $conn->prepare("
            SELECT
                COALESCE((SELECT SUM(total_amount_syp) FROM sales_returns WHERE sale_id = ?), 0)
                + COALESCE((SELECT SUM(amount_syp) FROM sale_item_discounts WHERE sale_id = ?), 0)
        ");
        $stmt_prior_returns->execute([$sale_id, $sale_id]);
        $prior_returns_syp = floatval($stmt_prior_returns->fetchColumn());
        $reclass_amount = floatval($sale['total_amount_syp']) - $prior_returns_syp;

        if ($reclass_amount > 0) {
            $desc = "الاعتراف بالإيراد عند اكتمال شرطي التسليم والتحصيل معاً لفاتورة: " . $sale['invoice_number'] . ($prior_returns_syp > 0 ? " (صافي بعد خصم مرتجع/خصم سابق: " . number_format($prior_returns_syp, 2) . ")" : "");
            postJournalLine($conn, $deferred_id, $reclass_amount, 0, $reclass_num, $today, $desc, 'Revenue Recognition');
            postJournalLine($conn, $revenue_id, 0, $reclass_amount, $reclass_num, $today, $desc, 'Revenue Recognition');
        }
    }

    /**
     * COGS واستحقاق العمولة مرتبطان بالتسليم الفعلي فقط (بغض النظر عن حالة الدفع) — المخزون لا يُخصَم
     * إلا عند خروج البضاعة فعلياً. تستدعي أيضاً محاولة الاعتراف بالإيراد (تنجح فقط إن كانت الفاتورة
     * مدفوعة بالفعل وقت التسليم).
     */
    function recognizeSaleRevenue($conn, $sale_id) {
        $stmt = $conn->prepare("SELECT * FROM sales WHERE id = ?");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale) return;

        $cogs_entry_num = "JE-" . $sale['invoice_number'] . "-COGS";
        $check = $conn->prepare("SELECT COUNT(*) FROM journal_entries WHERE entry_number = ?");
        $check->execute([$cogs_entry_num]);
        if ($check->fetchColumn() > 0) { tryRecognizeRevenue($conn, $sale_id); return; } // COGS مُرحَّل بالفعل — لا تكرار، لكن يبقى فحص الإيراد وارداً

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
        $stmt_chk_delivered = $conn->prepare("SELECT delivered_at FROM sales WHERE id = ?");
        $stmt_chk_delivered->execute([$sale_id]);
        if (!$stmt_chk_delivered->fetchColumn()) {
            $conn->prepare("UPDATE sales SET delivered_at = ? WHERE id = ?")->execute([$today, $sale_id]);
        }

        // قيد COGS
        // تصحيح: نفس المبدأ أعلاه — نخصم الكمية المرتجعة مسبقاً (وقت "قيد الانتظار") من كل سطر، وإلا
        // تُرحَّل تكلفة بضاعة أُعيدت للمخزون بالفعل عبر معالج المرتجع نفسه، فتُحتسَب مرتين.
        $stmt_items = $conn->prepare("
            SELECT si.*, p.cost_price_usd,
                   COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri WHERE sri.sale_item_id = si.id), 0) AS already_returned_qty
            FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?
        ");
        $stmt_items->execute([$sale_id]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        $total_cogs = 0;
        foreach ($items as $it) {
            $cost = $it['cost_price_usd_at_sale'] !== null ? floatval($it['cost_price_usd_at_sale']) : floatval($it['cost_price_usd']);
            $net_qty = max(0, floatval($it['quantity']) - floatval($it['already_returned_qty']));
            $total_cogs += $net_qty * $cost * floatval($sale['exchange_rate']);
        }
        if ($total_cogs > 0) {
            $cogs_exp = findOrCreateAccount($conn, ['تكلفة البضاعة', 'تكلفة البضائع', 'cogs'], 'تكلفة البضائع المباعة (COGS)', 'Expense');
            $inv = findOrCreateAccount($conn, ['مخزون', 'بضاعة', 'inventory'], 'المخزون', 'Asset');
            if ($cogs_exp && $inv) {
                $desc = "تكلفة البضاعة المباعة عند تأكيد تسليم فاتورة: " . $sale['invoice_number'];
                postJournalLine($conn, $cogs_exp, $total_cogs, 0, $cogs_entry_num, $today, $desc, 'Sales COGS');
                postJournalLine($conn, $inv, 0, $total_cogs, $cogs_entry_num, $today, $desc, 'Sales COGS');
            }
        }

        // قيد استحقاق العمولة
        // تصحيح: نخصم أي عمولة عُكِسَت مسبقاً بسبب مرتجع وقع وقت "قيد الانتظار"، لنفس السبب أعلاه.
        $stmt_prior_comm = $conn->prepare("SELECT COALESCE(SUM(total_commission_reversed), 0) FROM sales_returns WHERE sale_id = ?");
        $stmt_prior_comm->execute([$sale_id]);
        $prior_comm_reversed = floatval($stmt_prior_comm->fetchColumn());
        $net_commission = floatval($sale['total_commissions']) - $prior_comm_reversed;
        if ($sale['representative_id'] && $net_commission > 0) {
            $comm_entry_num = "JE-" . $sale['invoice_number'] . "-COMM";
            $comm_exp = findOrCreateAccount($conn, ['مصروف عمولات', 'عمولات مندوبين'], 'مصروف عمولات المندوبين', 'Expense');
            $comm_pay = findOrCreateAccount($conn, ['عمولات', 'مندوب'], 'عمولات المندوبين المستحقة', 'Liability');
            if ($comm_exp && $comm_pay) {
                $desc = "استحقاق عمولة عند تأكيد تسليم فاتورة: " . $sale['invoice_number'];
                postJournalLine($conn, $comm_exp, $net_commission, 0, $comm_entry_num, $today, $desc, 'Commission Accrual');
                postJournalLine($conn, $comm_pay, 0, $net_commission, $comm_entry_num, $today, $desc, 'Commission Accrual');
            }
        }

        // بعد ترحيل COGS/العمولة، نحاول الاعتراف بالإيراد — ينجح فقط إن كانت الفاتورة مدفوعة بالفعل
        tryRecognizeRevenue($conn, $sale_id);
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

        // تصحيح جوهري (خلل حقيقي مكتشَف بالبيانات الفعلية): إن كان قد سُجِّل مرتجع (جزئي أو كامل) على
        // هذه الفاتورة بينما كانت لا تزال "مُسلَّمة" — فمعالج المرتجع نفسه سبق أن عكس جزءاً أو كل COGS
        // (وربما العمولة) بقيده المستقل الخاص به (JE-RET-*). عكس القيد الأصلي بكامل مبلغه هنا، بلا خصم
        // ما عكسه المرتجع مسبقاً، يُنتج **عكساً مزدوجاً**: COGS ينتهي برصيد سالب غير منطقي، والمخزون
        // يُعاد له نفس القيمة مرتين رغم استرجاعها فعلياً مرة واحدة فقط عبر المرتجع. نفس مبدأ التصحيح
        // المُطبَّق أصلاً في recognizeSaleRevenue بالضبط: نحسب "صافي ما تبقّى" بعد خصم كل مرتجع سابق، ولا
        // نعكس إلا هذا الصافي فقط.
        $stmt_prior_returns_cogs = $conn->prepare("
            SELECT COALESCE(SUM(je.credit), 0)
            FROM journal_entries je
            JOIN accounts a ON je.account_id = a.id
            WHERE je.entry_number IN (SELECT CONCAT('JE-RET-', sr.id) FROM sales_returns sr WHERE sr.sale_id = ?)
              AND a.account_type = 'Expense'
        ");
        $stmt_prior_returns_cogs->execute([$sale_id]);
        $already_reversed_cogs = floatval($stmt_prior_returns_cogs->fetchColumn());

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
                $undo_debit = floatval($line['credit']);
                $undo_credit = floatval($line['debit']);
                // تخصيص خصم صافي المرتجعات على قيد -COGS تحديداً فقط: نقلّص كلا الطرفين بنفس المقدار
                // (المُعكوس مسبقاً عبر المرتجع) حتى يبقى القيد متوازناً بعد التصحيح.
                if ($suffix === '-COGS' && $already_reversed_cogs > 0) {
                    if ($undo_debit > 0) { $undo_debit = max(0, $undo_debit - $already_reversed_cogs); }
                    if ($undo_credit > 0) { $undo_credit = max(0, $undo_credit - $already_reversed_cogs); }
                }
                if ($undo_debit == 0 && $undo_credit == 0) continue; // لا شيء متبقٍّ ليُعكَس لهذا السطر
                postJournalLine($conn, $line['account_id'], $undo_debit, $undo_credit, $undo_num, $today, $desc, 'Delivery Reversal');
            }
        }
    }
}

// ================================================================================
// نظام تتبّع دفعات المخزون (Inventory Batches — FIFO حقيقي بمصدر ومورّد لكل وحدة)
// بُني بناءً على طلب صريح من المستخدم بعد اكتشاف أن "تكلفة البضاعة المباعة" لمورد معيّن قد تتجاوز
// إجمالي ما اشتُري منه فعلياً — سبب ذلك: نفس المنتج يُشترى أحياناً من أكثر من مورد بأسعار مختلفة، بينما
// النظام كان يحتفظ بتكلفة واحدة "ممزوجة" فقط (products.cost_price_usd) لا تُميِّز مصدر كل وحدة.
// من الآن: كل دفعة شراء (أو جرد مكتبي) دفعة مستقلة بتكلفتها ومورّدها وكميتها المتبقية، وكل بيع يستهلك
// من أقدم دفعة متاحة أولاً (FIFO) — فتُنسَب تكلفة كل وحدة مباعة بدقة لمصدرها الحقيقي دائماً.
// ================================================================================

if (!function_exists('ensureInventoryBatchTables')) {
    /**
     * ينشئ جدولَي الدفعات واستهلاكها إن لم يكونا موجودين بعد — آمن للاستدعاء المتكرر من أي ملف.
     * تصحيح جوهري: أوامر DDL (CREATE TABLE / ALTER TABLE) تُنفِّذ التزاماً ضمنياً (implicit commit) في
     * MySQL حتى لو كان الجدول موجوداً بالفعل — فاستدعاء هذه الدالة مراراً من داخل حلقة ضمن معاملة صريحة
     * (كما في سكربت الترحيل) كان يُنهي تلك المعاملة بصمت في منتصف الحلقة، فيفشل rollBack() لاحقاً بخطأ
     * "there is no active transaction" بدل إظهار الخطأ الحقيقي. الآن تُنفَّذ أوامر DDL **مرة واحدة فقط**
     * لكل طلب صفحة (متغيّر ثابت static)، بغض النظر عن عدد مرات استدعاء هذه الدالة.
     */
    function ensureInventoryBatchTables($conn) {
        static $already_ensured = false;
        if ($already_ensured) return;
        $already_ensured = true;
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS inventory_batches (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                source_type VARCHAR(30) NOT NULL,
                source_ref VARCHAR(150),
                source_item_id INT NULL,
                supplier_id INT NULL,
                unit_cost_usd DECIMAL(15,4) NOT NULL,
                quantity_received DECIMAL(15,4) NOT NULL,
                quantity_remaining DECIMAL(15,4) NOT NULL,
                batch_date DATE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product_id (product_id),
                INDEX idx_supplier_id (supplier_id),
                INDEX idx_batch_date (batch_date),
                INDEX idx_source_item (source_item_id)
            )");
            $conn->exec("CREATE TABLE IF NOT EXISTS sale_item_batch_consumption (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_item_id INT NOT NULL,
                batch_id INT NOT NULL,
                quantity_consumed DECIMAL(15,4) NOT NULL,
                unit_cost_usd DECIMAL(15,4) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sale_item_id (sale_item_id),
                INDEX idx_batch_id (batch_id)
            )");
            // ترحيل آمن: إن كان الجدول قد أُنشئ سابقاً (قبل إضافة عمود الربط الدقيق source_item_id)، يُضاف
            // الآن دون أي خطأ إن كان موجوداً بالفعل.
            try {
                $ib_cols = $conn->query("SHOW COLUMNS FROM inventory_batches")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('source_item_id', $ib_cols)) {
                    $conn->exec("ALTER TABLE inventory_batches ADD COLUMN source_item_id INT NULL, ADD INDEX idx_source_item (source_item_id)");
                }
            } catch (Exception $e) { /* يُتجاهل إن تعذّر */ }
        } catch (Exception $e) { /* تُعتَمد على وجود صلاحية CREATE TABLE؛ تُتجاهل بصمت إن تعذّر */ }
    }
}

if (!function_exists('createInventoryBatch')) {
    /**
     * يُسجِّل دفعة مخزون جديدة (من فاتورة شراء أو جرد مكتبي). يُستدعى فور أي إدخال كمية جديدة للمخزون.
     * $source_type: 'Purchase' أو 'OfficeInventory'. $supplier_id يُترَك NULL للجرد المكتبي.
     * $source_item_id: معرّف السطر المصدر (purchase_invoice_items.id مثلاً) — يسمح بتحديث هذه الدفعة
     * بدقة لاحقاً إن عُدِّلت الفاتورة الأصلية، بدل خلق دفعة مكرِّرة أو ترك القديمة عالقة بلا تحديث.
     */
    function createInventoryBatch($conn, $product_id, $source_type, $source_ref, $supplier_id, $unit_cost_usd, $quantity, $batch_date, $source_item_id = null) {
        ensureInventoryBatchTables($conn);
        if ($quantity <= 0) return null;
        $stmt = $conn->prepare("
            INSERT INTO inventory_batches (product_id, source_type, source_ref, source_item_id, supplier_id, unit_cost_usd, quantity_received, quantity_remaining, batch_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$product_id, $source_type, $source_ref, $source_item_id, $supplier_id, $unit_cost_usd, $quantity, $quantity, $batch_date]);
        return $conn->lastInsertId();
    }
}

if (!function_exists('updateInventoryBatchForEditedPurchase')) {
    /**
     * يُحدِّث دفعة مخزون قائمة عند تعديل فاتورة شراء (بدل إنشاء دفعة مكرِّرة أو ترك القديمة بتكلفة/كمية
     * خاطئة). يُطابِق الدفعة عبر source_item_id (معرّف سطر فاتورة الشراء الأصلي) — إن لم توجد دفعة مطابقة
     * (فاتورة قديمة سابقة لتفعيل هذا النظام)، يُنشئ دفعة جديدة بدل الفشل بصمت.
     * $new_quantity الكمية الجديدة الكاملة للسطر (لا الفرق) — تُحدَّث quantity_received لها، وتُعدَّل
     * quantity_remaining بنفس مقدار الفرق فقط (حفاظاً على ما استُهلِك منها فعلياً عبر مبيعات سابقة).
     */
    function updateInventoryBatchForEditedPurchase($conn, $purchase_invoice_item_id, $product_id, $supplier_id, $new_unit_cost_usd, $new_quantity, $batch_date, $source_ref) {
        ensureInventoryBatchTables($conn);
        $stmt = $conn->prepare("SELECT id, quantity_received, quantity_remaining FROM inventory_batches WHERE source_type = 'Purchase' AND source_item_id = ?");
        $stmt->execute([$purchase_invoice_item_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            // لا دفعة مرتبطة (فاتورة أُنشئت قبل تفعيل نظام الدفعات) — تُنشأ الآن بدل تجاهل التعديل بصمت.
            createInventoryBatch($conn, $product_id, 'Purchase', $source_ref, $supplier_id, $new_unit_cost_usd, $new_quantity, $batch_date, $purchase_invoice_item_id);
            return;
        }

        $qty_delta = $new_quantity - floatval($batch['quantity_received']);
        $new_remaining = max(0, floatval($batch['quantity_remaining']) + $qty_delta);
        $conn->prepare("
            UPDATE inventory_batches
            SET product_id = ?, supplier_id = ?, unit_cost_usd = ?, quantity_received = ?, quantity_remaining = ?, batch_date = ?
            WHERE id = ?
        ")->execute([$product_id, $supplier_id, $new_unit_cost_usd, $new_quantity, $new_remaining, $batch_date, $batch['id']]);
    }
}

if (!function_exists('consumeInventoryBatchesFIFO')) {
    /**
     * يستهلك كمية معيّنة من منتج عبر دفعاته المتاحة بترتيب الأقدم أولاً (FIFO)، ويُسجِّل كل استهلاك
     * (قد يمتد لأكثر من دفعة واحدة إن نفدت الأقدم أثناء تلبية الكمية). يُعيد التكلفة الموزونة للوحدة
     * (لتخزينها في sale_items.cost_price_usd_at_sale كما كان سابقاً — توافقاً كاملاً مع كل تقرير قائم
     * يقرأ هذا الحقل، بلا الحاجة لتعديل أي تقرير آخر). إن لم تكفِ الدفعات المسجَّلة (بيانات قبل تفعيل هذا
     * النظام، أو نقص فعلي)، يُستكمَل الفارق من `products.cost_price_usd` الحالي كـ"دفعة تراثية" (Legacy)
     * بلا مورّد محدَّد — فلا ينكسر أي بيع قديم، ويبقى التقرير الجديد يعمل جنباً لجنب مع البيانات السابقة.
     */
    function consumeInventoryBatchesFIFO($conn, $product_id, $quantity_needed, $sale_item_id) {
        ensureInventoryBatchTables($conn);
        $quantity_needed = floatval($quantity_needed);
        if ($quantity_needed <= 0) return 0;

        $stmt = $conn->prepare("
            SELECT id, quantity_remaining, unit_cost_usd
            FROM inventory_batches
            WHERE product_id = ? AND quantity_remaining > 0
            ORDER BY batch_date ASC, id ASC
            FOR UPDATE
        ");
        try { $stmt->execute([$product_id]); }
        catch (Exception $e) { $stmt = $conn->prepare("SELECT id, quantity_remaining, unit_cost_usd FROM inventory_batches WHERE product_id = ? AND quantity_remaining > 0 ORDER BY batch_date ASC, id ASC"); $stmt->execute([$product_id]); }
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $remaining_to_consume = $quantity_needed;
        $total_cost = 0;
        foreach ($batches as $batch) {
            if ($remaining_to_consume <= 0.00001) break;
            $take = min($remaining_to_consume, floatval($batch['quantity_remaining']));
            if ($take <= 0) continue;

            $conn->prepare("UPDATE inventory_batches SET quantity_remaining = quantity_remaining - ? WHERE id = ?")
                 ->execute([$take, $batch['id']]);
            $conn->prepare("INSERT INTO sale_item_batch_consumption (sale_item_id, batch_id, quantity_consumed, unit_cost_usd) VALUES (?, ?, ?, ?)")
                 ->execute([$sale_item_id, $batch['id'], $take, $batch['unit_cost_usd']]);

            $total_cost += $take * floatval($batch['unit_cost_usd']);
            $remaining_to_consume -= $take;
        }

        // فارق غير مُغطّى بأي دفعة مسجَّلة (بيانات تراثية قبل هذا النظام، أو نقص فعلي في تسجيل الشراء) —
        // يُستكمَل من التكلفة الحالية الممزوجة القديمة، ويُسجَّل كـ"دفعة تراثية" افتراضية بلا مورّد، حتى
        // يبقى مجموع الكمية المُستهلَكة والتكلفة الموزونة صحيحاً دائماً مهما كانت حالة البيانات القديمة.
        if ($remaining_to_consume > 0.00001) {
            $stmt_p = $conn->prepare("SELECT cost_price_usd FROM products WHERE id = ?");
            $stmt_p->execute([$product_id]);
            $legacy_cost = floatval($stmt_p->fetchColumn());
            $legacy_batch_id = createInventoryBatch($conn, $product_id, 'Legacy', 'رصيد سابق قبل تفعيل نظام الدفعات', null, $legacy_cost, $remaining_to_consume, date('Y-m-d'));
            if ($legacy_batch_id) {
                $conn->prepare("UPDATE inventory_batches SET quantity_remaining = 0 WHERE id = ?")->execute([$legacy_batch_id]);
                $conn->prepare("INSERT INTO sale_item_batch_consumption (sale_item_id, batch_id, quantity_consumed, unit_cost_usd) VALUES (?, ?, ?, ?)")
                     ->execute([$sale_item_id, $legacy_batch_id, $remaining_to_consume, $legacy_cost]);
                $total_cost += $remaining_to_consume * $legacy_cost;
            }
        }

        return $quantity_needed > 0 ? ($total_cost / $quantity_needed) : 0;
    }
}

if (!function_exists('reduceInventoryBatchForPurchaseReturn')) {
    /**
     * يُنقِص دفعة مخزون عند إرجاع كمية منها لمورّد (لا استهلاك بيع، بل تصحيح دائم لما استُلِم فعلياً من
     * تلك الدفعة). يُطابِق الدفعة عبر source_item_id (معرّف سطر فاتورة الشراء الأصلي). يُنقِص كلاً من
     * الكمية المستلمة والمتبقية بنفس المقدار — بخلاف بيع عادي، هذه الكمية لن تعود للمخزون مطلقاً.
     */
    function reduceInventoryBatchForPurchaseReturn($conn, $purchase_invoice_item_id, $quantity_returned) {
        ensureInventoryBatchTables($conn);
        $quantity_returned = floatval($quantity_returned);
        if ($quantity_returned <= 0) return;
        $stmt = $conn->prepare("SELECT id, quantity_received, quantity_remaining FROM inventory_batches WHERE source_type = 'Purchase' AND source_item_id = ?");
        $stmt->execute([$purchase_invoice_item_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) return; // لا دفعة مرتبطة (فاتورة سابقة لتفعيل هذا النظام) — لا شيء لتصحيحه هنا

        $new_received = max(0, floatval($batch['quantity_received']) - $quantity_returned);
        $new_remaining = max(0, floatval($batch['quantity_remaining']) - $quantity_returned);
        $conn->prepare("UPDATE inventory_batches SET quantity_received = ?, quantity_remaining = ? WHERE id = ?")
             ->execute([$new_received, $new_remaining, $batch['id']]);
    }
}

if (!function_exists('restoreInventoryBatchForReturn')) {
    /**
     * يعكس استهلاك دفعات مخزون لسطر بيع معيّن عند إرجاع كمية منه — يُعيد الكمية للدفعة/الدفعات التي
     * استُهلِكت منها أصلاً، بترتيب عكسي (الأحدث استهلاكاً أولاً LIFO-reversal، الأقرب تقنياً لواقع أي
     * إرجاع فعلي). يُعيد أيضاً التكلفة الموزونة للكمية المُرجَعة تحديداً (لاستخدامها في قيد عكس COGS).
     */
    function restoreInventoryBatchForReturn($conn, $sale_item_id, $quantity_returned) {
        ensureInventoryBatchTables($conn);
        $quantity_returned = floatval($quantity_returned);
        if ($quantity_returned <= 0) return 0;

        $stmt = $conn->prepare("
            SELECT id, batch_id, quantity_consumed, unit_cost_usd
            FROM sale_item_batch_consumption
            WHERE sale_item_id = ?
            ORDER BY id DESC
        ");
        $stmt->execute([$sale_item_id]);
        $consumptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $remaining_to_restore = $quantity_returned;
        $total_cost = 0;
        foreach ($consumptions as $c) {
            if ($remaining_to_restore <= 0.00001) break;
            $restore_qty = min($remaining_to_restore, floatval($c['quantity_consumed']));
            if ($restore_qty <= 0) continue;

            $conn->prepare("UPDATE inventory_batches SET quantity_remaining = quantity_remaining + ? WHERE id = ?")
                 ->execute([$restore_qty, $c['batch_id']]);

            if ($restore_qty >= floatval($c['quantity_consumed']) - 0.00001) {
                $conn->prepare("DELETE FROM sale_item_batch_consumption WHERE id = ?")->execute([$c['id']]);
            } else {
                $conn->prepare("UPDATE sale_item_batch_consumption SET quantity_consumed = quantity_consumed - ? WHERE id = ?")
                     ->execute([$restore_qty, $c['id']]);
            }

            $total_cost += $restore_qty * floatval($c['unit_cost_usd']);
            $remaining_to_restore -= $restore_qty;
        }

        return $quantity_returned > 0 ? ($total_cost / $quantity_returned) : 0;
    }
}