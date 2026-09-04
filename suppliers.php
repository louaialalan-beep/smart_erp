<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';
require_once __DIR__ . '/functions.php';

$supplier_id = intval($_GET['id'] ?? 0);
if ($supplier_id <= 0) {
    echo "<div style='padding: 20px; color: red;'>معرف المورد غير صالح.</div>";
    include 'footer.php';
    exit;
}

$msg = "";
$error = "";

// ============================================================
// فلتر زمني للإحصائيات (الأصناف/القطع المستلمة وCOGS) — لا يؤثر على الأرصدة المالية التراكمية
// (المشتريات/المدفوعات/الرصيد الصافي) التي تبقى دائماً إجمالية منذ البداية كالمعتاد.
// ============================================================
$stat_period = $_GET['stat_period'] ?? 'all';
if ($stat_period === 'month') {
    $stat_from = date('Y-m-01');
    $stat_to = date('Y-m-t');
} elseif ($stat_period === 'year') {
    $stat_from = date('Y-01-01');
    $stat_to = date('Y-12-31');
} elseif ($stat_period === 'custom' && !empty($_GET['stat_from']) && !empty($_GET['stat_to'])) {
    $stat_from = $_GET['stat_from'];
    $stat_to = $_GET['stat_to'];
} else {
    $stat_period = 'all';
    $stat_from = '2000-01-01';
    $stat_to = '2100-12-31';
}

// عمود الرصيد الافتتاحي (رصيد مورد سابق بلا تفاصيل فواتير/بضائع) — يُضاف بأثر رجعي إن لم يكن موجوداً
try {
    $sup_cols_chk = $conn->query("SHOW COLUMNS FROM suppliers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('opening_balance_usd', $sup_cols_chk)) {
        $conn->exec("ALTER TABLE suppliers ADD COLUMN opening_balance_usd DECIMAL(15,2) DEFAULT 0");
    }
} catch (Exception $e) { /* يُتجاهل إن تعذّر */ }

// معالجة إضافة/تعديل رصيد افتتاحي للمورد (دَين سابق بلا فاتورة أو تفاصيل بضاعة).
// المعالجة المحاسبية الصحيحة: لا يمر أبداً عبر المخزون أو أي حساب مصروف (لن يُصنَّف كخسارة)، بل
// يُقابَل بحساب "أرصدة افتتاحية" من نوع حقوق ملكية (Equity) — فيزداد الالتزام (ذمم الموردين) وتنخفض
// حقوق الملكية بنفس القدر، دون أي أثر على قائمة الدخل (الإيرادات/المصروفات) أو المخزون إطلاقاً.
// قيد واحد ثابت لكل مورد (JE-OPBAL-SUP-{id})، يُعكَس ويُعاد ترحيله عند أي تعديل لاحق للمبلغ بدل تكراره.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_opening_balance'])) {
    requireRole($conn, ['admin', 'accountant']);
    $opening_amount = floatval($_POST['opening_balance_usd']);
    $opening_date = $_POST['opening_balance_date'] ?: date('Y-m-d');

    if ($opening_amount < 0) {
        $error = "خطأ: لا يمكن أن يكون الرصيد الافتتاحي سالباً.";
    } else {
        try {
            $conn->beginTransaction();

            $stmt_name = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt_name->execute([$supplier_id]);
            $supplier_name_for_entry = $stmt_name->fetchColumn() ?: ('مورد #' . $supplier_id);

            $conn->prepare("UPDATE suppliers SET opening_balance_usd = ? WHERE id = ?")->execute([$opening_amount, $supplier_id]);

            $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
            $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);
            $entry_num = "JE-OPBAL-SUP-" . $supplier_id;

            // عكس القيد السابق لهذا المورد إن وُجد (تعديل، وليس إضافة أولى)، لتفادي تكرار الأثر
            $stmt_old = $conn->prepare("SELECT account_id, debit, credit, entry_date FROM journal_entries WHERE entry_number = ?");
            $stmt_old->execute([$entry_num]);
            $old_lines = $stmt_old->fetchAll(PDO::FETCH_ASSOC);
            if (count($old_lines) > 0) {
                $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ?")->execute([$entry_num]);
            }

            if ($opening_amount > 0 && in_array('account_id', $existing_cols) && in_array('debit', $existing_cols) && in_array('credit', $existing_cols)) {
                $exchange_rate = getExchangeRateForDate($conn, 'USD', $opening_date);

                // حساب "أرصدة افتتاحية" (Equity) — يُنشأ يدوياً بالنوع الصحيح إن لم يكن موجوداً، بلا الاعتماد
                // على findOrCreateAccount العامة التي لا تضبط account_type عند الإنشاء التلقائي حالياً.
                $stmt_eq = $conn->prepare("SELECT id FROM accounts WHERE account_name = 'أرصدة افتتاحية' LIMIT 1");
                $stmt_eq->execute();
                $equity_account_id = $stmt_eq->fetchColumn();
                if (!$equity_account_id) {
                    $eq_code = '3111';
                    $chk = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
                    for ($i = 0; $i < 50; $i++) {
                        $chk->execute([$eq_code]);
                        if ($chk->fetchColumn() == 0) break;
                        $eq_code = (string)(intval($eq_code) + 1);
                    }
                    $conn->prepare("INSERT INTO accounts (account_code, account_name, account_type) VALUES (?, 'أرصدة افتتاحية', 'Equity')")->execute([$eq_code]);
                    $equity_account_id = $conn->lastInsertId();
                }

                $payable_account_id = findOrCreateAccount($conn, ['مورد', 'payable'], 'ذمم الموردين', 'Liability');

                if ($equity_account_id && $payable_account_id) {
                    $journal_desc = "رصيد افتتاحي سابق للمورد: " . $supplier_name_for_entry . " (بلا تفاصيل فواتير)";
                    $base_amount = $opening_amount * $exchange_rate;

                    $insertJournalLine = function ($account_id, $f_debit, $f_credit, $b_debit, $b_credit) use ($conn, $existing_cols, $entry_num, $opening_date, $journal_desc, $exchange_rate) {
                        $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                        $vals = [$account_id, $opening_date, $journal_desc, $b_debit, $b_credit];
                        if (in_array('entry_number', $existing_cols)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_num; }
                        if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'USD'; }
                        if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $exchange_rate; }
                        if (in_array('foreign_debit', $existing_cols)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = $f_debit; }
                        if (in_array('foreign_credit', $existing_cols)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = $f_credit; }
                        if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Opening Balance'; }
                        $placeholders = implode(',', array_fill(0, count($cols_to_insert), '?'));
                        $col_names = implode(',', $cols_to_insert);
                        $conn->prepare("INSERT INTO journal_entries ({$col_names}) VALUES ({$placeholders})")->execute($vals);
                    };

                    // مدين: حساب حقوق الملكية (أرصدة افتتاحية) — يخفّض حقوق الملكية
                    $insertJournalLine($equity_account_id, $opening_amount, 0, $base_amount, 0);
                    // دائن: ذمم الموردين — يزيد الالتزام
                    $insertJournalLine($payable_account_id, 0, $opening_amount, 0, $base_amount);
                }
            }

            $conn->commit();
            $msg = "تم تسجيل الرصيد الافتتاحي (\$" . number_format($opening_amount, 2) . ") بنجاح.";
            logAudit($conn, 'UPDATE', 'الموردون', "تعيين رصيد افتتاحي بقيمة \$" . number_format($opening_amount, 2) . " للمورد: " . $supplier_name_for_entry, $supplier_id);
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ في تسجيل الرصيد الافتتاحي: " . $e->getMessage();
        }
    }
}

// دالة عامة للبحث عن حساب محاسبي بكلمات مفتاحية أو إنشائه إن لم يوجد
// (نفس المنطق المستخدم في sales.php وrepresentative_profile.php لضمان توفر حسابين
// منفصلين لطرفي القيد المزدوج مدين/دائن)

