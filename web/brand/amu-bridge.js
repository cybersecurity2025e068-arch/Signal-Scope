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
      ".amub-card{max-width:440px;width:100%;max-height:calc(100vh - 32px);overflow:auto;box-sizing:border-box;text-align:center;padding:26px 22px;border-radius:22px;background:#0D1424;color:#F8FAFC;border:1.5px solid rgba(245,158,11,.55);box-shadow:0 24px 60px rgba(0,0,0,.5)}" +
      ".amub-card img{width:78px;height:78px;border-radius:50%;border:2px solid #FBBF24;background:#060A17;padding:2px}.amub-card h2{margin:12px 0 6px;font:900 20px 'Readex Pro',Cairo,sans-serif}.amub-card p{margin:0 0 16px;color:#cbd5e1}" +
      ".amub-card a,.amub-card button{display:inline-block;margin:4px;padding:8px 16px;border-radius:12px;border:0;cursor:pointer;text-decoration:none;font:800 14px Cairo,sans-serif;background:linear-gradient(135deg,#F59E0B,#B45309);color:#fff}" +
      ".amub-card .soft{background:rgba(245,158,11,.18);color:#FDE68A;border:1px solid rgba(245,158,11,.45)}" +
      ".amub-adm{position:fixed;bottom:12px;inset-inline-start:12px;z-index:99980;direction:rtl;font:700 12px Cairo,sans-serif;background:#0D1424;color:#FDE68A;border:1px solid #F59E0B;border-radius:12px;padding:7px 12px;box-shadow:0 8px 24px rgba(0,0,0,.35)}" +
      ".amub-adm a{color:#FBBF24}.amub-mnt{border:1.5px dashed #F59E0B;border-radius:14px;padding:14px;margin:8px 0;background:rgba(245,158,11,.1);font:700 14px Cairo,sans-serif;direction:rtl}" +
      "[data-amub-hidden]{display:none!important}[data-amub-mnt]>*:not(.amub-mnt){display:none!important}" +
      ".amub-flag{outline:2px dashed #F59E0B!important;outline-offset:3px}" +
      ".amub-forgot{display:block;width:100%;margin-top:8px;background:none;border:0;color:#D97706;font:800 13px Cairo,sans-serif;cursor:pointer;text-decoration:underline;text-underline-offset:3px}" +
      ".amub-rc{text-align:right}.amub-rc h2{text-align:center}.amub-tabs{display:flex;gap:6px;margin:4px 0 12px}.amub-tabs button{flex:1;margin:0;background:rgba(245,158,11,.14);color:#FDE68A;border:1px solid rgba(245,158,11,.4)}" +
      ".amub-tabs button[aria-pressed=true]{background:linear-gradient(135deg,#F59E0B,#B45309);color:#fff}.amub-rc label{display:block;font:700 13px Cairo,sans-serif;color:#cbd5e1;margin:8px 0 3px}" +
      ".amub-rc input,.amub-rc textarea{width:100%;box-sizing:border-box;padding:9px 11px;border-radius:10px;border:1px solid rgba(245,158,11,.45);background:#060A17;color:#F8FAFC;font:600 14px Cairo,sans-serif}" +
      ".amub-rc p{margin:6px 0 10px;line-height:1.8;text-align:right}.amub-rc .ok{background:rgba(16,185,129,.15);border:1px solid #10B981;color:#A7F3D0;border-radius:10px;padding:8px 10px}" +
      ".amub-rc .bad{background:rgba(244,63,94,.15);border:1px solid #F43F5E;color:#FECDD3;border-radius:10px;padding:8px 10px}.amub-rc .row{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:10px}" +
      ".amub-rc ol{margin:0 0 8px;padding-inline-start:20px;line-height:1.9;color:#cbd5e1;font-size:14px}";
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

  // ------------------------------------------------------------------ نسيت كلمة المرور؟
  // حسابات الأساتذة (اسم مستخدم) بلا بريد حقيقي: لا يمكن إرسال رابط استرداد آلي، فيصل الطلب إلى المدير
  // في «طلبات الدعم الفني» بلوحة مدير البوابة، فيعيد تعيين كلمة مؤقتة تُغيَّر إجبارياً عند أول دخول.
  // حسابات Google (البريد الجامعي و Gmail): الاسترداد من Google نفسها.
  var REQ = GH || !/(^|\.)it-basra\.com$/.test(location.hostname) ? "https://it-basra.com/amu/requests.php" : "/amu/requests.php";
  function support() {
    var s = (window.AMU_ACCESS && AMU_ACCESS.support) || {};
    return { phone: s.phone || "+9647730477667", emails: s.emails || ["it.basra@gmail.com"] };
  }
  function recover(opt) {
    opt = opt || {};
    css();
    var sp = support(), wa = sp.phone.replace(/\D/g, "");
    var d = document.createElement("div"); d.className = "amub-ov"; d.setAttribute("role", "dialog"); d.setAttribute("aria-modal", "true"); d.setAttribute("aria-label", "استرداد كلمة المرور");
    d.innerHTML = '<div class="amub-card amub-rc"><h2>استرداد كلمة المرور</h2>' +
      '<div class="amub-tabs"><button type="button" data-t="staff">اسم مستخدم (أستاذ)</button><button type="button" data-t="google">بريد جامعي أو Gmail</button></div>' +
      '<div data-p="staff"><p>كلمات مرور الأساتذة لا ترتبط ببريد، لذلك يعيد المدير تعيينها: أرسل الطلب، فيُسلَّم لك كلمة مرور مؤقتة، وتختار كلمة جديدة عند أول دخول.</p>' +
      '<form><label>اسم المستخدم</label><input name="u" dir="ltr" placeholder="doc01" required autocomplete="username">' +
      '<label>الاسم الكامل</label><input name="n" required autocomplete="name">' +
      '<label>رقم الهاتف أو واتساب للتواصل</label><input name="c" dir="ltr" required inputmode="tel" placeholder="07XXXXXXXXX" autocomplete="tel">' +
      '<label>ملاحظة (اختيارية)</label><input name="m">' +
      '<input name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">' +
      '<div class="row"><button type="submit">إرسال طلب الاسترداد</button></div></form><div data-msg></div>' +
      '<p style="margin-top:12px">أو تواصل مباشرة مع الدعم الفني:</p><div class="row">' +
      '<a class="soft" target="_blank" rel="noopener" href="https://wa.me/' + wa + "?text=" + encodeURIComponent("نسيت كلمة مرور حسابي في بوابة جامعة المعقل. اسم المستخدم: ") + '">واتساب</a>' +
      '<a class="soft" href="tel:' + esc(sp.phone) + '">اتصال</a></div></div>' +
      '<div data-p="google" hidden><ol><li>افتح صفحة استرداد حساب Google، وأدخل بريدك.</li><li>اتبع الخطوات: رمز إلى هاتفك أو بريدك الاحتياطي.</li><li>عد إلى البوابة وادخل بزر Google.</li></ol>' +
      '<div class="row"><a target="_blank" rel="noopener" href="https://accounts.google.com/signin/recovery">استرداد حساب Google</a></div>' +
      '<p>البريد الجامعي (<bdi dir="ltr">@almaaqal.edu.iq</bdi>) تديره وحدة تقنية المعلومات في الجامعة: إن لم يكن له هاتف احتياطي فراجعها، أو راسل الدعم الفني.</p>' +
      '<div class="row"><a class="soft" target="_blank" rel="noopener" href="https://wa.me/' + wa + '">واتساب الدعم</a><a class="soft" href="mailto:' + esc(sp.emails[0]) + '?subject=' + encodeURIComponent("استرداد حساب البوابة") + '">بريد الدعم</a></div></div>' +
      '<div class="row"><button type="button" class="soft" data-close>إغلاق</button></div></div>';
    document.body.appendChild(d);
    var tab = function (t) {
      Array.prototype.forEach.call(d.querySelectorAll("[data-t]"), function (b) { b.setAttribute("aria-pressed", b.dataset.t === t ? "true" : "false"); });
      Array.prototype.forEach.call(d.querySelectorAll("[data-p]"), function (p) { p.hidden = p.dataset.p !== t; });
    };
    tab(opt.google ? "google" : "staff");
    var close = function () { d.remove(); document.removeEventListener("keydown", onKey); };
    var onKey = function (e) { if (e.key === "Escape") close(); };
    document.addEventListener("keydown", onKey);
    d.addEventListener("click", function (e) {
      if (e.target === d || e.target.closest("[data-close]")) return close();
      var t = e.target.closest("[data-t]"); if (t) tab(t.dataset.t);
    });
    var f = d.querySelector("form"), msg = d.querySelector("[data-msg]");
    if (opt.user) f.u.value = opt.user;
    var say = function (t, ok) { msg.innerHTML = '<p class="' + (ok ? "ok" : "bad") + '">' + esc(t) + "</p>"; };
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      var u = f.u.value.trim().toLowerCase().split("@")[0], n = f.n.value.trim(), c = f.c.value.trim();
      if (!/^[a-z0-9._-]{2,40}$/.test(u)) return say("اكتب اسم المستخدم كما سُلِّم لك (مثل doc01).");
      if (n.length < 3 || c.replace(/\D/g, "").length < 7) return say("اكتب اسمك ورقم هاتف صحيحاً حتى يتواصل معك المدير.");
      var b = f.querySelector("button[type=submit]"); b.disabled = true;
      var body = new URLSearchParams({ action: "create", type: "support", name: n, contact: c, website: f.website.value, page: location.href,
        msg: "[استرداد كلمة المرور] اسم المستخدم: " + u + " — يطلب إعادة تعيين كلمة المرور." + (f.m.value.trim() ? " ملاحظة: " + f.m.value.trim() : "") });
      fetch(REQ, { method: "POST", body: body }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || !j.id) throw new Error(j.error || "تعذّر الإرسال"); return j; }); })
        .then(function (j) { f.hidden = true; say("✓ وصل طلبك برقم " + j.id + ". سيتواصل معك المدير على " + c + " بكلمة مرور مؤقتة، وتختار كلمة جديدة عند أول دخول.", true); })
        .catch(function (x) { say(x.message + ". تواصل مع الدعم عبر واتساب أو الاتصال بالأسفل."); })
        .then(function () { b.disabled = false; });
    });
    setTimeout(function () { (opt.google ? d.querySelector("[data-t=google]") : f.u).focus(); }, 50);
    return d;
  }
  B.recover = recover;

  // رابط «نسيت كلمة المرور؟» تحت نماذج الدخول وتغيير كلمة المرور في نوافذ البوابة
  function addForgot(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('form input[type="password"][autocomplete="current-password"]'), function (inp) {
      var f = inp.form; if (!f || f.querySelector(".amub-forgot") || f.hasAttribute("data-no-forgot")) return;
      css();
      var b = document.createElement("button"); b.type = "button"; b.className = "amub-forgot"; b.textContent = "نسيت كلمة المرور؟";
      b.onclick = function () {
        var u = f.querySelector('input[autocomplete="username"]');
        var st = window.AMU_AUTH && AMU_AUTH.state;
        recover({ user: (u && u.value) || (st && st.username) || "" });
      };
      f.appendChild(b);
    });
  }
  function watchForms() {
    addForgot();
    if (!window.MutationObserver || !document.body) return;
    var t = null;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(addForgot, 120); }).observe(document.body, { childList: true, subtree: true });
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
  function boot() { watchForms(); start(); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot); else boot();
})();
