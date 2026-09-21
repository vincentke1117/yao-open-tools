<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

// 入口口令（config.admin_path_token 为空时恒通过）
if (!Admin::pathTokenOk()) {
    http_response_code(404);
    exit('Not Found');
}

Survey::autoClose();

$action = (string)($_GET['a'] ?? 'dash');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// ---------------------------------------------------------------- 登录
if ($action === 'login') {
    if (Admin::isLoggedIn()) {
        redirect(admin_url('dash'));
    }
    $error = '';
    if ($isPost) {
        if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
            $error = '页面已过期，请重试';
        } else {
            $r = Admin::login((string)($_POST['password'] ?? ''));
            if ($r['ok']) {
                redirect(admin_url('dash'));
            }
            $error = $r['message'];
        }
    }
    render_admin('login', ['error' => $error, 'pageTitle' => '登录 · 管理后台', 'bare' => true]);
    exit;
}

if ($action === 'logout') {
    Admin::logout();
    redirect(admin_url('login'));
}

Admin::requireLogin();

// 所有写操作统一校验 CSRF
if ($isPost && !csrf_check((string)($_POST['csrf'] ?? ''))) {
    http_response_code(419);
    exit('页面已过期，请返回刷新后重试。');
}

/** 读取 POST 整数数组 */
function post_ints(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? array_values(array_filter(array_map('intval', $v))) : [];
}

function flash(string $msg): void
{
    $_SESSION['flash'] = $msg;
}

