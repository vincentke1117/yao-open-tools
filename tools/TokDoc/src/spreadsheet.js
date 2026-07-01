import { parseHTML } from 'linkedom';
import { documentTitleFromFileName, escapeHtml } from './html.js';

const blockedElements = new Set(['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta']);

function columnLabel(index) {
  let value = index + 1;
  let label = '';
  while (value > 0) {
    const remainder = (value - 1) % 26;
    label = String.fromCharCode(65 + remainder) + label;
    value = Math.floor((value - 1) / 26);
  }
  return label;
}

function tableColumnCount(row) {
  return Array.from(row.children || []).reduce((count, cell) => {
    const span = Number(cell.getAttribute('colspan') || 1);
    return count + (Number.isFinite(span) && span > 0 ? span : 1);
  }, 0);
}

function enhanceTables(document) {
  for (const table of Array.from(document.querySelectorAll('table'))) {
    const rows = Array.from(table.querySelectorAll('tr'));
    if (!rows.length) continue;
    const columnCount = Math.max(1, ...rows.map(tableColumnCount));
    table.classList.add('sheet-grid');

    const header = document.createElement('tr');
    header.className = 'sheet-column-row';
    const corner = document.createElement('th');
    corner.className = 'sheet-corner-cell';
    corner.textContent = '';
    header.appendChild(corner);
    for (let column = 0; column < columnCount; column += 1) {
      const cell = document.createElement('th');
      cell.className = 'sheet-column-head';
      cell.textContent = columnLabel(column);
      header.appendChild(cell);
    }
    rows[0].parentNode.insertBefore(header, rows[0]);

    rows.forEach((row, index) => {
      row.classList.add('sheet-data-row');
      let columnIndex = 0;
      for (const originalCell of Array.from(row.children || [])) {
        originalCell.classList.add('sheet-data-cell');
        originalCell.setAttribute('data-sheet-row', String(index + 1));
        originalCell.setAttribute('data-sheet-column', columnLabel(columnIndex));
        originalCell.setAttribute('data-sheet-address', `${columnLabel(columnIndex)}${index + 1}`);
        columnIndex += tableColumnCount({ children: [originalCell] });
      }
      const cell = document.createElement('th');
      cell.className = 'sheet-row-head';
      cell.textContent = String(index + 1);
      row.insertBefore(cell, row.firstChild);
    });
  }
}

function sanitizeConvertedHtml(html) {
  const { document } = parseHTML(String(html || ''));
  for (const element of Array.from(document.querySelectorAll('*'))) {
    if (blockedElements.has(element.localName)) {
      element.remove();
      continue;
    }
    for (const attribute of Array.from(element.attributes || [])) {
      const name = attribute.name.toLowerCase();
      const value = String(attribute.value || '').trim().toLowerCase();
      if (name.startsWith('on') || value.startsWith('javascript:')) {
        element.removeAttribute(attribute.name);
      }
    }
    if (element.localName === 'a') {
      element.setAttribute('target', '_blank');
      element.setAttribute('rel', 'noopener noreferrer');
    }
  }
  enhanceTables(document);
  return document.body?.innerHTML || '';
}

export function parseSpreadsheetMetadata(fileName = 'spreadsheet.xlsx') {
  return { title: documentTitleFromFileName(fileName) };
}

