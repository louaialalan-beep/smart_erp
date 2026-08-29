<?php
include 'header.php';

// جلب إحصائيات سريعة من قاعدة البيانات لإثراء لوحة التحكم
try {
    // عدد العملات المضافة
    $cur_count = $conn->query("SELECT COUNT(*) FROM currencies")->fetchColumn();
    
    // عدد القيود المحاسبية المسجلة (مثال افتراضي حسب جدولك)
    // $entries_count = $conn->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
} catch (Exception $e) {
    $cur_count = 0;
}
?>

<div style="padding: 20px;">
    <!-- ترحيب بمدير النظام -->
    <div style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); color: white; padding: 25px; border-radius: 10px; margin-bottom: 25px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,0.15);">
        <h1 style="margin: 0 0 10px 0; font-size: 24px;">مرحباً بك، لؤي القبالان</h1>
        <p style="margin: 0; opacity: 0.9; font-size: 14px;">نظام Smart ERP المطور يعمل بكفاءة عالية. يمكنك إدارة الحسابات، العملات، والقيود من القائمة الجانبية.</p>
    </div>

    <!-- مربعات الإحصائيات السريعة (Widgets) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px;">
        
        <!-- بطاقة العملات -->
        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #4e73df; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;">العملات المُعرّفة</div>
            <div style="font-size: 22px; font-weight: bold; color: #3a3b45;"><?php echo $cur_count; ?> عملات</div>
        </div>

        <!-- بطاقة سريعة أخرى -->
        <div style="background: white; padding: 20px; border-radius: 8px; border-right: 4px solid #1cc88a; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
            <div style="color: #858796; font-size: 12px; font-weight: bold; margin-bottom: 5px;">حالة النظام</div>
            <div style="font-size: 18px; font-weight: bold; color: #1cc88a;">متصل وآمن (Online)</div>
        </div>

    </div>

    <!-- قسم الاختصارات السريعة -->
    <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1rem 0 rgba(58,59,69,0.08);">
        <h3 style="margin-top: 0; color: #3a3b45; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">إجراءات سريعة</h3>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
            <a href="currencies.php" style="background: #4e73df; color: white; padding: 10px 15px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;">إدارة العملات وأسعار الصرف</a>
            <a href="journal.php" style="background: #1cc88a; color: white; padding: 10px 15px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;">دفتر اليومية الشامل</a>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>