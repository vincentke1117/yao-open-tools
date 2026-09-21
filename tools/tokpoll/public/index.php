<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/state.php';

Survey::autoClose();
Voter::key();   // 确保访客拿到 uid cookie

// ---- 路由 --------------------------------------------------------------
$page = (string)($_GET['p'] ?? 'home');

// 伪静态：/history、/s/12
if (cfg('pretty_url') && !isset($_GET['p'])) {
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = '/' . trim(substr($path, strlen(base_path())), '/');
    if ($path === '/history') {
        $page = 'history';
    } elseif (preg_match('~^/s/(\d+)$~', $path, $m) === 1) {
        $page = 'survey';
        $_GET['id'] = $m[1];
    }
}

switch ($page) {

    // ------------------------------------------------------------ 首页
    case 'home':
    default:
        $survey = Survey::current();
        $state  = $survey ? build_state($survey) : ['stats' => ['voters' => 0, 'votes' => 0]];
        render('home', [
            'survey'    => $survey,
            'state'     => $state,
            'open'      => Survey::isOpen($survey),
            'pageTitle' => $survey ? $survey['title'] . ' · ' . cfg('site_name') : (string)cfg('site_name'),
            'navActive' => 'home',
        ]);
        break;

    // ------------------------------------------------------------ 历史列表
    case 'history':
        render('history', [
            'surveys'   => Survey::archiveList(),
            'current'   => Survey::current(),
            'pageTitle' => '历史调研 · ' . cfg('site_name'),
            'navActive' => 'history',
        ]);
        break;

    // ------------------------------------------------------------ 往期详情
    case 'survey':
        $id = (int)($_GET['id'] ?? 0);
        $survey = Survey::find($id);
        if (!$survey || $survey['status'] === 'draft') {
            http_response_code(404);
            render('history', [
                'surveys'   => Survey::archiveList(),
                'current'   => Survey::current(),
                'pageTitle' => '没有找到这一期 · ' . cfg('site_name'),
                'navActive' => 'history',
            ]);
            break;
        }
        if ($survey['status'] === 'active') {
            redirect(url('home'));
        }
        render('archive', [
            'survey'    => $survey,
            'questions' => Survey::questions($id),
            'stats'     => Survey::stats($id),
            'trend'     => Survey::dailyTrend($id, 14),
            'pageTitle' => $survey['title'] . ' · 往期结果',
            'navActive' => 'history',
        ]);
        break;
}