switch ($action) {

    // ============================================================ 总览
    case 'dash': {
        $current = Survey::current();
        render_admin('dash', [
            'current'   => $current,
            'stats'     => $current ? Survey::stats((int)$current['id']) : null,
            'trend'     => $current ? Survey::dailyTrend((int)$current['id'], 10) : [],
            'surveys'   => Survey::allSurveys(),
            'pending'   => (int)Db::val("SELECT COUNT(*) FROM questions WHERE status = 'pending'"),
            'recent'    => Db::all("SELECT q.*, s.title AS survey_title FROM questions q
                                     JOIN surveys s ON s.id = q.survey_id
                                    WHERE q.source = 'user' ORDER BY q.id DESC LIMIT 8"),
            'pageTitle' => '总览 · 管理后台',
            'navActive' => 'dash',
        ]);
        break;
    }

    // ============================================================ 期次
    case 'surveys': {
        render_admin('surveys', [
            'surveys'   => Survey::allSurveys(),
            'pageTitle' => '期次管理 · 管理后台',
            'navActive' => 'surveys',
        ]);
        break;
    }

    case 'survey_edit': {
        $id = (int)($_GET['id'] ?? 0);
        $survey = $id ? Survey::find($id) : null;
        if ($id && !$survey) {
            http_response_code(404);
            exit('期次不存在');
        }
        render_admin('survey_edit', [
            'survey'    => $survey,
            'pageTitle' => ($survey ? '编辑期次' : '新建期次') . ' · 管理后台',
            'navActive' => 'surveys',
        ]);
        break;
    }

    case 'survey_save': {
        if (!$isPost) redirect(admin_url('surveys'));
        $id = (int)($_POST['id'] ?? 0);

        $startsRaw = trim((string)($_POST['starts_at'] ?? ''));
        $endsRaw   = trim((string)($_POST['ends_at'] ?? ''));
        $data = [
            'title'         => Survey::cleanText((string)($_POST['title'] ?? '')),
            'subtitle'      => Survey::cleanText((string)($_POST['subtitle'] ?? '')),
            'description'   => trim((string)($_POST['description'] ?? '')),
            'status'        => in_array($_POST['status'] ?? '', ['draft', 'active', 'closed', 'archived'], true)
                               ? (string)$_POST['status'] : 'draft',
            'starts_at'     => normalize_dt($startsRaw),
            'ends_at'       => normalize_dt($endsRaw),
            'allow_submit'  => isset($_POST['allow_submit']) ? 1 : 0,
            'need_review'   => isset($_POST['need_review']) ? 1 : 0,
            'votes_per_day' => max(1, min(10, (int)($_POST['votes_per_day'] ?? 1))),
        ];
        if ($data['title'] === '') {
            flash('主题不能为空');
            redirect(admin_url('survey_edit', $id ? ['id' => $id] : []));
        }
        if (($startsRaw !== '' && $data['starts_at'] === null) || ($endsRaw !== '' && $data['ends_at'] === null)) {
            flash('时间格式不正确，请重新填写');
            redirect(admin_url('survey_edit', $id ? ['id' => $id] : []));
        }
        if ($data['starts_at'] && $data['ends_at'] && strtotime($data['starts_at']) >= strtotime($data['ends_at'])) {
            flash('开始时间必须早于截止时间');
            redirect(admin_url('survey_edit', $id ? ['id' => $id] : []));
        }

        // 同一时间只允许一期 active
        if ($data['status'] === 'active') {
            Db::run("UPDATE surveys SET status = 'closed', updated_at = ? WHERE status = 'active'" . ($id ? ' AND id != ?' : ''),
                $id ? [now(), $id] : [now()]);
        }

        if ($id) {
            Db::run('UPDATE surveys SET title=?, subtitle=?, description=?, status=?, starts_at=?, ends_at=?,
                                        allow_submit=?, need_review=?, votes_per_day=?, updated_at=? WHERE id=?',
                [...array_values($data), now(), $id]);
            audit('survey_update', (string)$id, $data['title']);
            flash('期次已保存');
        } else {
            Db::run('INSERT INTO surveys(title, subtitle, description, status, starts_at, ends_at,
                                         allow_submit, need_review, votes_per_day, submits_per_day, created_at, updated_at)
                     VALUES(?,?,?,?,?,?,?,?,?,1,?,?)',
                [...array_values($data), now(), now()]);
            $id = Db::lastId();
            audit('survey_create', (string)$id, $data['title']);
            flash('期次已创建，接下来添加候选问题');
        }
        redirect(admin_url('questions', ['sid' => $id]));
        break;
    }

    case 'survey_delete': {
        if (!$isPost) redirect(admin_url('surveys'));
        $id = (int)($_POST['id'] ?? 0);
        Db::run('DELETE FROM question_tags WHERE question_id IN (SELECT id FROM questions WHERE survey_id = ?)', [$id]);
        Db::run('DELETE FROM votes WHERE survey_id = ?', [$id]);
        Db::run('DELETE FROM submissions WHERE survey_id = ?', [$id]);
        Db::run('DELETE FROM questions WHERE survey_id = ?', [$id]);
        Db::run('DELETE FROM surveys WHERE id = ?', [$id]);
        audit('survey_delete', (string)$id);
        flash('期次及其数据已删除');
        redirect(admin_url('surveys'));
        break;
    }

    // ============================================================ 问题
    case 'questions': {
        $sid = (int)($_GET['sid'] ?? 0);
        if (!$sid) {
            $cur = Survey::current();
            $sid = $cur ? (int)$cur['id'] : (int)Db::val('SELECT id FROM surveys ORDER BY id DESC LIMIT 1');
        }
        $survey = $sid ? Survey::find($sid) : null;
        if (!$survey) {
            render_admin('questions', [
                'survey' => null, 'questions' => [], 'tags' => Survey::tags(),
                'surveys' => Survey::allSurveys(),
                'pageTitle' => '问题管理 · 管理后台', 'navActive' => 'questions',
            ]);
            break;
        }
        render_admin('questions', [
            'survey'    => $survey,
            'questions' => Survey::questions($sid, true),
            'tags'      => Survey::tags(),
            'surveys'   => Survey::allSurveys(),
            'pageTitle' => '问题管理 · 管理后台',
            'navActive' => 'questions',
        ]);
        break;
    }

    case 'q_add': {
        if (!$isPost) redirect(admin_url('questions'));
        $sid   = (int)($_POST['survey_id'] ?? 0);
        $title = Survey::cleanText((string)($_POST['title'] ?? ''));
        if ($sid && $title !== '') {
            Db::run('INSERT INTO questions(survey_id, title, detail, source, status, created_at, voter_key, ip)
                     VALUES(?,?,?,?,?,?,?,?)',
                [$sid, $title, Survey::cleanText((string)($_POST['detail'] ?? '')), 'admin', 'approved', now(), 'admin', client_ip()]);
            $qid = Db::lastId();
            Survey::setQuestionTags($qid, post_ints('tags'));
            audit('question_add', (string)$qid, $title);
            flash('问题已添加');
        } else {
            flash('问题内容不能为空');
        }
        redirect(admin_url('questions', ['sid' => $sid]));
        break;
    }

    case 'q_update': {
        if (!$isPost) redirect(admin_url('questions'));
        $qid = (int)($_POST['id'] ?? 0);
        $q = Db::one('SELECT * FROM questions WHERE id = ?', [$qid]);
        if (!$q) { flash('问题不存在'); redirect(admin_url('questions')); }

        $title = Survey::cleanText((string)($_POST['title'] ?? ''));
        if ($title !== '') {
            Db::run('UPDATE questions SET title = ?, detail = ?, updated_at = ? WHERE id = ?',
                [$title, Survey::cleanText((string)($_POST['detail'] ?? '')), now(), $qid]);
        }
        Survey::setQuestionTags($qid, post_ints('tags'));
        audit('question_update', (string)$qid, $title);
        flash('已保存');
        redirect(admin_url('questions', ['sid' => (int)$q['survey_id']]));
        break;
    }

    case 'q_action': {
        if (!$isPost) redirect(admin_url('questions'));
        $qid = (int)($_POST['id'] ?? 0);
        $op  = (string)($_POST['op'] ?? '');
        $q = Db::one('SELECT * FROM questions WHERE id = ?', [$qid]);
        if (!$q) { flash('问题不存在'); redirect(admin_url('questions')); }
        $sid = (int)$q['survey_id'];

        switch ($op) {
            case 'hide':
                Db::run("UPDATE questions SET status = 'hidden', updated_at = ? WHERE id = ?", [now(), $qid]);
                flash('已隐藏（不会出现在前台，数据保留）');
                break;
            case 'show':
                Db::run("UPDATE questions SET status = 'approved', updated_at = ? WHERE id = ?", [now(), $qid]);
                flash('已恢复显示');
                break;
            case 'approve':
                Db::run("UPDATE questions SET status = 'approved', updated_at = ? WHERE id = ?", [now(), $qid]);
                flash('已通过审核');
                break;
            case 'pin':
                Db::run('UPDATE questions SET pinned = CASE pinned WHEN 1 THEN 0 ELSE 1 END, updated_at = ? WHERE id = ?', [now(), $qid]);
                flash('置顶状态已切换');
                break;
            case 'purge':
                Db::run('DELETE FROM question_tags WHERE question_id = ?', [$qid]);
                Db::run('DELETE FROM votes WHERE question_id = ?', [$qid]);
                Db::run('UPDATE submissions SET question_id = NULL WHERE question_id = ?', [$qid]);
                Db::run('DELETE FROM questions WHERE id = ?', [$qid]);
                flash('问题已彻底删除');
                break;
            case 'merge': {
                $into = (int)($_POST['into'] ?? 0);
                $t = Db::one("SELECT * FROM questions WHERE id = ? AND survey_id = ? AND status = 'approved' AND merged_into IS NULL", [$into, $sid]);
                if (!$t || $into === $qid) {
                    flash('合并目标无效');
                    break;
                }
                // 票转移，同一人同一天已投目标题则丢弃重复票
                Db::run('UPDATE OR IGNORE votes SET question_id = ? WHERE question_id = ?', [$into, $qid]);
                Db::run('DELETE FROM votes WHERE question_id = ?', [$qid]);
                Db::run("UPDATE questions SET merged_into = ?, status = 'hidden', updated_at = ? WHERE id = ?", [$into, now(), $qid]);
                Db::run('UPDATE questions SET updated_at = ? WHERE id = ?', [now(), $into]);
                Survey::recount($into);
                Survey::recount($qid);
                flash('已合并到「' . str_limit((string)$t['title'], 20) . '」');
                break;
            }
        }
        audit('question_' . $op, (string)$qid, (string)$q['title']);
        redirect(admin_url('questions', ['sid' => $sid]));
        break;
    }

    // ============================================================ 标签
    case 'tags': {
        render_admin('tags', [
            'tags'      => Survey::tags(),
            'usage'     => Db::all('SELECT tag_id, COUNT(*) AS n FROM question_tags GROUP BY tag_id'),
            'pageTitle' => '标签管理 · 管理后台',
            'navActive' => 'tags',
        ]);
        break;
    }

    case 'tag_save': {
        if (!$isPost) redirect(admin_url('tags'));
        $id    = (int)($_POST['id'] ?? 0);
        $name  = Survey::cleanText((string)($_POST['name'] ?? ''));
        $color = in_array($_POST['color'] ?? '', ['blue', 'green', 'orange', 'purple', 'pink', 'gray'], true)
                 ? (string)$_POST['color'] : 'blue';
        if ($name === '') {
            flash('标签名不能为空');
            redirect(admin_url('tags'));
        }
        try {
            if ($id) {
                Db::run('UPDATE tags SET name = ?, color = ? WHERE id = ?', [$name, $color, $id]);
                flash('标签已更新');
            } else {
                Db::run('INSERT INTO tags(name, color, sort) VALUES(?,?,(SELECT COALESCE(MAX(sort),0)+1 FROM tags))', [$name, $color]);
                flash('标签已添加');
            }
        } catch (PDOException $e) {
            flash('标签名重复了');
        }
        redirect(admin_url('tags'));
        break;
    }

    case 'tag_delete': {
        if (!$isPost) redirect(admin_url('tags'));
        $id = (int)($_POST['id'] ?? 0);
        Db::run('DELETE FROM question_tags WHERE tag_id = ?', [$id]);
        Db::run('DELETE FROM tags WHERE id = ?', [$id]);
        audit('tag_delete', (string)$id);
        flash('标签已删除');
        redirect(admin_url('tags'));
        break;
    }

    // ============================================================ 数据
    case 'data': {
        $sid = (int)($_GET['sid'] ?? 0);
        if (!$sid) {
            $cur = Survey::current();
            $sid = $cur ? (int)$cur['id'] : (int)Db::val('SELECT id FROM surveys ORDER BY id DESC LIMIT 1');
        }
        $survey = $sid ? Survey::find($sid) : null;
        render_admin('data', [
            'survey'    => $survey,
            'surveys'   => Survey::allSurveys(),
            'stats'     => $survey ? Survey::stats($sid) : null,
            'trend'     => $survey ? Survey::dailyTrend($sid, 30) : [],
            'questions' => $survey ? Survey::questions($sid, true) : [],
            'ips'       => $survey ? Db::all('SELECT ip, COUNT(*) AS n, COUNT(DISTINCT voter_key) AS people
                                                FROM votes WHERE survey_id = ? GROUP BY ip
                                               ORDER BY n DESC LIMIT 10', [$sid]) : [],
            'pageTitle' => '数据 · 管理后台',
            'navActive' => 'data',
        ]);
        break;
    }

    case 'export': {
        $sid = (int)($_GET['sid'] ?? 0);
        $survey = Survey::find($sid);
        if (!$survey) { http_response_code(404); exit('期次不存在'); }

        $rows = Db::all(
            "SELECT q.id, q.title, q.detail, q.source, q.status, q.pinned, q.votes_cache, q.created_at,
                    (SELECT GROUP_CONCAT(t.name, ' / ') FROM question_tags qt JOIN tags t ON t.id = qt.tag_id
                      WHERE qt.question_id = q.id) AS tags
               FROM questions q WHERE q.survey_id = ?
              ORDER BY q.votes_cache DESC, q.id ASC", [$sid]);

        $name = 'tokpoll-' . $sid . '-' . date('Ymd-Hi') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM，Excel 才不会乱码
        fputcsv($out, ['ID', '问题', '补充说明', '来源', '状态', '置顶', '票数', '标签', '创建时间'], ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, array_map('csv_cell', [
                $r['id'], $r['title'], $r['detail'],
                $r['source'] === 'user' ? '用户补充' : '管理员添加',
                ['approved' => '显示中', 'pending' => '待审核', 'hidden' => '已隐藏'][$r['status']] ?? $r['status'],
                $r['pinned'] ? '是' : '', $r['votes_cache'], $r['tags'], $r['created_at'],
            ]), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    // ============================================================ 设置
    case 'settings': {
        render_admin('settings', [
            'logs'      => Db::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 40'),
            'dbPath'    => (string)cfg('db_path'),
            'dbSize'    => is_file((string)cfg('db_path')) ? filesize((string)cfg('db_path')) : 0,
            'pageTitle' => '设置 · 管理后台',
            'navActive' => 'settings',
        ]);
        break;
    }

    default:
        redirect(admin_url('dash'));
}

/** 把 datetime-local 的值规整成 Y-m-d H:i:s，空值返回 null */
function normalize_dt(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    $ts = strtotime(str_replace('T', ' ', $v));
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}
