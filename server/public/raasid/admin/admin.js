/* مركز إدارة البوابة: المدير ونائبه ومشرفو الأقسام — بوابة القسم (/amu/) ومنظومة راصد الإشارة معاً */
(function () {
  "use strict";
  const API_BASE = new URL("../../api/index.php", location.href).href;
  const apiUrl = path => { const i = path.indexOf("?"); const p = i < 0 ? path : path.slice(0, i), q = i < 0 ? "" : path.slice(i + 1); return `${API_BASE}?r=/${p}${q ? "&" + q : ""}`; };
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const fmt = v => (+v || 0).toLocaleString("en-US");
  const when = s => s ? new Date(String(s).replace(" ", "T") + "Z").toLocaleString("ar-IQ", { dateStyle: "medium", timeStyle: "short" }) : "—";
  const FB_CFG = "https://drdshsw2022-byte.github.io/almaaqal-kontrol/kontrol/firebase-config.js";
  let ME = null, ROLES = {}, STATUSES = {}, COLLEGES = null;

  async function api(path, body) {
    const o = { credentials: "same-origin", headers: { "X-SS": "1", Accept: "application/json" } };
    if (body !== undefined) { o.method = "POST"; o.body = JSON.stringify(body); o.headers["Content-Type"] = "application/json"; }
    const r = await fetch(apiUrl(path), o);
    let j = null; try { j = await r.json(); } catch (_) { }
    if (r.status === 401 && j && j.error === "login_required" && path !== "v1/portal/me") { showLogin(j.message); throw new Error(j.message); }
    if (!r.ok) { const e = new Error((j && j.message) || `HTTP ${r.status}`); e.code = j && j.error; throw e; }
    return j;
  }
  let tt; function toast(m) { const t = $("toast"); t.textContent = m; t.hidden = false; clearTimeout(tt); tt = setTimeout(() => t.hidden = true, 3800); }

  // ------------------------------------------------------------------ الدخول (حساب البوابة نفسه عبر Firebase)
  function load(src) { return new Promise((res, rej) => { const s = document.createElement("script"); s.src = src; s.onload = res; s.onerror = () => rej(new Error("تعذّر تحميل " + src)); document.head.appendChild(s); }); }
  let authBooted = false, exchanging = false;
  async function bootAuth() {
    if (authBooted) return; authBooted = true;
    try {
      await load(FB_CFG); await load("/amu/access.js"); await load("/amu/auth.js");
    } catch (e) { lgErr("تعذّر تحميل خدمة الدخول من بوابة القسم: " + e.message); return; }
    window.AMU_AUTH.onChange(async st => {
      if (st.status === "error" && st.error) lgErr(st.error);
      if (!st.user || exchanging || ME) return;
      exchanging = true;
      try {
        const tok = await st.user.getIdToken(true);
        const r = await api("v1/portal/session", { id_token: tok });
        ME = r.me; await start();
      } catch (e) { lgErr(e.message); }
      finally { exchanging = false; }
    });
  }
  function lgErr(m) { const e = $("lgErr"); e.textContent = m || ""; e.hidden = !m; }
  function showLogin(msg) { ME = null; $("shell").hidden = true; $("login").hidden = false; if (msg) lgErr(msg); bootAuth(); }
  $("lgGoogle").onclick = () => { lgErr(""); if (window.AMU_AUTH) window.AMU_AUTH.signIn(); else lgErr("خدمة الدخول تُحمَّل… أعد المحاولة بعد لحظة."); };
  $("lgForgot").onclick = () => { if (window.AMU_BRIDGE && AMU_BRIDGE.recover) AMU_BRIDGE.recover({ user: $("lgUser").value }); };
  $("lgStaff").onsubmit = async e => {
    e.preventDefault(); lgErr("");
    try { await window.AMU_AUTH.signInStaff($("lgUser").value, $("lgPass").value); $("lgPass").value = ""; } catch (x) { lgErr(x.message); }
  };

  // ------------------------------------------------------------------ الإطار
  const PAGES = [
    ["home", "الرئيسية", null], ["users", "الحسابات", "users"], ["colleges", "الكليات والأقسام", "colleges"], ["sections", "التفرعات", "sections"],
    ["news", "الأخبار والإعلانات", "news"], ["stats", "الإحصاءات", "stats"], ["audit", "سجل العمليات", "audit"],
  ];
  async function start() {
    const r = await api("v1/portal/me"); ME = r.me; ROLES = r.roles; STATUSES = r.statuses;
    $("login").hidden = true; $("shell").hidden = false;
    $("nav").innerHTML = PAGES.filter(p => !p[2] || ME.can[p[2]]).map(p => `<a href="#/${p[0]}">${p[1]}</a>`).join("");
    $("who").innerHTML = `<span class="role ${ME.role}">${esc(ME.role_ar)}</span><span class="email mono" dir="ltr">${esc(ME.email)}</span><button class="btn sm" id="out" type="button">خروج</button>`;
    $("out").onclick = async () => { try { await api("v1/portal/logout", {}); } catch (_) { } location.reload(); };
    route();
  }
  const V = () => $("view");
  async function route() {
    if (!ME) return;
    const page = (location.hash || "#/home").slice(2).split("/")[0] || "home";
    document.querySelectorAll("#nav a").forEach(a => a.getAttribute("href") === "#/" + page ? a.setAttribute("aria-current", "page") : a.removeAttribute("aria-current"));
    V().innerHTML = `<div class="empty">جارٍ التحميل…</div>`;
    try { await ({ home, users, colleges, sections, news, stats, audit }[page] || home)(); }
    catch (e) { V().innerHTML = `<div class="card"><p class="err">${esc(e.message)}</p></div>`; }
  }
  window.addEventListener("hashchange", route);
  async function getColleges() { if (!COLLEGES) COLLEGES = await api("v1/portal/colleges"); return COLLEGES; }
  const deptName = id => { for (const c of (COLLEGES || {}).colleges || []) for (const d of c.depts || []) if (d.id === id) return d.name; return id; };
  const deptPicker = (sel, only) => `<div class="depts">${((COLLEGES || {}).colleges || []).map(c => {
    const ds = (c.depts || []).filter(d => !only || only.includes(d.id));
    return ds.length ? `<b>${esc(c.name)}</b>${ds.map(d => `<label><input type="checkbox" name="dept" value="${esc(d.id)}" ${sel && sel.includes(d.id) ? "checked" : ""}>${esc(d.name)}</label>`).join("")}` : "";
  }).join("") || '<span class="muted">قائمة الأقسام غير متاحة</span>'}</div>`;
  const picked = f => [...f.querySelectorAll('input[name="dept"]:checked')].map(x => x.value);

  function dialog(html, onSubmit) {
    const d = $("dlg"), f = $("dlgForm");
    f.innerHTML = html + `<p class="err" id="dErr" hidden></p><div class="acts"><button class="btn" value="cancel" formnovalidate type="submit">إلغاء</button><button class="btn primary" value="ok" type="submit">حفظ</button></div>`;
    f.onsubmit = async e => {
      if (e.submitter && e.submitter.value === "cancel") return;
      e.preventDefault();
      try { await onSubmit(f); d.close(); } catch (x) { $("dErr").textContent = x.message; $("dErr").hidden = false; }
    };
    d.showModal();
  }

  // ------------------------------------------------------------------ الرئيسية
  async function home() {
    const [st, sec, nw] = await Promise.all([api("v1/portal/stats?days=30"), api("v1/portal/sections"), api("v1/portal/news")]);
    const us = ME.can.users ? await api("v1/portal/users") : null;
    const off = sec.sections.filter(s => s.status !== "visible");
    const live = nw.news.filter(n => n.active && (!n.ends || new Date(n.ends.replace(" ", "T") + "Z") > new Date()));
    V().innerHTML = `<div class="row spread" style="margin-bottom:12px"><h1>مرحباً ${esc(ME.name || "")}</h1><span class="role ${ME.role}">${esc(ME.role_ar)}</span></div>
      <p class="muted" style="margin-top:0">${ME.role === "owner" ? "لديك التحكم الكامل بكل أقسام البوابة وتفرعاتها ومنظومة راصد الإشارة." : ME.role === "deputy" ? "لديك صلاحيات المدير كاملة عدا حسابات المدير والنواب." : "يمكنك نشر الإعلانات لأقسامك ومتابعة الإحصاءات."}${ME.depts ? ` أقسامك: ${ME.depts.map(d => esc(deptName(d))).join("، ")}.` : ""}</p>
      <div class="tiles">
        <div class="tile"><span>زوار اليوم</span><b>${fmt(st.today)}</b></div>
        <div class="tile"><span>زيارات 30 يوماً</span><b>${fmt(st.total)}</b></div>
        <a class="tile" href="#/sections" style="text-decoration:none;color:inherit"><span>تفرعات غير ظاهرة</span><b style="color:${off.length ? "#b45309" : "inherit"}">${off.length}</b><small>من ${sec.sections.length}</small></a>
        <a class="tile" href="#/news" style="text-decoration:none;color:inherit"><span>إعلانات منشورة</span><b>${live.length}</b></a>
        ${us ? `<a class="tile" href="#/users" style="text-decoration:none;color:inherit"><span>حسابات الإدارة</span><b>${us.users.length}</b><small>${us.blocked.length} محظور</small></a>` : ""}
      </div>
      <h2 style="margin-top:18px">الأنظمة</h2>
      <div class="quick">
        <a href="/amu/" target="_blank" rel="noopener"><b>بوابة القسم</b><span>الصفحة الرئيسية وكل التفرعات</span></a>
        ${ME.can.colleges ? `<a href="/amu/admin/" target="_blank" rel="noopener"><b>لوحة مدير بوابة القسم</b><span>الكتب، حسابات الأساتذة، أسماء الكليات والأقسام، طلبات الدعم</span></a>` : ""}
        <a href="/raasid/" target="_blank" rel="noopener"><b>منظومة راصد الإشارة</b><span>التبويبات الأربعة</span></a>
        ${ME.can.dashboard ? `<a href="/dashboard/" target="_blank" rel="noopener"><b>لوحة الراصد</b><span>تدخلها مباشرة بجلستك الحالية</span></a>` : ""}
        ${ME.role === "owner" ? `<a href="https://console.firebase.google.com/project/${esc(window.__fbProject || "almaqal-uni")}/authentication/users" target="_blank" rel="noopener"><b>حسابات Firebase</b><span>مستخدمو تسجيل الدخول في البوابة</span></a>` : ""}
      </div>
      ${off.length ? `<div class="card" style="margin-top:14px"><h3>تفرعات غير ظاهرة الآن</h3>${off.map(s => `<span class="pill-x">${esc(s.name)} · <span class="st ${s.status}">${esc(s.status_ar)}</span></span>`).join(" ")}</div>` : ""}
      ${ME.can.dashboard ? `<div class="card" style="margin-top:14px"><h3>كلمات المرور</h3>
        <p class="note" style="margin-top:0"><b>الأساتذة والمشرفون</b> (اسم مستخدم): طلبات «نسيت كلمة المرور» تصل إلى <a href="/amu/admin/" target="_blank" rel="noopener">طلبات الدعم الفني في لوحة مدير البوابة</a>، ومنها «إعادة تعيين كلمات المرور» بكلمة مؤقتة يغيّرها الأستاذ إجبارياً عند أول دخول.
        <b>حسابات Google</b>: يستردها صاحبها من Google. <b>لوحة الراصد</b>: عيّن كلمة مرور جديدة هنا.</p>
        <div class="tablewrap" id="hDash"></div></div>` : ""}
      ${ME.can.audit ? `<div class="card" style="margin-top:14px"><h3>آخر العمليات</h3><div class="tablewrap" id="hAudit"></div></div>` : ""}`;
    if (ME.can.audit) { const a = await api("v1/portal/audit?limit=8"); $("hAudit").innerHTML = auditTable(a.log); }
    if (ME.can.dashboard) {
      const d = await api("v1/portal/dash-admins");
      $("hDash").innerHTML = `<table><tr><th>حساب لوحة الراصد</th><th>أُنشئ</th><th></th></tr>${d.admins.map(x => `<tr><td class="mono" dir="ltr">${esc(x.username)}</td><td>${when(x.created_at)}</td><td><button class="btn sm" data-dp="${esc(x.username)}" type="button">كلمة مرور جديدة</button></td></tr>`).join("")}</table>`;
      V().querySelectorAll("[data-dp]").forEach(b => b.onclick = () => dialog(`<h2>كلمة مرور جديدة لـ <bdi dir="ltr">${esc(b.dataset.dp)}</bdi></h2>
        <label class="f">كلمة المرور الجديدة (10 خانات على الأقل، حروف وأرقام)<input class="inp" name="p1" type="password" dir="ltr" minlength="10" required autocomplete="new-password"></label>
        <label class="f">تأكيدها<input class="inp" name="p2" type="password" dir="ltr" minlength="10" required autocomplete="new-password"></label>`, async f => {
        if (f.p1.value !== f.p2.value) throw new Error("التأكيد لا يطابق كلمة المرور");
        await api("v1/portal/dash-password", { username: b.dataset.dp, password: f.p1.value });
        toast("عُيّنت كلمة المرور الجديدة لـ " + b.dataset.dp);
      }));
    }
  }

  // ------------------------------------------------------------------ الحسابات
  async function users() {
    await getColleges();
    const r = await api("v1/portal/users");
    const canEdit = u => ME.can.admins || u.role === "supervisor";
    V().innerHTML = `<div class="row spread" style="margin-bottom:8px"><h1>حسابات الإدارة</h1><button class="btn primary" id="uAdd" type="button">إضافة حساب</button></div>
      <p class="muted" style="margin-top:0">الدخول بحساب البوابة نفسه: Google للمدير والنائب وللبريد الجامعي، أو اسم مستخدم الأستاذ (<span class="mono" dir="ltr">doc01@pw.almaaqal.app</span>) للمشرفين. ${ME.can.admins ? "أنت المدير: تضيف النواب وتنقل الإدارة." : "حسابات المدير والنواب يديرها المدير وحده."}</p>
      <div class="card"><div class="tablewrap"><table><tr><th>الحساب</th><th>الدور</th><th>الأقسام</th><th>آخر دخول</th><th>الحالة</th><th>ملاحظة</th><th></th></tr>
      ${r.users.map(u => `<tr><td><b>${esc(u.name || "")}</b><div class="mono muted" dir="ltr">${esc(u.email)}</div></td><td><span class="role ${u.role}">${esc(u.role_ar)}</span></td>
        <td class="td-wrap">${u.depts ? u.depts.map(d => `<span class="pill-x">${esc(deptName(d))}</span>`).join("") : "كل الأقسام"}</td><td>${when(u.last_login)}</td>
        <td>${u.active ? '<span class="st visible">فعّال</span>' : '<span class="st hidden">معطّل</span>'}</td><td class="td-wrap">${esc(u.note || "")}</td>
        <td>${canEdit(u) ? `<button class="btn sm" data-edit="${esc(u.email)}" type="button">تعديل</button>${u.role !== "owner" && u.email !== ME.email ? ` <button class="btn sm danger" data-del="${esc(u.email)}" type="button">حذف</button>` : ""}` : ""}</td></tr>`).join("")}</table></div></div>
      <div class="card" style="margin-top:14px"><h3>الحسابات المحظورة</h3><p class="note" style="margin-top:0">يُمنع البريد المحظور من دخول البوابة ومركز الإدارة (تُسجَّل خروجه تلقائياً من صفحات البوابة).</p>
        <form id="bForm" class="filters"><label class="f">البريد أو <span dir="ltr">doc01@pw.almaaqal.app</span><input class="inp" id="bEmail" dir="ltr" required></label><label class="f">السبب<input class="inp" id="bReason"></label><button class="btn" type="submit">حظر</button></form>
        <div class="tablewrap">${r.blocked.length ? `<table><tr><th>البريد</th><th>السبب</th><th>بواسطة</th><th>التاريخ</th><th></th></tr>${r.blocked.map(b => `<tr><td class="mono" dir="ltr">${esc(b.email)}</td><td>${esc(b.reason || "")}</td><td class="mono" dir="ltr">${esc(b.added_by)}</td><td>${when(b.created_at)}</td><td><button class="btn sm" data-unb="${esc(b.email)}" type="button">إلغاء الحظر</button></td></tr>`).join("")}</table>` : '<div class="empty">لا يوجد</div>'}</div></div>`;
    const edit = u => {
      const roles = Object.entries(ROLES).filter(([k]) => ME.can.admins || k === "supervisor");
      dialog(`<h2>${u ? "تعديل حساب" : "حساب جديد"}</h2>
        <label class="f">البريد<input class="inp" name="email" dir="ltr" required ${u ? "readonly" : ""} value="${esc(u ? u.email : "")}" placeholder="name@gmail.com أو doc01@pw.almaaqal.app"></label>
        <label class="f">الدور<select class="sel" name="role">${roles.map(([k, v]) => `<option value="${k}" ${u && u.role === k ? "selected" : (!u && k === "supervisor" ? "selected" : "")}>${v}</option>`).join("")}</select></label>
        ${ME.can.admins ? `<p class="note" style="margin:0">اختيار «المدير» ينقل الإدارة إلى هذا الحساب، ويصبح حسابك نائباً.</p>` : ""}
        <div id="dDepts"><span class="f">أقسام المشرف</span>${deptPicker(u && u.depts)}</div>
        <label class="f">ملاحظة<input class="inp" name="note" value="${esc(u ? u.note || "" : "")}"></label>
        <label><input type="checkbox" name="active" ${!u || u.active ? "checked" : ""}> الحساب فعّال</label>`, async f => {
        const role = f.role.value;
        if (role === "owner" && (!u || u.role !== "owner") && !confirm("نقل إدارة البوابة إلى " + f.email.value + "؟ ستصبح نائباً.")) throw new Error("أُلغي النقل");
        await api("v1/portal/users/save", { email: f.email.value, role, depts: role === "supervisor" ? picked(f) : null, note: f.note.value, active: f.active.checked });
        toast("حُفظ الحساب"); if (role === "owner") return start(); users();
      });
      const sync = () => $("dDepts").hidden = $("dlgForm").role.value !== "supervisor";
      $("dlgForm").role.onchange = sync; sync();
    };
    $("uAdd").onclick = () => edit(null);
    V().querySelectorAll("[data-edit]").forEach(b => b.onclick = () => edit(r.users.find(u => u.email === b.dataset.edit)));
    V().querySelectorAll("[data-del]").forEach(b => b.onclick = async () => { if (!confirm("حذف الحساب " + b.dataset.del + "؟")) return; try { await api("v1/portal/users/delete", { email: b.dataset.del }); toast("حُذف"); users(); } catch (e) { toast(e.message); } });
    $("bForm").onsubmit = async e => { e.preventDefault(); try { await api("v1/portal/blocked", { email: $("bEmail").value, reason: $("bReason").value, on: 1 }); toast("حُظر الحساب"); users(); } catch (x) { toast(x.message); } };
    V().querySelectorAll("[data-unb]").forEach(b => b.onclick = async () => { try { await api("v1/portal/blocked", { email: b.dataset.unb, on: 0 }); toast("أُلغي الحظر"); users(); } catch (e) { toast(e.message); } });
  }

  // ------------------------------------------------------------------ الكليات والأقسام
  async function colleges() {
    COLLEGES = null;
    const [c, sec] = await Promise.all([getColleges(), api("v1/portal/sections")]);
    V().innerHTML = `<div class="row spread" style="margin-bottom:8px"><h1>الكليات والأقسام</h1><a class="btn" href="/amu/admin/" target="_blank" rel="noopener">تعديل الأسماء في لوحة مدير البوابة</a></div>
      <p class="muted" style="margin-top:0">القائمة نفسها التي يختار منها الطالب قسمه في البوابة وفي منظومة راصد الإشارة. لكل قسم: مشرفوه، وزياراته، والتفرعات المخفية عنه. «التفرعات» تُخفي قسماً من البوابة عن طلبة أقسام بعينها.</p>
      <div class="tree">${(c.colleges || []).map(col => `<div class="card"><h3>${esc(col.name)} <small class="muted">${(col.depts || []).length} قسم</small></h3>
        <table><tr><th>القسم</th><th>المشرفون</th><th>زيارات 30 يوماً</th><th></th></tr>${(col.depts || []).map(d => `<tr><td><b>${esc(d.name)}</b>${d.hidden_sections.length ? `<div>${d.hidden_sections.map(s => `<span class="pill-x" style="color:#b91c1c">مخفي: ${esc(s)}</span>`).join("")}</div>` : ""}</td>
          <td>${d.supervisors.length ? d.supervisors.map(esc).join("، ") : '<span class="muted">—</span>'}</td><td class="n">${fmt(d.visits_30d)}</td>
          <td><button class="btn sm" data-dept="${esc(d.id)}" type="button">التفرعات</button></td></tr>`).join("")}</table></div>`).join("")}</div>`;
    V().querySelectorAll("[data-dept]").forEach(b => b.onclick = () => {
      const id = b.dataset.dept;
      dialog(`<h2>تفرعات قسم ${esc(deptName(id))}</h2><p class="note" style="margin:0">المؤشَّر عليه يُخفى عن طلبة هذا القسم فقط.</p>
        <div class="depts">${sec.sections.map(s => `<label style="display:flex"><input type="checkbox" name="sec" value="${esc(s.id)}" ${s.depts_off.includes(id) ? "checked" : ""}> ${esc(s.name)} <small class="muted">(${s.system === "amu" ? "بوابة القسم" : "راصد الإشارة"})</small></label>`).join("")}</div>`, async f => {
        const on = new Set([...f.querySelectorAll('input[name="sec"]:checked')].map(x => x.value));
        for (const s of sec.sections) {
          const has = s.depts_off.includes(id);
          if (has === on.has(s.id)) continue;
          const off = has ? s.depts_off.filter(x => x !== id) : s.depts_off.concat(id);
          await api("v1/portal/sections/save", { id: s.id, status: s.status, message: s.message || "", depts_off: off, sort: s.sort });
        }
        toast("حُفظت تفرعات القسم"); colleges();
      });
    });
  }

  // ------------------------------------------------------------------ التفرعات
  async function sections() {
    await getColleges();
    const r = await api("v1/portal/sections");
    const grp = { amu: "بوابة القسم", raasid: "منظومة راصد الإشارة" };
    V().innerHTML = `<div class="row spread" style="margin-bottom:8px"><h1>التفرعات</h1><button class="btn primary" id="sAdd" type="button">إضافة تفرع</button></div>
      <p class="muted" style="margin-top:0">حالة كل صفحة وقسم في البوابتين: <span class="st visible">ظاهر</span> <span class="st locked">يتطلب الدخول</span> (بحساب البوابة) <span class="st maintenance">صيانة</span> (تظهر رسالة بدل المحتوى) <span class="st hidden">مخفي</span> (تُخفى روابطه ويُمنع فتحه). المدير ونائبه يرون كل شيء دائماً مع تنبيه بالحالة. التغيير يسري خلال دقيقة.</p>
      ${Object.entries(grp).map(([g, label]) => `<div class="card" style="margin-bottom:14px"><h3>${label}</h3><div class="tablewrap"><table><tr><th>التفرع</th><th>الحالة</th><th>الرسالة للزائر</th><th>مخفي في أقسام</th><th></th></tr>
        ${r.sections.filter(s => s.system === g).map(s => `<tr><td><b>${esc(s.name)}</b><div class="mono muted" dir="ltr" style="font-size:12px">${esc(s.url)}</div></td>
          <td><select class="sel" data-st="${esc(s.id)}">${Object.entries(STATUSES).map(([k, v]) => `<option value="${k}" ${s.status === k ? "selected" : ""}>${v}</option>`).join("")}</select></td>
          <td class="td-wrap">${esc(s.message || "")}</td><td class="td-wrap">${s.depts_off.length ? s.depts_off.map(d => `<span class="pill-x">${esc(deptName(d))}</span>`).join("") : "—"}</td>
          <td><button class="btn sm" data-ed="${esc(s.id)}" type="button">تعديل</button>${s.builtin ? "" : ` <button class="btn sm danger" data-rm="${esc(s.id)}" type="button">حذف</button>`}</td></tr>`).join("")}</table></div></div>`).join("")}`;
    const byId = id => r.sections.find(s => s.id === id);
    const save = async (s, patch) => { await api("v1/portal/sections/save", Object.assign({ id: s.id, status: s.status, message: s.message || "", depts_off: s.depts_off, sort: s.sort, name: s.name }, patch)); };
    V().querySelectorAll("[data-st]").forEach(sel => sel.onchange = async () => {
      try { await save(byId(sel.dataset.st), { status: sel.value }); toast(`${byId(sel.dataset.st).name}: ${STATUSES[sel.value]}`); sections(); } catch (e) { toast(e.message); }
    });
    const edit = s => dialog(`<h2>${s ? "تعديل " + esc(s.name) : "تفرع جديد"}</h2>
      ${s ? "" : `<label class="f">المعرّف (لاتيني)<input class="inp" name="id" dir="ltr" required pattern="[a-z0-9-]{3,40}" placeholder="amu-forms"></label>
        <label class="f">النظام<select class="sel" name="system"><option value="amu">بوابة القسم</option><option value="raasid">منظومة راصد الإشارة</option></select></label>
        <label class="f">الرابط<input class="inp" name="url" dir="ltr" required placeholder="https://it-basra.com/amu/forms/"></label>`}
      <label class="f">الاسم<input class="inp" name="name" required value="${esc(s ? s.name : "")}"></label>
      <label class="f">الحالة<select class="sel" name="status">${Object.entries(STATUSES).map(([k, v]) => `<option value="${k}" ${s && s.status === k ? "selected" : ""}>${v}</option>`).join("")}</select></label>
      <label class="f">رسالة للزائر (اختيارية)<input class="inp" name="message" value="${esc(s ? s.message || "" : "")}" placeholder="مثال: متوقف لتحديث المحتوى حتى الأحد"></label>
      <label class="f">الترتيب<input class="inp" name="sort" type="number" value="${s ? s.sort : 500}"></label>
      <span class="f">إخفاء عن طلبة هذه الأقسام فقط</span>${deptPicker(s && s.depts_off)}`, async f => {
      const body = { name: f.name.value, status: f.status.value, message: f.message.value, sort: +f.sort.value, depts_off: picked(f) };
      if (s) await save(s, body); else await api("v1/portal/sections/save", Object.assign(body, { id: f.id.value, system: f.system.value, url: f.url.value }));
      toast("حُفظ التفرع"); sections();
    });
    $("sAdd").onclick = () => edit(null);
    V().querySelectorAll("[data-ed]").forEach(b => b.onclick = () => edit(byId(b.dataset.ed)));
    V().querySelectorAll("[data-rm]").forEach(b => b.onclick = async () => { if (!confirm("حذف التفرع؟")) return; try { await api("v1/portal/sections/delete", { id: b.dataset.rm }); toast("حُذف"); sections(); } catch (e) { toast(e.message); } });
  }

  // ------------------------------------------------------------------ الأخبار والإعلانات
  const LVL = { info: "معلومة", warn: "تنبيه", urgent: "عاجل" }, SYS = { all: "البوابتان", amu: "بوابة القسم", raasid: "راصد الإشارة" };
  const toLocal = s => { if (!s) return ""; const d = new Date(s.replace(" ", "T") + "Z"); const p = n => String(n).padStart(2, "0"); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
  async function news() {
    await getColleges();
    const r = await api("v1/portal/news");
    const mine = n => ME.can.news_all || n.created_by === ME.email;
    V().innerHTML = `<div class="row spread" style="margin-bottom:8px"><h1>الأخبار والإعلانات</h1><button class="btn primary" id="nAdd" type="button">إعلان جديد</button></div>
      <p class="muted" style="margin-top:0">يظهر الإعلان شريطاً أعلى صفحات البوابة المختارة، لكل الطلبة أو لأقسام محددة، بين وقتي البداية والنهاية. «عاجل» لا يمكن إغلاقه. ${ME.can.news_all ? "" : "تنشر لأقسامك فقط."}</p>
      <div class="card"><div class="tablewrap">${r.news.length ? `<table><tr><th>الإعلان</th><th>النوع</th><th>يظهر في</th><th>الأقسام</th><th>المدة</th><th>بواسطة</th><th>الحالة</th><th></th></tr>
      ${r.news.map(n => `<tr><td class="td-wrap"><b>${n.pinned ? "📌 " : ""}${esc(n.title)}</b><div class="muted" style="font-size:13px">${esc((n.body || "").slice(0, 140))}</div></td>
        <td><i class="lvl ${n.level}"></i>${LVL[n.level]}</td><td>${SYS[n.systems]}</td><td class="td-wrap">${n.depts ? n.depts.map(d => `<span class="pill-x">${esc(deptName(d))}</span>`).join("") : "الكل"}</td>
        <td style="font-size:12px">${n.starts ? "من " + when(n.starts) + "<br>" : ""}${n.ends ? "حتى " + when(n.ends) : "بلا نهاية"}</td><td class="mono" dir="ltr" style="font-size:12px">${esc(n.created_by)}</td>
        <td>${n.active ? '<span class="st visible">منشور</span>' : '<span class="st hidden">موقوف</span>'}</td>
        <td>${mine(n) ? `<button class="btn sm" data-ne="${n.id}" type="button">تعديل</button> <button class="btn sm danger" data-nd="${n.id}" type="button">حذف</button>` : ""}</td></tr>`).join("")}</table>` : '<div class="empty">لا توجد إعلانات</div>'}</div></div>`;
    const edit = n => dialog(`<h2>${n ? "تعديل إعلان" : "إعلان جديد"}</h2>
      <label class="f">العنوان<input class="inp" name="title" required maxlength="150" value="${esc(n ? n.title : "")}"></label>
      <label class="f">النص<textarea class="inp" name="body" rows="3" maxlength="2000">${esc(n ? n.body || "" : "")}</textarea></label>
      <div class="filters" style="margin:0"><label class="f">النوع<select class="sel" name="level">${Object.entries(LVL).map(([k, v]) => `<option value="${k}" ${n && n.level === k ? "selected" : ""}>${v}</option>`).join("")}</select></label>
        <label class="f">يظهر في<select class="sel" name="systems">${Object.entries(SYS).map(([k, v]) => `<option value="${k}" ${n && n.systems === k ? "selected" : ""}>${v}</option>`).join("")}</select></label></div>
      <div class="filters" style="margin:0"><label class="f">يبدأ<input class="inp" type="datetime-local" name="starts" value="${n ? toLocal(n.starts) : ""}"></label><label class="f">ينتهي<input class="inp" type="datetime-local" name="ends" value="${n ? toLocal(n.ends) : ""}"></label></div>
      <span class="f">${ME.can.news_all ? "لأقسام محددة (اتركها فارغة للجميع)" : "الأقسام"}</span>${deptPicker(n && n.depts, ME.can.news_all ? null : ME.depts)}
      <label><input type="checkbox" name="pinned" ${n && n.pinned ? "checked" : ""}> تثبيت في الأعلى</label>
      <label><input type="checkbox" name="active" ${!n || n.active ? "checked" : ""}> منشور</label>`, async f => {
      const iso = v => v ? new Date(v).toISOString() : "";
      await api("v1/portal/news/save", { id: n ? n.id : 0, title: f.title.value, body: f.body.value, level: f.level.value, systems: f.systems.value,
        depts: picked(f), pinned: f.pinned.checked, active: f.active.checked, starts: iso(f.starts.value), ends: iso(f.ends.value) });
      toast("حُفظ الإعلان"); news();
    });
    $("nAdd").onclick = () => edit(null);
    V().querySelectorAll("[data-ne]").forEach(b => b.onclick = () => edit(r.news.find(n => n.id === +b.dataset.ne)));
    V().querySelectorAll("[data-nd]").forEach(b => b.onclick = async () => { if (!confirm("حذف الإعلان؟")) return; try { await api("v1/portal/news/delete", { id: +b.dataset.nd }); toast("حُذف"); news(); } catch (e) { toast(e.message); } });
  }

  // ------------------------------------------------------------------ الإحصاءات
  async function stats() {
    await getColleges();
    const days = +(sessionStorage.getItem("hub_days") || 30);
    const [r, sec] = await Promise.all([api("v1/portal/stats?days=" + days), api("v1/portal/sections")]);
    const secName = id => (sec.sections.find(s => s.id === id) || {}).name || id;
    const bars = (rows, key, name) => { const mx = Math.max(1, ...rows.map(x => x.n)); return rows.length ? `<table>${rows.map(x => `<tr><td>${esc(name(x[key]))}</td><td style="width:55%"><div class="hbar"><i style="width:${100 * x.n / mx}%"></i><span class="n">${fmt(x.n)}</span></div></td></tr>`).join("")}</table>` : '<div class="empty">لا بيانات بعد</div>'; };
    const mx = Math.max(1, ...r.daily.map(x => x.n));
    V().innerHTML = `<div class="row spread" style="margin-bottom:8px"><h1>الإحصاءات</h1><select class="sel" id="sDays">${[7, 30, 90, 365].map(d => `<option value="${d}" ${d === days ? "selected" : ""}>آخر ${d} يوماً</option>`).join("")}</select></div>
      <p class="muted" style="margin-top:0">الزائر يُحسب مرة واحدة لكل تفرع في اليوم، دون حفظ عنوانه أو بياناته (بصمة مجزّأة تُحذف بعد يومين).</p>
      <div class="tiles"><div class="tile"><span>اليوم</span><b>${fmt(r.today)}</b></div><div class="tile"><span>الفترة</span><b>${fmt(r.total)}</b></div><div class="tile"><span>متوسط يومي</span><b>${fmt(Math.round(r.total / Math.max(1, r.daily.length)))}</b></div></div>
      <div class="card" style="margin-top:14px"><h3>الزيارات اليومية</h3><div class="daily">${r.daily.map(x => `<div style="height:${100 * x.n / mx}%" title="${x.day}: ${x.n}"></div>`).join("")}</div></div>
      <div class="grid g2" style="margin-top:14px"><div class="card"><h3>حسب التفرع</h3>${bars(r.sections, "section", secName)}</div><div class="card"><h3>حسب القسم</h3>${bars(r.depts, "dept", deptName)}</div></div>
      ${r.logins.length ? `<div class="card" style="margin-top:14px"><h3>دخول حسابات الإدارة</h3><div class="tablewrap"><table><tr><th>اليوم</th><th>الحساب</th><th>مرات</th></tr>${r.logins.map(l => `<tr><td>${esc(l.day)}</td><td class="mono" dir="ltr">${esc(l.email)}</td><td class="n">${l.n}</td></tr>`).join("")}</table></div></div>` : ""}`;
    $("sDays").onchange = () => { sessionStorage.setItem("hub_days", $("sDays").value); stats(); };
  }

  // ------------------------------------------------------------------ سجل العمليات
  const ACT = { login: "دخول", user_add: "إضافة حساب", user_update: "تعديل حساب", user_delete: "حذف حساب", transfer_owner: "نقل الإدارة", block: "حظر", unblock: "إلغاء حظر",
    section_add: "إضافة تفرع", section_update: "تعديل تفرع", section_delete: "حذف تفرع", news_add: "نشر إعلان", news_update: "تعديل إعلان", news_delete: "حذف إعلان", dash_password: "كلمة مرور لوحة الراصد" };
  const auditTable = rows => rows.length ? `<table><tr><th>الوقت</th><th>الحساب</th><th>العملية</th><th>الهدف</th><th>التفاصيل</th></tr>${rows.map(a => `<tr><td>${when(a.at)}</td><td class="mono" dir="ltr">${esc(a.email)}</td><td>${esc(ACT[a.action] || a.action)}</td><td class="mono" dir="ltr">${esc(a.target || "")}</td><td class="td-wrap">${esc(a.detail || "")}</td></tr>`).join("")}</table>` : '<div class="empty">لا عمليات بعد</div>';
  async function audit() {
    const r = await api("v1/portal/audit?limit=500");
    V().innerHTML = `<div class="row spread" style="margin-bottom:8px"><h1>سجل العمليات</h1><input class="inp" id="aQ" placeholder="بحث…" style="max-width:240px"></div>
      <p class="muted" style="margin-top:0">كل دخول وتعديل في مركز الإدارة، مع الحساب والوقت وعنوان الشبكة. لا يمكن حذف السجل من الواجهة.</p><div class="card"><div class="tablewrap" id="aT"></div></div>`;
    const draw = () => { const q = $("aQ").value.trim(); $("aT").innerHTML = auditTable(q ? r.log.filter(a => JSON.stringify(a).includes(q) || (ACT[a.action] || "").includes(q)) : r.log); };
    $("aQ").oninput = draw; draw();
  }

  // ------------------------------------------------------------------ البدء
  api("v1/portal/me").then(r => { window.__fbProject = r.project; return start(); }).catch(() => showLogin(""));
})();
