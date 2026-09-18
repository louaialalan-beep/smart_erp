<?php
/**
 * أداة تشخيص فروقات المخزون - Smart ERP
 * ------------------------------------------------------------
 * الغرض: عند وجود شك بأن current_quantity المخزَّن لمنتج ما لا يطابق ما يُفترض حسابه من سجل حركاته
 * الفعلية (شراء - بيع + إرجاع عميل - إرجاع لمورد +/- تسويات يدوية)، تعيد هذه الصفحة بناء "دفتر أستاذ"
 * كامل مرتَّب زمنياً لكل حركة لحقت بالمنتج من كل الجداول ذات العلاقة، وتحسب الرصيد التراكمي المتوقَّع
 * خطوة بخطوة، ثم تقارنه بالقيمة المخزَّنة فعلياً في جدول products — فيظهر الفرق (إن وُجد) بوضوح تام،
 * مع تحديد أي حركة بالضبط بعدها ينشأ الفرق لأول مرة.
 *
 * ملاحظة مهمة: هذه الصفحة للقراءة فقط (SELECT فقط) — لا تُعدِّل أي بيانات إطلاقاً.
 */
session_start();
include 'header.php';

if (!isset($conn)) {
    die("خطأ: اتصال قاعدة البيانات غير متوفر.");
}

$q = trim($_GET['q'] ?? '');
$products_found = [];

