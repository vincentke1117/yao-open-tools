import { escapeHtml } from './html.js';

export const editableElementSelector = [
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'p',
  'li',
  'td',
  'th',
  'blockquote',
  'figcaption',
  'caption',
  'dt',
  'dd',
  'summary',
  'label',
  'legend',
  'button',
  'a',
  'span',
  'strong',
  'em',
  'b',
  'i',
  'small',
  'div',
].join(',');

export const movableModuleSelector = [
  'section',
  'article',
  'header',
  'footer',
  'aside',
  'nav',
  'figure',
  'main > div',
  'body > div',
  '[data-section]',
  '[data-module]',
  '.section',
  '.module',
  '.card',
  '.panel',
  '.block',
  '.tile',
  '.feature',
  '.row',
  '.column',
].join(',');

function bridgeScript(page) {
  return `
(function () {
  const pageId = ${JSON.stringify(page.id)};
  let revision = ${Number(page.revision)};
  let saveTimer = null;
  let dragNode = null;
  let dragMoved = false;
  const selectors = ${JSON.stringify(editableElementSelector)};
  const moduleSelectors = ${JSON.stringify(movableModuleSelector)};
  const ignoredSelector = '[data-tokhtml-bridge],script,style,noscript,textarea,input,select,option,svg,canvas,iframe,video,audio';

  function setStatus(text, tone) {
    const status = document.querySelector('[data-tokhtml-status]');
    if (!status) return;
    status.textContent = text;
    status.dataset.tone = tone || 'idle';
  }

  function editableNodes() {
    const candidates = Array.from(document.body.querySelectorAll(selectors))
      .filter((node) => !node.closest('[data-tokhtml-bridge]'))
      .filter((node) => !node.closest(ignoredSelector))
      .filter((node) => Array.from(node.childNodes).some((child) => child.nodeType === Node.TEXT_NODE && child.textContent.trim()));
    return candidates.reduce((selected, node) => {
      if (!selected.some((parent) => parent.contains(node))) selected.push(node);
      return selected;
    }, []);
  }

  function siblingModules(parent) {
    if (!parent) return [];
    return Array.from(parent.children)
      .filter((node) => node.matches(moduleSelectors))
      .filter((node) => !node.closest('[data-tokhtml-bridge]'));
  }

  function movableNodes() {
    return Array.from(document.body.querySelectorAll(moduleSelectors))
      .filter((node) => !node.closest('[data-tokhtml-bridge]'))
      .filter((node) => !node.closest(ignoredSelector))
      .filter((node) => node.parentElement && siblingModules(node.parentElement).length > 1)
      .filter((node) => node.textContent.trim() || node.querySelector('img,video,canvas,svg,iframe'));
  }

  function directDragHandle(node) {
    return Array.from(node.children).find((child) => child.matches('[data-tokhtml-drag-handle]'));
  }

  function enableEditing() {
    editableNodes().forEach((node) => {
      node.setAttribute('contenteditable', 'true');
      node.setAttribute('data-tokhtml-editable', 'true');
      node.classList.add('tokhtml-editable');
    });
  }

  function clearDropState() {
    document.querySelectorAll('.tokhtml-module--drop-target').forEach((node) => node.classList.remove('tokhtml-module--drop-target'));
  }

  function parentPrefersHorizontal(parent) {
    const style = window.getComputedStyle(parent);
    if (style.display.includes('grid')) return true;
    if (style.display.includes('flex')) return style.flexDirection.startsWith('row');
    return false;
  }

  function shouldInsertBefore(target, event) {
    const rect = target.getBoundingClientRect();
    if (parentPrefersHorizontal(target.parentElement)) {
      return event.clientX < rect.left + rect.width / 2;
    }
    return event.clientY < rect.top + rect.height / 2;
  }

  function handleModuleDragOver(event) {
    if (!dragNode) return;
    const target = event.currentTarget;
    if (!target || target === dragNode || target.parentElement !== dragNode.parentElement) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    clearDropState();
    target.classList.add('tokhtml-module--drop-target');
    const before = shouldInsertBefore(target, event);
    if (before && target.previousElementSibling !== dragNode) {
      target.parentElement.insertBefore(dragNode, target);
      dragMoved = true;
    }
    if (!before && target.nextElementSibling !== dragNode) {
      target.parentElement.insertBefore(dragNode, target.nextSibling);
      dragMoved = true;
    }
  }

  function mountModuleHandles() {
    movableNodes().forEach((node) => {
      node.setAttribute('data-tokhtml-module', 'true');
      node.classList.add('tokhtml-draggable-module');
      if (!directDragHandle(node)) {
        const handle = document.createElement('button');
        handle.type = 'button';
        handle.draggable = true;
        handle.contentEditable = 'false';
        handle.className = 'tokhtml-module-handle';
        handle.setAttribute('data-tokhtml-bridge', 'drag-handle');
        handle.setAttribute('data-tokhtml-drag-handle', 'true');
        handle.setAttribute('aria-label', '拖动模块');
        handle.title = '拖动模块';
        handle.textContent = '↕';
        node.prepend(handle);
        handle.addEventListener('dragstart', (event) => {
          dragNode = node;
          dragMoved = false;
          node.classList.add('tokhtml-module--dragging');
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', 'tokhtml-module');
        });
        handle.addEventListener('dragend', () => {
          node.classList.remove('tokhtml-module--dragging');
          clearDropState();
          dragNode = null;
          if (dragMoved) scheduleSave();
          dragMoved = false;
        });
      }
      node.addEventListener('dragover', handleModuleDragOver);
      node.addEventListener('drop', (event) => {
        if (!dragNode) return;
        event.preventDefault();
        clearDropState();
      });
    });
  }

  function cleanHtmlSnapshot() {
    const clone = document.documentElement.cloneNode(true);
    clone.querySelectorAll('[data-tokhtml-bridge]').forEach((node) => node.remove());
    clone.querySelectorAll('[data-tokhtml-module]').forEach((node) => {
      node.removeAttribute('data-tokhtml-module');
      node.removeAttribute('draggable');
      node.classList.remove('tokhtml-draggable-module');
      node.classList.remove('tokhtml-module--dragging');
      node.classList.remove('tokhtml-module--drop-target');
    });
    clone.querySelectorAll('[data-tokhtml-editable]').forEach((node) => {
      node.removeAttribute('contenteditable');
      node.removeAttribute('data-tokhtml-editable');
      node.classList.remove('tokhtml-editable');
    });
    return '<!doctype html>\\n' + clone.outerHTML;
  }

  async function saveNow(manual) {
    window.clearTimeout(saveTimer);
    setStatus('保存中', 'saving');
    const response = await fetch('/api/pages/' + encodeURIComponent(pageId) + '/content', {
      method: 'PATCH',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ html: cleanHtmlSnapshot(), revision, reason: manual ? 'manual' : 'autosave' }),
    });
    if (response.status === 409) {
      setStatus('版本冲突，刷新后再改', 'error');
      return;
    }
    if (!response.ok) {
      setStatus('保存失败', 'error');
      return;
    }
    const data = await response.json();
    revision = data.page.revision;
    setStatus('已保存 ' + new Date().toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit', second: '2-digit' }), 'saved');
  }

  function scheduleSave() {
    setStatus('保存中', 'saving');
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(() => saveNow(false), 600);
  }

  function mountToolbar() {
    const toolbar = document.createElement('div');
    toolbar.className = 'tokhtml-edit-panel';
    toolbar.setAttribute('data-tokhtml-bridge', 'toolbar');
    toolbar.innerHTML = '<div class="tokhtml-edit-panel__brand"><strong>tokhtml</strong><span>页面内编辑</span></div><span class="tokhtml-edit-panel__status" data-tokhtml-status data-tone="saved">已保存</span><div class="tokhtml-edit-panel__actions"><button type="button" data-tokhtml-save>保存</button><a href="/${escapeHtml(page.slug)}">退出编辑</a><a href="/admin">管理器</a></div>';
    document.body.append(toolbar);
    toolbar.querySelector('[data-tokhtml-save]').addEventListener('click', () => saveNow(true));
  }

  mountToolbar();
  mountModuleHandles();
  enableEditing();
  document.addEventListener('input', (event) => {
    if (event.target && event.target.closest('[data-tokhtml-editable]')) scheduleSave();
  });
})();
`;
}

