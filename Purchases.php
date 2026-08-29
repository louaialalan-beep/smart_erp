<?php
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';
require_once __DIR__ . '/functions.php';

$msg = ""; $error = "";

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
$conn->exec("CREATE TABLE IF NOT EXISTS purchase_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_invoice_id INT NOT NULL,
    return_date DATE NOT NULL,
    total_amount_usd DECIMAL(15,2) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->exec("CREATE TABLE IF NOT EXISTS purchase_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_return_id INT NOT NULL,
    purchase_invoice_item_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    unit_cost_usd DECIMAL(15,4) NOT NULL,
    total_cost_usd DECIMAL(15,2) NOT NULL
)");

// دالة عامة للبحث عن حساب محاسبي (نفس منطق باقي النظام)

function insertJournalLine($conn, $account_id, $debit, $credit, $entry_number, $entry_date, $description, $source_module, $currency_code = 'SYP', $exchange_rate = 1, $foreign_debit = null, $foreign_credit = null) {
    $stmt_cols = $conn->query("SHOW COLUMNS FROM journal_entries");
    $existing_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);
    $cols_to_insert = ['account_id', 'entry_date', 'description', 'debit', 'credit'];
    $vals = [$account_id, $entry_date, $description, $debit, $credit];
    if (in_array('entry_number', $existing_cols)) { $cols_to_insert[] = 'entry_number'; $vals[] = $entry_number; }
    if (in_array('currency_code', $existing_cols)) { $cols_to_insert[] = 'currency_code'; $vals[] = $currency_code; }
    if (in_array('exchange_rate', $existing_cols)) { $cols_to_insert[] = 'exchange_rate'; $vals[] = $exchange_rate; }
    if ($foreign_debit !== null && in_array('foreign_debit', $existing_cols)) { $cols_to_insert[] = 'foreign_debit'; $vals[] = $foreign_debit; }
    if ($foreign_credit !== null && in_array('foreign_credit', $existing_cols)) { $cols_to_insert[] = 'foreign_credit'; $vals[] = $foreign_credit; }
    if (in_array('source_module', $existing_cols)) { $cols_to_insert[] = 'source_module'; $vals[] = $source_module; }
    $placeholders = implode(',', array_fill(0, count($cols_to_insert), '?'));
    $col_names = implode(',', $cols_to_insert);
    $conn->prepare("INSERT INTO journal_entries ({$col_names}) VALUES ({$placeholders})")->execute($vals);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_purchase'])) {
    requireRole($conn, ['admin', 'accountant']);

    $invoice_number = trim($_POST['invoice_number']);
    $supplier_id = intval($_POST['supplier_id']);
    $exchange_rate = floatval($_POST['exchange_rate']);
    $invoice_date = $_POST['invoice_date'];
    $notes = trim($_POST['notes']);
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_costs = $_POST['unit_cost_usd'] ?? [];

    if (empty($invoice_number) || $supplier_id <= 0 || count($product_ids) == 0) {
        $error = "يرجى إدخال رقم الفاتورة، اختيار المورد، وصنف واحد على الأقل.";
    } elseif (isDateInClosedPeriod($conn, $invoice_date)) {
        $error = getPeriodLockErrorMessage($invoice_date);
    } else {
        try {
            $conn->beginTransaction();
            $total_usd = 0;
            $items = [];
            for ($i = 0; $i < count($product_ids); $i++) {
                $pid = intval($product_ids[$i]);
                $qty = floatval($quantities[$i]);
                $cost = floatval($unit_costs[$i]);
                if ($pid > 0 && $qty > 0) {
                    $line_total = $qty * $cost;
                    $total_usd += $line_total;
                    $items[] = ['product_id' => $pid, 'qty' => $qty, 'cost' => $cost, 'total' => $line_total];
                }
            }

            $stmt = $conn->prepare("INSERT INTO purchase_invoices (invoice_number, supplier_id, exchange_rate, total_amount_usd, invoice_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoice_number, $supplier_id, $exchange_rate, $total_usd, $invoice_date, $notes]);
            $purchase_id = $conn->lastInsertId();

            foreach ($items as $it) {
                $conn->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id, product_id, quantity, unit_cost_usd, total_cost_usd) VALUES (?, ?, ?, ?, ?)")
                     ->execute([$purchase_id, $it['product_id'], $it['qty'], $it['cost'], $it['total']]);

                // زيادة المخزون الحالي والكمية المشتراة معاً (خلافاً للبيع الذي يُنقص current_quantity فقط)
                // وتحديث تكلفة المنتج لآخر سعر شراء (Latest Cost)
                $conn->prepare("UPDATE products SET current_quantity = current_quantity + ?, purchased_quantity = purchased_quantity + ?, cost_price_usd = ? WHERE id = ?")
                     ->execute([$it['qty'], $it['qty'], $it['cost'], $it['product_id']]);
            }

            $stmt_sup = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt_sup->execute([$supplier_id]);
            $supplier_name = $stmt_sup->fetchColumn() ?: "مورد #$supplier_id";

            // قيد مزدوج: مدين المخزون (أصل) / دائن ذمم الموردين (التزام) — بالدولار
            $entry_num = "JE-PUR-" . $purchase_id;
            $desc = "فاتورة شراء رقم $invoice_number من المورد: $supplier_name";
            $base_amount = $total_usd * $exchange_rate;

            $debit_account_id = findOrCreateAccount($conn, ['مخزون', 'بضاعة', 'inventory'], 'المخزون');
            $credit_account_id = findOrCreateAccount($conn, ['ذمم', 'مورد', 'payable'], 'ذمم الموردين');

            if ($debit_account_id && $credit_account_id) {
                insertJournalLine($conn, $debit_account_id, $base_amount, 0, $entry_num, $invoice_date, $desc, 'Purchase', 'USD', $exchange_rate, $total_usd, 0);
                insertJournalLine($conn, $credit_account_id, 0, $base_amount, $entry_num, $invoice_date, $desc, 'Purchase', 'USD', $exchange_rate, 0, $total_usd);
            }

            $conn->commit();
            logAudit($conn, 'INSERT', 'فواتير الشراء', "فاتورة شراء رقم $invoice_number من $supplier_name بقيمة $" . number_format($total_usd, 2), $purchase_id);
            $msg = "تم تسجيل فاتورة الشراء وتحديث المخزون وترحيل القيد المحاسبي بنجاح!";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ: " . $e->getMessage();
        }
    }
}

