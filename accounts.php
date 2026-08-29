<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

$msg = "";

// معالجة إضافة حساب جديد
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_account'])) {
    $account_code = trim($_POST['account_code']);
    $account_name = trim($_POST['account_name']);
    $account_type = $_POST['account_type'];
    $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;

    if (!empty($account_code) && !empty($account_name)) {
        try {
            $stmt = $conn->prepare("INSERT INTO accounts (account_code, account_name, account_type, parent_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$account_code, $account_name, $account_type, $parent_id]);
            $new_acc_id = $conn->lastInsertId();
            logAudit($conn, 'INSERT', 'شجرة الحسابات', "إضافة حساب جديد: $account_code - $account_name (النوع: $account_type)", $new_acc_id);
            $msg = "<div style='background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;'><i class='fas fa-check-circle'></i> تم إضافة الحساب بنجاح إلى شجرة الحسابات!</div>";
        } catch (PDOException $e) {
            $msg = "<div style='background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;'><i class='fas fa-exclamation-triangle'></i> خطأ: رمز الحساب مستخدم مسبقاً.</div>";
        }
    }
}

// معالجة إكمال بيانات حساب ناقص (أُنشئ تلقائياً من وحدة أخرى في النظام عبر findAccountId
// ولم يُملأ له رمز أو نوع حساب حينها)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_account'])) {
    $acc_id = intval($_POST['acc_id'] ?? 0);
    $account_code = trim($_POST['account_code'] ?? '');
    $account_type = $_POST['account_type'] ?? '';

    if ($acc_id > 0 && !empty($account_code)) {
        try {
            $stmt = $conn->prepare("UPDATE accounts SET account_code = ?, account_type = ? WHERE id = ?");
            $stmt->execute([$account_code, $account_type, $acc_id]);
            logAudit($conn, 'UPDATE', 'شجرة الحسابات', "إكمال بيانات حساب ناقص #{$acc_id} — الرمز: {$account_code}، النوع: {$account_type}", $acc_id);
            $msg = "<div style='background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;'><i class='fas fa-check-circle'></i> تم إكمال بيانات الحساب بنجاح!</div>";
        } catch (PDOException $e) {
            $msg = "<div style='background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;'><i class='fas fa-exclamation-triangle'></i> خطأ: رمز الحساب مستخدم مسبقاً لحساب آخر.</div>";
        }
    }
}

// دالة مساعدة: إيجاد أول رمز حساب متاح بدءاً من الرمز المقترح (تفادي تعارض unique)
function getNextAvailableCode($conn, $preferred_code) {
    $code = $preferred_code;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
    for ($i = 0; $i < 50; $i++) {
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() == 0) { return $code; }
        $code = (string)(intval($code) + 1);
    }
    return $preferred_code . '-' . substr(uniqid(), -4);
}

