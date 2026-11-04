/* =====================================================================
   SignalScope UI — data sources, routing, rendering.
   Data sources:  android (window.SignalBridge, injected by the app)
                  browser (Network Information API + speed test only)
                  demo    (SSEngine.DemoSource scenarios)
   ===================================================================== */
(function () {
  "use strict";
  const E = window.SSEngine;
  const $ = id => document.getElementById(id);
  const isNum = E.isNum, clamp = E.clamp;
  const fmt = (v, d = 1) => (isNum(v) ? v.toFixed(d) : "—");
  const esc = s => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const store = {
    get(k, d) { try { const v = localStorage.getItem("ss." + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem("ss." + k, JSON.stringify(v)); return true; } catch (e) { return false; } },
    del(k) { try { localStorage.removeItem("ss." + k); } catch (e) {} }
  };
  const Bridge = window.SignalBridge || null;
  const NATIVE = !!(Bridge && typeof Bridge.getSnapshot === "function");
  const callB = (fn, ...a) => { try { return Bridge && typeof Bridge[fn] === "function" ? Bridge[fn](...a) : null; } catch (e) { console.warn("bridge", fn, e); return null; } };
  const parseJ = s => { try { return typeof s === "string" ? JSON.parse(s) : s; } catch (e) { return null; } };

  /* ------------------------------------------------------------- state */
  const DEFAULT_OBS = [{ m: "concrete", n: 1, t: 20 }, { m: "glass", n: 1, t: 0.6 }, { m: "plaster", n: 2, t: 2.6 }];
  const ST = {
    source: NATIVE ? "android" : (store.get("source", "demo") === "android" ? "demo" : store.get("source", "demo")),
    scenario: store.get("scenario", "indoorEdge"),
    interval: store.get("interval", 1000),
    alert: store.get("alert", -110),
    theme: window.AMU ? AMU.get() : store.get("theme", "auto"),
    hist: [], last: null, findings: [], demo: null,
    recording: false, session: null, logView: null,
    speed: store.get("speedHist", []), speedRunning: false,
    wifiBand: "2.4", io: { out: null, in: null }, manual: null,
    obstacles: store.get("obstacles", DEFAULT_OBS),
    view: "live", openFindings: new Set(["__first"]), lastDiagRender: 0, lastAlert: 0,
    permissions: null, device: null, monitoring: false
  };

  /* ------------------------------------------------------------- icons */
  const IC = {
    live: '<path d="M3 12h4l2.5-6 4 12 2.5-6H21"/>',
    diagnose: '<path d="M7 3v5a4 4 0 0 0 8 0V3"/><path d="M11 12v3a4 4 0 0 0 8 0v-1"/><circle cx="19" cy="12" r="2"/>',
    spectrum: '<path d="M3 20h18"/><path d="M5 16v-3M9 16V6M13 16V9M17 16V4M21 16v-2"/>',
    obstacles: '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 10h18M3 15h18M9 5v5M15 10v5M9 15v4"/>',
    speed: '<path d="M4 17a8 8 0 1 1 16 0"/><path d="M12 17l4.5-5"/>',
    log: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    tools: '<path d="M14.5 6.5l3-3 3 3-3 3"/><path d="M17.5 6.5L7 17l-3 1 1-3L15.5 4.5"/>',
    guide: '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h11"/>',
    prefs: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
    more: '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.4 1.4M17.6 17.6 19 19M5 19l1.4-1.4M17.6 6.4 19 5"/>',
    moon: '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    chev: '<path d="M6 9l6 6 6-6"/>',
    rec: '<circle cx="12" cy="12" r="6" fill="currentColor"/>',
    stop: '<rect x="7" y="7" width="10" height="10" rx="1.5" fill="currentColor"/>'
  };
  const svg = (k, sw = 1.7) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${IC[k]}</svg>`;

  const NAV = [
    { id: "live", ar: "الحالة الحية", short: "الحالة" },
    { id: "diagnose", ar: "التشخيص والحلول", short: "التشخيص" },
    { id: "spectrum", ar: "الطيف والترددات", short: "الطيف" },
    { id: "obstacles", ar: "العوائق والاختراق", short: "العوائق" },
    { id: "speed", ar: "اختبار السرعة", short: "السرعة" },
    { id: "log", ar: "السجل والتحليل", short: "السجل" },
    { id: "tools", ar: "أدوات المهندس", short: "الأدوات" },
    { id: "guide", ar: "الدليل", short: "الدليل" },
    { id: "prefs", ar: "الإعدادات", short: "الإعدادات" }
  ];
  const TAB = ["live", "diagnose", "speed", "log"];

  /* ------------------------------------------------------------- theme + palette */
  function applyTheme() {
    const r = document.documentElement;
    if (window.AMU) {   // سمة مشتركة مع البوابة الموحدة ولوحة الراصد وحوض الأمواج
      if (ST.theme === "auto") ST.theme = AMU.get();
      if (r.getAttribute("data-theme") !== ST.theme) AMU.set(ST.theme);
    } else if (ST.theme === "auto") r.removeAttribute("data-theme"); else r.setAttribute("data-theme", ST.theme);
    const dark = r.getAttribute("data-theme") === "dark" || (!r.getAttribute("data-theme") && matchMedia("(prefers-color-scheme: dark)").matches);
    $("themeBtn").innerHTML = svg(dark ? "sun" : "moon");
    const m = document.querySelector('meta[name="theme-color"]'); if (m) m.content = dark ? "#060A17" : "#FEF8E7";
    PAL = null; renderActive(true);
  }
  let PAL = null;
  function pal() {
    if (PAL) return PAL;
    const cs = getComputedStyle(document.documentElement), g = v => cs.getPropertyValue(v).trim();
    PAL = { bg: g("--surface2"), line: g("--line"), line2: g("--line2"), fg: g("--fg"), muted: g("--muted"), dim: g("--dim"), accent: g("--accent"), signal: g("--signal"),
      ex: g("--q-ex"), good: g("--q-good"), fair: g("--q-fair"), poor: g("--q-poor"), bad: g("--q-bad"), na: g("--q-na"), surface3: g("--surface3") };
    return PAL;
  }
  const qColor = k => pal()[k] || pal().na;

  /* ------------------------------------------------------------- canvas toolkit */
  function prep(cv) {
    const dpr = Math.min(window.devicePixelRatio || 1, 2), w = cv.clientWidth, h = cv.clientHeight;
    if (!w || !h) return null;
    if (cv.width !== Math.round(w * dpr) || cv.height !== Math.round(h * dpr)) { cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr); }
    const g = cv.getContext("2d"); g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, w, h); g.direction = "ltr";
    return { g, w, h };
  }
  function niceStep(span, n) { const raw = span / n, p = Math.pow(10, Math.floor(Math.log10(raw))), f = raw / p; return (f < 1.5 ? 1 : f < 3 ? 2 : f < 7 ? 5 : 10) * p; }
  function axes(c, o) {
    const P = pal(), { g, w, h } = c, L = o.L ?? 42, R = o.R ?? 12, T = o.T ?? 10, B = o.B ?? 24, pw = w - L - R, ph = h - T - B;
    const fx = o.logx ? v => L + (Math.log10(v) - Math.log10(o.x0)) / (Math.log10(o.x1) - Math.log10(o.x0)) * pw : v => L + (v - o.x0) / (o.x1 - o.x0) * pw;
    const fy = v => T + (1 - (v - o.y0) / (o.y1 - o.y0)) * ph;
    g.font = '10px "IBM Plex Mono", monospace'; g.lineWidth = 1;
    const xt = o.xticks || (() => { const s = niceStep(o.x1 - o.x0, o.nx || 6), a = []; for (let v = Math.ceil(o.x0 / s) * s; v <= o.x1 + 1e-9; v += s) a.push(v); return a; })();
    xt.forEach(v => { const x = fx(v); g.strokeStyle = P.line; g.beginPath(); g.moveTo(x, T); g.lineTo(x, T + ph); g.stroke(); g.fillStyle = P.dim; g.textAlign = "center"; g.fillText(o.fxl ? o.fxl(v) : String(+v.toFixed(4)), x, T + ph + 14); });
    const ys = niceStep(o.y1 - o.y0, o.ny || 5);
    for (let v = Math.ceil(o.y0 / ys) * ys; v <= o.y1 + 1e-9; v += ys) { const y = fy(v); g.strokeStyle = P.line; g.beginPath(); g.moveTo(L, y); g.lineTo(L + pw, y); g.stroke(); g.fillStyle = P.dim; g.textAlign = "right"; g.fillText(o.fyl ? o.fyl(v) : String(+v.toFixed(4)), L - 5, y + 3); }
    g.strokeStyle = P.line2; g.strokeRect(L + 0.5, T + 0.5, pw, ph);
    return { fx, fy, L, R, T, B, pw, ph };
  }
  function text(g, s, x, y, col, align = "left", font = '10px "IBM Plex Mono", monospace') { g.font = font; g.fillStyle = col; g.textAlign = align; g.fillText(s, x, y); }

  function drawGauge(cv, metric, v) {
    const c = prep(cv); if (!c) return; const { g, w, h } = c, P = pal();
    const [lo, hi] = E.RANGES[metric], th = E.TH[metric];
    const cx = w / 2, cy = h - 14, r = Math.min(w / 2 - 14, h - 26), lw = 13;
    const ang = val => Math.PI + (clamp(val, lo, hi) - lo) / (hi - lo) * Math.PI;
    const segs = [[lo, th[3], "bad"], [th[3], th[2], "poor"], [th[2], th[1], "fair"], [th[1], th[0], "good"], [th[0], hi, "ex"]];
    g.lineWidth = lw; g.lineCap = "butt";
    segs.forEach(([a, b, k]) => { g.strokeStyle = P[k]; g.globalAlpha = 0.28; g.beginPath(); g.arc(cx, cy, r, ang(a) + 0.01, ang(b) - 0.01); g.stroke(); });
    g.globalAlpha = 1;
    if (isNum(v)) {
      const cls = E.classify(metric, v).k; g.strokeStyle = P[cls]; g.beginPath(); g.arc(cx, cy, r, Math.PI, ang(v)); g.stroke();
      const a = ang(v); g.strokeStyle = P.fg; g.lineWidth = 2.5; g.lineCap = "round";
      g.beginPath(); g.moveTo(cx + Math.cos(a) * (r - lw - 6), cy + Math.sin(a) * (r - lw - 6)); g.lineTo(cx + Math.cos(a) * (r + lw / 2 + 2), cy + Math.sin(a) * (r + lw / 2 + 2)); g.stroke();
    }
    text(g, String(lo), cx - r, cy + 12, P.dim, "center"); text(g, String(hi), cx + r, cy + 12, P.dim, "center");
  }
  function drawRing(cv, val) {
    const c = prep(cv); if (!c) return; const { g, w, h } = c, P = pal();
    const cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 9;
    g.lineWidth = 10; g.strokeStyle = P.surface3; g.beginPath(); g.arc(cx, cy, r, 0, Math.PI * 2); g.stroke();
    if (!isNum(val)) return;
    const k = val >= 80 ? "ex" : val >= 60 ? "good" : val >= 40 ? "fair" : val >= 25 ? "poor" : "bad";
    g.strokeStyle = P[k]; g.lineCap = "round"; g.beginPath(); g.arc(cx, cy, r, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * val / 100); g.stroke();
  }

  /* ------------------------------------------------------------- data acquisition */
  function cleanNum(v) { return (typeof v === "number" && isFinite(v) && Math.abs(v) < 2e9) ? v : undefined; }
  function fromSnapshot(j) {
    if (!j) return null;
    const sv = j.serving || {};
    const s = { t: j.ts || Date.now(), source: "android" };
    for (const k in sv) s[k] = typeof sv[k] === "number" ? cleanNum(sv[k]) : sv[k];
    s.nb = (j.neighbors || []).map(n => { const o = {}; for (const k in n) o[k] = typeof n[k] === "number" ? cleanNum(n[k]) : n[k]; return o; });
    if (j.wifi && isNum(j.wifi.rssi) && j.wifi.rssi > -127) s.wifi = j.wifi;
    if (j.scan && j.scan.length) { s.scan = j.scan; s.scanTs = j.scanTs; }
    if (j.location) { s.lat = j.location.lat; s.lon = j.location.lon; s.acc = j.location.acc; s.speed = cleanNum(j.location.speed); }
    s.net = j.network || null;
    ST.permissions = j.permissions || ST.permissions; ST.device = j.device || ST.device;
    if (j.error && !s.tech) s.error = j.error;
    return s;
  }
  function browserSample() {
    const c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    return { t: Date.now(), source: "browser", net: c ? { type: c.type, effectiveType: c.effectiveType, downlink: c.downlink, rtt: c.rtt, saveData: c.saveData } : null, online: navigator.onLine };
  }
  function acquire() {
    const t = Date.now();
    if (ST.source === "android" && NATIVE) return fromSnapshot(parseJ(callB("getSnapshot")));
    if (ST.source === "demo") { if (!ST.demo || ST.demo.key !== ST.scenario) ST.demo = E.DemoSource(ST.scenario); return ST.demo.next(t); }
    return browserSample();
  }
  function recentSpeed() { const r = ST.speed[0]; return r && Date.now() - r.t < 30 * 60000 ? r : null; }

  function ingest(s) {
    if (!s) return;
    ST.last = s;
    ST.hist.push(s); if (ST.hist.length > 900) ST.hist.splice(0, ST.hist.length - 900);
    if (ST.recording && ST.session) { ST.session.samples.push(slim(s)); if (ST.session.samples.length > 20000) ST.session.samples.shift(); }
    ST.findings = E.diagnose(s, ST.hist, { speedtest: recentSpeed() });
    if (isNum(s.rsrp) && s.rsrp < ST.alert && Date.now() - ST.lastAlert > 60000) {
      ST.lastAlert = Date.now(); toast("تنبيه: RSRP = " + s.rsrp + " dBm، أقل من حد " + ST.alert + " dBm"); try { navigator.vibrate && navigator.vibrate(150); } catch (e) {}
    }
    updateBadges(); renderActive(false);
  }
  function slim(s) { const o = Object.assign({}, s); delete o.scan; delete o.net; if (o.nb) o.nb = o.nb.slice(0, 6); return o; }
  let timer = null;
  function loop() { clearTimeout(timer); try { ingest(acquire()); } catch (e) { console.error(e); } timer = setTimeout(loop, ST.interval); }
  // native push (TelephonyCallback) — immediate refresh between polls
  window.SS = {
    onSnapshot(j) { if (ST.source === "android") ingest(fromSnapshot(parseJ(j))); },
    onSpeedProgress(stage, pct, mbps) { speedProgress(stage, pct, mbps); },
    onSpeedResult(j) { speedDone(parseJ(j)); },
    onSyncResult(j) { const r = parseJ(j) || {}; syncBusy = false; if (r.ok) { toast("تم ربط الجهاز: " + (r.name || "")); $("syKey").value = ""; } else toast(r.message || "فشل الربط"); renderSync(); },
    onPermissions(j) { ST.permissions = parseJ(j); renderActive(true); toast("تم تحديث الصلاحيات"); },
    onToast(m) { toast(m); }
  };

  /* ------------------------------------------------------------- routing */
  function buildNav() {
    $("railNav").innerHTML = NAV.map(n => `<button class="navbtn" type="button" data-go="${n.id}">${svg(n.id)}<span>${n.ar}</span>${n.id === "diagnose" ? '<span class="cnt" id="railCnt" hidden></span>' : ""}</button>`).join("") ;
    $("tabbar").innerHTML = TAB.map(id => { const n = NAV.find(x => x.id === id); return `<button type="button" data-go="${id}">${svg(id)}<span>${n.short}</span>${id === "diagnose" ? '<span class="cnt" id="tabCnt" hidden></span>' : ""}</button>`; }).join("") + `<button type="button" id="moreBtn">${svg("more", 2.4)}<span>المزيد</span></button>`;
    $("morePanel").innerHTML = '<div class="grab"></div>' + NAV.filter(n => !TAB.includes(n.id)).map(n => `<button type="button" data-go="${n.id}">${svg(n.id)}<span>${n.short}</span></button>`).join("");
    document.querySelectorAll("[data-go]").forEach(b => b.addEventListener("click", () => { location.hash = b.dataset.go; $("moreSheet").hidden = true; }));
    $("moreBtn").onclick = () => { $("moreSheet").hidden = false; };
    $("moreSheet").onclick = e => { if (e.target === $("moreSheet")) $("moreSheet").hidden = true; };
  }
  function route() {
    const id = (location.hash || "#live").slice(1);
    ST.view = NAV.some(n => n.id === id) ? id : "live";
    document.querySelectorAll("[data-view]").forEach(s => s.hidden = s.dataset.view !== ST.view);
    document.querySelectorAll("[data-go]").forEach(b => { if (b.dataset.go === ST.view) b.setAttribute("aria-current", "page"); else b.removeAttribute("aria-current"); });
    const mb = $("moreBtn"); if (mb) { if (!TAB.includes(ST.view)) mb.setAttribute("aria-current", "page"); else mb.removeAttribute("aria-current"); }
    $("pageTitle").textContent = NAV.find(n => n.id === ST.view).ar;
    window.scrollTo(0, 0);
    renderActive(true);
  }
  function updateBadges() {
    const n = ST.findings.filter(f => f.sev === "crit" || f.sev === "warn").length;
    ["railCnt", "tabCnt"].forEach(id => { const el = $(id); if (el) { el.hidden = !n; el.textContent = n; } });
    const src = { android: ["قراءة حية · Android", "var(--q-ex)"], browser: ["المتصفح", "var(--info)"], demo: ["تجريبي · " + (E.SCENARIOS[ST.scenario] || {}).ar, "var(--warn)"] }[ST.source];
    $("srcTxt").textContent = src[0]; $("srcPill").querySelector(".d").style.background = src[1];
    $("scenarioSel").hidden = ST.source !== "demo";
    const rb = $("recBtn"); rb.classList.toggle("on", ST.recording); rb.innerHTML = svg(ST.recording ? "stop" : "rec") + (ST.recording ? " " + (ST.session ? ST.session.samples.length : 0) : " تسجيل");
  }
  function renderActive(force) {
    if (!ST.last && ST.view !== "tools" && ST.view !== "guide" && ST.view !== "prefs" && ST.view !== "obstacles" && ST.view !== "log") return;
    const v = ST.view;
    if (v === "live") renderLive();
    else if (v === "diagnose") { if (force || Date.now() - ST.lastDiagRender > 3000) renderDiagnose(); }
    else if (v === "spectrum") renderSpectrum();
    else if (v === "obstacles") { if (force) renderObstacles(); }
    else if (v === "speed") { if (force) renderSpeed(); }
    else if (v === "log") { if (force || ST.recording) renderLog(); }
    else if (v === "tools") { if (force) renderTools(); }
    else if (v === "guide") { if (force) renderGuide(); }
    else if (v === "prefs") { if (force) renderPrefs(); }
  }

  /* ------------------------------------------------------------- LIVE */
  const TECH_AR = { LTE: "4G LTE", NR: "5G NR", WCDMA: "3G WCDMA", GSM: "2G GSM" };
  function techLabel(s) { if (!s || !s.tech) return "—"; return (s.tech === "LTE" && s.nsa ? "5G NSA (مرساة LTE)" : TECH_AR[s.tech] || s.tech); }
  function chipQ(metric, v, unit) { const c = E.classify(metric, v); return `<span class="chip q q-${c.k}">${esc(c.ar)}${isNum(v) ? ` · <span class="mono" dir="ltr">${v}${unit ? " " + unit : ""}</span>` : ""}</span>`; }
  function banner(html) { const b = $("liveBanner"); if (!html) { b.hidden = true; return; } b.hidden = false; b.innerHTML = svg("info") + `<div>${html}</div>`; }
  function renderLive() {
    const s = ST.last; if (!s) return;
    // banner per source
    if (ST.source === "demo") banner(`<b>بيانات تجريبية — ${esc(E.SCENARIOS[ST.scenario].ar)}</b>القيم مولّدة من نموذج فيزيائي للتدريب على التشخيص. للقراءة الحقيقية ثبّت تطبيق Android، أو استخدم «الفحص اليدوي»، أو استورد ملف CSV في صفحة السجل.`);
    else if (ST.source === "browser") banner(`<b>وضع المتصفح</b>المتصفحات لا تسمح بقراءة RSRP أو SINR أو الخلايا. المتاح هنا: نوع الاتصال التقريبي، واختبار السرعة، وكل أدوات التحليل والاستيراد.`);
    else if (s.error) banner(`<b>لا توجد قراءة</b>${esc(s.error)} <button class="btn sm" type="button" onclick="SignalBridge.requestPermissions()">منح الصلاحيات</button>`);
    else if (ST.permissions && (!ST.permissions.location || !ST.permissions.phone)) banner(`<b>صلاحيات ناقصة</b>قراءة الخلايا تحتاج صلاحيتي الموقع وحالة الهاتف. <button class="btn sm" type="button" onclick="SignalBridge.requestPermissions()">منح الصلاحيات</button>`);
    else banner(null);

    const h = E.health(s, ST.findings);
    drawRing($("cvHealth"), h); $("healthVal").textContent = isNum(h) ? h : "—";
    const car = E.carrierOf(s);
    $("heroTitle").textContent = s.tech ? [techLabel(s), s.operator, car.ok ? (car.bandName + (car.name && car.name !== "—" ? " · " + car.name : "")) : null].filter(Boolean).join(" · ") : (ST.source === "browser" ? "لا توجد قراءة خلوية في المتصفح" : "بانتظار القراءة…");
    const top = ST.findings[0];
    $("heroText").textContent = top ? (top.sev === "ok" ? top.cause : top.title + ". " + (top.symptom || "")) : "";
    $("heroTags").innerHTML = E.primaryMetrics(s).map(([k, v, l, u]) => `<span class="pill">${l} ${chipQ(k, v, u)}</span>`).join("") + (s.wifi ? `<span class="pill">Wi-Fi ${chipQ("wifi", s.wifi.rssi, "dBm")}</span>` : "");

    // gauges
    const pm = E.primaryMetrics(s), gg = $("gauges");
    if (gg.dataset.sig !== pm.map(m => m[0]).join()) {
      gg.dataset.sig = pm.map(m => m[0]).join();
      gg.innerHTML = pm.length ? pm.map(([k, , l]) => `<div class="gauge"><canvas id="g_${k}"></canvas><div class="val" id="gv_${k}">—</div><div class="lbl">${l} <span id="gc_${k}"></span></div></div>`).join("")
        : `<p class="note">لا توجد مقاييس راديوية من هذا المصدر.</p>`;
    }
    pm.forEach(([k, v, , u]) => { drawGauge($("g_" + k), k, v); $("gv_" + k).innerHTML = isNum(v) ? v + `<small>${u}</small>` : "—"; const c = E.classify(k, v); $("gc_" + k).innerHTML = `<span class="chip q q-${c.k}">${c.ar}</span>`; });
    $("gaugeTech").textContent = techLabel(s);

    // serving cell
    const ta = E.taDistance(s.tech, s.ta);
    const enb = isNum(s.ci) && s.tech === "LTE" ? Math.floor(s.ci / 256) + " / " + (s.ci % 256) : null;
    const rows = [
      ["التقنية", techLabel(s)], ["المشغّل", s.operator ? s.operator + (s.mcc ? ` (${s.mcc}-${s.mnc})` : "") : null],
      ["النطاق", car.ok ? car.bandName + (car.mode ? " · " + car.mode : "") : s.band],
      [s.tech === "NR" ? "NR-ARFCN" : s.tech === "WCDMA" ? "UARFCN" : s.tech === "GSM" ? "ARFCN" : "EARFCN", s.earfcn ?? s.arfcn ?? s.uarfcn],
      ["تردد الهابط DL", car.ok && isNum(car.dl) ? car.dl + " MHz" : null], ["تردد الصاعد UL", car.ok && isNum(car.ul) ? car.ul + " MHz" : null],
      ["عرض النطاق", isNum(s.bwKHz) && s.bwKHz > 0 ? s.bwKHz / 1000 + " MHz" : null], [s.tech === "WCDMA" ? "PSC" : "PCI", s.pci],
      ["Cell ID", s.ci], [s.tech === "LTE" ? "eNB / Sector" : null, enb], ["TAC / LAC", s.tac],
      ["Timing Advance", isNum(s.ta) ? s.ta + (isNum(ta) ? `  ≈ ${(ta / 1000).toFixed(2)} km` : "") : null],
      ["RSSI", isNum(s.rssi) ? s.rssi + " dBm" : null], ["CQI", s.cqi], ["السرعة", isNum(s.speed) ? (s.speed * 3.6).toFixed(0) + " km/h" : null],
      ["الطول الموجي λ", car.ok && isNum(car.dl) ? (E.C0 / car.dl / 1e4).toFixed(1) + " cm" : null],
      ["5G NSA · SS-RSRP", s.nr && isNum(s.nr.ssRsrp) ? s.nr.ssRsrp + " dBm" : null], ["5G NSA · SS-SINR", s.nr && isNum(s.nr.ssSinr) ? s.nr.ssSinr + " dB" : null]
    ].filter(r => r[0] && r[1] !== null && r[1] !== undefined && r[1] !== "");
    $("cellKv").innerHTML = rows.length ? rows.map(r => `<dt>${r[0]}</dt><dd>${esc(r[1])}</dd>`).join("") : `<dt>—</dt><dd>لا توجد بيانات خلية</dd>`;
    $("cellTech").textContent = s.roaming ? "تجوال" : "";

    drawTrend();

    // neighbors
    const nb = (s.nb || []).slice().sort((a, b) => (b.rsrp ?? -999) - (a.rsrp ?? -999));
    $("nbCount").textContent = nb.length + " خلية";
    $("nbTbl").innerHTML = nb.length ? `<tr><th>PCI</th><th>القناة</th><th>RSRP</th><th>Δ عن المخدّمة</th><th>RSRQ</th><th>ملاحظة</th></tr>` + nb.map(n => {
      const d = isNum(n.rsrp) && isNum(s.rsrp) ? n.rsrp - s.rsrp : NaN, c = E.classify("rsrp", n.rsrp);
      const notes = []; if (isNum(d) && d >= -6) notes.push("ضمن 6 dB"); if (s.tech === "LTE" && isNum(n.pci) && isNum(s.pci) && n.pci % 3 === s.pci % 3) notes.push("mod3=" + (n.pci % 3)); if (isNum(d) && d > 3) notes.push("أقوى — مرشّح Handover");
      return `<tr><td class="n">${n.pci ?? "—"}</td><td class="n">${n.earfcn ?? n.arfcn ?? "—"}</td><td><span class="chip q q-${c.k}"><span class="mono" dir="ltr">${n.rsrp ?? "—"}</span></span></td><td><div class="row" style="flex-wrap:nowrap"><div class="bar q-${isNum(d) && d >= -6 ? "poor" : "good"}" style="width:80px"><i style="width:${isNum(d) ? clamp(100 + d * 4, 4, 100) : 0}%"></i></div><span class="mono" dir="ltr">${isNum(d) ? (d > 0 ? "+" : "") + d : "—"}</span></div></td><td class="n">${n.rsrq ?? "—"}</td><td>${notes.join(" · ") || "—"}</td></tr>`;
    }).join("") : `<tr><td class="note">لا توجد خلايا مجاورة مرئية${ST.source === "browser" ? " في وضع المتصفح" : ""}.</td></tr>`;

    // wifi + net
    const w = s.wifi, wc = w ? E.wifiChannel(w.freq) : null;
    $("wifiQ").innerHTML = w ? chipQ("wifi", w.rssi) : "";
    $("wifiKv").innerHTML = w ? [["الشبكة", w.ssid], ["BSSID", w.bssid], ["RSSI", w.rssi + " dBm"], ["النطاق / القناة", wc ? wc.band + " GHz · ch " + wc.ch : w.freq + " MHz"], ["عرض القناة", w.width ? w.width + " MHz" : null], ["سرعة الوصلة", isNum(w.linkMbps) ? w.linkMbps + " Mb/s" : null], ["معيار", w.standard]]
      .filter(r => r[1] !== null && r[1] !== undefined).map(r => `<dt>${r[0]}</dt><dd>${esc(r[1])}</dd>`).join("") : `<dt>—</dt><dd>غير متصل أو غير متاح</dd>`;
    const n = s.net || {}, dv = ST.device || {};
    const nk = [["حالة الاتصال", navigator.onLine ? "متصل" : "غير متصل"], ["نوع الاتصال", n.type || n.transport], ["التقدير الفعّال", n.effectiveType], ["Downlink تقديري", isNum(n.downlink) ? n.downlink + " Mb/s" : (isNum(n.downKbps) ? (n.downKbps / 1000).toFixed(1) + " Mb/s" : null)],
      ["RTT تقديري", isNum(n.rtt) ? n.rtt + " ms" : null], ["الجهاز", dv.model ? dv.manufacturer + " " + dv.model : null], ["Android", dv.android ? dv.android + " (API " + dv.sdk + ")" : null], ["الموقع", isNum(s.lat) ? s.lat.toFixed(5) + ", " + s.lon.toFixed(5) + (isNum(s.acc) ? " ±" + Math.round(s.acc) + "m" : "") : null]]
      .filter(r => r[1] !== null && r[1] !== undefined);
    $("netKv").innerHTML = nk.map(r => `<dt>${r[0]}</dt><dd>${esc(r[1])}</dd>`).join("");
  }
  function drawTrend() {
    const c = prep($("cvTrend")); if (!c) return; const P = pal();
    const now = ST.last.t, H = ST.hist.filter(h => h.t >= now - 120000);
    const ax = axes(c, { x0: -120, x1: 0, y0: -130, y1: -50, L: 40, R: 36, fxl: v => v + "s", fyl: v => v });
    const fy2 = v => ax.T + (1 - (v + 10) / 45) * ax.ph;
    for (let v = -10; v <= 35; v += 15) text(c.g, String(v), ax.L + ax.pw + 4, fy2(v) + 3, P.accent);
    for (let i = 1; i < H.length; i++) if (isNum(H[i].pci) && isNum(H[i - 1].pci) && H[i].pci !== H[i - 1].pci) { const x = ax.fx((H[i].t - now) / 1000); c.g.strokeStyle = P.bad; c.g.globalAlpha = 0.6; c.g.setLineDash([3, 3]); c.g.beginPath(); c.g.moveTo(x, ax.T); c.g.lineTo(x, ax.T + ax.ph); c.g.stroke(); c.g.setLineDash([]); c.g.globalAlpha = 1; }
    const line = (key, fy, col) => { c.g.beginPath(); let st = false; H.forEach(h => { const v = key === "rsrp" ? (h.rsrp ?? h.rscp ?? h.rssi) : h[key]; if (!isNum(v)) { st = false; return; } const x = ax.fx((h.t - now) / 1000), y = fy(v); st ? c.g.lineTo(x, y) : c.g.moveTo(x, y); st = true; }); c.g.strokeStyle = col; c.g.lineWidth = 1.8; c.g.stroke(); };
    line("rsrp", v => ax.fy(clamp(v, -130, -50)), P.signal); line("sinr", v => fy2(clamp(v, -10, 35)), P.accent);
    if (!H.some(h => isNum(h.rsrp) || isNum(h.rscp))) text(c.g, "لا توجد سلسلة قياس", c.w / 2, c.h / 2, P.muted, "center", '12px "IBM Plex Sans Arabic", sans-serif');
  }

  /* ------------------------------------------------------------- DIAGNOSE */
  const SEV = { crit: "حرج", warn: "تحذير", info: "معلومة", ok: "سليم" };
  function findingHTML(f, i) {
    const open = ST.openFindings.has(f.id) || (ST.openFindings.has("__first") && i === 0);
    const li = a => a.length ? `<ol>${a.map(x => `<li>${esc(x)}</li>`).join("")}</ol>` : `<p class="note">—</p>`;
    return `<details class="fd ${f.sev}" data-id="${f.id}" ${open ? "open" : ""}><summary><span class="sv">${SEV[f.sev]}</span><span class="tt">${esc(f.title)}${f.symptom ? `<small>${esc(f.symptom)}</small>` : ""}</span><span class="chev">${svg("chev")}</span></summary>
      <div class="body"><div><h5>السبب</h5><p style="margin:0;font-size:13px">${esc(f.cause)}</p></div>
      ${f.evidence && f.evidence.length ? `<div><h5>الدليل من القياس</h5><div class="ev">${f.evidence.map(e => `<span>${esc(e)}</span>`).join("")}</div></div>` : ""}
      <div class="grid g2"><div><h5>ما يمكنك فعله</h5>${li(f.user || [])}</div><div><h5>ما يجب على المشغّل فعله</h5>${li(f.operator || [])}</div></div>
      <div class="concept">مفهوم المحاضرة: ${esc(f.concept || "—")}</div></div></details>`;
  }
  function renderDiagnose() {
    ST.lastDiagRender = Date.now();
    const F = ST.manual ? ST.manual.findings : ST.findings;
    const cnt = k => F.filter(f => f.sev === k).length;
    $("diagSum").innerHTML = (ST.manual ? `<span class="pill" style="border-color:var(--accent);color:var(--accent)">نتيجة الفحص اليدوي</span><button class="btn sm" type="button" id="backLive">عودة للقراءة الحية</button>` : "")
      + ["crit", "warn", "info"].map(k => `<span class="pill"><span class="d" style="background:var(--${k})"></span>${SEV[k]}: <b class="mono">${cnt(k)}</b></span>`).join("")
      + (ST.last && !ST.manual ? `<span class="pill">الصحة: <b class="mono">${E.health(ST.last, F) ?? "—"}</b></span>` : "");
    $("diagWhen").textContent = ST.manual ? "" : "يُحدَّث كل 3 ثوانٍ · " + new Date().toLocaleTimeString("ar");
    const box = $("findings");
    box.innerHTML = F.map(findingHTML).join("");
    box.querySelectorAll("details").forEach(d => d.addEventListener("toggle", () => { ST.openFindings.delete("__first"); if (d.open) ST.openFindings.add(d.dataset.id); else ST.openFindings.delete(d.dataset.id); }));
    const bl = $("backLive"); if (bl) bl.onclick = () => { ST.manual = null; renderDiagnose(); };
  }
  function runManual() {
    const v = id => { const x = parseFloat($(id).value); return isFinite(x) ? x : undefined; };
    const tech = $("mTech").value, a = v("mArfcn"), main = v("mRsrp");
    const s = { t: Date.now(), source: "manual", tech, pci: v("mPci"), ta: v("mTa"), nb: [] };
    if (tech === "LTE" || tech === "NR") { s.rsrp = main; s.rsrq = v("mRsrq"); s.sinr = v("mSinr"); if (tech === "LTE") s.earfcn = a; else s.arfcn = a; }
    else if (tech === "WCDMA") { s.rscp = main; s.ecno = v("mRsrq"); s.uarfcn = a; }
    else { s.rssi = main; s.arfcn = a; }
    const nb = v("mNb") || 0; for (let i = 0; i < nb; i++) s.nb.push({ pci: (s.pci || 0) + 1 + i * 4, earfcn: s.earfcn, rsrp: isNum(s.rsrp) ? s.rsrp - 2 - i : undefined });
    if (isNum(v("mWifi"))) s.wifi = { rssi: v("mWifi") };
    const st = (isNum(v("mDl")) || isNum(v("mPing"))) ? { dl: v("mDl"), ul: v("mUl"), ping: v("mPing") } : null;
    ST.manual = { sample: s, findings: E.diagnose(s, [s], { speedtest: st }) };
    ST.openFindings = new Set(["__first"]);
    $("mOut").textContent = "الصحة: " + (E.health(s, ST.manual.findings) ?? "—") + "/100";
    renderDiagnose(); $("findings").scrollIntoView({ behavior: "smooth", block: "start" });
  }
  function renderIO() {
    const o = parseFloat($("ioOutV").value), i = parseFloat($("ioInV").value);
    if (!isFinite(o) || !isFinite(i)) { $("ioRes").textContent = "سجّل القيمتين أو أدخلهما يدوياً."; return; }
    const d = o - i, s = ST.last || {}, car = E.carrierOf(s), fG = car.ok && isNum(car.dl) ? car.dl / 1000 : 1.8;
    const mats = Object.keys(E.MATERIALS).filter(k => k !== "metal").map(k => ({ k, L: E.materialLoss(k, fG) }));
    const combos = [];
    mats.forEach(a => { combos.push({ n: E.MATERIALS[a.k].ar, L: a.L }); mats.forEach(b => { if (b.k >= a.k) combos.push({ n: E.MATERIALS[a.k].ar + " + " + E.MATERIALS[b.k].ar, L: a.L + b.L }); }); });
    combos.sort((x, y) => Math.abs(x.L - d) - Math.abs(y.L - d));
    const bt = E.buildingEntryLoss(fG, "traditional", 0.5), bth = E.buildingEntryLoss(fG, "thermal", 0.5);
    $("ioRes").textContent = `فقد الدخول المقاس = ${d.toFixed(1)} dB  @ ${(fG * 1000).toFixed(0)} MHz\n` +
      `مرجع P.2109: مبنى تقليدي ${bt.toFixed(1)} dB · مبنى عازل حرارياً ${bth.toFixed(1)} dB\n` +
      `أقرب تفسير:\n` + combos.slice(0, 3).map(c => `  • ${c.n} ≈ ${c.L.toFixed(1)} dB`).join("\n") +
      (d > 25 ? "\nالفقد كبير: زجاج عازل Low-E أو عدة جدران خرسانية أو قبو. الحل الأمثل: وحدة خارجية (CPE) أو Wi-Fi Calling." : d < 6 ? "\nفقد منخفض: النوافذ أو الجدران الخفيفة هي المسار الرئيسي للإشارة." : "");
  }

  /* ------------------------------------------------------------- SPECTRUM */
  const BAND_ROWS = [["B28/n28", 758, 803], ["B20/n20", 791, 821], ["B5", 869, 894], ["B8/n8", 925, 960], ["B3/n3", 1805, 1880], ["B1/n1", 2110, 2170], ["B40", 2300, 2400], ["B7", 2620, 2690], ["B38", 2570, 2620], ["B41/n41", 2496, 2690], ["n78", 3300, 3800]];
  function renderSpectrum() {
    const s = ST.last || {}, P = pal(), car = E.carrierOf(s);
    let c = prep($("cvBands"));
    if (c) {
      const ax = axes(c, { x0: 600, x1: 3900, y0: 0, y1: BAND_ROWS.length, L: 64, ny: BAND_ROWS.length, fyl: () => "", xticks: [700, 900, 1800, 2100, 2600, 3500], fxl: v => v + "" });
      BAND_ROWS.forEach((b, i) => { const y = ax.fy(BAND_ROWS.length - i) + 2, hh = ax.ph / BAND_ROWS.length - 4; c.g.fillStyle = P.line2; c.g.globalAlpha = 0.7; c.g.fillRect(ax.fx(b[1]), y, Math.max(2, ax.fx(b[2]) - ax.fx(b[1])), hh); c.g.globalAlpha = 1; text(c.g, b[0], ax.L - 6, y + hh * 0.75, P.muted, "right"); });
      (s.nb || []).forEach(n => { const ci = E.carrierOf(Object.assign({ tech: s.tech }, n)); if (ci.ok && isNum(ci.dl)) { const x = ax.fx(ci.dl); c.g.strokeStyle = P.signal; c.g.lineWidth = 1.5; c.g.beginPath(); c.g.moveTo(x, ax.T); c.g.lineTo(x, ax.T + 10); c.g.stroke(); } });
      if (car.ok && isNum(car.dl) && car.dl < 3900) { const x = ax.fx(car.dl); c.g.strokeStyle = P.accent; c.g.lineWidth = 2.5; c.g.beginPath(); c.g.moveTo(x, ax.T); c.g.lineTo(x, ax.T + ax.ph); c.g.stroke(); text(c.g, car.dl + " MHz", x + 4, ax.T + 12, P.accent); }
    }
    if (car.ok) {
      const f = car.dl, lam = E.C0 / (f * 1e6);
      const rows = [["النطاق", car.bandName + (car.name ? " · " + car.name : "")], ["النمط", car.mode], ["DL", f + " MHz"], ["UL", car.ul ? car.ul + " MHz" : null], ["الطول الموجي", (lam * 100).toFixed(1) + " cm · λ/2 = " + (lam * 50).toFixed(1) + " cm"],
        ["فقد إضافي مقارنة بـ 800 MHz", (20 * Math.log10(f / 800)).toFixed(1) + " dB"], ["اختراق جدار خرساني 20 cm", E.materialLoss("concrete", f / 1000).toFixed(1) + " dB"], ["دخول مبنى تقليدي (P.2109 وسيط)", E.buildingEntryLoss(f / 1000, "traditional", 0.5).toFixed(1) + " dB"],
        car.ambiguous ? ["ملاحظة", "رقم القناة مشترك بين أكثر من نطاق. Android 11 فما فوق يعيد النطاق الفعلي."] : null].filter(r => r && r[1]);
      $("carrierCard").innerHTML = `<dl class="kv">${rows.map(r => `<dt>${r[0]}</dt><dd>${esc(r[1])}</dd>`).join("")}</dl>`;
    } else $("carrierCard").innerHTML = `<p class="note">لا يوجد رقم قناة متاح من المصدر الحالي. استخدم محوّل القنوات في صفحة الأدوات.</p>`;
    renderWifi();
  }
  function renderWifi() {
    const s = ST.last || {}, P = pal(), band = ST.wifiBand, scan = s.scan || [];
    const aps = scan.filter(a => (E.wifiChannel(a.freq) || {}).band === band);
    $("scanAge").textContent = scan.length ? scan.length + " شبكة" + (s.scanTs ? " · قبل " + Math.round((Date.now() - s.scanTs) / 1000) + " ث" : "") : "";
    const c = prep($("cvWifi"));
    if (c) {
      const x0 = band === "2.4" ? 2400 : 5150, x1 = band === "2.4" ? 2495 : 5850;
      const xt = band === "2.4" ? [1, 3, 6, 9, 11, 13].map(ch => 2407 + 5 * ch) : [36, 52, 100, 116, 132, 149, 165].map(ch => 5000 + 5 * ch);
      const ax = axes(c, { x0, x1, y0: -95, y1: -30, L: 38, xticks: xt, fxl: f => "ch" + E.wifiChannel(f).ch });
      const cols = [P.signal, P.good, P.fair, P.poor, P.ex, "#B48CFF", "#7FB2FF"];
      aps.sort((a, b) => a.rssi - b.rssi).forEach((a, i) => {
        const w = a.width || 20, cen = a.center || a.freq, lo = cen - w / 2, hi = cen + w / 2, me = s.wifi && a.bssid === s.wifi.bssid;
        const col = me ? P.accent : cols[i % cols.length];
        c.g.beginPath(); c.g.moveTo(ax.fx(lo), ax.fy(-95));
        for (let k = 0; k <= 30; k++) { const f = lo + (hi - lo) * k / 30, e = Math.sin(Math.PI * k / 30); c.g.lineTo(ax.fx(f), ax.fy(-95 + (a.rssi + 95) * Math.pow(e, 0.35))); }
        c.g.closePath(); c.g.fillStyle = col; c.g.globalAlpha = me ? 0.35 : 0.12; c.g.fill(); c.g.globalAlpha = 1; c.g.strokeStyle = col; c.g.lineWidth = me ? 2.4 : 1.2; c.g.stroke();
        if (a.rssi > -82 || me) text(c.g, a.ssid || "(مخفية)", ax.fx(cen), ax.fy(a.rssi) - 4, me ? P.accent : P.muted, "center");
      });
      if (!aps.length) text(c.g, scan.length ? "لا توجد شبكات في هذا النطاق" : "لا توجد نتائج مسح. اضغط «مسح الآن» في تطبيق Android.", c.w / 2, c.h / 2, P.muted, "center", '12px "IBM Plex Sans Arabic", sans-serif');
    }
    const plan = E.wifiPlan(scan, s.wifi && s.wifi.bssid), p = plan[band];
    const cur = s.wifi ? E.wifiChannel(s.wifi.freq) : null;
    $("wifiAdvice").innerHTML = scan.length ? `<b>التوصية لنطاق ${band} GHz:</b> القناة الأقل تداخلاً هي <b class="mono">${p.best.ch}</b>${p.best.n ? ` (${p.best.n} شبكة تتداخل معها، مجموع ${p.best.I.toFixed(0)} dBm)` : " (خالية)"}.` +
      (cur && cur.band === band ? ` قناتك الحالية <b class="mono">${cur.ch}</b>.` : "") + ` الترتيب: ` + p.scores.map(x => `ch${x.ch}: ${x.I > -119 ? x.I.toFixed(0) : "—"}`).join(" · ") +
      (band === "2.4" ? "<br>في نطاق 2.4 GHz استخدم 1 أو 6 أو 11 فقط بعرض 20 MHz، فالقنوات الأخرى تتداخل جزئياً مع جارتين." : "<br>نطاق 5 GHz أقل ازدحاماً، لكن الجدران تمتصه أكثر. قنوات DFS (52–144) متاحة في كثير من الدول.") : "—";
    $("apTbl").innerHTML = scan.length ? `<tr><th>SSID</th><th>القناة</th><th>العرض</th><th>RSSI</th><th>BSSID</th></tr>` + scan.slice().sort((a, b) => b.rssi - a.rssi).map(a => { const wc = E.wifiChannel(a.freq) || {}; const me = s.wifi && a.bssid === s.wifi.bssid; return `<tr><td>${me ? "★ " : ""}${esc(a.ssid || "(مخفية)")}</td><td class="n">${wc.band || "?"}G · ${wc.ch || a.freq}</td><td class="n">${a.width || 20} MHz</td><td>${chipQ("wifi", a.rssi)}</td><td class="n">${esc(a.bssid || "")}</td></tr>`; }).join("") : "";
  }

  /* ------------------------------------------------------------- OBSTACLES */
  function obsFromLive() { const car = E.carrierOf(ST.last || {}); if (car.ok && isNum(car.dl)) { $("obF").value = Math.round(car.dl); } if (ST.last && isNum(ST.last.rsrp)) { /* keep reference as user-set */ } renderObstacles(); }
  function renderObstacles() {
    const list = $("obList"), keys = Object.keys(E.MATERIALS);
    if (!$("obAddSel").options.length) $("obAddSel").innerHTML = keys.map(k => `<option value="${k}">${E.MATERIALS[k].ar}</option>`).join("");
    list.innerHTML = ST.obstacles.map((o, i) => `<div class="ob-row"><select class="sel" data-i="${i}" data-k="m">${keys.map(k => `<option value="${k}" ${k === o.m ? "selected" : ""}>${E.MATERIALS[k].ar} · ${E.MATERIALS[k].en}</option>`).join("")}</select>
      <label class="f" style="flex-direction:row;align-items:center;gap:4px"><input class="inp num" type="number" step="0.1" data-i="${i}" data-k="t" value="${o.t}" aria-label="السماكة"><span>${E.MATERIALS[o.m].veg ? "m" : "cm"}</span></label>
      <input class="inp num" type="number" min="1" max="9" data-i="${i}" data-k="n" value="${o.n}" aria-label="العدد"><button class="btn sm ghost" type="button" data-del="${i}" aria-label="حذف">✕</button></div>`).join("") || `<p class="note">أضف العوائق الموجودة على المسار.</p>`;
    list.querySelectorAll("[data-k]").forEach(el => el.addEventListener("change", () => { const o = ST.obstacles[+el.dataset.i]; if (el.dataset.k === "m") { o.m = el.value; const M = E.MATERIALS[o.m]; o.t = M.veg ? M.t : +(M.t * 100).toFixed(1); } else o[el.dataset.k] = parseFloat(el.value) || 0; store.set("obstacles", ST.obstacles); renderObstacles(); }));
    list.querySelectorAll("[data-del]").forEach(el => el.onclick = () => { ST.obstacles.splice(+el.dataset.del, 1); store.set("obstacles", ST.obstacles); renderObstacles(); });
    const f = parseFloat($("obF").value) || 1800, fG = f / 1000, ref = parseFloat($("obRef").value);
    const lossOf = (o, fg) => { const M = E.MATERIALS[o.m]; const t = M.veg ? o.t : o.t / 100; return E.materialLoss(o.m, fg, t) * (o.n || 1); };
    const items = ST.obstacles.map(o => ({ o, L: lossOf(o, fG) })), tot = items.reduce((a, b) => a + b.L, 0), mx = Math.max(10, ...items.map(x => x.L));
    $("obBars").innerHTML = items.map(x => { const k = x.L < 3 ? "ex" : x.L < 8 ? "good" : x.L < 15 ? "fair" : x.L < 30 ? "poor" : "bad"; return `<div class="lossbar"><span>${E.MATERIALS[x.o.m].ar}${x.o.n > 1 ? " ×" + x.o.n : ""}</span><div class="bar q-${k}"><i style="width:${clamp(x.L / mx * 100, 2, 100)}%"></i></div><span class="mono" dir="ltr">${x.L >= 300 ? "≥300" : x.L.toFixed(1)} dB</span></div>`; }).join("");
    const bel50 = E.buildingEntryLoss(fG, $("obType").value, 0.5), bel90 = E.buildingEntryLoss(fG, $("obType").value, 0.9);
    const pred = isFinite(ref) ? ref - tot : NaN, live = ST.last && isNum(ST.last.rsrp) ? ST.last.rsrp : NaN;
    const tile = (l, v, u) => `<div class="stat"><span>${l}</span><b>${v}<small>${u || ""}</small></b></div>`;
    $("obTiles").innerHTML = tile("فقد المكدّس", tot >= 300 ? "≥300" : tot.toFixed(1), "dB") + tile("RSRP المتوقع داخلاً", fmt(pred, 0), "dBm") + tile("P.2109 وسيط / 90%", bel50.toFixed(0) + " / " + bel90.toFixed(0), "dB") + tile("RSRP الحي الآن", fmt(live, 0), "dBm");
    const cls = E.classify("rsrp", pred);
    $("obNote").innerHTML = isFinite(pred) ? `داخل المبنى سيكون التصنيف المتوقع <span class="chip q q-${cls.k}">${cls.ar}</span>. ` + (pred < -105 ? "التغطية الداخلية لن تكفي. الحل الأمثل وحدة خارجية (CPE) أو هوائي على النافذة الأقل فقداً، أو نطاق تردد أدنى." : "يكفي اختيار موضع قريب من أضعف عائق، وغالباً تكون النافذة غير المطلية.") + (isNum(live) && isFinite(pred) ? ` الفرق بين المتوقع والحي ${(live - pred).toFixed(0)} dB، والفرق الكبير يعني وجود مسار انعكاس أو عائق غير محسوب.` : "") : "";
    // loss vs frequency
    const c = prep($("cvObF")); if (!c) return; const P = pal();
    const fs = []; for (let x = 0; x <= 80; x++) fs.push(600 * Math.pow(10, x / 80));
    const L = fs.map(fx => Math.min(120, ST.obstacles.reduce((a, o) => a + lossOf(o, fx / 1000), 0)));
    const ax = axes(c, { x0: 600, x1: 6000, y0: 0, y1: Math.max(20, Math.ceil(Math.max(...L) / 10) * 10 + 5), logx: true, xticks: [700, 900, 1800, 2600, 3500, 5000], fxl: v => v >= 1000 ? (v / 1000) + "G" : v + "M", fyl: v => v + " dB" , L: 50});
    c.g.beginPath(); fs.forEach((fx, i) => { const x = ax.fx(fx), y = ax.fy(L[i]); i ? c.g.lineTo(x, y) : c.g.moveTo(x, y); }); c.g.strokeStyle = P.accent; c.g.lineWidth = 2.2; c.g.stroke();
    c.g.beginPath(); fs.forEach((fx, i) => { const x = ax.fx(fx), y = ax.fy(Math.min(120, E.buildingEntryLoss(fx / 1000, $("obType").value, 0.5))); i ? c.g.lineTo(x, y) : c.g.moveTo(x, y); }); c.g.strokeStyle = P.signal; c.g.setLineDash([5, 4]); c.g.lineWidth = 1.5; c.g.stroke(); c.g.setLineDash([]);
    if (f >= 600 && f <= 6000) { const x = ax.fx(f); c.g.strokeStyle = P.fg; c.g.globalAlpha = 0.5; c.g.beginPath(); c.g.moveTo(x, ax.T); c.g.lineTo(x, ax.T + ax.ph); c.g.stroke(); c.g.globalAlpha = 1; text(c.g, f + " MHz", x + 4, ax.T + 12, P.fg); }
    text(c.g, "— المكدّس (P.2040)", ax.L + 8, ax.T + 14, P.accent); text(c.g, "- - مبنى P.2109 وسيط", ax.L + 8, ax.T + 28, P.signal);
  }

  /* ------------------------------------------------------------- SPEED */
  let speedLive = { stage: "جاهز", pct: 0, mbps: 0 };
  function drawSpeedGauge() {
    const c = prep($("cvSpeed")); if (!c) return; const { g, w, h } = c, P = pal();
    const cx = w / 2, cy = h - 22, r = Math.min(w / 2 - 20, h - 40);
    const sc = v => Math.log10(1 + v) / Math.log10(1001), ang = v => Math.PI + sc(clamp(v, 0, 1000)) * Math.PI;
    g.lineWidth = 16; g.strokeStyle = P.surface3; g.beginPath(); g.arc(cx, cy, r, Math.PI, 2 * Math.PI); g.stroke();
    g.strokeStyle = P.accent; g.beginPath(); g.arc(cx, cy, r, Math.PI, ang(speedLive.mbps)); g.stroke();
    [0, 1, 5, 10, 50, 100, 500, 1000].forEach(v => { const a = ang(v); text(g, String(v), cx + Math.cos(a) * (r - 24), cy + Math.sin(a) * (r - 24) + 4, P.dim, "center"); });
    text(g, fmt(speedLive.mbps, speedLive.mbps < 10 ? 2 : 1), cx, cy - 18, P.fg, "center", '600 30px "IBM Plex Mono", monospace');
    text(g, "Mb/s", cx, cy + 2, P.muted, "center", '12px "IBM Plex Mono", monospace');
  }
  function speedProgress(stage, pct, mbps) { speedLive = { stage, pct: pct || 0, mbps: isNum(mbps) ? mbps : speedLive.mbps }; if (ST.view === "speed") { $("stStage").textContent = stage; $("stProg").style.width = clamp(pct, 0, 100) + "%"; drawSpeedGauge(); } }
  function speedDone(r) {
    ST.speedRunning = false; $("stBtn").disabled = false; $("stBtn").textContent = "أعد الاختبار";
    if (!r || r.error) { speedProgress("فشل: " + ((r && r.error) || "خطأ غير معروف"), 0, 0); toast("فشل اختبار السرعة"); return; }
    const s = ST.last || {};
    const rec = { t: Date.now(), ping: r.ping ?? r.pingMs, jitter: r.jitter ?? r.jitterMs, dl: r.dl ?? r.downloadMbps, ul: r.ul ?? r.uploadMbps, src: ST.source, tech: s.tech, rsrp: s.rsrp, sinr: s.sinr, bwKHz: s.bwKHz, wifi: s.wifi ? s.wifi.rssi : null, server: r.server || "speed.cloudflare.com" };
    ST.speed.unshift(rec); ST.speed = ST.speed.slice(0, 50); store.set("speedHist", ST.speed);
    speedLive = { stage: "اكتمل", pct: 100, mbps: rec.dl || 0 };
    ST.findings = E.diagnose(ST.last, ST.hist, { speedtest: rec }); updateBadges(); renderSpeed();
  }
  async function browserSpeedTest() {
    const base = "https://speed.cloudflare.com";
    const pings = [];
    for (let i = 0; i < 8; i++) { const t0 = performance.now(); await fetch(base + "/__down?bytes=0&r=" + Math.random(), { cache: "no-store" }); pings.push(performance.now() - t0); speedProgress("قياس زمن الاستجابة", 4 + i, 0); }
    pings.sort((a, b) => a - b); const ping = pings[Math.floor(pings.length / 2)]; let jit = 0; for (let i = 1; i < pings.length; i++) jit += Math.abs(pings[i] - pings[i - 1]); jit /= pings.length - 1;
    let dlBytes = 0, dlTime = 0, best = 0;
    for (const sz of [1e5, 1e6, 1e7, 2.5e7]) {
      const t0 = performance.now(); const res = await fetch(base + "/__down?bytes=" + sz + "&r=" + Math.random(), { cache: "no-store" });
      const rd = res.body.getReader(); let got = 0;
      for (;;) { const { done, value } = await rd.read(); if (done) break; got += value.length; const dt = (performance.now() - t0) / 1000; if (dt > 0.05) speedProgress("التنزيل", 12 + 50 * Math.min(1, (dlTime + dt) / 10), got * 8 / dt / 1e6); }
      const dt = (performance.now() - t0) / 1000; dlBytes += got; dlTime += dt; best = Math.max(best, got * 8 / dt / 1e6);
      if (dlTime > 8) break;
    }
    let ulBest = 0, ulT = 0;
    for (const sz of [1e5, 1e6, 4e6]) {
      const body = new Uint8Array(sz); const t0 = performance.now();
      await fetch(base + "/__up?r=" + Math.random(), { method: "POST", body, cache: "no-store" });
      const dt = (performance.now() - t0) / 1000; ulT += dt; ulBest = Math.max(ulBest, sz * 8 / dt / 1e6); speedProgress("الرفع", 65 + 30 * Math.min(1, ulT / 6), ulBest);
      if (ulT > 6) break;
    }
    return { ping, jitter: jit, dl: best, ul: ulBest };
  }
  async function startSpeed() {
    if (ST.speedRunning) return; ST.speedRunning = true; $("stBtn").disabled = true; $("stBtn").textContent = "جارٍ الاختبار…"; speedLive = { stage: "بدء", pct: 0, mbps: 0 }; drawSpeedGauge();
    if (ST.source === "android" && NATIVE && typeof Bridge.runSpeedTest === "function") { callB("runSpeedTest"); return; }
    if (ST.source === "demo") {
      const r = ST.demo ? ST.demo.speedtest() : { ping: 40, jitter: 8, dl: 40, ul: 10 }; let p = 0;
      const iv = setInterval(() => { p += 4; const stg = p < 15 ? "قياس زمن الاستجابة" : p < 65 ? "التنزيل" : "الرفع"; const m = p < 15 ? 0 : p < 65 ? r.dl * (0.6 + 0.4 * Math.random()) : r.ul * (0.7 + 0.3 * Math.random()); speedProgress(stg + " (محاكاة)", p, m); if (p >= 100) { clearInterval(iv); speedDone(Object.assign({ server: "demo" }, r)); } }, 140);
      return;
    }
    try { speedDone(await browserSpeedTest()); }
    catch (e) { speedDone({ error: "تعذّر الوصول إلى خادم الاختبار. قد تمنعه سياسة الصفحة (مثل معاينة Claude) أو الشبكة. شغّل التطبيق من استضافة عادية أو من Android." }); }
  }
  function renderSpeed() {
    drawSpeedGauge(); $("stStage").textContent = speedLive.stage; $("stProg").style.width = speedLive.pct + "%";
    const r = ST.speed[0];
    const st = (l, v, u) => `<div class="stat"><span>${l}</span><b>${v}<small>${u}</small></b></div>`;
    $("stBig").innerHTML = r ? st("تنزيل", fmt(r.dl, r.dl < 10 ? 2 : 1), "Mb/s") + st("رفع", fmt(r.ul, r.ul < 10 ? 2 : 1), "Mb/s") + st("Ping / Jitter", fmt(r.ping, 0) + " / " + fmt(r.jitter, 0), "ms") : `<p class="note" style="grid-column:1/-1">لم يُجرَ اختبار بعد.</p>`;
    const s = ST.last || {};
    const bw = isNum(s.bwKHz) && s.bwKHz > 0 ? s.bwKHz / 1000 : 20, exp = E.expectedThroughput(s.sinr, bw, 2);
    if (r && isNum(exp)) {
      const ratio = r.dl / exp, k = ratio >= 0.6 ? "ex" : ratio >= 0.3 ? "fair" : "bad";
      $("stExpect").innerHTML = `<div class="lossbar"><span>المتوقع من SINR</span><div class="bar"><i style="width:100%;background:var(--line2)"></i></div><span class="mono" dir="ltr">${exp.toFixed(0)} Mb/s</span></div>
        <div class="lossbar"><span>المقاس</span><div class="bar q-${k}"><i style="width:${clamp(ratio * 100, 2, 100)}%"></i></div><span class="mono" dir="ltr">${r.dl.toFixed(1)} Mb/s</span></div>
        <p class="note">${ratio >= 0.6 ? "الأداء متوافق مع جودة الراديو." : ratio >= 0.3 ? "الأداء أقل من قدرة الراديو، وغالباً يرجع إلى حمل متوسط على الخلية." : "فجوة كبيرة بين الراديو والأداء. السبب ازدحام الخلية أو شبكة النقل أو تقييد الباقة. راجع صفحة التشخيص."} الحساب: Shannon معدّل بعرض ${bw} MHz وSINR = ${s.sinr} dB.</p>`;
    } else $("stExpect").innerHTML = `<p class="note">المقارنة تحتاج قيمة SINR من قراءة خلوية حية مع نتيجة اختبار.</p>`;
    $("stCount").textContent = ST.speed.length + " اختبار";
    $("stTbl").innerHTML = ST.speed.length ? `<tr><th>الوقت</th><th>تنزيل</th><th>رفع</th><th>Ping</th><th>الشبكة</th><th>RSRP</th><th>SINR</th></tr>` + ST.speed.map(x => `<tr><td>${new Date(x.t).toLocaleString("ar")}</td><td class="n">${fmt(x.dl, 1)}</td><td class="n">${fmt(x.ul, 1)}</td><td class="n">${fmt(x.ping, 0)}</td><td>${esc(TECH_AR[x.tech] || x.src || "—")}</td><td class="n">${x.rsrp ?? "—"}</td><td class="n">${x.sinr ?? "—"}</td></tr>`).join("") : "";
  }

  /* ------------------------------------------------------------- LOG */
  function sessions() { return store.get("sessions", []); }
  function saveSession(sess) {
    let list = sessions(); list.unshift({ id: sess.id, name: sess.name, start: sess.start, src: sess.src, samples: sess.samples.slice(-5000) });
    list = list.slice(0, 6);
    while (list.length && !store.set("sessions", list)) list.pop();
  }
  function toggleRec() {
    if (!ST.recording) {
      ST.session = { id: Date.now(), start: Date.now(), name: "جلسة " + new Date().toLocaleString("ar"), src: ST.source, samples: [] };
      ST.recording = true; ST.logView = ST.session;
      if (ST.source === "android" && NATIVE) { const r = parseJ(callB("startRecording", ST.interval)); if (r && r.file) toast("التسجيل في الملف: " + r.file); }
      else toast("بدأ التسجيل");
    } else {
      ST.recording = false;
      let msg = "حُفظت " + ST.session.samples.length + " قراءة";
      if (ST.source === "android" && NATIVE) { const r = parseJ(callB("stopRecording")); if (r && r.file) { msg += " · " + r.file; ST.session.file = r.file; } }
      if (ST.session.samples.length) saveSession(ST.session);
      toast(msg);
    }
    updateBadges(); renderActive(true);
  }
  function renderLog() {
    const sess = ST.logView || ST.session || (ST.hist.length ? { name: "الذاكرة الحية (آخر 15 دقيقة)", samples: ST.hist, live: true } : null);
    $("logRec").classList.toggle("on", ST.recording); $("logRec").innerHTML = svg(ST.recording ? "stop" : "rec") + (ST.recording ? " إيقاف التسجيل" : " بدء التسجيل");
    $("logState").textContent = sess ? sess.name + " · " + sess.samples.length + " قراءة" : "لا توجد بيانات";
    const S = sess ? sess.samples : [], st = E.sessionStats(S);
    const tile = (l, v, u) => `<div class="stat"><span>${l}</span><b>${v}<small>${u || ""}</small></b></div>`;
    const dur = st.dur >= 3600 ? (st.dur / 3600).toFixed(1) + " h" : st.dur >= 60 ? (st.dur / 60).toFixed(1) + " min" : st.dur.toFixed(0) + " s";
    $("logTiles").innerHTML = tile("المدة", dur) + tile("RSRP الوسيط", fmt(st.rsrp.p50, 0), "dBm") + tile("RSRP أسوأ 10%", fmt(st.rsrp.p10, 0), "dBm") + tile("SINR الوسيط", fmt(st.sinr.p50, 1), "dB")
      + tile("تبديلات الخلايا", st.handovers) + tile("زمن الانقطاع (< -110)", isNum(st.outage) ? (100 * st.outage).toFixed(1) : "—", "%") + tile("القراءات", st.n) + tile("التقنيات", Object.keys(st.techs).map(k => TECH_AR[k] || k).join("، ") || "—");
    $("logSpan").textContent = S.length ? new Date(S[0].t).toLocaleTimeString("ar") + " → " + new Date(S[S.length - 1].t).toLocaleTimeString("ar") : "";
    // timeline
    let c = prep($("cvLog")); const P = pal();
    if (c) {
      if (S.length < 2) text(c.g, "ابدأ التسجيل أو استورد ملف CSV لعرض الخط الزمني", c.w / 2, c.h / 2, P.muted, "center", '12px "IBM Plex Sans Arabic", sans-serif');
      else {
        const t0 = S[0].t, t1 = S[S.length - 1].t, span = Math.max(1, (t1 - t0) / 1000);
        const ax = axes(c, { x0: 0, x1: span, y0: -130, y1: -50, L: 40, R: 34, fxl: v => span > 600 ? (v / 60).toFixed(0) + "m" : v.toFixed(0) + "s" });
        const fy2 = v => ax.T + (1 - (v + 10) / 45) * ax.ph; for (let v = -10; v <= 35; v += 15) text(c.g, String(v), ax.L + ax.pw + 4, fy2(v) + 3, P.accent);
        for (let i = 1; i < S.length; i++) if (isNum(S[i].pci) && isNum(S[i - 1].pci) && S[i].pci !== S[i - 1].pci) { const x = ax.fx((S[i].t - t0) / 1000); c.g.strokeStyle = P.bad; c.g.globalAlpha = 0.45; c.g.beginPath(); c.g.moveTo(x, ax.T + ax.ph - 8); c.g.lineTo(x, ax.T + ax.ph); c.g.stroke(); c.g.globalAlpha = 1; }
        const step = Math.max(1, Math.floor(S.length / 1500));
        const line = (fn, fy, col) => { c.g.beginPath(); let stt = false; for (let i = 0; i < S.length; i += step) { const v = fn(S[i]); if (!isNum(v)) { stt = false; continue; } const x = ax.fx((S[i].t - t0) / 1000), y = fy(v); stt ? c.g.lineTo(x, y) : c.g.moveTo(x, y); stt = true; } c.g.strokeStyle = col; c.g.lineWidth = 1.4; c.g.stroke(); };
        line(s => s.rsrp ?? s.rscp ?? s.rssi, v => ax.fy(clamp(v, -130, -50)), P.signal); line(s => s.sinr, v => fy2(clamp(v, -10, 35)), P.accent);
      }
    }
    // distribution
    const tot = st.rsrpN || 1;
    $("logDist").innerHTML = E.CLASSES.map(k => `<div class="lossbar"><span>${k.ar}</span><div class="bar q-${k.k}"><i style="width:${clamp(100 * st.dist[k.k] / tot, 0, 100)}%"></i></div><span class="mono" dir="ltr">${(100 * st.dist[k.k] / tot).toFixed(0)}%</span></div>`).join("") + `<p class="note">الحدود: ممتاز ≥ -80، جيد ≥ -90، متوسط ≥ -100، ضعيف ≥ -110 dBm.</p>`;
    // route
    c = prep($("cvRoute"));
    if (c) {
      const G = S.filter(s => isNum(s.lat) && isNum(s.lon));
      if (G.length < 2) text(c.g, "لا توجد إحداثيات GPS في هذه الجلسة", c.w / 2, c.h / 2, P.muted, "center", '12px "IBM Plex Sans Arabic", sans-serif');
      else {
        let la0 = Infinity, la1 = -Infinity, lo0 = Infinity, lo1 = -Infinity; G.forEach(s => { la0 = Math.min(la0, s.lat); la1 = Math.max(la1, s.lat); lo0 = Math.min(lo0, s.lon); lo1 = Math.max(lo1, s.lon); });
        const k = Math.cos((la0 + la1) / 2 * Math.PI / 180), wM = Math.max((lo1 - lo0) * k, 1e-5), hM = Math.max(la1 - la0, 1e-5), pad = 16;
        const sc = Math.min((c.w - 2 * pad) / wM, (c.h - 2 * pad) / hM);
        const px = s => [pad + ((s.lon - lo0) * k) * sc + ((c.w - 2 * pad) - wM * sc) / 2, c.h - pad - (s.lat - la0) * sc - ((c.h - 2 * pad) - hM * sc) / 2];
        c.g.strokeStyle = P.line2; c.g.lineWidth = 1; c.g.beginPath(); G.forEach((s, i) => { const [x, y] = px(s); i ? c.g.lineTo(x, y) : c.g.moveTo(x, y); }); c.g.stroke();
        G.forEach(s => { const [x, y] = px(s); c.g.fillStyle = qColor(E.classify("rsrp", s.rsrp).k); c.g.beginPath(); c.g.arc(x, y, 3.2, 0, 7); c.g.fill(); });
        const meters = (hM * 111320); text(c.g, "↕ " + (meters > 1000 ? (meters / 1000).toFixed(2) + " km" : meters.toFixed(0) + " m"), 8, 14, P.dim);
      }
    }
    // sessions list
    const list = sessions();
    $("sessCount").textContent = list.length + " / 6";
    $("sessTbl").innerHTML = list.length ? `<tr><th>الجلسة</th><th>المصدر</th><th>القراءات</th><th>RSRP الوسيط</th><th></th></tr>` + list.map((x, i) => { const s2 = E.sessionStats(x.samples); return `<tr><td>${esc(x.name)}</td><td>${esc(x.src)}</td><td class="n">${x.samples.length}</td><td class="n">${fmt(s2.rsrp.p50, 0)}</td><td><div class="row" style="flex-wrap:nowrap"><button class="btn sm" type="button" data-open="${i}">عرض</button><button class="btn sm ghost" type="button" data-rm="${i}">حذف</button></div></td></tr>`; }).join("") : `<tr><td class="note">لا توجد جلسات محفوظة بعد.</td></tr>`;
    $("sessTbl").querySelectorAll("[data-open]").forEach(b => b.onclick = () => { ST.logView = sessions()[+b.dataset.open]; renderLog(); });
    $("sessTbl").querySelectorAll("[data-rm]").forEach(b => b.onclick = () => { const l = sessions(); l.splice(+b.dataset.rm, 1); store.set("sessions", l); renderLog(); });
  }
  function exportData(kind) {
    const sess = ST.logView || ST.session || { name: "live", samples: ST.hist };
    if (!sess.samples.length) { toast("لا توجد بيانات للتصدير"); return; }
    const stamp = new Date().toISOString().replace(/[:.]/g, "-").slice(0, 19);
    const name = "signalscope_" + stamp + (kind === "csv" ? ".csv" : ".json");
    const content = kind === "csv" ? "﻿" + E.toCSV(sess.samples) : JSON.stringify({ app: "SignalScope", version: 1, session: sess.name, samples: sess.samples }, null, 1);
    if (NATIVE && typeof Bridge.saveText === "function") { const r = parseJ(callB("saveText", name, kind === "csv" ? "text/csv" : "application/json", content)); toast(r && r.ok ? "حُفظ: " + r.file : "تعذّر الحفظ"); return; }
    try { const blob = new Blob([content], { type: kind === "csv" ? "text/csv" : "application/json" }); const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000); toast("جارٍ التنزيل: " + name); }
    catch (e) { toast("التنزيل غير مسموح في هذه البيئة"); }
  }
  function importFile(file) {
    const rd = new FileReader();
    rd.onload = () => {
      let samples = [];
      const txt = String(rd.result);
      if (/^\s*\{/.test(txt)) { const j = parseJ(txt); samples = (j && j.samples) || []; }
      else { const r = E.importCSV(txt); samples = r.samples; }
      if (!samples.length) { toast("لم أتعرف على أعمدة الملف"); return; }
      const sess = { id: Date.now(), start: samples[0].t, name: "مستورد: " + file.name, src: "import", samples };
      saveSession(sess); ST.logView = sess; toast("استوردت " + samples.length + " قراءة"); renderLog();
    };
    rd.readAsText(file);
  }

  /* ------------------------------------------------------------- TOOLS */
  function renderTools() {
    const v = id => parseFloat($(id).value);
    // channel converter
    const k = $("tcKind").value, n = v("tcVal"); let o = "";
    if (k === "lte") { const r = E.earfcnInfo(n); o = r.ok ? `Band ${r.band} (${r.name}) · ${r.mode}\nDL = ${r.dl} MHz${r.ul ? "\nUL = " + r.ul + " MHz" : ""}\nλ = ${(E.C0 / r.dl / 1e4).toFixed(2)} cm` : "EARFCN خارج الجداول المعروفة"; }
    else if (k === "nr") { const r = E.nrarfcnInfo(n); o = r.ok ? `F = ${r.dl} MHz (${r.fr})\nBands: ${r.bandName}${r.ambiguous ? "\n(متداخل: النطاق الفعلي يحدده الجهاز)" : ""}\nλ = ${(E.C0 / r.dl / 1e4).toFixed(2)} cm` : "NR-ARFCN خارج المدى 0 – 3279165"; }
    else if (k === "umts") { const r = E.uarfcnInfo(n); o = r.ok ? `${r.bandName} (${r.name})\nDL = ${r.dl} MHz` : "UARFCN غير معروف"; }
    else if (k === "gsm") { const r = E.gsmArfcnInfo(n); o = r.ok ? `${r.bandName}\nDL = ${r.dl} MHz · UL = ${r.ul} MHz${r.note ? "\n" + r.note : ""}` : "ARFCN غير معروف"; }
    else { const r = E.wifiChannel(n); o = r ? `${r.band} GHz · channel ${r.ch}` : "تردد خارج نطاقات Wi-Fi"; }
    $("tcOut").textContent = o;
    // link budget
    const f = v("lbF"), pt = v("lbPt"), g = v("lbG"), sens = v("lbS"), m = v("lbM"), b = v("lbB"), hb = v("lbH"), d = v("lbD");
    const mapl = pt + g - sens - m - b, dF = Math.pow(10, (mapl - 32.44 - 20 * Math.log10(f)) / 20);
    const rU = E.hataRadiusKm(mapl, f, hb, 1.5, true), rS = E.hataRadiusKm(mapl, f, hb, 1.5, false);
    const Lf = E.fspl(d, f), aH = (1.1 * Math.log10(f) - 0.7) * 1.5 - (1.56 * Math.log10(f) - 0.8), Lh = 46.3 + 33.9 * Math.log10(f) - 13.82 * Math.log10(hb) - aH + 3 + (44.9 - 6.55 * Math.log10(hb)) * Math.log10(d);
    $("lbOut").textContent = `MAPL = ${pt} + ${g} − (${sens}) − ${m} − ${b} = ${mapl.toFixed(1)} dB\nنصف القطر — فضاء حر: ${dF.toFixed(2)} km\nنصف القطر — Hata حضري: ${rU.toFixed(2)} km · ضاحية: ${rS.toFixed(2)} km\n\nعند ${d} km: FSPL = ${Lf.toFixed(1)} dB · Hata = ${Lh.toFixed(1)} dB\nالقدرة المستقبَلة (Hata) = ${(pt + g - Lh - b).toFixed(1)} dBm\nملاحظة: Hata صالح بين 1500 و2000 MHz ولمسافات من 1 إلى 20 km.`;
    // fresnel
    const ff = v("frF"), D = v("frD"), x = clamp(v("frX"), 0.001, D - 0.001), r1 = E.fresnelRadius(1, x * 1000, (D - x) * 1000, ff), bulge = x * (D - x) / (12.74 * 4 / 3);
    const rMid = E.fresnelRadius(1, D * 500, D * 500, ff);
    $("frOut").textContent = `r₁ عند ${x} km = ${r1.toFixed(2)} m\nخلوص 60% المطلوب = ${(0.6 * r1).toFixed(2)} m\nتحدب الأرض (k=4/3) = ${bulge.toFixed(2)} m\nالارتفاع الأدنى فوق العائق = ${(0.6 * r1 + bulge).toFixed(2)} m\nr₁ في المنتصف = ${rMid.toFixed(2)} m`;
    // conversions
    const dbm = v("cvDbm"), mw = Math.pow(10, dbm / 10), ta = v("cvTa"), rs = v("cvRs"), bw = v("cvBw"), ld = clamp(v("cvLd") / 100, 0.01, 1), sn = v("cvSn");
    const nrb = E.nRBfromBw(bw), rssi = E.rssiFromRsrp(rs, nrb, ld), rsrq = 10 * Math.log10(nrb) + rs - rssi;
    $("cvOut").textContent = `${dbm} dBm = ${mw < 1e-3 ? mw.toExponential(3) : mw.toFixed(4)} mW = ${(mw / 1000).toExponential(3)} W\nTA ${ta} → ${(E.taDistance("LTE", ta) / 1000).toFixed(3)} km\nRSSI ≈ ${rssi.toFixed(1)} dBm · RSRQ ≈ ${rsrq.toFixed(1)} dB (N_RB=${nrb}, حمل ${Math.round(ld * 100)}%)\nShannon: C = ${(bw * Math.log2(1 + Math.pow(10, sn / 10))).toFixed(1)} Mb/s · المتوقع عملياً (2×2) ≈ ${E.expectedThroughput(sn, bw, 2).toFixed(1)} Mb/s`;
  }

  /* ------------------------------------------------------------- GUIDE + PREFS */
  function renderGuide() {
    const T = E.TH;
    $("guideBox").innerHTML = `
    <div class="card guide"><h3>ما هو راصد الإشارة</h3><p>أداة لفحص الشبكة الخلوية وWi-Fi وتشخيص المشكلات. تعمل الواجهة نفسها على الحاسوب والموبايل. القراءة الحقيقية للإشارة متاحة في تطبيق Android فقط، لأن المتصفحات وiOS لا تتيح هذه البيانات للتطبيقات العامة.</p>
      <div class="tblw"><table><tr><th>المنصة</th><th>ما يعمل</th></tr>
      <tr><td>Android (التطبيق)</td><td>قراءة حية للخلية والجيران وWi-Fi وGPS، وتسجيل في الخلفية، وSpeedtest، وتصدير CSV، وكل الصفحات</td></tr>
      <tr><td>الحاسوب / المتصفح</td><td>التشخيص اليدوي، واستيراد وتحليل ملفات القياس، والعوائق، والأدوات، وSpeedtest، والمختبر ثلاثي الأبعاد، والوضع التجريبي</td></tr>
      <tr><td>iPhone</td><td>نفس قدرات المتصفح، مع إدخال القيم من قائمة Field Test ‎*3001#12345#*‎</td></tr></table></div></div>
    <div class="card guide"><h3>حدود التصنيف المستخدمة</h3><div class="tblw"><table><tr><th>المقياس</th><th>ممتاز</th><th>جيد</th><th>متوسط</th><th>ضعيف</th><th>سيئ</th></tr>
      ${[["RSRP (dBm)", "rsrp"], ["RSRQ (dB)", "rsrq"], ["SINR (dB)", "sinr"], ["RSCP 3G (dBm)", "rscp"], ["Ec/No 3G (dB)", "ecno"], ["Wi-Fi RSSI (dBm)", "wifi"]].map(([l, k]) => `<tr><td>${l}</td><td class="n">≥ ${T[k][0]}</td><td class="n">${T[k][1]} … ${T[k][0]}</td><td class="n">${T[k][2]} … ${T[k][1]}</td><td class="n">${T[k][3]} … ${T[k][2]}</td><td class="n">&lt; ${T[k][3]}</td></tr>`).join("")}</table></div>
      <p class="note" style="margin-top:8px">RSRP يقيس قوة الإشارة المرجعية. SINR يقيس نقاءها من التداخل والضوضاء. RSRQ يجمع بين القوة والحمل. إشارة قوية مع SINR منخفض تعني تداخلاً وليس ضعف تغطية.</p></div>
    <div class="card guide"><h3>كيف تقرأ التشخيص</h3><ol><li>كل بطاقة تحوي العَرَض والسبب الفيزيائي والدليل الرقمي من قياسك، ثم الحلول مقسمة بين ما تفعله أنت وما يفعله المشغّل.</li><li>الترتيب حسب الخطورة: حرج ثم تحذير ثم معلومة.</li><li>بعض القواعد تحتاج سجلاً زمنياً، مثل التبديل المتكرر والتذبذب. اترك التطبيق يقرأ دقيقة أو دقيقتين.</li><li>قواعد السرعة تحتاج اختبار سرعة حديثاً (آخر 30 دقيقة).</li></ol></div>
    <div class="card guide"><h3>قواعد القياس الصحيح</h3><ul><li>ثبّت الهاتف 30 ثانية قبل الحكم. الخفوت متعدد المسارات يغيّر القيمة 10 dB أو أكثر خلال سنتيمترات.</li><li>قِس في 3 مواضع على الأقل في الغرفة، ثم قرب النافذة، ثم خارج المبنى، لتعرف فقد الدخول الفعلي.</li><li>اختبار السرعة يتأثر بالخادم والوقت. كرره في وقتين مختلفين قبل استنتاج الازدحام.</li><li>غطاء معدني أو مغناطيسي على الهاتف قد يخفض الإشارة عدة dB.</li></ul></div>
    <div class="card guide"><h3>الخصوصية</h3><p>كل البيانات تبقى على جهازك افتراضياً. إن ربطت الجهاز بخادمك من الإعدادات، تُرفع جلسات التسجيل (الإشارة والموقع) إلى خادمك فقط عبر HTTPS، ويمكنك إيقاف ذلك أو فصل الجهاز في أي وقت. خارج ذلك لا يرسل التطبيق شيئاً، باستثناء طلبات اختبار السرعة إلى speed.cloudflare.com عندما تضغط «ابدأ».</p></div>`;
  }
  let syncBusy = false;
  function renderSync() {
    const has = !!(NATIVE && Bridge && typeof Bridge.getSyncStatus === "function");
    $("syConnect").disabled = !has || syncBusy;
    if (!has) { $("syncNote").textContent = "المزامنة متاحة في تطبيق Android. لوحة التحكم والتقارير على خادمك في المسار /dashboard/."; return; }
    const st = parseJ(callB("getSyncStatus")) || {};
    $("syncSetup").hidden = !!st.configured; $("syConnect").hidden = !!st.configured;
    ["syNow", "syToggle", "syDisconnect", "syncKv"].forEach(id => $(id).hidden = !st.configured);
    $("syConnect").textContent = syncBusy ? "جارٍ الاتصال…" : "ربط الجهاز بالخادم";
    $("syncErr").hidden = !st.error; $("syncErr").textContent = st.error || "";
    if (!st.configured) return;
    $("syToggle").textContent = st.enabled ? "إيقاف المزامنة" : "تشغيل المزامنة";
    const when = st.lastSync ? new Date(st.lastSync).toLocaleString("ar") : "لم يُرفع شيء بعد";
    $("syncKv").innerHTML = [["الخادم", st.server], ["اسم الجهاز", st.name], ["الحالة", st.enabled ? (st.recordingToServer ? "تُرفع الجلسة الحالية" : "مفعّلة") : "موقوفة"],
      ["بانتظار الرفع", String(st.pending) + " قراءة"], ["أُرسل إجمالاً", String(st.sent) + " قراءة"], ["آخر رفع", when]]
      .map(r => `<dt>${r[0]}</dt><dd dir="auto">${esc(r[1])}</dd>`).join("");
  }
  function renderPrefs() {
    renderSync();
    $("srcSeg").querySelectorAll("button").forEach(b => { b.setAttribute("aria-pressed", String(b.dataset.v === ST.source)); b.disabled = b.dataset.v === "android" && !NATIVE; });
    $("srcNote").textContent = NATIVE ? "التطبيق يعمل داخل Android، والقراءة الحية متاحة." : "أنت في متصفح. خيار Android يعمل فقط داخل التطبيق المثبّت.";
    $("pInt").value = String(ST.interval); $("pAlert").value = ST.alert; $("pTheme").value = ST.theme;
    $("pMonitor").disabled = !NATIVE; $("pMonitor").textContent = ST.monitoring ? "إيقاف المراقبة في الخلفية" : "مراقبة في الخلفية (Android)";
    const dv = ST.device || {};
    $("aboutKv").innerHTML = [["الإصدار", "SignalScope " + ((ST.device && ST.device.app) || "1.1.0")], ["المحرك", "18 قاعدة تشخيص · نماذج ITU-R P.525 / P.526 / P.2040 / P.2109 · COST-231 Hata"], ["أرقام القنوات", "3GPP TS 36.101 · 38.104 · 25.101 · 45.005"], ["المنصة", NATIVE ? `Android ${dv.android || ""} · ${dv.manufacturer || ""} ${dv.model || ""}` : navigator.userAgent.slice(0, 80)]]
      .map(r => `<dt>${r[0]}</dt><dd>${esc(r[1])}</dd>`).join("");
  }

  /* ------------------------------------------------------------- misc UI */
  let toastT = null;
  function toast(m) { const t = $("toast"); t.textContent = m; t.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => t.hidden = true, 3200); }
  function setSource(v) {
    if (v === "android" && !NATIVE) return;
    ST.source = v; if (!NATIVE) store.set("source", v); ST.hist = []; ST.last = null; ST.demo = null; ST.manual = null;
    updateBadges(); loop(); renderActive(true);
  }

  function wire() {
    $("scenarioSel").innerHTML = Object.keys(E.SCENARIOS).map(k => `<option value="${k}" ${k === ST.scenario ? "selected" : ""}>${E.SCENARIOS[k].ar}</option>`).join("");
    $("scenarioSel").onchange = e => { ST.scenario = e.target.value; store.set("scenario", ST.scenario); ST.hist = []; ST.demo = null; ST.openFindings = new Set(["__first"]); loop(); };
    $("recBtn").onclick = toggleRec; $("logRec").onclick = toggleRec;
    $("themeBtn").onclick = () => { const dark = document.documentElement.getAttribute("data-theme") === "dark" || (!document.documentElement.getAttribute("data-theme") && matchMedia("(prefers-color-scheme: dark)").matches); ST.theme = dark ? "light" : "dark"; store.set("theme", ST.theme); applyTheme(); };
    matchMedia("(prefers-color-scheme: dark)").addEventListener("change", () => { if (ST.theme === "auto") applyTheme(); });
    window.addEventListener("amu-theme", e => { if (ST.theme !== e.detail) { ST.theme = e.detail; store.set("theme", ST.theme); applyTheme(); } });
    $("mRun").onclick = runManual;
    $("ioOut").onclick = () => { if (ST.last && isNum(ST.last.rsrp)) { $("ioOutV").value = ST.last.rsrp; renderIO(); } else toast("لا توجد قيمة RSRP حية. أدخلها يدوياً."); };
    $("ioIn").onclick = () => { if (ST.last && isNum(ST.last.rsrp)) { $("ioInV").value = ST.last.rsrp; renderIO(); } else toast("لا توجد قيمة RSRP حية. أدخلها يدوياً."); };
    $("ioReset").onclick = () => { $("ioOutV").value = ""; $("ioInV").value = ""; renderIO(); };
    ["ioOutV", "ioInV"].forEach(id => $(id).oninput = renderIO);
    $("wifiBandSeg").querySelectorAll("button").forEach(b => b.onclick = () => { ST.wifiBand = b.dataset.v; $("wifiBandSeg").querySelectorAll("button").forEach(x => x.setAttribute("aria-pressed", String(x === b))); renderWifi(); });
    $("scanBtn").onclick = () => { if (ST.source === "android" && NATIVE) { const ok = callB("scanWifi"); toast(ok === false ? "النظام قيّد المسح (4 مرات كل دقيقتين). تُعرض آخر نتائج." : "جارٍ المسح…"); } else if (ST.source === "demo") { if (ST.demo) ST.demo._scan = null; toast("مسح تجريبي جديد"); } else toast("مسح Wi-Fi غير متاح في المتصفح"); };
    ["obF", "obRef", "obType"].forEach(id => $(id).addEventListener("input", renderObstacles));
    $("obAdd").onclick = () => { const m = $("obAddSel").value, M = E.MATERIALS[m]; ST.obstacles.push({ m, n: 1, t: M.veg ? M.t : +(M.t * 100).toFixed(1) }); store.set("obstacles", ST.obstacles); renderObstacles(); };
    $("obUseLive").onclick = obsFromLive;
    $("stBtn").onclick = startSpeed;
    $("expCsv").onclick = () => exportData("csv"); $("expJson").onclick = () => exportData("json");
    $("impFile").onchange = e => { if (e.target.files[0]) importFile(e.target.files[0]); e.target.value = ""; };
    $("logClear").onclick = () => { if (ST.recording) { toast("أوقف التسجيل أولاً"); return; } ST.logView = null; ST.session = null; ST.hist = []; renderLog(); };
    document.querySelectorAll('[data-view="tools"] input, [data-view="tools"] select').forEach(el => el.addEventListener("input", renderTools));
    $("srcSeg").querySelectorAll("button").forEach(b => b.onclick = () => { setSource(b.dataset.v); renderPrefs(); });
    $("pInt").onchange = e => { ST.interval = +e.target.value; store.set("interval", ST.interval); loop(); };
    $("pAlert").onchange = e => { ST.alert = parseFloat(e.target.value) || -110; store.set("alert", ST.alert); };
    $("pTheme").onchange = e => { ST.theme = e.target.value; store.set("theme", ST.theme); applyTheme(); };
    $("pMonitor").onclick = () => { if (!NATIVE) return; if (ST.monitoring) { callB("stopMonitor"); ST.monitoring = false; } else { callB("startMonitor", ST.interval, ST.alert); ST.monitoring = true; } renderPrefs(); };
    $("pReset").onclick = () => { ["sessions", "speedHist", "obstacles", "source", "scenario", "interval", "alert", "theme"].forEach(store.del); ST.speed = []; ST.obstacles = DEFAULT_OBS.map(o => Object.assign({}, o)); toast("حُذفت البيانات المحفوظة"); renderActive(true); };
    $("syConnect").onclick = () => {
      const url = $("syServer").value.trim(), key = $("syKey").value;
      if (!/^https:\/\//i.test(url) && !/^[a-z0-9.-]+\.[a-z]{2,}(\/.*)?$/i.test(url)) { toast("أدخل عنوان الخادم مثل https://example.iq"); return; }
      if (!key) { toast("أدخل رمز التسجيل من ملف config.php"); return; }
      syncBusy = true; renderSync(); callB("connectServer", url, key, $("syName").value.trim());
    };
    $("syNow").onclick = () => { callB("syncNow"); toast("سيُرفع الطابور عند توفر الشبكة"); setTimeout(renderSync, 4000); };
    $("syToggle").onclick = () => { const st = parseJ(callB("getSyncStatus")) || {}; callB("setSyncEnabled", !st.enabled); renderSync(); };
    $("syDisconnect").onclick = () => { if (confirm("فصل الجهاز عن الخادم؟ القراءات غير المرفوعة تبقى على الهاتف لكنها لن تُرسل.")) { callB("disconnectServer"); renderSync(); } };
    setInterval(() => { if (ST.view === "prefs") renderSync(); }, 5000);
    window.addEventListener("hashchange", route);
    let rz = null; window.addEventListener("resize", () => { clearTimeout(rz); rz = setTimeout(() => renderActive(true), 120); });
    document.addEventListener("visibilitychange", () => { if (!document.hidden) loop(); });
  }

  /* ------------------------------------------------------------- boot */
  buildNav(); wire(); applyTheme();
  if (NATIVE) { ST.monitoring = !!callB("isMonitoring"); const dv = parseJ(callB("getDeviceInfo")); if (dv) ST.device = dv; }
  updateBadges(); route(); loop();
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => renderActive(true));
  if ("serviceWorker" in navigator && location.protocol === "https:" && !NATIVE && !/claude\.ai|claudeusercontent/.test(location.host)) navigator.serviceWorker.register("sw.js").catch(() => {});
})();
