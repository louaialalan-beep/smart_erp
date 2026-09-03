<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

$msg = "";
$error = "";

// معالجة إضافة قيد يدوي جديد مع محرك العملات والحصانة التاريخية
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_manual_entry'])) {
    $entry_number = 'JE-' . time();
    $reference = trim($_POST['reference']);
    $entry_date = $_POST['entry_date'];
    $description = trim($_POST['description']);

    // القيود اليدوية أخطر نقطة تلاعب في النظام — تتطلب صلاحية محاسب/مدير
    requireRole($conn, ['admin', 'accountant']);
    
    // بيانات العملة وسعر الصرف المثبت تاريخياً
    $currency_code = trim($_POST['currency_code']);
    $exchange_rate = floatval($_POST['exchange_rate']);
    if ($exchange_rate <= 0) {
        $exchange_rate = 1.000000;
    }

    $accounts = $_POST['account_id'] ?? [];
    $foreign_debits = $_POST['foreign_debit'] ?? [];
    $foreign_credits = $_POST['foreign_credit'] ?? [];

    $total_foreign_debit = 0;
    $total_foreign_credit = 0;

    // حساب المجاميع بالعملة الأجنبية للتحقق من التوازن
    for ($i = 0; $i < count($accounts); $i++) {
        $total_foreign_debit += floatval($foreign_debits[$i] ?? 0);
        $total_foreign_credit += floatval($foreign_credits[$i] ?? 0);
    }

    // التحقق من توازن القيد بالعملة المدخلة
    if (round($total_foreign_debit, 4) !== round($total_foreign_credit, 4)) {
        $error = "خطأ: القيد غير متوازن! إجمالي المدين الأجنبي ($total_foreign_debit) لا يساوي إجمالي الدائن الأجنبي ($total_foreign_credit).";
    } elseif ($total_foreign_debit <= 0) {
        $error = "خطأ: يجب أن يحتوي القيد على مبالغ صحيحة أكبر من الصفر.";
    } elseif (isDateInClosedPeriod($conn, $entry_date)) {
        $error = getPeriodLockErrorMessage($entry_date);
    } else {
        try {
            $conn->beginTransaction();
            
            $stmt = $conn->prepare("INSERT INTO journal_entries (entry_number, reference, entry_date, description, currency_code, exchange_rate, foreign_debit, foreign_credit, account_id, debit, credit, source_module) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Manual')");
            
            for ($i = 0; $i < count($accounts); $i++) {
                $f_debit = floatval($foreign_debits[$i] ?? 0);
                $f_credit = floatval($foreign_credits[$i] ?? 0);

                if (!empty($accounts[$i]) && ($f_debit > 0 || $f_credit > 0)) {
                    // حساب القيم بالعملة الأساسية بناءً على سعر الصرف المثبت تاريخياً
                    $base_debit = $f_debit * $exchange_rate;
                    $base_credit = $f_credit * $exchange_rate;

                    $stmt->execute([
                        $entry_number,
                        $reference,
                        $entry_date,
                        $description,
                        $currency_code,
                        $exchange_rate,
                        $f_debit,
                        $f_credit,
                        $accounts[$i],
                        $base_debit,
                        $base_credit
                    ]);
                }
            }
            
            $conn->commit();
            $msg = "تم تسجيل القيد المحاسبي بنجاح مع تثبيت سعر الصرف الحصين برقم: " . $entry_number;
            logAudit($conn, 'INSERT', 'قيود يدوية', "قيد يدوي رقم $entry_number — $description (مدين: " . number_format($total_foreign_debit, 2) . " $currency_code)");
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "حدث خطأ أثناء حفظ القيد: " . $e->getMessage();
        }
    }
}

// معاملات الفلترة والبحث
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$filter_account = $_GET['account_id'] ?? '';
$search = $_GET['search'] ?? '';

$where = ["1=1"];
$params = [];