// 2. تعديل فاتورة شراء موجودة (تعديل تاريخ/سعر صرف/ملاحظات + كميات وتكاليف الأصناف الحالية،
// دون إضافة/حذف أصناف جديدة لتبسيط ضبط أثر المخزون). يُعاد ترحيل قيد عكس + قيد تصحيحي (نفس مبدأ
// التعديل بالعكس المعتمد في بقية النظام)، مع التحقق أن التعديل لن يُنقص المخزون تحت الصفر.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_purchase'])) {
    requireRole($conn, ['admin', 'accountant']);

    $purchase_id = intval($_POST['purchase_id'] ?? 0);
    $invoice_number = trim($_POST['invoice_number']);
    $exchange_rate = floatval($_POST['exchange_rate']);
    $invoice_date = $_POST['invoice_date'];
    $notes = trim($_POST['notes']);
    $item_ids = $_POST['edit_item_id'] ?? [];
    $new_quantities = $_POST['edit_quantity'] ?? [];
    $new_costs = $_POST['edit_unit_cost_usd'] ?? [];

    $stmt_check = $conn->prepare("SELECT * FROM purchase_invoices WHERE id = ?");
    $stmt_check->execute([$purchase_id]);
    $purchase = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) {
        $error = "فاتورة الشراء غير موجودة.";
    } elseif (empty($invoice_number)) {
        $error = "رقم الفاتورة إجباري.";
    } elseif (isDateInClosedPeriod($conn, $purchase['invoice_date']) || isDateInClosedPeriod($conn, $invoice_date)) {
        $error = getPeriodLockErrorMessage($invoice_date);
    } else {
        try {
            $conn->beginTransaction();

            $new_total_usd = 0;
            $stock_adjustments = []; // product_id => delta (قد تكون سالبة)
            $updated_items = [];

            for ($i = 0; $i < count($item_ids); $i++) {
                $item_id = intval($item_ids[$i]);
                $new_qty = floatval($new_quantities[$i] ?? 0);
                $new_cost = floatval($new_costs[$i] ?? 0);
                if ($new_qty <= 0) { throw new Exception("الكمية يجب أن تكون أكبر من صفر لكل الأصناف."); }

                $stmt_old_item = $conn->prepare("SELECT * FROM purchase_invoice_items WHERE id = ? AND purchase_invoice_id = ?");
                $stmt_old_item->execute([$item_id, $purchase_id]);
                $old_item = $stmt_old_item->fetch(PDO::FETCH_ASSOC);
                if (!$old_item) { continue; }

                $qty_delta = $new_qty - floatval($old_item['quantity']);
                $stock_adjustments[$old_item['product_id']] = ($stock_adjustments[$old_item['product_id']] ?? 0) + $qty_delta;

                $new_line_total = $new_qty * $new_cost;
                $new_total_usd += $new_line_total;
                $updated_items[] = ['id' => $item_id, 'product_id' => $old_item['product_id'], 'qty' => $new_qty, 'cost' => $new_cost, 'total' => $new_line_total];
            }

            // التحقق أن أي تخفيض في الكمية لن يُنقص المخزون الحالي تحت الصفر (أي أن جزءاً من الكمية بيع بالفعل)
            foreach ($stock_adjustments as $pid => $delta) {
                if ($delta < 0) {
                    $stmt_cur = $conn->prepare("SELECT current_quantity FROM products WHERE id = ?");
                    $stmt_cur->execute([$pid]);
                    $cur_qty = floatval($stmt_cur->fetchColumn());
                    if (($cur_qty + $delta) < 0) {
                        throw new Exception("لا يمكن تخفيض الكمية: جزء منها بيع بالفعل من المخزون ولا يمكن الرجوع تحت الصفر.");
                    }
                }
            }

            // تطبيق التعديلات: تحديث سطور الفاتورة + المخزون + التكلفة الحالية
            foreach ($updated_items as $it) {
                $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, unit_cost_usd = ?, total_cost_usd = ? WHERE id = ?")
                     ->execute([$it['qty'], $it['cost'], $it['total'], $it['id']]);
            }
            foreach ($stock_adjustments as $pid => $delta) {
                if ($delta != 0) {
                    $conn->prepare("UPDATE products SET current_quantity = current_quantity + ?, purchased_quantity = purchased_quantity + ? WHERE id = ?")
                         ->execute([$delta, $delta, $pid]);
                }
            }
            // تحديث التكلفة الحالية لكل منتج مُعدَّل إلى آخر تكلفة أُدخلت في هذا التعديل
            foreach ($updated_items as $it) {
                $conn->prepare("UPDATE products SET cost_price_usd = ? WHERE id = ?")->execute([$it['cost'], $it['product_id']]);
            }

            $conn->prepare("UPDATE purchase_invoices SET invoice_number = ?, exchange_rate = ?, total_amount_usd = ?, invoice_date = ?, notes = ? WHERE id = ?")
                 ->execute([$invoice_number, $exchange_rate, $new_total_usd, $invoice_date, $notes, $purchase_id]);

            // القيد المحاسبي: عكس القيد الأصلي بالكامل + ترحيل قيد جديد صحيح بالقيمة المُحدَّثة
            $stmt_sup = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt_sup->execute([$purchase['supplier_id']]);
            $supplier_name = $stmt_sup->fetchColumn() ?: "مورد #" . $purchase['supplier_id'];

            $original_entry_num = "JE-PUR-" . $purchase_id;
            $stmt_je = $conn->prepare("SELECT account_id, debit, credit FROM journal_entries WHERE entry_number = ?");
            $stmt_je->execute([$original_entry_num]);
            $je_lines = $stmt_je->fetchAll(PDO::FETCH_ASSOC);

            if (count($je_lines) > 0) {
                $today = date('Y-m-d');
                $rev_entry_num = $original_entry_num . "-REV-" . time();
                $new_entry_num = $original_entry_num . "-CORR-" . time();
                $rev_desc = "عكس تلقائي لفاتورة شراء معدَّلة (الأصل: $original_entry_num) من المورد: $supplier_name";
                $new_desc = "فاتورة شراء رقم $invoice_number معدَّلة من المورد: $supplier_name";
                $new_base_amount = $new_total_usd * $exchange_rate;

                foreach ($je_lines as $line) {
                    insertJournalLine($conn, $line['account_id'], floatval($line['credit']), floatval($line['debit']), $rev_entry_num, $today, $rev_desc, 'Purchase Reversal', 'USD', $exchange_rate);
                    $is_debit_line = floatval($line['debit']) > 0;
                    insertJournalLine($conn, $line['account_id'], $is_debit_line ? $new_base_amount : 0, $is_debit_line ? 0 : $new_base_amount, $new_entry_num, $invoice_date, $new_desc, 'Purchase', 'USD', $exchange_rate, $is_debit_line ? $new_total_usd : 0, $is_debit_line ? 0 : $new_total_usd);
                }
            }

            $conn->commit();
            logAudit($conn, 'UPDATE', 'فواتير الشراء', "تعديل فاتورة شراء #$purchase_id ($invoice_number) من $supplier_name — القيمة الجديدة $" . number_format($new_total_usd, 2), $purchase_id);
            $msg = "تم تحديث فاتورة الشراء والمخزون، مع ترحيل قيد عكس وقيد تصحيحي، بنجاح!";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ أثناء التعديل: " . $e->getMessage();
        }
    }
}

