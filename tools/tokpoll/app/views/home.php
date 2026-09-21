<?php
/** @var array|null $survey @var array $state @var bool $open */
$phase = $survey ? Survey::phase($survey) : 'closed';

if (!$survey):
?>
<div class="wrap">
  <div class="empty" style="padding-top:120px">
    <div class="empty__icon">☕️</div>
    <p class="empty__title">还没有正在进行的调研</p>
    <p class="empty__text">下一期主题正在准备中，稍后再来看看。</p>
    <p style="margin-top:22px"><a class="btn btn--quiet" href="<?= e(url('history')) ?>">看看往期</a></p>
  </div>
</div>
<?php return; endif; ?>

<header class="hero wrap">
  <p class="hero__eyebrow">本期调研</p>
  <h1 class="hero__title"><?= e($survey['title']) ?></h1>
  <?php if (!empty($survey['subtitle'])): ?>
    <p class="hero__sub"><?= e($survey['subtitle']) ?></p>
  <?php endif; ?>

  <div class="hero__meta">
    <?php if ($phase === 'open'): ?>
      <span class="chip chip--accent"><span class="chip__dot"></span>进行中</span>
    <?php elseif ($phase === 'pending'): ?>
      <span class="chip chip--warn"><span class="chip__dot"></span>即将开始</span>
    <?php else: ?>
      <span class="chip chip--warn"><span class="chip__dot"></span>已结束</span>
    <?php endif; ?>
    <?php if (!empty($survey['ends_at'])): ?>
      <span class="chip" id="countdown"
            data-starts="<?= e((string)($survey['starts_at'] ? strtotime((string)$survey['starts_at']) : '')) ?>"
            data-ends="<?= e((string)strtotime((string)$survey['ends_at'])) ?>">
        <?= e($phase === 'pending' ? '即将开始' : human_left($survey['ends_at'])) ?>
      </span>
    <?php endif; ?>
    <span class="chip" id="chip-stats">
      <span class="tnum"><?= (int)$state['stats']['voters'] ?></span> 人参与 ·
      <span class="tnum"><?= (int)$state['stats']['votes'] ?></span> 票
    </span>
  </div>

  <?php if (!empty($survey['description'])): ?>
    <p class="hero__desc"><?= nl2br(e($survey['description'])) ?></p>
  <?php endif; ?>
</header>

<div class="wrap">
  <div class="section-head">
    <h2>大家关心的问题</h2>
    <span class="muted" id="list-hint">按票数排序</span>
  </div>

  <div class="list" id="list">
    <div class="skeleton"></div>
    <div class="skeleton" style="opacity:.6"></div>
    <div class="skeleton" style="opacity:.3"></div>
  </div>
</div>

<?php if ($open): ?>
<div class="submit-dock">
  <div class="submit-dock__inner">
    <span class="submit-dock__hint" id="dock-hint">
      没看到想听的？补充一个，提交后会出现在上面的列表里。
    </span>
    <button class="btn" id="btn-open-sheet" type="button">提出我的问题</button>
  </div>
</div>

<div class="sheet-mask" id="sheet-mask"></div>
<div class="sheet" id="sheet" role="dialog" aria-modal="true" aria-labelledby="sheet-title">
  <div class="sheet__grip"></div>
  <h3 class="sheet__title" id="sheet-title">提出你关心的问题</h3>
  <p class="sheet__desc">一句话说清楚就好，提交后会出现在列表里供大家投票。每人每天可以提 <?= (int)($survey['submits_per_day'] ?? 1) ?> 个。</p>

  <form id="submit-form" novalidate>
    <div class="field">
      <label class="field__label" for="q-title">
        <span>问题</span>
        <span class="counter" id="counter-title">0 / <?= (int)cfg('question_max_len', 80) ?></span>
      </label>
      <input class="input" id="q-title" name="title" type="text" autocomplete="off"
             maxlength="<?= (int)cfg('question_max_len', 80) + 20 ?>"
             placeholder="例如：如何判断一个需求该不该做？">
    </div>
    <div class="field">
      <label class="field__label" for="q-detail">
        <span>补充说明（选填）</span>
        <span class="counter" id="counter-detail">0 / <?= (int)cfg('detail_max_len', 200) ?></span>
      </label>
      <textarea class="textarea" id="q-detail" name="detail" rows="3"
                maxlength="<?= (int)cfg('detail_max_len', 200) + 40 ?>"
                placeholder="想听到哪个角度、遇到过什么具体情况……"></textarea>
    </div>
    <div style="display:flex;gap:10px;margin-top:20px">
      <button class="btn btn--quiet" type="button" id="btn-cancel" style="flex:0 0 auto">取消</button>
      <button class="btn" type="submit" id="btn-submit" style="flex:1 1 auto">提交</button>
    </div>
  </form>
</div>
<?php endif; ?>

<script id="initial-state" type="application/json"><?= json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
