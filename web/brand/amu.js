/* سمة مشتركة بين واجهات راصد الإشارة الثلاث والبوابة الموحدة.
   - تُحمَّل في <head> قبل الرسم، فتضبط data-theme فوراً (لا وميض).
   - المفتاح المشترك: localStorage["amu-theme"] = "light" | "dark" (الافتراضي نهاري كالبوابة).
   - أي تغيير في تبويب أو إطار يصل للبقية عبر حدث storage ورسائل postMessage.
   - كل واجهة تستمع لحدث "amu-theme" لتعيد رسم ما يلزم. */
(function () {
  "use strict";
  var KEY = "amu-theme", root = document.documentElement;
  function read() { try { var t = localStorage.getItem(KEY); return t === "dark" || t === "light" ? t : "light"; } catch (e) { return "light"; } }
  function apply(t, silent) {
    root.setAttribute("data-theme", t);
    var m = document.querySelector('meta[name="theme-color"]');
    if (m) m.content = t === "dark" ? "#060A17" : "#FEF8E7";
    if (!silent) { try { window.dispatchEvent(new CustomEvent("amu-theme", { detail: t })); } catch (e) {} }
  }
  function set(t) {
    if (t !== "dark" && t !== "light") return;
    try { localStorage.setItem(KEY, t); } catch (e) {}
    apply(t);
    // إبلاغ الإطارات الأبناء والأب (البوابة الموحدة)
    try { for (var i = 0; i < window.frames.length; i++) window.frames[i].postMessage({ type: "amu-theme", theme: t }, location.origin); } catch (e) {}
    try { if (window.parent !== window) window.parent.postMessage({ type: "amu-theme", theme: t }, location.origin); } catch (e) {}
  }
  var embedded = false;
  try { embedded = window.self !== window.top; } catch (e) { embedded = true; }
  if (embedded) root.classList.add("amu-embed");
  apply(read(), true);
  window.addEventListener("storage", function (e) { if (e.key === KEY && e.newValue) apply(e.newValue); });
  window.addEventListener("message", function (e) {
    if (e.origin !== location.origin || !e.data || e.data.type !== "amu-theme") return;
    if (root.getAttribute("data-theme") !== e.data.theme) apply(e.data.theme);
  });
  window.AMU = { get: function () { return root.getAttribute("data-theme") || "light"; }, set: set, toggle: function () { set(this.get() === "dark" ? "light" : "dark"); }, embedded: embedded };
})();