if (!empty($from_date)) {
    $where[] = "j.entry_date >= ?";
    $params[] = $from_date;
}
if (!empty($to_date)) {
    $where[] = "j.entry_date <= ?";
    $params[] = $to_date;
}
if (!empty($search)) {
    $where[] = "(j.entry_number LIKE ? OR j.reference LIKE ? OR j.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// تصحيح جوهري: فلتر الحساب لم يعد يقتصر على "سطر هذا الحساب فقط" (وهو ما كان يجعل كل قيد يظهر
// وكأنه غير متوازن/غير مكتمل زوراً، لأن سطره الآخر يُستبعَد من العرض بينما هو موجود فعلاً في القاعدة).
// الآن: يُحدَّد أولاً أي أرقام قيود (entry_number) تخص هذا الحساب (بنفس فلاتر التاريخ/البحث)، ثم تُجلَب
// كل أسطر تلك القيود كاملة — فتظهر البطاقة متوازنة وصحيحة، لكن القائمة تبقى مُصفَّاة لتشمل فقط القيود
// التي تلمس هذا الحساب تحديداً.
if (!empty($filter_account)) {
    $where_acc = $where;
    $params_acc = $params;
    $where_acc[] = "j.account_id = ?";
    $params_acc[] = $filter_account;
    $stmt_find_en = $conn->prepare("SELECT DISTINCT j.entry_number FROM journal_entries j WHERE " . implode(" AND ", $where_acc));
    $stmt_find_en->execute($params_acc);
    $matching_entry_numbers = $stmt_find_en->fetchAll(PDO::FETCH_COLUMN);

    if (count($matching_entry_numbers) > 0) {
        $en_placeholders = implode(',', array_fill(0, count($matching_entry_numbers), '?'));
        $where[] = "j.entry_number IN ({$en_placeholders})";
        $params = array_merge($params, $matching_entry_numbers);
    } else {
        $where[] = "1=0";
    }
}

// استعلام جلب القيود مع الحسابات المرتبطة وتفاصيل العملة والصرف التاريخي
$sql = "SELECT j.*, a.account_code, a.account_name 
        FROM journal_entries j 
        LEFT JOIN accounts a ON j.account_id = a.id 
        WHERE " . implode(" AND ", $where) . " 
        ORDER BY j.entry_date DESC, j.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$raw_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// العملة الأساسية للنظام (يُطابَق بها لتحديد ما إذا كان القيد "أجنبياً" فعلاً أم لا)
define('BASE_CURRENCY', 'SYP');

// تجميع السطور حسب رقم القيد مع التكنيك الاحتياطي (Fallback) للقيود الآلية
$journal_vouchers = [];
foreach ($raw_entries as $row) {
    $en = $row['entry_number'] ?: ('JE-' . $row['id']);
    
    $rate = floatval($row['exchange_rate']) > 0 ? floatval($row['exchange_rate']) : 1.0;
    $row_currency = $row['currency_code'] ?: BASE_CURRENCY;

    $f_debit = floatval($row['foreign_debit']);
    $f_credit = floatval($row['foreign_credit']);
    $base_debit = floatval($row['debit']);
    $base_credit = floatval($row['credit']);

    // التحويل بين العملة الأجنبية والأساسية يُطبَّق فقط عندما يكون القيد فعلاً بعملة أجنبية
    // (مثال: فواتير/حسابات الموردين بالدولار). أما القيود المسجَّلة أصلاً بالعملة الأساسية (SYP)
    // كقيود المبيعات الآلية، فتُعرض كما هي دون أي قسمة على سعر الصرف لتفادي تحويلها خطأً لدولار.
    if ($row_currency === BASE_CURRENCY || $rate == 1.0) {
        $f_debit = $base_debit;
        $f_credit = $base_credit;
    } else {
        if ($f_debit == 0 && $base_debit > 0) {
            $f_debit = $base_debit / $rate;
        }
        if ($f_credit == 0 && $base_credit > 0) {
            $f_credit = $base_credit / $rate;
        }
    }

    $row['calculated_f_debit'] = $f_debit;
    $row['calculated_f_credit'] = $f_credit;

    if (!isset($journal_vouchers[$en])) {
        $journal_vouchers[$en] = [
            'entry_number' => $en,
            'reference' => $row['reference'],
            'entry_date' => $row['entry_date'],
            'description' => $row['description'],
            'currency_code' => $row_currency,
            'exchange_rate' => $rate,
            'source_module' => $row['source_module'],
            'lines' => []
        ];
    }
    $journal_vouchers[$en]['lines'][] = $row;
}

// جلب الحسابات والعملات للقوائم المنسدلة
$accounts_list = $conn->query("SELECT id, account_code, account_name FROM accounts ORDER BY account_code ASC")->fetchAll(PDO::FETCH_ASSOC);
$currencies_list = $conn->query("SELECT * FROM currencies ORDER BY currency_code ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .journal-card {
        background: #fff;
        border: 1px solid #e3e6f0;
        border-radius: 8px;
        margin-bottom: 20px;
        box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);
        overflow: hidden;
    }
    .journal-header {
        background: #f8f9fc;
        padding: 12px 20px;
        border-bottom: 1px solid #e3e6f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .journal-desc {
        padding: 12px 20px;
        background: #fdfdfd;
        border-bottom: 1px solid #eee;
        font-weight: 500;
        color: #333;
    }
    .badge-balanced {
        background: #1cc88a;
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
    }
    .badge-unbalanced {
        background: #e74a3b;
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
    }
    .badge-currency {
        background: #4e73df;
        color: white;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }
    .filter-box {
        background: #f8f9fc;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid #e3e6f0;
    }
    @media print {
        body * { visibility: hidden; }
        #printable-area, #printable-area * { visibility: visible; }
        #printable-area { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none !important; }
    }
