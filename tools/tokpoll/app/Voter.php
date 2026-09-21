<?php
declare(strict_types=1);

/**
 * 访客身份识别（无登录场景）。
 *
 * 三层判据：
 *   1. tp_uid Cookie —— 主判据，硬限制。服务端生成 32 位随机串，有效期一年。
 *   2. IP + UA 指纹 —— 兜底，默认软限制（仅提示），config['strict_ip'] = true 时升为硬限制。
 *   3. 同 IP 每分钟写操作次数上限 —— 防脚本刷。
 *
 * 说明：同一场地内的用户常连同一个 WiFi，出口 IP 相同。若把 IP 设为硬限制，
 * 第二个人就会被拦下，所以默认关闭，由后台/配置自行开启。
 */
final class Voter
{
    public const COOKIE = 'tp_uid';

    private static ?string $key = null;

    /** 当前访客的唯一标识（读不到就发一个新的） */
    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $raw = (string)($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $raw) === 1) {
            self::$key = $raw;
            return $raw;
        }

        $new = bin2hex(random_bytes(16));
        // 通过 CLI 或已输出内容时 setcookie 会失败，忽略即可
        @setcookie(self::COOKIE, $new, [
            'expires'  => time() + 86400 * 365,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        $_COOKIE[self::COOKIE] = $new;
        self::$key = $new;
        return $new;
    }

    public static function ip(): string
    {
        return client_ip();
    }

    public static function uaHash(): string
    {
        return substr(hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
    }

    /** 设备指纹：IP + UA，用于 strict_ip 模式下的兜底识别 */
    public static function fingerprint(): string
    {
        return substr(hash('sha256', self::ip() . '|' . self::uaHash()), 0, 24);
    }

    /**
     * 今天还能不能投票。
     * @return array{allowed:bool, used:int, quota:int, reason:string}
     */
    public static function voteStatus(array $survey): array
    {
        $quota = max(1, (int)$survey['votes_per_day']);
        $used  = (int)Db::val(
            'SELECT COUNT(*) FROM votes WHERE survey_id = ? AND voter_key = ? AND vote_date = ?',
            [$survey['id'], self::key(), today()]
        );

        if ($used < $quota) {
            // 严格模式下再查一次同 IP 是否已投满
            if (cfg('strict_ip')) {
                $ipUsed = (int)Db::val(
                    'SELECT COUNT(*) FROM votes WHERE survey_id = ? AND ip = ? AND vote_date = ?',
                    [$survey['id'], self::ip(), today()]
                );
                if ($ipUsed >= $quota) {
                    return ['allowed' => false, 'used' => $used, 'quota' => $quota,
                            'reason' => '这个网络今天已经投过票了'];
                }
            }
            return ['allowed' => true, 'used' => $used, 'quota' => $quota, 'reason' => ''];
        }

        return ['allowed' => false, 'used' => $used, 'quota' => $quota, 'reason' => '今天的票已经投完了'];
    }

    /**
     * 今天还能不能提问。
     * @return array{allowed:bool, used:int, quota:int, reason:string}
     */
    public static function submitStatus(array $survey): array
    {
        $quota = max(1, (int)$survey['submits_per_day']);
        $used  = (int)Db::val(
            'SELECT COUNT(*) FROM submissions WHERE survey_id = ? AND voter_key = ? AND submit_date = ?',
            [$survey['id'], self::key(), today()]
        );

        if (!(int)$survey['allow_submit']) {
            return ['allowed' => false, 'used' => $used, 'quota' => $quota, 'reason' => '本期不开放补充问题'];
        }
        if ($used >= $quota) {
            return ['allowed' => false, 'used' => $used, 'quota' => $quota, 'reason' => '今天已经提过问题了'];
        }
        if (cfg('strict_ip')) {
            $ipUsed = (int)Db::val(
                'SELECT COUNT(*) FROM submissions WHERE survey_id = ? AND ip = ? AND submit_date = ?',
                [$survey['id'], self::ip(), today()]
            );
            if ($ipUsed >= $quota) {
                return ['allowed' => false, 'used' => $used, 'quota' => $quota,
                        'reason' => '这个网络今天已经提过问题了'];
            }
        }
        return ['allowed' => true, 'used' => $used, 'quota' => $quota, 'reason' => ''];
    }

    /** 我今天投给了哪些问题 */
    public static function myVotes(int $surveyId): array
    {
        $rows = Db::all(
            'SELECT question_id FROM votes WHERE survey_id = ? AND voter_key = ? AND vote_date = ?',
            [$surveyId, self::key(), today()]
        );
        return array_map(static fn($r) => (int)$r['question_id'], $rows);
    }

    /** 写操作限流，超限返回 false */
    public static function passRateLimit(string $action): bool
    {
        return rate_limit($action . ':' . self::ip(), (int)cfg('rate_limit_per_min', 30));
    }
}
