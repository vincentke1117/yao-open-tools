<?php /** @var array $logs @var string $dbPath @var int $dbSize */ ?>

<div class="ahead">
  <div>
    <h1>设置</h1>
    <p>这些开关在 config.php 里改，改完刷新即可生效。</p>
  </div>
</div>

<div class="grid2">
  <div class="panel">
    <h2>当前配置</h2>
    <div class="kv"><span>站点名称</span><span><?= e((string)cfg('site_name')) ?></span></div>
    <div class="kv"><span>时区</span><span><?= e((string)cfg('timezone')) ?>（今天是 <?= e(today()) ?>）</span></div>
    <div class="kv"><span>IP 严格模式</span><span><?= cfg('strict_ip') ? '开启 · 同 IP 每天只能投一次' : '关闭 · 同 IP 多设备可各自投票' ?></span></div>
    <div class="kv"><span>信任反向代理 IP</span><span><?= cfg('trust_proxy') ? '是' : '否' ?></span></div>
    <div class="kv"><span>伪静态</span><span><?= cfg('pretty_url') ? '开启' : '关闭（使用 ?p= 参数）' ?></span></div>
    <div class="kv"><span>前台刷新间隔</span><span><?= (int)cfg('poll_interval_ms') ?> 毫秒</span></div>
    <div class="kv"><span>写操作限流</span><span>每 IP 每分钟 <?= (int)cfg('rate_limit_per_min') ?> 次</span></div>
    <div class="kv"><span>问题长度</span><span><?= (int)cfg('question_min_len') ?> – <?= (int)cfg('question_max_len') ?> 字</span></div>
    <div class="kv"><span>后台入口口令</span><span><?= cfg('admin_path_token') ? '已启用' : '未启用' ?></span></div>
  </div>

  <div class="panel">
    <h2>数据库</h2>
    <div class="kv"><span>文件</span><span style="font-family:ui-monospace,Menlo,monospace;font-size:12px;word-break:break-all"><?= e($dbPath) ?></span></div>
    <div class="kv"><span>大小</span><span><?= number_format($dbSize / 1024, 1) ?> KB</span></div>
    <div class="kv"><span>期次 / 问题 / 票</span><span>
      <?= (int)Db::val('SELECT COUNT(*) FROM surveys') ?> /
      <?= (int)Db::val('SELECT COUNT(*) FROM questions') ?> /
      <?= (int)Db::val('SELECT COUNT(*) FROM votes') ?>
    </span></div>
    <p class="muted-note" style="margin-top:14px">
      备份就是把 data/ 目录整个复制走（含 -wal、-shm 文件）。
      换密码：<code>php -r 'echo password_hash("新密码", PASSWORD_DEFAULT);'</code>，
      把结果填进 config.php 的 admin_password_hash。
    </p>
  </div>
</div>

<div class="panel">
  <h2>最近的后台操作</h2>
  <?php if (!$logs): ?>
    <p class="muted-note">还没有记录。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>时间</th><th>操作</th><th>对象</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td class="muted-note" style="white-space:nowrap"><?= e((string)$l['created_at']) ?></td>
          <td><?= e((string)$l['action']) ?></td>
          <td><?= e(str_limit((string)$l['detail'] ?: (string)$l['target'], 34)) ?></td>
          <td class="muted-note" style="font-family:ui-monospace,Menlo,monospace;font-size:12px"><?= e((string)$l['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
