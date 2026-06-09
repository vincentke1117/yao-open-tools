import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';

const indexPath = path.resolve('public/index.html');
const publicIndexPath = path.resolve('public/index-public.html');
const publicAppPath = path.resolve('public/public-app.js');

test('keeps the page list full width and moves watch directories into settings', async () => {
  const html = await fs.readFile(indexPath, 'utf8');
  const contentArea = html.match(/<section class="content-area"[\s\S]*?<\/section>/)?.[0] || '';
  const overview = html.match(/<section class="overview"[\s\S]*?<section class="content-area"/)?.[0] || '';
  const sideSummary = overview.match(/<aside class="panel side-summary"[\s\S]*?<\/aside>/)?.[0] || '';
  const settingsDrawer = html.match(/<div class="drawer-backdrop" id="settingsBackdrop"[\s\S]*?<div class="modal-backdrop" id="previewBackdrop"/)?.[0] || '';

  assert.match(contentArea, /class="panel table-panel"/);
  assert.doesNotMatch(contentArea, /class="inspector"/);
  assert.doesNotMatch(sideSummary, /id="watchList"/);
  assert.doesNotMatch(sideSummary, /class="side-watch"/);
  assert.doesNotMatch(sideSummary, /读取文件/);
  assert.doesNotMatch(sideSummary, /解析元信息/);
  assert.match(settingsDrawer, /id="watchList"/);
  assert.match(settingsDrawer, /id="addWatchDirectory"/);
  assert.match(settingsDrawer, /登录用户名/);
  assert.match(settingsDrawer, /登录密码/);
  assert.match(settingsDrawer, /后台访问目录/);
  assert.match(settingsDrawer, /id="adminPathInput"/);
  assert.match(settingsDrawer, /id="currentPasswordInput"/);
  assert.match(settingsDrawer, /公开首页/);
  assert.match(settingsDrawer, /id="publicHomepageEnabledInput"/);
  assert.match(settingsDrawer, /线上绑定/);
  assert.match(settingsDrawer, /id="remoteSyncEnabledInput"/);
  assert.match(settingsDrawer, /id="remoteSyncUrlInput"/);
  assert.match(settingsDrawer, /id="remoteSyncTokenInput"/);
  assert.match(html, /id="loginBackdrop"/);
  assert.match(html, /TokDoc 本地文档管理器/);
  assert.match(html, /TokDoc 登录/);
  assert.match(html, /id="openPublicHome"/);
  assert.doesNotMatch(html, /tokhtml 登录/);
  assert.match(html, /data-filter="trash"/);
  assert.match(html, /回收站/);
});

test('exposes PDF and Word document upload affordances in the manager UI', async () => {
  const html = await fs.readFile(indexPath, 'utf8');

  assert.match(html, /上传 HTML、PDF 或 Word/);
  assert.match(html, /选择文件/);
  assert.match(html, /accept="\.html,\.htm,\.pdf,\.doc,\.docx,text\/html,application\/pdf"/);
  assert.match(html, /文档列表/);
  assert.match(html, /<th>类型<\/th>/);
  assert.match(html, /id="metaFileType"/);
  assert.match(html, /id="editFromPreview"/);
});

test('defines a standalone public document index page', async () => {
  const html = await fs.readFile(publicIndexPath, 'utf8');

  assert.match(html, /TokDoc 文档索引/);
  assert.match(html, /id="typeTabs"/);
  assert.match(html, /data-type="html"/);
  assert.match(html, /data-type="pdf"/);
  assert.match(html, /data-type="word"/);
  assert.match(html, /id="searchInput"/);
  assert.match(html, /id="sortSelect"/);
  assert.match(html, /id="docRows"/);
  assert.match(html, /id="docCards"/);
  assert.match(html, /\/assets\/public-app\.js/);
});

test('opens public documents in a new tab and defaults public pagination to 10', async () => {
  const script = await fs.readFile(publicAppPath, 'utf8');

  assert.match(script, /pageSize:\s*10/);
  assert.match(script, /target="_blank"/);
  assert.match(script, /rel="noopener noreferrer"/);
  assert.match(script, /window\.open\(row\.dataset\.url, '_blank', 'noopener,noreferrer'\)/);
});
