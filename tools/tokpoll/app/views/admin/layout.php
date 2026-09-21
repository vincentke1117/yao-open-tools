<?php
/** @var string $content @var string $pageTitle @var string $navActive */
$bare = $vars['bare'] ?? false;
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$nav = [
    'dash'      => '总览',
    'surveys'   => '期次',
    'questions' => '问题',
    'tags'      => '标签',
    'data'      => '数据',
    'settings'  => '设置',
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title><?= e($pageTitle) ?></title>
<link rel="icon" href="<?= e(base_path()) ?>/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('admin.css')) ?>">
</head>
<body class="admin">

<?php if (!$bare): ?>
<header class="abar">
  <a class="abar__brand" href="<?= e(admin_url('dash')) ?>"><?= e((string)cfg('site_name')) ?> · 后台</a>
  <nav class="abar__nav">
    <?php foreach ($nav as $key => $label): ?>
      <a class="<?= $navActive === $key ? 'on' : '' ?>" href="<?= e(admin_url($key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="abar__right">
    <a class="btn btn--quiet btn--sm" href="<?= e(base_path()) ?>/" target="_blank" rel="noopener">看前台</a>
    <a class="btn btn--quiet btn--sm" href="<?= e(admin_url('logout')) ?>">退出</a>
  </div>
</header>

<div class="awrap">
  <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
  <?= $content ?>
</div>
<?php else: ?>
  <?= $content ?>
<?php endif; ?>

<script>
// 危险操作前二次确认（用内联确认替代 confirm 弹窗以外的场景）
document.addEventListener('submit', function (e) {
  var f = e.target;
  if (f.dataset && f.dataset.confirm) {
    if (!window.confirm(f.dataset.confirm)) { e.preventDefault(); }
  }
});
</script>
</body>
</html>
