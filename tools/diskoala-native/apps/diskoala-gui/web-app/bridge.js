// Diskoala Tauri 兼容层：把 web 前端调用的 pywebview.api 桥接到 Tauri invoke。
// 前端代码（app.js 等）零改动平移自 Python 版。
(function () {
  "use strict";

  function invoke(cmd, args) {
    return window.__TAURI__.core.invoke(cmd, args || {});
  }

  const api = {
    get_initial_state: () => invoke("get_initial_state"),
    save_prefs: (prefs) => invoke("save_prefs", { prefs: prefs || {} }),
    start_scan: (options) => invoke("start_scan", { options: options || {} }),
    get_progress: () => invoke("get_progress"),
    cancel_scan: () => invoke("cancel_scan"),
    get_results: () => invoke("get_results"),
    plan_trash: (keys) => invoke("plan_trash", { keys: keys || [] }),
    do_trash: (keys, mode) => invoke("do_trash", { keys: keys || [], mode: mode || "recycle" }),
    auto_plan: (target) => invoke("auto_plan", { target: String(target || "") }),
    browse: () => invoke("browse"),
    reveal: (key) => invoke("reveal", { key: String(key || "") }),
    open_log: () => invoke("open_log"),
    ai_prompt: () => invoke("ai_prompt"),
    get_log: (limit) => invoke("get_log", { limit: limit || 200 }),
    smoke_done: (report) => invoke("smoke_done", { report: report || {} }),
  };

  window.pywebview = { api: api };
})();
