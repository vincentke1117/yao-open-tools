<?php
/** @var array $survey @var array $questions @var array $stats @var array $trend */
$total = 0;
foreach ($questions as $q) { $total += (int)$q['votes_cache']; }
$max = 0;
foreach ($questions as $q) { $max = max($max, (int)$q['votes_cache']); }
?>

<header class="hero wrap">
  <p class="hero__eyebrow"><?= e($survey['ends_at'] ? date('Y年n月j日', strtotime((string)$survey['ends_at'])) : '往期') ?> · 已结束</p>
  <h1 class="hero__title"><?= e($survey['title']) ?></h1>
  <?php if (!empty($survey['subtitle'])): ?>
    <p class="hero__sub"><?= e($survey['subtitle']) ?></p>
  <?php endif; ?>
</header>

<div class="wrap">
  <div class="stats">
    <div class="stat"><div class="stat__num tnum"><?= (int)$stats['voters'] ?></div><div class="stat__label">参与人数</div></div>
    <div class="stat"><div class="stat__num tnum"><?= (int)$stats['votes'] ?></div><div class="stat__label">总票数</div></div>
    <div class="stat"><div class="stat__num tnum"><?= (int)$stats['questions'] ?></div><div class="stat__label">问题总数</div></div>
    <div class="stat"><div class="stat__num tnum"><?= (int)$stats['user_questions'] ?></div><div class="stat__label">用户补充</div></div>
  </div>

  <div class="section-head">
    <h2>问题排行</h2>
    <span class="muted">共 <?= count($questions) ?> 个</span>
  </div>

  <?php if (!$questions): ?>
    <div class="empty"><div class="empty__icon">📭</div><p class="empty__title">这一期没有留下问题</p></div>
  <?php else: ?>
    <div style="background:var(--bg-elevated);border:1px solid var(--hairline-soft);border-radius:var(--r-lg);padding:4px 18px;box-shadow:var(--shadow-1)">
      <?php foreach ($questions as $i => $q):
        $v = (int)$q['votes_cache'];
        $pct = $total > 0 ? round($v * 100 / $total) : 0;
        $w   = $max > 0 ? round($v * 100 / $max) : 0; ?>
        <div class="result">
          <div class="result__head">
            <span class="result__rank tnum"><?= $i + 1 ?></span>
            <span class="result__title"><?= e($q['title']) ?></span>
            <span class="result__num tnum"><?= $v ?></span>
            <span class="result__pct tnum"><?= $pct ?>%</span>
          </div>
          <?php if (!empty($q['tags'])): ?>
            <div style="margin-left:34px;margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
              <?php foreach ($q['tags'] as $t): ?>
                <span class="tag" data-color="<?= e($t['color']) ?>"><?= e($t['name']) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="bar" style="margin-left:34px">
            <div class="bar__fill <?= $i === 0 ? '' : 'bar__fill--muted' ?>" style="width:<?= $w ?>%"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($trend): ?>
    <div class="section-head"><h2>每日参与</h2></div>
    <div style="background:var(--bg-elevated);border:1px solid var(--hairline-soft);border-radius:var(--r-lg);padding:16px 18px;box-shadow:var(--shadow-1)">
      <?php
      $maxDay = 1;
      foreach ($trend as $t) { $maxDay = max($maxDay, (int)$t['n']); }
      foreach (array_reverse($trend) as $t): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:6px 0">
          <span class="tnum" style="width:58px;font-size:12px;color:var(--text-3)"><?= e(date('n/j', strtotime((string)$t['d']))) ?></span>
          <div class="bar" style="flex:1 1 auto;margin:0;height:8px">
            <div class="bar__fill" style="width:<?= round((int)$t['n'] * 100 / $maxDay) ?>%"></div>
          </div>
          <span class="tnum" style="width:56px;text-align:right;font-size:13px"><?= (int)$t['n'] ?> 票</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <p style="margin-top:32px;text-align:center">
    <a class="btn btn--quiet" href="<?= e(url('history')) ?>">← 返回历史列表</a>
  </p>
</div>