if ($q !== '') {
    $stmt = $conn->prepare("SELECT * FROM products WHERE product_name LIKE ? OR sku LIKE ? ORDER BY id ASC");
    $stmt->execute(["%$q%", "%$q%"]);
    $products_found = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * يبني دفتر الحركات الكامل لمنتج واحد (بمعرّفه) من كل الجداول ذات العلاقة، ثم يحسب رصيداً تراكمياً
 * تدريجياً، ويعيد أيضاً الفرق النهائي بين الرصيد المحسوب والرصيد المخزَّن فعلياً في جدول products.
 */
function buildProductLedger(PDO $conn, array $product): array {
    $pid = intval($product['id']);
    $ledger = [];

    // 1) حدث الإنشاء العادي (إضافة منتج جديد بالنموذج القياسي — يُنشأ دائماً بكمية 0، فلا يُغيِّر الرصيد)
    try {
        $stmt = $conn->prepare("SELECT * FROM audit_logs WHERE record_id = ? AND module_name = 'المنتجات والمخزون' AND action_type = 'INSERT' ORDER BY created_at ASC");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ledger[] = [
                'date' => substr($row['created_at'], 0, 10), 'time' => $row['created_at'], 'type' => 'إنشاء المنتج',
                'ref' => '—', 'delta' => null, 'note' => $row['details'], 'source' => 'audit_logs#' . $row['id'],
            ];
        }
    } catch (Exception $e) { }

    // 1ب) إضافات "الجرد المكتبي" (تُنفِّذ فعلياً current_quantity = current_quantity + qty في قاعدة
    // البيانات) — تُستخرَج الكمية المضافة من نص السجل وتُحسَب كحركة زيادة حقيقية، وليست مجرّد معلومة.
    // (تصحيح: كانت هذه الأحداث سابقاً تُصنَّف بلا قيمة رقمية إطلاقاً، فتختفي من الرصيد المحسوب بالكامل.)
    try {
        $stmt = $conn->prepare("SELECT * FROM audit_logs WHERE record_id = ? AND module_name = 'الجرد المكتبي' AND action_type = 'INSERT' ORDER BY created_at ASC");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $delta = null;
            if (preg_match('/كمية\s*([\-\d.,]+)\s*بتكلفة/u', $row['details'], $m)) {
                $delta = floatval(str_replace(',', '', $m[1]));
            }
            $ledger[] = [
                'date' => substr($row['created_at'], 0, 10), 'time' => $row['created_at'], 'type' => 'إضافة جرد مكتبي',
                'ref' => 'audit_logs#' . $row['id'], 'delta' => $delta, 'note' => $row['details'],
                'source' => 'audit_logs#' . $row['id'],
            ];
        }
    } catch (Exception $e) { }

    // 2) فواتير الشراء (زيادة)
    try {
        $stmt = $conn->prepare("
            SELECT pii.quantity, pi.invoice_date, pi.invoice_number, pi.id AS invoice_id, pi.created_at
            FROM purchase_invoice_items pii INNER JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
            WHERE pii.product_id = ? ORDER BY pi.invoice_date ASC, pi.id ASC
        ");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ledger[] = [
                'date' => $row['invoice_date'], 'time' => $row['created_at'], 'type' => 'شراء',
                'ref' => 'فاتورة شراء #' . $row['invoice_number'], 'delta' => floatval($row['quantity']),
                'note' => '', 'source' => 'purchase_invoice_items (invoice #' . $row['invoice_id'] . ')',
            ];
        }
    } catch (Exception $e) { }

    // 3) إرجاع لمورد (نقصان)
    try {
        $stmt = $conn->prepare("
            SELECT pri.quantity, pr.return_date, pi.invoice_number, pr.id AS return_id, pr.created_at
            FROM purchase_return_items pri
            INNER JOIN purchase_returns pr ON pri.purchase_return_id = pr.id
            INNER JOIN purchase_invoices pi ON pr.purchase_invoice_id = pi.id
            WHERE pri.product_id = ? ORDER BY pr.return_date ASC, pr.id ASC
        ");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ledger[] = [
                'date' => $row['return_date'], 'time' => $row['created_at'], 'type' => 'إرجاع لمورد',
                'ref' => 'مرتجع شراء على فاتورة #' . $row['invoice_number'], 'delta' => -floatval($row['quantity']),
                'note' => '', 'source' => 'purchase_return_items (return #' . $row['return_id'] . ')',
            ];
        }
    } catch (Exception $e) { }

    // 4) فواتير المبيعات (نقصان) — يُخصَم من المخزون عند إنشاء الفاتورة بغض النظر عن حالة التسليم
    try {
        $stmt = $conn->prepare("
            SELECT si.id AS sale_item_id, si.quantity, s.invoice_date, s.invoice_number, s.delivery_status, s.id AS sale_id, s.created_at
            FROM sale_items si INNER JOIN sales s ON si.sale_id = s.id
            WHERE si.product_id = ? ORDER BY s.invoice_date ASC, s.id ASC
        ");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ledger[] = [
                'date' => $row['invoice_date'], 'time' => $row['created_at'], 'type' => 'بيع',
                'ref' => 'فاتورة مبيع #' . $row['invoice_number'] . ' (حالة: ' . $row['delivery_status'] . ')',
                'delta' => -floatval($row['quantity']), 'note' => '',
                'source' => 'sale_items#' . $row['sale_item_id'] . ' (sale #' . $row['sale_id'] . ')',
            ];
        }
    } catch (Exception $e) { }

    // 5) إرجاع من عميل (زيادة)
    try {
        $stmt = $conn->prepare("
            SELECT sri.quantity, sr.return_date, s.invoice_number, sr.id AS return_id, sr.created_at
            FROM sales_return_items sri
            INNER JOIN sales_returns sr ON sri.sales_return_id = sr.id
            INNER JOIN sales s ON sr.sale_id = s.id
            WHERE sri.product_id = ? ORDER BY sr.return_date ASC, sr.id ASC
        ");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ledger[] = [
                'date' => $row['return_date'], 'time' => $row['created_at'], 'type' => 'إرجاع من عميل',
                'ref' => 'مرتجع على فاتورة مبيع #' . $row['invoice_number'], 'delta' => floatval($row['quantity']),
                'note' => '', 'source' => 'sales_return_items (return #' . $row['return_id'] . ')',
            ];
        }
    } catch (Exception $e) { }

    // 7) تعديلات يدوية على current_quantity من شاشة تعديل المنتج (تسويات جرد) — تُستخرَج من نص السجل
    try {
        $stmt = $conn->prepare("SELECT * FROM audit_logs WHERE record_id = ? AND module_name = 'المنتجات والمخزون' AND action_type = 'UPDATE' AND details LIKE '%تصحيح المخزون%' ORDER BY created_at ASC");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $delta = null;
            $reset_to = null;
            if (preg_match('/تصحيح المخزون من\s*([\-\d.,]+)\s*إلى\s*([\-\d.,]+)/u', $row['details'], $m)) {
                $from = floatval(str_replace(',', '', $m[1]));
                $to = floatval(str_replace(',', '', $m[2]));
                $delta = $to - $from;
                $reset_to = $to;
            }
            $ledger[] = [
                'date' => substr($row['created_at'], 0, 10), 'time' => $row['created_at'], 'type' => 'تعديل يدوي (تسوية جرد)',
                'ref' => 'audit_logs#' . $row['id'], 'delta' => $delta, 'reset_to' => $reset_to, 'note' => $row['details'],
                'source' => 'audit_logs#' . $row['id'],
            ];
        }
    } catch (Exception $e) { }

    // ترتيب زمني: التاريخ أولاً، ثم الطابع الزمني الدقيق created_at كفاصل عند تطابق التاريخ
    usort($ledger, function ($a, $b) {
        $d = strcmp($a['date'], $b['date']);
        if ($d !== 0) return $d;
        return strcmp($a['time'], $b['time']);
    });

    $running = 0;
    foreach ($ledger as &$row) {
        if (array_key_exists('reset_to', $row) && $row['reset_to'] !== null) {
            // تصحيح يدوي: يُعاد ضبط الرصيد التراكمي مباشرة إلى القيمة المصرَّح بها في السجل، بدل جمع
            // الفرق فوق الرصيد المحسوب — لأن هذا التصحيح صُمِّم أصلاً ليطابق الواقع الفعلي، فإن جُمع
            // كإضافة عادية فوق رصيد كان منحرفاً أصلاً عن المخزَّن، يُنتج انحرافاً وهمياً جديداً بنفس المقدار.
            $running = $row['reset_to'];
        } elseif ($row['delta'] !== null) {
            $running += $row['delta'];
        }
        $row['running_balance'] = $running;
    }
    unset($row);

    $computed_final = $running;
    $stored_final = floatval($product['current_quantity']);
    $discrepancy = $stored_final - $computed_final;

    // كشف احتمال ضغط زر "إضافة جرد مكتبي" مرتين بالخطأ: نفس الكمية، خلال أقل من 5 دقائق من بعضهما
    $possible_duplicates = [];
    $office_rows = array_values(array_filter($ledger, function ($r) { return $r['type'] === 'إضافة جرد مكتبي'; }));
    for ($i = 1; $i < count($office_rows); $i++) {
        $prev = $office_rows[$i - 1];
        $cur = $office_rows[$i];
        if ($prev['delta'] !== null && $cur['delta'] !== null && abs($prev['delta'] - $cur['delta']) < 0.0001) {
            $t1 = strtotime($prev['time']);
            $t2 = strtotime($cur['time']);
            if ($t1 !== false && $t2 !== false && abs($t2 - $t1) <= 300) {
                $possible_duplicates[] = [$prev, $cur];
            }
        }
    }

    return ['ledger' => $ledger, 'computed_final' => $computed_final, 'stored_final' => $stored_final, 'discrepancy' => $discrepancy, 'possible_duplicates' => $possible_duplicates];
}
?>

