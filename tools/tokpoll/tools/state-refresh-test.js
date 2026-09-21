'use strict';

// 回归测试：期次阶段或个人配额变化时，即使问题列表版本不变，前台也要刷新。
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('public/assets/app.js', 'utf8');
const initial = {
  survey: {
    id: 1, title: '测试期次', open: false, phase: 'pending',
    starts_at: '2099-01-01 10:00:00', ends_at: '2099-01-01 11:00:00',
    allow_submit: 1, need_review: 1, votes_per_day: 1,
  },
  questions: [],
  my_votes: [],
  quota: {
    vote: { allowed: false, used: 0, quota: 1, reason: '本期尚未开始' },
    submit: { allowed: false, used: 0, quota: 1, reason: '本期尚未开始' },
  },
  stats: { voters: 0, votes: 0 },
  version: 'same-list-version', csrf: 'csrf', api: '/api.php', poll_ms: 8000,
};
const next = JSON.parse(JSON.stringify(initial));
next.survey.open = true;
next.survey.phase = 'open';
next.quota.vote.allowed = true;
next.quota.vote.reason = '';
next.quota.submit.allowed = true;
next.quota.submit.reason = '';

const hint = { textContent: '' };
const button = { disabled: false, textContent: '', addEventListener() {} };
const list = {
  children: [],
  innerHTML: '',
  addEventListener() {},
  querySelector() { return null; },
};
const initialState = { textContent: JSON.stringify(initial) };
const elements = {
  'initial-state': initialState,
  list,
  'list-hint': hint,
  'btn-open-sheet': button,
};

let refresh;
const context = {
  document: {
    hidden: false,
    getElementById(id) { return elements[id] || null; },
    addEventListener() {},
  },
  fetch() {
    return Promise.resolve({
      json: () => Promise.resolve({ ok: true, data: next }),
    });
  },
  setInterval(fn) {
    refresh = fn;
    return 1;
  },
  clearInterval() {},
  setTimeout(fn) { fn(); },
  requestAnimationFrame(fn) { fn(); },
  performance: { now: () => 0 },
  console,
  JSON,
  Object,
  Array,
  Math,
  Date,
  Promise,
  String,
  parseInt,
};

vm.runInNewContext(source, context, { filename: 'public/assets/app.js' });
assert.strictEqual(hint.textContent, '本期尚未开始');
assert.strictEqual(button.disabled, true);
assert.ok(refresh, '轮询回调未注册');

refresh();
setImmediate(() => {
  assert.strictEqual(hint.textContent, '今天还有 1 票，点一下就投');
  assert.strictEqual(button.disabled, false);
  console.log('state-refresh: PASS');
});