// 3. حذف فاتورة شراء بالكامل — مسموح فقط إن لم تُستهلك أي كمية منها بعد (لم تُبَع)، ولم تقع
// ضمن فترة مالية مغلقة. يعكس أثر المخزون بالكامل ويحذف القيد المرتبط والسطور.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_purchase'])) {
    requireRole($conn, ['admin']);

    $purchase_id = intval($_POST['purchase_id'] ?? 0);
    $stmt_check = $conn->prepare("SELECT * FROM purchase_invoices WHERE id = ?");
    $stmt_check->execute([$purchase_id]);
    $purchase = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) {
        $error = "فاتورة الشراء غير موجودة.";
    } elseif (isDateInClosedPeriod($conn, $purchase['invoice_date'])) {
        $error = getPeriodLockErrorMessage($purchase['invoice_date']);
    } else {
        try {
            $conn->beginTransaction();

            $stmt_items = $conn->prepare("SELECT * FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $stmt_items->execute([$purchase_id]);
            $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

            // التحقق أن الكمية لم تُستهلك جزئياً من المبيعات (current_quantity لا يجب أن ينزل تحت الصفر)
            foreach ($items as $it) {
                $stmt_cur = $conn->prepare("SELECT current_quantity FROM products WHERE id = ?");
                $stmt_cur->execute([$it['product_id']]);
                $cur_qty = floatval($stmt_cur->fetchColumn());
                if (($cur_qty - floatval($it['quantity'])) < 0) {
                    throw new Exception("لا يمكن حذف الفاتورة: جزء من كمية هذا الصنف بيع بالفعل من المخزون.");
                }
            }

            foreach ($items as $it) {
                $conn->prepare("UPDATE products SET current_quantity = current_quantity - ?, purchased_quantity = purchased_quantity - ? WHERE id = ?")
                     ->execute([$it['quantity'], $it['quantity'], $it['product_id']]);
            }

            $conn->prepare("DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?")->execute([$purchase_id]);
            $conn->prepare("DELETE FROM purchase_invoices WHERE id = ?")->execute([$purchase_id]);
            $conn->prepare("DELETE FROM journal_entries WHERE entry_number LIKE ?")->execute(["JE-PUR-" . $purchase_id . "%"]);

            $conn->commit();
            logAudit($conn, 'DELETE', 'فواتير الشراء', "حذف فاتورة شراء #$purchase_id (" . $purchase['invoice_number'] . ") وعكس أثرها على المخزون والقيود", $purchase_id);
            $msg = "تم حذف فاتورة الشراء وعكس أثرها على المخزون والقيود المحاسبية بنجاح.";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ أثناء الحذف: " . $e->getMessage();
        }
    }
}