export function renderSpreadsheetDocument(convertedHtml, fileName = 'spreadsheet.xlsx', titleOverride = '') {
  const parsedTitle = parseSpreadsheetMetadata(fileName).title;
  const title = String(titleOverride || '').trim() || parsedTitle;
  const body = sanitizeConvertedHtml(convertedHtml) || '<div class="empty-sheet">空工作表</div>';
  return [
    '<!doctype html>',
    '<html lang="zh-CN">',
    '<head>',
    '<meta charset="utf-8">',
    '<meta name="viewport" content="width=device-width,initial-scale=1">',
    `<title>${escapeHtml(title)}</title>`,
    '<style>',
    ':root{color-scheme:light;--sheet-ink:#1f2328;--sheet-muted:#667085;--sheet-soft:#f7f6ef;--sheet-panel:#fffefa;--sheet-surface:#fff;--sheet-line:#dcd7cb;--sheet-grid:#e9e4d8;--sheet-head:#f4f1e8;--sheet-head-strong:#ebe6d8;--sheet-active:#1f6f43;--sheet-accent:#1b365d;--sheet-blue-soft:#edf5ff}*{box-sizing:border-box}html,body{height:100%}body{margin:0;background:var(--sheet-soft);color:var(--sheet-ink);font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","PingFang SC","Noto Sans SC",sans-serif;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}.sheet-app{min-height:100vh;padding:18px}.sheet-shell{height:calc(100vh - 36px);display:flex;flex-direction:column;border:1px solid var(--sheet-line);border-radius:10px;background:var(--sheet-panel);box-shadow:0 16px 38px rgba(41,34,20,.08);overflow:hidden}.sheet-topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 16px;border-bottom:1px solid var(--sheet-line);background:var(--sheet-surface)}.sheet-title{min-width:0;display:flex;align-items:center;gap:10px}.sheet-file-icon{width:34px;height:34px;display:grid;place-items:center;border:1px solid #cddfcb;border-radius:8px;background:#eef8ef;color:var(--sheet-active);font-weight:800;font-size:15px;line-height:1}.sheet-title-text{min-width:0}.sheet-title h1{margin:0;max-width:62vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:17px;line-height:1.25;font-weight:700;letter-spacing:0}.sheet-title span{display:block;margin-top:2px;max-width:62vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--sheet-muted);font-size:12px;line-height:1.4}.sheet-actions{display:flex;align-items:center;gap:8px;flex:none}.sheet-pill{height:30px;display:inline-flex;align-items:center;border:1px solid var(--sheet-line);border-radius:999px;background:#fbfaf6;color:#3f454c;padding:0 10px;font-size:12px;font-weight:700;white-space:nowrap}.sheet-pill.is-green{border-color:#d7eadb;background:#eaf6ed;color:#2f6d47}.sheet-toolbar{display:flex;align-items:center;gap:8px;min-height:42px;padding:6px 16px;border-bottom:1px solid var(--sheet-line);background:#fbfaf6;overflow-x:auto}.sheet-toolbar-group{display:flex;align-items:center;gap:4px;padding-right:8px;border-right:1px solid var(--sheet-line)}.sheet-toolbar-group:last-child{border-right:0}.sheet-mode{height:28px;min-width:32px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:6px;color:#3f454c;background:transparent;padding:0 8px;font-size:12px;font-weight:700;white-space:nowrap}.sheet-mode.is-on{border-color:#d7eadb;background:#eef8ef;color:var(--sheet-active)}.sheet-formula{display:grid;grid-template-columns:72px 38px minmax(0,1fr);align-items:center;min-height:38px;border-bottom:1px solid var(--sheet-line);background:var(--sheet-surface)}.sheet-name-box,.sheet-fx,.sheet-formula-input{height:100%;display:flex;align-items:center;border-right:1px solid var(--sheet-line);font-size:12px}.sheet-name-box{justify-content:center;color:var(--sheet-accent);font-weight:800;font-variant-numeric:tabular-nums;background:#fbfaf6}.sheet-fx{justify-content:center;color:#7a756b;font-family:Georgia,serif;font-style:italic;font-weight:700;background:#fbfaf6}.sheet-formula-input{min-width:0;padding:0 12px;color:#30363d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sheet-workspace{position:relative;min-height:0;flex:1;background:#fbfaf6;overflow:auto;padding:0}.sheet-grid{border-collapse:separate;border-spacing:0;min-width:max-content;background:var(--sheet-surface);font-variant-numeric:tabular-nums}.sheet-grid th,.sheet-grid td{height:30px;min-width:96px;max-width:360px;padding:5px 8px;border-right:1px solid var(--sheet-grid);border-bottom:1px solid var(--sheet-grid);font-size:13px;line-height:1.35;vertical-align:middle;white-space:pre-wrap;overflow-wrap:anywhere;background:var(--sheet-surface)}.sheet-grid font{font:inherit;color:inherit}.sheet-grid a{color:var(--sheet-accent);text-decoration-thickness:1px;text-underline-offset:3px}.sheet-column-head,.sheet-row-head,.sheet-corner-cell{position:sticky;background:var(--sheet-head)!important;color:#5f625d;font-weight:700;text-align:center;white-space:nowrap;user-select:none}.sheet-column-head{top:0;z-index:3}.sheet-row-head{left:0;z-index:2;min-width:52px;width:52px}.sheet-corner-cell{top:0;left:0;z-index:4;min-width:52px;width:52px;background:var(--sheet-head-strong)!important;color:var(--sheet-accent)}.sheet-column-row{height:30px}.sheet-data-cell{cursor:cell}.sheet-data-cell:hover{background:#f9fcf8!important}.sheet-data-cell.sheet-cell-active{position:relative;outline:2px solid var(--sheet-active);outline-offset:-2px;z-index:1}.sheet-tabs{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:44px;padding:6px 14px;border-top:1px solid var(--sheet-line);background:var(--sheet-surface)}.sheet-tab-list{display:flex;align-items:center;gap:8px;min-width:0}.sheet-tab{height:30px;display:inline-flex;align-items:center;border:1px solid #d7eadb;border-radius:8px 8px 0 0;background:#eef8ef;color:var(--sheet-active);padding:0 14px;font-size:13px;font-weight:800;white-space:nowrap}.sheet-status{min-width:0;color:var(--sheet-muted);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.empty-sheet{margin:24px;color:var(--sheet-muted)}@media (max-width:720px){.sheet-app{padding:0}.sheet-shell{height:100vh;border:0;border-radius:0}.sheet-topbar{padding:10px 12px}.sheet-title h1{max-width:54vw;font-size:16px}.sheet-title span{max-width:54vw}.sheet-actions .sheet-pill:not(.is-green){display:none}.sheet-toolbar{padding:6px 10px}.sheet-formula{grid-template-columns:58px 34px minmax(0,1fr)}.sheet-grid th,.sheet-grid td{min-width:86px;max-width:280px}.sheet-row-head,.sheet-corner-cell{min-width:46px;width:46px}.sheet-tabs{padding:6px 10px}}',
    '</style>',
    '</head>',
    '<body>',
    '<main class="sheet-app" aria-label="TokDoc 表格阅读器">',
    '<section class="sheet-shell">',
    '<header class="sheet-topbar">',
    '<div class="sheet-title">',
    '<span class="sheet-file-icon" aria-hidden="true">XL</span>',
    `<div class="sheet-title-text"><h1>${escapeHtml(title)}</h1><span>${escapeHtml(fileName)} · TokDoc 表格阅读页</span></div>`,
    '</div>',
    '<div class="sheet-actions"><span class="sheet-pill is-green">只读</span><span class="sheet-pill">100%</span></div>',
    '</header>',
    '<div class="sheet-toolbar" aria-label="表格工具栏">',
    '<div class="sheet-toolbar-group"><span class="sheet-mode is-on">网格视图</span><span class="sheet-mode">冻结行列</span></div>',
    '<div class="sheet-toolbar-group"><span class="sheet-mode">单元格选择</span><span class="sheet-mode">公式栏</span></div>',
    '</div>',
    '<div class="sheet-formula" aria-label="公式栏">',
    '<div class="sheet-name-box" id="sheetActiveCell">A1</div>',
    '<div class="sheet-fx">fx</div>',
    '<div class="sheet-formula-input" id="sheetFormulaValue">选择单元格查看内容</div>',
    '</div>',
    `<div class="sheet-workspace">${body}</div>`,
    '<footer class="sheet-tabs">',
    '<div class="sheet-tab-list"><span class="sheet-tab">工作表 1</span></div>',
    '<div class="sheet-status">TokDoc · Excel 在线阅读</div>',
    '</footer>',
    '</section>',
    '</main>',
    '<script>',
    '(() => {',
    '  const address = document.getElementById("sheetActiveCell");',
    '  const value = document.getElementById("sheetFormulaValue");',
    '  const cells = Array.from(document.querySelectorAll(".sheet-data-cell"));',
    '  const activate = (cell) => {',
    '    document.querySelector(".sheet-cell-active")?.classList.remove("sheet-cell-active");',
    '    cell.classList.add("sheet-cell-active");',
    '    if (address) address.textContent = cell.dataset.sheetAddress || "A1";',
    '    if (value) value.textContent = cell.innerText.trim() || " ";',
    '  };',
    '  cells.forEach((cell) => cell.addEventListener("click", () => activate(cell)));',
    '  if (cells[0]) activate(cells[0]);',
    '})();',
    '</script>',
    '</body>',
    '</html>',
  ].join('');
}
