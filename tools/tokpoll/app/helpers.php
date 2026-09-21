<?php
declare(strict_types=1);

/** HTML 转义输出（防 XSS） */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 当前时间字符串 */
function now(): string
{
    return date('Y-m-d H:i:s');
}

/** 今天日期（按配置时区），用于「每天一次」的判定 */
function today(): string
{
    return date('Y-m-d');
}

/** 获取访客真实 IP */
function client_ip(): string
{
    if (cfg('trust_proxy')) {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** CSRF token（同一 Session 内固定） */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** 校验 CSRF token */
function csrf_check(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $token);
}

/** 输出 JSON 并结束请求 */
function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $data = []): void
{
    json_out(['ok' => true] + ($data ? ['data' => $data] : []));
}

function json_err(string $message, int $status = 400, string $code = ''): void
{
    json_out(['ok' => false, 'error' => $message, 'code' => $code], $status);
}

/** 防止 CSV 在电子表格软件中被当作公式执行 */
function csv_cell($value)
{
    if (!is_string($value)) {
        return $value;
    }
    return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
}

/** 生成站内链接，自动适配伪静态开关 */
function url(string $page, array $params = []): string
{
    $base = base_path();
    if (cfg('pretty_url')) {
        if ($page === 'home') {
            $path = $base . '/';
        } elseif ($page === 'history') {
            $path = $base . '/history';
        } elseif ($page === 'survey' && isset($params['id'])) {
            $path = $base . '/s/' . (int)$params['id'];
            unset($params['id']);
        } else {
            $path = $base . '/';
            $params['p'] = $page;
        }
        return $params ? $path . '?' . http_build_query($params) : $path;
    }
    $params = ['p' => $page] + $params;
    return $base . '/index.php?' . http_build_query($params);
}

/** 站点所在的子目录前缀，例如部署在 /poll 下时返回 '/poll' */
function base_path(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $dir === '.' ? '' : $dir;
}

function asset(string $file): string
{
    $path = PUBLIC_DIR . '/assets/' . $file;
    $v = is_file($path) ? substr((string)filemtime($path), -6) : '1';
    return base_path() . '/assets/' . $file . '?v=' . $v;
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 302);
    exit;
}

/** 按字符数截断 */
function str_limit(string $s, int $len): string
{
    return mb_strlen($s, 'UTF-8') > $len ? mb_substr($s, 0, $len, 'UTF-8') . '…' : $s;
}

/**
 * 简易限流：同一 key 在 60 秒窗口内最多 $limit 次。
 * 超限返回 false。
 */
function rate_limit(string $key, int $limit): bool
{
    if ($limit <= 0) {
        return true;
    }
    $pdo = Db::pdo();
    $winStart = time() - (time() % 60);
    $pdo->prepare('DELETE FROM rate_limit WHERE window_start < ?')->execute([$winStart - 120]);

    $st = $pdo->prepare('SELECT hits, window_start FROM rate_limit WHERE k = ?');
    $st->execute([$key]);
    $row = $st->fetch();

    if (!$row || (int)$row['window_start'] !== $winStart) {
        $pdo->prepare('INSERT INTO rate_limit(k, hits, window_start) VALUES(?,1,?)
                       ON CONFLICT(k) DO UPDATE SET hits = 1, window_start = excluded.window_start')
            ->execute([$key, $winStart]);
        return true;
    }
    if ((int)$row['hits'] >= $limit) {
        return false;
    }
    $pdo->prepare('UPDATE rate_limit SET hits = hits + 1 WHERE k = ?')->execute([$key]);
    return true;
}

/** 友好的剩余时间描述 */
function human_left(?string $endsAt): string
{
    if (!$endsAt) {
        return '长期开放';
    }
    $diff = strtotime($endsAt) - time();
    if ($diff <= 0) {
        return '已截止';
    }
    $d = intdiv($diff, 86400);
    $h = intdiv($diff % 86400, 3600);
    $m = intdiv($diff % 3600, 60);
    if ($d > 0) return "剩 {$d} 天 {$h} 小时";
    if ($h > 0) return "剩 {$h} 小时 {$m} 分";
    return "剩 {$m} 分钟";
}

/** 渲染视图（套 layout） */
function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_DIR . '/views/' . $view . '.php';
    $content = ob_get_clean();
    $pageTitle = $vars['pageTitle'] ?? cfg('site_name');
    $navActive = $vars['navActive'] ?? '';
    require APP_DIR . '/views/layout.php';
}

/** 渲染后台视图（套后台 layout） */
function render_admin(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_DIR . '/views/admin/' . $view . '.php';
    $content = ob_get_clean();
    $pageTitle = $vars['pageTitle'] ?? '管理后台';
    $navActive = $vars['navActive'] ?? '';
    require APP_DIR . '/views/admin/layout.php';
}

/** 记录后台操作日志 */
function audit(string $action, string $target = '', string $detail = ''): void
{
    Db::pdo()->prepare('INSERT INTO audit_log(action, target, detail, ip, created_at) VALUES(?,?,?,?,?)')
        ->execute([$action, $target, $detail, client_ip(), now()]);
}
