<?php /** @var array $surveys @var array|null $current */ ?>

<header class="hero wrap">
  <p class="hero__eyebrow">往期回顾</p>
  <h1 class="hero__title">历史调研</h1>
  <p class="hero__sub">每一期的主题、大家投出的问题，以及最终的数据。</p>
</header>

<div class="wrap">
  <?php if ($current): ?>
    <div class="section-head"><h2>正在进行</h2></div>
    <a class="archive-card" href="<?= e(url('home')) ?>" style="border-color:var(--accent)">
      <p class="archive-card__title"><?= e($current['title']) ?></p>
      <?php if (!empty($current['subtitle'])): ?>
        <p class="archive-card__sub"><?= e(str_limit($current['subtitle'], 60)) ?></p>
      <?php endif; ?>
      <div class="archive-card__meta">
        <span style="color:var(--accent)">● 进行中</span>
        <span><?= e(human_left($current['ends_at'] ?? null)) ?></span>
        <span>去投票 →</span>
      </div>
    </a>
  <?php endif; ?>

  <div class="section-head">
    <h2>已结束</h2>
    <span class="muted"><?= count($surveys) ?> 期</span>
  </div>

  <?php if (!$surveys): ?>
    <div class="empty">
      <div class="empty__icon">🗂</div>
      <p class="empty__title">还没有已结束的调研</p>
      <p class="empty__text">第一期结束后，结果会归档到这里。</p>
    </div>
  <?php else: ?>
    <div class="list">
      <?php foreach ($surveys as $s): ?>
        <a class="archive-card" href="<?= e(url('survey', ['id' => (int)$s['id']])) ?>">
          <p class="archive-card__title"><?= e($s['title']) ?></p>
          <?php if (!empty($s['subtitle'])): ?>
            <p class="archive-card__sub"><?= e(str_limit($s['subtitle'], 60)) ?></p>
          <?php endif; ?>
          <div class="archive-card__meta">
            <span><?= e($s['ends_at'] ? date('Y年n月j日', strtotime((string)$s['ends_at'])) : '—') ?> 结束</span>
            <span class="tnum"><?= (int)$s['question_count'] ?> 个问题</span>
            <span class="tnum"><?= (int)$s['vote_count'] ?> 票</span>
            <span class="tnum"><?= (int)$s['voter_count'] ?> 人参与</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