export function injectEditBridge(page, html) {
  const bridge = `
<style data-tokhtml-bridge="style">
  .tokhtml-edit-panel,.tokhtml-edit-panel *{box-sizing:border-box}
  .tokhtml-edit-panel{position:fixed;right:22px;bottom:22px;z-index:2147483647;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;width:min(386px,calc(100vw - 32px));padding:14px;border:1px solid #d1cfc5;border-radius:8px;background:#faf9f5;color:#141413;box-shadow:0 18px 46px rgba(20,20,19,.18),inset 0 1px 0 #fff;font:13px/1.35 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;letter-spacing:0}
  .tokhtml-edit-panel__brand{min-width:0;padding-left:10px;border-left:3px solid #1B365D}
  .tokhtml-edit-panel__brand strong{display:block;overflow:hidden;color:#1B365D;font-family:"Songti SC","STSong",Georgia,serif;font-size:18px;font-weight:600;line-height:1.15;text-overflow:ellipsis;white-space:nowrap}
  .tokhtml-edit-panel__brand span{display:block;margin-top:3px;color:#5e5d59;font-size:12px;line-height:1.35}
  .tokhtml-edit-panel__status{align-self:start;min-height:28px;padding:5px 10px;border-radius:999px;background:#edf3ea;color:#365f45;font-size:12px;font-weight:600;white-space:nowrap}
  .tokhtml-edit-panel__status[data-tone="saving"]{background:#f4ead8;color:#805a23}
  .tokhtml-edit-panel__status[data-tone="error"]{background:#f3e1dc;color:#9f3430}
  .tokhtml-edit-panel__actions{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
  .tokhtml-edit-panel__actions button,.tokhtml-edit-panel__actions a{display:inline-flex;align-items:center;justify-content:center;height:34px;min-width:0;padding:0 10px;border:1px solid #e8e5da;border-radius:7px;background:#fffefa;color:#1B365D;text-decoration:none;cursor:pointer;font:600 13px/1 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;white-space:nowrap}
  .tokhtml-edit-panel__actions button:hover,.tokhtml-edit-panel__actions a:hover{border-color:#d1cfc5;background:#f2f0e7}
  @media (max-width:520px){.tokhtml-edit-panel{right:12px;bottom:12px;width:calc(100vw - 24px)}.tokhtml-edit-panel__actions{grid-template-columns:1fr}.tokhtml-edit-panel__actions button,.tokhtml-edit-panel__actions a{height:36px}}
  .tokhtml-editable{outline:1px dashed transparent;outline-offset:3px;transition:outline-color .15s ease,background .15s ease}
  .tokhtml-editable:hover{outline-color:#9db4d0;background:rgba(238,242,247,.55)}
  .tokhtml-editable:focus{outline:2px solid #1B365D;background:rgba(238,242,247,.85)}
  .tokhtml-draggable-module{position:relative}
  .tokhtml-module-handle{position:absolute;left:6px;top:6px;z-index:2147483646;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid #d1cfc5;border-radius:7px;background:#faf9f5;color:#1B365D;box-shadow:0 10px 24px rgba(20,20,19,.16);cursor:grab;font:700 15px/1 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;opacity:0;transition:opacity .15s ease,transform .15s ease}
  .tokhtml-draggable-module:hover>.tokhtml-module-handle,.tokhtml-module-handle:focus{opacity:1}
  .tokhtml-module-handle:active{cursor:grabbing;transform:scale(.96)}
  .tokhtml-module--dragging{opacity:.62;outline:2px solid #1B365D!important;outline-offset:4px}
  .tokhtml-module--drop-target{outline:2px dashed #1B365D!important;outline-offset:6px;background-image:linear-gradient(rgba(238,242,247,.58),rgba(238,242,247,.58))}
</style>
<script data-tokhtml-bridge="script">${bridgeScript(page)}</script>`;
  if (/<\/body>/i.test(html)) {
    return html.replace(/<\/body>/i, `${bridge}</body>`);
  }
  return `${html}${bridge}`;
}
