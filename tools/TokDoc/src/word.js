import { parseHTML } from 'linkedom';
import { documentTitleFromFileName, escapeHtml } from './html.js';

const blockedElements = new Set(['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta']);

function sanitizeCssText(value = '') {
  return String(value || '')
    .replace(/@import[^;]+;/gi, '')
    .replace(/javascript\s*:/gi, '')
    .replace(/expression\s*\(/gi, '')
    .replace(/<\/style/gi, '<\\/style');
}

function safeAssetUrl(value = '', assetBaseUrl = '') {
  const raw = String(value || '').trim();
  if (!raw || raw.startsWith('#') || raw.startsWith('/') || /^[a-z][a-z0-9+.-]*:/i.test(raw)) return raw;
  const clean = raw
    .replace(/\\/g, '/')
    .split('/')
    .filter((part) => part && part !== '.')
    .map((part) => encodeURIComponent(part))
    .join('/');
  const base = String(assetBaseUrl || '').trim();
  if (!base || !clean) return raw;
  return `${base.endsWith('/') ? base : `${base}/`}${clean}`;
}

function sanitizeConvertedHtml(html, assetBaseUrl = '') {
  const { document } = parseHTML(String(html || ''));
  for (const element of Array.from(document.querySelectorAll('*'))) {
    if (blockedElements.has(element.localName)) {
      element.remove();
      continue;
    }
    if (element.localName === 'style') {
      element.textContent = sanitizeCssText(element.textContent || '');
      continue;
    }
    for (const attribute of Array.from(element.attributes || [])) {
      const name = attribute.name.toLowerCase();
      const value = String(attribute.value || '');
      if (name.startsWith('on') || /javascript\s*:/i.test(value) || /expression\s*\(/i.test(value)) {
        element.removeAttribute(attribute.name);
        continue;
      }
      if (name === 'style') element.setAttribute(attribute.name, sanitizeCssText(value));
      if ((name === 'src' || name === 'href') && assetBaseUrl) element.setAttribute(attribute.name, safeAssetUrl(value, assetBaseUrl));
    }
    if (element.localName === 'a') {
      element.setAttribute('target', '_blank');
      element.setAttribute('rel', 'noopener noreferrer');
    }
  }
  return {
    body: document.body?.innerHTML || '',
    styles: Array.from(document.querySelectorAll('style'))
      .map((style) => sanitizeCssText(style.textContent || ''))
      .filter(Boolean)
      .join('\n'),
  };
}

export function parseWordMetadata(fileName = 'document.docx') {
  return { title: documentTitleFromFileName(fileName) };
}

export function renderWordDocument(convertedHtml, fileName = 'document.docx', titleOverride = '', assetBaseUrl = '') {
  const parsedTitle = parseWordMetadata(fileName).title;
  const title = String(titleOverride || '').trim() || parsedTitle;
  const sanitized = sanitizeConvertedHtml(convertedHtml, assetBaseUrl);
  const body = sanitized.body || `<h1>${escapeHtml(title)}</h1>`;
  return [
    '<!doctype html>',
    '<html lang="zh-CN">',
    '<head>',
    '<meta charset="utf-8">',
    '<meta name="viewport" content="width=device-width,initial-scale=1">',
    `<title>${escapeHtml(title)}</title>`,
    '<style>',
    ':root{color-scheme:light;--ink:#25231f;--muted:#6b675f;--line:#e6dfd1;--paper:#fffefa;--canvas:#f7f6ef;--accent:#1b365d}*{box-sizing:border-box}body{margin:0;background:var(--canvas);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","PingFang SC","Noto Sans SC",sans-serif;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}.tokdoc-word-shell{max-width:980px;margin:28px auto;padding:0 20px 64px}.tokdoc-word-topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px}.tokdoc-word-title{min-width:0}.tokdoc-word-title h1{margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#141413;font-family:"Songti SC","STSong",Georgia,serif;font-size:28px;font-weight:500;line-height:1.25}.tokdoc-word-title span{display:block;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:13px}.tokdoc-word-badge{flex:none;border:1px solid #ded7c8;border-radius:999px;background:#fbfaf6;color:var(--accent);padding:6px 10px;font-size:12px;font-weight:700}.tokdoc-word-paper{min-height:calc(100vh - 150px);border:1px solid var(--line);border-radius:8px;background:var(--paper);box-shadow:0 18px 44px rgba(41,34,20,.06);padding:52px 64px;line-height:1.72}.tokdoc-word-paper h1,.tokdoc-word-paper h2,.tokdoc-word-paper h3{font-family:"Songti SC","STSong",Georgia,serif;font-weight:500;line-height:1.28}.tokdoc-word-paper p{margin:0 0 14px}.tokdoc-word-paper img{max-width:100%;height:auto}.tokdoc-word-paper table{max-width:100%;border-collapse:collapse}.tokdoc-word-paper td,.tokdoc-word-paper th{border:1px solid var(--line);padding:8px 10px;vertical-align:top}.tokdoc-word-paper a{color:var(--accent);text-underline-offset:3px}@media (max-width:720px){.tokdoc-word-shell{margin:0;padding:0}.tokdoc-word-topbar{padding:14px 16px;margin:0;border-bottom:1px solid var(--line);background:var(--paper)}.tokdoc-word-title h1{font-size:22px}.tokdoc-word-paper{min-height:calc(100vh - 74px);border:0;border-radius:0;box-shadow:none;padding:24px 18px}}',
    sanitized.styles ? sanitized.styles : '',
    '</style>',
    '</head>',
    '<body>',
    '<main class="tokdoc-word-shell">',
    '<header class="tokdoc-word-topbar">',
    `<div class="tokdoc-word-title"><h1>${escapeHtml(title)}</h1><span>${escapeHtml(fileName)} · Word 阅读页，可在线编辑生成版本</span></div>`,
    '<span class="tokdoc-word-badge">Word</span>',
    '</header>',
    `<article class="tokdoc-word-paper">${body}</article>`,
    '</main>',
    '</body>',
    '</html>',
  ].join('');
}
