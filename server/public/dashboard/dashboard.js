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

  // ------------------------------------------------------------------ obstacles helpers
  const OBS = [["terrain", "التضاريس"], ["buildings", "المباني"], ["indoor", "داخل مبنى"], ["residual", "غير مفسَّر (خفوت أو تداخل)"]];
  const obsColor = k => css("--o-" + k);
  function obsCard(o) {
    if (!o || !o.n) return `<div class="card"><h3>تحليل العوائق</h3><p class="note">لم تُحلَّل قراءات هذه الجلسة بعد. يعمل التحليل تلقائياً كل 15 دقيقة بعد أن يُقدَّر موقع البرج (يلزم نصف قطر ثقة أقل من 1.5 km). يمكنك تشغيله فوراً من صفحة <a href="#/obstacles">العوائق</a>.</p></div>`;
    const tot = Math.max(o.excess, 0.1);
    return `<div class="card"><h3>تحليل العوائق</h3>
      <p>متوسط الفقد الزائد <b class="mono">${bd(fmt(o.excess, 1) + " dB")}</b> على ${fmt(o.n)} قراءة، منه:</p>
      <div class="stack">${OBS.map(([k, l]) => o[k] > 0 ? `<div style="width:${100 * o[k] / tot}%;background:${obsColor(k)}" title="${l}: ${o[k]} dB">${o[k] / tot > 0.12 ? fmt(o[k], 1) : ""}</div>` : "").join("")}</div>
      <div class="legend">${OBS.map(([k, l]) => `<span><i style="background:${obsColor(k)}"></i>${l}: ${bd(fmt(o[k], 1) + " dB")}</span>`).join("")}</div>
      <p class="note">${fmt(o.indoor_pct, 0)}% من القراءات داخل مبانٍ، و${fmt(o.blocked_pct, 0)}% مسارها محجوب بمبنى.${o.terrain_data ? "" : " بيانات التضاريس غير متاحة بعد."} هذا <b>تقدير نموذجي</b> (ITU-R P.526 للحيود و P.2109 لدخول المباني، مع مباني OpenStreetMap وتضاريس SRTM) وليس قياساً مباشراً للعائق.</p></div>`;
  }

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
      if (opts.dots) { g.fillStyle = s.color; for (const [x, y] of s.data) if (isNum(y)) { g.beginPath(); g.arc(fx(x), fy(y), 3.5, 0, Math.PI * 2); g.fill(); } }
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
      const fn = { overview, sessions, session, coverage, cells, cell, obstacles, compare, devices, settings }[page] || overview;
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
        <div class="span2">${obsCard(r.obstacles)}</div>
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

  async function obstacles() {
    const [o, stt] = await Promise.all([api("v1/overview"), api("v1/obstacles/status")]);
    const sum = stt.summary || {};
    V().innerHTML = `<div class="row spread" style="margin-bottom:6px"><h1>العوائق</h1><button class="btn sm noprint" id="oRun" type="button">تشغيل التحليل الآن</button></div>
      <p class="muted" style="margin-top:0" id="oStat"></p>
      <div class="grid g2">
        <div class="card span2"><div class="filters noprint">
          <label class="f">المشغّل<select class="sel" id="oOp"><option value="">الكل</option>${o.operators.map(p => `<option value="${p.mcc}-${p.mnc}">${esc(opName(p.mcc, p.mnc))}</option>`).join("")}</select></label>
          <label class="f">حجم المربع<select class="sel" id="oSize"><option value="100">100 m</option><option value="150" selected>150 m</option><option value="300">300 m</option></select></label>
        </div><div class="map" id="oMap"></div>
        <div class="legend">${OBS.map(([k, l]) => `<span><i style="background:${obsColor(k)}"></i>${l}</span>`).join("")}<span><i style="background:${obsColor("clear")}"></i>بلا فقد يُذكر (&lt; 3 dB)</span><span>كثافة اللون = حجم الفقد الزائد</span></div></div>
        <div class="card" id="oSum"></div>
        <div class="card"><h3>أسوأ المناطق</h3><div class="tablewrap" id="oWorst"></div></div>
      </div>`;
    const showStatus = s => { $("oStat").innerHTML = `حُلِّلت ${fmt(s.analyzed)} من ${fmt(s.samples_with_location)} قراءة · ${fmt(s.cells_eligible)} خلية موقعها مقدَّر بدقة كافية · ${fmt(s.buildings)} مبنى من OpenStreetMap في ${fmt(s.osm_tiles)} مربع${s.osm_errors ? ` (${s.osm_errors} مربع تعذّر جلبه)` : ""} · تضاريس: ${s.dem_tiles.length ? esc(s.dem_tiles.join(", ")) : "لم تُنزَّل بعد"}`; };
    showStatus(stt);
    $("oSum").innerHTML = obsCard(sum).replace('<div class="card"><h3>تحليل العوائق</h3>', "<h3>الإجمالي</h3>").replace(/<\/div>$/, "");
    const m = await makeMap($("oMap"));
    m.addSource("g", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
    m.addLayer({ id: "g", type: "fill", source: "g", paint: {
      "fill-color": ["match", ["get", "dominant"], "terrain", obsColor("terrain"), "buildings", obsColor("buildings"), "indoor", obsColor("indoor"), "residual", obsColor("residual"), obsColor("clear")],
      "fill-opacity": ["interpolate", ["linear"], ["get", "excess"], 0, 0.35, 10, 0.6, 25, 0.85], "fill-outline-color": "#0003" } });
    popupOn(m, "g", p => `فقد زائد <b class="mono">${p.excess}</b> dB · إشارة ${p.signal} dBm<br>تضاريس ${p.terrain} · مبانٍ ${p.buildings} · داخل مبنى ${p.indoor} · غير مفسَّر ${p.residual} dB<br>${p.n} قراءة`);
    let first = true;
    async function load() {
      const q = new URLSearchParams({ size: $("oSize").value }); const [mcc, mnc] = ($("oOp").value || "-").split("-"); if (mcc) { q.set("mcc", mcc); q.set("mnc", mnc); }
      const gj = await api("v1/obstacles?" + q);
      m.getSource("g").setData(gj);
      if (first && gj.features.length) { fit(m, gj.features.flatMap(f => f.geometry.coordinates[0]), 15); first = false; }
      const worst = gj.features.map(f => f.properties).filter(p => p.n >= 3).sort((a, b) => b.excess - a.excess).slice(0, 10);
      const lbl = Object.fromEntries(OBS.concat([["clear", "بلا فقد"]]));
      $("oWorst").innerHTML = worst.length ? `<table><tr><th>الموقع</th><th>فقد زائد</th><th>السبب الغالب</th><th>قراءات</th></tr>${worst.map(p => `<tr class="click" data-ll="${p.lon},${p.lat}"><td class="n">${p.lat.toFixed(4)}, ${p.lon.toFixed(4)}</td><td class="n">${fmt(p.excess, 1)} dB</td><td><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:${obsColor(p.dominant)}"></i> ${esc(lbl[p.dominant])}</td><td class="n">${p.n}</td></tr>`).join("")}</table>` : `<div class="empty">لا توجد قراءات محلَّلة بعد</div>`;
      $("oWorst").querySelectorAll("tr[data-ll]").forEach(tr => tr.onclick = () => m.flyTo({ center: tr.dataset.ll.split(",").map(Number), zoom: 16 }));
    }
    ["oOp", "oSize"].forEach(id => $(id).onchange = () => load().catch(e => toast(e.message)));
    $("oRun").onclick = async () => {
      $("oRun").disabled = true; $("oRun").textContent = "جارٍ التحليل…";
      try { const r = await api("v1/obstacles/run", { method: "POST" }); showStatus(r.status); toast(`حُلِّلت ${r.run.samples} قراءة جديدة`); await load(); }
      catch (e) { toast(e.message); }
      finally { $("oRun").disabled = false; $("oRun").textContent = "تشغيل التحليل الآن"; }
    };
    await load();
  }

  // ------------------------------------------------------------------ comparison reports
  const CMP_A = "#0284C7", CMP_B = "#D97706";
  const CMP_MODES = {
    ba:    { ar: "قبل وبعد",     hint: "فترتان زمنيتان للمكان نفسه، مثلاً قبل إضافة برج وبعده.", group: ["from", "to"], names: ["قبل", "بعد"] },
    op:    { ar: "المشغّلون",    hint: "مشغّلان في الفترة والمكان نفسيهما.", group: ["op"], names: null },
    hours: { ar: "أوقات اليوم",  hint: "ساعات الذروة مقابل الساعات الهادئة (بتوقيت البصرة).", group: ["hours"], names: null },
    custom:{ ar: "مخصص",         hint: "كل شروط المجموعتين حرة.", group: ["op", "tech", "from", "to", "hours", "session"], names: ["المجموعة A", "المجموعة B"] },
  };
  const CMP_FIELDS = ["op", "tech", "from", "to", "hours", "session"];
  async function compare() {
    const [o, ss] = await Promise.all([api("v1/overview"), api("v1/sessions?limit=200")]);
    let mode = localGet("cmp_mode") || "ba";
    const opOpts = `<option value="">الكل</option>` + o.operators.map(p => `<option value="${p.mcc}-${p.mnc}">${esc(opName(p.mcc, p.mnc))} (${p.mcc}-${String(p.mnc).padStart(2, "0")})</option>`).join("");
    const hOpts = `<option value="">—</option>` + Array.from({ length: 24 }, (_, h) => `<option value="${h}">${String(h).padStart(2, "0")}:00</option>`).join("");
    const sOpts = `<option value="">كل الجلسات</option>` + ss.map(s => `<option value="${s.id}">${esc(s.name || "جلسة " + s.id)} · ${esc(s.device_name)}</option>`).join("");
    const fieldHtml = (p) => `
      <label class="f" data-f="op">المشغّل<select class="sel" id="${p}op">${opOpts}</select></label>
      <label class="f" data-f="tech">التقنية<select class="sel" id="${p}tech"><option value="">الكل</option><option>LTE</option><option>NR</option></select></label>
      <label class="f" data-f="from">من تاريخ<input class="inp" type="date" id="${p}from"></label>
      <label class="f" data-f="to">إلى تاريخ<input class="inp" type="date" id="${p}to"></label>
      <label class="f" data-f="hours">من الساعة<select class="sel" id="${p}hf">${hOpts}</select></label>
      <label class="f" data-f="hours">إلى الساعة<select class="sel" id="${p}ht">${hOpts}</select></label>
      <label class="f" data-f="session">الجلسة<select class="sel" id="${p}session">${sOpts}</select></label>`;
    V().innerHTML = `
      <div class="row spread" style="margin-bottom:6px"><h1>المقارنة</h1><button class="btn sm noprint" id="cPrint" type="button" hidden>طباعة / PDF</button></div>
      <p class="muted" style="margin-top:0">قارن مجموعتين من القراءات. المقارنة العادلة تتم في <b>الأماكن المشتركة فقط</b>، لأن الفرق الإجمالي قد يعكس اختلاف أماكن القياس لا الشبكة.</p>
      <div class="card noprint">
        <div class="row" id="cModes" style="margin-bottom:10px">${Object.entries(CMP_MODES).map(([k, m]) => `<button class="btn sm" type="button" data-m="${k}">${m.ar}</button>`).join("")}</div>
        <p class="note" id="cHint"></p>
        <div class="grid g2" style="gap:10px">
          <div class="card" style="border-color:${CMP_A};box-shadow:none"><h3 style="color:${CMP_A}">المجموعة A</h3><div class="filters" style="margin:0">${fieldHtml("a")}</div></div>
          <div class="card" style="border-color:${CMP_B};box-shadow:none"><h3 style="color:${CMP_B}">المجموعة B</h3><div class="filters" style="margin:0">${fieldHtml("b")}</div></div>
        </div>
        <h3 style="margin-top:12px">شروط مشتركة</h3>
        <div class="filters" id="cShared">${fieldHtml("s")}
          <label class="f">حجم المربع<select class="sel" id="cSize"><option value="100">100 m</option><option value="150" selected>150 m</option><option value="300">300 m</option><option value="500">500 m</option></select></label>
        </div>
        <div class="row"><button class="btn primary" id="cGo" type="button">قارن</button><button class="btn" id="cBox" type="button" hidden>قارن داخل المنطقة الظاهرة في الخريطة</button></div>
      </div>
      <div id="cOut"></div>`;
    const setMode = m => {
      mode = m; localSet("cmp_mode", m);
      $("cModes").querySelectorAll("button").forEach(b => b.classList.toggle("primary", b.dataset.m === m));
      $("cHint").textContent = CMP_MODES[m].hint;
      const g = CMP_MODES[m].group;
      for (const p of ["a", "b"]) V().querySelectorAll(`#${p}op, #${p}tech, #${p}from, #${p}to, #${p}hf, #${p}ht, #${p}session`).forEach(el => { el.closest("label").hidden = !g.includes(el.closest("label").dataset.f); });
      $("cShared").querySelectorAll("label[data-f]").forEach(l => { l.hidden = g.includes(l.dataset.f) || l.dataset.f === "session"; });
    };
    $("cModes").querySelectorAll("button").forEach(b => b.onclick = () => setMode(b.dataset.m));
    // قيم افتراضية مفيدة
    const today = new Date(), iso = d => new Date(d.getTime() + 3 * 3600e3).toISOString().slice(0, 10);
    $("afrom").value = iso(new Date(today - 60 * 864e5)); $("ato").value = iso(new Date(today - 30 * 864e5));
    $("bfrom").value = iso(new Date(today - 30 * 864e5 + 864e5)); $("bto").value = iso(today);
    $("ahf").value = "17"; $("aht").value = "23"; $("bhf").value = "0"; $("bht").value = "6";
    if (o.operators[0]) $("aop").value = `${o.operators[0].mcc}-${o.operators[0].mnc}`;
    if (o.operators[1]) $("bop").value = `${o.operators[1].mcc}-${o.operators[1].mnc}`;
    setMode(CMP_MODES[mode] ? mode : "ba");

    const read = (p, fields) => {
      const out = {}, v = id => $(p + id) && $(p + id).value;
      if (fields.includes("op") && v("op")) { const [mcc, mnc] = v("op").split("-"); out.mcc = +mcc; out.mnc = +mnc; }
      if (fields.includes("tech") && v("tech")) out.tech = v("tech");
      if (fields.includes("from") && v("from")) out.from = Date.parse(v("from") + "T00:00:00+03:00");
      if (fields.includes("to") && v("to")) out.to = Date.parse(v("to") + "T23:59:59+03:00");
      if (fields.includes("hours") && v("hf") !== "" && v("ht") !== "") { out.hour_from = +v("hf"); out.hour_to = +v("ht"); }
      if (fields.includes("session") && v("session")) out.session = +v("session");
      return out;
    };
    const label = (p, g) => {
      const m = CMP_MODES[mode];
      if (m.names) return m.names[p === "a" ? 0 : 1];
      if (mode === "op") { const sel = $(p + "op"); return sel.value ? sel.options[sel.selectedIndex].text.replace(/ \(.*\)$/, "") : "كل المشغّلين"; }
      if (mode === "hours") return g.hour_from !== undefined ? `${String(g.hour_from).padStart(2, "0")}:00–${String(g.hour_to).padStart(2, "0")}:59` : "كل الساعات";
      return p.toUpperCase();
    };
    let map = null;
    async function run(bbox) {
      const g = CMP_MODES[mode].group, sharedFields = CMP_FIELDS.filter(f => !g.includes(f) && f !== "session");
      const a = read("a", g), b = read("b", g), shared = Object.assign(read("s", sharedFields), { size: +$("cSize").value });
      if (bbox) shared.bbox = bbox;
      $("cGo").disabled = true; $("cGo").textContent = "جارٍ الحساب…";
      try {
        const r = await api("v1/compare", { method: "POST", body: { a, b, shared } });
        renderCompare(r, label("a", a), label("b", b), !!bbox);
      } catch (e) { toast(e.message); }
      finally { $("cGo").disabled = false; $("cGo").textContent = "قارن"; }
    }
    $("cGo").onclick = () => run(null);
    $("cBox").onclick = () => { if (!map) return; const bb = map.getBounds(); run([bb.getWest(), bb.getSouth(), bb.getEast(), bb.getNorth()].map(v => v.toFixed(5)).join(",")); };
    $("cPrint").onclick = () => window.print();

    async function renderCompare(r, la, lb, boxed) {
      const A = r.a, B = r.b, P = r.paired;
      const dl = (x, y, d = 1, higherBetter = true) => {
        if (!isNum(x) || !isNum(y)) return "—";
        const v = y - x, good = higherBetter ? v > 0 : v < 0, s = (v > 0 ? "+" : "") + fmt(v, d);
        return Math.abs(v) < Math.pow(10, -d) ? `<span class="mono">0</span>` : `<b class="mono" style="color:${good ? "var(--good,#16a34a)" : "var(--bad,#dc2626)"}">${bd(s)}</b>`;
      };
      let verdict, level;
      if (!A.samples || !B.samples) { level = "bad"; verdict = `لا توجد قراءات في ${!A.samples ? "«" + esc(la) + "»" : "«" + esc(lb) + "»"} بهذه الشروط. وسّع الفترة أو غيّر المرشّحات.`; }
      else if (P.squares >= 5) {
        const d = P.median_diff, better = d > 0, sig = P.p_value !== null && P.p_value < 0.05;
        level = Math.abs(d) < 3 ? "good" : (better ? "good" : "warn");
        verdict = `في <b>${fmt(P.squares)}</b> مربعاً مشتركاً (${P.size_m} م)، «${esc(lb)}» ${Math.abs(d) < 0.5 ? "مماثل لـ" : better ? "أقوى من" : "أضعف من"} «${esc(la)}» بوسيط فرق <b>${bd((d > 0 ? "+" : "") + fmt(d, 1) + " dB")}</b>: تحسّن في ${P.improved} مربعاً، وساء في ${P.worse}، وتقارب (أقل من 3 dB) في ${P.same}. `
          + (sig ? `الفرق <b>ذو دلالة إحصائية</b> (${bd(P.p_value < 0.001 ? "p < 0.001" : "p = " + fmt(P.p_value, 3))}).` : `الفرق <b>ليس ذا دلالة إحصائية كافية</b> (p = ${bd(fmt(P.p_value ?? 1, 3))})، فقد يكون عشوائياً.`);
      } else {
        level = "warn";
        const d = isNum(A.rsrp.median) && isNum(B.rsrp.median) ? B.rsrp.median - A.rsrp.median : null;
        verdict = `الأماكن المشتركة بين المجموعتين قليلة (${P.squares} مربع)، فلا تصح مقارنة عادلة. الفرق الإجمالي في وسيط RSRP ${d === null ? "—" : bd((d > 0 ? "+" : "") + fmt(d, 1) + " dB")} قد يعكس <b>اختلاف أماكن القياس لا الشبكة</b>. سجّل المجموعتين في المسار نفسه، أو كبّر حجم المربع.`;
      }
      const per = g => g.from ? `${new Date(g.from).toLocaleDateString("ar-IQ")} – ${new Date(g.to).toLocaleDateString("ar-IQ")}` : "—";
      const row = (l, a, b, d) => `<tr><td>${l}</td><td class="n">${a}</td><td class="n">${b}</td><td class="n">${d}</td></tr>`;
      const ob = (g) => g.obstacles && g.obstacles.n ? fmt(g.obstacles.excess, 1) + " dB" : "—";
      $("cOut").innerHTML = `
        <div class="card" style="margin-top:14px"><ul class="diag"><li class="${level}">${verdict}</li></ul></div>
        <div class="grid g2" style="margin-top:14px">
          <div class="card span2"><h3>المقاييس</h3><div class="tablewrap"><table>
            <tr><th>المقياس</th><th style="color:${CMP_A}">A · ${esc(la)}</th><th style="color:${CMP_B}">B · ${esc(lb)}</th><th>الفرق (B − A)</th></tr>
            ${row("القراءات", fmt(A.samples), fmt(B.samples), "")}
            ${row("الجلسات · الأجهزة", `${A.sessions} · ${A.devices}`, `${B.sessions} · ${B.devices}`, "")}
            ${row("الفترة الفعلية", per(A), per(B), "")}
            ${row("وسيط RSRP (dBm)", fmt(A.rsrp.median), fmt(B.rsrp.median), dl(A.rsrp.median, B.rsrp.median, 0))}
            ${row("أضعف 10% (P10)", fmt(A.rsrp.p10), fmt(B.rsrp.p10), dl(A.rsrp.p10, B.rsrp.p10, 0))}
            ${row("أفضل 10% (P90)", fmt(A.rsrp.p90), fmt(B.rsrp.p90), dl(A.rsrp.p90, B.rsrp.p90, 0))}
            ${row("وسيط SINR (dB)", fmt(A.sinr.median, 1), fmt(B.sinr.median, 1), dl(A.sinr.median, B.sinr.median, 1))}
            ${row("وسيط RSRQ (dB)", fmt(A.rsrq.median), fmt(B.rsrq.median), dl(A.rsrq.median, B.rsrq.median, 0))}
            ${row("تغطية جيدة فأفضل (≥ −90)", A.good_pct === null ? "—" : fmt(A.good_pct, 1) + "%", B.good_pct === null ? "—" : fmt(B.good_pct, 1) + "%", dl(A.good_pct, B.good_pct, 1))}
            ${row("انقطاع الخدمة", A.no_service_pct === null ? "—" : fmt(A.no_service_pct, 1) + "%", B.no_service_pct === null ? "—" : fmt(B.no_service_pct, 1) + "%", dl(A.no_service_pct, B.no_service_pct, 1, false))}
            ${row("الخلايا المخدِّمة", fmt(A.cells), fmt(B.cells), "")}
            ${row("متوسط الفقد الزائد (العوائق)", ob(A), ob(B), "")}
          </table></div><p class="note">مقاييس الإشارة من RSRP لشبكات LTE و5G. «الفرق» لونه أخضر إذا كانت B أفضل.</p></div>
          <div class="card"><h3>توزيع RSRP</h3><canvas class="chart" id="cHist"></canvas>
            <div class="legend"><span><i style="background:${CMP_A}"></i>A · ${esc(la)}</span><span><i style="background:${CMP_B}"></i>B · ${esc(lb)}</span><span>المحور الأفقي dBm، والعمودي % من القراءات</span></div></div>
          <div class="card"><h3>متوسط RSRP حسب ساعة اليوم</h3><canvas class="chart" id="cHours"></canvas>
            <div class="legend"><span><i style="background:${CMP_A}"></i>A</span><span><i style="background:${CMP_B}"></i>B</span><span>بتوقيت البصرة</span></div></div>
          <div class="card span2"><h3>الفرق في الأماكن المشتركة (B − A)</h3><div class="map" id="cMap"></div>
            <div class="legend"><span><i style="background:#16a34a"></i>B أقوى بـ 10 dB فأكثر</span><span><i style="background:#86efac"></i>أقوى بـ 3</span><span><i style="background:#cbd5e1"></i>متقارب</span><span><i style="background:#fca5a5"></i>أضعف بـ 3</span><span><i style="background:#dc2626"></i>أضعف بـ 10 فأكثر</span></div>
            <p class="note">${boxed ? "المقارنة محصورة في المنطقة التي كانت ظاهرة في الخريطة. " : ""}كل مربع يحتاج ${r.notes.min_per_square} قراءات على الأقل من كل مجموعة.</p></div>
        </div>`;
      $("cPrint").hidden = false; $("cBox").hidden = false;
      lineChart($("cHist"), [{ color: CMP_A, data: A.hist }, { color: CMP_B, data: B.hist }], { yMin: 0, xLabel: x => String(Math.round(x)) });
      const hrs = g => Array.from({ length: 24 }, (_, h) => { const x = g.hours.find(v => v.h === h); return [h, x ? x.rsrp : null]; });
      lineChart($("cHours"), [{ color: CMP_A, data: hrs(A) }, { color: CMP_B, data: hrs(B) }], { dots: true, xLabel: x => String(Math.round(x)).padStart(2, "0") });
      if (map) { try { map.remove(); } catch (_) { } maps.splice(maps.indexOf(map), 1); }
      map = await makeMap($("cMap"));
      map.addSource("d", { type: "geojson", data: r.grid });
      map.addLayer({ id: "d", type: "fill", source: "d", paint: {
        "fill-color": ["interpolate", ["linear"], ["get", "d"], -10, "#dc2626", -3, "#fca5a5", 0, "#cbd5e1", 3, "#86efac", 10, "#16a34a"],
        "fill-opacity": 0.78, "fill-outline-color": "#0003" } });
      popupOn(map, "d", p => `الفرق <b class="mono">${p.d > 0 ? "+" : ""}${p.d}</b> dB<br>A: ${p.ra} dBm (${p.na} قراءة)<br>B: ${p.rb} dBm (${p.nb} قراءة)`);
      if (r.grid.features.length) fit(map, r.grid.features.flatMap(f => f.geometry.coordinates[0]), 15);
    }
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
        <label class="f">السمة<select class="sel" id="sTheme"><option value="light">نهاري (ذهبي)</option><option value="dark">ليلي (كحلي)</option></select></label>
        <button class="btn" id="bOut" type="button">تسجيل الخروج</button></div></div>
      <div class="card span2"><h3>المهام المجدولة (cPanel ← Cron Jobs)</h3><p class="note">تقدير مواقع الأبراج يعمل كل 15 دقيقة، والتنظيف مرة يومياً. الأوامر في ملف README ضمن المستودع.</p></div></div>`;
    $("sTheme").value = window.AMU ? AMU.get() : (localGet("theme") || "light");
    $("sTheme").onchange = e => { if (window.AMU) AMU.set(e.target.value); else { localSet("theme", e.target.value); applyTheme(); } };
    $("pwForm").onsubmit = async e => { e.preventDefault(); try { await api("v1/admin/password", { method: "POST", body: { current: $("pCur").value, new: $("pNew").value } }); toast("تم تغيير كلمة المرور"); e.target.reset(); } catch (err) { toast(err.message); } };
    $("bOut").onclick = async () => { await api("v1/admin/logout", { method: "POST" }); showAuth(false); };
  }

  // ------------------------------------------------------------------ boot
  function localGet(k) { try { return localStorage.getItem("ss_" + k); } catch (_) { return null; } }
  function localSet(k, v) { try { v ? localStorage.setItem("ss_" + k, v) : localStorage.removeItem("ss_" + k); } catch (_) { } }
  function applyTheme() {
    if (window.AMU) return;   // السمة تُدار من brand/amu.js (مشتركة مع البوابة والواجهتين الأخريين)
    const t = localGet("theme"); t ? document.documentElement.setAttribute("data-theme", t) : document.documentElement.removeAttribute("data-theme");
  }
  applyTheme();
  // ألوان الخرائط والرسوم تُقرأ عند الرسم: نعيد رسم الصفحة الحالية عند تغيّر السمة
  window.addEventListener("amu-theme", () => { if (!$("shell").hidden) route(); });
  (async () => {
    try {
      const res = await fetch(apiUrl("v1/admin/status"), { credentials: "same-origin" });
      const body = await res.text();
      let st;
      try { st = JSON.parse(body); } catch (_) { throw new Error(`HTTP ${res.status}: ${body.trim().slice(0, 300) || "رد فارغ من الخادم (غالباً خطأ PHP؛ راجع إصدار PHP وملف error_log)"}`); }
      if (st.error) throw new Error(st.message || st.error);
      if (!st.setup_done) showAuth(true); else if (!st.logged_in) showAuth(false); else enter();
    } catch (e) {
      document.body.innerHTML = `<div class="auth"><div class="card authcard"><h1>تعذّر الاتصال بالخادم</h1><p class="err">${esc(e.message)}</p><p class="muted">افتح صفحة الفحص لمعرفة السبب: <a href="${new URL("../api/check.php", location.href).href}" target="_blank" rel="noopener">api/check.php</a></p></div></div>`;
    }
  })();
})();
