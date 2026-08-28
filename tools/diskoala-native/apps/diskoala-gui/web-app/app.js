// Scai GUI 主逻辑：状态机（empty → scanning → results）+ 渲染 + 桥接
(function () {
  "use strict";

  const S = {
    view: "empty",
    root: "",
    rows: [],
    filter: "all",
    checked: new Set(), // row key（绝对路径）
    selectedKey: null,
    sortKey: "size",
    sortAsc: false,
    limit: 500,
    canMore: false,
    totalBytes: 0,
    theme: "light",
    lastScanAt: "",
    maker: "Koding Studio",
    homepage: "",
    version: "",
    scanning: false,
    detailKey: null,
    inited: false,
    _pendingTrash: null,
    _dedupedSelection: [],
  };

  const $ = (id) => document.getElementById(id);
  const api = () => (window.pywebview && window.pywebview.api) || null;
  const RISK_LABEL = { safe: "可清理", review: "需确认", risky: "高风险" };
  const RISK_COLOR = () => {
    const dark = document.documentElement.dataset.theme === "dark";
    return {
      safe: dark ? "#4ADE80" : "#22C55E",
      review: dark ? "#FBBF24" : "#F59E0B",
      risky: dark ? "#F87171" : "#EF4444",
      other: dark ? "#64748B" : "#CBD5E1",
    };
  };

  // 与 Python scai.human_size 同规则
  function human(bytes) {
    const units = ["B", "KB", "MB", "GB", "TB", "PB"];
    let v = bytes, i = 0;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i += 1; }
    return i === 0 ? bytes + " B" : v.toFixed(1) + " " + units[i];
  }
  function humanSplit(bytes) {
    const t = human(bytes);
    const idx = t.lastIndexOf(" ");
    return { value: t.slice(0, idx), unit: t.slice(idx + 1) };
  }
  function fmtCount(n) { return Number(n).toLocaleString("en-US"); }

  function show(view) {
    S.view = view;
    $("view-empty").classList.toggle("hidden", view !== "empty");
    $("view-scanning").classList.toggle("hidden", view !== "scanning");
    $("view-results").classList.toggle("hidden", view !== "results");
    $("scanbar").classList.toggle("hidden", view === "empty");
  }

  function toast(text, kind) {
    const el = document.createElement("div");
    el.className = "toast " + (kind || "");
    el.textContent = text;
    $("toast-box").appendChild(el);
    setTimeout(() => el.remove(), 4200);
  }

  // ---------------- 渲染 ----------------

  function visibleRows() {
    const rows = S.filter === "all" ? S.rows : S.rows.filter((r) => r.risk === S.filter);
    const dir = S.sortAsc ? 1 : -1;
    return rows.slice().sort((a, b) => {
      if (S.sortKey === "mtime") return (a.mtime - b.mtime) * dir;
      return (a.size - b.size) * dir;
    });
  }

  function counts() {
    return {
      all: S.rows.length,
      safe: S.rows.filter((r) => r.risk === "safe").length,
      review: S.rows.filter((r) => r.risk === "review").length,
      risky: S.rows.filter((r) => r.risk === "risky").length,
    };
  }

  function sums() {
    let safe = 0, review = 0;
    for (const r of S.rows) {
      if (r.risk === "safe") safe += r.size;
      else if (r.risk === "review") review += r.size;
    }
    return { safe, review };
  }

  function renderAll() {
    renderTabs();
    renderTable();
    renderCards();
    renderTreemap();
    renderDetail();
    updateSelection();
  }

  function renderTabs() {
    const c = counts();
    $("count-all").textContent = fmtCount(c.all);
    $("count-safe").textContent = fmtCount(c.safe);
    $("count-review").textContent = fmtCount(c.review);
    $("count-risky").textContent = fmtCount(c.risky);
    document.querySelectorAll(".tab").forEach((t) => t.classList.toggle("active", t.dataset.filter === S.filter));
  }

  function renderCards() {
    const s = sums();
    const t = humanSplit(S.totalBytes || 0);
    $("card-total").textContent = t.value;
    $("card-total-unit").textContent = t.unit;
    const sv = humanSplit(s.safe);
    $("card-safe").textContent = sv.value;
    $("card-safe-unit").textContent = sv.unit;
    const rv = humanSplit(s.review);
    $("card-review").textContent = rv.value;
    $("card-review-unit").textContent = rv.unit;
  }

  function renderTable() {
    const tbody = $("table-body");
    tbody.innerHTML = "";
    const rows = visibleRows();
    if (!rows.length) {
      const tr = document.createElement("tr");
      tr.className = "table-empty";
      const td = document.createElement("td");
      td.colSpan = 6;
      td.textContent = S.filter === "all" ? "本次扫描没有发现可展示的项目" : "该分类下暂无项目";
      tr.appendChild(td);
      tbody.appendChild(tr);
    }
    for (const row of rows) {
      const tr = document.createElement("tr");
      tr.dataset.key = row.key;
      tr.className = "clickable";
      if (row.risk === "risky") tr.classList.add("risky-row");
      if (row.key === S.selectedKey) tr.classList.add("selected");

      const tdCheck = document.createElement("td");
      tdCheck.className = "col-check";
      if (row.risk === "risky") {
        tdCheck.innerHTML = '<span class="lock-cell" title="系统管理，不可删除">' + window.scaiIcon("lock", 14) + "</span>";
      } else {
        const cb = document.createElement("input");
        cb.type = "checkbox";
        cb.className = "row-check";
        cb.checked = S.checked.has(row.key);
        cb.title = "勾选后移到回收站";
        cb.addEventListener("click", (e) => e.stopPropagation());
        cb.addEventListener("change", () => { toggleCheck(row.key, cb.checked); });
        tdCheck.appendChild(cb);
      }

      const tdName = document.createElement("td");
      tdName.className = "td-name";
      const nameCell = document.createElement("div");
      nameCell.className = "name-cell";
      nameCell.innerHTML = window.scaiIcon(row.kind === "文件夹" ? "folder" : "file", 15) + '<span class="name-text"></span>';
      nameCell.querySelector(".name-text").textContent = row.display;
      nameCell.title = row.display;
      tdName.appendChild(nameCell);

      const tdSize = document.createElement("td");
      tdSize.className = "td-size";
      tdSize.textContent = row.human;

      const tdRisk = document.createElement("td");
      const pill = document.createElement("span");
      pill.className = "risk-pill " + row.risk;
      pill.textContent = RISK_LABEL[row.risk];
      tdRisk.appendChild(pill);

      const tdCat = document.createElement("td");
      tdCat.className = "col-category";
      tdCat.textContent = row.category;
      tdCat.title = row.category;

      const tdM = document.createElement("td");
      tdM.className = "col-mtime";
      tdM.textContent = row.mtimeText;

      tr.append(tdCheck, tdName, tdSize, tdRisk, tdCat, tdM);
      tr.addEventListener("click", () => selectRow(row.key));
      tr.addEventListener("dblclick", () => reveal(row.key));
      tbody.appendChild(tr);
    }
    $("sort-size").textContent = S.sortKey === "size" ? (S.sortAsc ? "▲" : "▼") : "";
    $("sort-mtime").textContent = S.sortKey === "mtime" ? (S.sortAsc ? "▲" : "▼") : "";
    $("btn-more").classList.toggle("hidden", !S.canMore);
    $("more-limit").textContent = fmtCount(S.limit + 500);
  }

  function renderTreemap() {
    const colors = RISK_COLOR();
    const dirs = S.rows.filter((r) => r.kind === "文件夹").slice().sort((a, b) => b.size - a.size);
    const top = dirs.slice(0, 8).map((d) => ({
      key: d.key, name: d.display, size: d.size, human: d.human,
      color: colors[d.risk] || colors.other,
    }));
    const rest = dirs.slice(8);
    if (rest.length) {
      top.push({
        key: null, name: "其他 (" + rest.length + " 项)",
        size: rest.reduce((a, r) => a + r.size, 0),
        human: "", color: colors.other,
      });
    }
    window.scaiTreemap.render($("treemap"), top, (node) => {
      selectRow(node.key);
      const tr = document.querySelector('tr[data-key="' + cssEsc(node.key) + '"]');
      if (tr) tr.scrollIntoView({ block: "nearest" });
    }, (node) => {
      // 双击下钻：把扫描根切到该目录重新扫描
      startScan(node.key, $("include-all").checked);
    });
  }

  function renderDetail() {
    const row = S.rows.find((r) => r.key === S.detailKey);
    $("detail-empty").classList.toggle("hidden", !!row);
    $("detail-body").classList.toggle("hidden", !row);
    if (!row) return;
    $("detail-icon").innerHTML = window.scaiIcon(row.kind === "文件夹" ? "folder" : "file", 18);
    $("detail-name").textContent = row.display;
    $("detail-size").textContent = row.human + "（" + fmtCount(row.size) + " B）";
    $("detail-risk").innerHTML = "";
    const pill = document.createElement("span");
    pill.className = "risk-pill " + row.risk;
    pill.textContent = RISK_LABEL[row.risk] + (row.risk === "risky" ? " · 系统管理，不可删除" : "");
    $("detail-risk").appendChild(pill);
    $("detail-category").textContent = row.category;
    $("detail-reason").textContent = row.reason;
    $("detail-action").textContent = row.risk === "risky"
      ? "通过系统设置或磁盘清理工具管理，请勿直接删除。"
      : "移到回收站（可恢复）。" + row.action;
    $("detail-mtime").textContent = row.mtimeText;
  }

  function updateSelection() {
    const keys = dedupeClient([...S.checked]);
    S._dedupedSelection = keys;
    const items = keys.map((k) => S.rows.find((r) => r.key === k)).filter(Boolean);
    const total = items.reduce((a, r) => a + r.size, 0);
    $("btn-clear-sel").classList.toggle("hidden", items.length === 0);
    if (!items.length) {
      $("selection-summary").textContent = "未选择项目";
      $("btn-trash").disabled = true;
    } else {
      const safeN = items.filter((r) => r.risk === "safe").length;
      const revN = items.length - safeN;
      const parts = ["已选 <b></b> 项"];
      if (revN > 0) parts.push('<span class="risk-breakdown">可清理 ' + safeN + " · 需确认 " + revN + "</span>");
      parts.push("共 <b></b>");
      $("selection-summary").innerHTML = parts.join("，");
      const bs = $("selection-summary").querySelectorAll("b");
      bs[0].textContent = fmtCount(items.length);
      bs[1].textContent = human(total);
      $("btn-trash").disabled = false;
    }
  }

  // 客户端父子去重（与服务端 paths_overlap 同规则）
  function dedupeClient(keys) {
    const norm = (p) => p.replace(/\//g, "\\").replace(/\\+$/, "").toLowerCase();
    const ks = keys.map((k) => ({ key: k, n: norm(k) }));
    return ks
      .filter((a) => !ks.some((b) => b !== a && (a.n + "\\").startsWith(b.n + "\\")))
      .map((x) => x.key);
  }

  function toggleCheck(key, on) {
    if (on) S.checked.add(key); else S.checked.delete(key);
    updateSelection();
  }

  function selectRow(key) {
    S.selectedKey = key;
    S.detailKey = key;
    document.querySelectorAll("#table-body tr").forEach((tr) => {
      tr.classList.toggle("selected", tr.dataset.key === key);
    });
    renderDetail();
  }

  function cssEsc(t) { return (window.CSS && CSS.escape) ? CSS.escape(t) : t.replace(/"/g, '\\"'); }

  // ---------------- 流程 ----------------

  async function startScan(path, includeAll, limit) {
    const a = api();
    if (!a || S.scanning) return;
    const p = (path || "").trim();
    if (!p) { toast("请先选择要扫描的目录", "warning"); return; }
    S.scanning = true;
    S.root = p;
    $("scan-path").value = p;
    $("scanning-title").textContent = "正在扫描 " + p;
    $("scanning-hint").textContent = /:\\\\?$/.test(p.trim()) ? "全盘扫描可能需要数分钟，请稍候" : "可在扫描完成后按风险筛选与勾选";
    show("scanning");
    const res = await a.start_scan({ path: p, include_all: !!includeAll, limit: limit || S.limit });
    if (!res || !res.ok) {
      S.scanning = false;
      show(S.rows.length ? "results" : "empty");
      toast("扫描失败: " + ((res && res.error) || "路径不存在或无法读取"), "error");
      return;
    }
    pollProgress();
  }

  let pollTimer = null;
  function pollProgress() {
    clearInterval(pollTimer);
    pollTimer = setInterval(async () => {
      const a = api();
      if (!a) return;
      const p = await a.get_progress();
      if (!p) return;
      $("stat-dirs").textContent = fmtCount(p.dirs || 0);
      $("stat-files").textContent = fmtCount(p.files || 0);
      $("stat-elapsed").textContent = fmtElapsed(p.elapsed || 0);
      if (!p.running) {
        clearInterval(pollTimer);
        pollTimer = null;
        S.scanning = false;
        if (p.phase === "cancelled") {
          toast("扫描已取消", "warning");
          show(S.rows.length ? "results" : "empty");
        } else if (p.phase === "error") {
          toast("扫描失败: " + (p.error || "未知错误"), "error");
          show(S.rows.length ? "results" : "empty");
        } else {
          await loadResults();
          show("results");
        }
      }
    }, 250);
  }

  function fmtElapsed(sec) {
    const m = Math.floor(sec / 60), s = Math.floor(sec % 60);
    return String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
  }

  async function loadResults() {
    const a = api();
    const res = await a.get_results();
    if (!res || !res.ok) { toast("获取扫描结果失败", "error"); return; }
    const d = res.data;
    S.root = d.root;
    S.rows = d.rows;
    S.totalBytes = d.total_bytes;
    S.limit = d.limit;
    S.canMore = d.can_more;
    S.checked = new Set();
    S.selectedKey = null;
    S.detailKey = null;
    $("scan-path").value = d.root;
    renderAll();
  }

  // ---------------- 删除流程 ----------------

  async function openConfirm() {
    const a = api();
    const keys = S._dedupedSelection || [];
    if (!keys.length) return;
    const res = await a.plan_trash(keys);
    if (!res || !res.ok) { toast("生成清理清单失败: " + ((res && res.error) || ""), "error"); return; }
    if (!res.items.length) { toast("没有可清理的项目（高风险项已自动排除）", "warning"); return; }
    S._pendingTrash = res.items.map((i) => i.key);
    $("modal-desc").textContent = "以下 " + res.items.length + " 个项目将移到回收站，可从回收站恢复。总大小: " + res.total_human + "。";
    const reviewCount = res.items.filter((i) => i.risk === "review").length;
    $("modal-warning").classList.toggle("hidden", reviewCount === 0);
    if (reviewCount) $("modal-warning-text").textContent = "所选项目中包含 " + reviewCount + " 项「需确认」，请再次核对。";
    const list = $("modal-list");
    list.innerHTML = "";
    for (const item of res.items.slice(0, 8)) {
      const tr = document.createElement("tr");
      const tdName = document.createElement("td");
      tdName.innerHTML = window.scaiIcon(item.kind === "文件夹" ? "folder" : "file", 14) + " ";
      const span = document.createElement("span");
      span.textContent = item.display;
      tdName.appendChild(span);
      tdName.title = item.key;
      const tdSize = document.createElement("td");
      tdSize.className = "col-size";
      tdSize.textContent = item.human;
      const tdRisk = document.createElement("td");
      const pill = document.createElement("span");
      pill.className = "risk-pill " + item.risk;
      pill.textContent = RISK_LABEL[item.risk];
      tdRisk.appendChild(pill);
      tr.append(tdName, tdSize, tdRisk);
      list.appendChild(tr);
    }
    $("modal-more").classList.toggle("hidden", res.items.length <= 8);
    $("modal-more").textContent = "… 以及另外 " + (res.items.length - 8) + " 项";
    $("modal-overlay").classList.remove("hidden");
  }

  function closeConfirm() {
    $("modal-overlay").classList.add("hidden");
    S._pendingTrash = null;
    resetPermArm();
  }

  // 永久删除两步确认：第一次点击进入待确认态，5 秒内再次点击才执行
  let permArmed = false;
  let permTimer = null;
  function resetPermArm() {
    permArmed = false;
    if (permTimer) { clearTimeout(permTimer); permTimer = null; }
    const b = $("modal-perm");
    b.classList.remove("armed");
    b.innerHTML = window.scaiIcon("trash") + "直接删除";
    $("perm-warning").classList.add("hidden");
  }
  function armPerm() {
    permArmed = true;
    const b = $("modal-perm");
    b.classList.add("armed");
    b.innerHTML = window.scaiIcon("alert") + "确认永久删除";
    $("perm-warning").classList.remove("hidden");
    if (permTimer) clearTimeout(permTimer);
    permTimer = setTimeout(resetPermArm, 5000);
  }

  async function doTrash(mode) {
    const a = api();
    if (!a || !S._pendingTrash) return;
    const keys = S._pendingTrash;
    const permanent = mode === "permanent";
    $("modal-confirm").disabled = true;
    $("modal-perm").disabled = true;
    const res = await a.do_trash(keys, permanent ? "permanent" : "recycle");
    $("modal-confirm").disabled = false;
    $("modal-perm").disabled = false;
    closeConfirm();
    if (!res || !res.ok) { toast("清理执行失败: " + ((res && res.error) || ""), "error"); return; }
    const removed = new Set(res.moved || []);
    S.rows = S.rows.filter((r) => !removed.has(r.key));
    for (const k of removed) S.checked.delete(k);
    S.totalBytes = Math.max(0, (S.totalBytes || 0) - (res.freed || 0));
    renderAll();
    const actionText = permanent ? "已永久删除 " : "已移到回收站 ";
    if (res.failures && res.failures.length) {
      toast(actionText + res.moved.length + " 项（" + res.freed_human + "），" + res.failures.length + " 项失败，详见日志", "warning");
    } else {
      toast(actionText + res.moved.length + " 项，释放约 " + res.freed_human + "。" + (permanent ? "" : "可从回收站恢复。") + "建议重新扫描查看最新空间分布。", permanent ? "warning" : "success");
    }
  }

  // ---------------- AI 提示词 ----------------

  async function openPrompt() {
    const a = api();
    const res = await a.ai_prompt();
    if (!res || !res.ok) { toast("请先完成一次扫描，再生成 AI 提示词。", "warning"); return; }
    $("prompt-text").value = res.prompt;
    $("prompt-overlay").classList.remove("hidden");
  }

  async function copyPrompt() {
    const text = $("prompt-text").value;
    let copied = false;
    try {
      await navigator.clipboard.writeText(text);
      copied = true;
    } catch (e) { /* 走 execCommand 兜底 */ }
    if (!copied) {
      const ta = $("prompt-text");
      ta.removeAttribute("readonly");
      ta.select();
      try { copied = document.execCommand("copy"); } catch (e) { /* 忽略 */ }
      ta.setAttribute("readonly", "");
      window.getSelection().removeAllRanges();
    }
    toast(copied ? "AI 提示词已复制到剪贴板，可直接粘贴给任意 AI。" : "复制失败，请手动全选复制。", copied ? "success" : "warning");
  }

  // ---------------- 其他 ----------------

  async function reveal(key) {
    const row = S.rows.find((r) => r.key === key);
    if (!row) return;
    const a = api();
    const res = await a.reveal(row.key);
    if (res && !res.ok && res.error === "missing") toast("路径已不存在: " + row.key, "warning");
  }

  function applyTheme(theme) {
    S.theme = theme;
    document.documentElement.dataset.theme = theme;
    $("btn-theme").innerHTML = window.scaiIcon(theme === "dark" ? "sun" : "moon");
    if (S.view === "results") renderTreemap();
  }

  function setFooter() {
    const date = S.lastScanAt ? S.lastScanAt.slice(0, 10) : "";
    const credit = S.homepage
      ? '<a href="' + S.homepage + '" style="color:inherit">Koding Studio</a>'
      : "Koding Studio";
    $("empty-footer").innerHTML = (date ? "上次扫描: " + date + " · " : "") + "Diskoala 由 " + credit + " 制作";
  }

  // ---------------- 关于 / 日志 ----------------

  function openAbout() {
    $("about-version").textContent = "v" + (S.version || "") + (S.maker ? "" : "");
    const makerEl = $("about-maker");
    makerEl.innerHTML = "";
    makerEl.textContent = S.maker || "Koding Studio";
    const hp = $("about-homepage");
    hp.innerHTML = "";
    if (S.homepage) {
      const a = document.createElement("a");
      a.href = S.homepage;
      a.textContent = S.homepage;
      hp.appendChild(a);
    } else {
      hp.textContent = "主页与社交媒体即将上线";
    }
    $("about-overlay").classList.remove("hidden");
  }

  async function openLogViewer() {
    const a = api();
    if (!a) return;
    $("log-overlay").classList.remove("hidden");
    await refreshLogViewer();
  }

  async function refreshLogViewer() {
    const a = api();
    if (!a) return;
    const res = await a.get_log(200);
    const list = $("log-list");
    list.innerHTML = "";
    if (!res || !res.ok || !res.entries.length) {
      const tr = document.createElement("tr");
      const td = document.createElement("td");
      td.colSpan = 5;
      td.style.textAlign = "center";
      td.style.color = "var(--muted)";
      td.textContent = "暂无清理记录";
      tr.appendChild(td);
      list.appendChild(tr);
      return;
    }
    for (const e of res.entries) {
      const tr = document.createElement("tr");
      const cells = [
        e.time || "-",
        "",
        e.human || "-",
        e.path || "",
        "",
      ];
      for (let i = 0; i < cells.length; i++) {
        const td = document.createElement("td");
        if (i === 1) {
          const span = document.createElement("span");
          span.className = "log-mode " + (e.mode_kind === "permanent" ? "permanent" : "recycle");
          span.textContent = e.mode;
          td.appendChild(span);
        } else if (i === 3) {
          td.textContent = cells[i];
          td.title = cells[i];
        } else if (i === 4) {
          if (e.ok) td.textContent = "成功";
          else {
            td.className = "log-result-fail";
            td.textContent = "失败";
            td.title = e.error || "";
          }
        } else {
          td.textContent = cells[i];
        }
        tr.appendChild(td);
      }
      list.appendChild(tr);
    }
  }

  function setFilter(filter) {
    S.filter = filter || "all";
    renderTabs();
    renderTable();
  }

  async function autoPlan() {
    const a = api();
    if (!a) return;
    const target = $("target-input").value.trim();
    if (!target) { toast("请输入目标空间，例如 20g 或 500m", "warning"); return; }
    const res = await a.auto_plan(target);
    if (!res || !res.ok) { toast("目标大小无效（示例: 20g、500m）: " + ((res && res.error) || ""), "warning"); return; }
    S.checked = new Set(res.paths);
    renderTable();
    updateSelection();
    toast("已按目标 " + res.target_human + " 勾选 " + res.count + " 项（约 " + res.total_human + "），请人工复核后再删除。", "success");
  }

  async function browseTo(inputId) {
    const a = api();
    if (!a) return;
    const res = await a.browse();
    if (res && res.ok && res.path) $(inputId).value = res.path;
  }

  // ---------------- 事件绑定 ----------------

  function bind() {
    $("btn-scan").addEventListener("click", () => startScan($("scan-path").value, $("include-all").checked));
    $("btn-empty-scan").addEventListener("click", () => startScan($("empty-path").value, false));
    $("btn-scan-all").addEventListener("click", () => startScan(window.__scaiComputerRoot, $("include-all").checked));
    $("btn-empty-scan-all").addEventListener("click", () => startScan(window.__scaiComputerRoot, false));
    $("btn-browse").addEventListener("click", () => browseTo("scan-path"));
    $("btn-empty-browse").addEventListener("click", () => browseTo("empty-path"));
    $("btn-cancel-scan").addEventListener("click", async () => { const a = api(); if (a) await a.cancel_scan(); });
    $("btn-clear-sel").addEventListener("click", () => { S.checked.clear(); renderTable(); updateSelection(); });

    // 键盘：路径回车触发扫描/勾选；Esc 关闭弹窗
    $("scan-path").addEventListener("keydown", (e) => { if (e.key === "Enter") startScan($("scan-path").value, $("include-all").checked); });
    $("empty-path").addEventListener("keydown", (e) => { if (e.key === "Enter") startScan($("empty-path").value, false); });
    $("target-input").addEventListener("keydown", (e) => { if (e.key === "Enter") autoPlan(); });
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      if (!$("modal-overlay").classList.contains("hidden")) closeConfirm();
      else if (!$("prompt-overlay").classList.contains("hidden")) $("prompt-overlay").classList.add("hidden");
      else if (!$("log-overlay").classList.contains("hidden")) $("log-overlay").classList.add("hidden");
      else if (!$("about-overlay").classList.contains("hidden")) $("about-overlay").classList.add("hidden");
    });

    document.querySelectorAll(".tab").forEach((t) =>
      t.addEventListener("click", () => setFilter(t.dataset.filter))
    );
    document.querySelectorAll(".stat-card.clickable").forEach((card) =>
      card.addEventListener("click", () => setFilter(card.dataset.filter))
    );
    document.querySelectorAll(".sortable").forEach((th) =>
      th.addEventListener("click", () => {
        const key = th.dataset.sort;
        if (S.sortKey === key) S.sortAsc = !S.sortAsc;
        else { S.sortKey = key; S.sortAsc = false; }
        renderTable();
      })
    );
    $("include-all").addEventListener("change", () => startScan(S.root || $("scan-path").value, $("include-all").checked));
    $("btn-more").addEventListener("click", () => startScan(S.root, $("include-all").checked, S.limit + 500));

    $("btn-trash").addEventListener("click", openConfirm);
    $("modal-cancel").addEventListener("click", closeConfirm);
    $("modal-close").addEventListener("click", closeConfirm);
    $("modal-confirm").addEventListener("click", () => doTrash("recycle"));
    $("modal-perm").addEventListener("click", () => { if (!permArmed) armPerm(); else doTrash("permanent"); });
    $("modal-overlay").addEventListener("click", (e) => { if (e.target === $("modal-overlay")) closeConfirm(); });

    $("btn-ai-prompt").addEventListener("click", openPrompt);
    $("prompt-copy").addEventListener("click", copyPrompt);
    $("prompt-cancel").addEventListener("click", () => $("prompt-overlay").classList.add("hidden"));
    $("prompt-close").addEventListener("click", () => $("prompt-overlay").classList.add("hidden"));

    $("btn-open-log").addEventListener("click", openLogViewer);
    $("log-close").addEventListener("click", () => $("log-overlay").classList.add("hidden"));
    $("log-cancel").addEventListener("click", () => $("log-overlay").classList.add("hidden"));
    $("log-refresh").addEventListener("click", refreshLogViewer);
    $("log-open-file").addEventListener("click", async () => { const a = api(); if (a) await a.open_log(); });
    $("btn-about").addEventListener("click", openAbout);
    $("brand-about").addEventListener("click", openAbout);
    $("about-close").addEventListener("click", () => $("about-overlay").classList.add("hidden"));
    $("about-cancel").addEventListener("click", () => $("about-overlay").classList.add("hidden"));
    $("btn-reveal-detail").addEventListener("click", () => reveal(S.detailKey));
    $("btn-auto-plan").addEventListener("click", autoPlan);

    $("btn-theme").addEventListener("click", async () => {
      applyTheme(S.theme === "dark" ? "light" : "dark");
      const a = api();
      if (a) await a.save_prefs({ theme: S.theme });
    });
  }

  // ---------------- 启动 ----------------

  async function init() {
    if (S.inited) return;
    S.inited = true;
    bind();
    const a = api();
    if (!a) return;
    try {
      const st = await a.get_initial_state();
      if (st && st.ok) {
        window.__scaiComputerRoot = st.computer_root;
        S.lastScanAt = st.last_scan_at || "";
        S.maker = st.maker || S.maker;
        S.homepage = st.homepage || "";
        S.version = st.version || "";
        applyTheme(st.theme || "light");
        $("empty-path").value = st.last_root || "";
        $("scan-path").value = st.last_root || "";
        setFooter();
      }
    } catch (e) { /* 初始化失败不阻塞界面 */ }

    const params = new URLSearchParams(location.search);
    if (params.get("smoke") === "1") {
      window.__runSmoke(params.get("dir") || "");
    }
  }

  // ---------------- 冒烟自测（仅 ?smoke=1 时运行，不做真实删除） ----------------

  window.__runSmoke = async function (dir) {
    const report = { ok: true, steps: [], errors: [] };
    const step = (name, cond, extra) => {
      report.steps.push({ name, ok: !!cond, extra: extra || "" });
      if (!cond) { report.ok = false; report.errors.push(name); }
    };
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    try {
      // 等 init 完成
      for (let i = 0; i < 50 && !window.__scaiComputerRoot; i++) await sleep(100);
      step("init-state", !!window.__scaiComputerRoot);

      await startScan(dir, false);
      for (let i = 0; i < 120 && S.view !== "results"; i++) await sleep(250);
      step("scan-results", S.view === "results");
      step("rows-nonempty", S.rows.length > 0, "rows=" + S.rows.length);
      step("cards-filled", ($("card-total").textContent || "") !== "", $("card-total").textContent);
      const c = counts();
      step("tab-counts", c.all === S.rows.length, JSON.stringify(c));
      step("treemap-svg", !!$("treemap").querySelector("svg"));

      // 勾选第一个非 risky 行
      const target = S.rows.find((r) => r.risk !== "risky");
      if (target) {
        toggleCheck(target.key, true);
        step("check-row", S.checked.has(target.key));
        updateSelection();
        step("selection-bar", $("selection-summary").textContent.indexOf("1") >= 0, $("selection-summary").textContent);
      }

      // 打开确认弹窗（不真正删除）
      await openConfirm();
      step("confirm-modal", !$("modal-overlay").classList.contains("hidden"));
      step("modal-items", $("modal-list").querySelectorAll("tr").length > 0);
      step("perm-button", !!$("modal-perm") && $("modal-perm").textContent.indexOf("直接删除") >= 0);

      // 永久删除两步确认：第一次点击进入待确认态，关闭后复位
      $("modal-perm").click();
      step("perm-armed", $("modal-perm").classList.contains("armed") && !$("perm-warning").classList.contains("hidden"));
      closeConfirm();
      step("perm-reset", !$("modal-perm").classList.contains("armed") && $("perm-warning").classList.contains("hidden"));
      step("modal-close", $("modal-overlay").classList.contains("hidden"));

      // 空状态：切到无数据的分类
      const before = S.filter;
      setFilter("review");
      step("empty-state", document.querySelector("#table-body .table-empty") !== null || S.rows.some((r) => r.risk === "review"));
      setFilter(before);

      // 统计卡点击筛选
      const activeTab = document.querySelector(".tab.active");
      const card = document.querySelector('.stat-card.clickable[data-filter="safe"]');
      if (card) {
        card.click();
        step("card-filter", document.querySelector(".tab.active").dataset.filter === "safe");
        setFilter("all");
      }

      // 主题切换
      applyTheme(S.theme === "dark" ? "light" : "dark");
      step("theme-toggle", document.documentElement.dataset.theme === "dark");
      applyTheme("light");

      // AI 提示词弹窗
      await openPrompt();
      step("ai-prompt", !$("prompt-overlay").classList.contains("hidden") && $("prompt-text").value.length > 100);
      $("prompt-overlay").classList.add("hidden");

      // 关于弹窗
      openAbout();
      step("about-modal", !$("about-overlay").classList.contains("hidden") && ($("about-version").textContent || "").length > 1);
      $("about-overlay").classList.add("hidden");

      // 日志查看器
      await openLogViewer();
      step("log-modal", !$("log-overlay").classList.contains("hidden") && $("log-list").querySelectorAll("tr").length > 0);
      $("log-overlay").classList.add("hidden");
    } catch (e) {
      report.ok = false;
      report.errors.push("exception: " + (e && e.message));
    }
    window.__smoke_report = report;
    try { await api().smoke_done(report); } catch (e) { /* 忽略 */ }
  };

  window.__scaiState = () => S;
  window.__smoke_report = null;

  window.addEventListener("pywebviewready", init);
  // pywebview 注入可能晚于本脚本，兜底轮询
  const kick = setInterval(() => {
    if (window.pywebview && window.pywebview.api) { clearInterval(kick); init(); }
  }, 120);
  setTimeout(() => clearInterval(kick), 10000);
})();
