/* =====================================================================
   SignalScope Engine — pure logic, no DOM.
   Radio classification, 3GPP channel-number math, propagation models,
   diagnosis rules, Wi-Fi channel planning, CSV I/O, demo data.
   Runs in the browser (window.SSEngine) and in Node (module.exports).
   ===================================================================== */
(function (root) {
  "use strict";
  const C0 = 299792458;
  const log10 = Math.log10;
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const isNum = v => typeof v === "number" && isFinite(v);
  const db2lin = d => Math.pow(10, d / 10);
  const lin2db = l => 10 * log10(Math.max(l, 1e-30));

  /* ---------------------------------------------------------------
     1. QUALITY CLASSES  (higher value = better for every metric)
     Bounds are the lower edge of: excellent, good, fair, poor; below = bad
     --------------------------------------------------------------- */
  const TH = {
    rsrp: [-80, -90, -100, -110],   // LTE RSRP / NR SS-RSRP (dBm)
    rsrq: [-10, -13, -16, -20],     // LTE RSRQ / NR SS-RSRQ (dB)
    sinr: [20, 13, 5, 0],           // LTE RS-SINR / NR SS-SINR (dB)
    rscp: [-75, -85, -95, -105],    // WCDMA RSCP (dBm)
    ecno: [-6, -10, -14, -18],      // WCDMA Ec/No (dB)
    gsm:  [-70, -80, -90, -100],    // GSM RSSI (dBm)
    wifi: [-50, -60, -70, -80]      // Wi-Fi RSSI (dBm)
  };
  const CLASSES = [
    { k: "ex",   ar: "ممتاز",  score: 100 },
    { k: "good", ar: "جيد",    score: 80 },
    { k: "fair", ar: "متوسط",  score: 55 },
    { k: "poor", ar: "ضعيف",   score: 30 },
    { k: "bad",  ar: "سيئ",    score: 8 }
  ];
  function classify(metric, v) {
    if (!isNum(v) || !TH[metric]) return { k: "na", ar: "غير متاح", score: null };
    const b = TH[metric];
    for (let i = 0; i < b.length; i++) if (v >= b[i]) return CLASSES[i];
    return CLASSES[4];
  }
  const RANGES = {
    rsrp: [-140, -44], rsrq: [-24, -3], sinr: [-10, 30], rscp: [-120, -25],
    ecno: [-24, 0], gsm: [-113, -51], wifi: [-95, -30]
  };

  /* ---------------------------------------------------------------
     2. 3GPP CHANNEL NUMBERS
     LTE: TS 36.101 Table 5.7.3-1   F_DL = F_DL_low + 0.1 (N_DL − N_Offs-DL)
     NR : TS 38.104 §5.4.2.1        global frequency raster
     UMTS: TS 25.101 §5.4.4          GSM: TS 45.005 §2
     --------------------------------------------------------------- */
  // [band, F_DL_low MHz, N_offs, N_max, F_UL_low MHz | null, mode, common name]
  const LTE = [
    [1, 2110, 0, 599, 1920, "FDD", "2100"], [2, 1930, 600, 1199, 1850, "FDD", "PCS 1900"],
    [3, 1805, 1200, 1949, 1710, "FDD", "1800"], [4, 2110, 1950, 2399, 1710, "FDD", "AWS-1"],
    [5, 869, 2400, 2649, 824, "FDD", "850"], [7, 2620, 2750, 3449, 2500, "FDD", "2600"],
    [8, 925, 3450, 3799, 880, "FDD", "900"], [12, 729, 5010, 5179, 699, "FDD", "700a"],
    [13, 746, 5180, 5279, 777, "FDD", "700c"], [14, 758, 5280, 5379, 788, "FDD", "700 PS"],
    [17, 734, 5730, 5849, 704, "FDD", "700b"], [18, 860, 5850, 5999, 815, "FDD", "800 JP"],
    [19, 875, 6000, 6149, 830, "FDD", "800 JP"], [20, 791, 6150, 6449, 832, "FDD", "800 DD"],
    [21, 1495.9, 6450, 6599, 1447.9, "FDD", "1500"], [25, 1930, 8040, 8689, 1850, "FDD", "1900+"],
    [26, 859, 8690, 9039, 814, "FDD", "850+"], [28, 758, 9210, 9659, 703, "FDD", "700 APT"],
    [29, 717, 9660, 9769, null, "SDL", "700 SDL"], [30, 2350, 9770, 9869, 2305, "FDD", "WCS"],
    [31, 462.5, 9870, 9919, 452.5, "FDD", "450"], [32, 1452, 9920, 10359, null, "SDL", "1500 L"],
    [34, 2010, 36200, 36349, 2010, "TDD", "2000"], [38, 2570, 37750, 38249, 2570, "TDD", "2600 TDD"],
    [39, 1880, 38250, 38649, 1880, "TDD", "1900 TDD"], [40, 2300, 38650, 39649, 2300, "TDD", "2300"],
    [41, 2496, 39650, 41589, 2496, "TDD", "2500"], [42, 3400, 41590, 43589, 3400, "TDD", "3500"],
    [43, 3600, 43590, 45589, 3600, "TDD", "3700"], [46, 5150, 46790, 54539, 5150, "TDD", "LAA 5 GHz"],
    [48, 3550, 55240, 56739, 3550, "TDD", "CBRS"], [66, 2110, 66436, 67335, 1710, "FDD", "AWS-3"],
    [71, 617, 68586, 68935, 663, "FDD", "600"]
  ];
  function earfcnInfo(n) {
    for (const b of LTE) {
      if (n >= b[2] && n <= b[3]) {
        const dl = b[1] + 0.1 * (n - b[2]);
        const ul = b[4] === null ? null : (b[5] === "TDD" ? dl : b[4] + (dl - b[1]));
        return { ok: true, band: b[0], bandName: "B" + b[0], name: b[6], mode: b[5], dl: +dl.toFixed(1), ul: ul === null ? null : +ul.toFixed(1) };
      }
    }
    return { ok: false };
  }
  // [band, N_low, N_high, mode, name]  (DL NR-ARFCN ranges, TS 38.101-1/-2)
  const NR = [
    ["n1", 422000, 434000, "FDD", "2100"], ["n3", 361000, 376000, "FDD", "1800"],
    ["n5", 173800, 178800, "FDD", "850"], ["n7", 524000, 538000, "FDD", "2600"],
    ["n8", 185000, 192000, "FDD", "900"], ["n20", 158200, 164200, "FDD", "800"],
    ["n28", 151600, 160600, "FDD", "700"], ["n38", 514000, 524000, "TDD", "2600 TDD"],
    ["n40", 460000, 480000, "TDD", "2300"], ["n41", 499200, 537999, "TDD", "2500"],
    ["n66", 422000, 440000, "FDD", "AWS-3"], ["n71", 123400, 130400, "FDD", "600"],
    ["n77", 620000, 680000, "TDD", "3.7 GHz C-band"], ["n78", 620000, 653333, "TDD", "3.5 GHz"],
    ["n79", 693334, 733333, "TDD", "4.7 GHz"], ["n257", 2054166, 2104165, "TDD", "28 GHz"],
    ["n258", 2016667, 2070832, "TDD", "26 GHz"], ["n260", 2229166, 2279165, "TDD", "39 GHz"],
    ["n261", 2070833, 2084999, "TDD", "28 GHz US"]
  ];
  function nrarfcnFreq(n) {
    if (n >= 0 && n < 600000) return 0.005 * n;                         // ΔF_Global 5 kHz
    if (n >= 600000 && n <= 2016666) return 3000 + 0.015 * (n - 600000);   // 15 kHz
    if (n >= 2016667 && n <= 3279165) return 24250.08 + 0.06 * (n - 2016667); // 60 kHz
    return NaN;
  }
  function nrarfcnInfo(n, hintBands) {
    const f = nrarfcnFreq(n);
    if (!isNum(f)) return { ok: false };
    let cands = NR.filter(b => n >= b[1] && n <= b[2]);
    if (hintBands && hintBands.length) {
      const h = cands.filter(b => hintBands.includes(+b[0].slice(1)));
      if (h.length) cands = h;
    }
    return {
      ok: true, dl: +f.toFixed(3), fr: f > 7125 ? "FR2" : "FR1",
      bands: cands.map(b => b[0]), bandName: cands.length ? cands.map(b => b[0]).join(" / ") : "—",
      name: cands.length ? cands[0][4] : "—", mode: cands.length ? cands[0][3] : "—",
      ambiguous: cands.length > 1
    };
  }
  function uarfcnInfo(n) {
    const T = [[1, 10562, 10838, 0, "2100"], [2, 9662, 9938, 0, "1900"], [4, 1537, 1738, 1805, "AWS"],
               [5, 4357, 4458, 0, "850"], [8, 2937, 3088, 340, "900"]];
    for (const b of T) if (n >= b[1] && n <= b[2]) return { ok: true, band: b[0], bandName: "UMTS B" + b[0], name: b[4], dl: +(b[3] + n / 5).toFixed(1) };
    return { ok: false };
  }
  function gsmArfcnInfo(n) {
    if (n >= 1 && n <= 124) return { ok: true, bandName: "GSM 900", dl: +(935 + 0.2 * n).toFixed(1), ul: +(890 + 0.2 * n).toFixed(1) };
    if (n >= 975 && n <= 1023) return { ok: true, bandName: "E-GSM 900", dl: +(935 + 0.2 * (n - 1024)).toFixed(1), ul: +(890 + 0.2 * (n - 1024)).toFixed(1) };
    if (n >= 512 && n <= 885) return { ok: true, bandName: "DCS 1800", dl: +(1805.2 + 0.2 * (n - 512)).toFixed(1), ul: +(1710.2 + 0.2 * (n - 512)).toFixed(1), note: "512–810 قد تكون PCS 1900 في الأمريكتين" };
    if (n >= 128 && n <= 251) return { ok: true, bandName: "GSM 850", dl: +(869.2 + 0.2 * (n - 128)).toFixed(1), ul: +(824.2 + 0.2 * (n - 128)).toFixed(1) };
    return { ok: false };
  }
  function wifiChannel(fMHz) {
    if (!isNum(fMHz)) return null;
    if (fMHz === 2484) return { band: "2.4", ch: 14 };
    if (fMHz >= 2412 && fMHz <= 2472) return { band: "2.4", ch: (fMHz - 2407) / 5 };
    if (fMHz >= 5150 && fMHz <= 5895) return { band: "5", ch: (fMHz - 5000) / 5 };
    if (fMHz >= 5925 && fMHz <= 7125) return { band: "6", ch: (fMHz - 5950) / 5 };
    return null;
  }
  // Resolve the serving carrier for any technology
  function carrierOf(s) {
    if (!s) return { ok: false };
    if (s.tech === "LTE" && isNum(s.earfcn)) return earfcnInfo(s.earfcn);
    if (s.tech === "NR" && isNum(s.arfcn)) return nrarfcnInfo(s.arfcn, s.bands);
    if (s.tech === "WCDMA" && isNum(s.uarfcn)) return uarfcnInfo(s.uarfcn);
    if (s.tech === "GSM" && isNum(s.arfcn)) return gsmArfcnInfo(s.arfcn);
    if (isNum(s.freq)) return { ok: true, dl: s.freq, bandName: s.band ? String(s.band) : "—" };
    return { ok: false };
  }

  /* ---------------------------------------------------------------
     3. PROPAGATION & LINK MATH
     --------------------------------------------------------------- */
  const fspl = (dKm, fMHz) => 32.44 + 20 * log10(fMHz) + 20 * log10(dKm);           // ITU-R P.525
  function hataRadiusKm(maxPL, fMHz, hb, hm, urban) {                                 // COST-231 Hata, solved for d
    const a = (1.1 * log10(fMHz) - 0.7) * hm - (1.56 * log10(fMHz) - 0.8);
    const Cm = urban ? 3 : 0;
    const A = 46.3 + 33.9 * log10(fMHz) - 13.82 * log10(hb) - a + Cm;
    const B = 44.9 - 6.55 * log10(hb);
    return Math.pow(10, (maxPL - A) / B);
  }
  const fresnelRadius = (n, d1, d2, fMHz) => Math.sqrt(n * (C0 / (fMHz * 1e6)) * d1 * d2 / (d1 + d2));
  function taDistance(tech, ta) {
    if (!isNum(ta) || ta < 0) return NaN;
    if (tech === "LTE" || tech === "NR") return ta * 78.12;   // 16·Ts·c/2
    if (tech === "GSM") return ta * 553.5;
    return NaN;
  }
  // RSSI estimate from RSRP: RSSI = RSRP + 10log10(12·N_RB) + 10log10(load share of REs)
  const rssiFromRsrp = (rsrp, nRB, load) => rsrp + 10 * log10(12 * nRB) + 10 * log10(Math.max(load, 1 / 6));
  const nRBfromBw = bwMHz => ({ 1.4: 6, 3: 15, 5: 25, 10: 50, 15: 75, 20: 100 })[bwMHz] || Math.round(bwMHz * 5);
  // Attenuated Shannon (Mogensen et al.) — expected DL throughput in Mb/s
  function expectedThroughput(sinr, bwMHz, layers) {
    if (!isNum(sinr) || !isNum(bwMHz)) return NaN;
    const se = Math.min(5.55, 0.6 * Math.log2(1 + db2lin(sinr)));
    const mimo = layers > 1 ? (sinr > 15 ? 1.7 : sinr > 5 ? 1.25 : 1) : 1;
    return bwMHz * 0.75 * se * mimo;
  }

  // ITU-R P.2040 materials (same library as the 3D lab)
  const MATERIALS = {
    concrete: { ar: "خرسانة", en: "Concrete", a: 5.24, b: 0, c: 0.0462, d: 0.7822, t: 0.20 },
    brick:    { ar: "طابوق", en: "Brick", a: 3.91, b: 0, c: 0.0238, d: 0.16, t: 0.20 },
    plaster:  { ar: "جبس بورد", en: "Plasterboard", a: 2.73, b: 0, c: 0.0085, d: 0.9395, t: 0.026 },
    wood:     { ar: "خشب", en: "Wood", a: 1.99, b: 0, c: 0.0047, d: 1.0718, t: 0.04 },
    glass:    { ar: "زجاج عادي", en: "Glass", a: 6.31, b: 0, c: 0.0036, d: 1.3394, t: 0.006 },
    lowe:     { ar: "زجاج عازل Low-E", en: "Coated glass", fixed: f => 23 + 0.3 * f, t: 0.008 },
    metal:    { ar: "معدن", en: "Metal", a: 1, b: 0, c: 1e7, d: 0, t: 0.002 },
    chip:     { ar: "خشب مضغوط", en: "Chipboard", a: 2.58, b: 0, c: 0.0217, d: 0.78, t: 0.018 },
    floor:    { ar: "سقف/أرضية خرسانية", en: "Floor slab", a: 5.24, b: 0, c: 0.0462, d: 0.7822, t: 0.15 },
    foliage:  { ar: "أشجار", en: "Foliage", veg: true, t: 5 },
    human:    { ar: "جسم بشري", en: "Human body", body: true, t: 0.3 }
  };
  function csqrt(re, im) { const r = Math.hypot(re, im); const a = Math.sqrt(Math.max(0, (r + re) / 2)); let b = Math.sqrt(Math.max(0, (r - re) / 2)); if (im < 0) b = -b; return [a, b]; }
  function materialLoss(key, fGHz, thickness) {
    const m = MATERIALS[key]; if (!m) return NaN;
    const t = isNum(thickness) ? thickness : m.t;
    if (m.fixed) return m.fixed(fGHz);
    if (m.veg) return t < 14 ? 0.45 * Math.pow(fGHz, 0.284) * t : 1.33 * Math.pow(fGHz, 0.284) * Math.pow(t, 0.588); // Weissberger
    if (m.body) return 4 * Math.sqrt(fGHz);
    const er = m.a * Math.pow(fGHz, m.b), sig = m.c * Math.pow(fGHz, m.d), ei = -17.98 * sig / fGHz;
    const [sr, si] = csqrt(er, ei);
    const g = Math.hypot(1 - sr, -si) / Math.hypot(1 + sr, si);
    const iface = -10 * log10(Math.max(1e-30, Math.pow(1 - g * g, 2)));
    const alpha = 8.686 * (2 * Math.PI * fGHz * 1e9 / C0) * Math.abs(si);
    return Math.min(300, iface + alpha * t);
  }
  // ITU-R P.2109-1 building entry loss, horizontal path, probability p (0..1)
  function normInv(p) { // Acklam
    const a = [-39.6968302866538, 220.946098424521, -275.928510446969, 138.357751867269, -30.6647980661472, 2.50662827745924];
    const b = [-54.4760987982241, 161.585836858041, -155.698979859887, 66.8013118877197, -13.2806815528857];
    const c = [-0.00778489400243029, -0.322396458041136, -2.40075827716184, -2.54973253934373, 4.37466414146497, 2.93816398269878];
    const d = [0.00778469570904146, 0.32246712907004, 2.445134137143, 3.75440866190742];
    const pl = 0.02425;
    if (p < pl) { const q = Math.sqrt(-2 * Math.log(p)); return (((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1); }
    if (p > 1 - pl) return -normInv(1 - p);
    const q = p - 0.5, r = q * q;
    return (((((a[0] * r + a[1]) * r + a[2]) * r + a[3]) * r + a[4]) * r + a[5]) * q / (((((b[0] * r + b[1]) * r + b[2]) * r + b[3]) * r + b[4]) * r + 1);
  }
  function buildingEntryLoss(fGHz, type, p) {
    const P = type === "thermal"
      ? { r: 28.19, s: -3.0, t: 8.48, u: 13.5, v: 3.8, w: 27.8, x: -2.9, y: 9.4, z: -2.1 }
      : { r: 12.64, s: 3.72, t: 0.96, u: 9.6, v: 2.0, w: 9.1, x: -3.0, y: 4.5, z: -2.0 };
    const lf = log10(fGHz), Fi = normInv(clamp(p, 0.001, 0.999));
    const Lh = P.r + P.s * lf + P.t * lf * lf;
    const A = Fi * (P.u + P.v * lf) + Lh;
    const B = Fi * (P.y + P.z * lf) + P.w + P.x * lf;
    return 10 * log10(Math.pow(10, 0.1 * A) + Math.pow(10, 0.1 * B) + Math.pow(10, 0.1 * -3));
  }

  /* ---------------------------------------------------------------
     4. WI-FI CHANNEL PLANNING
     Each AP occupies [center − W/2, center + W/2]; interference on a
     candidate channel = Σ overlap-fraction × linear RSSI.
     --------------------------------------------------------------- */
  function apSpan(ap) {
    const w = ap.width || 20, c = ap.center || ap.freq;
    return [c - w / 2, c + w / 2];
  }
  function wifiPlan(scan, currentBssid) {
    const out = {};
    const plans = {
      "2.4": [1, 6, 11].map(ch => ({ ch, f: 2407 + 5 * ch, w: 20 })),
      "5": [36, 40, 44, 48, 149, 153, 157, 161, 165].map(ch => ({ ch, f: 5000 + 5 * ch, w: 20 }))
    };
    for (const band in plans) {
      const aps = (scan || []).filter(a => (wifiChannel(a.freq) || {}).band === band && a.bssid !== currentBssid);
      const scores = plans[band].map(c => {
        let I = 0, n = 0;
        for (const ap of aps) {
          const [lo, hi] = apSpan(ap), clo = c.f - c.w / 2, chi = c.f + c.w / 2;
          const ov = Math.max(0, Math.min(hi, chi) - Math.max(lo, clo)) / c.w;
          if (ov > 0) { I += ov * db2lin(ap.rssi); n++; }
        }
        return { ch: c.ch, f: c.f, I: I > 0 ? lin2db(I) : -120, n };
      });
      scores.sort((a, b) => a.I - b.I);
      out[band] = { best: scores[0], scores, count: aps.length };
    }
    return out;
  }

  /* ---------------------------------------------------------------
     5. METRIC NORMALISATION + HEALTH
     --------------------------------------------------------------- */
  function primaryMetrics(s) {
    if (!s) return [];
    if (s.tech === "LTE" || s.tech === "NR") return [["rsrp", s.rsrp, "RSRP", "dBm"], ["rsrq", s.rsrq, "RSRQ", "dB"], ["sinr", s.sinr, "SINR", "dB"]];
    if (s.tech === "WCDMA") return [["rscp", s.rscp, "RSCP", "dBm"], ["ecno", s.ecno, "Ec/No", "dB"]];
    if (s.tech === "GSM") return [["gsm", s.rssi, "RSSI", "dBm"]];
    return [];
  }
  function health(s, findings) {
    const m = primaryMetrics(s); if (!m.length) return null;
    const w = { rsrp: 0.4, rsrq: 0.2, sinr: 0.4, rscp: 0.6, ecno: 0.4, gsm: 1 };
    let sum = 0, ws = 0;
    for (const [k, v] of m) { const c = classify(k, v); if (c.score === null) continue; sum += c.score * w[k]; ws += w[k]; }
    if (!ws) return null;
    let h = sum / ws;
    (findings || []).forEach(f => { if (f.sev === "crit") h -= 10; else if (f.sev === "warn") h -= 4; });
    if (s.tech === "WCDMA" || s.tech === "GSM") h = Math.min(h, 60);
    return Math.round(clamp(h, 0, 100));
  }
  const stdev = a => { if (a.length < 2) return 0; const m = a.reduce((x, y) => x + y, 0) / a.length; return Math.sqrt(a.reduce((x, y) => x + (y - m) * (y - m), 0) / (a.length - 1)); };

  /* ---------------------------------------------------------------
     6. DIAGNOSIS ENGINE — symptom → cause → solutions (with evidence)
     Each finding: {id, sev:'crit'|'warn'|'info'|'ok', title, symptom,
       cause, evidence:[...], user:[...], operator:[...], concept}
     --------------------------------------------------------------- */
  function diagnose(s, hist, ctx) {
    ctx = ctx || {};
    const F = [];
    const add = f => F.push(f);
    hist = hist || [];
    if (!s) return F;
    const car = carrierOf(s);
    const fMHz = car.ok ? car.dl : (isNum(s.freq) ? s.freq : null);
    const fGHz = fMHz ? fMHz / 1000 : null;
    const lamCm = fMHz ? C0 / (fMHz * 1e6) * 100 : null;
    const cell = s.tech === "LTE" || s.tech === "NR";
    const st = ctx.speedtest;

    if (!s.tech && !s.wifi) {
      add({ id: "nodata", sev: "info", title: "لا توجد قراءة راديوية", symptom: "لا تتوفر قيم RSRP أو SINR من المصدر الحالي.",
        cause: "المتصفح لا يملك صلاحية قراءة الشبكة الخلوية. هذه القيم تأتي من نظام Android فقط.",
        evidence: ["المصدر: " + (s.source || "—")], user: ["ثبّت تطبيق Android المرفق لقراءة الإشارة الحقيقية.", "أو استخدم «الفحص اليدوي» في صفحة التشخيص لإدخال القيم من قائمة الهاتف (##4636## أو إعدادات حول الهاتف ← الحالة).", "أو استورد ملف CSV من جلسة قياس سابقة."], operator: [], concept: "—" });
      return F;
    }

    if (s.tech === "LTE" || s.tech === "NR") {
      const ta = taDistance(s.tech, s.ta);
      // R1 — coverage
      if (isNum(s.rsrp) && s.rsrp < -100) {
        const crit = s.rsrp < -110;
        const ben = fGHz ? buildingEntryLoss(fGHz, "traditional", 0.5) : null;
        const lowBand = fMHz && fMHz > 1500;
        const user = [
          "اقترب من نافذة أو انتقل لطابق أعلى. خسارة دخول المبنى عند " + (fMHz ? Math.round(fMHz) + " MHz" : "هذا التردد") + " تبلغ تقريباً " + (ben ? ben.toFixed(0) : "10–20") + " dB (ITU-R P.2109).",
          lowBand ? "اسمح للهاتف باستخدام النطاقات المنخفضة (B20/B8/B28/n28). فقد الفضاء الحر يزيد 20·log10(f) فينفذ 800 MHz أفضل بنحو " + (20 * log10(fMHz / 800)).toFixed(1) + " dB من التردد الحالي." : "أنت على نطاق منخفض بالفعل. المشكلة في المسافة أو العوائق وليست في التردد.",
          "استخدم موجّه 4G/5G خارجي (CPE) أو هوائي اتجاهي بكسب 9–14 dBi موجّه نحو البرج.",
          "فعّل الاتصال عبر Wi-Fi (Wi-Fi Calling) للمكالمات داخل المبنى."
        ];
        const ev = ["RSRP = " + s.rsrp + " dBm (" + classify("rsrp", s.rsrp).ar + ")"];
        if (isNum(ta)) ev.push("المسافة التقديرية من TA ≈ " + (ta / 1000).toFixed(2) + " km");
        if (fMHz) ev.push("التردد " + Math.round(fMHz) + " MHz · " + (car.bandName || ""));
        add({ id: "coverage", sev: crit ? "crit" : "warn", title: crit ? "تغطية شبه منعدمة" : "حافة الخلية — تغطية ضعيفة",
          symptom: "قدرة الإشارة المرجعية منخفضة، فيكون الاتصال بطيئاً أو متقطعاً وتنقطع المكالمات.",
          cause: (isNum(ta) && ta > 3000 ? "بُعد كبير عن الموقع (" + (ta / 1000).toFixed(1) + " km). " : "") + "فقد المسار مع المسافة، يُضاف إليه امتصاص الجدران (Absorption) والحيود حول المباني. نموذج FSPL وحده لا يفسر الفقد الكلي داخل المباني.",
          evidence: ev, user, operator: ["إضافة موقع أو Small Cell، أو تعديل الإمالة (Tilt) نحو المنطقة.", "تفعيل طبقة نطاق منخفض في الموقع المخدّم."], concept: "Path loss · Absorption · Link budget" });
      }
      // R2 — strong signal but low SINR (interference)
      if (isNum(s.rsrp) && isNum(s.sinr) && s.rsrp >= -100 && s.sinr < 3) {
        const near = (s.nb || []).filter(n => isNum(n.rsrp) && n.rsrp >= s.rsrp - 6);
        add({ id: "interference", sev: s.sinr < 0 ? "crit" : "warn", title: "إشارة قوية لكن SINR منخفض — تداخل",
          symptom: "أعمدة الإشارة ممتلئة لكن السرعة منخفضة أو الاتصال غير مستقر.",
          cause: "قوة الإشارة S لا تكفي وحدها. التداخل من خلايا مجاورة على التردد نفسه يرفع مقام SINR = S/(I+N). القدرات تُجمع خطياً بالـ mW قبل التحويل إلى dB.",
          evidence: ["RSRP = " + s.rsrp + " dBm مقابل SINR = " + s.sinr + " dB", near.length + " خلية مجاورة ضمن 6 dB من الخلية المخدّمة"],
          user: ["غيّر موقعك داخل المكان، فالتداخل يتغير خلال أمتار قليلة.", "هوائي اتجاهي نحو الخلية المخدّمة يخفض التداخل الجانبي.", "جرّب قفل النطاق على نطاق آخر أقل ازدحاماً إن كان الجهاز يدعم ذلك."],
          operator: ["مراجعة الإمالة والقدرة لتقليل التداخل (Coordination / ICIC).", "مراجعة قائمة الجيران وتخطيط PCI."], concept: "SINR · Co-channel interference · Coordination" });
      }
      // R3 — pilot pollution
      const sameF = (s.nb || []).filter(n => isNum(n.rsrp) && (!isNum(n.earfcn) || !isNum(s.earfcn) || n.earfcn === s.earfcn) && isNum(s.rsrp) && n.rsrp >= s.rsrp - 6);
      if (sameF.length >= 3) {
        add({ id: "pollution", sev: "warn", title: "تلوث الإشارات (Pilot Pollution)",
          symptom: "عدة خلايا بقوة متقاربة ولا توجد خلية مهيمنة واضحة.",
          cause: "عند تقارب ثلاث خلايا أو أكثر ضمن 6 dB يصبح كل منها تداخلاً على الأخرى، ويكثر التبديل بينها.",
          evidence: [sameF.length + " خلايا ضمن 6 dB: PCI " + sameF.slice(0, 5).map(n => n.pci).join(", ")],
          user: ["الهوائي الاتجاهي هو الحل الأكثر فاعلية.", "الوقوف قرب نافذة تواجه اتجاه الخلية المخدّمة."],
          operator: ["ضبط الإمالة لتحديد حدود الخلايا وإنشاء خلية مهيمنة."], concept: "Interference · Dominance" });
      }
      // R4 — PCI mod-3 collision (LTE CRS shift)
      if (s.tech === "LTE" && isNum(s.pci)) {
        const m3 = (s.nb || []).filter(n => isNum(n.pci) && n.pci % 3 === s.pci % 3 && isNum(n.rsrp) && isNum(s.rsrp) && n.rsrp >= s.rsrp - 6 && (!isNum(n.earfcn) || n.earfcn === s.earfcn));
        if (m3.length) add({ id: "mod3", sev: "warn", title: "تعارض PCI mod 3",
          symptom: "SINR أقل من المتوقع رغم قوة الإشارة.",
          cause: "إشارات CRS المرجعية لخليتين لهما نفس قيمة PCI mod 3 تقع على العناصر الترددية نفسها فتتصادم مباشرة.",
          evidence: ["المخدّمة PCI " + s.pci + " (mod3=" + (s.pci % 3) + ") والجارة PCI " + m3.map(n => n.pci).join(", ")],
          user: ["تغيير الموقع أو استخدام هوائي اتجاهي. الحل الجذري لدى المشغّل."],
          operator: ["إعادة تخطيط PCI لتجنب تطابق mod 3 بين الجيران المتقاربين."], concept: "Reference signals · Interference" });
      }
      // R5 — load / congestion from RSRQ
      if (isNum(s.sinr) && isNum(s.rsrq) && s.sinr >= 10 && s.rsrq < -13) {
        add({ id: "load", sev: "warn", title: "حمل مرتفع على الخلية",
          symptom: "جودة الراديو جيدة لكن RSRQ منخفض.",
          cause: "RSRQ = N·RSRP/RSSI. يرتفع RSSI مع امتلاء موارد الخلية بالبيانات، فينخفض RSRQ رغم أن SINR جيد. هذه علامة ازدحام وليست ضعف تغطية.",
          evidence: ["SINR = " + s.sinr + " dB · RSRQ = " + s.rsrq + " dB"],
          user: ["أعد الاختبار خارج وقت الذروة.", "انقل الاستخدام الثقيل إلى Wi-Fi.", "جرّب نطاقاً آخر أو شبكة 5G إن توفرت."],
          operator: ["توسعة السعة (Carrier/Sector)، أو موازنة الحمل بين النطاقات."], concept: "Capacity · Load" });
      }
      // R6 — ping-pong handover
      const win = hist.filter(h => h.t >= s.t - 90000 && (h.tech === "LTE" || h.tech === "NR") && isNum(h.pci));
      let changes = 0; const seq = [];
      for (let i = 1; i < win.length; i++) if (win[i].pci !== win[i - 1].pci) { changes++; seq.push(win[i - 1].pci + "→" + win[i].pci); }
      const uniq = new Set(win.map(h => h.pci));
      if (changes >= 4 && uniq.size <= 3) {
        add({ id: "pingpong", sev: "warn", title: "تبديل متكرر بين الخلايا (Ping-Pong)",
          symptom: "انقطاعات قصيرة وتأخر متقطع.",
          cause: "خليتان متقاربتا القوة عند الموقع الحالي. كل تبديل (Handover) يستهلك وقتاً وقد يُسقط حزماً.",
          evidence: [changes + " تبديلات خلال 90 ثانية: " + seq.slice(-4).join(" ، ")],
          user: ["ثبّت الجهاز في موضع تهيمن فيه خلية واحدة.", "قفل الخلية أو النطاق من تطبيقات متخصصة على الأجهزة المدعومة."],
          operator: ["ضبط Hysteresis و Time-To-Trigger و CIO بين الخليتين."], concept: "Mobility · Handover" });
      }
      // R7 — fast fading while static (multipath)
      const recent = hist.filter(h => h.t >= s.t - 20000 && isNum(h.rsrp)).map(h => h.rsrp);
      const sd = stdev(recent);
      if (recent.length >= 8 && sd > 5 && (!isNum(s.speed) || s.speed < 2)) {
        add({ id: "fading", sev: "info", title: "تذبذب سريع في الإشارة (Multipath Fading)",
          symptom: "القيم تقفز رغم أن الجهاز ثابت تقريباً.",
          cause: "الموجة المباشرة والمنعكسة تصلان بأطوار مختلفة. عند فرق طور 180° يحدث خفوت عميق، وتتغير الصورة كلياً مع إزاحة نحو λ/2.",
          evidence: ["الانحراف المعياري لـ RSRP خلال 20 ثانية = " + sd.toFixed(1) + " dB", lamCm ? "λ/2 = " + (lamCm / 2).toFixed(1) + " cm عند هذا التردد" : ""].filter(Boolean),
          user: ["حرّك الجهاز بضعة سنتيمترات (" + (lamCm ? (lamCm / 2).toFixed(0) : "≈8") + " cm) وثبّته حيث تكون القيمة أعلى.", "أبعده عن الأسطح المعدنية القريبة.", "الأجهزة متعددة الهوائيات (MIMO/Diversity) أقل تأثراً."],
          operator: [], concept: "Multipath · Diversity" });
      }
      // R8 — high speed (Doppler)
      if (isNum(s.speed) && s.speed > 22 && fMHz) {
        const fd = s.speed * fMHz * 1e6 / C0;
        add({ id: "doppler", sev: "info", title: "سرعة عالية — تأثير دوبلر",
          symptom: "تذبذب وتبديل خلايا أكثر أثناء القيادة.",
          cause: "إزاحة دوبلر القصوى f_D = v·f/c، وزمن التماسك Tc ≈ 0.423/f_D. كلما قصر Tc تغيرت القناة أسرع من قدرة المستقبل على تتبعها.",
          evidence: ["v = " + (s.speed * 3.6).toFixed(0) + " km/h · f_D = " + fd.toFixed(0) + " Hz · Tc ≈ " + (423 / fd).toFixed(2) + " ms"],
          user: ["هذا سلوك طبيعي. للقياس الدقيق أوقف المركبة."], operator: [], concept: "Doppler · Coherence time" });
      }
      // R9 — far from site
      if (isNum(ta) && ta > 5000 && !(isNum(s.rsrp) && s.rsrp < -100)) {
        add({ id: "far", sev: "info", title: "مسافة كبيرة عن الموقع",
          symptom: "الإشارة مقبولة الآن لكنها حساسة لأي عائق.", cause: "Timing Advance يدل على مسافة تتجاوز 5 km.",
          evidence: ["TA = " + s.ta + " ≈ " + (ta / 1000).toFixed(1) + " km"],
          user: ["حدد موقع البرج من خرائط المواقع (CellMapper) ووجّه هوائياً اتجاهياً نحوه."], operator: [], concept: "Link budget" });
      }
      // R10 — uplink power-limited
      if (st && isNum(st.dl) && isNum(st.ul) && st.dl > 1 && st.ul < 0.12 * st.dl && isNum(s.rsrp) && s.rsrp < -100) {
        add({ id: "uplink", sev: "warn", title: "الرفع محدود بقدرة الهاتف",
          symptom: "التنزيل مقبول والرفع ضعيف جداً.", cause: "قدرة إرسال الهاتف محدودة (≈23 dBm) مقابل عشرات الواط في البرج، فتنهار الوصلة الصاعدة أولاً عند حافة الخلية.",
          evidence: ["DL = " + st.dl.toFixed(1) + " Mb/s · UL = " + st.ul.toFixed(2) + " Mb/s · RSRP = " + s.rsrp + " dBm"],
          user: ["الحل نفسه للتغطية الضعيفة: نافذة أو هوائي خارجي أو CPE."], operator: ["تفعيل طبقة نطاق منخفض أو UL CA."], concept: "Link budget asymmetry" });
      }
    }
    // R11 — legacy fallback
    if (s.tech === "WCDMA" || s.tech === "GSM") {
      add({ id: "fallback", sev: "warn", title: "الهاتف على شبكة " + (s.tech === "GSM" ? "2G" : "3G"),
        symptom: "سرعات منخفضة جداً وتأخر عالٍ.", cause: "لا توجد تغطية 4G/5G كافية في الموقع، أو أن وضع الشبكة في الهاتف مقيّد.",
        evidence: [s.tech === "WCDMA" ? "RSCP = " + s.rscp + " dBm · Ec/No = " + s.ecno + " dB" : "RSSI = " + s.rssi + " dBm"],
        user: ["تأكد أن «نوع الشبكة المفضل» يتضمن 4G/5G.", "تأكد أن الشريحة والباقة تدعمان 4G/VoLTE.", "انتقل لموقع مفتوح وأعد الفحص."], operator: ["توسعة تغطية LTE."], concept: "Coverage" });
    }
    // R12 — speed test vs radio expectation (congestion / backhaul)
    if (st && isNum(st.dl) && cell && isNum(s.sinr) && s.sinr >= 8) {
      const bw = isNum(s.bwKHz) && s.bwKHz > 0 ? s.bwKHz / 1000 : 20;
      const exp = expectedThroughput(s.sinr, bw, 2);
      if (st.dl < 0.3 * exp) add({ id: "congestion", sev: "warn", title: "السرعة الفعلية أقل بكثير من قدرة الراديو",
        symptom: "قناة الراديو جيدة لكن Speedtest منخفض.",
        cause: "حسب Shannon المعدّل تتحمل القناة نحو " + exp.toFixed(0) + " Mb/s. الفرق الكبير يعني أن عنق الزجاجة في ازدحام الخلية أو في شبكة النقل (Backhaul) أو في تقييد الباقة، وليس في الراديو.",
        evidence: ["SINR = " + s.sinr + " dB · BW = " + bw + " MHz → متوقع ≈ " + exp.toFixed(0) + " Mb/s", "مقاس = " + st.dl.toFixed(1) + " Mb/s (" + (100 * st.dl / exp).toFixed(0) + "%)"],
        user: ["كرر الاختبار في وقت مختلف للتمييز بين الازدحام وتقييد الباقة.", "استخدم Wi-Fi للأحمال الثقيلة."], operator: ["توسعة سعة الموقع أو وصلة النقل."], concept: "Shannon capacity · Congestion" });
    }
    // R13 — latency / jitter
    if (st && isNum(st.ping) && st.ping > 80) {
      add({ id: "latency", sev: st.ping > 150 ? "warn" : "info", title: "زمن استجابة مرتفع",
        symptom: "تأخر في الألعاب ومكالمات الفيديو.", cause: "الزمن المرتفع مع راديو جيد يدل على ازدحام في الطريق إلى الخادم (Bufferbloat أو Backhaul). مع راديو ضعيف يضاف إليه إعادة الإرسال (HARQ/ARQ).",
        evidence: ["Ping = " + st.ping.toFixed(0) + " ms" + (isNum(st.jitter) ? " · Jitter = " + st.jitter.toFixed(0) + " ms" : "")],
        user: ["أوقف التنزيلات الخلفية أثناء الاستخدام التفاعلي.", "قارن مع Wi-Fi لعزل المشكلة."], operator: [], concept: "Latency" });
    }
    // R14..16 — Wi-Fi
    if (s.wifi && isNum(s.wifi.rssi)) {
      const w = s.wifi, wc = wifiChannel(w.freq);
      if (w.rssi < -70) add({ id: "wifiweak", sev: w.rssi < -80 ? "crit" : "warn", title: "إشارة Wi-Fi ضعيفة",
        symptom: "بطء وانقطاع في الشبكة اللاسلكية.", cause: "الجدران والأسقف تمتص الطاقة، والامتصاص أشد عند 5 GHz منه عند 2.4 GHz. والمسافة تضيف 6 dB لكل مضاعفة في الفضاء الحر.",
        evidence: ["RSSI = " + w.rssi + " dBm (" + classify("wifi", w.rssi).ar + ")", wc ? "النطاق " + wc.band + " GHz · قناة " + wc.ch : ""].filter(Boolean),
        user: ["ضع الراوتر في مكان مرتفع ومركزي بعيداً عن المعادن والمرايا.", wc && wc.band !== "2.4" ? "لمسافات بعيدة خلف عدة جدران قد يكون 2.4 GHz أفضل تغطية." : "إن كان الجهاز قريباً فاستخدم 5 GHz لسرعة أعلى وتداخل أقل.", "نظام Mesh أو نقطة وصول إضافية موصولة بكابل للمساحات الكبيرة."],
        operator: [], concept: "Absorption · FSPL" });
      if (s.scan && s.scan.length) {
        const plan = wifiPlan(s.scan, w.bssid);
        if (wc && plan[wc.band]) {
          const P = plan[wc.band];
          const cur = P.scores.find(x => x.ch === wc.ch);
          const [wlo, whi] = apSpan(w);
          const overl = s.scan.filter(a => { if (a.bssid === w.bssid || (wifiChannel(a.freq) || {}).band !== wc.band || a.rssi <= -82) return false; const [lo, hi] = apSpan(a); return Math.min(hi, whi) - Math.max(lo, wlo) > 0; });
          if (overl.length >= 3 || (overl.length >= 1 && cur && P.best && cur.I - P.best.I > 10 && cur.I > -75)) add({ id: "wifichan", sev: "warn", title: "ازدحام قناة Wi-Fi",
            symptom: "سرعة متذبذبة رغم أن الإشارة مقبولة.", cause: "شبكات مجاورة تشارك القناة نفسها أو تتداخل معها جزئياً. Wi-Fi يتقاسم الوقت (CSMA/CA)، فكل شبكة إضافية تخفض نصيبك.",
            evidence: [overl.length + " شبكة متداخلة على قناتك (" + wc.ch + ")", "أفضل قناة مقترحة: " + P.best.ch + " (تداخل " + (P.best.I > -119 ? P.best.I.toFixed(0) + " dBm" : "لا يوجد") + ")"],
            user: ["غيّر قناة الراوتر إلى " + P.best.ch + (wc.band === "2.4" ? " واستخدم فقط 1 أو 6 أو 11 مع عرض 20 MHz." : "."), "انقل الأجهزة الثابتة إلى 5 GHz أو 6 GHz."], operator: [], concept: "Interference · Channel planning" });
        }
        if (wc && wc.band === "2.4" && (w.width || 20) >= 40 && (plan["2.4"].count || 0) >= 5) add({ id: "wifiwidth", sev: "info", title: "عرض قناة 40 MHz في نطاق 2.4 مزدحم",
          symptom: "", cause: "قناة 40 MHz تغطي ثلثي نطاق 2.4 GHz فتتداخل مع معظم الجيران.", evidence: ["عرض القناة " + w.width + " MHz مع " + plan["2.4"].count + " شبكة مجاورة"],
          user: ["اضبط عرض القناة على 20 MHz في إعدادات الراوتر."], operator: [], concept: "Bandwidth vs interference" });
      }
      if (isNum(w.linkMbps) && w.rssi > -60 && w.linkMbps < 50) add({ id: "wifilink", sev: "info", title: "سرعة الوصلة أقل من المتوقع",
        symptom: "", cause: "إشارة قوية مع معدل وصلة منخفض يدل على تداخل أو جهاز قديم (802.11g/n) على الشبكة أو إعداد عرض قناة ضيق.",
        evidence: ["RSSI = " + w.rssi + " dBm · Link = " + w.linkMbps + " Mb/s"], user: ["حدّث برنامج الراوتر وفعّل 802.11ac/ax.", "افحص ازدحام القناة في صفحة الطيف."], operator: [], concept: "MCS adaptation" });
    }
    if (s.roaming) add({ id: "roaming", sev: "info", title: "الهاتف في وضع التجوال", symptom: "", cause: "قد تكون الأولوية والسرعات محدودة على شبكة الشريك.", evidence: [s.operator || ""], user: ["تحقق من شروط التجوال وأولوية الشبكة."], operator: [], concept: "—" });

    const order = { crit: 0, warn: 1, info: 2, ok: 3 };
    F.sort((a, b) => order[a.sev] - order[b.sev]);
    if (!F.some(f => f.sev === "crit" || f.sev === "warn")) {
      F.unshift({ id: "ok", sev: "ok", title: "لا توجد مشكلة راديوية جوهرية", symptom: "القيم ضمن الحدود الجيدة.",
        cause: "التغطية والجودة كافيتان. أي بطء متبقٍ مصدره غالباً الخادم أو التطبيق أو الباقة.",
        evidence: primaryMetrics(s).filter(m => isNum(m[1])).map(m => m[2] + " = " + m[1] + " " + m[3]), user: ["شغّل Speedtest للتحقق من السرعة الفعلية."], operator: [], concept: "—" });
    }
    return F;
  }

  /* ---------------------------------------------------------------
     7. CSV I/O (own format + heuristic import of other tools)
     --------------------------------------------------------------- */
  const CSV_COLS = ["time_iso", "epoch_ms", "source", "tech", "nsa", "operator", "mcc", "mnc", "band", "earfcn", "arfcn", "uarfcn", "freq_mhz", "bw_khz", "pci", "cell_id", "tac",
    "rsrp", "rsrq", "sinr", "rssi", "cqi", "ta", "rscp", "ecno", "lat", "lon", "acc_m", "speed_mps", "wifi_ssid", "wifi_rssi", "wifi_freq", "wifi_link_mbps", "n_neighbors", "best_nb_pci", "best_nb_rsrp"];
  function csvEsc(v) { if (v === null || v === undefined || (typeof v === "number" && !isFinite(v))) return ""; const s = String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }
  function toCSV(samples) {
    const rows = [CSV_COLS.join(",")];
    for (const s of samples) {
      const c = carrierOf(s); const nb = (s.nb || []).slice().sort((a, b) => (b.rsrp || -999) - (a.rsrp || -999))[0];
      const r = [new Date(s.t).toISOString(), s.t, s.source, s.tech, s.nsa ? 1 : 0, s.operator, s.mcc, s.mnc, c.ok ? c.bandName : s.band, s.earfcn, s.arfcn, s.uarfcn, c.ok ? c.dl : s.freq, s.bwKHz, s.pci, s.ci, s.tac,
        s.rsrp, s.rsrq, s.sinr, s.rssi, s.cqi, s.ta, s.rscp, s.ecno, s.lat, s.lon, s.acc, s.speed,
        s.wifi && s.wifi.ssid, s.wifi && s.wifi.rssi, s.wifi && s.wifi.freq, s.wifi && s.wifi.linkMbps, (s.nb || []).length, nb && nb.pci, nb && nb.rsrp];
      rows.push(r.map(csvEsc).join(","));
    }
    return rows.join("\n");
  }
  function parseCSVText(text) {
    text = text.replace(/^﻿/, "");
    const rows = []; let row = [], f = "", q = false;
    for (let i = 0; i < text.length; i++) {
      const ch = text[i];
      if (q) { if (ch === '"') { if (text[i + 1] === '"') { f += '"'; i++; } else q = false; } else f += ch; }
      else if (ch === '"') q = true;
      else if (ch === "," || ch === ";" || ch === "\t") { row.push(f); f = ""; }
      else if (ch === "\n" || ch === "\r") { if (ch === "\r" && text[i + 1] === "\n") i++; row.push(f); rows.push(row); row = []; f = ""; }
      else f += ch;
    }
    if (f.length || row.length) { row.push(f); rows.push(row); }
    return rows.filter(r => r.length > 1 || (r[0] || "").trim());
  }
  // header aliases → field
  const ALIAS = {
    t: ["epoch_ms", "timestamp_ms", "time_ms"], time: ["time_iso", "timestamp", "time", "date", "datetime"],
    tech: ["tech", "technology", "networktech", "nettype", "networktype", "rat"], operator: ["operator", "operatorname", "carrier", "carriername"],
    rsrp: ["rsrp", "ssrsrp", "level", "rsrp/ssrsrp(dbm)", "rsrp_dbm"], rsrq: ["rsrq", "ssrsrq", "qual", "rsrq/ssrsrq(db)", "rsrq_db"],
    sinr: ["sinr", "rssnr", "snr", "sssinr", "sinr/sssinr(db)", "sinr_db"], rssi: ["rssi"], pci: ["pci", "psc"], earfcn: ["earfcn", "arfcn_lte", "dl_earfcn"],
    arfcn: ["arfcn", "nrarfcn"], uarfcn: ["uarfcn"], band: ["band"], freq: ["freq_mhz", "frequency(mhz)", "frequency", "freq"], ci: ["cell_id", "cellid", "ci", "eci", "nci"],
    tac: ["tac", "lac"], ta: ["ta", "timing_advance"], lat: ["lat", "latitude"], lon: ["lon", "lng", "longitude"], speed: ["speed_mps", "speed"],
    rscp: ["rscp"], ecno: ["ecno", "ec/no"], wifiRssi: ["wifi_rssi"], wifiFreq: ["wifi_freq"], wifiSsid: ["wifi_ssid"], wifiLink: ["wifi_link_mbps"], bwKHz: ["bw_khz"], nsa: ["nsa"]
  };
  function normTech(v) {
    v = String(v || "").toUpperCase();
    if (/NR|5G/.test(v)) return "NR"; if (/LTE|4G/.test(v)) return "LTE";
    if (/WCDMA|UMTS|HSPA|3G/.test(v)) return "WCDMA"; if (/GSM|EDGE|GPRS|2G/.test(v)) return "GSM"; return v ? "LTE" : null;
  }
  function importCSV(text) {
    const rows = parseCSVText(text); if (rows.length < 2) return { samples: [], mapped: [] };
    const head = rows[0].map(h => h.trim().toLowerCase().replace(/\s+/g, ""));
    const idx = {}; const mapped = [];
    for (const k in ALIAS) { const i = head.findIndex(h => ALIAS[k].includes(h)); if (i >= 0) { idx[k] = i; mapped.push(k + "←" + rows[0][i]); } }
    const num = (r, k) => { if (!(k in idx)) return undefined; const v = parseFloat(String(r[idx[k]]).replace(",", ".")); return isFinite(v) ? v : undefined; };
    const out = []; let t0 = Date.now() - rows.length * 1000;
    for (let j = 1; j < rows.length; j++) {
      const r = rows[j];
      let t = num(r, "t");
      if (!isNum(t) && "time" in idx) { const d = Date.parse(String(r[idx.time]).replace(" ", "T")); if (isFinite(d)) t = d; }
      if (!isNum(t)) t = t0 + j * 1000;
      const s = { t, source: "import", tech: "tech" in idx ? normTech(r[idx.tech]) : "LTE", operator: "operator" in idx ? r[idx.operator] : undefined,
        rsrp: num(r, "rsrp"), rsrq: num(r, "rsrq"), sinr: num(r, "sinr"), rssi: num(r, "rssi"), pci: num(r, "pci"), earfcn: num(r, "earfcn"),
        arfcn: num(r, "arfcn"), uarfcn: num(r, "uarfcn"), freq: num(r, "freq"), ci: num(r, "ci"), tac: num(r, "tac"), ta: num(r, "ta"),
        lat: num(r, "lat"), lon: num(r, "lon"), speed: num(r, "speed"), rscp: num(r, "rscp"), ecno: num(r, "ecno"), bwKHz: num(r, "bwKHz"), nb: [] };
      if ("band" in idx) s.band = r[idx.band];
      if (isNum(num(r, "wifiRssi"))) s.wifi = { rssi: num(r, "wifiRssi"), freq: num(r, "wifiFreq"), ssid: "wifiSsid" in idx ? r[idx.wifiSsid] : "", linkMbps: num(r, "wifiLink") };
      if (isNum(s.rsrp) && s.rsrp > 0) s.rsrp = -s.rsrp;
      if (s.tech === "GSM" && !isNum(s.rssi) && isNum(s.rsrp)) { s.rssi = s.rsrp; s.rsrp = undefined; }
      out.push(s);
    }
    out.sort((a, b) => a.t - b.t);
    return { samples: out, mapped };
  }

  /* ---------------------------------------------------------------
     8. SESSION STATISTICS
     --------------------------------------------------------------- */
  function sessionStats(samples) {
    const pct = (a, p) => { if (!a.length) return NaN; const s = a.slice().sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor(p * (s.length - 1)))]; };
    const pick = k => samples.map(s => s[k]).filter(isNum);
    const rsrp = pick("rsrp"), sinr = pick("sinr"), rsrq = pick("rsrq");
    const dist = {}; CLASSES.forEach(c => dist[c.k] = 0);
    rsrp.forEach(v => dist[classify("rsrp", v).k]++);
    let ho = 0; for (let i = 1; i < samples.length; i++) if (isNum(samples[i].pci) && isNum(samples[i - 1].pci) && samples[i].pci !== samples[i - 1].pci) ho++;
    const techs = {}; samples.forEach(s => { if (s.tech) techs[s.tech] = (techs[s.tech] || 0) + 1; });
    return { n: samples.length, dur: samples.length ? (samples[samples.length - 1].t - samples[0].t) / 1000 : 0,
      rsrp: { p10: pct(rsrp, 0.1), p50: pct(rsrp, 0.5), p90: pct(rsrp, 0.9), min: Math.min(...rsrp), max: Math.max(...rsrp) },
      sinr: { p10: pct(sinr, 0.1), p50: pct(sinr, 0.5), p90: pct(sinr, 0.9) }, rsrq: { p50: pct(rsrq, 0.5) },
      dist, rsrpN: rsrp.length, handovers: ho, techs,
      outage: rsrp.length ? rsrp.filter(v => v < -110).length / rsrp.length : NaN };
  }

  /* ---------------------------------------------------------------
     9. DEMO DATA — physically consistent scenarios for training
     --------------------------------------------------------------- */
  const SCENARIOS = {
    good:       { ar: "تغطية ممتازة 5G SA", tech: "NR", arfcn: 636666, bands: [78], pci: 412, rsrp: -79, sinr: 22, rsrq: -10.5, ta: 3, bw: 100000, op: "Demo Mobile" },
    indoorEdge: { ar: "داخل مبنى خرساني عند حافة الخلية", tech: "LTE", earfcn: 1650, pci: 117, rsrp: -109, sinr: 1.5, rsrq: -14, ta: 11, bw: 20000, op: "Demo Mobile" },
    interference:{ ar: "إشارة قوية مع تداخل وتلوث", tech: "LTE", earfcn: 1650, pci: 52, rsrp: -83, sinr: 0.5, rsrq: -16.5, ta: 4, bw: 20000, op: "Demo Mobile", polluted: true },
    jammer:     { ar: "تشويش خارجي (مقوٍّ غير مرخص قريب)", tech: "LTE", earfcn: 1650, pci: 52, rsrp: -79, sinr: 1, rsrq: -17, ta: 3, bw: 20000, op: "Demo Mobile" },
    congestion: { ar: "ازدحام وقت الذروة", tech: "LTE", earfcn: 3050, pci: 233, rsrp: -86, sinr: 17, rsrq: -14.5, ta: 6, bw: 20000, op: "Demo Mobile", slow: true },
    highway:    { ar: "قيادة على طريق سريع", tech: "LTE", earfcn: 6300, pci: 301, rsrp: -92, sinr: 9, rsrq: -11, ta: 20, bw: 10000, op: "Demo Mobile", drive: true },
    wifiCrowded:{ ar: "Wi-Fi مزدحم في عمارة سكنية", tech: "LTE", earfcn: 1650, pci: 117, rsrp: -94, sinr: 11, rsrq: -11.5, ta: 8, bw: 20000, op: "Demo Mobile", crowded: true },
    legacy:     { ar: "هبوط إلى 3G", tech: "WCDMA", uarfcn: 10700, pci: 211, rscp: -97, ecno: -13, op: "Demo Mobile" }
  };
  function makeRng(seed) { let x = seed >>> 0 || 1; return () => { x ^= x << 13; x ^= x >>> 17; x ^= x << 5; return ((x >>> 0) / 4294967296); }; }
  function demoScan(sc, rnd) {
    const aps = [];
    const n24 = sc.crowded ? 13 : 5, n5 = sc.crowded ? 5 : 3;
    const ch24 = sc.crowded ? [6, 6, 6, 1, 3, 6, 6, 9, 1, 6, 4, 6, 1] : [1, 6, 11, 6, 11];
    for (let i = 0; i < n24; i++) { const ch = ch24[i % ch24.length], w = sc.crowded && i % 4 === 1 ? 40 : 20, f = 2407 + 5 * ch;
      aps.push({ ssid: i === 0 ? "MyHome" : "Net-" + (100 + i), bssid: "aa:bb:cc:00:00:" + (10 + i), freq: f, center: w === 40 ? f + 10 : f, width: w, rssi: Math.round((i === 0 ? -71 : -58 - rnd() * 32)) }); }
    const ch5 = sc.crowded ? [36, 44, 149, 157, 40] : [36, 149, 157, 100, 161];
    for (let i = 0; i < n5; i++) { const ch = ch5[i], f = 5000 + 5 * ch; aps.push({ ssid: i === 0 ? "MyHome-5G" : "Net5-" + i, bssid: "aa:bb:cc:00:01:" + (10 + i), freq: f, center: f + (i === 0 ? 30 : 0), width: i === 0 ? 80 : 40, rssi: Math.round(i === 0 ? -76 : (sc.crowded ? -68 : -74) - rnd() * 20) }); }
    return aps;
  }
  function DemoSource(key) {
    const sc = SCENARIOS[key] || SCENARIOS.indoorEdge;
    const rnd = makeRng(key.length * 7919 + 13);
    let k = 0, slow = 0, lat = 30.5085, lon = 47.7804, pciAlt = sc.pci + 7;
    const sp = sc.drive ? 31 : 0.3;
    return {
      key, scenario: sc,
      next(t) {
        k++;
        slow += (rnd() - 0.5) * 0.6; slow *= 0.97;
        const ray = Math.sqrt(-2 * Math.log(Math.max(1e-6, rnd())) / 2); const fast = 10 * Math.log10(ray * ray + 0.05) + 1.5;
        const s = { t, source: "demo", tech: sc.tech, operator: sc.op, mcc: "418", mnc: "05", roaming: false, nb: [], speed: sp + (rnd() - 0.5) * 2, lat, lon, acc: 8 };
        if (sc.drive) { lat += sp * 1e-5 * 0.9; lon += sp * 1e-5 * 0.4; }
        s.lat = lat; s.lon = lon;
        if (sc.tech === "WCDMA") {
          s.uarfcn = sc.uarfcn; s.pci = sc.pci; s.rscp = Math.round(sc.rscp + slow + fast * 0.6); s.ecno = Math.round(sc.ecno + slow * 0.3 + (rnd() - 0.5) * 2); s.ci = 10123;
        } else {
          let base = sc.rsrp + slow + fast * (sc.drive ? 1 : 0.55);
          let pci = sc.pci;
          if (sc.drive) { const ph = (k % 140) / 140; base = -78 - 32 * Math.sin(Math.PI * ph) + slow; pci = (k % 140) < 70 ? sc.pci : pciAlt; if (k % 140 > 60 && k % 140 < 80) pci = (k % 2) ? sc.pci : pciAlt; }
          s.rsrp = Math.round(base);
          s.sinr = +(sc.sinr + (s.rsrp - sc.rsrp) * (sc.polluted ? 0.1 : 0.45) + (rnd() - 0.5) * 3).toFixed(1);
          s.rsrq = Math.round(sc.rsrq + (rnd() - 0.5) * 2 + (sc.drive ? (s.rsrp + 90) * 0.08 : 0));
          s.rssi = Math.round(rssiFromRsrp(s.rsrp, 100, sc.slow ? 0.9 : 0.5));
          s.pci = pci; s.ta = Math.max(0, Math.round(sc.ta + (sc.drive ? (s.rsrp < -100 ? 15 : 0) : 0) + (rnd() - 0.5)));
          s.bwKHz = sc.bw; s.cqi = clamp(Math.round(s.sinr / 2 + 4), 1, 15); s.ci = 4459521 + pci; s.tac = 21011;
          if (sc.tech === "LTE") s.earfcn = sc.earfcn; else { s.arfcn = sc.arfcn; s.bands = sc.bands; }
          const nbn = sc.polluted ? 5 : 3;
          for (let i = 0; i < nbn; i++) {
            const p = sc.polluted ? [55, 58, 49, 61, 70][i] : (sc.drive ? [pciAlt, sc.pci + 30, sc.pci + 42][i] : sc.pci + 3 + i * 7);
            const off = sc.polluted ? [2, 3.5, 5, 4, 11][i] : 8 + i * 5;
            const r = sc.drive && p === pciAlt ? Math.round(-110 + 32 * Math.sin(Math.PI * ((k % 140) / 140)) + (rnd() - 0.5) * 4) : Math.round(s.rsrp - off + (rnd() - 0.5) * 3);
            s.nb.push({ tech: sc.tech, pci: p === s.pci ? (sc.pci === p ? pciAlt : sc.pci) : p, earfcn: sc.earfcn, arfcn: sc.arfcn, rsrp: r, rsrq: Math.round(s.rsrq - 2 - rnd() * 3) });
          }
        }
        if (!sc.drive) {
          s.wifi = { ssid: "MyHome", bssid: "aa:bb:cc:00:00:10", freq: sc.crowded ? 2437 : 5180, width: sc.crowded ? 40 : 80, center: sc.crowded ? 2447 : 5210,
            rssi: Math.round((sc.crowded ? -71 : -58) + (rnd() - 0.5) * 4), linkMbps: sc.crowded ? 72 : 433 };
          if (k % 15 === 1 || !this._scan) this._scan = demoScan(sc, rnd);
          s.scan = this._scan;
        }
        return s;
      },
      speedtest() {
        const r = () => rnd();
        if (sc.slow) return { ping: 68 + r() * 30, jitter: 18 + r() * 10, dl: 2.5 + r() * 3, ul: 1.8 + r() * 1.5 };
        if (sc.tech === "NR") return { ping: 18 + r() * 6, jitter: 3 + r() * 2, dl: 420 + r() * 120, ul: 55 + r() * 25 };
        if (sc.tech === "WCDMA") return { ping: 95 + r() * 40, jitter: 25 + r() * 10, dl: 2 + r() * 2, ul: 0.6 + r() * 0.4 };
        if (sc.polluted) return { ping: 45 + r() * 25, jitter: 22 + r() * 10, dl: 6 + r() * 5, ul: 2 + r() * 2 };
        if (sc.rsrp < -100) return { ping: 55 + r() * 30, jitter: 15 + r() * 10, dl: 4 + r() * 4, ul: 0.25 + r() * 0.3 };
        return { ping: 32 + r() * 15, jitter: 6 + r() * 4, dl: 38 + r() * 25, ul: 12 + r() * 8 };
      }
    };
  }

  /* ---------------------------------------------------------------
     INTERFERENCE ISOLATION (same model as the server, Interference.php)
     N per RE = −174 + 10log(SCS) + NF; SINR_exp = 1/(1/SNR + 1/SINR_max);
     degradation = SINR_exp − SINR; I = S/SINR − S/SINR_exp; co-channel
     neighbours explain ρ·RSRP each (ρ = 1 on PCI mod-3 collision, else 0.5).
     --------------------------------------------------------------- */
  const INTF_LABELS = { clean: "إشارة نظيفة", coverage: "ضعف تغطية", load: "ازدحام الخلية", cochannel: "تداخل خلايا مجاورة", mod3: "تصادم PCI mod 3",
    pilot: "تلوث الإشارة", external: "تشويش خارجي", unexplained: "تشويش غير مفسَّر", mixed: "تشويش مختلط" };
  function interference(s, sinrMax = 25, nf = 7) {
    if (!s || (s.tech !== "LTE" && s.tech !== "NR") || !isNum(s.rsrp)) return null;
    const arfcn = s.tech === "LTE" ? (s.earfcn ?? s.arfcn) : s.arfcn;
    const scs = s.tech === "NR" && isNum(arfcn) && arfcn >= 600000 ? 30 : 15;
    const N = -174 + 10 * log10(scs * 1000) + nf, snr = s.rsrp - N;
    const sinrExp = lin2db(1 / (1 / db2lin(snr) + 1 / db2lin(sinrMax)));
    let sm, src;
    if (isNum(s.sinr)) { sm = s.sinr; src = "sinr"; }
    else if (isNum(s.rsrq)) { const den = 1 / db2lin(s.rsrq) - 6; sm = den <= 12 / db2lin(sinrMax) ? sinrMax : lin2db(12 / den); src = "rsrq"; }
    else return null;
    const deg = Math.max(0, sinrExp - sm), S = db2lin(s.rsrp), I = S / db2lin(sm) - S / db2lin(sinrExp), perMHz = 10 * log10(1000 / scs);
    let Inb = 0, nClose = 0, agg = null; const co = [];
    for (const n of s.nb || []) {
      const nf2 = n.earfcn ?? n.arfcn;
      if (n.tech && n.tech !== s.tech) continue;
      if (!isNum(nf2) || nf2 !== arfcn || !isNum(n.rsrp) || (isNum(n.pci) && n.pci === s.pci)) continue;
      const gap = s.rsrp - n.rsrp, m3 = isNum(n.pci) && isNum(s.pci) && n.pci % 3 === s.pci % 3;
      Inb += (m3 ? 1 : 0.5) * db2lin(n.rsrp); co.push(n);
      if (gap <= 6) nClose++;
      if (!agg || gap < agg.gap) agg = { pci: n.pci, gap, m3 };
    }
    const hasNb = (s.nb || []).length > 0;
    let cls;
    if (deg < 3) cls = isNum(s.sinr) && isNum(s.rsrq) && s.rsrq <= -14 && sm >= 10 ? "load" : s.rsrp < -110 ? "coverage" : "clean";
    else if (nClose >= 3) cls = "pilot";
    else if (agg && agg.m3 && agg.gap <= 6) cls = "mod3";
    else if (I > 0 && Inb >= 0.5 * I) cls = "cochannel";
    else if (deg >= 6) cls = hasNb && s.rsrp >= -105 ? "external" : "unexplained";
    else cls = "mixed";
    const ext = I - Inb;
    return { cls, label: INTF_LABELS[cls], src, sinrExp: +sinrExp.toFixed(1), sinr: +sm.toFixed(1), deg: +deg.toFixed(1),
      iDbm: I > 0 ? +(lin2db(I) + perMHz).toFixed(1) : null, nbDbm: Inb > 0 ? +(lin2db(Inb) + perMHz).toFixed(1) : null,
      extDbm: ext > 0 ? +(lin2db(ext) + perMHz).toFixed(1) : null, explained: I > 0 ? clamp(Inb / I, 0, 1) : null,
      nCochan: co.length, nClose, agg };
  }

  const API = {
    C0, TH, CLASSES, RANGES, classify, LTE, NR, earfcnInfo, nrarfcnInfo, nrarfcnFreq, uarfcnInfo, gsmArfcnInfo, wifiChannel, carrierOf,
    fspl, hataRadiusKm, fresnelRadius, taDistance, rssiFromRsrp, nRBfromBw, expectedThroughput, MATERIALS, materialLoss, buildingEntryLoss,
    wifiPlan, primaryMetrics, health, diagnose, toCSV, importCSV, parseCSVText, CSV_COLS, sessionStats, SCENARIOS, DemoSource, db2lin, lin2db, isNum, clamp, interference, INTF_LABELS
  };
  if (typeof module !== "undefined" && module.exports) module.exports = API;
  else root.SSEngine = API;
})(typeof window !== "undefined" ? window : this);
