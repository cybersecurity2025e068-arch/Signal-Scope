/* لوحة راصد الإشارة — واجهة المدير (بلا مكتبات بناء؛ MapLibre من CDN) */
(function () {
  "use strict";
  // index.php?r=/v1/... يعمل على أي استضافة (لا يحتاج mod_rewrite ولا PATH_INFO)
  const API_BASE = new URL("../api/index.php", location.href).href;
  const apiUrl = path => { const i = path.indexOf("?"); const p = i < 0 ? path : path.slice(0, i), q = i < 0 ? "" : path.slice(i + 1);
    return `${API_BASE}?r=/${encodeURI(p)}${q ? "&" + q : ""}`; };
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const isNum = v => typeof v === "number" && isFinite(v);
  const fmt = (v, d = 0) => isNum(v) ? v.toLocaleString("en-US", { maximumFractionDigits: d, minimumFractionDigits: d }) : "—";
  const dt = ms => ms ? new Date(ms).toLocaleString("ar-IQ", { dateStyle: "medium", timeStyle: "short" }) : "—";
  const dur = s => { s = Math.round(s || 0); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h ? `${h} س ${m} د` : `${m} د ${s % 60} ث`; };
  const km = m => m >= 1000 ? fmt(m / 1000, 2) + " km" : fmt(m) + " m";
  const bd = s => `<bdi dir="ltr">${s}</bdi>`;

  const OPERATORS = { "418-5": "Asiacell", "418-20": "Zain IQ", "418-30": "Zain IQ", "418-40": "Korek", "418-45": "Mobitel", "418-62": "Itisaluna", "418-92": "Omnnea" };
  const opName = (mcc, mnc) => OPERATORS[`${mcc}-${mnc}`] || `${mcc}-${mnc}`;
  const Q = [
    { k: "excellent", ar: "ممتاز", min: -80 }, { k: "good", ar: "جيد", min: -90 }, { k: "fair", ar: "متوسط", min: -100 },
    { k: "poor", ar: "ضعيف", min: -110 }, { k: "bad", ar: "ضعيف جداً", min: -999 },
  ];
  const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  const qColor = k => css("--q-" + k);
  const rsrpColorExpr = prop => ["case", ["==", ["get", prop], null], qColor("none"),
    ["step", ["get", prop], qColor("bad"), -110, qColor("poor"), -100, qColor("fair"), -90, qColor("good"), -80, qColor("excellent")]];
  const legend = () => `<div class="legend">${Q.map(q => `<span><i style="background:${qColor(q.k)}"></i>${q.ar} ${bd(q.min > -999 ? "≥ " + q.min : "&lt; -110")}</span>`).join("")}<span><i style="background:${qColor("none")}"></i>بلا خدمة</span></div>`;

  // ------------------------------------------------------------------ API
  async function api(path, opts = {}) {
    const o = { credentials: "same-origin", headers: { "X-SS": "1", "Accept": "application/json" }, ...opts };
    if (o.body && typeof o.body !== "string") { o.body = JSON.stringify(o.body); o.headers["Content-Type"] = "application/json"; }
    const r = await fetch(apiUrl(path), o);
    let j = null; try { j = await r.json(); } catch (_) { }
    if (r.status === 401 && j && j.error === "login_required") { showAuth(false); throw new Error(j.message); }
    if (!r.ok) throw new Error((j && j.message) || `HTTP ${r.status}`);
    return j;
  }
  let toastT;
  function toast(m) { const t = $("toast"); t.textContent = m; t.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => t.hidden = true, 3500); }

  // ------------------------------------------------------------------ auth
  let setupMode = false;
  function showAuth(setup) {
    setupMode = setup; $("shell").hidden = true; $("auth").hidden = false;
    $("setupRow").hidden = !setup; $("aBtn").textContent = setup ? "إنشاء حساب المدير" : "دخول";
    $("authHint").textContent = setup ? "أول تشغيل: أنشئ حساب المدير. كلمة المرور 10 أحرف على الأقل." : "سجّل الدخول لمتابعة الجلسات والتقارير.";
    $("aPass").autocomplete = setup ? "new-password" : "current-password";
  }
  $("authForm").addEventListener("submit", async e => {
    e.preventDefault(); $("aErr").hidden = true;
    try {
      const body = { username: $("aUser").value.trim(), password: $("aPass").value };
      if (setupMode) body.setup_key = $("aSetup").value;
      await api(setupMode ? "v1/admin/setup" : "v1/admin/login", { method: "POST", body });
      $("aPass").value = ""; $("aSetup").value = ""; enter();
    } catch (err) { $("aErr").textContent = err.message; $("aErr").hidden = false; }
  });
  function enter() { $("auth").hidden = true; $("shell").hidden = false; route(); }

  // ------------------------------------------------------------------ maps
  const maps = [];
  function makeMap(el, center = [44.366, 33.315], zoom = 11) {
    const m = new maplibregl.Map({
      container: el, center, zoom, attributionControl: { compact: true }, preserveDrawingBuffer: true,
      style: {
        version: 8,
        sources: { osm: { type: "raster", tiles: ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"], tileSize: 256, maxzoom: 19, attribution: "© OpenStreetMap" } },
        layers: [{ id: "osm", type: "raster", source: "osm" }],
      },
    });
    m.addControl(new maplibregl.NavigationControl({ showCompass: false }), "top-left");
    maps.push(m);
    return new Promise(res => m.on("load", () => res(m)));
  }
  function fit(m, coords, maxZoom = 16) {
    if (!coords.length) return;
    const b = coords.reduce((b, c) => b.extend(c), new maplibregl.LngLatBounds(coords[0], coords[0]));
    m.fitBounds(b, { padding: 40, maxZoom, duration: 0 });
  }
  function circle(lon, lat, r, n = 64) {
    const pts = [], dLat = r / 111320, dLon = r / (111320 * Math.cos(lat * Math.PI / 180));
    for (let i = 0; i <= n; i++) { const a = 2 * Math.PI * i / n; pts.push([lon + dLon * Math.cos(a), lat + dLat * Math.sin(a)]); }
    return { type: "Feature", geometry: { type: "Polygon", coordinates: [pts] }, properties: {} };
  }
  function popupOn(m, layer, html) {
    m.on("click", layer, e => { const f = e.features[0]; new maplibregl.Popup({ closeButton: false }).setLngLat(e.lngLat).setHTML(html(f.properties)).addTo(m); });
    m.on("mouseenter", layer, () => m.getCanvas().style.cursor = "pointer");
    m.on("mouseleave", layer, () => m.getCanvas().style.cursor = "");
  }

  // ------------------------------------------------------------------ charts
  function lineChart(cv, series, opts = {}) {
    const dpr = window.devicePixelRatio || 1, w = cv.clientWidth, h = cv.clientHeight;
    cv.width = w * dpr; cv.height = h * dpr; const g = cv.getContext("2d"); g.scale(dpr, dpr);
    const pad = { l: 44, r: 12, t: 10, b: 24 };
    g.direction = "ltr";
    const xs = series.flatMap(s => s.data.map(p => p[0])), ys = series.flatMap(s => s.data.map(p => p[1])).filter(isNum);
    if (!xs.length || !ys.length) { g.fillStyle = css("--muted"); g.fillText("لا بيانات", w / 2, h / 2); return; }
    const x0 = Math.min(...xs), x1 = Math.max(...xs) || x0 + 1;
    const y0 = opts.yMin ?? Math.floor(Math.min(...ys) / 10) * 10, y1 = opts.yMax ?? Math.ceil(Math.max(...ys) / 10) * 10;
    const fx = x => pad.l + (w - pad.l - pad.r) * (x - x0) / (x1 - x0 || 1);
    const fy = y => pad.t + (h - pad.t - pad.b) * (1 - (y - y0) / (y1 - y0 || 1));
    g.strokeStyle = css("--line"); g.fillStyle = css("--muted"); g.font = "11px ui-monospace, monospace"; g.lineWidth = 1;
    for (let y = y0; y <= y1; y += Math.max(5, Math.round((y1 - y0) / 5 / 5) * 5)) {
      g.beginPath(); g.moveTo(pad.l, fy(y)); g.lineTo(w - pad.r, fy(y)); g.stroke(); g.textAlign = "right"; g.fillText(String(y), pad.l - 6, fy(y) + 4);
    }
    g.textAlign = "center";
    for (let i = 0; i <= 4; i++) { const x = x0 + (x1 - x0) * i / 4; g.fillText(opts.xLabel ? opts.xLabel(x) : String(Math.round(x)), fx(x), h - 6); }
    for (const s of series) {
      g.beginPath(); g.strokeStyle = s.color; g.lineWidth = 1.5; let on = false;
      for (const [x, y] of s.data) { if (!isNum(y)) { on = false; continue; } on ? g.lineTo(fx(x), fy(y)) : g.moveTo(fx(x), fy(y)); on = true; }
      g.stroke();
    }
    (opts.marks || []).forEach(x => { g.strokeStyle = css("--bad"); g.setLineDash([3, 3]); g.beginPath(); g.moveTo(fx(x), pad.t); g.lineTo(fx(x), h - pad.b); g.stroke(); g.setLineDash([]); });
  }

  // ------------------------------------------------------------------ router
  const V = () => $("view");
  async function route() {
    maps.splice(0).forEach(m => { try { m.remove(); } catch (_) { } });
    const parts = (location.hash || "#/overview").slice(2).split("/");
    const page = parts[0] || "overview", id = parseInt(parts[1], 10);
    document.querySelectorAll("#nav a").forEach(a => {
      const p = a.getAttribute("href").slice(2);
      if (p === page || (p === "sessions" && page === "session") || (p === "cells" && page === "cell")) a.setAttribute("aria-current", "page"); else a.removeAttribute("aria-current");
    });
    V().innerHTML = `<div class="empty">جارٍ التحميل…</div>`;
    try {
      const fn = { overview, sessions, session, coverage, cells, cell, devices, settings }[page] || overview;
      await fn(id);
    } catch (e) { V().innerHTML = `<div class="card"><p class="err">${esc(e.message)}</p></div>`; }
  }
  window.addEventListener("hashchange", route);

  // ------------------------------------------------------------------ pages
  async function overview() {
    const [o, ss] = await Promise.all([api("v1/overview"), api("v1/sessions?limit=8")]);
    V().innerHTML = `
      <div class="row spread" style="margin-bottom:14px"><h1>نظرة عامة</h1><span class="muted mono">v${esc(o.version)}</span></div>
      <div class="tiles">
        <div class="tile"><span>الأجهزة النشطة</span><b>${fmt(o.devices)}</b></div>
        <div class="tile"><span>الجلسات</span><b>${fmt(o.sessions)}</b></div>
        <div class="tile"><span>القراءات</span><b>${fmt(o.samples)}</b></div>
        <div class="tile"><span>قراءات آخر 24 ساعة</span><b>${fmt(o.samples_24h)}</b></div>
        <div class="tile"><span>الخلايا المكتشفة</span><b>${fmt(o.cells)}</b></div>
        <div class="tile"><span>خلايا قُدّر موقعها</span><b>${fmt(o.cells_located)}</b></div>
      </div>
      <div class="grid g2" style="margin-top:14px">
        <div class="card"><h3>المشغّلون</h3><div class="tablewrap"><table><tr><th>المشغّل</th><th>MCC-MNC</th><th>الخلايا</th><th>القراءات</th></tr>
          ${o.operators.map(p => `<tr><td>${esc(opName(p.mcc, p.mnc))}</td><td class="n">${p.mcc}-${String(p.mnc).padStart(2, "0")}</td><td class="n">${fmt(p.cells)}</td><td class="n">${fmt(p.samples)}</td></tr>`).join("") || `<tr><td colspan="4" class="muted">لا بيانات بعد</td></tr>`}</table></div></div>
        <div class="card"><h3>آخر الجلسات</h3>${sessionTable(ss)}</div>
      </div>
      ${o.devices === 0 ? `<div class="card" style="margin-top:14px"><h3>البداية</h3><ol><li>ثبّت تطبيق راصد الإشارة على الهاتف.</li><li>افتح الإعدادات ← المزامنة مع الخادم.</li><li>أدخل عنوان موقعك ورمز التسجيل <span class="mono">enroll_key</span> من ملف config.php.</li><li>ابدأ التسجيل؛ تظهر الجلسة هنا بعد الرفع.</li></ol></div>` : ""}`;
    bindRows();
  }

  function sessionTable(rows) {
    if (!rows.length) return `<div class="empty">لا توجد جلسات بعد</div>`;
    return `<div class="tablewrap"><table><tr><th>الجلسة</th><th>الجهاز</th><th>البداية</th><th>المدة</th><th>القراءات</th></tr>
      ${rows.map(s => `<tr class="click" data-href="#/session/${s.id}"><td>${esc(s.name || "جلسة " + s.id)}</td><td>${esc(s.device_name)}</td><td>${dt(s.first_ts || s.started_at)}</td><td>${s.first_ts ? dur((s.last_ts - s.first_ts) / 1000) : "—"}</td><td class="n">${fmt(s.sample_count)}</td></tr>`).join("")}</table></div>`;
  }
  function bindRows() { V().querySelectorAll("tr[data-href]").forEach(tr => tr.onclick = () => location.hash = tr.dataset.href); }

  async function sessions() {
    const [rows, devs] = await Promise.all([api("v1/sessions?limit=500"), api("v1/devices")]);
    V().innerHTML = `<div class="row spread" style="margin-bottom:14px"><h1>الجلسات</h1>
      <label class="f">الجهاز<select class="sel" id="fDev"><option value="">كل الأجهزة</option>${devs.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join("")}</select></label></div>
      <div class="card" id="sTbl">${sessionTable(rows)}</div>`;
    bindRows();
    $("fDev").onchange = async e => { $("sTbl").innerHTML = sessionTable(await api("v1/sessions?limit=500" + (e.target.value ? "&device=" + e.target.value : ""))); bindRows(); };
  }

  async function session(id) {
    const [r, gj] = await Promise.all([api(`v1/sessions/${id}`), api(`v1/sessions/${id}/geojson`)]);
    const s = r.session, st = r.stats, total = st.samples || 1;
    const cellName = {}; r.cells.forEach((c, i) => cellName[c.cell_ref] = `PCI ${c.pci ?? "?"} · ${c.cid}`);
    V().innerHTML = `
      <div class="row spread" style="margin-bottom:6px"><h1>${esc(s.name || "جلسة " + s.id)}</h1>
        <div class="row noprint">
          <a class="btn sm" href="${apiUrl(`v1/sessions/${id}/csv`)}">CSV</a>
          <a class="btn sm" href="${apiUrl(`v1/sessions/${id}/geojson?download=1`)}">GeoJSON</a>
          <button class="btn sm" id="bPrint" type="button">طباعة / PDF</button>
          <button class="btn sm danger" id="bDel" type="button">حذف</button>
        </div></div>
      <p class="muted" style="margin-top:0">${esc(s.device_name)} · ${dt(s.first_ts || s.started_at)} · المدة ${dur(st.duration_s)} · المسافة ${bd(km(st.distance_m))}</p>
      <div class="tiles">
        <div class="tile"><span>القراءات</span><b>${fmt(st.samples)}</b></div>
        <div class="tile"><span>وسيط RSRP</span><b>${fmt(st.rsrp.median)}<small>dBm</small></b></div>
        <div class="tile"><span>أضعف 10% (P10)</span><b>${fmt(st.rsrp.p10)}<small>dBm</small></b></div>
        <div class="tile"><span>وسيط SINR</span><b>${fmt(st.sinr.median, 1)}<small>dB</small></b></div>
        <div class="tile"><span>انقطاع الخدمة</span><b>${fmt(st.no_service_pct, 1)}<small>%</small></b></div>
        <div class="tile"><span>التبديلات</span><b>${fmt(st.handovers)}<small>(${fmt(st.pingpong)} ذهاب وإياب)</small></b></div>
      </div>
      <div class="card" style="margin-top:14px"><h3>توزيع الجودة (RSRP)</h3>
        <div class="bar">${[...Q.map(q => q.k), "none"].map(k => st.buckets[k] ? `<div title="${k}: ${st.buckets[k]}" style="width:${100 * st.buckets[k] / total}%;background:${qColor(k)}"></div>` : "").join("")}</div>
        <div class="legend">${Q.map(q => `<span><i style="background:${qColor(q.k)}"></i>${q.ar}: ${bd(fmt(100 * (st.buckets[q.k] || 0) / total, 1) + "%")}</span>`).join("")}<span><i style="background:${qColor("none")}"></i>بلا خدمة: ${bd(fmt(st.no_service_pct, 1) + "%")}</span></div>
      </div>
      <div class="grid g2" style="margin-top:14px">
        <div class="card span2"><h3>المسار ملوّناً حسب RSRP</h3><div class="map" id="sMap"></div>${legend()}<p class="note">الحلقات الحمراء مواضع التبديل بين الخلايا. انقر أي نقطة لتفاصيلها.</p></div>
        <div class="card span2"><h3>RSRP وSINR عبر الزمن</h3><canvas class="chart" id="sChart"></canvas>
          <div class="legend"><span><i style="background:var(--accent)"></i>RSRP (dBm)</span><span><i style="background:${qColor("good")}"></i>SINR (dB) مزاح −100</span><span><i style="background:var(--bad)"></i>تبديل خلية</span></div></div>
        <div class="card"><h3>التشخيص</h3><ul class="diag">${r.diagnostics.map(d => `<li class="${d.level}">${esc(d.text)}</li>`).join("")}</ul></div>
        <div class="card"><h3>الخلايا المخدِّمة</h3><div class="tablewrap"><table><tr><th>الخلية</th><th>التقنية</th><th>القراءات</th><th>متوسط RSRP</th></tr>
          ${r.cells.map(c => `<tr class="click" data-href="#/cell/${c.cell_ref}"><td class="n">${esc(cellName[c.cell_ref])}</td><td>${esc(c.tech)}</td><td class="n">${fmt(c.samples)}</td><td class="n">${fmt(c.avg_rsrp, 1)}</td></tr>`).join("")}</table></div></div>
      </div>`;
    bindRows();
    $("bPrint").onclick = () => window.print();
    $("bDel").onclick = async () => { if (!confirm("حذف الجلسة وكل قراءاتها نهائياً؟")) return; await api(`v1/sessions/${id}`, { method: "DELETE" }); toast("حُذفت الجلسة"); location.hash = "#/sessions"; };

    const t0 = gj.features.length ? gj.features[0].properties.ts : 0;
    const min = ts => (ts - t0) / 60000;
    lineChart($("sChart"), [
      { color: css("--accent"), data: gj.features.map(f => [min(f.properties.ts), f.properties.rsrp]) },
      { color: qColor("good"), data: gj.features.map(f => [min(f.properties.ts), isNum(f.properties.sinr) ? f.properties.sinr - 100 : null]) },
    ], { xLabel: x => Math.round(x) + " د", marks: r.handovers.slice(0, 200).map(h => min(h.ts)) });

    const m = await makeMap($("sMap"));
    const line = { type: "Feature", geometry: { type: "LineString", coordinates: gj.features.map(f => f.geometry.coordinates) }, properties: {} };
    m.addSource("line", { type: "geojson", data: line });
    m.addLayer({ id: "line", type: "line", source: "line", paint: { "line-color": css("--muted"), "line-width": 1, "line-opacity": .5 } });
    m.addSource("pts", { type: "geojson", data: gj });
    m.addLayer({ id: "pts", type: "circle", source: "pts", paint: { "circle-radius": ["interpolate", ["linear"], ["zoom"], 10, 2.5, 16, 6], "circle-color": rsrpColorExpr("rsrp"), "circle-stroke-width": 0.5, "circle-stroke-color": "#0006" } });
    const ho = { type: "FeatureCollection", features: r.handovers.filter(h => h.lat !== null).map(h => ({ type: "Feature", geometry: { type: "Point", coordinates: [+h.lon, +h.lat] }, properties: { from: cellName[h.from] || h.from, to: cellName[h.to] || h.to, ts: h.ts, rsrp: h.rsrp } })) };
    m.addSource("ho", { type: "geojson", data: ho });
    m.addLayer({ id: "ho", type: "circle", source: "ho", paint: { "circle-radius": 4, "circle-color": "#fff", "circle-stroke-color": css("--bad"), "circle-stroke-width": 2.5 } });
    popupOn(m, "pts", p => `<b>${dt(+p.ts)}</b><br>${esc(p.tech)} · PCI ${p.pci ?? "—"}<br>RSRP <b class="mono">${p.rsrp ?? "—"}</b> dBm · RSRQ ${p.rsrq ?? "—"} · SINR ${p.sinr ?? "—"}<br>دقة GPS ${p.acc ?? "—"} m`);
    popupOn(m, "ho", p => `<b>تبديل خلية</b><br>${dt(+p.ts)}<br>من ${esc(p.from)}<br>إلى ${esc(p.to)}<br>RSRP ${p.rsrp ?? "—"} dBm`);
    fit(m, gj.features.map(f => f.geometry.coordinates));
  }

  async function coverage() {
    const o = await api("v1/overview");
    V().innerHTML = `<h1>خريطة التغطية</h1>
      <div class="card"><div class="filters noprint">
        <label class="f">المشغّل<select class="sel" id="cOp"><option value="">الكل</option>${o.operators.map(p => `<option value="${p.mcc}-${p.mnc}">${esc(opName(p.mcc, p.mnc))}</option>`).join("")}</select></label>
        <label class="f">التقنية<select class="sel" id="cTech"><option value="">الكل</option><option>LTE</option><option>NR</option><option>WCDMA</option><option>GSM</option></select></label>
        <label class="f">حجم المربع<select class="sel" id="cSize"><option value="75">75 m</option><option value="150" selected>150 m</option><option value="300">300 m</option><option value="1000">1 km</option></select></label>
        <label class="f">من تاريخ<input class="inp" id="cFrom" type="date"></label>
        <label class="f">إلى تاريخ<input class="inp" id="cTo" type="date"></label>
        <label class="f">الساعات (بغداد)<select class="sel" id="cHours"><option value="">كل اليوم</option><option value="7-10">الصباح 7–10</option><option value="11-16">الظهيرة 11–16</option><option value="17-23">الذروة المسائية 17–23</option><option value="0-6">الليل 0–6</option></select></label>
        <label class="f">اللون حسب<select class="sel" id="cMetric"><option value="rsrp">متوسط RSRP</option><option value="rsrp_min">أضعف RSRP</option></select></label>
        <button class="btn primary" id="cGo" type="button">تحديث</button>
      </div><div class="map" id="cMap"></div>${legend()}<p class="note" id="cNote"></p></div>`;
    const m = await makeMap($("cMap"));
    m.addSource("cov", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
    m.addLayer({ id: "cov", type: "fill", source: "cov", paint: { "fill-color": rsrpColorExpr("rsrp"), "fill-opacity": .7, "fill-outline-color": "#0002" } });
    popupOn(m, "cov", p => `متوسط RSRP <b class="mono">${p.rsrp}</b> dBm<br>أضعف ${p.rsrp_min} dBm<br>SINR ${p.sinr ?? "—"} dB<br>${p.n} قراءة`);
    let first = true;
    async function load() {
      const q = new URLSearchParams({ size: $("cSize").value });
      const [mcc, mnc] = ($("cOp").value || "-").split("-"); if (mcc) { q.set("mcc", mcc); q.set("mnc", mnc); }
      if ($("cTech").value) q.set("tech", $("cTech").value);
      if ($("cFrom").value) q.set("from", Date.parse($("cFrom").value + "T00:00:00+03:00"));
      if ($("cTo").value) q.set("to", Date.parse($("cTo").value + "T23:59:59+03:00"));
      if ($("cHours").value) { const [a, b] = $("cHours").value.split("-"); q.set("hour_from", a); q.set("hour_to", b); }
      const gj = await api("v1/coverage?" + q);
      m.setPaintProperty("cov", "fill-color", rsrpColorExpr($("cMetric").value));
      m.getSource("cov").setData(gj);
      $("cNote").textContent = `${gj.features.length} مربع بحجم ${gj.cell_m} m. المربعات ذات القراءات القليلة (أقل من 5) أقل موثوقية.`;
      if (first && gj.features.length) { fit(m, gj.features.flatMap(f => f.geometry.coordinates[0]), 15); first = false; }
    }
    $("cGo").onclick = () => load().catch(e => toast(e.message));
    await load();
  }

  async function cells() {
    const o = await api("v1/overview");
    V().innerHTML = `<h1>الأبراج والخلايا</h1>
      <div class="card"><div class="filters noprint">
        <label class="f">المشغّل<select class="sel" id="lOp"><option value="">الكل</option>${o.operators.map(p => `<option value="${p.mcc}-${p.mnc}">${esc(opName(p.mcc, p.mnc))}</option>`).join("")}</select></label>
        <label class="f">التقنية<select class="sel" id="lTech"><option value="">الكل</option><option>LTE</option><option>NR</option><option>WCDMA</option><option>GSM</option></select></label>
        <label class="f"><span><input type="checkbox" id="lRad" checked> دوائر الثقة</span></label>
      </div>
      <div class="map" id="lMap"></div>
      <div class="legend"><span><i style="background:var(--accent)"></i>موقع مقدّر من قياساتك</span><span><i style="background:#8e44ad"></i>موقع OpenCelliD</span><span>الدائرة: نصف قطر الثقة</span></div>
      <p class="note">الموقع تقدير نموذجي من قوة الإشارة وTiming Advance، وليس موقعاً مسجّلاً. يتحسن كلما قِست من اتجاهات متنوعة حول البرج. الخلايا التي تشترك في رقم eNB غالباً على البرج نفسه.</p></div>
      <div class="card" style="margin-top:14px"><div class="tablewrap" id="lTbl"></div></div>`;
    const m = await makeMap($("lMap"));
    ["rad", "est", "ocid"].forEach(s => m.addSource(s, { type: "geojson", data: { type: "FeatureCollection", features: [] } }));
    m.addLayer({ id: "rad", type: "fill", source: "rad", paint: { "fill-color": css("--accent"), "fill-opacity": .08, "fill-outline-color": css("--accent") } });
    m.addLayer({ id: "ocid", type: "circle", source: "ocid", paint: { "circle-radius": 5, "circle-color": "#8e44ad", "circle-stroke-color": "#fff", "circle-stroke-width": 1 } });
    m.addLayer({ id: "est", type: "circle", source: "est", paint: { "circle-radius": 7, "circle-color": css("--accent"), "circle-stroke-color": "#fff", "circle-stroke-width": 1.5 } });
    popupOn(m, "est", p => `<b>${esc(p.label)}</b><br>${esc(p.op)}<br>${p.n} قراءة · ثقة ±${p.r} m<br><a href="#/cell/${p.id}">تقرير الخلية</a>`);
    let first = true;
    async function load() {
      const q = new URLSearchParams(); const [mcc, mnc] = ($("lOp").value || "-").split("-");
      if (mcc) { q.set("mcc", mcc); q.set("mnc", mnc); } if ($("lTech").value) q.set("tech", $("lTech").value);
      const rows = await api("v1/cells?" + q);
      const loc = rows.filter(c => c.est_lat !== null && c.est_method !== "insufficient");
      const label = c => `${c.tech} ${c.enb !== undefined ? "eNB " + c.enb + "/" + c.sector : "CID " + c.cid} · PCI ${c.pci ?? "?"}`;
      m.getSource("est").setData({ type: "FeatureCollection", features: loc.map(c => ({ type: "Feature", geometry: { type: "Point", coordinates: [c.est_lon, c.est_lat] }, properties: { id: c.id, label: label(c), op: opName(c.mcc, c.mnc), n: c.est_n, r: Math.round(c.est_radius_m) } })) });
      m.getSource("rad").setData({ type: "FeatureCollection", features: $("lRad").checked ? loc.map(c => circle(c.est_lon, c.est_lat, c.est_radius_m)) : [] });
      m.getSource("ocid").setData({ type: "FeatureCollection", features: rows.filter(c => c.ocid_lat !== null).map(c => ({ type: "Feature", geometry: { type: "Point", coordinates: [c.ocid_lon, c.ocid_lat] }, properties: {} })) });
      if (first && loc.length) { fit(m, loc.map(c => [c.est_lon, c.est_lat]), 15); first = false; }
      const method = { "pathloss+ta": "فقد مسار + TA", pathloss: "نموذج فقد المسار", centroid: "مركز موزون", insufficient: "قراءات غير كافية" };
      $("lTbl").innerHTML = rows.length ? `<table><tr><th>الخلية</th><th>المشغّل</th><th>TAC</th><th>القراءات</th><th>متوسط الإشارة</th><th>الطريقة</th><th>الثقة</th><th>آخر ظهور</th></tr>
        ${rows.map(c => `<tr class="click" data-href="#/cell/${c.id}"><td class="n">${esc(label(c))}</td><td>${esc(opName(c.mcc, c.mnc))}</td><td class="n">${c.tac ?? "—"}</td><td class="n">${fmt(c.sample_count)}</td><td class="n">${fmt(c.avg_rsrp, 1)}</td><td>${esc(method[c.est_method] || "بانتظار الحساب")}</td><td class="n">${c.est_radius_m ? "±" + fmt(c.est_radius_m) + " m" : "—"}</td><td>${dt(c.last_seen)}</td></tr>`).join("")}</table>` : `<div class="empty">لا خلايا بعد</div>`;
      bindRows();
    }
    ["lOp", "lTech", "lRad"].forEach(id => $(id).onchange = () => load().catch(e => toast(e.message)));
    await load();
  }

  async function cell(id) {
    const r = await api(`v1/cells/${id}`), c = r.cell;
    const method = { "pathloss+ta": "ملاءمة نموذج فقد المسار مع قيود Timing Advance", pathloss: "ملاءمة نموذج فقد المسار", centroid: "مركز موزون بقوة الإشارة (قراءات قليلة)", insufficient: "قراءات غير كافية للتقدير" };
    V().innerHTML = `
      <div class="row spread"><h1>${esc(c.tech)} ${c.enb !== undefined ? "eNB " + c.enb + " · قطاع " + c.sector : "CID " + c.cid}</h1><button class="btn sm noprint" id="bPrint" type="button">طباعة / PDF</button></div>
      <p class="muted" style="margin-top:0">${esc(opName(c.mcc, c.mnc))} · MCC ${c.mcc} · MNC ${c.mnc} · TAC ${c.tac ?? "—"} · PCI ${c.pci ?? "—"} · ARFCN ${c.arfcn ?? "—"} · أول ظهور ${dt(c.first_seen)} · آخر ظهور ${dt(c.last_seen)}</p>
      <div class="tiles">
        <div class="tile"><span>القراءات</span><b>${fmt(c.sample_count)}</b></div>
        <div class="tile"><span>وسيط RSRP</span><b>${fmt(r.stats.rsrp.median)}<small>dBm</small></b></div>
        <div class="tile"><span>وسيط SINR</span><b>${fmt(r.stats.sinr.median, 1)}<small>dB</small></b></div>
        <div class="tile"><span>أبعد نقطة خدمة</span><b>${r.stats.max_service_distance_m ? km(r.stats.max_service_distance_m) : "—"}</b></div>
        <div class="tile"><span>نصف قطر الثقة</span><b>${c.est_radius_m ? "±" + fmt(c.est_radius_m) : "—"}<small>m</small></b></div>
        <div class="tile"><span>أس فقد المسار n</span><b>${fmt(c.est_exponent, 2)}</b></div>
      </div>
      <div class="grid g2" style="margin-top:14px">
        <div class="card span2"><h3>منطقة الخدمة الفعلية والموقع المقدّر</h3><div class="map" id="cMap"></div>${legend()}</div>
        <div class="card"><h3>طريقة التقدير</h3><dl>
          <dt class="muted">الطريقة</dt><dd>${esc(method[c.est_method] || "لم يُحسب بعد (يعمل كل 15 دقيقة)")}</dd>
          <dt class="muted">الإحداثيات</dt><dd class="mono" dir="ltr">${c.est_lat !== null ? c.est_lat.toFixed(6) + ", " + c.est_lon.toFixed(6) : "—"}</dd>
          <dt class="muted">قراءات TA</dt><dd>${fmt(r.stats.ta_samples)}</dd>
          <dt class="muted">OpenCelliD</dt><dd>${c.ocid_lat !== null ? `يبعد ${bd(km(r.ocid_offset_m))} عن تقديرنا (مدى القاعدة ${bd(km(c.ocid_range || 0))})` : "غير متوفر"}</dd></dl>
          <p class="note">أس فقد المسار قرب 2 يعني مساراً مكشوفاً، وبين 3 و4 بيئة حضرية بعوائق. هذا تقدير نموذجي وليس قياساً مباشراً للعائق.</p></div>
        <div class="card"><h3>الإشارة حسب المسافة من البرج</h3><canvas class="chart" id="dChart"></canvas></div>
      </div>`;
    $("bPrint").onclick = () => window.print();
    lineChart($("dChart"), [{ color: css("--accent"), data: r.by_distance.map(b => [b.from_m + 125, b.avg_rsrp]) }], { xLabel: x => km(x) });
    const m = await makeMap($("cMap"));
    m.addSource("fp", { type: "geojson", data: { type: "FeatureCollection", features: r.footprint.map(p => ({ type: "Feature", geometry: { type: "Point", coordinates: [p.lon, p.lat] }, properties: p })) } });
    m.addLayer({ id: "fp", type: "circle", source: "fp", paint: { "circle-radius": 5, "circle-color": rsrpColorExpr("rsrp"), "circle-opacity": .85 } });
    popupOn(m, "fp", p => `RSRP ${p.rsrp ?? "—"} dBm<br>${p.n} قراءة`);
    const pts = r.footprint.map(p => [p.lon, p.lat]);
    if (c.est_lat !== null) {
      m.addSource("r", { type: "geojson", data: circle(c.est_lon, c.est_lat, c.est_radius_m || 100) });
      m.addLayer({ id: "r", type: "fill", source: "r", paint: { "fill-color": css("--accent"), "fill-opacity": .12, "fill-outline-color": css("--accent") } });
      new maplibregl.Marker({ color: css("--accent") }).setLngLat([c.est_lon, c.est_lat]).setPopup(new maplibregl.Popup().setHTML("الموقع المقدّر")).addTo(m);
      pts.push([c.est_lon, c.est_lat]);
    }
    if (c.ocid_lat !== null) { new maplibregl.Marker({ color: "#8e44ad" }).setLngLat([c.ocid_lon, c.ocid_lat]).setPopup(new maplibregl.Popup().setHTML("OpenCelliD")).addTo(m); pts.push([c.ocid_lon, c.ocid_lat]); }
    fit(m, pts);
  }

  async function devices() {
    const rows = await api("v1/devices");
    V().innerHTML = `<h1>الأجهزة</h1>
      <div class="card"><div class="tablewrap">${rows.length ? `<table><tr><th>الاسم</th><th>الطراز</th><th>Android</th><th>إصدار التطبيق</th><th>الجلسات</th><th>آخر اتصال (UTC)</th><th>الحالة</th><th></th></tr>
        ${rows.map(d => `<tr><td>${esc(d.name)}</td><td>${esc(d.model)}</td><td>${esc(d.android)}</td><td class="n">${esc(d.app_version)}</td><td class="n">${d.sessions}</td><td class="n">${esc(d.last_seen || "—")}</td>
          <td>${d.revoked ? `<span class="chip off">ملغى</span>` : `<span class="chip">نشط</span>`}</td>
          <td class="row"><button class="btn sm" data-act="rename" data-id="${d.id}" data-name="${esc(d.name)}" type="button">تسمية</button>
          ${d.revoked ? `<button class="btn sm" data-act="restore" data-id="${d.id}" type="button">استعادة</button>` : `<button class="btn sm danger" data-act="revoke" data-id="${d.id}" type="button">إلغاء</button>`}</td></tr>`).join("")}</table>` : `<div class="empty">لا أجهزة مسجّلة بعد</div>`}</div></div>
      <div class="card" style="margin-top:14px"><h3>إضافة جهاز</h3><p>في تطبيق Android: الإعدادات ← المزامنة مع الخادم. العنوان <b class="mono" dir="ltr">${esc(location.origin)}</b> ورمز التسجيل هو <span class="mono">enroll_key</span> في ملف config.php. إلغاء جهاز يوقف قبول بياناته فوراً، وتبقى بياناته السابقة.</p></div>`;
    V().querySelectorAll("button[data-act]").forEach(b => b.onclick = async () => {
      const id = b.dataset.id, act = b.dataset.act;
      if (act === "rename") { const n = prompt("الاسم الجديد", b.dataset.name); if (!n) return; await api(`v1/devices/${id}/rename`, { method: "POST", body: { name: n } }); }
      else { if (act === "revoke" && !confirm("إلغاء هذا الجهاز؟ لن يُقبل منه أي رفع بعد الآن.")) return; await api(`v1/devices/${id}/${act}`, { method: "POST" }); }
      toast("تم"); devices();
    });
  }

  async function settings() {
    V().innerHTML = `<h1>الإعدادات</h1><div class="grid g2">
      <div class="card"><h3>تغيير كلمة المرور</h3><form id="pwForm" class="grid">
        <label class="f">الحالية<input class="inp" id="pCur" type="password" dir="ltr" autocomplete="current-password" required></label>
        <label class="f">الجديدة (10 أحرف على الأقل)<input class="inp" id="pNew" type="password" dir="ltr" autocomplete="new-password" minlength="10" required></label>
        <button class="btn primary" type="submit">حفظ</button></form></div>
      <div class="card"><h3>العرض والجلسة</h3><div class="grid">
        <label class="f">السمة<select class="sel" id="sTheme"><option value="">تلقائي</option><option value="light">فاتح</option><option value="dark">داكن</option></select></label>
        <button class="btn" id="bOut" type="button">تسجيل الخروج</button></div></div>
      <div class="card span2"><h3>المهام المجدولة (cPanel ← Cron Jobs)</h3><p class="note">تقدير مواقع الأبراج يعمل كل 15 دقيقة، والتنظيف مرة يومياً. الأوامر في ملف README ضمن المستودع.</p></div></div>`;
    $("sTheme").value = localGet("theme") || "";
    $("sTheme").onchange = e => { localSet("theme", e.target.value); applyTheme(); };
    $("pwForm").onsubmit = async e => { e.preventDefault(); try { await api("v1/admin/password", { method: "POST", body: { current: $("pCur").value, new: $("pNew").value } }); toast("تم تغيير كلمة المرور"); e.target.reset(); } catch (err) { toast(err.message); } };
    $("bOut").onclick = async () => { await api("v1/admin/logout", { method: "POST" }); showAuth(false); };
  }

  // ------------------------------------------------------------------ boot
  function localGet(k) { try { return localStorage.getItem("ss_" + k); } catch (_) { return null; } }
  function localSet(k, v) { try { v ? localStorage.setItem("ss_" + k, v) : localStorage.removeItem("ss_" + k); } catch (_) { } }
  function applyTheme() { const t = localGet("theme"); t ? document.documentElement.setAttribute("data-theme", t) : document.documentElement.removeAttribute("data-theme"); }
  applyTheme();
  (async () => {
    try {
      const res = await fetch(apiUrl("v1/admin/status"), { credentials: "same-origin" });
      const body = await res.text();
      let st;
      try { st = JSON.parse(body); } catch (_) { throw new Error(`HTTP ${res.status}: ${body.trim().slice(0, 300) || "رد فارغ من الخادم (غالباً خطأ PHP؛ راجع إصدار PHP وملف error_log)"}`); }
      if (st.error) throw new Error(st.message || st.error);
      if (!st.setup_done) showAuth(true); else if (!st.logged_in) showAuth(false); else enter();
    } catch (e) {
      document.body.innerHTML = `<div class="auth"><div class="card authcard"><h1>تعذّر الاتصال بالخادم</h1><p class="err">${esc(e.message)}</p><p class="muted">تأكد من رفع مجلد api ومجلد signalscope، ومن وجود config.php.</p></div></div>`;
    }
  })();
})();
