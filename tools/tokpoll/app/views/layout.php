<?php /** @var string $content @var string $pageTitle @var string $navActive */
$footerSurvey = $survey ?? ($current ?? null);
$footerVotes = max(1, (int)($footerSurvey['votes_per_day'] ?? 1));
$footerSubmits = max(1, (int)($footerSurvey['submits_per_day'] ?? 1));
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="referrer" content="same-origin">
<title><?= e($pageTitle) ?></title>
<link rel="icon" href="<?= e(base_path()) ?>/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>

<nav class="nav">
  <a class="nav__brand" href="<?= e(url('home')) ?>"><?= e((string)cfg('site_name')) ?></a>
  <div class="nav__links">
    <a class="nav__link <?= $navActive === 'home' ? 'nav__link--active' : '' ?>" href="<?= e(url('home')) ?>">本期</a>
    <a class="nav__link <?= $navActive === 'history' ? 'nav__link--active' : '' ?>" href="<?= e(url('history')) ?>">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <circle cx="8" cy="8" r="6.4" stroke="currentColor" stroke-width="1.4"/>
        <path d="M8 4.6V8l2.4 1.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
      历史调研
    </a>
  </div>
</nav>

<main class="page">
<?= $content ?>
</main>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<footer class="foot wrap">
  <div><?= e((string)cfg('site_name')) ?></div>
  <div>每人每天可投 <?= $footerVotes ?> 票、提 <?= $footerSubmits ?> 个问题</div>
  <div class="foot__brand">Tokpoll</div>
</footer>

<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
