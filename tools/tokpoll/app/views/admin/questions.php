<?php /** @var array|null $survey @var array $questions @var array $tags @var array $surveys */ ?>

<div class="ahead">
  <div>
    <h1>问题管理</h1>
    <p>不合适的问题用「隐藏」就够了——前台立刻消失，票数留着可查；「彻底删除」才会清数据。</p>
  </div>
  <?php if ($surveys): ?>
  <form method="get" action="<?= e(admin_url()) ?>" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="a" value="questions">
    <?php if (cfg('admin_path_token')): ?><input type="hidden" name="k" value="<?= e((string)cfg('admin_path_token')) ?>"><?php endif; ?>
    <select class="select" name="sid" style="width:auto;min-width:220px" onchange="this.form.submit()">
      <?php foreach ($surveys as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $survey && (int)$survey['id'] === (int)$s['id'] ? 'selected' : '' ?>>
          <?= e(str_limit((string)$s['title'], 24)) ?>
          <?= $s['status'] === 'active' ? '（进行中）' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<?php if (!$survey): ?>
  <div class="panel">
    <h2>还没有期次</h2>
    <p class="muted-note">先去「期次」里新建一期，再回来添加候选问题。</p>
    <p style="margin-top:14px"><a class="btn" href="<?= e(admin_url('survey_edit')) ?>">新建期次</a></p>
  </div>
<?php return; endif; ?>

<div class="panel">
  <h2>添加候选问题</h2>
  <form method="post" action="<?= e(admin_url('q_add')) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="survey_id" value="<?= (int)$survey['id'] ?>">
    <div class="field">
      <input class="input" name="title" type="text" required placeholder="输入一个候选问题，回车提交">
    </div>
    <div class="field">
      <input class="input" name="detail" type="text" placeholder="补充说明（选填）">
    </div>
    <?php if ($tags): ?>
      <div class="field">
        <div class="field__label"><span>标签</span></div>
        <div class="tagpick">
          <?php foreach ($tags as $t): ?>
            <label>
              <input type="checkbox" name="tags[]" value="<?= (int)$t['id'] ?>">
              <span class="tag" data-color="<?= e((string)$t['color']) ?>"><?= e((string)$t['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <button class="btn btn--sm" type="submit">添加</button>
  </form>
</div>

<div class="panel">
  <h2>本期问题（<?= count($questions) ?>）</h2>

  <?php if (!$questions): ?>
    <p class="muted-note">还没有问题。上面先加几个候选，用户进来就能直接投票了。</p>
  <?php else: ?>
  <table class="table">
    <thead>
      <tr><th>问题</th><th class="num">票数</th><th>状态</th><th>来源</th><th></th></tr>
    </thead>
    <tbody>
    <?php
    $maxVotes = 1;
    foreach ($questions as $q) { $maxVotes = max($maxVotes, (int)$q['votes_cache']); }
    foreach ($questions as $q): ?>
      <tr>
        <td>
          <details>
            <summary style="cursor:pointer;font-weight:500;list-style:none">
              <?= e((string)$q['title']) ?>
              <?php if ((int)$q['pinned']): ?><span class="pill pill--active">置顶</span><?php endif; ?>
            </summary>

            <form method="post" action="<?= e(admin_url('q_update')) ?>" style="margin-top:12px">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
              <div class="field">
                <input class="input" name="title" type="text" value="<?= e((string)$q['title']) ?>">
              </div>
              <div class="field">
                <input class="input" name="detail" type="text" value="<?= e((string)$q['detail']) ?>" placeholder="补充说明">
              </div>
              <?php if ($tags): ?>
                <div class="field">
                  <div class="tagpick">
                    <?php $own = array_column($q['tags'], 'id');
                    foreach ($tags as $t): ?>
                      <label>
                        <input type="checkbox" name="tags[]" value="<?= (int)$t['id'] ?>"
                               <?= in_array((int)$t['id'], $own, true) ? 'checked' : '' ?>>
                        <span class="tag" data-color="<?= e((string)$t['color']) ?>"><?= e((string)$t['name']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
              <button class="btn btn--sm" type="submit">保存</button>
            </form>

            <form method="post" action="<?= e(admin_url('q_action')) ?>" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
              <input type="hidden" name="op" value="merge">
              <span class="muted-note">合并到：</span>
              <select class="select" name="into" style="width:auto;max-width:260px">
                <?php foreach ($questions as $o): if ((int)$o['id'] === (int)$q['id']) continue; ?>
                  <option value="<?= (int)$o['id'] ?>"><?= e(str_limit((string)$o['title'], 26)) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn--quiet btn--sm" type="submit">合并</button>
              <span class="muted-note">重复提问用这个，票数会并过去。</span>
            </form>
          </details>

          <div class="mini-bar" style="margin-top:8px;max-width:220px">
            <i style="width:<?= round((int)$q['votes_cache'] * 100 / $maxVotes) ?>%"></i>
          </div>
        </td>

        <td class="num" style="font-weight:600"><?= (int)$q['votes_cache'] ?></td>

        <td>
          <?php if ($q['status'] === 'approved'): ?><span class="pill pill--active">显示中</span>
          <?php elseif ($q['status'] === 'pending'): ?><span class="pill pill--pending">待审核</span>
          <?php else: ?><span class="pill pill--hidden">已隐藏</span><?php endif; ?>
        </td>

        <td>
          <?php if ($q['source'] === 'user'): ?><span class="pill pill--user">用户</span>
          <?php else: ?><span class="pill pill--draft">管理员</span><?php endif; ?>
        </td>

        <td class="num">
          <div class="actions">
            <?php if ($q['status'] === 'pending'): ?>
              <form class="inline-form" method="post" action="<?= e(admin_url('q_action')) ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                <input type="hidden" name="op" value="approve">
                <button class="btn btn--sm" type="submit">通过</button>
              </form>
            <?php endif; ?>

            <form class="inline-form" method="post" action="<?= e(admin_url('q_action')) ?>">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
              <input type="hidden" name="op" value="pin">
              <button class="btn btn--quiet btn--sm" type="submit"><?= (int)$q['pinned'] ? '取消置顶' : '置顶' ?></button>
            </form>

            <form class="inline-form" method="post" action="<?= e(admin_url('q_action')) ?>">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
              <input type="hidden" name="op" value="<?= $q['status'] === 'hidden' ? 'show' : 'hide' ?>">
              <button class="btn btn--quiet btn--sm" type="submit"><?= $q['status'] === 'hidden' ? '恢复' : '隐藏' ?></button>
            </form>

            <form class="inline-form" method="post" action="<?= e(admin_url('q_action')) ?>"
                  data-confirm="彻底删除「<?= e(str_limit((string)$q['title'], 18)) ?>」及其 <?= (int)$q['votes_cache'] ?> 张票？不可恢复。">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
              <input type="hidden" name="op" value="purge">
              <button class="btn btn--danger btn--sm" type="submit">删除</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
