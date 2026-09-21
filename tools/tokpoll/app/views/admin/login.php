<?php /** @var string $error */ ?>
<div class="login-wrap">
  <div class="login-card">
    <h1><?= e((string)cfg('site_name')) ?></h1>
    <p class="sub">管理后台</p>

    <?php if ($error): ?>
      <div class="login-err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(admin_url('login')) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="field">
        <label class="field__label" for="pwd"><span>管理密码</span></label>
        <input class="input" id="pwd" name="password" type="password" autocomplete="current-password" autofocus>
      </div>
      <button class="btn btn--block" type="submit">进入后台</button>
    </form>

    <p class="muted-note" style="text-align:center;margin:20px 0 0">
      忘记密码？改 config.php 里的 admin_password_hash。
    </p>
  </div>
</div>
