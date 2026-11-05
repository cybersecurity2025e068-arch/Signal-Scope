/* =====================================================================
   شريط التنقل الموحّد بين أنظمة جامعة المعقل الدولية
   ---------------------------------------------------------------------
   يُضاف إلى أي صفحة بسطر واحد قبل </body>:
     <script src="https://it-basra.com/amu/amu-nav.js" defer></script>
   خيارات اختيارية على الوسم نفسه:
     data-pos="bottom-right | bottom-left | top-right | top-left"  (الافتراضي bottom-right)
     data-offset="0"      إزاحة الزر بالبكسل عن الحافة
     data-offset-m="84"   إزاحة خاصة بشاشات الجوال (مثلاً فوق شريط التبويبات السفلي)
     data-newtab="1"      فتح الأنظمة الأخرى في تبويب جديد (مفيد في منصة الاختبارات)
   لإضافة نظام جديد مستقبلاً: أضف سطراً واحداً في القائمة SYSTEMS أدناه وارفع الملف،
   فيظهر في كل الأنظمة تلقائياً.
   ===================================================================== */
(function () {
  if (window.__AMU_NAV__) return; window.__AMU_NAV__ = true;

  const SYSTEMS = [
    { id: 'portal',   name: 'البوابة الرئيسية',      sub: 'الإعلانات والمواعيد',        url: 'https://it-basra.com/amu/',                                   match: [/it-basra\.com\/amu\/?(index\.html)?$/, /almaaqal-kontrol\/portal\/?(index\.html)?$/], icon: 'home' },
    { id: 'lectures', name: 'المحاضرات والمناهج',    sub: 'الدروس والملازم والواجبات',  url: 'https://it-basra.com/amu/lectures/',                          match: [/\/amu\/lectures\//], icon: 'book' },
    { id: 'exams',    name: 'منصة الاختبارات',       sub: 'الاختبارات الإلكترونية',     url: 'https://it-basra.com/amu/exam/',                              match: [/\/amu(-staging)?\/exam\//], icon: 'exam' },
    { id: 'exams-old',name: 'منصة الاختبارات القديمة', sub: 'تبقى حتى اكتمال الانتقال',   url: 'https://it-basra.com/cyb.ces/index.html',                     match: [/\/cyb\.ces\//], icon: 'exam' },
    { id: 'library',  name: 'الكتب الإلكترونية',   sub: 'مختصرات الكتب والمراجع',     url: 'https://it-basra.com/amu/library/',                           match: [/\/library\//], icon: 'library' },
    { id: 'attend',   name: 'الحضور والغيابات',      sub: 'تطبيق الحضور على الويب',     url: 'https://drdshsw2022-byte.github.io/almaaqal/',                match: [/github\.io\/almaaqal\/(?!kontrol)/, /github\.io\/almaaqal\/?$/], icon: 'attend' },
    { id: 'results',  name: 'السعي والنتائج',        sub: 'الدرجات في تطبيق الحضور',    url: 'https://drdshsw2022-byte.github.io/almaaqal/',                match: [], icon: 'results' },
    { id: 'kontrol',  name: 'نظام الكنترول',         sub: 'اللجنة الامتحانية',           url: 'https://drdshsw2022-byte.github.io/almaaqal-kontrol/kontrol/', match: [/almaaqal-kontrol\/kontrol\//], icon: 'kontrol' },
    { id: 'raasid',   name: 'منظومة راصد الإشارة',   sub: 'الإشارة الخلوية والمحاكاة',  url: 'https://it-basra.com/raasid/',                                match: [/\/raasid\//, /it-basra\.com\/(app|dashboard)\//], icon: 'grid' }
    // نظام جديد؟ أضف هنا: { id:'x', name:'الاسم', sub:'وصف قصير', url:'https://…', match:[/جزء-من-الرابط/], icon:'grid' },
  ];

  const ICONS = {
    home: 'M3 12l9-8 9 8M5 10v10h5v-6h4v6h5V10',
    book: 'M12 6.25v13M12 6.25C10.8 5.5 9.25 5 7.5 5S4.2 5.5 3 6.25v13C4.2 18.5 5.75 18 7.5 18s3.3.5 4.5 1.25m0-13C13.2 5.5 14.75 5 16.5 5s3.3.5 4.5 1.25v13C19.8 18.5 18.25 18 16.5 18s-3.3.5-4.5 1.25',
    library: 'M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4z',
    exam: 'M9 12l2 2 4-4m5.6-4A12 12 0 0112 3a12 12 0 01-8.6 3A12 12 0 003 9c0 5.6 3.8 10.3 9 11.6 5.2-1.3 9-6 9-11.6 0-1-.1-2-.4-3z',
    attend: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    results: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    kontrol: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    grid: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z'
  };
  const svg = (k, s) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="${ICONS[k] || ICONS.grid}"/></svg>`;

  const me = document.currentScript || document.querySelector('script[src*="amu-nav.js"]');
  const opt = (k, d) => (me && me.dataset && me.dataset[k]) || d;
  const pos = opt('pos', 'bottom-right');
  const offset = parseInt(opt('offset', '0'), 10) || 0;
  const offsetM = parseInt(opt('offsetM', String(offset)), 10) || 0;
  // داخل التطبيق (أندرويد) أو التطبيق المثبّت لا تُفتح تبويبات جديدة: الانتقال في الشاشة نفسها دائماً
  const inApp = !!window.Capacitor || (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  const newTab = opt('newtab', '') === '1' && !inApp;
  const here = location.href.split('#')[0].split('?')[0];
  const current = SYSTEMS.find((s) => s.match.some((r) => r.test(here)));

  function mount() {
    const host = document.createElement('div');
    host.id = 'amu-nav';
    host.setAttribute('dir', 'rtl');
    const root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    const V = pos.startsWith('top') ? 'top' : 'bottom';
    const H = pos.endsWith('left') ? 'left' : 'right';
    root.innerHTML = `
<style>
  :host{all:initial}
  *{box-sizing:border-box;font-family:'Cairo','Segoe UI',Tahoma,sans-serif}
  .fab{position:fixed;${V}:calc(${16 + offset}px + env(safe-area-inset-${V}, 0px));${H}:16px;z-index:48;display:flex;align-items:center;gap:8px;
    padding:8px 14px 8px 10px;border-radius:999px;border:1.5px solid rgba(245,158,11,.6);cursor:pointer;
    background:linear-gradient(135deg,#0D1428,#060A17);color:#FDE68A;font-size:13px;font-weight:800;
    box-shadow:0 10px 28px rgba(0,0,0,.45),0 0 0 3px rgba(245,158,11,.12);transition:transform .2s,box-shadow .2s}
  .fab:hover{transform:translateY(-2px);box-shadow:0 14px 32px rgba(0,0,0,.5),0 0 0 4px rgba(245,158,11,.2)}
  .fab .ic{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#F59E0B,#B45309);color:#fff}
  .fab .lbl{white-space:nowrap}
  @media (max-width:640px){.fab .lbl{display:none}.fab{padding:6px}}
  @media (max-width:767px){.fab{${V}:calc(${16 + offsetM}px + env(safe-area-inset-${V}, 0px))}.panel{${V}:calc(${70 + offsetM}px + env(safe-area-inset-${V}, 0px))}}
  .ov{position:fixed;inset:0;z-index:2147483001;display:none;background:rgba(2,4,10,.55);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
  .ov.open{display:block}
  .panel{position:fixed;${V}:calc(${70 + offset}px + env(safe-area-inset-${V}, 0px));${H}:16px;width:min(360px,calc(100vw - 32px));max-height:min(78vh,620px);overflow:auto;
    border-radius:22px;padding:14px;background:linear-gradient(180deg,#0D1428,#060A17);border:1.5px solid rgba(245,158,11,.45);
    box-shadow:0 30px 60px -12px rgba(0,0,0,.7);color:#F8FAFC;animation:up .22s cubic-bezier(.16,1,.3,1)}
  @keyframes up{from{opacity:0;transform:translateY(${V === 'bottom' ? '10px' : '-10px'})}to{opacity:1;transform:none}}
  .hd{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:2px 4px 10px;border-bottom:1px solid rgba(245,158,11,.2);margin-bottom:8px}
  .hd b{font-size:15px;font-weight:900;color:#FDE68A}
  .hd small{display:block;font-size:11px;color:#94A3B8;font-weight:700}
  .x{background:none;border:0;color:#94A3B8;font-size:22px;line-height:1;cursor:pointer;padding:4px 8px;border-radius:8px}
  .x:hover{color:#fff;background:rgba(255,255,255,.06)}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  a.it{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:12px;border-radius:16px;text-decoration:none;color:#F8FAFC;
    background:rgba(255,255,255,.04);border:1px solid rgba(148,163,184,.14);transition:background .15s,border-color .15s,transform .15s}
  a.it:hover,a.it:focus-visible{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.55);transform:translateY(-2px);outline:none}
  a.it .ii{width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:rgba(245,158,11,.15);color:#FBBF24;border:1px solid rgba(245,158,11,.35)}
  a.it b{font-size:13px;font-weight:800;line-height:1.4}
  a.it small{font-size:10.5px;color:#94A3B8;font-weight:600;line-height:1.5}
  a.it.cur{background:linear-gradient(135deg,rgba(245,158,11,.28),rgba(180,83,9,.18));border-color:#F59E0B}
  a.it.cur .ii{background:linear-gradient(135deg,#F59E0B,#B45309);color:#fff}
  a.it.cur small::after{content:' · أنت هنا';color:#FDE68A;font-weight:800}
  .ft{margin-top:10px;font-size:10.5px;color:#64748B;text-align:center;font-weight:700}
</style>
<button class="fab" type="button" aria-haspopup="dialog" aria-expanded="false" title="التنقل بين أنظمة الجامعة">
  <span class="ic">${svg('grid', 16)}</span><span class="lbl">أنظمة المعقل</span>
</button>
<div class="ov" role="dialog" aria-modal="true" aria-label="أنظمة جامعة المعقل الدولية">
  <div class="panel">
    <div class="hd"><div><b>أنظمة جامعة المعقل</b><small>انتقل بين الأنظمة بضغطة واحدة</small></div><button class="x" type="button" aria-label="إغلاق">×</button></div>
    <div class="grid">${SYSTEMS.map((s) => `
      <a class="it${current && current.id === s.id ? ' cur' : ''}" href="${s.url}"${newTab && !(current && current.id === s.id) ? ' target="_blank" rel="noopener"' : ''}${current && current.id === s.id ? ' aria-current="page"' : ''}>
        <span class="ii">${svg(s.icon, 18)}</span><b>${s.name}</b><small>${s.sub}</small>
      </a>`).join('')}
    </div>
    <div class="ft">جامعة المعقل الدولية · قسم هندسة الأمن السيبراني</div>
  </div>
</div>`;
    document.body.appendChild(host);
    const fab = root.querySelector('.fab'), ov = root.querySelector('.ov');
    const open = (v) => { ov.classList.toggle('open', v); fab.setAttribute('aria-expanded', v ? 'true' : 'false'); if (v) { const a = root.querySelector('a.it'); a && a.focus({ preventScroll: true }); } };
    fab.addEventListener('click', () => open(!ov.classList.contains('open')));
    ov.addEventListener('click', (e) => { if (e.target === ov) open(false); });
    root.querySelector('.x').addEventListener('click', () => open(false));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') open(false); });
  }
  if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);
  window.AMU_NAV = { systems: SYSTEMS };
})();

/* جسر مركز إدارة البوابة (https://it-basra.com/raasid/admin/):
   يطبّق في هذه الصفحة ما يقرره المدير ونائبه: حالة التفرعات (ظاهر/يتطلب الدخول/صيانة/مخفي) لكل قسم،
   والإعلانات، وحظر الحسابات، وعدّاد الزيارات، ورابط «نسيت كلمة المرور؟» في نوافذ الدخول.
   إن تعذّر تحميله تعمل الصفحة كما هي. */
(function (d) {
  if (window.AMU_BRIDGE || d.querySelector('script[src*="amu-bridge.js"]')) return;
  var s = d.createElement('script');
  s.src = 'https://it-basra.com/app/brand/amu-bridge.js?v=' + new Date().toISOString().slice(0, 10);
  s.defer = true;
  (d.head || d.documentElement).appendChild(s);
})(document);
