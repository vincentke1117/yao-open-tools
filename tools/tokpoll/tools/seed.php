<?php
declare(strict_types=1);

/**
 * 演示数据生成器。
 *   php tools/seed.php          写入一期进行中 + 一期已结束的示例数据
 *   php tools/seed.php --reset  先清空所有数据再写入
 *
 * 正式使用前，在后台把示例期次删掉或改成自己的主题即可。
 */

require __DIR__ . '/../app/bootstrap.php';

$reset = in_array('--reset', $argv, true);
$pdo = Db::pdo();

if ($reset) {
    foreach (['votes', 'submissions', 'question_tags', 'questions', 'tags', 'surveys', 'audit_log', 'rate_limit', 'login_attempts'] as $t) {
        $pdo->exec("DELETE FROM {$t}");
    }
    $pdo->exec("DELETE FROM sqlite_sequence");
    echo "已清空旧数据\n";
}

if ((int)Db::val('SELECT COUNT(*) FROM surveys') > 0 && !$reset) {
    echo "数据库里已经有期次了，加 --reset 可重来。\n";
    exit;
}

// ---- 标签 ----
$tagIds = [];
foreach ([['工程实践', 'blue'], ['职业发展', 'green'], ['工具链', 'purple'], ['团队协作', 'orange'], ['入门', 'gray']] as $i => [$name, $color]) {
    Db::run('INSERT INTO tags(name, color, sort) VALUES(?,?,?)', [$name, $color, $i]);
    $tagIds[$name] = Db::lastId();
}

// ---- 进行中的一期 ----
Db::run("INSERT INTO surveys(title, subtitle, description, status, starts_at, ends_at,
                             allow_submit, need_review, votes_per_day, submits_per_day, created_at, updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?)", [
    '下一期分享，你最想听什么？',
    '选出你最关心的一个问题，票数最高的会成为下一期的主讲内容。',
    '每人每天可以投 1 票，也可以补充 1 个自己关心的问题。',
    'active',
    date('Y-m-d H:i:s', strtotime('-2 days')),
    date('Y-m-d H:i:s', strtotime('+5 days 21:00')),
    1, 0, 1, 1, now(), now(),
]);
$sid = Db::lastId();

$seedQuestions = [
    ['AI 辅助编程到底该怎么用才不踩坑？', '想听真实项目里的边界在哪。', 'admin', ['工程实践', '工具链'], 23],
    ['代码评审要看什么，怎么评才不得罪人？', '', 'admin', ['团队协作'], 17],
    ['怎么判断一个需求该不该做？', '', 'user', ['工程实践'], 14],
    ['三年经验想转架构，路径是什么？', '希望有具体的能力清单。', 'user', ['职业发展'], 11],
    ['单元测试写到什么程度算够？', '', 'admin', ['工程实践'], 8],
    ['零基础怎么入门后端开发？', '', 'user', ['入门'], 5],
    ['远程协作里，文档该怎么写？', '', 'user', ['团队协作'], 3],
];

foreach ($seedQuestions as $i => [$title, $detail, $source, $tags, $votes]) {
    Db::run('INSERT INTO questions(survey_id, title, detail, source, status, votes_cache, created_at, voter_key, ip)
             VALUES(?,?,?,?,?,?,?,?,?)',
        [$sid, $title, $detail, $source, 'approved', 0, date('Y-m-d H:i:s', strtotime("-{$i} hours")), 'seed', '127.0.0.1']);
    $qid = Db::lastId();
    Survey::setQuestionTags($qid, array_map(fn($n) => $tagIds[$n], $tags));

    // 造票
    for ($k = 0; $k < $votes; $k++) {
        $day = date('Y-m-d', strtotime('-' . ($k % 3) . ' days'));
        Db::run('INSERT OR IGNORE INTO votes(survey_id, question_id, voter_key, ip, ua_hash, vote_date, created_at)
                 VALUES(?,?,?,?,?,?,?)',
            [$sid, $qid, 'seed-' . $i . '-' . $k, '10.0.0.' . ($k % 200), 'seedua', $day, now()]);
    }
    Survey::recount($qid);
}

// ---- 已结束的一期 ----
Db::run("INSERT INTO surveys(title, subtitle, description, status, starts_at, ends_at,
                             allow_submit, need_review, votes_per_day, submits_per_day, created_at, updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?)", [
    '第 01 期：工程师的第一年该怎么过？',
    '上一期的调研结果已归档。',
    '',
    'closed',
    date('Y-m-d H:i:s', strtotime('-21 days')),
    date('Y-m-d H:i:s', strtotime('-14 days')),
    1, 0, 1, 1, now(), now(),
]);
$sid2 = Db::lastId();

$old = [
    ['怎么从「能跑就行」进阶到「写得好」？', 31],
    ['该不该早早换语言 / 换方向？', 19],
    ['怎么跟产品经理有效沟通？', 12],
    ['第一年要不要刷算法题？', 7],
];
foreach ($old as $i => [$title, $votes]) {
    Db::run('INSERT INTO questions(survey_id, title, source, status, votes_cache, created_at, voter_key, ip)
             VALUES(?,?,?,?,?,?,?,?)',
        [$sid2, $title, $i % 2 ? 'user' : 'admin', 'approved', 0, date('Y-m-d H:i:s', strtotime('-20 days')), 'seed', '127.0.0.1']);
    $qid = Db::lastId();
    for ($k = 0; $k < $votes; $k++) {
        $day = date('Y-m-d', strtotime('-' . (14 + ($k % 5)) . ' days'));
        Db::run('INSERT OR IGNORE INTO votes(survey_id, question_id, voter_key, ip, ua_hash, vote_date, created_at)
                 VALUES(?,?,?,?,?,?,?)',
            [$sid2, $qid, 'old-' . $i . '-' . $k, '10.0.1.' . ($k % 200), 'seedua', $day, now()]);
    }
    Survey::recount($qid);
}

echo "演示数据已写入：\n";
echo "  进行中期次 #{$sid}，已结束期次 #{$sid2}\n";
echo "  数据库：" . cfg('db_path') . "\n";