// معالجة إضافة دفعة مالية للمورد + قيد محاسبي متوازن بالدولار
// (مدين: ذمم الموردين "يُخفِّض الالتزام تجاه المورد" / دائن: الصندوق "تخرج النقدية")
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_payment'])) {
    requireRole($conn, ['admin', 'accountant']);
    $amount_usd = floatval($_POST['amount_usd']);
    $payment_date = $_POST['payment_date'];
    $notes = trim($_POST['notes']);

    if ($amount_usd <= 0) {
        $error = "خطأ: يرجى إدخال مبلغ صحيح للدفعة.";
    } elseif (isDateInClosedPeriod($conn, $payment_date)) {
        $error = getPeriodLockErrorMessage($payment_date);
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("INSERT INTO supplier_payments (supplier_id, amount_usd, payment_date, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$supplier_id, $amount_usd, $payment_date, $notes]);
            $payment_id = $conn->lastInsertId();

            // جلب اسم المورد واستخدامه في وصف القيد
            $stmt_name = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt_name->execute([$supplier_id]);
            $supplier_name_for_entry = $stmt_name->fetchColumn() ?: ('مورد #' . $supplier_id);

            // تصحيح: سعر الصرف يُجلب بتاريخ الدفعة الفعلي (وليس "أحدث سعر" دائماً)، لأن المستخدم قد
            // يُسجِّل دفعة بتاريخ سابق (قيد متأخر) فيجب تثبيت السعر الذي كان سارياً حينها فعلاً،
            // تماشياً مع مبدأ "الحصانة التاريخية" المعتمد في بقية النظام.
            $exchange_rate = getExchangeRateForDate($conn, 'USD', $payment_date);

            $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
            $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

            if (in_array('account_id', $existing_cols) && in_array('debit', $existing_cols) && in_array('credit', $existing_cols)) {
                $debit_account_id  = findOrCreateAccount($conn, ['مورد', 'payable'], 'ذمم الموردين', 'Liability');
                $credit_account_id = findOrCreateAccount($conn, ['صندوق', 'نقد', 'cash'], 'الصندوق الرئيسي', 'Asset');

                if ($debit_account_id && $credit_account_id) {
                    $entry_num = "JE-SPAY-" . $payment_id;
                    $journal_desc = "سداد دفعة نقدية للمورد: " . $supplier_name_for_entry . (!empty($notes) ? " (" . $notes . ")" : "");
                    $base_amount = $amount_usd * $exchange_rate;

                    $insertJournalLine = function ($account_id, $f_debit, $f_credit, $b_debit, $b_credit) use ($conn, $existing_cols, $entry_num, $payment_date, $journal_desc, $exchange_rate) {
                        $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                        $vals = [$account_id, $payment_date, $journal_desc, $b_debit, $b_credit];

                        if (in_array('entry_number', $existing_cols)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_num; }
                        if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'USD'; }
                        if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $exchange_rate; }
                        if (in_array('foreign_debit', $existing_cols)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = $f_debit; }
                        if (in_array('foreign_credit', $existing_cols)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = $f_credit; }
                        if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Supplier Payment'; }

                        $placeholders = implode(',', array_fill(0, count($cols_to_insert), '?'));
                        $col_names = implode(',', $cols_to_insert);
                        $stmt_j = $conn->prepare("INSERT INTO journal_entries ({$col_names}) VALUES ({$placeholders})");
                        $stmt_j->execute($vals);
                    };

                    // مدين: ذمم الموردين بالدولار (foreign) وما يقابلها بالليرة (base)
                    $insertJournalLine($debit_account_id, $amount_usd, 0, $base_amount, 0);
                    // دائن: الصندوق بنفس القيمة تماماً لضمان توازن القيد
                    $insertJournalLine($credit_account_id, 0, $amount_usd, 0, $base_amount);
                }
            }

            $conn->commit();
            $msg = "تم تسجيل الدفعة النقدية وترحيل القيد المحاسبي بنجاح!";
            logAudit($conn, 'INSERT', 'دفعات الموردين', "تسجيل دفعة نقدية بقيمة $" . number_format($amount_usd, 2) . " للمورد: " . $supplier_name_for_entry, $payment_id);
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "خطأ في تسجيل الدفعة: " . $e->getMessage();
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ في تسجيل الدفعة: " . $e->getMessage();
        }
    }
}

// ============================================================
// خصم/مردود يدوي جديد — لم يكن هناك أي آلية فعلية لتسجيل هذا سابقاً (returns_discounts كان حقلاً
// ثابتاً يُدخَل مرة واحدة عند إنشاء المورد فقط، بلا أي قيد محاسبي ولا سجل حركة قابل للإضافة لاحقاً).
// الآن: جدول حركات مستقل + قيد محاسبي حقيقي في كل مرة (مدين ذمم الموردين "يُخفِّض الالتزام"
// / دائن "خصومات مكتسبة من الموردين" — حساب إيراد/تخفيض تكلفة).
$conn->exec("CREATE TABLE IF NOT EXISTS supplier_discounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    amount_usd DECIMAL(15,2) NOT NULL,
    discount_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_discount'])) {
    requireRole($conn, ['admin', 'accountant']);
    $disc_amount_usd = floatval($_POST['disc_amount_usd']);
    $disc_date = $_POST['disc_date'];
    $disc_notes = trim($_POST['disc_notes']);

    if ($disc_amount_usd <= 0) {
        $error = "خطأ: يرجى إدخال مبلغ خصم صحيح.";
    } elseif (isDateInClosedPeriod($conn, $disc_date)) {
        $error = getPeriodLockErrorMessage($disc_date);
    } else {
        try {
            $conn->beginTransaction();

            $stmt_d = $conn->prepare("INSERT INTO supplier_discounts (supplier_id, amount_usd, discount_date, notes) VALUES (?, ?, ?, ?)");
            $stmt_d->execute([$supplier_id, $disc_amount_usd, $disc_date, $disc_notes]);
            $discount_id = $conn->lastInsertId();

            $stmt_name2 = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt_name2->execute([$supplier_id]);
            $supplier_name_for_disc = $stmt_name2->fetchColumn() ?: ('مورد #' . $supplier_id);

            $exchange_rate_d = getExchangeRateForDate($conn, 'USD', $disc_date);
            $stmt_cols_d = $conn->query("SHOW COLUMNS FROM journal_entries");
            $existing_cols_d = $stmt_cols_d->fetchAll(PDO::FETCH_COLUMN);

            $debit_id_d  = findOrCreateAccount($conn, ['مورد', 'payable'], 'ذمم الموردين', 'Liability');
            $credit_id_d = findOrCreateAccount($conn, ['خصومات مكتسبة', 'خصم موردين'], 'خصومات مكتسبة من الموردين', 'Expense');

            if ($debit_id_d && $credit_id_d) {
                $entry_num_d = "JE-SDISC-" . $discount_id;
                $desc_d = "خصم/مردود من المورد: " . $supplier_name_for_disc . (!empty($disc_notes) ? " (" . $disc_notes . ")" : "");
                $base_amount_d = $disc_amount_usd * $exchange_rate_d;

                $insertDiscLine = function ($account_id, $f_debit, $f_credit, $b_debit, $b_credit) use ($conn, $existing_cols_d, $entry_num_d, $disc_date, $desc_d, $exchange_rate_d) {
                    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
                    $vals = [$account_id, $disc_date, $desc_d, $b_debit, $b_credit];
                    if (in_array('entry_number', $existing_cols_d)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_num_d; }
                    if (in_array('currency_code', $existing_cols_d)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'USD'; }
                    if (in_array('exchange_rate', $existing_cols_d)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $exchange_rate_d; }
                    if (in_array('foreign_debit', $existing_cols_d)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = $f_debit; }
                    if (in_array('foreign_credit', $existing_cols_d)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = $f_credit; }
                    if (in_array('source_module', $existing_cols_d)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Supplier Discount'; }
                    $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                    $cn = implode(',', $cols_to_insert);
                    $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                };
                // مدين: ذمم الموردين (يُخفِّض الالتزام المستحق) — دائن: خصومات مكتسبة (إيراد/تخفيض تكلفة)
                $insertDiscLine($debit_id_d, $disc_amount_usd, 0, $base_amount_d, 0);
                $insertDiscLine($credit_id_d, 0, $disc_amount_usd, 0, $base_amount_d);
            }

            $conn->commit();
            $msg = "تم تسجيل الخصم وترحيل القيد المحاسبي بنجاح!";
            logAudit($conn, 'INSERT', 'خصومات الموردين', "تسجيل خصم بقيمة $" . number_format($disc_amount_usd, 2) . " للمورد: " . $supplier_name_for_disc, $discount_id);
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $error = "خطأ في تسجيل الخصم: " . $e->getMessage();
        }
    }
}

// معالجة تعديل دفعة موجودة + تحديث القيد المحاسبي المرتبط بها (إن وُجد) للحفاظ على توازنه
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_payment'])) {
    $payment_id = intval($_POST['payment_id']);
    $amount_usd = floatval($_POST['amount_usd']);
    $payment_date = $_POST['payment_date'];
    $notes = trim($_POST['notes']);

    if ($amount_usd <= 0) {
        $error = "خطأ: يرجى إدخال مبلغ صحيح للدفعة.";
    } elseif (isDateInClosedPeriod($conn, $payment_date)) {
        $error = getPeriodLockErrorMessage($payment_date);
    } else {
        try {
            $conn->beginTransaction();

            // تأكيد أن الدفعة تخص هذا المورد فعلاً قبل التعديل
            $stmt_check = $conn->prepare("SELECT id FROM supplier_payments WHERE id = ? AND supplier_id = ?");
            $stmt_check->execute([$payment_id, $supplier_id]);
            if (!$stmt_check->fetchColumn()) {
                throw new Exception("الدفعة غير موجودة أو لا تخص هذا المورد.");
            }

            $stmt_up = $conn->prepare("UPDATE supplier_payments SET amount_usd = ?, payment_date = ?, notes = ? WHERE id = ?");
            $stmt_up->execute([$amount_usd, $payment_date, $notes, $payment_id]);

            // تصحيح جوهري: القيود المرحَّلة لا تُعدَّل مباشرة أبداً (مبدأ محاسبي أساسي) — بدل UPDATE على
            // السطور الأصلية، نُنشئ قيد عكس (Reversal) يُلغي الأثر الأصلي بالضبط، ثم قيداً جديداً صحيحاً.
            // الأثر الصافي في دفتر الأستاذ مطابق تماماً، لكن الأثر الأصلي يبقى محفوظاً كما رُحِّل فعلياً — قابل للتتبع الكامل.
            $stmt_name = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt_name->execute([$supplier_id]);
            $supplier_name_for_entry = $stmt_name->fetchColumn() ?: ('مورد #' . $supplier_id);

            $original_entry_num = "JE-SPAY-" . $payment_id;
            $stmt_active = $conn->prepare(
                "SELECT entry_number FROM journal_entries
                 WHERE entry_number = ? OR entry_number LIKE ?
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt_active->execute([$original_entry_num, $original_entry_num . "-CORR-%"]);
            $active_entry_num = $stmt_active->fetchColumn() ?: $original_entry_num;

            $stmt_je = $conn->prepare("SELECT id, account_id, debit, credit, foreign_debit, foreign_credit, exchange_rate FROM journal_entries WHERE entry_number = ?");
            $stmt_je->execute([$active_entry_num]);
            $je_lines = $stmt_je->fetchAll(PDO::FETCH_ASSOC);

            $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
            $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);

            if (count($je_lines) > 0) {
                $rev_entry_num = $original_entry_num . "-REV-" . time();
                $today = date('Y-m-d');
                $rev_desc = "عكس تلقائي لقيد سداد دفعة معدَّلة (عكس القيد: $active_entry_num) للمورد: " . $supplier_name_for_entry;

                // 1) قيد العكس: يُبدِّل المدين والدائن لكل سطر أصلي بنفس قيمه تماماً
                foreach ($je_lines as $line) {
                    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit', 'entry_number'];
                    $vals = [$line['account_id'], $today, $rev_desc, floatval($line['credit']), floatval($line['debit']), $rev_entry_num];
                    if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'USD'; }
                    if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $line['exchange_rate']; }
                    if (in_array('foreign_debit', $existing_cols)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = floatval($line['foreign_credit'] ?? 0); }
                    if (in_array('foreign_credit', $existing_cols)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = floatval($line['foreign_debit'] ?? 0); }
                    if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Supplier Payment Reversal'; }
                    $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                    $cn = implode(',', $cols_to_insert);
                    $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                }

                // 2) القيد الصحيح الجديد بالمبلغ المُحدَّث، بنفس سعر الصرف التاريخي الأصلي المثبت (الحصانة التاريخية)
                $orig_rate = floatval($je_lines[0]['exchange_rate']) > 0 ? floatval($je_lines[0]['exchange_rate']) : 1.0;
                $new_entry_num = $original_entry_num . "-CORR-" . time();
                $new_desc = "سداد دفعة نقدية معدَّلة للمورد: " . $supplier_name_for_entry . (!empty($notes) ? " (" . $notes . ")" : "");
                $new_base = $amount_usd * $orig_rate;

                foreach ($je_lines as $line) {
                    $is_debit_line = floatval($line['debit']) > 0;
                    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit', 'entry_number'];
                    $vals = [$line['account_id'], $payment_date, $new_desc, $is_debit_line ? $new_base : 0, $is_debit_line ? 0 : $new_base, $new_entry_num];
                    if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = 'USD'; }
                    if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $orig_rate; }
                    if (in_array('foreign_debit', $existing_cols)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = $is_debit_line ? $amount_usd : 0; }
                    if (in_array('foreign_credit', $existing_cols)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = $is_debit_line ? 0 : $amount_usd; }
                    if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = 'Supplier Payment'; }
                    $ph = implode(',', array_fill(0, count($cols_to_insert), '?'));
                    $cn = implode(',', $cols_to_insert);
                    $conn->prepare("INSERT INTO journal_entries ({$cn}) VALUES ({$ph})")->execute($vals);
                }
            }

            $conn->commit();
            $msg = "تم تحديث الدفعة" . (count($je_lines) > 0 ? " مع ترحيل قيد عكس وقيد تصحيحي (بدل تعديل القيد الأصلي مباشرة)" : "") . " بنجاح!";
            logAudit($conn, 'UPDATE', 'دفعات الموردين', "تعديل دفعة رقم #$payment_id للمورد: " . $supplier_name_for_entry . " إلى $" . number_format($amount_usd, 2) . " — تم ترحيل قيد عكس + قيد تصحيحي", $payment_id);
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ أثناء تحديث الدفعة: " . $e->getMessage();
        }
    }
}

