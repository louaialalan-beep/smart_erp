<?php
/**
 * صفحة إدارة المنتجات والمخزون الاحترافية - Smart ERP
 * تم تطبيق معايير الأمان، التحقق من المدخلات، وتحسين واجهة المستخدم.
 */
session_start();
include 'header.php';
require_once __DIR__ . '/includes/system_helpers.php';

// التأكد من وجود اتصال قاعدة البيانات
if (!isset($conn)) {
    die("خطأ: اتصال قاعدة البيانات غير متوفر.");
}

$msg = "";
$error = "";

// معرفة أعمدة جدول products ديناميكياً (تُستخدم لدعم purchased_quantity إن وُجد دون كسر التوافق)
$products_cols_stmt = $conn->query("SHOW COLUMNS FROM products");
$products_existing_cols = $products_cols_stmt->fetchAll(PDO::FETCH_COLUMN);
$has_purchased_quantity_col = in_array('purchased_quantity', $products_existing_cols);

// 1. معالجة إضافة منتج جديد مع حماية كاملة للمدخلات
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    // التحقق من رمز التحقق CSRF إذا وجد أو تنظيف المدخلات بصرامة
    $product_name        = trim($_POST['product_name'] ?? '');
    $sku                 = trim($_POST['sku'] ?? '');
    $category_id         = !empty($_POST['category_id']) ? filter_var($_POST['category_id'], FILTER_VALIDATE_INT) : null;
    // تصحيح معماري إضافي: نفس مبدأ فك الربط المطبَّق على الكمية يُطبَّق الآن على التكلفة أيضاً —
    // مصدر الحقيقة الوحيد لـ cost_price_usd هو فاتورة الشراء (purchases.php) حصراً، وليس إدخالاً يدوياً
    // هنا. المنتج الجديد يُنشأ بتكلفة صفر دائماً، وتُحدَّد تلقائياً عند تسجيل أول فاتورة شراء له.
    $cost_price_usd      = 0;
    $wholesale_price_syp = filter_var($_POST['wholesale_price_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $retail_price_syp    = filter_var($_POST['retail_price_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    // السعر الخاص أُزيل من نموذج الإضافة بناءً على الطلب؛ يبقى العمود موجوداً بقاعدة البيانات
    // (إن وُجد) ويُخزَّن صفراً افتراضياً، ويمكن تحديده لاحقاً عبر التعديل إن لزم مستقبلاً.
    $special_price_syp   = 0;
    $base_unit           = trim($_POST['base_unit'] ?? 'قطعة');
    $packing_unit        = trim($_POST['packing_unit'] ?? '');
    // تصحيح معماري: فك الربط بين إنشاء المنتج ودخول البضاعة للمخزون. المنتج الجديد يُنشأ دائماً
    // بمخزون صفري — الكمية الفعلية تدخل حصراً عبر فاتورة شراء حقيقية (purchases.php)، فيبقى
    // purchase_invoice_items المصدر الوحيد لكل حسابات المشتريات دون أي استثناءات لاحقاً.
    $current_quantity    = 0;
    $supplier_id         = !empty($_POST['supplier_id']) ? filter_var($_POST['supplier_id'], FILTER_VALIDATE_INT) : null;

    // التحقق من الحقول الإجبارية
    if (empty($product_name) || empty($sku)) {
        $error = "خطأ: اسم المنتج ورمز الباركود (SKU) حقول إجبارية لا يمكن تركها فارغة.";
    } else {
        try {
            // التحقق مسبقاً إذا كان SKU مستخدماً لتجنب خطأ قاعدة البيانات المباشر
            $check_sku = $conn->prepare("SELECT id FROM products WHERE sku = ?");
            $check_sku->execute([$sku]);
            if ($check_sku->rowCount() > 0) {
                $error = "خطأ: رمز الباركود (SKU) مستخدم مصداقاً لصنف آخر، يرجى اختيار رمز فريد.";
            } else {
                // ملاحظة: purchased_quantity لا يُملأ هنا إطلاقاً بعد الآن — يبقى 0 حتى تُسجَّل أول
                // فاتورة شراء حقيقية له عبر purchases.php، وهي التي تُنشئ current_quantity وpurchased_quantity معاً.
                $cols = ['product_name', 'sku', 'category_id', 'cost_price_usd', 'wholesale_price_syp', 'retail_price_syp', 'special_price_syp', 'base_unit', 'packing_unit', 'current_quantity', 'supplier_id'];
                $vals = [$product_name, $sku, $category_id, $cost_price_usd, $wholesale_price_syp, $retail_price_syp, $special_price_syp, $base_unit, $packing_unit, $current_quantity, $supplier_id];

                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $col_names = implode(',', $cols);
                $stmt = $conn->prepare("INSERT INTO products ({$col_names}) VALUES ({$placeholders})");
                $stmt->execute($vals);
                $new_prod_id = $conn->lastInsertId();
                logAudit($conn, 'INSERT', 'المنتجات والمخزون', "إضافة منتج: $product_name (SKU: $sku) بتكلفة $" . number_format($cost_price_usd, 4) . " — بلا مخزون أولي (يُضاف عبر فاتورة شراء)", $new_prod_id);

                $msg = "تم إضافة المنتج بنجاح! توجّه إلى (فواتير الشراء) لتسجيل أول كمية تدخل المخزون منه.";
            }
        } catch (PDOException $e) {
            $error = "خطأ في قاعدة البيانات أثناء الحفظ: " . $e->getMessage();
        }
    }
}

// 2. معالجة تعديل منتج موجود
// ملاحظة: لا يُعدَّل purchased_quantity من هنا عمداً — فهو سجل تاريخي لقيمة الشراء الأصلية.
// تعديل current_quantity هنا يُستخدم فقط لتصحيح المخزون الحالي (جرد يدوي) ولا يمس ذلك السجل.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_product'])) {
    $product_id          = intval($_POST['product_id'] ?? 0);
    $product_name        = trim($_POST['product_name'] ?? '');
    $sku                 = trim($_POST['sku'] ?? '');
    $category_id         = !empty($_POST['category_id']) ? filter_var($_POST['category_id'], FILTER_VALIDATE_INT) : null;
    // تصحيح معماري: cost_price_usd لم يعد يُقبَل من هذا النموذج إطلاقاً — القيمة المُرسَلة (إن وُجدت)
    // تُتجاهَل تماماً؛ التعديل الوحيد المسموح للتكلفة يكون عبر فاتورة شراء جديدة في purchases.php.
    $wholesale_price_syp = filter_var($_POST['wholesale_price_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $retail_price_syp    = filter_var($_POST['retail_price_syp'] ?? 0, FILTER_VALIDATE_FLOAT);
    $base_unit           = trim($_POST['base_unit'] ?? 'قطعة');
    $packing_unit        = trim($_POST['packing_unit'] ?? '');
    $current_quantity    = filter_var($_POST['current_quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
    $supplier_id         = !empty($_POST['supplier_id']) ? filter_var($_POST['supplier_id'], FILTER_VALIDATE_INT) : null;

    if ($product_id <= 0) {
        $error = "خطأ: معرف المنتج غير صالح.";
    } elseif (empty($product_name) || empty($sku)) {
        $error = "خطأ: اسم المنتج ورمز الباركود (SKU) حقول إجبارية لا يمكن تركها فارغة.";
    } else {
        try {
            // التحقق أن SKU الجديد غير مستخدم من قِبل منتج آخر (باستثناء المنتج الحالي نفسه)
            $check_sku = $conn->prepare("SELECT id FROM products WHERE sku = ? AND id != ?");
            $check_sku->execute([$sku, $product_id]);
            if ($check_sku->rowCount() > 0) {
                $error = "خطأ: رمز الباركود (SKU) مستخدم مسبقاً لصنف آخر، يرجى اختيار رمز فريد.";
            } else {
                $stmt_old = $conn->prepare("SELECT current_quantity FROM products WHERE id = ?");
                $stmt_old->execute([$product_id]);
                $old_vals = $stmt_old->fetch(PDO::FETCH_ASSOC);

                // ملاحظة: cost_price_usd مُستثنى عمداً من جملة UPDATE هذه — يبقى كما رحَّلته آخر
                // فاتورة شراء فقط، ولا يُمكن لأي مستخدم تغييره من هذا النموذج مهما أرسل في الطلب.
                $stmt = $conn->prepare("UPDATE products SET 
                    product_name = ?, sku = ?, category_id = ?, 
                    wholesale_price_syp = ?, retail_price_syp = ?, base_unit = ?, 
                    packing_unit = ?, current_quantity = ?, supplier_id = ? 
                    WHERE id = ?");
                $stmt->execute([
                    $product_name, $sku, $category_id,
                    $wholesale_price_syp, $retail_price_syp, $base_unit,
                    $packing_unit, $current_quantity, $supplier_id,
                    $product_id
                ]);

                $log_details = "تعديل منتج: $product_name (SKU: $sku)";
                if ($old_vals && floatval($old_vals['current_quantity']) != $current_quantity) {
                    $log_details .= " — تصحيح المخزون من " . number_format($old_vals['current_quantity'], 2) . " إلى " . number_format($current_quantity, 2);
                }
                logAudit($conn, 'UPDATE', 'المنتجات والمخزون', $log_details, $product_id);

                $msg = "تم تحديث بيانات المنتج بنجاح!";
            }
        } catch (PDOException $e) {
            $error = "خطأ في قاعدة البيانات أثناء التحديث: " . $e->getMessage();
        }
    }
}

// 3. معاملات البحث والفلترة الآمنة
$search           = trim($_GET['search'] ?? '');
$filter_category  = $_GET['category_id'] ?? '';
$filter_supplier  = $_GET['supplier_id'] ?? '';

$where  = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[]  = "(p.product_name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!empty($filter_category)) {
    $where[]  = "p.category_id = ?";
    $params[] = $filter_category;
}
if (!empty($filter_supplier)) {
    $where[]  = "p.supplier_id = ?";
    $params[] = $filter_supplier;
}

// 4. استعلام جلب المنتجات مع التصنيفات والموردين (Prepared Statements)
$sql = "SELECT p.*, c.category_name, s.supplier_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        LEFT JOIN suppliers s ON p.supplier_id = s.id 
        WHERE " . implode(" AND ", $where) . " 
        ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب التصنيفات والموردين للقوائم المنسدلة
$categories = $conn->query("SELECT id, category_name FROM categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$suppliers  = $conn->query("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="color: #2e384d; margin-bottom: 5px;">إدارة المنتجات والمخزون (Products Module)</h2>
        <p style="color: #6c757d; margin: 0; font-size: 14px;">التحكم بالأصناف، تكاليف البضائع بالدولار، أسعار البيع، والتتبع اللحظي للمخزون.</p>
    </div>
    <div>
        <button onclick="toggleProductModal(true)" style="background: #1cc88a; color: white; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <i class="fas fa-plus"></i> إضافة صنف جديد
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- شريط البحث والفلترة الاحترافي -->
<div style="background: #f8f9fc; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58, 59, 69, 0.05);">
    <form method="GET" action="" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 2; min-width: 220px;">
            <label style="font-size: 13px; font-weight: bold; color: #4e73df;">بحث بالاسم أو الباركود (SKU):</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="أدخل اسم المنتج أو رمز الباركود..." style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; margin-top: 5px;">
        </div>
        <div style="flex: 1; min-width: 160px;">
            <label style="font-size: 13px; font-weight: bold; color: #4e73df;">التصنيف:</label>
            <select name="category_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; margin-top: 5px; background: #fff;">
                <option value="">-- كافة التصنيفات --</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['category_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1; min-width: 160px;">
            <label style="font-size: 13px; font-weight: bold; color: #4e73df;">المورد الأساسي:</label>
            <select name="supplier_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; margin-top: 5px; background: #fff;">
                <option value="">-- كافة الموردين --</option>
                <?php foreach ($suppliers as $sup): ?>
                    <option value="<?php echo $sup['id']; ?>" <?php echo $filter_supplier == $sup['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sup['supplier_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" style="background: #4e73df; color: white; border: none; padding: 10px 18px; border-radius: 6px; cursor: pointer; font-weight: bold;">بحث وتصفية</button>
            <a href="products.php" style="background: #858796; color: white; padding: 10px 15px; border-radius: 6px; text-decoration: none; display: inline-block; font-size: 13px; margin-right: 5px; font-weight: bold;">إلغاء الفلتر</a>
        </div>
    </form>
</div>

<!-- جدول عرض المنتجات والمخزون -->
<div style="background: #fff; border: 1px solid #e3e6f0; border-radius: 8px; overflow: hidden; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: right;">
            <thead>
                <tr style="background: #f8f9fc; color: #4e73df; border-bottom: 2px solid #e3e6f0;">
                    <th style="padding: 12px 15px;">الباركود / SKU</th>
                    <th style="padding: 12px 15px;">اسم المنتج</th>
                    <th style="padding: 12px 15px;">التصنيف</th>
                    <th style="padding: 12px 15px; color: #e74a3b;">التكلفة (USD)</th>
                    <th style="padding: 12px 15px;">سعر الجملة (ل.س)</th>
                    <th style="padding: 12px 15px;">سعر المفرق (ل.س)</th>
                    <th style="padding: 12px 15px;">المخزون الحالي</th>
                    <th style="padding: 12px 15px;">المورد الأساسي</th>
                    <th style="padding: 12px 15px; text-align: center;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $prod): ?>
                        <tr style="border-bottom: 1px solid #f1f1f1; transition: background 0.2s;" onmouseover="this.style.background='#f8f9fc'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 12px 15px; font-family: monospace; font-weight: bold; color: #4e73df;">
                                <?php echo htmlspecialchars($prod['sku']); ?>
                            </td>
                            <td style="padding: 12px 15px; font-weight: 600; color: #333;">
                                <?php echo htmlspecialchars($prod['product_name']); ?>
                            </td>
                            <td style="padding: 12px 15px; color: #666;">
                                <?php echo htmlspecialchars($prod['category_name'] ?: 'غير مصنف'); ?>
                            </td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #e74a3b; font-weight: bold;">
                                $<?php echo number_format($prod['cost_price_usd'], 4); ?>
                            </td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #1cc88a; font-weight: bold;">
                                <?php echo number_format($prod['wholesale_price_syp'], 2); ?>
                            </td>
                            <td style="padding: 12px 15px; font-family: monospace; color: #2e59d9; font-weight: bold;">
                                <?php echo number_format($prod['retail_price_syp'], 2); ?>
                            </td>
                            <td style="padding: 12px 15px; font-family: monospace; font-weight: bold;">
                                <span style="background: #eaecf4; padding: 4px 10px; border-radius: 4px; color: #333; display: inline-block;">
                                    <?php echo number_format($prod['current_quantity'], 2); ?> <?php echo htmlspecialchars($prod['base_unit']); ?>
                                </span>
                            </td>
                            <td style="padding: 12px 15px; color: #555; font-size: 13px;">
                                <?php echo htmlspecialchars($prod['supplier_name'] ?: 'بدون مورد'); ?>
                            </td>
                            <td style="padding: 12px 15px; text-align: center;">
                                <button onclick='openEditProductModal(<?php echo json_encode($prod, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' style="background: #f6c23e; color: white; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">
                                    <i class="fas fa-edit"></i> تعديل
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="padding: 40px; text-align: center; color: #777;">
                            <i class="fas fa-box-open" style="font-size: 35px; color: #ccc; margin-bottom: 10px; display: block;"></i>
                            لا توجد منتجات مسجلة مطابقة لخيارات البحث أو الفلترة الحالية.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- نافذة إضافة صنف جديد (Modal) -->
<div id="productModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1050; justify-content: center; align-items: center;">
    <div style="background: white; width: 900px; max-width: 95%; border-radius: 8px; padding: 25px; max-height: 90vh; overflow-y: auto; box-shadow: 0 5px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e3e6f0; padding-bottom: 12px; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #4e73df;"><i class="fas fa-plus-circle"></i> إضافة صنف منتج جديد وتحديد التسعير والمخزون</h3>
            <button onclick="toggleProductModal(false)" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #888; line-height: 1;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="add_product" value="1">

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">اسم المنتج: <span style="color: red;">*</span></label>
                    <input type="text" name="product_name" required placeholder="مثال: لابتوب ديل / سكر ناعم..." style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">الباركود / SKU: <span style="color: red;">*</span></label>
                    <input type="text" name="sku" required placeholder="مثال: PRD-1001" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">التصنيف أو الفئة:</label>
                    <select name="category_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                        <option value="">-- اختر التصنيف --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">المورد الأساسي:</label>
                    <select name="supplier_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                        <option value="">-- اختر المورد الأساسي --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h4 style="margin: 20px 0 10px 0; border-bottom: 1px solid #e3e6f0; padding-bottom: 5px; color: #4e73df;"><i class="fas fa-dollar-sign"></i> أسعار البيع (ل.س):</h4>
            <div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 10px 14px; border-radius: 6px; margin-bottom: 15px; color: #0c5460; font-size: 12.5px;">
                <i class="fas fa-info-circle"></i> سعر التكلفة بالدولار (COGS) لا يُدخَل هنا — يبدأ صفراً، ويُحدَّد تلقائياً وحصراً من <strong>فاتورة الشراء</strong> الأولى لهذا المنتج في صفحة "فواتير الشراء".
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">سعر البيع جملة (ل.س):</label>
                <input type="number" step="0.01" name="wholesale_price_syp" value="0.00" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">سعر البيع مفرق (ل.س):</label>
                <input type="number" step="0.01" name="retail_price_syp" value="0.00" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
            </div>

            <h4 style="margin: 20px 0 10px 0; border-bottom: 1px solid #e3e6f0; padding-bottom: 5px; color: #4e73df;"><i class="fas boxes"></i> الوحدات:</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">وحدة القياس الأساسية:</label>
                    <input type="text" name="base_unit" value="قطعة" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">وحدات التعبئة (اختياري):</label>
                    <input type="text" name="packing_unit" placeholder="مثال: كرتونة = 12 قطعة" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
            </div>
            <div style="background: #e8f4fd; border: 1px solid #bbe1fa; padding: 10px 14px; border-radius: 6px; margin-bottom: 20px; color: #0c5460; font-size: 12.5px;">
                <i class="fas fa-info-circle"></i> المخزون يبدأ بصفر دائماً. لتسجيل أول كمية تدخل المخزون، توجّه إلى <strong>فواتير الشراء</strong> بعد حفظ هذا المنتج.
            </div>

            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px; margin-top: 25px;">
                <button type="button" onclick="toggleProductModal(false)" style="background: #e2e8f0; color: #333; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; margin-left: 10px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: #1cc88a; color: white; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-weight: bold;">حفظ الصنف</button>
            </div>
        </form>
    </div>
</div>

<!-- نافذة تعديل صنف موجود (Modal) -->
<div id="editProductModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1050; justify-content: center; align-items: center;">
    <div style="background: white; width: 900px; max-width: 95%; border-radius: 8px; padding: 25px; max-height: 90vh; overflow-y: auto; box-shadow: 0 5px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e3e6f0; padding-bottom: 12px; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #f6c23e;"><i class="fas fa-edit"></i> تعديل بيانات الصنف والتسعير والمخزون</h3>
            <button onclick="toggleEditProductModal(false)" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #888; line-height: 1;">&times;</button>
        </div>

        <form method="POST" action="">
<?php csrfField(); ?>
            <input type="hidden" name="edit_product" value="1">
            <input type="hidden" name="product_id" id="edit_product_id">

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">اسم المنتج: <span style="color: red;">*</span></label>
                    <input type="text" name="product_name" id="edit_product_name" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">الباركود / SKU: <span style="color: red;">*</span></label>
                    <input type="text" name="sku" id="edit_sku" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">التصنيف أو الفئة:</label>
                    <select name="category_id" id="edit_category_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                        <option value="">-- اختر التصنيف --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">المورد الأساسي:</label>
                    <select name="supplier_id" id="edit_supplier_id" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; background: #fff;">
                        <option value="">-- اختر المورد الأساسي --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h4 style="margin: 20px 0 10px 0; border-bottom: 1px solid #e3e6f0; padding-bottom: 5px; color: #4e73df;"><i class="fas fa-dollar-sign"></i> التسعير:</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div style="background: #f8f9fc; padding: 12px; border-radius: 6px; border: 1px dashed #ccc;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #888;">سعر التكلفة الحالي (للعرض فقط):</label>
                    <input type="text" id="edit_cost_price_usd_display" readonly disabled style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-weight: bold; font-family: monospace; background: #eee; color: #666; cursor: not-allowed;">
                    <p style="font-size: 11px; color: #888; margin: 5px 0 0;"><i class="fas fa-lock"></i> يُحدَّث تلقائياً حصراً من فاتورة شراء جديدة في "فواتير الشراء"</p>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">سعر البيع جملة (ل.س):</label>
                    <input type="number" step="0.01" name="wholesale_price_syp" id="edit_wholesale_price_syp" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">سعر البيع مفرق (ل.س):</label>
                <input type="number" step="0.01" name="retail_price_syp" id="edit_retail_price_syp" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
            </div>

            <h4 style="margin: 20px 0 10px 0; border-bottom: 1px solid #e3e6f0; padding-bottom: 5px; color: #4e73df;"><i class="fas boxes"></i> الوحدات والمخزون:</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 10px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">وحدة القياس الأساسية:</label>
                    <input type="text" name="base_unit" id="edit_base_unit" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">وحدات التعبئة (اختياري):</label>
                    <input type="text" name="packing_unit" id="edit_packing_unit" style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333;">الكمية الحالية بالمخزون:</label>
                    <input type="number" step="0.0001" name="current_quantity" id="edit_current_quantity" required style="width: 100%; padding: 9px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: monospace;">
                </div>
            </div>
            <p style="font-size: 12px; color: #888; margin: 0 0 15px 0;">
                <i class="fas fa-info-circle"></i> تعديل الكمية هنا يُصحِّح المخزون الحالي فقط (كجرد يدوي) ولا يُغيِّر سجل الكمية الأصلية المشتراة من المورد.
            </p>

            <div style="text-align: left; border-top: 1px solid #e3e6f0; padding-top: 15px; margin-top: 25px;">
                <button type="button" onclick="toggleEditProductModal(false)" style="background: #e2e8f0; color: #333; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; margin-left: 10px; font-weight: bold;">إلغاء</button>
                <button type="submit" style="background: #f6c23e; color: white; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-weight: bold;">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleProductModal(show) {
        document.getElementById('productModal').style.display = show ? 'flex' : 'none';
    }

    function toggleEditProductModal(show) {
        document.getElementById('editProductModal').style.display = show ? 'flex' : 'none';
    }

    function openEditProductModal(prod) {
        document.getElementById('edit_product_id').value = prod.id;
        document.getElementById('edit_product_name').value = prod.product_name;
        document.getElementById('edit_sku').value = prod.sku;
        document.getElementById('edit_category_id').value = prod.category_id || '';
        document.getElementById('edit_supplier_id').value = prod.supplier_id || '';
        document.getElementById('edit_cost_price_usd_display').value = '$' + parseFloat(prod.cost_price_usd).toFixed(4);
        document.getElementById('edit_wholesale_price_syp').value = prod.wholesale_price_syp;
        document.getElementById('edit_retail_price_syp').value = prod.retail_price_syp;
        document.getElementById('edit_base_unit').value = prod.base_unit;
        document.getElementById('edit_packing_unit').value = prod.packing_unit || '';
        document.getElementById('edit_current_quantity').value = prod.current_quantity;
        toggleEditProductModal(true);
    }
</script>

<?php include 'footer.php'; ?>