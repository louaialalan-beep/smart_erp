<?php
/**
 * سكربت ترحيل لمرة واحدة: يبني دفعات مخزون (inventory_batches) من فواتير الشراء التاريخية الموجودة
 * فعلاً، ويضبط "الكمية المتبقية" لكل دفعة عبر منطق FIFO ليطابق current_quantity الفعلي لكل منتج الآن.
 *
 * آمن تماماً على البيانات المالية القائمة: لا يلمس sale_items ولا journal_entries ولا أي رصيد محاسبي
 * موجود إطلاقاً — فقط يُنشئ سجلات "دفعات" جديدة تصف تاريخ الشراء الفعلي، ليستفيد منها النظام الجديد
 * (COGS حسب المورد الحقيقي) بدءاً من أول عملية بيع/مرتجع جديدة تُنشأ بعد تشغيل هذا السكربت.
 *
 * لا يُعيد بناء دفعات استهلاك تاريخية لكل عملية بيع سابقة (خطر غير ضروري) — المبيعات القديمة ستستمر
 * باستخدام منهجية التكلفة الممزوجة القديمة تلقائياً (عبر آلية الرجوع built-in في التقارير)، بينما أي
 * بيع/مرتجع جديد من الآن فصاعداً يستخدم الدفعات الدقيقة الجديدة مباشرة.
 *
 * آمن للتشغيل مرة واحدة فقط — يرفض العمل إن وجد أي دفعات مُرحَّلة مسبقاً (تفادياً للتكرار).
 * التشغيل: افتح هذا الملف مباشرة في المتصفح مرة واحدة، أو نفّذه عبر سطر الأوامر (php migrate_inventory_batches.php).
 */

