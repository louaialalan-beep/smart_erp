<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

$msg = "";
$error = "";

// 1. معالجة إضافة عملة جديدة
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_currency'])) {
    $code = strtoupper(trim($_POST['currency_code']));
    $name = trim($_POST['currency_name']);

    if (!empty($code) && !empty($name)) {
        try {
            // تصحيح: الجدول الفعلي في بعض التثبيتات لا يحتوي عمود status إطلاقاً (يحتوي بدلاً منه
            // exchange_rate وupdated_at من نسخة أقدم من التصميم)، فكان أي إدراج يفشل بخطأ "عمود غير
            // موجود" ويظهر برسالة مضلِّلة "العملة موجودة مسبقاً". الآن نتحقق من الأعمدة الفعلية ديناميكياً.
            $cur_cols = $conn->query("SHOW COLUMNS FROM currencies")->fetchAll(PDO::FETCH_COLUMN);
            $cols_to_insert = ['currency_code', 'currency_name', 'is_base'];
            $vals = [$code, $name, 0];
            if (in_array('status', $cur_cols)) { $cols_to_insert[] = 'status'; $vals[] = 1; }
            if (in_array('exchange_rate', $cur_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = 1; }

            $placeholders = implode(',', array_fill(0, count($cols_to_insert), '?'));
            $col_names = implode(',', $cols_to_insert);
            $stmt = $conn->prepare("INSERT INTO currencies ({$col_names}) VALUES ({$placeholders})");
            $stmt->execute($vals);
            logAudit($conn, 'INSERT', 'إدارة العملات', "إضافة عملة جديدة: $code - $name");
            $msg = "تم إضافة العملة ($code) بنجاح.";
        } catch (Exception $e) {
            $error = "خطأ أثناء إضافة العملة: " . $e->getMessage();
        }
    } else {
        $error = "الرجاء إدخال رمز ورسم العملة بشكل صحيح.";
    }
}

// 2. معالجة إضافة/تحديث سعر صرف جديد ليوم معين
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_exchange_rate'])) {
    $currency_code = trim($_POST['rate_currency_code']);
    $rate_date = $_POST['rate_date'];
    $exchange_rate = floatval($_POST['exchange_rate']);

    if (!empty($currency_code) && !empty($rate_date) && $exchange_rate > 0) {
        try {
            // التحقق إذا كان هناك سعر مسجل بنفس التاريخ لنفس العملة لتحديثه أو إضافته
            $stmt_check = $conn->prepare("SELECT id FROM exchange_rates WHERE currency_code = ? AND rate_date = ?");
            $stmt_check->execute([$currency_code, $rate_date]);
            $existing = $stmt_check->fetch();

            if ($existing) {
                $stmt_update = $conn->prepare("UPDATE exchange_rates SET exchange_rate = ? WHERE id = ?");
                $stmt_update->execute([$exchange_rate, $existing['id']]);
                logAudit($conn, 'UPDATE', 'أسعار الصرف', "تحديث سعر صرف $currency_code بتاريخ $rate_date إلى " . number_format($exchange_rate, 6), $existing['id']);
                $msg = "تم تحديث سعر الصرف للعملة ($currency_code) بتاريخ ($rate_date) بنجاح.";
            } else {
                $stmt_insert = $conn->prepare("INSERT INTO exchange_rates (currency_code, rate_date, exchange_rate) VALUES (?, ?, ?)");
                $stmt_insert->execute([$currency_code, $rate_date, $exchange_rate]);
                $new_rate_id = $conn->lastInsertId();
                logAudit($conn, 'INSERT', 'أسعار الصرف', "تسجيل سعر صرف جديد لـ $currency_code بتاريخ $rate_date: " . number_format($exchange_rate, 6), $new_rate_id);
                $msg = "تمت إضافة سعر الصرف الجديد بنجاح.";
            }
        } catch (Exception $e) {
            $error = "حدث خطأ أثناء حفظ سعر الصرف: " . $e->getMessage();
        }
    } else {
        $error = "الرجاء إدخال بيانات صحيحة لسعر الصرف والتاريخ.";
    }
}

