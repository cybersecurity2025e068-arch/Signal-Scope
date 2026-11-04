/* جسر مركز إدارة البوابة: تطبّق صفحات بوابة القسم ومنظومة راصد الإشارة ما يقرره المدير ونائبه من /raasid/admin/
   - حالة كل تفرع: ظاهر · يتطلب الدخول · صيانة · مخفي (وإخفاؤه عن أقسام بعينها)
   - الإعلانات أعلى الصفحات لكل الطلبة أو لأقسام محددة
   - الحسابات المحظورة تُخرَج من البوابة
   - عدّاد زيارات مجهول الهوية لكل تفرع وقسم
   يُحمَّل من amu-nav.js في كل صفحات البوابة، ومباشرة في صفحات راصد الإشارة. لا يُعطّل الصفحة إن تعذّر الاتصال. */
(function () {
  "use strict";
  if (window.AMU_BRIDGE) return;
  var B = window.AMU_BRIDGE = { ready: false };
  var GH = /github\.io$/.test(location.hostname);
  var API = GH ? "https://it-basra.com/api/index.php?r=" : "/api/index.php?r=";
  var TOP = (function () { try { return window.self === window.top; } catch (e) { return false; } })();
  var HERE = location.hostname + location.pathname;
  var SYS = GH || /^\/amu\//.test(location.pathname) ? "amu" : "raasid";
  var STAT = { hidden: "مخفي", maintenance: "صيانة", locked: "يتطلب الدخول" };
  var cfg = null, priv = null, me = null;

  function dept() { try { var d = JSON.parse(localStorage.getItem("amu_dept") || "null"); return (d && d.id) || ""; } catch (e) { return ""; } }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function sha256(s) {
    if (!window.crypto || !crypto.subtle) return Promise.resolve("");
    return crypto.subtle.digest("SHA-256", new TextEncoder().encode(String(s || "").trim().toLowerCase())).then(function (b) {
      return Array.prototype.map.call(new Uint8Array(b), function (x) { return ("0" + x.toString(16)).slice(-2); }).join("");
    });
  }
  function effective(s) { return s.depts_off && s.depts_off.indexOf(dept()) >= 0 ? "hidden" : s.status; }
  function current() {
    if (!cfg) return null;
    for (var i = 0; i < cfg.sections.length; i++) {
      var s = cfg.sections[i];
      if (!s.match) continue;
      try { if (new RegExp(s.match).test(HERE)) return s; } catch (e) { }
    }
    return null;
  }
  function norm(u) {
    try { var x = new URL(u, location.href); return x.hostname + x.pathname.replace(/index\.html$/, "") + x.hash; } catch (e) { return ""; }
  }

  // ------------------------------------------------------------------ من يتصفح؟ (المدير ونائبه يرون كل شيء)
  function whoAmI() {
    if (priv !== null) return Promise.resolve(priv);
    if (window.AMU_AUTH && AMU_AUTH.state) {
      var u = AMU_AUTH.state.user;
      return sha256(u && u.email).then(function (h) { priv = !!(h && cfg.priv.indexOf(h) >= 0); return priv; });
    }
    if (GH) return Promise.resolve(priv = false);
    return fetch(API + "/v1/portal/me", { credentials: "same-origin" }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { me = j && j.me; priv = !!(me && (me.role === "owner" || me.role === "deputy")); return priv; })
      .catch(function () { return (priv = false); });
  }

  // ------------------------------------------------------------------ التنسيق
  function css() {
    if (document.getElementById("amu-bridge-css")) return;
    var st = document.createElement("style"); st.id = "amu-bridge-css";
    st.textContent =
      ".amub-news{position:relative;z-index:40;direction:rtl;font:600 14px/1.6 Cairo,'IBM Plex Sans Arabic',sans-serif;display:flex;gap:10px;align-items:flex-start;padding:9px 14px;color:#fff}" +
      ".amub-news.info{background:linear-gradient(90deg,#0369a1,#0284c7)}.amub-news.warn{background:linear-gradient(90deg,#b45309,#d97706)}.amub-news.urgent{background:linear-gradient(90deg,#991b1b,#dc2626)}" +
      ".amub-news b{font-weight:900}.amub-news p{margin:0;flex:1}.amub-news small{opacity:.9;font-weight:600}.amub-news button{background:rgba(255,255,255,.2);border:0;color:#fff;border-radius:8px;padding:2px 10px;cursor:pointer;font:inherit}" +
      ".amub-ov{position:fixed;inset:0;z-index:99990;display:grid;place-items:center;padding:16px;background:rgba(2,4,10,.72);backdrop-filter:blur(6px);direction:rtl;font-family:Cairo,'IBM Plex Sans Arabic',sans-serif}" +
      ".amub-card{max-width:440px;width:100%;text-align:center;padding:26px 22px;border-radius:22px;background:#0D1424;color:#F8FAFC;border:1.5px solid rgba(245,158,11,.55);box-shadow:0 24px 60px rgba(0,0,0,.5)}" +
      ".amub-card img{width:78px;height:78px;border-radius:50%;border:2px solid #FBBF24;background:#060A17;padding:2px}.amub-card h2{margin:12px 0 6px;font:900 20px 'Readex Pro',Cairo,sans-serif}.amub-card p{margin:0 0 16px;color:#cbd5e1}" +
      ".amub-card a,.amub-card button{display:inline-block;margin:4px;padding:8px 16px;border-radius:12px;border:0;cursor:pointer;text-decoration:none;font:800 14px Cairo,sans-serif;background:linear-gradient(135deg,#F59E0B,#B45309);color:#fff}" +
      ".amub-card .soft{background:rgba(245,158,11,.18);color:#FDE68A;border:1px solid rgba(245,158,11,.45)}" +
      ".amub-adm{position:fixed;bottom:12px;inset-inline-start:12px;z-index:99980;direction:rtl;font:700 12px Cairo,sans-serif;background:#0D1424;color:#FDE68A;border:1px solid #F59E0B;border-radius:12px;padding:7px 12px;box-shadow:0 8px 24px rgba(0,0,0,.35)}" +
      ".amub-adm a{color:#FBBF24}.amub-mnt{border:1.5px dashed #F59E0B;border-radius:14px;padding:14px;margin:8px 0;background:rgba(245,158,11,.1);font:700 14px Cairo,sans-serif;direction:rtl}" +
      "[data-amub-hidden]{display:none!important}[data-amub-mnt]>*:not(.amub-mnt){display:none!important}" +
      ".amub-flag{outline:2px dashed #F59E0B!important;outline-offset:3px}";
    document.head.appendChild(st);
  }

  function overlay(title, msg, actions) {
    var old = document.querySelector(".amub-ov"); if (old) old.remove();
    var crest = (GH ? "https://it-basra.com" : "") + "/app/brand/crest.svg";
    var home = SYS === "amu" ? (GH ? "https://it-basra.com/amu/" : "/amu/") : "/raasid/";
    var d = document.createElement("div"); d.className = "amub-ov"; d.setAttribute("role", "alertdialog"); d.setAttribute("aria-modal", "true");
    d.innerHTML = '<div class="amub-card"><img src="' + crest + '" alt="شعار جامعة المعقل الدولية"><h2>' + esc(title) + "</h2><p>" + esc(msg) + "</p>" + (actions || "") +
      '<a class="soft" href="' + home + '">العودة إلى البوابة</a></div>';
    document.body.appendChild(d);
    if (TOP) document.documentElement.style.overflow = "hidden";
    return d;
  }
  function clearOverlay() { var o = document.querySelector(".amub-ov"); if (o) { o.remove(); document.documentElement.style.overflow = ""; } }
  function adminNote(s, st) {
    if (!TOP || document.querySelector(".amub-adm")) return;
    var n = document.createElement("div"); n.className = "amub-adm";
    n.innerHTML = "تفرع «" + esc(s.name) + "» حالته للزوار: <b>" + STAT[st] + "</b>. تراه لأنك مدير · <a href=\"" + (GH ? "https://it-basra.com" : "") + '/raasid/admin/#/sections">تغيير</a>';
    document.body.appendChild(n);
  }

  // ------------------------------------------------------------------ التطبيق
  function applyPage() {
    var s = current(); if (!s) return;
    var st = effective(s);
    if (st === "visible") { clearOverlay(); return; }
    whoAmI().then(function (isPriv) {
      if (isPriv) { clearOverlay(); adminNote(s, st); return; }
      if (st === "hidden") { overlay("هذا القسم غير متاح", s.message || "«" + s.name + "» غير متاح حالياً."); return; }
      if (st === "maintenance") { overlay("قيد الصيانة", s.message || "«" + s.name + "» متوقف مؤقتاً للصيانة والتحديث. عُد إليه لاحقاً."); return; }
      if (st === "locked") {
        if (window.AMU_AUTH && AMU_AUTH.state) {
          var a = AMU_AUTH.state;
          if (a.status === "in") { clearOverlay(); return; }
          if (a.status === "loading") return;
          var o = overlay("يتطلب تسجيل الدخول", s.message || "سجّل الدخول بحساب البوابة لفتح «" + s.name + "».", '<button type="button" data-amub-in>تسجيل الدخول</button>');
          var b = o.querySelector("[data-amub-in]"); if (b) b.onclick = function () { AMU_AUTH.signIn(); };
          return;
        }
        if (me) { clearOverlay(); return; }
        overlay("يتطلب تسجيل الدخول", s.message || "«" + s.name + "» متاح لحسابات الإدارة فقط.", '<a href="/raasid/admin/">الدخول من مركز الإدارة</a>');
      }
    });
  }

  function applyElements() {
    if (!cfg) return;
    whoAmI().then(function (isPriv) {
      var here = norm(location.href).replace(/#.*$/, "");
      cfg.sections.forEach(function (s) {
        var st = effective(s);
        // أقسام داخل الصفحة نفسها (مثل #lib في الصفحة الرئيسية)
        if (s.selector) {
          var el = document.querySelector(s.selector);
          if (el) {
            el.removeAttribute("data-amub-hidden"); el.removeAttribute("data-amub-mnt"); el.classList.remove("amub-flag");
            var m = el.querySelector(":scope > .amub-mnt"); if (m) m.remove();
            if (st !== "visible" && isPriv) el.classList.add("amub-flag");
            else if (st === "hidden") el.setAttribute("data-amub-hidden", "");
            else if (st === "maintenance") {
              el.setAttribute("data-amub-mnt", "");
              var box = document.createElement("div"); box.className = "amub-mnt"; box.textContent = "«" + s.name + "»: " + (s.message || "قيد الصيانة مؤقتاً.");
              el.insertBefore(box, el.firstChild);
            }
          }
        }
        // تبويبات منظومة راصد الإشارة
        Array.prototype.forEach.call(document.querySelectorAll('[data-section="' + s.id + '"]'), function (t) {
          t.toggleAttribute("data-amub-hidden", st === "hidden" && !isPriv);
          t.classList.toggle("amub-flag", st !== "visible" && isPriv);
          t.title = st === "visible" ? "" : STAT[st] + (s.message ? ": " + s.message : "");
        });
        // روابط التفرعات المخفية في أي صفحة (عدا الصفحات الرئيسية نفسها)
        if (st === "hidden" && !isPriv && !/\/(amu|raasid)\/$/.test(norm(s.url))) {
          var target = norm(s.url);
          Array.prototype.forEach.call(document.querySelectorAll("a[href]"), function (a) {
            var h = norm(a.href);
            if (h && h.replace(/#.*$/, "") !== here && (h === target || (target.indexOf("#") < 0 && h.indexOf(target) === 0))) {
              var card = a.closest("li, article") || a; card.setAttribute("data-amub-hidden", "");
            }
          });
        }
      });
    });
  }

  function applyNews() {
    if (!TOP || !cfg || !document.body) return;
    Array.prototype.forEach.call(document.querySelectorAll(".amub-news"), function (n) { n.remove(); });
    var seen = {}; try { seen = JSON.parse(localStorage.getItem("amu_news_seen") || "{}"); } catch (e) { }
    var d = dept();
    var list = cfg.news.filter(function (n) {
      return (n.systems === "all" || n.systems === SYS) && (!n.depts || n.depts.indexOf(d) >= 0) && (n.level === "urgent" || !seen[n.id]);
    });
    if (!list.length) return;
    var n = list[0];
    var bar = document.createElement("div"); bar.className = "amub-news " + n.level; bar.setAttribute("role", n.level === "urgent" ? "alert" : "status");
    bar.innerHTML = "<p><b>" + esc(n.title) + "</b>" + (n.body ? " — " + esc(n.body) : "") + (list.length > 1 ? " <small>(+" + (list.length - 1) + " إعلان)</small>" : "") + "</p>" +
      (n.level === "urgent" ? "" : '<button type="button" aria-label="إغلاق الإعلان">' + (list.length > 1 ? "التالي" : "إغلاق") + "</button>");
    var btn = bar.querySelector("button");
    if (btn) btn.onclick = function () { seen[n.id] = 1; try { localStorage.setItem("amu_news_seen", JSON.stringify(seen)); } catch (e) { } applyNews(); };
    document.body.insertBefore(bar, document.body.firstChild);
  }

  function checkBlocked() {
    if (!cfg || !cfg.blocked.length || !window.AMU_AUTH || !AMU_AUTH.state || !AMU_AUTH.state.user) return;
    sha256(AMU_AUTH.state.user.email).then(function (h) {
      if (h && cfg.blocked.indexOf(h) >= 0) { AMU_AUTH.signOut(); overlay("الحساب محظور", "هذا الحساب ممنوع من دخول البوابة. راجع إدارة القسم."); }
    });
  }

  function hit() {
    var s = current(); if (!s) return;
    var body = JSON.stringify({ s: s.id, d: dept() });
    try {
      if (navigator.sendBeacon) navigator.sendBeacon(API + "/v1/portal/hit", new Blob([body], { type: "text/plain" }));
      else fetch(API + "/v1/portal/hit", { method: "POST", body: body, keepalive: true, mode: GH ? "cors" : "same-origin" });
    } catch (e) { }
  }

  function applyAll() { css(); applyPage(); applyElements(); applyNews(); checkBlocked(); }

  function start() {
    fetch(API + "/v1/portal/config", { cache: "no-cache", mode: GH ? "cors" : "same-origin" }).then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(function (j) {
      cfg = j; B.ready = true; B.config = j;
      applyAll(); hit();
      setTimeout(applyElements, 1500);   // عناصر ترسمها الصفحة لاحقاً
      window.addEventListener("amu-dept", function () { applyAll(); });
      if (window.AMU_AUTH && AMU_AUTH.onChange) AMU_AUTH.onChange(function () { priv = null; applyPage(); applyElements(); checkBlocked(); });
    }).catch(function () { /* تعذّر الاتصال: الصفحة تعمل كما هي */ });
  }
  B.refresh = function () { priv = null; start(); };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start); else start();
})();
