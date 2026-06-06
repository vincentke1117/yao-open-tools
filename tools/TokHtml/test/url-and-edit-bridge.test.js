import assert from 'node:assert/strict';
import test from 'node:test';
import { parseHTML } from 'linkedom';
import { buildManagedSlug, removeEditBridge } from '../src/html.js';
import { editableElementSelector, injectEditBridge, movableModuleSelector } from '../src/edit-bridge.js';

test('buildManagedSlug creates a short six-character URL slug for uploads', () => {
  const slug = buildManagedSlug({
    code: 'f812c6',
  });

  assert.equal(slug, 'f812c6');
  assert.match(slug, /^[a-z0-9]{6}$/);
});

test('editableElementSelector covers common text modules beyond paragraphs', () => {
  const { document } = parseHTML(`
    <main>
      <div class="card">卡片文案</div>
      <span>标签文案</span>
      <a href="#">链接文案</a>
      <button>按钮文案</button>
      <p>段落文案</p>
    </main>
  `);

  const editableTexts = Array.from(document.querySelectorAll(editableElementSelector)).map((node) => node.textContent.trim());

  assert.deepEqual(editableTexts, ['卡片文案', '标签文案', '链接文案', '按钮文案', '段落文案']);
});

test('movableModuleSelector covers common layout modules', () => {
  const { document } = parseHTML(`
    <main>
      <section>章节</section>
      <article>文章</article>
      <div class="card">卡片</div>
      <div class="module">模块</div>
      <div data-module="hero">数据模块</div>
    </main>
  `);

  const movableTexts = Array.from(document.querySelectorAll(movableModuleSelector)).map((node) => node.textContent.trim());

  assert.deepEqual(movableTexts, ['章节', '文章', '卡片', '模块', '数据模块']);
});

test('injectEditBridge uses a structured kami-style floating toolbar', () => {
  const html = injectEditBridge(
    { id: 'page-1', slug: 'f812c6', revision: 1 },
    '<!doctype html><html><head><title>页面</title></head><body><h1>页面</h1></body></html>',
  );

  assert.match(html, /tokhtml-edit-panel/);
  assert.match(html, /tokhtml-edit-panel__brand/);
  assert.match(html, /tokhtml-edit-panel__status/);
  assert.match(html, /tokhtml-edit-panel__actions/);
  assert.match(html, /tokhtml-module-handle/);
  assert.match(html, /data-tokhtml-drag-handle/);
  assert.match(html, /mountModuleHandles/);
  assert.match(html, /startFreeDrag/);
  assert.match(html, /tokhtml-module--free-positioned/);
  assert.match(html, /href="\/f812c6"/);
  assert.match(html, /href="\/admin"/);
  assert.doesNotMatch(html, /href="\/">管理器/);
  assert.doesNotMatch(html, /href="\/pages\/f812c6\.html"/);
});

test('removeEditBridge strips drag sorting runtime markers before saving', () => {
  const cleaned = removeEditBridge(`
    <!doctype html>
    <html>
      <body>
        <section
          data-tokhtml-module="true"
          data-tokhtml-free-positioned="true"
          draggable="true"
          class="hero tokhtml-draggable-module tokhtml-module--dragging tokhtml-module--drop-target tokhtml-module--free-positioned tokhtml-module--free-dragging"
          style="position:absolute;left:24px;top:32px;width:300px;z-index:10"
        >
          <button data-tokhtml-bridge="drag-handle" data-tokhtml-drag-handle="true">↕</button>
          <h1 data-tokhtml-editable="true" contenteditable="true" class="tokhtml-editable">标题</h1>
        </section>
      </body>
    </html>
  `);

  assert.doesNotMatch(cleaned, /data-tokhtml-module/);
  assert.doesNotMatch(cleaned, /data-tokhtml-free-positioned/);
  assert.doesNotMatch(cleaned, /data-tokhtml-bridge/);
  assert.doesNotMatch(cleaned, /contenteditable/);
  assert.doesNotMatch(cleaned, /tokhtml-editable/);
  assert.doesNotMatch(cleaned, /tokhtml-draggable-module/);
  assert.doesNotMatch(cleaned, /tokhtml-module--dragging/);
  assert.doesNotMatch(cleaned, /tokhtml-module--drop-target/);
  assert.doesNotMatch(cleaned, /tokhtml-module--free-positioned/);
  assert.doesNotMatch(cleaned, /tokhtml-module--free-dragging/);
  assert.match(cleaned, /class="hero"/);
  assert.match(cleaned, /position:absolute/);
  assert.match(cleaned, /left:24px/);
  assert.match(cleaned, /top:32px/);
  assert.match(cleaned, /标题/);
});