// 3. معالجة تعيين العملة الأساسية للنظام
// تصحيح جوهري: هذه الميزة مُعطَّلة عملياً حالياً — كل ملفات النظام الأخرى (journal.php, sales.php,
// functions.php, reports.php...) مبنية بافتراض ثابت (Hardcoded) أن العملة الأساسية هي SYP حصراً،
// وأن USD هي العملة الأجنبية الوحيدة المدعومة مقابلها. تفعيل هذا الزر فعلياً على أي عملة غير SYP
// سيُحدث تضارباً صامتاً في كل القيود والتقارير دون أي رسالة خطأ ظاهرة، لأن بقية الملفات لن "تعرف"
// أن العملة الأساسية تغيّرت — ستستمر بمعاملة SYP كأساس والقيم كأنها بالليرة رغم عكس ذلك هنا.
// دعم عملة أساسية ديناميكية فعلياً يتطلب تعديل كل ملف يتعامل مع القيود والتقارير، وهو خارج نطاق هذا الإصلاح.
// تصحيح أمني احترازي: تحوَّل من GET إلى POST محمي بـCSRF (رغم عدم وجود رابط فعلي قابل للنقر حالياً،
// إذ الميزة مُقفَلة عمداً لغير SYP — راجع التعليق أدناه)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_base']) && !empty($_POST['set_base'])) {
    $base_code = $_POST['set_base'];
    if ($base_code !== 'SYP') {
        $error = "تعذّر التنفيذ: تغيير العملة الأساسية عن الليرة السورية (SYP) غير مدعوم حالياً — بقية وحدات النظام (المبيعات، القيود، التقارير) مبنية بافتراض ثابت أن SYP هي العملة الأساسية، وتفعيل هذا التغيير سيُسبب تضارباً صامتاً في كل الحسابات. يلزم تعديل شامل عبر النظام قبل تفعيل هذه الميزة بأمان.";
    } else {
        try {
            $conn->beginTransaction();
            $conn->query("UPDATE currencies SET is_base = 0");
            $stmt = $conn->prepare("UPDATE currencies SET is_base = 1 WHERE currency_code = ?");
            $stmt->execute([$base_code]);
            $conn->commit();
            logAudit($conn, 'UPDATE', 'إدارة العملات', "تأكيد العملة الأساسية للنظام: $base_code");
            $msg = "العملة الأساسية للنظام مؤكدة كـ ($base_code).";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "فشل تحديث العملة الأساسية.";
        }
    }
}

// 4. حذف عملة مُضافة بالخطأ (مثل كتابة رمز خاطئ SYR بدل SYP) — لم تكن هذه الميزة موجودة إطلاقاً
// سابقاً، فلا توجد وسيلة لتصحيح رمز عملة أُدخل خطأً سوى حذفه وإعادة إضافته بالرمز الصحيح.
// محمية: لا يمكن حذف العملة الأساسية الحالية (SYP) حتى لا يبقى النظام بلا عملة أساسية إطلاقاً.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_currency'])) {
    $del_code = trim($_POST['delete_currency']);
    if ($del_code === 'SYP') {
        $error = "لا يمكن حذف العملة الأساسية للنظام (SYP).";
    } else {
        try {
            $conn->prepare("DELETE FROM currencies WHERE currency_code = ? AND is_base = 0")->execute([$del_code]);
            $conn->prepare("DELETE FROM exchange_rates WHERE currency_code = ?")->execute([$del_code]);
            logAudit($conn, 'DELETE', 'إدارة العملات', "حذف العملة: $del_code");
            $msg = "تم حذف العملة ($del_code) وسجل أسعار صرفها بنجاح.";
        } catch (Exception $e) {
            $error = "خطأ أثناء حذف العملة: " . $e->getMessage();
        }
    }
}

// جلب القوائم للعرض
$currencies = $conn->query("SELECT * FROM currencies ORDER BY is_base DESC, currency_code ASC")->fetchAll(PDO::FETCH_ASSOC);

// فلتر وسجل أسعار الصرف
$filter_cur = $_GET['filter_currency'] ?? '';
$rate_where = "1=1";
$rate_params = [];
if (!empty($filter_cur)) {
    $rate_where .= " AND currency_code = ?";
    $rate_params[] = $filter_cur;
}