// معالجة الإنشاء/الإكمال التلقائي الجماعي لكل الحسابات الأساسية التي يبحث عنها findAccountId
// عبر النظام كله، بحيث لا تحتاج هذه الدالة للإنشاء التلقائي الناقص مستقبلاً
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['seed_accounts'])) {
    // القائمة مطابقة تماماً لأسماء fallback_name المستخدمة في findAccountId بكل ملفات النظام،
    // بحيث تُطابق بالاسم الحرفي أي حساب أُنشئ تلقائياً سابقاً بنفس هذا الاسم فتُكمله بدل تكراره.
    $seed_list = [
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

    // إضافة تصنيفات المصاريف الفعلية المستخدمة (من المصاريف الفورية والبنود المتكررة) كحسابات مصروفات منفصلة
    try {
        $cat_stmt = $conn->query("
            SELECT DISTINCT category AS cat FROM operational_expenses WHERE category IS NOT NULL AND category != ''
            UNION
            SELECT DISTINCT category AS cat FROM recurring_expense_templates WHERE category IS NOT NULL AND category != ''
        ");
        $existing_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
        $next_expense_code = 5141;
        foreach ($existing_categories as $cat) {
            $seed_list[] = ['name' => $cat, 'type' => 'Expense', 'code' => (string)$next_expense_code];
            $next_expense_code++;
        }
    } catch (Exception $e) {
        // في حال عدم توفر جدولَي المصاريف بعد، يُتجاهل هذا الجزء بصمت
    }

    $created = 0; $completed = 0; $skipped = 0;
    try {
        foreach ($seed_list as $item) {
            $stmt_find = $conn->prepare("SELECT id, account_code, account_type FROM accounts WHERE account_name = ? LIMIT 1");
            $stmt_find->execute([$item['name']]);
            $existing = $stmt_find->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if (empty($existing['account_code']) || empty($existing['account_type'])) {
                    $code = !empty($existing['account_code']) ? $existing['account_code'] : getNextAvailableCode($conn, $item['code']);
                    $type = !empty($existing['account_type']) ? $existing['account_type'] : $item['type'];
                    $conn->prepare("UPDATE accounts SET account_code = ?, account_type = ? WHERE id = ?")->execute([$code, $type, $existing['id']]);
                    $completed++;
                } else {
                    $skipped++;
                }
            } else {
                $code = getNextAvailableCode($conn, $item['code']);
                $conn->prepare("INSERT INTO accounts (account_code, account_name, account_type) VALUES (?, ?, ?)")->execute([$code, $item['name'], $item['type']]);
                $created++;
            }
        }
        $msg = "<div style='background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;'><i class='fas fa-check-circle'></i> تم: إنشاء {$created} حساب جديد، إكمال {$completed} حساب ناقص، وتخطي {$skipped} حساب مكتمل مسبقاً.</div>";
        if ($created > 0 || $completed > 0) {
            logAudit($conn, 'INSERT', 'شجرة الحسابات', "تشغيل الإنشاء التلقائي الجماعي للحسابات: إنشاء {$created}، إكمال {$completed}، تخطي {$skipped}");
        }
    } catch (Exception $e) {
        $msg = "<div style='background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;'><i class='fas fa-exclamation-triangle'></i> خطأ أثناء الإنشاء التلقائي: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// معالجة حذف حساب واحد، بشرط ألا يكون مستخدَماً في أي قيد محاسبي ولا حساباً أباً لحسابات فرعية
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_account'])) {
    requireRole($conn, ['admin']);
    $acc_id = intval($_POST['acc_id'] ?? 0);
    try {
        $stmt_usage = $conn->prepare("SELECT COUNT(*) FROM journal_entries WHERE account_id = ?");
        $stmt_usage->execute([$acc_id]);
        $usage_count = $stmt_usage->fetchColumn();

        $stmt_children = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE parent_id = ?");
        $stmt_children->execute([$acc_id]);
        $children_count = $stmt_children->fetchColumn();

        if ($usage_count > 0) {
            $msg = "<div style='background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;'><i class='fas fa-lock'></i> لا يمكن حذف هذا الحساب: مستخدَم في $usage_count سطر قيد محاسبي.</div>";
        } elseif ($children_count > 0) {
            $msg = "<div style='background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;'><i class='fas fa-lock'></i> لا يمكن حذف هذا الحساب: هو حساب أب لـ $children_count حساب فرعي.</div>";
        } else {
            $stmt_name_before = $conn->prepare("SELECT account_code, account_name FROM accounts WHERE id = ?");
            $stmt_name_before->execute([$acc_id]);
            $acc_before = $stmt_name_before->fetch(PDO::FETCH_ASSOC);

            $conn->prepare("DELETE FROM accounts WHERE id = ?")->execute([$acc_id]);
            logAudit($conn, 'DELETE', 'شجرة الحسابات', "حذف حساب: " . ($acc_before['account_code'] ?? '') . " - " . ($acc_before['account_name'] ?? "حساب #$acc_id"), $acc_id);
            $msg = "<div style='background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;'><i class='fas fa-check-circle'></i> تم حذف الحساب بنجاح.</div>";
        }
    } catch (Exception $e) {
        $msg = "<div style='background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;'><i class='fas fa-exclamation-triangle'></i> خطأ أثناء الحذف: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// معالجة حذف كل الحسابات غير المستخدمة دفعة واحدة (لا في أي قيد محاسبي ولا أب لأي حساب فرعي)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cleanup_unused'])) {
    requireRole($conn, ['admin']);
    try {
        // التقاط أسماء الحسابات المرشَّحة للحذف قبل تنفيذ الحذف الفعلي، لتوثيقها في سجل التدقيق
        $stmt_candidates = $conn->query("
            SELECT account_code, account_name FROM accounts
            WHERE id NOT IN (SELECT DISTINCT account_id FROM journal_entries WHERE account_id IS NOT NULL)
            AND id NOT IN (SELECT parent_id FROM (SELECT DISTINCT parent_id FROM accounts WHERE parent_id IS NOT NULL) AS t)
        ");
        $candidates = $stmt_candidates->fetchAll(PDO::FETCH_ASSOC);
        $candidate_names = array_map(fn($a) => ($a['account_code'] ?: '—') . '-' . $a['account_name'], $candidates);

        $stmt_del = $conn->prepare("
            DELETE FROM accounts 
            WHERE id NOT IN (SELECT DISTINCT account_id FROM journal_entries WHERE account_id IS NOT NULL)
            AND id NOT IN (SELECT parent_id FROM (SELECT DISTINCT parent_id FROM accounts WHERE parent_id IS NOT NULL) AS t)
        ");
        $stmt_del->execute();
        $deleted_count = $stmt_del->rowCount();
        $msg = "<div style='background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;'><i class='fas fa-check-circle'></i> تم حذف $deleted_count حساب غير مستخدَم.</div>";
        if ($deleted_count > 0) {
            logAudit($conn, 'DELETE', 'شجرة الحسابات', "تنظيف جماعي: حذف $deleted_count حساب غير مستخدَم — " . implode(', ', array_slice($candidate_names, 0, 20)) . (count($candidate_names) > 20 ? ' ...' : ''));
        }
    } catch (Exception $e) {
        $msg = "<div style='background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;'><i class='fas fa-exclamation-triangle'></i> خطأ أثناء التنظيف الجماعي: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// جلب كافة الحسابات للقائمة المنسدلة والجدول
try {
    $all_accounts = $conn->query("SELECT * FROM accounts ORDER BY account_code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_accounts = [];
    $msg .= "<div style='background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;'><i class='fas fa-exclamation-triangle'></i> تعذّر جلب دليل الحسابات: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// كشف الحسابات "الناقصة" التي أنشأتها الوحدات الأخرى تلقائياً (بلا رمز أو بلا نوع) عبر findAccountId
// ليتمكن المستخدم من إكمالها هنا بدل بقائها بيانات غير مكتملة تكسر منطق هذه الصفحة وتقارير أخرى
$incomplete_accounts = array_filter($all_accounts, function($acc) {
    return empty($acc['account_code']) || empty($acc['account_type']);
});

// احتساب حالة الاستخدام لكل حساب (هل يظهر في أي قيد محاسبي؟ وهل هو حساب أب لحسابات فرعية؟)
// يُستخدم لتحديد إمكانية عرض زر الحذف الآمن لكل صف في الجدول
$usage_counts = [];
try {
    $stmt_usage = $conn->query("SELECT account_id, COUNT(*) AS cnt FROM journal_entries WHERE account_id IS NOT NULL GROUP BY account_id");
    foreach ($stmt_usage->fetchAll(PDO::FETCH_ASSOC) as $row) { $usage_counts[$row['account_id']] = intval($row['cnt']); }
} catch (Exception $e) { /* يُتجاهل بصمت إن تعذّر الاستعلام */ }

$children_counts = [];
foreach ($all_accounts as $acc) {
    if (!empty($acc['parent_id'])) {
        $children_counts[$acc['parent_id']] = ($children_counts[$acc['parent_id']] ?? 0) + 1;
    }
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="color: #2e384d; margin-bottom: 5px;"><i class="fas fa-sitemap"></i> إدارة شجرة الحسابات الذكية والهرمية</h2>
        <p style="color: #6c757d; margin: 0; font-size: 14px;">الهيكل المحاسبي المرن لإدارة الأصول، الخصوم، حقوق الملكية، الإيرادات، والمصروفات.</p>
    </div>
    <div style="display: flex; gap: 8px;">
        <form method="POST" onsubmit="return confirm('سيتم إنشاء أي حساب أساسي مفقود وإكمال أي حساب ناقص البيانات تلقائياً. متابعة؟');">
<?php csrfField(); ?>
            <input type="hidden" name="seed_accounts" value="1">
            <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">
                <i class="fas fa-magic"></i> إنشاء الحسابات الأساسية تلقائياً
            </button>
        </form>
        <form method="POST" onsubmit="return confirm('سيتم حذف كل الحسابات غير المستخدمة في أي قيد محاسبي وغير الأب لأي حساب فرعي. هذا الإجراء لا يمكن التراجع عنه. متابعة؟');">
<?php csrfField(); ?>
            <input type="hidden" name="cleanup_unused" value="1">
            <button type="submit" style="background: #e74a3b; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">
                <i class="fas fa-broom"></i> حذف الحسابات غير المستخدمة
            </button>
        </form>
    </div>
</div>

<?php echo $msg; ?>

<?php if (count($incomplete_accounts) > 0): ?>
<!-- تنبيه وإكمال الحسابات التي أُنشئت تلقائياً من وحدات أخرى (مبيعات، مندوبين، موردين، رواتب...) -->
<div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px;">
    <h3 style="margin: 0 0 5px 0; color: #856404; font-size: 15px;"><i class="fas fa-exclamation-triangle"></i> حسابات أُنشئت تلقائياً وتحتاج إكمالاً (<?php echo count($incomplete_accounts); ?>)</h3>
    <p style="margin: 0 0 15px 0; color: #856404; font-size: 12.5px;">
        هذه الحسابات أنشأتها وحدات أخرى في النظام (فواتير، عمولات، رواتب، مصاريف...) تلقائياً عند عدم إيجاد حساب مطابق، ولم تُملأ لها رمز أو نوع محاسبي بعد. أكملها الآن لضمان دقة القوائم المالية.
    </p>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: right; background: #fff; border-radius: 6px; overflow: hidden;">
            <thead>
                <tr style="background: #fdf6e3; color: #856404;">
                    <th style="padding: 8px 12px;">اسم الحساب</th>
                    <th style="padding: 8px 12px;">رمز الحساب</th>
                    <th style="padding: 8px 12px;">نوع الحساب</th>
                    <th style="padding: 8px 12px; text-align: center;">حفظ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($incomplete_accounts as $acc): ?>
                    <tr style="border-top: 1px solid #f1e5c0;">
                        <td style="padding: 8px 12px; font-weight: bold;"><?php echo htmlspecialchars($acc['account_name']); ?></td>
                        <td style="padding: 4px 12px;" colspan="3">
                            <form method="POST" style="display: flex; gap: 8px; align-items: center;">
<?php csrfField(); ?>
                                <input type="hidden" name="complete_account" value="1">
                                <input type="hidden" name="acc_id" value="<?php echo $acc['id']; ?>">
                                <input type="text" name="account_code" required placeholder="رمز الحساب مثال: 1112" style="flex: 1; padding: 6px 10px; border: 1px solid #d1d3e2; border-radius: 4px; font-family: monospace;">
                                <select name="account_type" required style="flex: 1; padding: 6px 10px; border: 1px solid #d1d3e2; border-radius: 4px;">
                                    <option value="">-- اختر النوع --</option>
                                    <option value="Asset">أصول (Asset)</option>
                                    <option value="Liability">خصوم (Liability)</option>
                                    <option value="Equity">حقوق ملكية (Equity)</option>
                                    <option value="Revenue">إيرادات (Revenue)</option>
                                    <option value="Expense">مصروفات (Expense)</option>
                                </select>
                                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; white-space: nowrap;">حفظ</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">
    
    <!-- نموذج إضافة حساب -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08); flex: 1; min-width: 320px;">
        <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0;">
            <h3 style="margin: 0; color: #4e73df; font-size: 16px;"><i class="fas fa-plus-circle"></i> إضافة حساب جديد</h3>
        </div>
        <div style="padding: 20px;">
            <form method="POST" action="">
<?php csrfField(); ?>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 13px; color: #333;">رمز الحساب (Account Code)</label>
                    <input type="text" name="account_code" required style="width: 100%; padding: 9px 12px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace; font-size: 14px;" placeholder="مثال: 1113">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 13px; color: #333;">اسم الحساب</label>
                    <input type="text" name="account_name" required style="width: 100%; padding: 9px 12px; border: 1px solid #d1d3e2; border-radius: 6px; font-size: 14px;" placeholder="مثال: صندوق الفرع الرئيسي">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 13px; color: #333;">نوع الحساب الرئيسي</label>
                    <select name="account_type" required style="width: 100%; padding: 9px 12px; border: 1px solid #d1d3e2; border-radius: 6px; font-size: 14px; background: #fff;">
                        <option value="Asset">أصول (Asset)</option>
                        <option value="Liability">خصوم (Liability)</option>
                        <option value="Equity">حقوق ملكية (Equity)</option>
                        <option value="Revenue">إيرادات (Revenue)</option>
                        <option value="Expense">مصروفات (Expense)</option>
                    </select>
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 13px; color: #333;">يتبع للحساب (اختيار الحساب الأب)</label>
                    <select name="parent_id" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d3e2; border-radius: 6px; font-size: 14px; background: #fff;">
                        <option value="">-- حساب رئيسي (مستقل) --</option>
                        <?php foreach ($all_accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars(($acc['account_code'] ?: '—') . ' - ' . $acc['account_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="add_account" style="width: 100%; background: #4e73df; color: white; border: none; padding: 10px; border-radius: 6px; font-weight: bold; font-size: 14px; cursor: pointer; transition: background 0.2s;">
                    <i class="fas fa-save"></i> حفظ الحساب في الشجرة
                </button>
            </form>
        </div>
    </div>

    <!-- جدول عرض دليل الحسابات مع فلتر البحث -->
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08); flex: 2; min-width: 450px;">
        <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <h3 style="margin: 0; color: #2e384d; font-size: 16px;"><i class="fas fa-list-alt"></i> دليل الحسابات الهرمي</h3>
            
            <!-- خانة البحث الفوري -->
            <div style="position: relative; width: 250px;">
                <span style="position: absolute; right: 10px; top: 10px; color: #aaa; font-size: 13px;"><i class="fas fa-search"></i></span>
                <input type="text" id="accountSearch" placeholder="ابحث برمز أو اسم الحساب..." onkeyup="filterAccounts()" style="width: 100%; padding: 7px 30px 7px 10px; border: 1px solid #d1d3e2; border-radius: 6px; font-size: 13px; outline: none;">
            </div>
        </div>
        
        <div style="overflow-x: auto;">
            <table id="accountsTable" style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: right;">
                <thead>
                    <tr style="background: #fdfdfe; color: #555; border-bottom: 2px solid #e3e6f0;">
                        <th style="padding: 12px 15px;">الرمز</th>
                        <th style="padding: 12px 15px;">اسم الحساب</th>
                        <th style="padding: 12px 15px;">النوع المحاسبي</th>
                        <th style="padding: 12px 15px; text-align: center;">الحالة</th>
                        <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($all_accounts) > 0): ?>
                        <?php foreach ($all_accounts as $acc): 
                            // تخصيص لون شارة نوع الحساب لاحترافية أعلى
                            $type_bg = "#eef1f6";
                            $type_color = "#333";
                            switch($acc['account_type']) {
                                case 'Asset':
                                    $type_bg = '#d1ecf1'; $type_color = '#0c5460'; break;
                                case 'Liability':
                                    $type_bg = '#f8d7da'; $type_color = '#721c24'; break;
                                case 'Equity':
                                    $type_bg = '#e2d9f3'; $type_color = '#4b3869'; break;
                                case 'Revenue':
                                    $type_bg = '#d4edda'; $type_color = '#155724'; break;
                                case 'Expense':
                                    $type_bg = '#fff3cd'; $type_color = '#856404'; break;
                            }
                            $is_incomplete = empty($acc['account_code']) || empty($acc['account_type']);
                            $in_use = ($usage_counts[$acc['id']] ?? 0) > 0;
                            $has_children = ($children_counts[$acc['id']] ?? 0) > 0;
                            $can_delete = !$in_use && !$has_children;
                        ?>
                            <tr style="border-bottom: 1px solid #f1f1f1; transition: background 0.1s; <?php echo $is_incomplete ? 'background: #fffbf0;' : ''; ?>" onmouseover="this.style.background='#fcfcfc'" onmouseout="this.style.background='<?php echo $is_incomplete ? '#fffbf0' : 'transparent'; ?>'">
                                <td style="padding: 11px 15px; font-weight: bold; font-family: monospace; color: #4e73df;"><?php echo $acc['account_code'] ? htmlspecialchars($acc['account_code']) : '<span style="color:#e74a3b; font-style: italic;">بلا رمز</span>'; ?></td>
                                <td style="padding: 11px 15px; padding-right: <?php echo $acc['parent_id'] ? '30px' : '15px'; ?>; color: #333;">
                                    <?php echo ($acc['parent_id'] ? '<span style="color: #adb5bd; margin-left: 5px;">└─</span> ' : '') . htmlspecialchars($acc['account_name']); ?>
                                </td>
                                <td style="padding: 11px 15px;">
                                    <?php if ($acc['account_type']): ?>
                                        <span style="background: <?php echo $type_bg; ?>; color: <?php echo $type_color; ?>; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">
                                            <?php echo htmlspecialchars($acc['account_type']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #e74a3b; font-size: 11px; font-style: italic;">بلا نوع</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 11px 15px; text-align: center;">
                                    <?php if ($is_incomplete): ?>
                                        <span style="background: #fff3cd; color: #856404; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">ناقص البيانات</span>
                                    <?php else: ?>
                                        <span style="background: #d4edda; color: #155724; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">نشط</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 11px 15px; text-align: center;">
                                    <?php if ($can_delete): ?>
                                        <form method="POST" class="delete-account-form" data-name="<?php echo htmlspecialchars($acc['account_name'], ENT_QUOTES); ?>" style="display: inline;">
<?php csrfField(); ?>
                                            <input type="hidden" name="delete_account" value="1">
                                            <input type="hidden" name="acc_id" value="<?php echo $acc['id']; ?>">
                                            <button type="submit" style="background: #e74a3b; color: white; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 11px;" title="حذف — غير مستخدم في أي قيد">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: #aaa; font-size: 11px;" title="<?php echo $in_use ? 'مستخدَم في قيود محاسبية' : 'حساب أب لحسابات فرعية'; ?>">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="padding: 25px; text-align: center; color: #777;">لا توجد حسابات مضافة في الدليل حتى الآن.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- كود جافاسكريبت للبحث الفوري اللحظي -->
<script>
function filterAccounts() {
    let input = document.getElementById('accountSearch').value.toLowerCase();
    let table = document.getElementById('accountsTable');
    let tr = table.getElementsByTagName('tr');
    
    for (let i = 1; i < tr.length; i++) {
        let cells = tr[i].getElementsByTagName('td');
        // تصحيح: صف "لا توجد حسابات" له خلية واحدة فقط (colspan) وكان يُسبب خطأ JS عند البحث فيه
        if (cells.length < 2) { continue; }

        let codeValue = cells[0].textContent || cells[0].innerText;
        let nameValue = cells[1].textContent || cells[1].innerText;

        if (codeValue.toLowerCase().indexOf(input) > -1 || nameValue.toLowerCase().indexOf(input) > -1) {
            tr[i].style.display = "";
        } else {
            tr[i].style.display = "none";
        }
    }
}

// معالج تأكيد الحذف عبر JS خارجي (بدل تضمين PHP داخل خاصية onsubmit مباشرة، وهو ما تسبب سابقاً
// بانكسار وسم النموذج أثناء الحقن الآلي لحماية CSRF)
document.querySelectorAll('.delete-account-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var name = form.getAttribute('data-name');
        if (!confirm('حذف الحساب "' + name + '" نهائياً؟')) {
            e.preventDefault();
        }
    });
});
</script>

<?php include 'footer.php'; ?>