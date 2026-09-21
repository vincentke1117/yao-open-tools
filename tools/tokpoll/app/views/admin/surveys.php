<?php /** @var array $surveys */ ?>

<div class="ahead">
  <div>
    <h1>期次管理</h1>
    <p>首页每次只展示一期。把某一期设为「进行中」时，原先进行中的会自动结束。</p>
  </div>
  <a class="btn btn--sm" href="<?= e(admin_url('survey_edit')) ?>">新建一期</a>
</div>

<div class="panel">
  <table class="table">
    <thead>
      <tr><th>主题</th><th>状态</th><th class="num">问题</th><th class="num">票数</th><th>起止时间</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (!$surveys): ?>
      <tr><td colspan="6"><p class="muted-note" style="margin:8px 0">还没有任何期次，先新建一期吧。</p></td></tr>
    <?php endif; ?>
    <?php foreach ($surveys as $s): ?>
      <tr>
        <td>
          <div style="font-weight:500"><?= e((string)$s['title']) ?></div>
          <?php if (!empty($s['subtitle'])): ?>
            <div class="muted-note"><?= e(str_limit((string)$s['subtitle'], 44)) ?></div>
          <?php endif; ?>
        </td>
        <td><span class="pill pill--<?= e((string)$s['status'] === 'archived' ? 'closed' : (string)$s['status']) ?>">
          <?= e(['draft' => '草稿', 'active' => '进行中', 'closed' => '已结束', 'archived' => '已归档'][$s['status']] ?? $s['status']) ?>
        </span></td>
        <td class="num"><?= (int)$s['question_count'] ?></td>
        <td class="num"><?= (int)$s['vote_count'] ?></td>
        <td class="muted-note" style="white-space:nowrap">
          <?= e($s['starts_at'] ? date('n/j H:i', strtotime((string)$s['starts_at'])) : '—') ?>
          →
          <?= e($s['ends_at'] ? date('n/j H:i', strtotime((string)$s['ends_at'])) : '—') ?>
        </td>
        <td class="num">
          <div class="actions">
            <a class="btn btn--quiet btn--sm" href="<?= e(admin_url('questions', ['sid' => (int)$s['id']])) ?>">问题</a>
            <a class="btn btn--quiet btn--sm" href="<?= e(admin_url('data', ['sid' => (int)$s['id']])) ?>">数据</a>
            <a class="btn btn--quiet btn--sm" href="<?= e(admin_url('survey_edit', ['id' => (int)$s['id']])) ?>">编辑</a>
            <form class="inline-form" method="post" action="<?= e(admin_url('survey_delete')) ?>"
                  data-confirm="确定删除「<?= e(str_limit((string)$s['title'], 20)) ?>」？这一期的问题和票数会一起删掉，不可恢复。">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn--danger btn--sm" type="submit">删除</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