// حذف دفعة مسددة سابقاً بالخطأ — لم تكن هذه الميزة موجودة إطلاقاً في الواجهة رغم توفر تعديل الدفعة.
// حذف حرفي (وليس عكساً محاسبياً كما في التعديل) لأنه يفترض أن الدفعة لم يكن يجب تسجيلها أصلاً،
// فيُحذف السجل والقيد المحاسبي المرتبط به (JE-SPAY-{id}) معاً دون ترك أي أثر متبقٍ.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_payment'])) {
    requireRole($conn, ['admin', 'accountant']);
    $del_payment_id = intval($_POST['delete_payment']);

    $stmt_chk = $conn->prepare("SELECT * FROM supplier_payments WHERE id = ? AND supplier_id = ?");
    $stmt_chk->execute([$del_payment_id, $supplier_id]);
    $payment_to_delete = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    if (!$payment_to_delete) {
        $error = "خطأ: الدفعة غير موجودة أو لا تخص هذا المورد.";
    } elseif (isDateInClosedPeriod($conn, $payment_to_delete['payment_date'])) {
        $error = getPeriodLockErrorMessage($payment_to_delete['payment_date']);
    } else {
        try {
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM journal_entries WHERE entry_number = ?")->execute(["JE-SPAY-" . $del_payment_id]);
            $conn->prepare("DELETE FROM supplier_payments WHERE id = ?")->execute([$del_payment_id]);
            $conn->commit();
            logAudit($conn, 'DELETE', 'مدفوعات الموردين', "حذف دفعة بقيمة \$" . number_format($payment_to_delete['amount_usd'], 2) . " للمورد #" . $supplier_id, $del_payment_id);
            $msg = "تم حذف الدفعة والقيد المحاسبي المرتبط بها بنجاح.";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ أثناء حذف الدفعة: " . $e->getMessage();
        }
    }
}

// جلب بيانات المورد الأساسية
$stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    echo "<div style='padding: 20px; color: red;'>المورد غير موجود.</div>";
    include 'footer.php';
    exit;
}

// ضمان وجود جداول فواتير الشراء (قد لم تُزار صفحة purchases.php بعد على هذا الخادم)
$conn->exec("CREATE TABLE IF NOT EXISTS purchase_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL,
    supplier_id INT NOT NULL,
    exchange_rate DECIMAL(15,4) DEFAULT 1,
    total_amount_usd DECIMAL(15,2) DEFAULT 0,
    invoice_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->exec("CREATE TABLE IF NOT EXISTS purchase_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_invoice_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    unit_cost_usd DECIMAL(15,4) NOT NULL,
    total_cost_usd DECIMAL(15,2) NOT NULL
)");

// نفس فحص/ترحيل عمود حالة الدفع الموجود في Purchases.php — مكرر هنا دفاعياً لضمان عمل هذه الصفحة
// بشكل صحيح حتى لو كانت هذه أول صفحة يفتحها المستخدم قبل زيارة Purchases.php.
try {
    $pi_cols_chk = $conn->query("SHOW COLUMNS FROM purchase_invoices")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_status', $pi_cols_chk)) {
        $conn->exec("ALTER TABLE purchase_invoices ADD COLUMN payment_status ENUM('Paid','Unpaid') NOT NULL DEFAULT 'Unpaid'");
    }
} catch (Exception $e) { /* يُتجاهل إن تعذّر */ }

