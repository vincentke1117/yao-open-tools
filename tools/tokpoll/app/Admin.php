<?php
declare(strict_types=1);

/**
 * 后台鉴权：单一管理密码 + Session；连续失败 5 次锁定 10 分钟。
 */
final class Admin
{
    private const MAX_FAILS   = 5;
    private const LOCK_SECOND = 600;

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['admin_ok']) && $_SESSION['admin_ok'] === self::sessionFingerprint();
    }

    private static function sessionFingerprint(): string
    {
        return substr(hash('sha256', (string)cfg('admin_password_hash') . '|' . Voter::uaHash()), 0, 32);
    }

    /** 入口口令校验（config.admin_path_token 为空时恒真） */
    public static function pathTokenOk(): bool
    {
        $token = (string)cfg('admin_path_token', '');
        if ($token === '') {
            return true;
        }
        if (!empty($_SESSION['admin_path_ok'])) {
            return true;
        }
        if (hash_equals($token, (string)($_GET['k'] ?? ''))) {
            $_SESSION['admin_path_ok'] = true;
            return true;
        }
        return false;
    }

    /** @return array{ok:bool, message:string} */
    public static function login(string $password): array
    {
        $ip = client_ip();
        $row = Db::one('SELECT fails, locked_until FROM login_attempts WHERE ip = ?', [$ip]);
        if ($row && (int)$row['locked_until'] > time()) {
            $min = (int)ceil(((int)$row['locked_until'] - time()) / 60);
            return ['ok' => false, 'message' => "尝试次数过多，请 {$min} 分钟后再试"];
        }

        $hash = (string)cfg('admin_password_hash', '');
        if ($hash !== '' && password_verify($password, $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = self::sessionFingerprint();
            Db::run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
            audit('login', $ip);
            return ['ok' => true, 'message' => ''];
        }

        $fails = ($row ? (int)$row['fails'] : 0) + 1;
        $lock  = $fails >= self::MAX_FAILS ? time() + self::LOCK_SECOND : 0;
        Db::run('INSERT INTO login_attempts(ip, fails, locked_until) VALUES(?,?,?)
                 ON CONFLICT(ip) DO UPDATE SET fails = excluded.fails, locked_until = excluded.locked_until',
            [$ip, $fails >= self::MAX_FAILS ? 0 : $fails, $lock]);

        $left = self::MAX_FAILS - $fails;
        return ['ok' => false, 'message' => $left > 0 ? "密码不对，还可以试 {$left} 次" : '尝试次数过多，已锁定 10 分钟'];
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_ok']);
        session_regenerate_id(true);
    }

    /** 未登录则跳转登录页 */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            redirect(admin_url('login'));
        }
    }
}

/** 后台链接 */
function admin_url(string $action = '', array $params = []): string
{
    $token = (string)cfg('admin_path_token', '');
    if ($action !== '') {
        $params = ['a' => $action] + $params;
    }
    if ($token !== '') {
        $params['k'] = $token;
    }
    $u = base_path() . '/admin.php';
    return $params ? $u . '?' . http_build_query($params) : $u;
}