require_once 'db.php';
require_once 'includes/system_helpers.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html dir='rtl' lang='ar'><head><meta charset='UTF-8'><title>ترحيل نظام دفعات المخزون</title>";
echo "<style>body{font-family:Tahoma,Arial,sans-serif;background:#f8f9fc;padding:30px;line-height:1.8;}
.ok{color:#1a8f5f;} .warn{color:#96751c;} .err{color:#a33636;font-weight:bold;}
table{border-collapse:collapse;width:100%;margin:15px 0;background:white;}
th,td{border:1px solid #e3e6f0;padding:8px 12px;text-align:right;font-size:13px;}
th{background:#f8f9fc;} code{background:#eef1f9;padding:2px 6px;border-radius:4px;font-family:monospace;}
.box{background:white;border:1px solid #e3e6f0;border-radius:8px;padding:20px;margin-bottom:15px;}</style></head><body>";
echo "<h2>ترحيل نظام دفعات المخزون (تشغيل لمرة واحدة)</h2>";

ensureInventoryBatchTables($conn);

// ============================================================
// حارس عدم التكرار: إن وُجدت أي دفعات مُرحَّلة مسبقاً (source_type IN ('Purchase','OfficeInventoryLegacy')
// بمعرّف مصدر)، نرفض التشغيل مرة أخرى — يشمل هذا أي دفعات أنشأها النظام الجديد فعلياً منذ اليوم (لا
// نريد لهذا السكربت أن يُنشئ دفعات مكرِّرة فوق دفعات حقيقية جديدة بالفعل).
// ============================================================
$stmt_guard = $conn->query("SELECT COUNT(*) FROM inventory_batches WHERE source_type IN ('Purchase', 'OfficeInventoryLegacy')");
if ($stmt_guard->fetchColumn() > 0) {
    echo "<div class='box err'>تم تشغيل هذا الترحيل مسبقاً (توجد دفعات بالفعل في الجدول). لن يُنفَّذ مرة أخرى تفادياً للتكرار.<br>";
    echo "إن كنت تريد إعادة الترحيل من الصفر عن قصد (غير مستحسن)، احذف يدوياً أولاً: <code>DELETE FROM inventory_batches WHERE source_type IN ('Purchase','OfficeInventoryLegacy'); DELETE FROM sale_item_batch_consumption WHERE batch_id NOT IN (SELECT id FROM inventory_batches);</code></div>";
    echo "</body></html>";
    exit;
}

$conn->beginTransaction();
try {
    $created_batches = 0;
    $products_processed = 0;
    $log_rows = [];

    // كل المنتجات التي لها أي أثر تاريخي (مشتريات، أو كمية حالية، أو بيع سابق)
    $stmt_products = $conn->query("SELECT id, product_name, sku, supplier_id, current_quantity, cost_price_usd, created_at FROM products ORDER BY id");
    $products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $p) {
        $pid = $p['id'];

        // (أ) كل فواتير الشراء الفعلية لهذا المنتج، بترتيب تاريخ الشراء (الأقدم أولاً = FIFO)
        $stmt_pur = $conn->prepare("
            SELECT pii.id AS item_id, pii.quantity, pii.unit_cost_usd, pi.supplier_id, pi.invoice_date, pi.invoice_number
            FROM purchase_invoice_items pii
            JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
            WHERE pii.product_id = ?
            ORDER BY pi.invoice_date ASC, pii.id ASC
        ");
        $stmt_pur->execute([$pid]);
        $purchase_rows = $stmt_pur->fetchAll(PDO::FETCH_ASSOC);

        // صافي ما أُرجِع لكل سطر شراء (لطرحه من الكمية المستلمة الفعلية لهذا السطر)
        $stmt_pur_ret = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM purchase_return_items WHERE purchase_invoice_item_id = ?");

        $total_purchased_net = 0;
        $batch_specs = []; // كل عنصر: ['item_id'=>, 'supplier_id'=>, 'cost'=>, 'qty'=>, 'date'=>, 'ref'=>]
        foreach ($purchase_rows as $pr) {
            $stmt_pur_ret->execute([$pr['item_id']]);
            $returned = floatval($stmt_pur_ret->fetchColumn());
            $net_qty = floatval($pr['quantity']) - $returned;
            if ($net_qty <= 0) continue;
            $batch_specs[] = [
                'item_id' => $pr['item_id'], 'supplier_id' => $pr['supplier_id'], 'cost' => floatval($pr['unit_cost_usd']),
                'qty' => $net_qty, 'date' => $pr['invoice_date'], 'ref' => $pr['invoice_number'], 'type' => 'Purchase'
            ];
            $total_purchased_net += $net_qty;
        }

        // (ب) الفارق بين "كل ما دخل عبر فواتير شراء فعلية" و"إجمالي ما دخل المخزون تاريخياً فعلياً"
        // (المُشتق من: الكمية الحالية + صافي كل ما بِيع تاريخياً) — الفارق الموجب يمثّل جرداً مكتبياً أو
        // إضافة يدوية للمخزون سابقة لهذا الترحيل، بلا سجل فاتورة شراء مقابل. يُغطَّى بدفعة "تراثية" واحدة
        // بتكلفة المنتج الحالية، مؤرَّخة بأقدم نقطة معروفة (تاريخ إنشاء المنتج) لتُستهلَك أولاً في FIFO
        // (تقريب معقول: الأقدم زمنياً على الأرجح الأقدم دخولاً للمخزون فعلياً).
        $stmt_sold = $conn->prepare("
            SELECT COALESCE(SUM(si.quantity), 0) AS sold, COALESCE((
                SELECT SUM(sri.quantity) FROM sales_return_items sri
                JOIN sale_items si2 ON sri.sale_item_id = si2.id WHERE si2.product_id = ?
            ), 0) AS returned_by_customers
            FROM sale_items si WHERE si.product_id = ?
        ");
        $stmt_sold->execute([$pid, $pid]);
        $sold_row = $stmt_sold->fetch(PDO::FETCH_ASSOC);
        $total_sold_net = floatval($sold_row['sold']) - floatval($sold_row['returned_by_customers']);

        $current_qty = floatval($p['current_quantity']);
        $implied_total_inflow = $current_qty + $total_sold_net; // كل ما دخل المخزون تاريخياً على الإطلاق
        $office_legacy_qty = $implied_total_inflow - $total_purchased_net;

        if ($office_legacy_qty > 0.0001) {
            $legacy_date = substr($p['created_at'] ?: date('Y-m-d'), 0, 10);
            array_unshift($batch_specs, [
                'item_id' => null, 'supplier_id' => null, 'cost' => floatval($p['cost_price_usd']),
                'qty' => $office_legacy_qty, 'date' => $legacy_date, 'ref' => 'رصيد جرد مكتبي/يدوي سابق لتفعيل نظام الدفعات', 'type' => 'OfficeInventoryLegacy'
            ]);
        }

        if (count($batch_specs) == 0) continue;

        // (ج) توزيع "ما تبقّى فعلياً الآن" (current_quantity) على الدفعات بترتيب FIFO: الأقدم يُستهلَك
        // أولاً بالكامل، حتى تُغطّى كمية "ما بِيع صافياً"، والباقي (الأحدث) يبقى متبقياً بالكامل.
        $qty_to_mark_consumed = $total_sold_net;
        foreach ($batch_specs as $bs) {
            $consumed_here = min($qty_to_mark_consumed, $bs['qty']);
            $remaining_here = $bs['qty'] - $consumed_here;
            $qty_to_mark_consumed -= $consumed_here;

            $batch_id = createInventoryBatch(
                $conn, $pid, $bs['type'], $bs['ref'], $bs['supplier_id'], $bs['cost'], $bs['qty'], $bs['date'], $bs['item_id']
            );
            if ($batch_id) {
                $conn->prepare("UPDATE inventory_batches SET quantity_remaining = ? WHERE id = ?")->execute([$remaining_here, $batch_id]);
                $created_batches++;
            }
        }

        $products_processed++;
        $log_rows[] = [$p['sku'], $p['product_name'], count($batch_specs), $total_purchased_net, $office_legacy_qty, $current_qty];
    }

    $conn->commit();

    echo "<div class='box ok'>✅ اكتمل الترحيل بنجاح.<br>";
    echo "منتجات عولجت: <b>$products_processed</b> — دفعات أُنشئت: <b>$created_batches</b></div>";

    echo "<div class='box'><h3>تفصيل أول 50 منتجاً (للمراجعة)</h3><table>";
    echo "<tr><th>SKU</th><th>الاسم</th><th>عدد الدفعات</th><th>إجمالي مُشترى (صافي)</th><th>جرد مكتبي/تراثي مُستنتَج</th><th>الكمية الحالية</th></tr>";
    foreach (array_slice($log_rows, 0, 50) as $lr) {
        echo "<tr><td>{$lr[0]}</td><td>{$lr[1]}</td><td>{$lr[2]}</td><td>" . number_format($lr[3], 2) . "</td><td>" . number_format($lr[4], 2) . "</td><td>" . number_format($lr[5], 2) . "</td></tr>";
    }
    echo "</table></div>";

    echo "<div class='box warn'>⚠️ يمكنك الآن حذف هذا الملف (<code>migrate_inventory_batches.php</code>) بأمان — لن يُسمَح بتشغيله مرة أخرى على أي حال طالما بقيت هذه الدفعات موجودة.</div>";

} catch (Exception $e) {
    if ($conn->inTransaction()) { $conn->rollBack(); }
    echo "<div class='box err'>❌ فشل الترحيل، لم يُحفَظ أي شيء (تراجع كامل): " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</body></html>";