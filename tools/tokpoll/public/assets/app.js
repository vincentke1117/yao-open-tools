/* ==========================================================================
   Tokpoll — 前台交互
   无框架、无依赖。负责：渲染问题列表、投票/改票、补充问题、定时增量刷新。
   ========================================================================== */
(function () {
  'use strict';

  var stateEl = document.getElementById('initial-state');
  if (!stateEl) { return; }

  var S = JSON.parse(stateEl.textContent || '{}');
  if (!S.survey) { return; }

  var listEl   = document.getElementById('list');
  var toastEl  = document.getElementById('toast');
  var hintEl   = document.getElementById('list-hint');
  var dockHint = document.getElementById('dock-hint');
  var chipStat = document.getElementById('chip-stats');
  var busy     = false;
  var seenIds  = null;   // 首次渲染后记录，用于给新问题加入场动画

  /* ---------------------------------------------------------------- 工具 */

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  var toastTimer = null;
  function toast(msg, isErr) {
    if (!toastEl) { return; }
    toastEl.textContent = msg;
    toastEl.className = 'toast is-on' + (isErr ? ' toast--err' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.className = 'toast'; }, 2600);
  }

  function post(action, data) {
    var payload = Object.assign({ csrf: S.csrf }, data || {});
    return fetch(S.api + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: '网络返回异常' }; });
    });
  }

  /* ------------------------------------------------------- 渲染问题列表 */

  var prevCounts = {};   // 上一次每题的票数，用于做数字滚动和弹跳
  var prevPcts   = {};   // 上一次每题的占比，用于让底部条平滑过渡

  // 图标参考 SF Symbols 的 chevron.up / checkmark：圆角描边，比实心三角更轻。
  // 宽高同时写在属性和 CSS 上，万一样式表没加载也不会撑大。
  var MARK_UP =
    '<svg class="vote__mark" width="14" height="14" viewBox="0 0 14 14" ' +
      'fill="none" aria-hidden="true"><path d="M3.1 8.9 7 5 10.9 8.9" ' +
      'stroke="currentColor" stroke-width="2.1" stroke-linecap="round" ' +
      'stroke-linejoin="round"/></svg>';
  var MARK_OK =
    '<svg class="vote__mark" width="14" height="14" viewBox="0 0 14 14" ' +
      'fill="none" aria-hidden="true"><path d="M2.9 7.4 5.8 10.3 11.1 4.2" ' +
      'stroke="currentColor" stroke-width="2.1" stroke-linecap="round" ' +
      'stroke-linejoin="round"/></svg>';

  function cardHTML(q, idx, total, myVotes, open) {
    var voted = myVotes.indexOf(q.id) !== -1;
    var pct   = total > 0 ? Math.round(q.votes_cache * 100 / total) : 0;
    var from  = prevPcts[q.id];
    var isNew = seenIds && seenIds.indexOf(q.id) === -1;

    var foot = (q.tags || []).map(function (t) {
      return '<span class="tag" data-color="' + esc(t.color) + '">' + esc(t.name) + '</span>';
    }).join('');
    if (q.pinned) { foot = '<span class="badge-pin">置顶</span>' + foot; }
    if (q.source === 'user') { foot += '<span class="badge-new">用户补充</span>'; }
    if (q.votes_cache > 0) { foot += '<span class="card__pct">占 ' + pct + '%</span>'; }

    return '' +
      '<article class="card' + (voted ? ' card--voted' : '') + (open ? '' : ' card--locked') +
               (isNew ? ' card--new' : '') + '" data-id="' + q.id + '" ' +
               (open ? 'role="button" tabindex="0"' : '') + '>' +
        '<div class="card__rank' + (idx < 3 ? ' card__rank--top' : '') + '">' + (idx + 1) + '</div>' +
        '<div class="card__body">' +
          '<h3 class="card__title">' + esc(q.title) + '</h3>' +
          (q.detail ? '<p class="card__detail">' + esc(q.detail) + '</p>' : '') +
          (foot ? '<div class="card__foot">' + foot + '</div>' : '') +
        '</div>' +
        '<button class="vote' + (voted ? ' vote--on' : '') + '" type="button" ' +
                (open ? '' : 'disabled ') +
                'aria-label="' + (voted ? '撤回这一票' : '投票给这个问题') +
                '，当前 ' + q.votes_cache + ' 票">' +
          (voted ? MARK_OK : MARK_UP) +
          '<span class="vote__num">' + q.votes_cache + '</span>' +
        '</button>' +
        '<div class="card__bar"><i data-w="' + pct + '" style="width:' +
             (from === undefined ? pct : from) + '%"></i></div>' +
      '</article>';
  }

  // 票数变化时，数字从旧值滚到新值
  function tweenNumber(el, from, to, ms) {
    var t0 = performance.now();
    (function step(now) {
      var k = Math.min(1, (now - t0) / ms);
      var eased = 1 - Math.pow(1 - k, 3);
      el.textContent = Math.round(from + (to - from) * eased);
      if (k < 1) { requestAnimationFrame(step); }
      else { el.textContent = to; }
    })(t0);
  }

  function render(animate) {
    var qs = S.questions || [];
    var open = !!(S.survey && S.survey.open);
    var total = qs.reduce(function (a, q) { return a + q.votes_cache; }, 0);
    var myVotes = S.my_votes || [];

    // FLIP：先记录旧位置
    var before = {};
    if (animate) {
      Array.prototype.forEach.call(listEl.children, function (el) {
        if (el.dataset && el.dataset.id) { before[el.dataset.id] = el.getBoundingClientRect().top; }
      });
    }

    if (!qs.length) {
      listEl.innerHTML =
        '<div class="empty"><div class="empty__icon">💬</div>' +
        '<p class="empty__title">还没有人提问</p>' +
        '<p class="empty__text">' + (open ? '你可以第一个提出想听的问题。' : '这一期没有收到问题。') + '</p></div>';
    } else {
      listEl.innerHTML = qs.map(function (q, i) {
        return cardHTML(q, i, total, myVotes, open);
      }).join('');
    }

    // FLIP：反向位移后放行，得到平滑重排
    if (animate) {
      Array.prototype.forEach.call(listEl.children, function (el) {
        if (!el.dataset || !el.dataset.id) { return; }
        var old = before[el.dataset.id];
        if (old === undefined) { return; }
        var delta = old - el.getBoundingClientRect().top;
        if (!delta) { return; }
        el.style.transition = 'none';
        el.style.transform = 'translateY(' + delta + 'px)';
        requestAnimationFrame(function () {
          el.style.transition = 'transform .45s cubic-bezier(.22,.61,.36,1)';
          el.style.transform = '';
        });
      });
    }

    // 票数变化：数字滚动 + 按钮轻弹；底部占比条滑到新宽度
    qs.forEach(function (q) {
      var card = listEl.querySelector('[data-id="' + q.id + '"]');
      if (!card) { return; }

      var fill = card.querySelector('.card__bar > i');
      if (fill) {
        requestAnimationFrame(function () { fill.style.width = fill.dataset.w + '%'; });
      }

      var was = prevCounts[q.id];
      if (animate && was !== undefined && was !== q.votes_cache) {
        var btn = card.querySelector('.vote');
        var num = card.querySelector('.vote__num');
        if (num) { tweenNumber(num, was, q.votes_cache, 440); }
        if (btn) {
          btn.classList.remove('vote--bump');
          void btn.offsetWidth;          // 强制回流，保证动画能重播
          btn.classList.add('vote--bump');
        }
      }
      prevCounts[q.id] = q.votes_cache;
      prevPcts[q.id]   = total > 0 ? Math.round(q.votes_cache * 100 / total) : 0;
    });

    seenIds = qs.map(function (q) { return q.id; });
    updateMeta();
  }

  function updateMeta() {
    if (chipStat && S.stats) {
      chipStat.innerHTML = '<span class="tnum">' + S.stats.voters + '</span> 人参与 · ' +
                           '<span class="tnum">' + S.stats.votes + '</span> 票';
    }
    if (hintEl) {
      var q = S.quota && S.quota.vote;
      var phase = S.survey && S.survey.phase;
      if (phase === 'pending') { hintEl.textContent = '本期尚未开始'; }
      else if (phase === 'closed' || !S.survey.open) { hintEl.textContent = '本期已结束'; }
      else if (q && q.used > 0) {
        hintEl.textContent = q.quota === 1
          ? '你今天已投 1 票，可改投'
          : '你今天已投 ' + q.used + '/' + q.quota + ' 票，还可投 ' + Math.max(0, q.quota - q.used) + ' 票';
      } else {
        hintEl.textContent = '今天还有 ' + (q ? q.quota : 1) + ' 票，点一下就投';
      }
    }
    if (dockHint) {
      var s = S.quota && S.quota.submit;
      dockHint.textContent = (s && !s.allowed)
        ? (s.reason || '今天的提问次数用完了')
        : '没看到想听的？补充一个，会立刻出现在上面的列表里。';
    }
    var btn = document.getElementById('btn-open-sheet');
    if (btn) {
      var s2 = S.quota && S.quota.submit;
      btn.disabled = !(s2 && s2.allowed) || !S.survey.open;
      if (!S.survey.open) {
        btn.textContent = S.survey.phase === 'pending' ? '尚未开始' : '本期已结束';
      } else if (s2 && !s2.allowed) {
        btn.textContent = s2.reason || '今天已提过';
      } else {
        btn.textContent = '提出我的问题';
      }
    }
  }

  /* ---------------------------------------------------------------- 投票 */

  function handleVote(id) {
    if (busy) { return; }
    if (!S.survey.open) { toast('本期调研已截止', true); return; }

    var voted = (S.my_votes || []).indexOf(id) !== -1;
    busy = true;

    post(voted ? 'unvote' : 'vote', { question_id: id }).then(function (res) {
      busy = false;
      if (!res.ok) { toast(res.error || '操作失败', true); return; }
      var d = res.data || {};
      S.questions = d.questions || S.questions;
      S.my_votes  = d.my_votes  || [];
      S.quota     = d.quota     || S.quota;
      S.stats     = d.stats     || S.stats;
      S.version   = d.version   || S.version;
      render(true);
      if (d.message) { toast(d.message); }
    }).catch(function () {
      busy = false;
      toast('网络不太顺，稍后再试', true);
    });
  }

  listEl.addEventListener('click', function (ev) {
    var card = ev.target.closest('.card');
    if (!card || !card.dataset.id) { return; }
    handleVote(parseInt(card.dataset.id, 10));
  });

  listEl.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' && ev.key !== ' ') { return; }
    var card = ev.target.closest('.card');
    if (!card || !card.dataset.id) { return; }
    ev.preventDefault();
    handleVote(parseInt(card.dataset.id, 10));
  });

  /* ------------------------------------------------------------ 提问弹层 */

  var sheet = document.getElementById('sheet');
  var mask  = document.getElementById('sheet-mask');
  var form  = document.getElementById('submit-form');

  function openSheet() {
    if (!sheet) { return; }
    sheet.classList.add('is-open');
    mask.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { document.getElementById('q-title').focus(); }, 320);
  }
  function closeSheet() {
    if (!sheet) { return; }
    sheet.classList.remove('is-open');
    mask.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  var openBtn = document.getElementById('btn-open-sheet');
  if (openBtn) { openBtn.addEventListener('click', openSheet); }
  if (mask)    { mask.addEventListener('click', closeSheet); }
  var cancelBtn = document.getElementById('btn-cancel');
  if (cancelBtn) { cancelBtn.addEventListener('click', closeSheet); }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeSheet(); }
  });

  function bindCounter(inputId, counterId, max) {
    var input = document.getElementById(inputId);
    var out   = document.getElementById(counterId);
    if (!input || !out) { return; }
    input.addEventListener('input', function () {
      var n = Array.from(input.value.trim()).length;
      out.textContent = n + ' / ' + max;
      out.className = 'counter' + (n > max ? ' counter--over' : '');
    });
  }

  if (form) {
    var maxT = parseInt((document.getElementById('counter-title').textContent.split('/')[1] || '80'), 10);
    var maxD = parseInt((document.getElementById('counter-detail').textContent.split('/')[1] || '200'), 10);
    bindCounter('q-title', 'counter-title', maxT);
    bindCounter('q-detail', 'counter-detail', maxD);

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (busy) { return; }
      var title  = document.getElementById('q-title').value.trim();
      var detail = document.getElementById('q-detail').value.trim();
      if (Array.from(title).length < 4) { toast('问题至少写 4 个字', true); return; }
      if (Array.from(title).length > maxT) { toast('问题最多 ' + maxT + ' 个字', true); return; }

      var btn = document.getElementById('btn-submit');
      busy = true;
      btn.disabled = true;
      btn.textContent = '提交中…';

      post('submit', { title: title, detail: detail }).then(function (res) {
        busy = false;
        btn.disabled = false;
        btn.textContent = '提交';
        if (!res.ok) { toast(res.error || '提交失败', true); return; }
        var d = res.data || {};
        S.questions = d.questions || S.questions;
        S.my_votes  = d.my_votes  || S.my_votes;
        S.quota     = d.quota     || S.quota;
        S.stats     = d.stats     || S.stats;
        S.version   = d.version   || S.version;
        form.reset();
        document.getElementById('counter-title').textContent = '0 / ' + maxT;
        document.getElementById('counter-detail').textContent = '0 / ' + maxD;
        closeSheet();
        render(true);
        toast(d.message || '提交成功');
        // 滚动到新问题
        if (d.question_id) {
          var el = listEl.querySelector('[data-id="' + d.question_id + '"]');
          if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        }
      }).catch(function () {
        busy = false;
        btn.disabled = false;
        btn.textContent = '提交';
        toast('网络不太顺，稍后再试', true);
      });
    });
  }

  /* -------------------------------------------------------- 定时增量刷新 */

  var timer = null;
  function refresh() {
    if (busy || document.hidden) { return; }
    fetch(S.api + '?action=state', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok || !res.data) { return; }
        if (!res.data.survey) {
          window.location.reload();
          return;
        }
        var d = res.data;
        var stateChanged = d.version !== S.version ||
          JSON.stringify(d.survey) !== JSON.stringify(S.survey) ||
          JSON.stringify(d.quota) !== JSON.stringify(S.quota) ||
          JSON.stringify(d.my_votes) !== JSON.stringify(S.my_votes) ||
          JSON.stringify(d.stats) !== JSON.stringify(S.stats);
        if (!stateChanged) { return; }   // 列表、期次、配额和个人状态都没变化
        S.questions = d.questions;
        S.my_votes  = d.my_votes;
        S.quota     = d.quota;
        S.stats     = d.stats;
        S.survey    = d.survey;
        S.version   = d.version;
        render(true);
      })
      .catch(function () { /* 静默失败，下一轮再试 */ });
  }

  function startPolling() {
    if (timer || !S.poll_ms) { return; }
    timer = setInterval(refresh, S.poll_ms);
  }
  function stopPolling() {
    clearInterval(timer);
    timer = null;
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stopPolling(); }
    else { refresh(); startPolling(); }
  });

  /* ------------------------------------------------------------ 倒计时 */

  var cd = document.getElementById('countdown');
  if (cd && (cd.dataset.ends || cd.dataset.starts)) {
    var tick = function () {
      var targetTs = S.survey.phase === 'pending' && cd.dataset.starts
        ? parseInt(cd.dataset.starts, 10) * 1000
        : parseInt(cd.dataset.ends, 10) * 1000;
      var diff = Math.floor((targetTs - Date.now()) / 1000);
      if (diff <= 0) {
        cd.textContent = S.survey.phase === 'pending' ? '即将开始' : '已结束';
        return;
      }
      var d = Math.floor(diff / 86400),
          h = Math.floor(diff % 86400 / 3600),
          m = Math.floor(diff % 3600 / 60),
          s = diff % 60;
      var prefix = S.survey.phase === 'pending' ? '距开始 ' : '剩 ';
      cd.textContent = d > 0 ? (prefix + d + ' 天 ' + h + ' 小时')
                    : h > 0 ? (prefix + h + ' 小时 ' + m + ' 分')
                            : (prefix + m + ' 分 ' + s + ' 秒');
      cd.classList.toggle('chip--warn', diff < 3600);
    };
    tick();
    setInterval(tick, 1000);
  }

  /* ---------------------------------------------------------------- 启动 */

  render(false);
  startPolling();
})();