<div style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0;">أداة تشخيص فروقات المخزون</h2>
            <p style="color: #666; margin: 5px 0 0;">إعادة بناء دفتر حركات المنتج الكامل من كل الجداول (شراء / بيع / إرجاع عميل / إرجاع لمورد / تسويات يدوية) لتحديد مصدر أي فرق بين الرصيد المتوقَّع والرصيد المخزَّن فعلياً. للقراءة فقط، لا تُعدِّل أي بيانات.</p>
        </div>
    </div>

    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px;">
        <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <label style="font-size: 13px; font-weight: bold; color: #555;">اسم المنتج أو الباركود (SKU):</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="مثال: فستان دانتيل مكتب" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 5px; min-width: 280px; font-size: 14px;">
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 8px 18px; border-radius: 5px; cursor: pointer; font-weight: bold;"><i class="fas fa-search"></i> تشخيص</button>
        </form>
    </div>

    <?php if ($q !== '' && count($products_found) === 0): ?>
        <div style="background: #fdecea; color: #a33636; padding: 15px; border-radius: 6px;">لا يوجد أي منتج بهذا الاسم أو الباركود.</div>
    <?php endif; ?>

    <?php if (count($products_found) > 1): ?>
        <div style="background: #fff8e6; color: #96751c; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-weight: bold;">
            <i class="fas fa-exclamation-triangle"></i> تنبيه: وُجد أكثر من منتج (<?php echo count($products_found); ?>) بنفس الاسم أو باسم مشابه — سبب شائع جداً لفروقات المخزون هو وجود صنفين منفصلين بنفس الاسم تقريباً، فتُسجَّل بعض الفواتير على معرّف والبعض الآخر على معرّف مختلف، فينقسم السجل الحقيقي بين صنفين بدل صنف واحد. راجع كل صنف أدناه على حدة.
        </div>
    <?php endif; ?>

    <?php foreach ($products_found as $product):
        $result = buildProductLedger($conn, $product);
    ?>
    <div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; margin-bottom: 30px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,0.08);">
        <div style="background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #e3e6f0;">
            <h3 style="margin: 0; color: #333;"><?php echo htmlspecialchars($product['product_name']); ?> <span style="font-size: 13px; color: #888; font-weight: normal;">(معرّف #<?php echo $product['id']; ?> — SKU: <?php echo htmlspecialchars($product['sku']); ?> — <?php echo $product['supplier_id'] ? 'منتج مورد' : 'جرد مكتبي'; ?>)</span></h3>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; padding: 20px; border-bottom: 1px solid #f1f1f1;">
            <div>
                <div style="color: #888; font-size: 12.5px; font-weight: bold;">الكمية المخزَّنة فعلياً (current_quantity)</div>
                <div style="font-size: 22px; font-weight: bold; color: #333; font-family: monospace;"><?php echo rtrim(rtrim(number_format($result['stored_final'], 4), '0'), '.'); ?></div>
            </div>
            <div>
                <div style="color: #888; font-size: 12.5px; font-weight: bold;">الرصيد المحسوب من مجموع الحركات</div>
                <div style="font-size: 22px; font-weight: bold; color: #333; font-family: monospace;"><?php echo rtrim(rtrim(number_format($result['computed_final'], 4), '0'), '.'); ?></div>
            </div>
            <div>
                <div style="color: #888; font-size: 12.5px; font-weight: bold;">الفرق (المخزَّن - المحسوب)</div>
                <div style="font-size: 22px; font-weight: bold; font-family: monospace; color: <?php echo abs($result['discrepancy']) > 0.0001 ? '#e74a3b' : '#1cc88a'; ?>;">
                    <?php echo $result['discrepancy'] > 0 ? '+' : ''; ?><?php echo rtrim(rtrim(number_format($result['discrepancy'], 4), '0'), '.'); ?>
                    <?php if (abs($result['discrepancy']) <= 0.0001): ?> <i class="fas fa-check-circle"></i><?php else: ?> <i class="fas fa-exclamation-circle"></i><?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (count($result['possible_duplicates']) > 0): ?>
            <div style="background: #fff8e6; color: #96751c; padding: 12px 20px; font-size: 13.5px; border-bottom: 1px solid #f1f1f1; font-weight: bold;">
                <i class="fas fa-exclamation-triangle"></i> يوجد <?php echo count($result['possible_duplicates']); ?> إضافة/إضافات "جرد مكتبي" بنفس الكمية خلال أقل من 5 دقائق من بعضها — سبب شائع جداً: الضغط على زر "حفظ" مرتين بالخطأ (نقرة مزدوجة) عند إضافة الكمية، فتُنفَّذ عملية "current_quantity = current_quantity + الكمية" مرتين فعلياً على قاعدة البيانات، مما يزيد الرصيد المخزَّن بمقدار الكمية الزائدة عن الحقيقة. راجع السطور الملوَّنة "إضافة جرد مكتبي" في الجدول أدناه بنفس التاريخ تقريباً.
            </div>
        <?php endif; ?>

        <?php if (abs($result['discrepancy']) > 0.0001): ?>
            <div style="background: #fdecea; color: #a33636; padding: 12px 20px; font-size: 13.5px; border-bottom: 1px solid #f1f1f1;">
                <i class="fas fa-info-circle"></i> يوجد فرق حقيقي. راجع الجدول أدناه سطراً سطراً — إن كان عدد أسطر "بيع" أقل من عدد الفواتير التي تعرفها فعلياً لهذا المنتج، فهذا يعني على الأرجح أن بعض الفواتير سُجِّلت على معرّف منتج آخر مطابق بالاسم (راجع تنبيه الأعلى إن وُجد). إن لم يظهر أي سطر "تعديل يدوي" أو "إضافة جرد مكتبي" مشبوه، فالفرق ليس ناتجاً عن حدث مسجَّل — وإن كانت قاعدة البيانات قديمة قبل تفعيل تسجيل هذه التسويات، فقد يكون تعديلاً يدوياً غير مُوثَّق حدث سابقاً.
            </div>
        <?php endif; ?>

        <?php if (count($result['ledger']) === 0): ?>
            <p style="padding: 20px; text-align: center; color: #777;">لا توجد أي حركة مسجَّلة لهذا المنتج في أي من الجداول (شراء/بيع/إرجاع/تعديل).</p>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: right;">
                <thead>
                    <tr style="background: #fdfdfd; color: #555; border-bottom: 2px solid #e3e6f0;">
                        <th style="padding: 10px 15px;">التاريخ</th>
                        <th style="padding: 10px 15px;">نوع الحركة</th>
                        <th style="padding: 10px 15px;">المرجع</th>
                        <th style="padding: 10px 15px;">التغيير</th>
                        <th style="padding: 10px 15px;">الرصيد التراكمي بعدها</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result['ledger'] as $row): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="padding: 8px 15px; font-family: monospace; color: #666;"><?php echo htmlspecialchars($row['date']); ?></td>
                            <td style="padding: 8px 15px; font-weight: bold; color: <?php
                                echo $row['delta'] === null ? '#888' : ($row['delta'] > 0 ? '#1cc88a' : '#e74a3b');
                            ?>;"><?php echo htmlspecialchars($row['type']); ?></td>
                            <td style="padding: 8px 15px; color: #333;">
                                <?php echo htmlspecialchars($row['ref']); ?>
                                <?php if (!empty($row['note'])): ?><div style="font-size: 11.5px; color: #999; margin-top: 2px;"><?php echo htmlspecialchars($row['note']); ?></div><?php endif; ?>
                            </td>
                            <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color: <?php
                                echo $row['delta'] === null ? '#888' : ($row['delta'] > 0 ? '#1cc88a' : '#e74a3b');
                            ?>;">
                                <?php echo $row['delta'] === null ? '—' : ($row['delta'] > 0 ? '+' : '') . rtrim(rtrim(number_format($row['delta'], 4), '0'), '.'); ?>
                            </td>
                            <td style="padding: 8px 15px; font-family: monospace; font-weight: bold; color: #2e59d9;"><?php echo rtrim(rtrim(number_format($row['running_balance'], 4), '0'), '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div style="padding: 12px 20px; background: #f8f9fc; border-top: 1px solid #f1f1f1;">
            <a href="sales.php?list_search=<?php echo urlencode($product['product_name']); ?>" style="color: #4e73df; font-weight: bold; font-size: 13px; text-decoration: none;">
                <i class="fas fa-external-link-alt"></i> عرض كل فواتير المبيعات التي تحتوي هذا المنتج في صفحة المبيعات
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php include 'footer.php'; ?>