// 4. مرتجع للمورد (Purchase Return) — يوثِّق إرجاع جزء معيب/غير مطابق من فاتورة شراء بعينها،
// بخلاف الحذف الكامل: هنا يمكن إرجاع جزء فقط حتى لو بِيع الباقي من نفس الفاتورة، طالما الكمية
// المرتجعة تحديداً لا تزال متوفرة فعلياً في المخزون (لم تُبَع هي بالذات).
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_purchase_return'])) {
    requireRole($conn, ['admin', 'accountant']);

    $ret_purchase_id = intval($_POST['return_purchase_id']);
    $return_date = $_POST['return_date'];
    $ret_notes = trim($_POST['return_notes']);
    $item_ids = $_POST['ret_item_id'] ?? [];
    $ret_quantities = $_POST['ret_quantity'] ?? [];

    if ($ret_purchase_id <= 0 || count($item_ids) == 0) {
        $error = "يرجى اختيار فاتورة وكمية مرتجعة واحدة على الأقل.";
    } elseif (isDateInClosedPeriod($conn, $return_date)) {
        $error = getPeriodLockErrorMessage($return_date);
    } else {
        try {
            $conn->beginTransaction();

            $stmt_purchase = $conn->prepare("SELECT * FROM purchase_invoices WHERE id = ?");
            $stmt_purchase->execute([$ret_purchase_id]);
            $purchase = $stmt_purchase->fetch(PDO::FETCH_ASSOC);
            if (!$purchase) { throw new Exception("فاتورة الشراء غير موجودة."); }

            $total_return_usd = 0;
            $return_lines = [];

            for ($i = 0; $i < count($item_ids); $i++) {
                $item_id = intval($item_ids[$i]);
                $ret_qty = floatval($ret_quantities[$i] ?? 0);
                if ($ret_qty <= 0) { continue; }

                $stmt_item = $conn->prepare("SELECT * FROM purchase_invoice_items WHERE id = ? AND purchase_invoice_id = ?");
                $stmt_item->execute([$item_id, $ret_purchase_id]);
                $item = $stmt_item->fetch(PDO::FETCH_ASSOC);
                if (!$item) { continue; }

                // الكمية المتاحة للإرجاع = كمية هذا السطر - ما أُرجِع منه سابقاً بالفعل
                $stmt_already = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM purchase_return_items WHERE purchase_invoice_item_id = ?");
                $stmt_already->execute([$item_id]);
                $already_returned = floatval($stmt_already->fetchColumn());
                $max_returnable = floatval($item['quantity']) - $already_returned;

                if ($ret_qty > $max_returnable) {
                    throw new Exception("الكمية المرتجعة تتجاوز المتاح للإرجاع لهذا الصنف ($max_returnable).");
                }

                // يجب أن تكون الكمية المرتجعة لا تزال متوفرة فعلياً في المخزون (لم تُبَع بعد)
                $stmt_stock = $conn->prepare("SELECT current_quantity FROM products WHERE id = ?");
                $stmt_stock->execute([$item['product_id']]);
                $current_stock = floatval($stmt_stock->fetchColumn());
                if ($ret_qty > $current_stock) {
                    throw new Exception("لا يمكن إرجاع هذه الكمية: جزء منها بيع بالفعل من المخزون الحالي.");
                }

                $line_total = $ret_qty * floatval($item['unit_cost_usd']);
                $total_return_usd += $line_total;
                $return_lines[] = ['item_id' => $item_id, 'product_id' => $item['product_id'], 'qty' => $ret_qty, 'cost' => $item['unit_cost_usd'], 'total' => $line_total];
            }

            if (count($return_lines) == 0) { throw new Exception("لم تُدخَل أي كمية مرتجعة صحيحة."); }

            $stmt_ret = $conn->prepare("INSERT INTO purchase_returns (purchase_invoice_id, return_date, total_amount_usd, notes) VALUES (?, ?, ?, ?)");
            $stmt_ret->execute([$ret_purchase_id, $return_date, $total_return_usd, $ret_notes]);
            $return_id = $conn->lastInsertId();

            foreach ($return_lines as $line) {
                $conn->prepare("INSERT INTO purchase_return_items (purchase_return_id, purchase_invoice_item_id, product_id, quantity, unit_cost_usd, total_cost_usd) VALUES (?, ?, ?, ?, ?, ?)")
                     ->execute([$return_id, $line['item_id'], $line['product_id'], $line['qty'], $line['cost'], $line['total']]);

                // إخراج الكمية المرتجعة من المخزون (تعود فعلياً للمورد وتخرج من عهدتنا)
                $conn->prepare("UPDATE products SET current_quantity = current_quantity - ?, purchased_quantity = purchased_quantity - ? WHERE id = ?")
                     ->execute([$line['qty'], $line['qty'], $line['product_id']]);
            }

            $stmt_sup = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt_sup->execute([$purchase['supplier_id']]);
            $supplier_name = $stmt_sup->fetchColumn() ?: "مورد #" . $purchase['supplier_id'];

            // القيد المحاسبي: عكس جزئي لأثر الشراء الأصلي — مدين ذمم الموردين (يُخفِّض الالتزام تجاهه)
            // / دائن المخزون (يُخفِّض قيمة الأصل، لأن البضاعة خرجت فعلياً وعادت للمورد)
            $debit_account_id = findOrCreateAccount($conn, ['ذمم', 'مورد', 'payable'], 'ذمم الموردين');
            $credit_account_id = findOrCreateAccount($conn, ['مخزون', 'بضاعة', 'inventory'], 'المخزون');

            if ($debit_account_id && $credit_account_id) {
                $entry_num = "JE-PURRET-" . $return_id;
                $desc = "مرتجع للمورد على فاتورة شراء رقم: " . $purchase['invoice_number'] . " (" . $supplier_name . ")" . (!empty($ret_notes) ? " - $ret_notes" : "");
                $base_amount = $total_return_usd * floatval($purchase['exchange_rate']);

                insertJournalLine($conn, $debit_account_id, $base_amount, 0, $entry_num, $return_date, $desc, 'Purchase Return', 'USD', $purchase['exchange_rate'], $total_return_usd, 0);
                insertJournalLine($conn, $credit_account_id, 0, $base_amount, $entry_num, $return_date, $desc, 'Purchase Return', 'USD', $purchase['exchange_rate'], 0, $total_return_usd);
            }

            $conn->commit();
            logAudit($conn, 'INSERT', 'مرتجعات الشراء', "مرتجع للمورد $supplier_name على فاتورة " . $purchase['invoice_number'] . " بقيمة $" . number_format($total_return_usd, 2), $return_id);
            $msg = "تم تسجيل مرتجع المورد، إخراج الكمية من المخزون، وترحيل القيد المحاسبي بنجاح!";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "خطأ أثناء تسجيل المرتجع: " . $e->getMessage();
        }
    }
}

