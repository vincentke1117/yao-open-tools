<?php /** @var array|null $current @var array|null $stats @var array $trend @var array $surveys @var int $pending @var array $recent */ ?>

<div class="ahead">
  <div>
    <h1>总览</h1>
    <p>当前这一期的实时情况，以及用户刚补充的问题。</p>
  </div>
  <a class="btn btn--sm" href="<?= e(admin_url('survey_edit')) ?>">新建一期</a>
</div>

<?php if (!$current): ?>
  <div class="panel">
    <h2>还没有进行中的期次</h2>
    <p class="muted-note">新建一期并把状态设为「进行中」，前台首页就会显示它。同一时间只会有一期在跑。</p>
    <p style="margin-top:16px"><a class="btn" href="<?= e(admin_url('survey_edit')) ?>">新建期次</a></p>
  </div>
<?php else: ?>

  <div class="panel">
    <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
      <div style="min-width:0">
        <span class="pill pill--active">进行中</span>
        <h2 style="margin:8px 0 2px;font-size:20px"><?= e($current['title']) ?></h2>
        <p class="muted-note" style="margin:0"><?= e((string)$current['subtitle']) ?></p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn--quiet btn--sm" href="<?= e(admin_url('survey_edit', ['id' => (int)$current['id']])) ?>">编辑本期</a>
        <a class="btn btn--sm" href="<?= e(admin_url('questions', ['sid' => (int)$current['id']])) ?>">管理问题</a>
      </div>
    </div>

    <div class="stats" style="margin-top:18px">
      <div class="stat"><div class="stat__num tnum"><?= (int)$stats['voters'] ?></div><div class="stat__label">参与人数</div></div>
      <div class="stat"><div class="stat__num tnum"><?= (int)$stats['votes'] ?></div><div class="stat__label">总票数</div></div>
      <div class="stat"><div class="stat__num tnum"><?= (int)$stats['questions'] ?></div><div class="stat__label">显示中的问题</div></div>
      <div class="stat"><div class="stat__num tnum"><?= (int)$stats['user_questions'] ?></div><div class="stat__label">用户补充</div></div>
    </div>

    <div class="kv" style="margin-top:14px">
      <span>截止时间</span>
      <span><?= $current['ends_at'] ? e((string)$current['ends_at']) . '（' . e(human_left($current['ends_at'])) . '）' : '未设置' ?></span>
    </div>
    <div class="kv">
      <span>用户补充问题</span>
      <span><?= (int)$current['allow_submit'] ? ((int)$current['need_review'] ? '开放 · 先审后显' : '开放 · 后发先显') : '已关闭' ?></span>
    </div>
    <div class="kv">
      <span>每人每天票数</span>
      <span><?= (int)$current['votes_per_day'] ?> 票</span>
    </div>
  </div>

  <div class="grid2">
    <div class="panel">
      <h2>每日投票</h2>
      <?php if (!$trend): ?>
        <p class="muted-note">还没有人投票。</p>
      <?php else:
        $maxDay = 1;
        foreach ($trend as $t) { $maxDay = max($maxDay, (int)$t['n']); }
        foreach (array_reverse($trend) as $t): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:5px 0">
          <span class="tnum" style="width:50px;font-size:12px;color:var(--text-3)"><?= e(date('n/j', strtotime((string)$t['d']))) ?></span>
          <div class="mini-bar" style="flex:1 1 auto"><i style="width:<?= round((int)$t['n'] * 100 / $maxDay) ?>%"></i></div>
          <span class="tnum" style="width:92px;text-align:right;font-size:13px;white-space:nowrap"><?= (int)$t['n'] ?> 票 / <?= (int)$t['people'] ?> 人</span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="panel">
      <h2>最新补充的问题 <?= $pending ? '<span class="pill pill--pending">' . $pending . ' 条待审</span>' : '' ?></h2>
      <?php if (!$recent): ?>
        <p class="muted-note">还没有用户补充问题。</p>
      <?php else: ?>
        <table class="table">
          <?php foreach ($recent as $r): ?>
          <tr>
            <td>
              <div><?= e(str_limit((string)$r['title'], 34)) ?></div>
              <div class="muted-note" style="margin-top:3px">
                <?= e(date('n月j日 H:i', strtotime((string)$r['created_at']))) ?>
                <?php if ($r['status'] === 'pending'): ?><span class="pill pill--pending">待审</span>
                <?php elseif ($r['status'] === 'hidden'): ?><span class="pill pill--hidden">已隐藏</span><?php endif; ?>
              </div>
            </td>
            <td class="num" style="white-space:nowrap">
              <a class="btn btn--quiet btn--sm" href="<?= e(admin_url('questions', ['sid' => (int)$r['survey_id']])) ?>">处理</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

<div class="panel">
  <h2>全部期次</h2>
  <table class="table">
    <thead><tr><th>主题</th><th>状态</th><th class="num">问题</th><th class="num">票数</th><th>截止</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($surveys as $s): ?>
      <tr>
        <td><?= e(str_limit((string)$s['title'], 30)) ?></td>
        <td><span class="pill pill--<?= e((string)$s['status'] === 'archived' ? 'closed' : (string)$s['status']) ?>">
          <?= e(['draft' => '草稿', 'active' => '进行中', 'closed' => '已结束', 'archived' => '已归档'][$s['status']] ?? $s['status']) ?>
        </span></td>
        <td class="num"><?= (int)$s['question_count'] ?></td>
        <td class="num"><?= (int)$s['vote_count'] ?></td>
        <td><?= e($s['ends_at'] ? date('Y-m-d H:i', strtotime((string)$s['ends_at'])) : '—') ?></td>
        <td class="num"><div class="actions">
          <a class="btn btn--quiet btn--sm" href="<?= e(admin_url('questions', ['sid' => (int)$s['id']])) ?>">问题</a>
          <a class="btn btn--quiet btn--sm" href="<?= e(admin_url('survey_edit', ['id' => (int)$s['id']])) ?>">编辑</a>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
