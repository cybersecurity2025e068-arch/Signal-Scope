// سلوكيات بسيطة بلا مكتبات (متوافقة مع CSP: لا سكربتات داخل الصفحة)
document.addEventListener('click', function (e) {
  var t = e.target.closest('[data-confirm]');
  if (t && !confirm(t.getAttribute('data-confirm'))) { e.preventDefault(); return; }
  if (e.target.closest('[data-print]')) { e.preventDefault(); window.print(); return; }
  if (e.target.closest('[data-toggle-side]')) { document.body.classList.toggle('side-open'); return; }
  if (e.target.closest('[data-close-side]')) { document.body.classList.remove('side-open'); return; }
  var r = e.target.closest('[data-reveal]');
  if (r) {
    var s = r.parentNode.querySelector('[data-secret]');
    if (s) { var shown = s.textContent !== '••••••'; s.textContent = shown ? '••••••' : s.getAttribute('data-secret'); r.textContent = shown ? 'إظهار' : 'إخفاء'; }
    return;
  }
  var row = e.target.closest('tr[data-href]');
  if (row && !e.target.closest('a,button,input,select,form')) { location.href = row.getAttribute('data-href'); }
});
document.addEventListener('change', function (e) {
  if (e.target.matches('[data-autosubmit]')) { e.target.form.submit(); }
});
// منع الإرسال المزدوج للنماذج
document.addEventListener('submit', function (e) {
  var f = e.target;
  if (f.method.toLowerCase() !== 'post') return;
  if (f.dataset.sent) { e.preventDefault(); return; }
  f.dataset.sent = '1';
  setTimeout(function () { delete f.dataset.sent; }, 4000);
});