// حساب إجمالي المشتريات — تصحيح دقة تاريخية إضافي:
// المصدر الأدق الآن هو purchase_invoice_items (القيمة الفعلية المسجَّلة لحظة كل فاتورة شراء حقيقية
// عبر purchases.php)، وليس products.cost_price_usd الحالي الذي يتغيّر مع كل عملية شراء لاحقة.
// تصحيح: فواتير الشراء "نقداً" (payment_status = 'Paid') تُستبعَد من هذا الحساب — فهي تُسدَّد فوراً
// من الصندوق ولا تُنشئ أي ذمة (التزام) تجاه المورد، بخلاف الفواتير "آجل" التي هي مصدر الذمة الفعلي.
$stmt_pi = $conn->prepare("
    SELECT COALESCE(SUM(pii.total_cost_usd), 0)
    FROM purchase_invoice_items pii
    JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
    WHERE pi.supplier_id = ? AND pi.payment_status != 'Paid'
");
$stmt_pi->execute([$supplier_id]);
$total_purchases_from_invoices = floatval($stmt_pi->fetchColumn());

// تصحيح خطأ سابق: الحساب القديم كان يستبعد المنتج بالكامل من "القيمة القديمة" بمجرد وجود أي فاتورة
// شراء واحدة له، فيختفي كل تاريخه المتراكم دفعة واحدة (وهو ما أدى لعدم ظهور فاتورة الشراء الجديدة
// فعلياً في الإجمالي). الصحيح: احسب فقط "الكمية القديمة المتبقية غير المُغطَّاة بعد بأي فاتورة شراء
// حقيقية" لكل منتج = (إجمالي الكمية المشتراة تاريخياً في عمود المنتج) - (مجموع كميات فواتير الشراء
// الفعلية المسجَّلة له). هذا الفارق يتقلّص تدريجياً مع كل فاتورة شراء جديدة بدل أن يُصفَّر دفعة واحدة.
$stmt_legacy = $conn->prepare("
    SELECT COALESCE(SUM(
        GREATEST(0, p.purchased_quantity - COALESCE((SELECT SUM(pii2.quantity) FROM purchase_invoice_items pii2 WHERE pii2.product_id = p.id), 0))
        * p.cost_price_usd
    ), 0)
    FROM products p
    WHERE p.supplier_id = ?
");
$stmt_legacy->execute([$supplier_id]);
$total_purchases_legacy = floatval($stmt_legacy->fetchColumn());

$total_purchases = $total_purchases_from_invoices + $total_purchases_legacy;

// إجمالي المدفوعات النقدية
$stmt_pay = $conn->prepare("SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_payments WHERE supplier_id = ?");
$stmt_pay->execute([$supplier_id]);
$total_payments = $stmt_pay->fetchColumn();

// تصحيح: مرتجعات المشتريات الفعلية المسجَّلة عبر شاشة "إرجاع للمورد" في Purchases.php (جدول purchase_returns)
// منفصلة تماماً عن الحقل اليدوي returns_discounts، ولم تكن تُطرَح من الذمة سابقاً رغم أن القيد المحاسبي
// الخاص بها (JE-PURRET-*) صحيح ويُخفِّض الالتزام فعلياً — الفجوة كانت فقط في حساب هذه البطاقة.
// تصحيح إضافي: مرتجع على فاتورة "نقداً" يجب ألا يُطرَح من الذمة هنا — فالفاتورة الأصلية أصلاً مستبعدة
// من total_purchases_from_invoices أعلاه (لم تُضِف أي التزام)، فطرح مرتجعها هنا كان سيُخفِّض ذمة لم
// تُسجَّل أصلاً، وهو ما يُصحَّح محاسبياً باسترداد نقدي مباشر (Dr صندوق / Cr مخزون) لا علاقة له بالذمة.
$stmt_pr = $conn->prepare("
    SELECT COALESCE(SUM(pr.total_amount_usd), 0)
    FROM purchase_returns pr
    INNER JOIN purchase_invoices pi ON pr.purchase_invoice_id = pi.id
    WHERE pi.supplier_id = ? AND pi.payment_status != 'Paid'
");
$stmt_pr->execute([$supplier_id]);
$total_purchase_returns = floatval($stmt_pr->fetchColumn());

$returns_discounts_manual = floatval($supplier['returns_discounts']);
$stmt_disc_sum = $conn->prepare("SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_discounts WHERE supplier_id = ?");
$stmt_disc_sum->execute([$supplier_id]);
$returns_discounts_logged = floatval($stmt_disc_sum->fetchColumn());
$returns_discounts = $returns_discounts_manual + $returns_discounts_logged;
$opening_balance_usd = floatval($supplier['opening_balance_usd'] ?? 0);
$net_balance = $total_purchases - $total_payments - $returns_discounts - $total_purchase_returns + $opening_balance_usd;

// (1) إجمالي عدد الأصناف + إجمالي عدد القطع المستلمة فعلياً — يُحسب مباشرة من فواتير الشراء الفعلية
// (purchase_invoice_items)، مع استبعاد أي سطر فاتورة سُجِّل بتكلفة وحدة صفر (unit_cost_usd = 0) — هذا
// هو المستوى الصحيح للاستبعاد (سطر الفاتورة نفسه، وليس رصيد "حالي" على جدول المنتجات كما كان سابقاً،
// إذ كان يستبعد قطعاً حقيقية القيمة بالخطأ لمجرد أن سعر المنتج الحالي تغيّر لاحقاً).
$stmt_item_stats = $conn->prepare("
    SELECT COUNT(DISTINCT pii.product_id) AS distinct_products,
           COALESCE(SUM(pii.quantity), 0) AS total_pieces_received,
           COALESCE(SUM(pii.quantity * pii.unit_cost_usd), 0) AS total_pieces_received_value
    FROM purchase_invoice_items pii
    INNER JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
    WHERE pi.supplier_id = ? AND pi.invoice_date BETWEEN ? AND ? AND pii.unit_cost_usd > 0
");
$stmt_item_stats->execute([$supplier_id, $stat_from, $stat_to]);
$item_stats = $stmt_item_stats->fetch(PDO::FETCH_ASSOC);

// المتبقي حالياً بالمخزون يبقى من جدول المنتجات (current_quantity هو الرصيد الحي الصحيح لهذا الغرض
// تحديداً، بلا فلترة على cost_price_usd — لا علاقة له بالتاريخ فهو "الآن" دائماً بغض النظر عن الفلتر)
$stmt_stock_now = $conn->prepare("SELECT COALESCE(SUM(current_quantity), 0) FROM products WHERE supplier_id = ?");
$stmt_stock_now->execute([$supplier_id]);
$item_stats['total_pieces'] = floatval($stmt_stock_now->fetchColumn());

// (2) تكلفة البضائع المباعة (COGS) — للأصناف المُسلَّمة فعلياً حصراً (مصروف حقيقي مُرحَّل بالفعل)
$stmt_cogs_delivered = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity * si.cost_price_usd_at_sale), 0)
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE p.supplier_id = ? AND s.delivery_status = 'Delivered' AND COALESCE(s.delivered_at, s.invoice_date) BETWEEN ? AND ?
");
$stmt_cogs_delivered->execute([$supplier_id, $stat_from, $stat_to]);
$cogs_delivered = floatval($stmt_cogs_delivered->fetchColumn());

// (3) تكلفة البضائع المباعة (COGS) قيد الانتظار — أصناف بِيعت لكن لم تُسلَّم بعد (تقديرية، لم تُرحَّل
// كمصروف حقيقي بعد في اليومية، تُعرَض فقط للاطلاع المسبق)
$stmt_cogs_pending = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity * si.cost_price_usd_at_sale), 0)
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE p.supplier_id = ? AND s.delivery_status IN ('Pending', 'Deferred') AND s.invoice_date BETWEEN ? AND ?
");
$stmt_cogs_pending->execute([$supplier_id, $stat_from, $stat_to]);
$cogs_pending = floatval($stmt_cogs_pending->fetchColumn());

// جلب قائمة المنتجات المرتبطة بهذا المورد
$stmt_products = $conn->prepare("SELECT * FROM products WHERE supplier_id = ? ORDER BY id DESC");
$stmt_products->execute([$supplier_id]);
$products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