$purchases_list = $conn->query("SELECT p.*, s.supplier_name FROM purchase_invoices p LEFT JOIN suppliers s ON p.supplier_id = s.id ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$suppliers_list = $conn->query("SELECT * FROM suppliers ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$products_list = $conn->query("SELECT * FROM products ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$default_rate = getExchangeRateForDate($conn, 'USD', date('Y-m-d'));

// جلب أصناف كل فاتورة شراء (لتغذية نافذتَي التعديل والمرتجع بالجافاسكريبت)
// مع الكمية المتاحة للإرجاع لكل صنف = الكمية الأصلية - ما أُرجِع منه سابقاً
$items_by_purchase = [];
$stmt_all_pi_items = $conn->query("
    SELECT pii.*, pr.product_name, pr.current_quantity AS product_current_qty,
           COALESCE((SELECT SUM(pri.quantity) FROM purchase_return_items pri WHERE pri.purchase_invoice_item_id = pii.id), 0) AS already_returned
    FROM purchase_invoice_items pii
    LEFT JOIN products pr ON pii.product_id = pr.id
");
foreach ($stmt_all_pi_items->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $row['returnable'] = min(floatval($row['quantity']) - floatval($row['already_returned']), floatval($row['product_current_qty']));
    $items_by_purchase[$row['purchase_invoice_id']][] = $row;
}
?>
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <h2><i class="fas fa-truck-loading"></i> فواتير الشراء من الموردين</h2>
        <p style="color:#666; margin:0;">تسجيل كل عملية توريد كمعاملة مستقلة موثَّقة، مع تحديث المخزون وترحيل القيد تلقائياً.</p>
    </div>
    <button onclick="openModal()" style="background:#1cc88a; color:white; border:none; padding:9px 18px; border-radius:6px; cursor:pointer; font-weight:bold;"><i class="fas fa-plus"></i> فاتورة شراء جديدة</button>
</div>

<?php if ($msg): ?><div style="background:#d4edda; color:#155724; padding:12px; border-radius:6px; margin-bottom:15px;"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<?php if ($error): ?><div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:6px; margin-bottom:15px;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div style="background:white; border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
    <table style="width:100%; border-collapse:collapse; font-size:14px; text-align:right;">
        <thead>
            <tr style="background:#f8f9fc; color:#4e73df; border-bottom:2px solid #e3e6f0;">
                <th style="padding:10px 15px;">رقم الفاتورة</th><th style="padding:10px 15px;">المورد</th>
                <th style="padding:10px 15px;">القيمة (USD)</th><th style="padding:10px 15px;">سعر الصرف</th><th style="padding:10px 15px;">التاريخ</th>
                <th style="padding:10px 15px; text-align:center;">الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($purchases_list) > 0): foreach ($purchases_list as $p):
                $p_items = $items_by_purchase[$p['id']] ?? [];
                // إن كانت أي كمية من أي صنف في هذه الفاتورة قد استُهلكت جزئياً من المخزون الحالي، نمنع الحذف
                $has_consumed_stock = false;
                foreach ($p_items as $it) {
                    if (floatval($it['product_current_qty']) < floatval($it['quantity'])) { $has_consumed_stock = true; break; }
                }
            ?>
                <tr style="border-bottom:1px solid #f1f1f1;">
                    <td style="padding:10px 15px; font-family:monospace; font-weight:bold; color:#4e73df;"><?php echo htmlspecialchars($p['invoice_number']); ?></td>
                    <td style="padding:10px 15px;"><?php echo htmlspecialchars($p['supplier_name'] ?: 'غير محدد'); ?></td>
                    <td style="padding:10px 15px; font-family:monospace; color:#e74a3b; font-weight:bold;">$<?php echo number_format($p['total_amount_usd'], 2); ?></td>
                    <td style="padding:10px 15px; font-family:monospace;"><?php echo number_format($p['exchange_rate'], 2); ?></td>
                    <td style="padding:10px 15px; font-family:monospace; color:#666;"><?php echo htmlspecialchars($p['invoice_date']); ?></td>
                    <td style="padding:10px 15px; text-align:center; white-space:nowrap;">
                        <button onclick='openEditPurchaseModal(<?php echo json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($p_items, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background:#f6c23e; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:12px; font-weight:bold;">
                            <i class="fas fa-edit"></i> تعديل
                        </button>
                        <?php if (count(array_filter($p_items, fn($it) => $it['returnable'] > 0)) > 0): ?>
                            <button onclick='openReturnPurchaseModal(<?php echo $p['id']; ?>, "<?php echo htmlspecialchars($p['invoice_number'], ENT_QUOTES); ?>", <?php echo json_encode($p_items, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background:#856404; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:12px; font-weight:bold;">
                                <i class="fas fa-undo"></i> مرتجع للمورد
                            </button>
                        <?php endif; ?>
                        <?php if ($has_consumed_stock): ?>
                            <span style="color:#aaa; font-size:11px;" title="لا يمكن الحذف — جزء من الكمية بيع بالفعل"><i class="fas fa-lock"></i></span>
                        <?php else: ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('حذف فاتورة الشراء نهائياً؟ سيُعكَس أثرها على المخزون والقيود.');">
<?php csrfField(); ?>
                                <input type="hidden" name="delete_purchase" value="1">
                                <input type="hidden" name="purchase_id" value="<?php echo $p['id']; ?>">
                                <button type="submit" style="background:#e74a3b; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:12px; font-weight:bold;">
                                    <i class="fas fa-trash-alt"></i> حذف
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" style="padding:25px; text-align:center; color:#777;">لا توجد فواتير شراء مسجلة بعد.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="purModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; overflow-y:auto;">
    <div style="background:white; width:800px; max-width:95%; border-radius:8px; padding:25px; margin:30px auto;">
        <h3 style="margin-top:0; color:#1cc88a;">فاتورة شراء جديدة</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="add_purchase" value="1">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div><label>رقم الفاتورة:</label><input type="text" name="invoice_number" required value="PUR-<?php echo time(); ?>" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:monospace;"></div>
                <div><label>المورد:</label>
                    <select name="supplier_id" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
                        <option value="">-- اختر --</option>
                        <?php foreach ($suppliers_list as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div><label>سعر الصرف:</label><input type="number" step="0.0001" name="exchange_rate" value="<?php echo htmlspecialchars($default_rate); ?>" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:monospace;"></div>
                <div><label>تاريخ الفاتورة:</label><input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:monospace;"></div>
            </div>
            <h4 style="color:#4e73df;">الأصناف</h4>
            <div id="itemsContainer">
                <div class="pur-row" style="display:grid; grid-template-columns:3fr 1fr 1fr auto; gap:10px; margin-bottom:10px;">
                    <select name="product_id[]" required style="padding:8px;border:1px solid #ccc;border-radius:4px;">
                        <option value="">-- المنتج --</option>
                        <?php foreach ($products_list as $prod): ?><option value="<?php echo $prod['id']; ?>"><?php echo htmlspecialchars($prod['product_name']); ?></option><?php endforeach; ?>
                    </select>
                    <input type="number" step="0.0001" name="quantity[]" placeholder="الكمية" required style="padding:8px;border:1px solid #ccc;border-radius:4px;">
                    <input type="number" step="0.0001" name="unit_cost_usd[]" placeholder="تكلفة الوحدة $" required style="padding:8px;border:1px solid #ccc;border-radius:4px;">
                    <button type="button" onclick="this.parentElement.remove()" style="background:#e74a3b;color:white;border:none;padding:8px 12px;border-radius:4px;cursor:pointer;"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <button type="button" onclick="addRow()" style="background:#4e73df;color:white;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:13px;">+ إضافة صنف</button>
            <div style="text-align:left; border-top:1px solid #eee; padding-top:15px; margin-top:15px;">
                <button type="button" onclick="closeModal()" style="background:none;border:none;padding:8px 15px;cursor:pointer;">إلغاء</button>
                <button type="submit" style="background:#1cc88a;color:white;border:none;padding:8px 20px;border-radius:4px;cursor:pointer;font-weight:bold;">حفظ وترحيل الفاتورة</button>
            </div>
        </form>
    </div>
</div>
<script>
    function openModal() { document.getElementById('purModal').style.display='flex'; }
    function closeModal() { document.getElementById('purModal').style.display='none'; }
    function addRow() {
        var c = document.getElementById('itemsContainer');
        var r = c.querySelector('.pur-row').cloneNode(true);
        r.querySelectorAll('input').forEach(i => i.value = '');
        r.querySelector('select').selectedIndex = 0;
        c.appendChild(r);
    }

    function openEditPurchaseModal(purchase, items) {
        document.getElementById('edit_purchase_id').value = purchase.id;
        document.getElementById('edit_invoice_number').value = purchase.invoice_number;
        document.getElementById('edit_exchange_rate').value = purchase.exchange_rate;
        document.getElementById('edit_invoice_date').value = purchase.invoice_date;
        document.getElementById('edit_notes').value = purchase.notes || '';

        var tbody = document.getElementById('editItemsBody');
        tbody.innerHTML = '';
        items.forEach(function (it) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td style="padding:8px;">' + (it.product_name || '') + '</td>' +
                '<td style="padding:8px;"><input type="hidden" name="edit_item_id[]" value="' + it.id + '">' +
                '<input type="number" step="0.0001" min="0.0001" name="edit_quantity[]" value="' + it.quantity + '" required style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;"></td>' +
                '<td style="padding:8px;"><input type="number" step="0.0001" min="0" name="edit_unit_cost_usd[]" value="' + it.unit_cost_usd + '" required style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;"></td>' +
                '<td style="padding:8px; font-family:monospace; color:#666;">' + it.product_current_qty + '</td>';
            tbody.appendChild(tr);
        });
        document.getElementById('editPurchaseModal').style.display = 'flex';
    }

    function closeEditPurchaseModal() {
        document.getElementById('editPurchaseModal').style.display = 'none';
    }

    function openReturnPurchaseModal(purchaseId, invoiceNumber, items) {
        document.getElementById('return_purchase_id').value = purchaseId;
        document.getElementById('return_invoice_label').innerText = invoiceNumber;
        var tbody = document.getElementById('returnPurItemsBody');
        tbody.innerHTML = '';
        items.forEach(function (it) {
            if (parseFloat(it.returnable) <= 0) return;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td style="padding:8px;">' + (it.product_name || '') + '</td>' +
                '<td style="padding:8px; font-family:monospace;">' + it.quantity + '</td>' +
                '<td style="padding:8px; font-family:monospace; color:#856404;">' + it.returnable + '</td>' +
                '<td style="padding:8px;"><input type="hidden" name="ret_item_id[]" value="' + it.id + '">' +
                '<input type="number" step="0.0001" min="0" max="' + it.returnable + '" name="ret_quantity[]" value="0" style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;"></td>';
            tbody.appendChild(tr);
        });
        if (tbody.children.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="padding:15px; text-align:center; color:#777;">لا توجد كمية متاحة للإرجاع (إما مُرجَعة سابقاً أو بيعت من المخزون).</td></tr>';
        }
        document.getElementById('returnPurchaseModal').style.display = 'flex';
    }

    function closeReturnPurchaseModal() {
        document.getElementById('returnPurchaseModal').style.display = 'none';
    }
</script>

<!-- Modal تعديل فاتورة شراء -->
<div id="editPurchaseModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; overflow-y:auto;">
    <div style="background:white; width:750px; max-width:95%; border-radius:8px; padding:25px; margin:30px auto;">
        <h3 style="margin-top:0; color:#f6c23e;"><i class="fas fa-edit"></i> تعديل فاتورة شراء</h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="edit_purchase" value="1">
            <input type="hidden" name="purchase_id" id="edit_purchase_id">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div><label>رقم الفاتورة:</label><input type="text" name="invoice_number" id="edit_invoice_number" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:monospace;"></div>
                <div><label>سعر الصرف:</label><input type="number" step="0.0001" name="exchange_rate" id="edit_exchange_rate" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:monospace;"></div>
            </div>
            <div style="margin-bottom:12px;"><label>تاريخ الفاتورة:</label><input type="date" name="invoice_date" id="edit_invoice_date" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:monospace;"></div>

            <h4 style="color:#4e73df;">الأصناف (الكمية والتكلفة قابلتان للتعديل)</h4>
            <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:right; margin-bottom:15px;">
                <thead>
                    <tr style="background:#f8f9fc; border-bottom:1px solid #ddd;">
                        <th style="padding:8px;">الصنف</th><th style="padding:8px;">الكمية</th><th style="padding:8px;">تكلفة الوحدة $</th><th style="padding:8px;">المخزون الحالي</th>
                    </tr>
                </thead>
                <tbody id="editItemsBody"></tbody>
            </table>

            <div style="margin-bottom:15px;"><label>ملاحظات:</label><textarea name="notes" id="edit_notes" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;height:55px;"></textarea></div>
            <p style="font-size:12px; color:#888;">تصغير الكمية مسموح فقط إن لم يُباع منها شيء بعد. سيُرحَّل قيد عكس + قيد تصحيحي تلقائياً.</p>
            <div style="text-align:left; border-top:1px solid #eee; padding-top:15px;">
                <button type="button" onclick="closeEditPurchaseModal()" style="background:none;border:none;padding:8px 15px;cursor:pointer;">إلغاء</button>
                <button type="submit" style="background:#f6c23e;color:white;border:none;padding:8px 20px;border-radius:4px;cursor:pointer;font-weight:bold;">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal مرتجع للمورد -->
<div id="returnPurchaseModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; overflow-y:auto;">
    <div style="background:white; width:650px; max-width:95%; border-radius:8px; padding:25px; margin:30px auto;">
        <h3 style="margin-top:0; color:#856404;"><i class="fas fa-undo"></i> مرتجع للمورد على فاتورة: <span id="return_invoice_label"></span></h3>
        <form method="POST">
<?php csrfField(); ?>
            <input type="hidden" name="add_purchase_return" value="1">
            <input type="hidden" name="return_purchase_id" id="return_purchase_id">
            <div style="margin-bottom:12px;"><label>تاريخ المرتجع:</label><input type="date" name="return_date" value="<?php echo date('Y-m-d'); ?>" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:monospace;"></div>
            <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:right; margin-bottom:15px;">
                <thead>
                    <tr style="background:#f8f9fc; border-bottom:1px solid #ddd;">
                        <th style="padding:8px;">الصنف</th><th style="padding:8px;">الكمية الأصلية</th><th style="padding:8px;">المتاح للإرجاع</th><th style="padding:8px;">كمية المرتجع</th>
                    </tr>
                </thead>
                <tbody id="returnPurItemsBody"></tbody>
            </table>
            <div style="margin-bottom:15px;"><label>سبب المرتجع:</label><textarea name="return_notes" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;height:55px;" placeholder="مثال: بضاعة معيبة، مخالفة للمواصفات..."></textarea></div>
            <p style="font-size:12px; color:#888;">تُخرَج الكمية من المخزون وتُخفَّض ذمم المورد تلقائياً بقيمة المرتجع.</p>
            <div style="text-align:left; border-top:1px solid #eee; padding-top:15px;">
                <button type="button" onclick="closeReturnPurchaseModal()" style="background:none;border:none;padding:8px 15px;cursor:pointer;">إلغاء</button>
                <button type="submit" style="background:#856404;color:white;border:none;padding:8px 20px;border-radius:4px;cursor:pointer;font-weight:bold;">تسجيل المرتجع</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>