</style>

<div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2>دفتر اليومية الشامل (General Journal)</h2>
        <p style="color: #666; margin: 0;">محرك العملات والحصانة التاريخية لتثبيت أسعار الصرف وحماية القوائم المالية.</p>
    </div>
    <div>
        <button onclick="window.print()" class="btn" style="background: #4e73df; color: white; padding: 8px 16px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold;">
            <i class="fas fa-print"></i> طباعة السجل
        </button>
        <button onclick="toggleModal(true)" class="btn" style="background: #1cc88a; color: white; padding: 8px 16px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold; margin-right: 5px;">
            <i class="fas fa-plus"></i> إضافة قيد يدوي
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div class="no-print" style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $msg; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="no-print" style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- شريط الفلترة المتقدم -->
<div class="filter-box no-print">
    <form method="GET" action="" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 150px;">
            بحث (مرجع / بيان):
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="بحث..." style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; margin-top: 5px;">
        </div>
        <div style="flex: 1; min-width: 130px;">
            من تاريخ:
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; margin-top: 5px;">
        </div>
        <div style="flex: 1; min-width: 130px;">
            إلى تاريخ:
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; margin-top: 5px;">
        </div>
        <div style="flex: 1; min-width: 180px;">
            الحساب:
            <select name="account_id" style="width: 100%; padding: 7px; border: 1px solid #ccc; border-radius: 4px; margin-top: 5px;">
                <option value="">-- كافة الحسابات --</option>
                <?php foreach ($accounts_list as $acc): ?>
                    <option value="<?php echo $acc['id']; ?>" <?php echo $filter_account == $acc['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">تطبيق الفلتر</button>
            <a href="journal.php" style="background: #6c757d; color: white; padding: 8px 15px; border-radius: 4px; text-decoration: none; display: inline-block; font-size: 13px; margin-right: 5px;">إعادة ضبط</a>
        </div>
    </form>
</div>

<!-- منطقة القيود القابلة للطباعة والعرض -->
<div id="printable-area">
    <?php if (count($journal_vouchers) > 0): ?>
        <?php foreach ($journal_vouchers as $voucher): 
            $sum_f_debit = 0;
            $sum_f_credit = 0;
            $sum_base_debit = 0;
            $sum_base_credit = 0;
            foreach ($voucher['lines'] as $line) {
                $sum_f_debit += $line['calculated_f_debit'];
                $sum_f_credit += $line['calculated_f_credit'];
                $sum_base_debit += floatval($line['debit']);
                $sum_base_credit += floatval($line['credit']);
            }

            // فحص توازن صحي يضمن التوازن الفعلي وعدم كونه صفراً
            $is_balanced = (round($sum_base_debit, 2) === round($sum_base_credit, 2)) && ($sum_base_debit > 0);
        ?>
            <div class="journal-card">
                <div class="journal-header">
                    <div>
                        <span style="background: #e2e8f0; color: #333; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-family: monospace; font-size: 14px;">
                            # مرجع: <?php echo htmlspecialchars($voucher['reference'] ?: $voucher['entry_number']); ?>
                        </span>
                        <span style="margin-right: 15px; color: #555; font-size: 14px;">
                            <i class="far fa-calendar-alt"></i> التاريخ: <?php echo htmlspecialchars($voucher['entry_date']); ?>
                        </span>
                        <span style="margin-right: 15px;">
                            <span class="badge-currency"><?php echo htmlspecialchars($voucher['currency_code']); ?></span>
                            <?php if ($voucher['currency_code'] !== BASE_CURRENCY): ?>
                                <span style="font-size: 12px; color: #666; margin-right: 5px;">سعر الصرف المثبت: <b><?php echo number_format($voucher['exchange_rate'], 4); ?></b></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div>
                        <?php if ($is_balanced): ?>
                            <span class="badge-balanced">قيد متوازن</span>
                        <?php else: ?>
                            <span class="badge-unbalanced">قيد غير متوازن / غير مكتمل</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="journal-desc">
                    البيان / الوصف الدقيق: <?php echo htmlspecialchars($voucher['description'] ?: 'بدون بيان'); ?>
                </div>

                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
                        <thead>
                            <tr style="background: #f8f9fc; color: #4e73df; border-bottom: 2px solid #e3e6f0;">
                                <th style="padding: 10px 15px;">رمز الحساب</th>
                                <th style="padding: 10px 15px;">اسم الحساب المحاسبي</th>
                                <th style="padding: 10px 15px; text-align: left;">مدين (<?php echo $voucher['currency_code']; ?>)</th>
                                <th style="padding: 10px 15px; text-align: left;">دائن (<?php echo $voucher['currency_code']; ?>)</th>
                                <?php if ($voucher['currency_code'] !== BASE_CURRENCY): ?>
                                    <th style="padding: 10px 15px; text-align: left; color: #555;">المقابل (ل.س أساسي)</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($voucher['lines'] as $line): ?>
                                <tr style="border-bottom: 1px solid #f1f1f1;">
                                    <td style="padding: 10px 15px; font-family: monospace;">
                                        <span style="background: #eaecf4; color: #4e73df; padding: 2px 8px; border-radius: 4px; font-weight: bold;">
                                            <?php echo htmlspecialchars($line['account_code'] ?: '---'); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px 15px; font-weight: 500; color: #333;">
                                        <?php echo htmlspecialchars($line['account_name'] ?: 'حساب مبيعات عام'); ?>
                                    </td>
                                    <td style="padding: 10px 15px; text-align: left; font-family: monospace; color: #1cc88a; font-weight: bold;">
                                        <?php echo number_format($line['calculated_f_debit'], 2); ?>
                                    </td>
                                    <td style="padding: 10px 15px; text-align: left; font-family: monospace; color: #e74a3b; font-weight: bold;">
                                        <?php echo number_format($line['calculated_f_credit'], 2); ?>
                                    </td>
                                    <?php if ($voucher['currency_code'] !== BASE_CURRENCY): ?>
                                        <td style="padding: 10px 15px; text-align: left; font-family: monospace; color: #666; font-size: 13px;">
                                            (مدين: <?php echo number_format($line['debit'], 2); ?> / دائن: <?php echo number_format($line['credit'], 2); ?>)
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #fdfdfd; border-top: 2px solid #e3e6f0; font-weight: bold;">
                                <td colspan="2" style="padding: 10px 15px; text-align: left; color: #555;">المجموع الكلي للقيد:</td>
                                <td style="padding: 10px 15px; text-align: left; font-family: monospace; color: #1cc88a;">
                                    <?php echo number_format($sum_f_debit, 2); ?>
                                </td>
                                <td style="padding: 10px 15px; text-align: left; font-family: monospace; color: #e74a3b;">
                                    <?php echo number_format($sum_f_credit, 2); ?>
                                </td>
                                <?php if ($voucher['currency_code'] !== BASE_CURRENCY): ?>
                                    <td style="padding: 10px 15px; text-align: left; font-family: monospace; color: #444; font-size: 13px;">
                                        الإجمالي بالعملة الأساسية: <?php echo number_format($sum_base_debit, 2); ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="background: #fff; padding: 30px; text-align: center; border-radius: 8px; border: 1px solid #e3e6f0; color: #777;">
            لا توجد قيود محاسبية مسجلة تطابق خيارات البحث الحالية.
        </div>
    <?php endif; ?>
</div>

<!-- نافذة إضافة قيد يدوي منبثقة مع محرك العملات (Modal) -->
<div id="manualEntryModal" class="no-print" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 800px; max-width: 95%; border-radius: 8px; padding: 25px; max-height: 90vh; overflow-y: auto; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #4e73df;">إضافة قيد يومية يدوي (محرك العملات والحصانة التاريخية)</h3>
            <button onclick="toggleModal(false)" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_manual_entry" value="1">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">المرجع / الفاتورة:</label>
                    <input type="text" name="reference" placeholder="مثال: INV-101" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">تاريخ القيد:</label>
                    <input type="date" name="entry_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">العملة:</label>
                    <select name="currency_code" id="currencySelect" onchange="updateExchangeRate()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <?php foreach ($currencies_list as $cur): ?>
                            <option value="<?php echo $cur['currency_code']; ?>" data-rate="<?php echo $cur['exchange_rate']; ?>">
                                <?php echo htmlspecialchars($cur['currency_code'] . ' - ' . $cur['currency_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500; color: #e74a3b;">سعر الصرف المثبت (الحصانة):</label>
                    <input type="number" step="0.000001" name="exchange_rate" id="exchangeRateInput" value="1.000000" required oninput="calculateTotals()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-weight: bold;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">البيان / الوصف الدقيق للحركة:</label>
                    <input type="text" name="description" required placeholder="أدخل وصف تفصيلي للحركة المحاسبية..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>

            <h4 style="margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;">بنود القيد (بالعملة المحددة):</h4>
            
            <div id="entry-rows">
                <div class="entry-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                    <div style="flex: 2;">
                        <select name="account_id[]" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">-- اختر الحساب --</option>
                            <?php foreach ($accounts_list as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <input type="number" step="0.0001" name="foreign_debit[]" placeholder="مدين" value="0.00" oninput="calculateTotals()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    </div>
                    <div style="flex: 1;">
                        <input type="number" step="0.0001" name="foreign_credit[]" placeholder="دائن" value="0.00" oninput="calculateTotals()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    </div>
                </div>

                <div class="entry-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                    <div style="flex: 2;">
                        <select name="account_id[]" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">-- اختر الحساب --</option>
                            <?php foreach ($accounts_list as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <input type="number" step="0.0001" name="foreign_debit[]" placeholder="مدين" value="0.00" oninput="calculateTotals()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    </div>
                    <div style="flex: 1;">
                        <input type="number" step="0.0001" name="foreign_credit[]" placeholder="دائن" value="0.00" oninput="calculateTotals()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    </div>
                </div>
            </div>

            <button type="button" onclick="addRow()" style="background: #e2e8f0; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 13px; margin-bottom: 15px;">+ إضافة سطر جديد</button>

            <div style="display: flex; justify-content: space-between; background: #f8f9fc; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; font-size: 13px;">
                <div>إجمالي المدين الأجنبي: <span id="modal-total-debit" style="color: #1cc88a;">0.00</span></div>
                <div>إجمالي الدائن الأجنبي: <span id="modal-total-credit" style="color: #e74a3b;">0.00</span></div>
                <div>المقابل بالعملة الأساسية: <span id="modal-total-base" style="color: #4e73df;">0.00</span></div>
            </div>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px;">
                <button type="button" onclick="toggleModal(false)" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ وترحيل مع الحصانة</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleModal(show) {
        document.getElementById('manualEntryModal').style.display = show ? 'flex' : 'none';
        if(show) {
            updateExchangeRate();
        }
    }

    function updateExchangeRate() {
        const select = document.getElementById('currencySelect');
        const selectedOption = select.options[select.selectedIndex];
        const rate = selectedOption.getAttribute('data-rate');
        document.getElementById('exchangeRateInput').value = rate || '1.000000';
        calculateTotals();
    }

    function addRow() {
        const container = document.getElementById('entry-rows');
        const firstRow = container.querySelector('.entry-row').cloneNode(true);
        firstRow.querySelectorAll('input').forEach(input => input.value = '0.00');
        firstRow.querySelector('select').selectedIndex = 0;
        container.appendChild(firstRow);
    }

    function calculateTotals() {
        let totalDebit = 0;
        let totalCredit = 0;
        const rate = parseFloat(document.getElementById('exchangeRateInput').value) || 1;
        
        document.querySelectorAll('input[name="foreign_debit[]"]').forEach(input => {
            totalDebit += parseFloat(input.value) || 0;
        });
        document.querySelectorAll('input[name="foreign_credit[]"]').forEach(input => {
            totalCredit += parseFloat(input.value) || 0;
        });

        document.getElementById('modal-total-debit').innerText = totalDebit.toFixed(2);
        document.getElementById('modal-total-credit').innerText = totalCredit.toFixed(2);
        document.getElementById('modal-total-base').innerText = (totalDebit * rate).toFixed(2);
    }
</script>

<?php include 'footer.php'; ?>