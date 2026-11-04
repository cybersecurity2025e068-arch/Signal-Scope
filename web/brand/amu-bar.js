/* شريط الصفحات الفرعية لمنظومة راصد الإشارة، مطابق لتفرعات بوابة القسم (it-basra.com/amu):
   الشعار واسم الصفحة · «البوابة» للرجوع · «المنظومة» · «تغيير القسم» · الوضع الليلي.
   - اختيار القسم مشترك مع البوابة نفسها (localStorage "amu_dept" وقائمة colleges.json).
   - داخل البوابة الموحدة (iframe) لا يُرسم الشريط، لأن البوابة تعرض الأزرار نفسها فوق كل التبويبات،
     وروابط [data-amu-tab] تبدّل تبويب البوابة بدل فتح صفحة داخل الإطار.
   الاستخدام: <script src="brand/amu-bar.js" data-title="اسم الصفحة" [data-mode="portal"]></script> */
(function () {
  "use strict";
  var me = document.currentScript || document.querySelector('script[src*="amu-bar.js"]');
  var base = me ? me.src.replace(/amu-bar\.js.*$/, "") : "brand/";
  var title = (me && me.dataset.title) || document.title;
  var mode = (me && me.dataset.mode) || "page";
  var onSite = /(^|\.)it-basra\.com$/.test(location.hostname);
  var PORTAL = onSite ? "/amu/" : "https://it-basra.com/amu/";
  var SYSTEM = onSite ? "/raasid/" : "https://it-basra.com/raasid/";
  var KEY = "amu_dept";
  var DEF = { id: "", name: "هندسة الأمن السيبراني", cid: "", cname: "كلية الهندسة", def: 1 };
  var embedded = false;
  try { embedded = window.self !== window.top; } catch (e) { embedded = true; }
  var esc = function (s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); };
  var strip = function (s) { return String(s || "").replace(/^قسم\s+/, ""); };

  function cur() { try { return JSON.parse(localStorage.getItem(KEY) || "null") || DEF; } catch (e) { return DEF; } }
  function label(d) { d = d || cur(); return (d.cname ? d.cname + " · " : "") + "قسم " + strip(d.name); }
  var LIST = null;
  function load() {
    if (LIST) return Promise.resolve(LIST);
    var urls = onSite ? ["/amu/colleges.json", base + "colleges.json"] : [base + "colleges.json"];
    var tryAt = function (i) {
      return fetch(urls[i], { cache: "no-cache" }).then(function (r) { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); })
        .then(function (j) { LIST = j.colleges || []; return LIST; })
        .catch(function (e) { if (i + 1 < urls.length) return tryAt(i + 1); throw e; });
    };
    return tryAt(0);
  }
  function paintAll() {
    var t = label();
    Array.prototype.forEach.call(document.querySelectorAll("[data-amu-dept-label]"), function (el) { el.textContent = t; });
  }

  // ---------- نافذة اختيار الكلية والقسم (مثل البوابة) ----------
  var dlg = null;
  function ensureDlg() {
    if (dlg) return dlg;
    dlg = document.createElement("div");
    dlg.className = "amu-dept-dlg"; dlg.setAttribute("role", "dialog"); dlg.setAttribute("aria-modal", "true"); dlg.setAttribute("aria-label", "اختيار الكلية والقسم");
    dlg.innerHTML = '<div class="amu-dept-card"><h3>اختر كليتك وقسمك</h3><p>يُحفظ اختيارك على هذا الجهاز، ويُستخدم في بوابة القسم ومنظومة راصد الإشارة معاً.</p><div class="amu-dept-list">جارٍ التحميل…</div><button type="button" class="amu-sbtn amu-dept-close">إغلاق</button></div>';
    dlg.addEventListener("click", function (e) {
      if (e.target === dlg || e.target.closest(".amu-dept-close")) { dlg.classList.remove("open"); return; }
      var b = e.target.closest("button[data-id]"); if (!b || !LIST) return;
      var c = LIST.find(function (x) { return x.id === b.dataset.c; }), d = c && (c.depts || []).find(function (x) { return x.id === b.dataset.id; });
      if (!d) return;
      try { localStorage.setItem(KEY, JSON.stringify({ id: d.id, name: d.name, cid: c.id, cname: c.name })); } catch (x) {}
      paintAll(); dlg.classList.remove("open");
      try { window.dispatchEvent(new Event("amu-dept")); } catch (x) {}
    });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && dlg) dlg.classList.remove("open"); });
    document.body.appendChild(dlg);
    return dlg;
  }
  function openDept() {
    var d = ensureDlg(), box = d.querySelector(".amu-dept-list"), now = cur();
    d.classList.add("open");
    load().then(function (L) {
      box.innerHTML = L.map(function (c) {
        return '<div class="amu-dept-col"><b>' + esc(c.name) + "</b><div>" + (c.depts || []).map(function (x) {
          var on = x.id === now.id || (!now.id && strip(x.name) === strip(now.name));
          return '<button type="button" data-id="' + esc(x.id) + '" data-c="' + esc(c.id) + '" class="' + (on ? "on" : "") + '">' + esc(strip(x.name)) + "</button>";
        }).join("") + "</div></div>";
      }).join("");
    }).catch(function () { box.textContent = "تعذّر تحميل قائمة الكليات. حاول لاحقاً."; });
  }

  // ---------- الشريط ----------
  var ICO = {
    back: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>',
    grid: '<path stroke-linecap="round" stroke-linejoin="round" d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
    moon: '<path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>'
  };
  var svg = function (k) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' + ICO[k] + "</svg>"; };
  function bar() {
    var el = document.createElement("div");
    el.className = "amu-sub"; el.setAttribute("role", "navigation"); el.setAttribute("aria-label", "التنقل في منظومة جامعة المعقل");
    el.innerHTML =
      '<a class="amu-sub-brand" href="' + SYSTEM + '" title="منظومة راصد الإشارة"><span class="amu-crest"><img src="' + base + 'crest.svg" alt="شعار جامعة المعقل الدولية"></span>' +
      "<span><b>" + esc(title) + "</b><small>جامعة المعقل الدولية · منظومة راصد الإشارة</small></span></a>" +
      '<button type="button" class="amu-sub-dept" title="تغيير القسم"><span class="d"></span><span data-amu-dept-label></span><em>تغيير القسم ▾</em></button>' +
      '<span class="amu-sub-acts">' +
      '<a class="amu-sbtn" href="' + PORTAL + '" title="العودة إلى بوابة القسم">' + svg("back") + '<span>البوابة</span></a>' +
      '<a class="amu-sbtn" href="' + SYSTEM + '" title="منظومة راصد الإشارة بتبويباتها الأربعة">' + svg("grid") + '<span>المنظومة</span></a>' +
      '<button type="button" class="amu-sbtn amu-sub-theme" title="الوضع الليلي/النهاري"></button></span>';
    el.querySelector(".amu-sub-dept").addEventListener("click", openDept);
    var th = el.querySelector(".amu-sub-theme");
    var paintTheme = function () { var d = window.AMU && AMU.get() === "dark"; th.innerHTML = svg(d ? "sun" : "moon") + "<span>" + (d ? "الوضع النهاري" : "الوضع الليلي") + "</span>"; };
    th.addEventListener("click", function () { if (window.AMU) AMU.toggle(); paintTheme(); });
    window.addEventListener("amu-theme", paintTheme); paintTheme();
    document.body.insertBefore(el, document.body.firstChild);
    var setH = function () { document.documentElement.style.setProperty("--amu-sub-h", el.offsetHeight + "px"); };
    setH(); window.addEventListener("resize", setH);
    if (window.ResizeObserver) new ResizeObserver(setH).observe(el);
  }

  // روابط تبويبات المنظومة: داخل البوابة تبدّل التبويب، وخارجها تفتح الصفحة
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest("a[data-amu-tab]");
    if (!a || !embedded) return;
    e.preventDefault();
    try { window.parent.postMessage({ type: "amu-tab", tab: a.dataset.amuTab }, location.origin); } catch (x) {}
  });
  window.addEventListener("storage", function (e) { if (e.key === KEY) { paintAll(); try { window.dispatchEvent(new Event("amu-dept")); } catch (x) {} } });

  window.AMU_DEPT = { get: cur, label: label, open: openDept, paint: paintAll, portal: PORTAL, system: SYSTEM };
  function init() { if (mode === "page" && !embedded) bar(); paintAll(); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
})();