// إجمالي الكمية المرتجعة لكل منتج من مرتجعات هذا المورد تحديداً، لعرض شارة "مرتجع" وكميتها
// بجانب كل منتج طالته عملية إرجاع في جدول المنتجات المرتبطة بالمورد.
$returned_qty_by_product = [];
if (count($products) > 0) {
    $stmt_ret_qty = $conn->prepare("
        SELECT pri.product_id, SUM(pri.quantity) AS total_returned
        FROM purchase_return_items pri
        INNER JOIN purchase_returns pr ON pri.purchase_return_id = pr.id
        INNER JOIN purchase_invoices pi ON pr.purchase_invoice_id = pi.id
        WHERE pi.supplier_id = ?
        GROUP BY pri.product_id
    ");
    $stmt_ret_qty->execute([$supplier_id]);
    foreach ($stmt_ret_qty->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $returned_qty_by_product[$row['product_id']] = floatval($row['total_returned']);
    }
}

// === فلتر تاريخ + بحث (مشترك لفواتير الشراء وسجل المدفوعات) ===
$filter_start = $_GET['filter_start'] ?? '';
$filter_end = $_GET['filter_end'] ?? '';
$filter_search = trim($_GET['filter_search'] ?? '');

// فواتير الشراء الخاصة بهذا المورد (لم تكن معروضة هنا إطلاقاً سابقاً رغم وجود الجدول)
$pi_where = ["supplier_id = ?"];
$pi_params = [$supplier_id];
if (!empty($filter_start)) { $pi_where[] = "invoice_date >= ?"; $pi_params[] = $filter_start; }
if (!empty($filter_end)) { $pi_where[] = "invoice_date <= ?"; $pi_params[] = $filter_end; }
if (!empty($filter_search)) { $pi_where[] = "(invoice_number LIKE ? OR notes LIKE ?)"; $pi_params[] = "%$filter_search%"; $pi_params[] = "%$filter_search%"; }
$stmt_pi_list = $conn->prepare("
    SELECT pi.*, COALESCE((
        SELECT SUM(pr.total_amount_usd) FROM purchase_returns pr WHERE pr.purchase_invoice_id = pi.id
    ), 0) AS returned_amount_usd
    FROM purchase_invoices pi
    WHERE " . implode(' AND ', $pi_where) . "
    ORDER BY pi.invoice_date DESC, pi.id DESC
");
$stmt_pi_list->execute($pi_params);
$purchase_invoices_list = $stmt_pi_list->fetchAll(PDO::FETCH_ASSOC);

// جلب أصناف كل فواتير الشراء (اسم المنتج + الكمية) لعرضها في عمود مضغوط بجانب كل فاتورة
$items_by_purchase = [];
if (count($purchase_invoices_list) > 0) {
    $pi_ids = array_column($purchase_invoices_list, 'id');
    $placeholders = implode(',', array_fill(0, count($pi_ids), '?'));
    $stmt_pi_items = $conn->prepare("
        SELECT pii.purchase_invoice_id, pii.quantity, pii.unit_cost_usd, p.product_name
        FROM purchase_invoice_items pii
        LEFT JOIN products p ON pii.product_id = p.id
        WHERE pii.purchase_invoice_id IN ($placeholders)
    ");
    $stmt_pi_items->execute($pi_ids);
    foreach ($stmt_pi_items->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items_by_purchase[$row['purchase_invoice_id']][] = $row;
    }
}

// جلب سجل المدفوعات السابقة (مع نفس الفلتر)
$pay_where = ["supplier_id = ?"];
$pay_params = [$supplier_id];
if (!empty($filter_start)) { $pay_where[] = "payment_date >= ?"; $pay_params[] = $filter_start; }
if (!empty($filter_end)) { $pay_where[] = "payment_date <= ?"; $pay_params[] = $filter_end; }
if (!empty($filter_search)) { $pay_where[] = "notes LIKE ?"; $pay_params[] = "%$filter_search%"; }
$stmt_payments_list = $conn->prepare("SELECT * FROM supplier_payments WHERE " . implode(' AND ', $pay_where) . " ORDER BY payment_date DESC, id DESC");
$stmt_payments_list->execute($pay_params);
$payments_list = $stmt_payments_list->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// كشف حساب المورد: قائمة زمنية موحّدة (فاتورة شراء / مرتجع للمورد / دفعة نقدية / خصم يدوي أولي)
// مع رصيد تراكمي. نجلب كل الحركات (بدون فلتر تاريخ) لحساب "الرصيد الافتتاحي" الصحيح لما قبل بداية
// الفلتر، ثم نعرض فقط الحركات الواقعة ضمن الفلتر المُطبَّق أعلاه.
// ============================================================
$stmt_ledger_inv = $conn->prepare("SELECT invoice_date AS event_date, id AS src_id, invoice_number AS ref, total_amount_usd AS amount FROM purchase_invoices WHERE supplier_id = ? AND total_amount_usd > 0 AND payment_status != 'Paid'");
$stmt_ledger_inv->execute([$supplier_id]);
$ledger_inv = $stmt_ledger_inv->fetchAll(PDO::FETCH_ASSOC);

// أصناف كل فواتير هذا المورد (بلا فلترة، بنفس نطاق استعلام ledger_inv أعلاه تماماً) لإدراج اسم المنتج
// والكمية داخل بيان كل حركة "فاتورة شراء" في كشف الحساب نفسه، وليس فقط في جدول الفواتير المنفصل.
$items_by_purchase_stmt = [];
if (count($ledger_inv) > 0) {
    $stmt_items_stmt = $conn->prepare("
        SELECT pii.purchase_invoice_id, pii.quantity, pii.unit_cost_usd, p.product_name
        FROM purchase_invoice_items pii
        LEFT JOIN products p ON pii.product_id = p.id
        INNER JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
        WHERE pi.supplier_id = ?
    ");
    $stmt_items_stmt->execute([$supplier_id]);
    foreach ($stmt_items_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items_by_purchase_stmt[$row['purchase_invoice_id']][] = $row;
    }
}
$formatInvoiceItemsSummary = function ($items) {
    if (empty($items)) return '';
    $parts = [];
    foreach ($items as $it) {
        $qty_display = rtrim(rtrim(number_format(floatval($it['quantity']), 2), '0'), '.');
        $unit_price_display = number_format(floatval($it['unit_cost_usd']), 2);
        $parts[] = ($it['product_name'] ?: 'منتج محذوف') . ' × ' . $qty_display . ' (@ $' . $unit_price_display . ')';
    }
    return ' — ' . implode('، ', $parts);
};

$stmt_ledger_ret = $conn->prepare("SELECT pr.return_date AS event_date, pr.id AS src_id, pi.invoice_number AS ref, pr.total_amount_usd AS amount FROM purchase_returns pr INNER JOIN purchase_invoices pi ON pr.purchase_invoice_id = pi.id WHERE pi.supplier_id = ? AND pr.total_amount_usd > 0 AND pi.payment_status != 'Paid'");
$stmt_ledger_ret->execute([$supplier_id]);
$ledger_ret = $stmt_ledger_ret->fetchAll(PDO::FETCH_ASSOC);

// أصناف كل مرتجعات هذا المورد، لنفس الغرض أعلاه لكن لبيان حركات "مرتجع للمورد"
$items_by_return_stmt = [];
if (count($ledger_ret) > 0) {
    $stmt_items_ret = $conn->prepare("
        SELECT pri.purchase_return_id, pri.quantity, pri.unit_cost_usd, p.product_name
        FROM purchase_return_items pri
        LEFT JOIN products p ON pri.product_id = p.id
        INNER JOIN purchase_returns pr ON pri.purchase_return_id = pr.id
        INNER JOIN purchase_invoices pi ON pr.purchase_invoice_id = pi.id
        WHERE pi.supplier_id = ?
    ");
    $stmt_items_ret->execute([$supplier_id]);
    foreach ($stmt_items_ret->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items_by_return_stmt[$row['purchase_return_id']][] = $row;
    }
}

$stmt_ledger_pay2 = $conn->prepare("SELECT payment_date AS event_date, id AS src_id, notes AS ref, amount_usd AS amount FROM supplier_payments WHERE supplier_id = ?");
$stmt_ledger_pay2->execute([$supplier_id]);
$ledger_pay2 = $stmt_ledger_pay2->fetchAll(PDO::FETCH_ASSOC);

$statement_entries = [];
foreach ($ledger_inv as $r) {
    $item_summary = $formatInvoiceItemsSummary($items_by_purchase_stmt[$r['src_id']] ?? []);
    $statement_entries[] = ['date' => $r['event_date'], 'src_id' => (int)$r['src_id'], 'label' => 'فاتورة شراء رقم ' . $r['ref'] . $item_summary, 'due' => floatval($r['amount']), 'settled' => 0];
}
foreach ($ledger_ret as $r) {
    $item_summary_ret = $formatInvoiceItemsSummary($items_by_return_stmt[$r['src_id']] ?? []);
    $statement_entries[] = ['date' => $r['event_date'], 'src_id' => (int)$r['src_id'], 'label' => 'مرتجع للمورد - فاتورة ' . $r['ref'] . $item_summary_ret, 'due' => 0, 'settled' => floatval($r['amount'])];
}
foreach ($ledger_pay2 as $r) {
    $statement_entries[] = ['date' => $r['event_date'], 'src_id' => (int)$r['src_id'], 'label' => 'دفعة نقدية مسددة' . (!empty($r['ref']) ? ' - ' . $r['ref'] : ''), 'due' => 0, 'settled' => floatval($r['amount'])];
}
// كل خصم مُسجَّل فعلياً عبر الآلية الجديدة (وليس فقط الحقل اليدوي الثابت) — يظهر كحركة مستقلة بتاريخها الحقيقي
$stmt_disc_ledger = $conn->prepare("SELECT discount_date, amount_usd, notes FROM supplier_discounts WHERE supplier_id = ? ORDER BY discount_date ASC");
$stmt_disc_ledger->execute([$supplier_id]);
foreach ($stmt_disc_ledger->fetchAll(PDO::FETCH_ASSOC) as $dr) {
    $statement_entries[] = ['date' => $dr['discount_date'], 'src_id' => -3, 'label' => 'خصم مُسجَّل' . (!empty($dr['notes']) ? ' - ' . $dr['notes'] : ''), 'due' => 0, 'settled' => floatval($dr['amount_usd'])];
}
// الحقل اليدوي returns_discounts (مردودات/خصومات أولية أُدخلت يدوياً عند إنشاء/تعديل المورد) — تُدرَج
// كحركة بتاريخ إنشاء المورد لضمان ظهورها دائماً ضمن الرصيد الافتتاحي إن كانت الفواتير أقدم منها.
if (floatval($supplier['returns_discounts']) > 0) {
    $statement_entries[] = ['date' => substr($supplier['created_at'] ?? date('Y-m-d'), 0, 10), 'src_id' => -1, 'label' => 'مردودات / خصومات يدوية أولية', 'due' => 0, 'settled' => floatval($supplier['returns_discounts'])];
}
// الرصيد الافتتاحي (دَين سابق للمورد بلا تفاصيل فواتير) — يُدرَج كحركة "مستحق" بتاريخ إنشاء المورد
if ($opening_balance_usd > 0) {
    $statement_entries[] = ['date' => substr($supplier['created_at'] ?? date('Y-m-d'), 0, 10), 'src_id' => -2, 'label' => 'رصيد افتتاحي سابق (بلا تفاصيل فواتير)', 'due' => $opening_balance_usd, 'settled' => 0];
}

usort($statement_entries, function ($a, $b) {
    $cmp = strcmp($a['date'], $b['date']);
    if ($cmp !== 0) return $cmp;
    return $a['src_id'] <=> $b['src_id'];
});

$statement_opening_balance = 0;
$statement_rows = [];
foreach ($statement_entries as $e) {
    if (!empty($filter_start) && $e['date'] < $filter_start) {
        $statement_opening_balance += $e['due'] - $e['settled'];
        continue;
    }
    if (!empty($filter_end) && $e['date'] > $filter_end) {
        continue;
    }
    $statement_rows[] = $e;
}

$statement_running_balance = $statement_opening_balance;
foreach ($statement_rows as &$row) {
    $statement_running_balance += $row['due'] - $row['settled'];
    $row['balance'] = $statement_running_balance;
}
unset($row);
$statement_closing_balance = $statement_running_balance;
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <a href="suppliers.php" style="color: #4e73df; text-decoration: none; font-size: 13px; font-weight: bold;"><i class="fas fa-arrow-right"></i> العودة لقائمة الموردين</a>
        <h2 style="margin: 5px 0 0 0; color: #333;">الملف التفصيلي للمورد: <?php echo htmlspecialchars($supplier['supplier_name']); ?></h2>
    </div>
    <div>
        <button onclick="toggleOpeningBalanceModal(true)" style="background: #6f42c1; color: white; padding: 9px 18px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold; margin-left: 8px;">
            <i class="fas fa-history"></i> رصيد افتتاحي سابق
        </button>
        <button onclick="togglePaymentModal(true)" style="background: #1cc88a; color: white; padding: 9px 18px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold; margin-left: 8px;">
            <i class="fas fa-money-bill-wave"></i> تسجيل دفعة جديدة للمورد
        </button>
        <button onclick="toggleDiscountModal(true)" style="background: #f6c23e; color: white; padding: 9px 18px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold;">
            <i class="fas fa-percent"></i> تسجيل خصم / مردود جديد
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $msg; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- بطاقات الملخص المالي -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px;">
    <div style="background: #fff; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px; box-shadow: 0 0.15rem 1rem rgba(0,0,0,0.05);">
        <div style="color: #888; font-size: 13px; font-weight: bold;">إجمالي المشتريات (له)</div>
        <div style="font-size: 20px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;">$<?php echo number_format($total_purchases, 2); ?></div>
    </div>
    <div style="background: #fff; border-right: 4px solid #1cc88a; padding: 15px; border-radius: 6px; box-shadow: 0 0.15rem 1rem rgba(0,0,0,0.05);">
        <div style="color: #888; font-size: 13px; font-weight: bold;">إجمالي المدفوعات (عليه)</div>
        <div style="font-size: 20px; font-weight: bold; color: #1cc88a; font-family: monospace; margin-top: 5px;">$<?php echo number_format($total_payments, 2); ?></div>
    </div>
    <div style="background: #fff; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px; box-shadow: 0 0.15rem 1rem rgba(0,0,0,0.05);">
        <div style="color: #888; font-size: 13px; font-weight: bold;" title="خصومات يدوية: $<?php echo number_format($returns_discounts, 2); ?> + مرتجعات فعلية: $<?php echo number_format($total_purchase_returns, 2); ?>">المردودات / الخصم</div>
        <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;">$<?php echo number_format($returns_discounts + $total_purchase_returns, 2); ?></div>
    </div>
    <div style="background: #fff; border-right: 4px solid #6f42c1; padding: 15px; border-radius: 6px; box-shadow: 0 0.15rem 1rem rgba(0,0,0,0.05);">
        <div style="color: #888; font-size: 13px; font-weight: bold;">رصيد افتتاحي سابق</div>
        <div style="font-size: 20px; font-weight: bold; color: #6f42c1; font-family: monospace; margin-top: 5px;">$<?php echo number_format($opening_balance_usd, 2); ?></div>
    </div>
    <div style="background: #fff; border-right: 4px solid #2e59d9; padding: 15px; border-radius: 6px; box-shadow: 0 0.15rem 1rem rgba(0,0,0,0.05);">
        <div style="color: #888; font-size: 13px; font-weight: bold;">صافي الحساب الباقي</div>
        <div style="font-size: 20px; font-weight: bold; color: #2e59d9; font-family: monospace; margin-top: 5px;">$<?php echo number_format($net_balance, 2); ?></div>
    </div>
</div>

<!-- فلتر زمني للإحصائيات أدناه فقط (لا يؤثر على الأرصدة المالية التراكمية أعلاه) -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 15px;">
    <form method="GET" action="" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="id" value="<?php echo $supplier_id; ?>">
        <label style="font-size: 13px; font-weight: bold; color: #555;"><i class="fas fa-filter"></i> فترة الإحصائيات أدناه:</label>
        <a href="?id=<?php echo $supplier_id; ?>&stat_period=all" style="text-decoration: none;">
            <span style="padding: 6px 12px; border-radius: 5px; font-size: 12.5px; font-weight: bold; background: <?php echo $stat_period === 'all' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $stat_period === 'all' ? '#fff' : '#4e73df'; ?>;">كل الفترة</span>
        </a>
        <a href="?id=<?php echo $supplier_id; ?>&stat_period=month" style="text-decoration: none;">
            <span style="padding: 6px 12px; border-radius: 5px; font-size: 12.5px; font-weight: bold; background: <?php echo $stat_period === 'month' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $stat_period === 'month' ? '#fff' : '#4e73df'; ?>;">هذا الشهر</span>
        </a>
        <a href="?id=<?php echo $supplier_id; ?>&stat_period=year" style="text-decoration: none;">
            <span style="padding: 6px 12px; border-radius: 5px; font-size: 12.5px; font-weight: bold; background: <?php echo $stat_period === 'year' ? '#4e73df' : '#f1f3f9'; ?>; color: <?php echo $stat_period === 'year' ? '#fff' : '#4e73df'; ?>;">هذه السنة</span>
        </a>
        <input type="hidden" name="stat_period" value="custom">
        <input type="date" name="stat_from" value="<?php echo $stat_period === 'custom' ? htmlspecialchars($stat_from) : ''; ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
        <span style="color: #888;">إلى</span>
        <input type="date" name="stat_to" value="<?php echo $stat_period === 'custom' ? htmlspecialchars($stat_to) : ''; ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px;">
        <button type="submit" style="background: #6f42c1; color: white; border: none; padding: 6px 14px; border-radius: 5px; cursor: pointer; font-size: 12.5px; font-weight: bold;">تطبيق فترة مخصصة</button>
    </form>
</div>

<!-- بطاقات إضافية: إحصاء الأصناف وتكلفة البضائع المباعة حسب حالة التسليم -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px;">
    <div style="background: #eaf1fc; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px;">
        <div style="color: #2c4e9c; font-size: 13px; font-weight: bold;">إجمالي عدد الأصناف المستلمة (الفترة)</div>
        <div style="font-size: 20px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo intval($item_stats['distinct_products']); ?> صنف</div>
    </div>
    <div style="background: #eaf1fc; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px;">
        <div style="color: #2c4e9c; font-size: 13px; font-weight: bold;" title="محسوبة من فواتير الشراء الفعلية ضمن الفترة، باستبعاد أي سطر بتكلفة وحدة = صفر">إجمالي عدد القطع المستلمة (الفترة، بلا تكلفة صفر)</div>
        <div style="font-size: 20px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($item_stats['total_pieces_received'], 2), '0'), '.'); ?> قطعة</div>
        <div style="font-size: 12.5px; color: #2c4e9c; font-family: monospace; margin-top: 3px;">بقيمة: $<?php echo number_format($item_stats['total_pieces_received_value'], 2); ?></div>
    </div>
    <div style="background: #eaf1fc; border-right: 4px solid #4e73df; padding: 15px; border-radius: 6px;">
        <div style="color: #2c4e9c; font-size: 13px; font-weight: bold;" title="الرصيد الحي الآن دائماً، بغض النظر عن الفلتر الزمني أعلاه">المتبقي حالياً بالمخزون</div>
        <div style="font-size: 20px; font-weight: bold; color: #4e73df; font-family: monospace; margin-top: 5px;"><?php echo rtrim(rtrim(number_format($item_stats['total_pieces'], 2), '0'), '.'); ?> قطعة</div>
    </div>
    <div style="background: #fdecea; border-right: 4px solid #e74a3b; padding: 15px; border-radius: 6px;">
        <div style="color: #a33636; font-size: 13px; font-weight: bold;" title="مصروف حقيقي مُرحَّل فعلياً في اليومية">تكلفة البضائع المباعة (COGS) — مُسلَّمة (الفترة)</div>
        <div style="font-size: 20px; font-weight: bold; color: #e74a3b; font-family: monospace; margin-top: 5px;">$<?php echo number_format($cogs_delivered, 2); ?></div>
    </div>
    <div style="background: #fff8e6; border-right: 4px solid #f6c23e; padding: 15px; border-radius: 6px;">
        <div style="color: #96751c; font-size: 13px; font-weight: bold;" title="تقديرية — لم تُرحَّل كمصروف حقيقي بعد، للاطلاع المسبق فقط">تكلفة البضائع المباعة (COGS) — قيد الانتظار (الفترة)</div>
        <div style="font-size: 20px; font-weight: bold; color: #f6c23e; font-family: monospace; margin-top: 5px;">$<?php echo number_format($cogs_pending, 2); ?></div>
    </div>
</div>

<!-- معلومات الاتصال والشروط -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px; margin-bottom: 25px; display: flex; gap: 30px; font-size: 14px;">
    <div><strong>رقم الهاتف:</strong> <span style="font-family: monospace; color: #555;"><?php echo htmlspecialchars($supplier['phone'] ?: 'غير متوفر'); ?></span></div>
    <div><strong>العملة المعتمدة:</strong> <span style="color: #4e73df; font-weight: bold;"><?php echo htmlspecialchars($supplier['currency']); ?></span></div>
    <div><strong>شروط السداد:</strong> <span style="color: #666;"><?php echo htmlspecialchars($supplier['payment_terms']); ?></span></div>
</div>

<!-- جدول المنتجات المرتبطة بالمورد -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; margin-bottom: 25px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="background: #f8f9fc; padding: 12px 15px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #4e73df;">
        <i class="fas fa-boxes"></i> المنتجات المرتبطة والمشتراة من هذا المورد
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0; color: #555;">
                    <th style="padding: 10px 15px;">اسم المنتج</th>
                    <th style="padding: 10px 15px;">رمز الصنف (SKU)</th>
                    <th style="padding: 10px 15px;">إجمالي الكمية المشتراة</th>
                    <th style="padding: 10px 15px;">الكمية الحالية بالمخزون</th>
                    <th style="padding: 10px 15px;">سعر التكلفة (USD)</th>
                    <th style="padding: 10px 15px;">إجمالي قيمة الشراء (USD)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $prod): 
                        // إجمالي قيمة الشراء يعتمد على الكمية المشتراة الأصلية (ثابتة) وليس المتبقية بالمخزون
                        $purchased_qty = $prod['purchased_quantity'] ?? $prod['current_quantity'];
                        $total_val = $purchased_qty * $prod['cost_price_usd'];
                    ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-weight: 600; color: #333;">
                                <?php echo htmlspecialchars($prod['product_name']); ?>
                                <?php if (!empty($returned_qty_by_product[$prod['id']])): ?>
                                    <span style="background: #fbe3e0; color: #e74a3b; font-size: 11px; font-weight: bold; padding: 2px 8px; border-radius: 10px; margin-right: 6px; white-space: nowrap;">
                                        <i class="fas fa-undo"></i> مرتجع × <?php echo rtrim(rtrim(number_format($returned_qty_by_product[$prod['id']], 2), '0'), '.'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($prod['sku']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; font-weight: bold; color: #333;"><?php echo number_format($purchased_qty, 2); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #888;"><?php echo number_format($prod['current_quantity'], 2); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #e74a3b;">$<?php echo number_format($prod['cost_price_usd'], 4); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #2e59d9; font-weight: bold;">$<?php echo number_format($total_val, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="padding: 20px; text-align: center; color: #777;">لا توجد منتجات مسجلة مرتبطة بهذا المورد حتى الآن.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- شريط الفلترة المشترك (تاريخ + بحث) لفواتير الشراء وسجل المدفوعات أدناه -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px;">
    <form method="GET" action="" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="id" value="<?php echo $supplier_id; ?>">
        <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">من تاريخ:</label>
            <input type="date" name="filter_start" value="<?php echo htmlspecialchars($filter_start); ?>" style="padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <div><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">إلى تاريخ:</label>
            <input type="date" name="filter_end" value="<?php echo htmlspecialchars($filter_end); ?>" style="padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <div style="flex:1; min-width:180px;"><label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">بحث (رقم فاتورة / ملاحظات):</label>
            <input type="text" name="filter_search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="ابحث..." style="width:100%; padding:7px; border:1px solid #ccc; border-radius:4px;"></div>
        <button type="submit" style="background:#4e73df; color:white; border:none; padding:8px 18px; border-radius:6px; cursor:pointer; font-weight:bold;">تطبيق الفلتر</button>
        <?php if (!empty($filter_start) || !empty($filter_end) || !empty($filter_search)): ?>
            <a href="supplier_view.php?id=<?php echo $supplier_id; ?>" style="color:#666; font-size:13px; padding:8px 0;">إلغاء الفلتر</a>
        <?php endif; ?>
    </form>
</div>

<!-- فواتير الشراء الخاصة بهذا المورد -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08); margin-bottom: 25px;">
    <div style="background: #f8f9fc; padding: 12px 15px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #4e73df;">
        <i class="fas fa-truck-loading"></i> فواتير الشراء المسجَّلة من هذا المورد
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0; color: #555;">
                    <th style="padding: 10px 15px;">رقم الفاتورة</th>
                    <th style="padding: 10px 15px;">الأصناف (الكمية)</th>
                    <th style="padding: 10px 15px;">التاريخ</th>
                    <th style="padding: 10px 15px;">القيمة (USD)</th>
                    <th style="padding: 10px 15px;">المرتجع (USD)</th>
                    <th style="padding: 10px 15px;">سعر الصرف</th>
                    <th style="padding: 10px 15px;">ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($purchase_invoices_list) > 0): ?>
                    <?php foreach ($purchase_invoices_list as $pi): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-family: monospace; font-weight: bold; color: #4e73df;"><?php echo htmlspecialchars($pi['invoice_number']); ?></td>
                            <td style="padding: 10px 15px; color: #555; font-size: 12.5px; max-width: 240px;">
                                <?php
                                    $pi_items = $items_by_purchase[$pi['id']] ?? [];
                                    if (count($pi_items) > 0) {
                                        $item_parts = [];
                                        foreach ($pi_items as $it) {
                                            $qty_display = rtrim(rtrim(number_format(floatval($it['quantity']), 2), '0'), '.');
                                            $unit_price_display = number_format(floatval($it['unit_cost_usd']), 2);
                                            $item_parts[] = htmlspecialchars(($it['product_name'] ?: 'منتج محذوف') . ' × ' . $qty_display . ' (@ $' . $unit_price_display . ')');
                                        }
                                        echo implode('، ', $item_parts);
                                    } else {
                                        echo '<span style="color: #aaa;">-</span>';
                                    }
                                ?>
                            </td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($pi['invoice_date']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; font-weight: bold; color: #e74a3b;">$<?php echo number_format($pi['total_amount_usd'], 2); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: <?php echo $pi['returned_amount_usd'] > 0 ? '#e6a817' : '#aaa'; ?>;">$<?php echo number_format($pi['returned_amount_usd'], 2); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace;"><?php echo number_format($pi['exchange_rate'], 2); ?></td>
                            <td style="padding: 10px 15px; color: #777;"><?php echo htmlspecialchars($pi['notes'] ?: '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="padding: 20px; text-align: center; color: #777;">لا توجد فواتير شراء مطابقة لخيارات الفلتر الحالية.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- جدول سجل الحركات والدفعات السابقة -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="background: #f8f9fc; padding: 12px 15px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #1cc88a;">
        <i class="fas fa-history"></i> سجل المدفوعات النقدية المسددة للمورد
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfe; border-bottom: 2px solid #e3e6f0; color: #555;">
                    <th style="padding: 10px 15px;">تاريخ الدفعة</th>
                    <th style="padding: 10px 15px;">المبلغ المسدد (USD)</th>
                    <th style="padding: 10px 15px;">ملاحظات / البيان</th>
                    <th style="padding: 10px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($payments_list) > 0): ?>
                    <?php foreach ($payments_list as $pay): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-family: monospace; color: #555;"><?php echo htmlspecialchars($pay['payment_date']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #1cc88a; font-weight: bold;">$<?php echo number_format($pay['amount_usd'], 2); ?></td>
                            <td style="padding: 10px 15px; color: #666;"><?php echo htmlspecialchars($pay['notes'] ?: 'بدون ملاحظات'); ?></td>
                            <td style="padding: 10px 15px; text-align: center;">
                                <button onclick='openEditPaymentModal(<?php echo json_encode($pay, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #f6c23e; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">
                                    <i class="fas fa-edit"></i> تعديل
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('حذف هذه الدفعة (\$<?php echo number_format($pay['amount_usd'], 2); ?>) نهائياً مع القيد المحاسبي المرتبط بها؟');">
<?php csrfField(); ?>
                                    <input type="hidden" name="delete_payment" value="<?php echo intval($pay['id']); ?>">
                                    <button type="submit" style="background: #e74a3b; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; margin-right: 6px;">
                                        <i class="fas fa-trash"></i> حذف
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="padding: 20px; text-align: center; color: #777;">لم يتم تسجيل أي دفعات مالية لهذا المورد بعد.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- كشف حساب المورد -->
<div id="statement-print-area" style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08); margin-bottom: 30px;">
    <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0; font-weight: bold; color: #4e73df; display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fas fa-file-invoice"></i> كشف حساب المورد<?php if (!empty($filter_start) || !empty($filter_end)): ?> (<?php echo htmlspecialchars($filter_start ?: 'البداية'); ?> إلى <?php echo htmlspecialchars($filter_end ?: 'اليوم'); ?>)<?php endif; ?></span>
        <button onclick="window.print()" class="no-print" style="background: #4e73df; color: white; border: none; padding: 6px 16px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 13px;">
            <i class="fas fa-print"></i> طباعة الكشف
        </button>
    </div>
    <div style="padding: 15px 20px; border-bottom: 1px solid #f1f1f1; font-weight: bold; color: #555;">
        الرصيد الافتتاحي: <span style="font-family: monospace; color: #2e59d9;">$<?php echo number_format($statement_opening_balance, 2); ?></span>
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
            <thead>
                <tr style="background: #fdfdfd; color: #555; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">التاريخ</th>
                    <th style="padding: 12px 15px;">البيان</th>
                    <th style="padding: 12px 15px;">مستحق له (+)</th>
                    <th style="padding: 12px 15px;">مسدد/مرتجع (-)</th>
                    <th style="padding: 12px 15px;">الرصيد التراكمي</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($statement_rows) > 0): ?>
                    <?php foreach ($statement_rows as $row): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 10px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($row['date']); ?></td>
                            <td style="padding: 10px 15px; color: #333;"><?php echo htmlspecialchars($row['label']); ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #e74a3b;"><?php echo $row['due'] > 0 ? '$' . number_format($row['due'], 2) : '-'; ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; color: #1cc88a;"><?php echo $row['settled'] > 0 ? '$' . number_format($row['settled'], 2) : '-'; ?></td>
                            <td style="padding: 10px 15px; font-family: monospace; font-weight: bold; color: #2e59d9;">$<?php echo number_format($row['balance'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="padding: 25px; text-align: center; color: #777;">لا توجد حركات ضمن الفترة المحددة.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8f9fc; border-top: 2px solid #e3e6f0;">
                    <td colspan="4" style="padding: 12px 15px; font-weight: bold; color: #333; text-align: left;">الرصيد الختامي:</td>
                    <td style="padding: 12px 15px; font-weight: bold; font-family: monospace; color: #2e59d9;">$<?php echo number_format($statement_closing_balance, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<style>
@media print {
    body * { visibility: hidden; }
    #statement-print-area, #statement-print-area * { visibility: visible; }
    #statement-print-area { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none; border: none; }
    #statement-print-area .no-print { display: none !important; }
}
</style>

<!-- نافذة تسجيل دفعة جديدة (Modal) -->
<div id="paymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #1cc88a;">تسجيل دفعة جديدة للمورد</h3>
            <button onclick="togglePaymentModal(false)" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_payment" value="1">

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">المبلغ المدفوع (USD):</label>
                <input type="number" step="0.0001" name="amount_usd" required placeholder="0.00" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ الدفعة:</label>
                <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">ملاحظات / السند:</label>
                <textarea name="notes" rows="3" placeholder="تفاصيل الدفعة أو رقم السند..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            </div>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">
                <button type="button" onclick="togglePaymentModal(false)" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ الدفعة</button>
            </div>
        </form>
    </div>
</div>

<!-- نافذة تسجيل خصم/مردود جديد (Modal) -->
<div id="discountModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #f6c23e;">تسجيل خصم / مردود جديد</h3>
            <button onclick="toggleDiscountModal(false)" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <div style="background: #fff8e6; color: #856404; padding: 10px 12px; border-radius: 6px; font-size: 12.5px; margin-bottom: 15px;">
            خصم نقدي أو مردود متفق عليه مع المورد (بلا فاتورة إرجاع فعلية) — يُخفِّض المبلغ المستحق له فوراً، ويُرحَّل كقيد محاسبي حقيقي (مدين ذمم الموردين / دائن خصومات مكتسبة).
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_discount" value="1">

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">قيمة الخصم (USD):</label>
                <input type="number" step="0.0001" name="disc_amount_usd" required placeholder="0.00" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ الخصم:</label>
                <input type="date" name="disc_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">ملاحظات / السبب:</label>
                <textarea name="disc_notes" rows="3" placeholder="سبب الخصم أو تفاصيله..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            </div>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">
                <button type="button" onclick="toggleDiscountModal(false)" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ الخصم</button>
            </div>
        </form>
    </div>
</div>

<!-- نافذة تعيين رصيد افتتاحي سابق للمورد -->
<div id="openingBalanceModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #6f42c1;">رصيد افتتاحي سابق للمورد</h3>
            <button onclick="toggleOpeningBalanceModal(false)" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <div style="background: #f4f0fa; color: #4a2f7a; padding: 10px 12px; border-radius: 6px; font-size: 12.5px; margin-bottom: 15px;">
            لدَين سابق للمورد بلا فواتير أو تفاصيل بضاعة (رصيد يوم بدء استخدام النظام). لا يؤثر على المخزون
            ولا على قائمة الدخل — يُقيَّد فقط كذمة مقابل حساب "أرصدة افتتاحية" ضمن حقوق الملكية.
            إدخال قيمة جديدة هنا يستبدل القيمة السابقة بالكامل (وليس إضافة عليها).
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="set_opening_balance" value="1">

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">قيمة الرصيد الافتتاحي (USD):</label>
                <input type="number" step="0.0001" min="0" name="opening_balance_usd" value="<?php echo htmlspecialchars($opening_balance_usd); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ الرصيد الافتتاحي:</label>
                <input type="date" name="opening_balance_date" value="<?php echo substr($supplier['created_at'] ?? date('Y-m-d'), 0, 10); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">
                <button type="button" onclick="toggleOpeningBalanceModal(false)" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" style="background: #6f42c1; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ الرصيد الافتتاحي</button>
            </div>
        </form>
    </div>
</div>

<script>
    function togglePaymentModal(show) {
        document.getElementById('paymentModal').style.display = show ? 'flex' : 'none';
    }

    function toggleDiscountModal(show) {
        document.getElementById('discountModal').style.display = show ? 'flex' : 'none';
    }

    function toggleOpeningBalanceModal(show) {
        document.getElementById('openingBalanceModal').style.display = show ? 'flex' : 'none';
    }

    function openEditPaymentModal(payment) {
        document.getElementById('edit_payment_id').value = payment.id;
        document.getElementById('edit_amount_usd').value = payment.amount_usd;
        document.getElementById('edit_payment_date').value = payment.payment_date;
        document.getElementById('edit_notes').value = payment.notes || '';
        document.getElementById('editPaymentModal').style.display = 'flex';
    }

    function closeEditPaymentModal() {
        document.getElementById('editPaymentModal').style.display = 'none';
    }
</script>

<!-- نافذة تعديل دفعة موجودة (Modal) -->
<div id="editPaymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; width: 450px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #f6c23e;"><i class="fas fa-edit"></i> تعديل دفعة المورد</h3>
            <button onclick="closeEditPaymentModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #888;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="edit_payment" value="1">
            <input type="hidden" name="payment_id" id="edit_payment_id">

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">المبلغ المدفوع (USD):</label>
                <input type="number" step="0.0001" name="amount_usd" id="edit_amount_usd" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">تاريخ الدفعة:</label>
                <input type="date" name="payment_date" id="edit_payment_date" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">ملاحظات / السند:</label>
                <textarea name="notes" id="edit_notes" rows="3" placeholder="تفاصيل الدفعة أو رقم السند..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            </div>

            <div style="text-align: left; border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">
                <button type="button" onclick="closeEditPaymentModal()" style="background: none; border: none; color: #666; padding: 8px 15px; cursor: pointer; margin-left: 5px;">إلغاء</button>
                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">حفظ التعديل</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>