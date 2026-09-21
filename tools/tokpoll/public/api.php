<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/state.php';

header('X-Content-Type-Options: nosniff');

Survey::autoClose();
Voter::key();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// POST 请求统一做 CSRF 与限流校验
if ($isPost) {
    $body = [];
    $raw  = file_get_contents('php://input');
    if ($raw !== false && $raw !== '' && str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
        $body = json_decode($raw, true) ?: [];
    }
    $input = $body + $_POST;

    if (!csrf_check((string)($input['csrf'] ?? ''))) {
        json_err('页面已过期，请刷新后重试', 419, 'csrf');
    }
    if (!Voter::passRateLimit($action)) {
        json_err('操作太频繁了，歇一会儿', 429, 'rate');
    }
} else {
    $input = $_GET;
}

$survey = Survey::current();

switch ($action) {

    // ------------------------------------------------------- 拉取最新状态
    case 'state':
        if (!$survey) {
            json_ok(['survey' => null, 'questions' => [], 'my_votes' => [], 'version' => '0']);
        }
        json_ok(build_state($survey));
        break;

    // ------------------------------------------------------- 投票
    case 'vote':
        if (!$isPost)  json_err('方法不允许', 405);
        if (!$survey)  json_err('当前没有进行中的调研', 404);
        if (!Survey::isOpen($survey)) json_err('本期调研已截止', 403, 'closed');

        $qid = (int)($input['question_id'] ?? 0);
        if ($qid <= 0) json_err('参数不对', 400);

        $r = Survey::vote($survey, $qid);
        if (!$r['ok']) json_err($r['message'], 409, 'quota');
        json_ok(['message' => $r['message'], 'switched' => $r['switched']] + build_state($survey));
        break;

    // ------------------------------------------------------- 撤回投票
    case 'unvote':
        if (!$isPost)  json_err('方法不允许', 405);
        if (!$survey)  json_err('当前没有进行中的调研', 404);
        if (!Survey::isOpen($survey)) json_err('本期调研已截止', 403, 'closed');

        $qid = (int)($input['question_id'] ?? 0);
        $r = Survey::unvote($survey, $qid);
        if (!$r['ok']) json_err($r['message'], 409);
        json_ok(['message' => $r['message']] + build_state($survey));
        break;

    // ------------------------------------------------------- 补充问题
    case 'submit':
        if (!$isPost)  json_err('方法不允许', 405);
        if (!$survey)  json_err('当前没有进行中的调研', 404);
        if (!Survey::isOpen($survey)) json_err('本期调研已截止', 403, 'closed');

        $r = Survey::submitQuestion(
            $survey,
            (string)($input['title'] ?? ''),
            (string)($input['detail'] ?? '')
        );
        if (!$r['ok']) json_err($r['message'], 409, 'submit');
        json_ok(['message' => $r['message'], 'question_id' => $r['question_id']] + build_state($survey));
        break;

    default:
        json_err('未知的操作', 404);
}