$rates_sql = "SELECT * FROM exchange_rates WHERE $rate_where ORDER BY rate_date DESC, id DESC LIMIT 50";
$rates_stmt = $conn->prepare($rates_sql);
$rates_stmt->execute($rate_params);
$exchange_rates_history = $rates_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .panel-box {
        background: #fff;
        border: 1px solid #e3e6f0;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 25px;
        box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);
    }
    .badge-base { background: #1cc88a; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px; }
    .badge-normal { background: #6c757d; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px; }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2>محرك العملات وأسعار الصرف التاريخية</h2>
        <p style="color: #666; margin: 0;">إدارة العملات، وتثبيت أسعار الصرف اليومية لضمان الحصانة التاريخية للقوائم المالية.</p>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; color: #0c5460; font-size: 13px;">
    <i class="fas fa-info-circle"></i> <strong>تنبيه توافق:</strong> العملة الأساسية للنظام مثبَّتة حالياً على الليرة السورية (SYP) في كل الوحدات. لا يمكن تغييرها من هنا دون تعديل شامل عبر النظام بأكمله.
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
    
    <!-- قسم إضافة عملة جديدة -->
    <div class="panel-box" style="margin-bottom: 0;">
        <h4 style="margin-top: 0; color: #4e73df; border-bottom: 1px solid #eee; padding-bottom: 10px;">إضافة عملة جديدة</h4>
        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_currency" value="1">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 500;">رمز العملة (مثال: USD, EUR):</label>
                <input type="text" name="currency_code" required placeholder="USD" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; text-transform: uppercase;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 500;">اسم العملة (مثال: دولار أمريكي):</label>
                <input type="text" name="currency_name" required placeholder="دولار أمريكي" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ العملة</button>
        </form>
    </div>

    <!-- قسم تسجيل سعر صرف جديد (يومي) -->
    <div class="panel-box" style="margin-bottom: 0;">
        <h4 style="margin-top: 0; color: #1cc88a; border-bottom: 1px solid #eee; padding-bottom: 10px;">تسجيل / تحديث سعر صرف ليوم</h4>
        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_exchange_rate" value="1">
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 500;">العملة:</label>
                <select name="rate_currency_code" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- اختر العملة --</option>
                    <?php foreach ($currencies as $cur): ?>
                        <?php if ($cur['is_base'] == 0): // سعر الصرف للعملات الاجنبية مقابل الأساسية ?>
                            <option value="<?php echo htmlspecialchars($cur['currency_code']); ?>"><?php echo htmlspecialchars($cur['currency_code'] . ' - ' . $cur['currency_name']); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">تاريخ السريان:</label>
                    <input type="date" name="rate_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">سعر الصرف:</label>
                    <input type="number" step="0.000001" name="exchange_rate" required placeholder="0.000000" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-weight: bold;">
                </div>
            </div>
            <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ واختيار السعر</button>
        </form>
    </div>

</div>

<!-- جدول العملات المتاحة -->
<div class="panel-box">
    <h4 style="margin-top: 0; margin-bottom: 15px; color: #333;">العملات المعرفة في النظام</h4>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #4e73df; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 10px;">رمز العملة</th>
                    <th style="padding: 10px;">اسم العملة</th>
                    <th style="padding: 10px;">الحالة</th>
                    <th style="padding: 10px; text-align: left;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($currencies as $cur): ?>
                    <tr style="border-bottom: 1px solid #f1f1f1;">
                        <td style="padding: 10px; font-weight: bold; font-family: monospace;"><?php echo htmlspecialchars($cur['currency_code']); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($cur['currency_name']); ?></td>
                        <td style="padding: 10px;">
                            <?php if ($cur['is_base'] == 1): ?>
                                <span class="badge-base">العملة الأساسية للنظام</span>
                            <?php else: ?>
                                <span class="badge-normal">عملة أجنبية</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 10px; text-align: left;">
                            <?php if ($cur['is_base'] == 0 && $cur['currency_code'] === 'SYP'): ?>
                                <form method="POST" style="display:inline;">
<?php csrfField(); ?>
                                    <input type="hidden" name="set_base" value="SYP">
                                    <button type="submit" style="background:#1cc88a; color:white; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:12px;">
                                        <i class="fas fa-star"></i> تعيين كأساسية
                                    </button>
                                </form>
                            <?php elseif ($cur['is_base'] == 0): ?>
                                <span style="color: #aaa; font-size: 12px;" title="غير مدعوم حالياً — راجع التنبيه أعلى الصفحة">
                                    <i class="fas fa-lock"></i> تعيين كأساسية (غير متاح)
                                </span>
                            <?php endif; ?>
                            <?php if ($cur['is_base'] == 0): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('حذف العملة (<?php echo htmlspecialchars($cur['currency_code'], ENT_QUOTES); ?>) وكل سجل أسعار صرفها نهائياً؟');">
<?php csrfField(); ?>
                                    <input type="hidden" name="delete_currency" value="<?php echo htmlspecialchars($cur['currency_code']); ?>">
                                    <button type="submit" style="background:#e74a3b; color:white; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:12px; margin-right:6px;">
                                        <i class="fas fa-trash"></i> حذف
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- سجل أسعار الصرف التاريخي -->
<div class="panel-box">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
        <h4 style="margin: 0; color: #333;">أرشيف أسعار الصرف اليومية</h4>
        <form method="GET" action="" style="display: flex; gap: 10px; align-items: center;">
            <select name="filter_currency" onchange="this.form.submit()" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="">-- كل العملات --</option>
                <?php foreach ($currencies as $cur): ?>
                    <?php if ($cur['is_base'] == 0): ?>
                        <option value="<?php echo htmlspecialchars($cur['currency_code']); ?>" <?php echo $filter_cur == $cur['currency_code'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cur['currency_code']); ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #4e73df; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 10px;">العملة</th>
                    <th style="padding: 10px;">تاريخ السريان</th>
                    <th style="padding: 10px; text-align: left;">سعر الصرف المقابل</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($exchange_rates_history) > 0): ?>
                    <?php foreach ($exchange_rates_history as $rate): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px; font-weight: bold; font-family: monospace; color: #4e73df;"><?php echo htmlspecialchars($rate['currency_code']); ?></td>
                            <td style="padding: 10px;"><?php echo htmlspecialchars($rate['rate_date']); ?></td>
                            <td style="padding: 10px; text-align: left; font-family: monospace; font-weight: bold; color: #e74a3b;"><?php echo number_format($rate['exchange_rate'], 6); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" style="padding: 20px; text-align: center; color: #777;">لا توجد أسعار صرف مسجلة في الأرشيف حالياً.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>