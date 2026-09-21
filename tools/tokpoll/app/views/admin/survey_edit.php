<?php
/** @var array|null $survey */
$s = $survey ?? [];
$dt = static function (?string $v): string {
    return $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
};
?>

<div class="ahead">
  <div>
    <h1><?= $survey ? '编辑期次' : '新建期次' ?></h1>
    <p>主题、副标题和截止时间会显示在前台首页顶部。</p>
  </div>
  <a class="btn btn--quiet btn--sm" href="<?= e(admin_url('surveys')) ?>">返回列表</a>
</div>

<form method="post" action="<?= e(admin_url('survey_save')) ?>">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <?php if ($survey): ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><?php endif; ?>

  <div class="panel">
    <h2>主题信息</h2>

    <div class="field">
      <label class="field__label" for="f-title"><span>主题 <small>必填，显示为首页大标题</small></span></label>
      <input class="input" id="f-title" name="title" type="text" required
             value="<?= e((string)($s['title'] ?? '')) ?>"
             placeholder="例如：下一期分享，你最想听什么？">
    </div>

    <div class="field">
      <label class="field__label" for="f-subtitle"><span>副标题 <small>一句话说明规则或意图</small></span></label>
      <input class="input" id="f-subtitle" name="subtitle" type="text"
             value="<?= e((string)($s['subtitle'] ?? '')) ?>"
             placeholder="选出你最关心的一个问题，票数最高的会成为下一期的主讲内容。">
    </div>

    <div class="field">
      <label class="field__label" for="f-desc"><span>补充说明 <small>选填，显示在副标题下方</small></span></label>
      <textarea class="textarea" id="f-desc" name="description" rows="2"><?= e((string)($s['description'] ?? '')) ?></textarea>
    </div>
  </div>

  <div class="panel">
    <h2>时间与状态</h2>
    <div class="form-row">
      <div class="field">
        <label class="field__label" for="f-status"><span>状态</span></label>
        <select class="select" id="f-status" name="status">
          <?php foreach (['draft' => '草稿（前台不显示）', 'active' => '进行中（首页展示这一期）',
                          'closed' => '已结束（进入历史）', 'archived' => '已归档'] as $k => $label): ?>
            <option value="<?= $k ?>" <?= ($s['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="f-start"><span>开始时间 <small>选填</small></span></label>
        <input class="input" id="f-start" name="starts_at" type="datetime-local" value="<?= e($dt($s['starts_at'] ?? null)) ?>">
      </div>
      <div class="field">
        <label class="field__label" for="f-end"><span>截止时间 <small>到点自动结束</small></span></label>
        <input class="input" id="f-end" name="ends_at" type="datetime-local" value="<?= e($dt($s['ends_at'] ?? null)) ?>">
      </div>
    </div>
  </div>

  <div class="panel">
    <h2>投票规则</h2>
    <div class="form-row">
      <div class="field">
        <label class="field__label" for="f-vpd"><span>每人每天票数</span></label>
        <select class="select" id="f-vpd" name="votes_per_day">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <option value="<?= $i ?>" <?= (int)($s['votes_per_day'] ?? 1) === $i ? 'selected' : '' ?>>
              <?= $i ?> 票<?= $i === 1 ? '（推荐，可当天改投）' : '' ?>
            </option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field">
        <label class="field__label"><span>用户补充问题</span></label>
        <label class="check">
          <input type="checkbox" name="allow_submit" value="1" <?= (int)($s['allow_submit'] ?? 1) ? 'checked' : '' ?>>
          <span>允许补充<small>每人每天 1 个</small></span>
        </label>
      </div>
      <div class="field">
        <label class="field__label"><span>审核方式</span></label>
        <label class="check">
          <input type="checkbox" name="need_review" value="1" <?= (int)($s['need_review'] ?? 0) ? 'checked' : '' ?>>
          <span>先审后显<small>不勾选＝后发先显，现场不用盯着审</small></span>
        </label>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button class="btn" type="submit"><?= $survey ? '保存修改' : '创建并添加问题' ?></button>
    <a class="btn btn--quiet" href="<?= e(admin_url('surveys')) ?>">取消</a>
  </div>
</form>
