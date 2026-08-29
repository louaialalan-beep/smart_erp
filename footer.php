<footer class="zoho-footer">
    <span>VNDR &copy; 2026 - جميع الحقوق محفوظة</span>
    <span>|</span>
    <strong>VNRD</strong>
</footer>

<style>
.zoho-footer {
    background: var(--zoho-card-bg, #ffffff);
    border-top: 1px solid var(--zoho-border, #e3e6f0);
    padding: 12px 25px;
    color: var(--zoho-text-muted, #6c757d);
    font-size: 13px;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin-top: auto;
}
.zoho-footer strong {
    color: var(--zoho-primary, #4e73df);
}

/* ============================================================
   أزرار إجراءات هادئة للعين (تُستخدم في جداول الصفوف عبر كل صفحات النظام:
   sales.php, purchases.php, supplier_view.php, representative_profile.php...)
   نمط outline خفيف بدل ألوان صارخة ممتلئة، مع تلوين كامل فقط عند التمرير.
   ============================================================ */
.row-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 11px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    background: transparent;
    transition: all 0.15s ease;
    line-height: 1.4;
    text-decoration: none;
}

/* تعديل (أصفر/كهرماني هادئ) */
.row-action-edit {
    color: #b8860b;
    border-color: #f0dca0;
    background: #fdf8ec;
}
.row-action-edit:hover {
    background: #f6c23e;
    border-color: #f6c23e;
    color: #fff;
}

/* مرتجع / إلغاء (أحمر هادئ) */
.row-action-return, .row-action-danger {
    color: #c0392b;
    border-color: #f1c6c0;
    background: #fdf2f0;
}
.row-action-return:hover, .row-action-danger:hover {
    background: #e74a3b;
    border-color: #e74a3b;
    color: #fff;
}

/* تأكيد / نجاح (أخضر هادئ) */
.row-action-success {
    color: #1a8f6b;
    border-color: #b9e6d4;
    background: #eefaf5;
}
.row-action-success:hover {
    background: #1cc88a;
    border-color: #1cc88a;
    color: #fff;
}

/* معلومة / عرض (أزرق هادئ) */
.row-action-info {
    color: #2e59d9;
    border-color: #c3d3f7;
    background: #eef2fd;
}
.row-action-info:hover {
    background: #4e73df;
    border-color: #4e73df;
    color: #fff;
}

/* مقفَل / غير متاح */
.row-action-locked {
    color: #b0b7c3;
    font-size: 11px;
}

/* شارات الحالة بخلفية باهتة أهدأ للعين بدل الألوان المشبعة الممتلئة
   (تُستخدم مع style="background: X; color: Y;" مُمرَّرة من PHP حسب الحالة) */
.status-badge {
    display: inline-block;
    padding: 4px 11px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 600;
}
</style>