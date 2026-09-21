<?php
/** @var array $tags @var array $usage */
$use = [];
foreach ($usage as $u) { $use[(int)$u['tag_id']] = (int)$u['n']; }
$colors = ['blue' => '蓝', 'green' => '绿', 'orange' => '橙', 'purple' => '紫', 'pink' => '粉', 'gray' => '灰'];
?>

<div class="ahead">
  <div>
    <h1>标签管理</h1>
    <p>给问题打标签，前台会显示在问题下方，方便用户快速分辨主题。</p>
  </div>
</div>

<div class="panel">
  <h2>新增标签</h2>
  <form method="post" action="<?= e(admin_url('tag_save')) ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="field" style="flex:1 1 200px;margin:0">
      <label class="field__label" for="t-name"><span>标签名</span></label>
      <input class="input" id="t-name" name="name" type="text" required placeholder="例如：工程实践">
    </div>
    <div class="field" style="flex:0 0 140px;margin:0">
      <label class="field__label" for="t-color"><span>颜色</span></label>
      <select class="select" id="t-color" name="color">
        <?php foreach ($colors as $k => $label): ?>
          <option value="<?= $k ?>"><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn" type="submit">添加</button>
  </form>
</div>

<div class="panel">
  <h2>已有标签（<?= count($tags) ?>）</h2>
  <?php if (!$tags): ?>
    <p class="muted-note">还没有标签。</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>标签</th><th>颜色</th><th class="num">已用于</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tags as $t): ?>
      <tr>
        <td>
          <form method="post" action="<?= e(admin_url('tag_save')) ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <input class="input" name="name" type="text" value="<?= e((string)$t['name']) ?>" style="width:auto;flex:1 1 150px">
            <select class="select" name="color" style="width:auto">
              <?php foreach ($colors as $k => $label): ?>
                <option value="<?= $k ?>" <?= $t['color'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn--quiet btn--sm" type="submit">保存</button>
          </form>
        </td>
        <td><span class="tag" data-color="<?= e((string)$t['color']) ?>"><?= e((string)$t['name']) ?></span></td>
        <td class="num"><?= $use[(int)$t['id']] ?? 0 ?> 个问题</td>
        <td class="num">
          <form class="inline-form" method="post" action="<?= e(admin_url('tag_delete')) ?>"
                data-confirm="删除标签「<?= e((string)$t['name']) ?>」？已打上的问题会失去这个标签。">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn btn--danger btn--sm" type="submit">删除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
