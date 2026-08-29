<?php
// دالة جلب سعر الصرف التاريخي أو أحدث سعر سابق متوفر حتى تاريخ محدد
// $fallback: القيمة الاحتياطية عند عدم وجود أي سعر مسجَّل إطلاقاً لهذه العملة.
// تصحيح: الافتراضي السابق كان 1.0 لكل العملات، وهو خطأ جسيم للدولار (1$ ≠ 1 ل.س).
// الآن الافتراضي 15000 لعملة USD تحديداً (مطابق للقيمة المستخدمة فعلياً في sales.php وsupplier_view.php)،
// و1.0 لأي عملة أخرى غير محدَّدة صراحة (منطقي فقط للعملة الأساسية نفسها).
function getExchangeRateForDate($conn, $currency_code, $date, $fallback = null) {
    if ($fallback === null) {
        $fallback = ($currency_code === 'USD') ? 15000.0 : 1.0;
    }

    try {
        $stmt = $conn->prepare("SELECT exchange_rate FROM exchange_rates WHERE currency_code = ? AND rate_date <= ? ORDER BY rate_date DESC LIMIT 1");
        $stmt->execute([$currency_code, $date]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? floatval($res['exchange_rate']) : $fallback;
    } catch (Exception $e) {
        return $fallback;
    }
}