<?php /** @var array|null $survey @var array $surveys @var array|null $stats @var array $trend @var array $questions @var array $ips */ ?>

<div class="ahead">
  <div>
    <h1>数据</h1>
    <p>每一期的参与情况。导出的 CSV 带 BOM，Excel 直接打开不乱码。</p>
  </div>
  <?php if ($surveys): ?>
  <div style="display:flex;gap:8px;align-items:center">
    <form method="get" action="<?= e(admin_url()) ?>">
      <input type="hidden" name="a" value="data">
      <?php if (cfg('admin_path_token')): ?><input type="hidden" name="k" value="<?= e((string)cfg('admin_path_token')) ?>"><?php endif; ?>
      <select class="select" name="sid" style="width:auto;min-width:200px" onchange="this.form.submit()">
        <?php foreach ($surveys as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $survey && (int)$survey['id'] === (int)$s['id'] ? 'selected' : '' ?>>
            <?= e(str_limit((string)$s['title'], 22)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($survey): ?>
      <a class="btn btn--sm" href="<?= e(admin_url('export', ['sid' => (int)$survey['id']])) ?>">导出 CSV</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (!$survey): ?>
  <div class="panel"><p class="muted-note">还没有任何期次。</p></div>
<?php return; endif; ?>

<div class="panel">
  <h2><?= e((string)$survey['title']) ?></h2>
  <div class="stats">
    <div class="stat"><div class="stat__num tnum"><?= (int)$stats['voters'] ?></div><div class="stat__label">参与人数</div></div>
    <div class="stat"><div class="stat__num tnum"><?= (int)$stats['votes'] ?></div><div class="stat__label">总票数</div></div>
    <div class="stat"><div class="stat__num tnum"><?= (int)$stats['questions'] ?></div><div class="stat__label">显示中的问题</div></div>
    <div class="stat"><div class="stat__num tnum"><?= (int)$stats['user_questions'] ?></div><div class="stat__label">用户补充</div></div>
  </div>
</div>

<div class="grid2">
  <div class="panel">
    <h2>每日参与</h2>
    <?php if (!$trend): ?>
      <p class="muted-note">还没有投票记录。</p>
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
    <h2>投票来源 IP <span class="muted-note">Top 10</span></h2>
    <?php if (!$ips): ?>
      <p class="muted-note">还没有投票记录。</p>
    <?php else: ?>
      <?php foreach ($ips as $r): ?>
        <div class="kv">
          <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px"><?= e((string)$r['ip']) ?></span>
          <span><?= (int)$r['n'] ?> 票 · <?= (int)$r['people'] ?> 台设备</span>
        </div>
      <?php endforeach; ?>
      <p class="muted-note" style="margin-top:12px">
        同一个 IP 出现多台设备是正常的——同一间办公室或教室里，大家连的是同一个 WiFi。
        如果某个 IP 的设备数明显异常，可以在 config.php 里把 strict_ip 打开。
      </p>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <h2>问题排行</h2>
  <?php if (!$questions): ?>
    <p class="muted-note">这一期还没有问题。</p>
  <?php else:
    $total = 0;
    foreach ($questions as $q) { $total += (int)$q['votes_cache']; } ?>
    <table class="table">
      <thead><tr><th>#</th><th>问题</th><th class="num">票数</th><th class="num">占比</th><th>状态</th></tr></thead>
      <tbody>
      <?php foreach ($questions as $i => $q): ?>
        <tr>
          <td class="tnum" style="color:var(--text-3)"><?= $i + 1 ?></td>
          <td><?= e((string)$q['title']) ?></td>
          <td class="num" style="font-weight:600"><?= (int)$q['votes_cache'] ?></td>
          <td class="num"><?= $total > 0 ? round((int)$q['votes_cache'] * 100 / $total) : 0 ?>%</td>
          <td>
            <?php if ($q['status'] === 'approved'): ?><span class="pill pill--active">显示中</span>
            <?php elseif ($q['status'] === 'pending'): ?><span class="pill pill--pending">待审核</span>
            <?php else: ?><span class="pill pill--hidden">已隐藏</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
