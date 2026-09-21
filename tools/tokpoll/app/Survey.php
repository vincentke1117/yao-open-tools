<?php
declare(strict_types=1);

/**
 * 期次 / 问题 / 投票 / 标签的业务逻辑。
 */
final class Survey
{
    // ---------------------------------------------------------------- 期次

    /** 当前对外展示的一期（取最新的 active） */
    public static function current(): ?array
    {
        return Db::one("SELECT * FROM surveys WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM surveys WHERE id = ?', [$id]);
    }

    /** 往期列表（已截止 / 已归档），带统计 */
    public static function archiveList(): array
    {
        return Db::all(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM questions q
                      WHERE q.survey_id = s.id AND q.status = 'approved') AS question_count,
                    (SELECT COUNT(*) FROM votes v WHERE v.survey_id = s.id)  AS vote_count,
                    (SELECT COUNT(DISTINCT v.voter_key) FROM votes v
                      WHERE v.survey_id = s.id)                              AS voter_count
               FROM surveys s
              WHERE s.status IN ('closed','archived')
              ORDER BY COALESCE(s.ends_at, s.created_at) DESC, s.id DESC"
        );
    }

    /** 后台用：全部期次 */
    public static function allSurveys(): array
    {
        return Db::all(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM questions q WHERE q.survey_id = s.id AND q.status != 'hidden') AS question_count,
                    (SELECT COUNT(*) FROM votes v WHERE v.survey_id = s.id) AS vote_count
               FROM surveys s ORDER BY s.id DESC"
        );
    }

    /** 本期是否还能投票 / 提问 */
    public static function isOpen(?array $survey): bool
    {
        return self::phase($survey) === 'open';
    }

    /** 当前期次阶段：pending | open | closed */
    public static function phase(?array $survey): string
    {
        if (!$survey || $survey['status'] !== 'active') {
            return 'closed';
        }
        if (!empty($survey['starts_at']) && strtotime((string)$survey['starts_at']) > time()) {
            return 'pending';
        }
        if (!empty($survey['ends_at']) && strtotime((string)$survey['ends_at']) <= time()) {
            return 'closed';
        }
        return 'open';
    }

    /** 关闭所有过了截止时间的期次 */
    public static function autoClose(): void
    {
        Db::run("UPDATE surveys
                    SET status = 'closed', updated_at = ?
                  WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at != '' AND ends_at <= ?",
            [now(), now()]);
    }

    // -------------------------------------------------------------- 问题

    /**
     * 取某期的问题列表。
     * @param bool $adminView true 时包含 pending / hidden
     */
    public static function questions(int $surveyId, bool $adminView = false): array
    {
        $where = $adminView ? '1=1' : "q.status = 'approved'";
        $rows = Db::all(
            "SELECT q.*,
                    (SELECT GROUP_CONCAT(t.id || ':' || t.name || ':' || t.color, '|')
                       FROM question_tags qt JOIN tags t ON t.id = qt.tag_id
                      WHERE qt.question_id = q.id) AS tag_blob
               FROM questions q
              WHERE q.survey_id = ? AND q.merged_into IS NULL AND {$where}
              ORDER BY q.pinned DESC, q.votes_cache DESC, q.id ASC",
            [$surveyId]
        );

        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['votes_cache'] = (int)$r['votes_cache'];
            $r['pinned']      = (int)$r['pinned'];
            $r['tags']        = self::parseTagBlob($r['tag_blob'] ?? null);
            unset($r['tag_blob'], $r['voter_key'], $r['ip']);
        }
        return $rows;
    }

    private static function parseTagBlob(?string $blob): array
    {
        if (!$blob) {
            return [];
        }
        $out = [];
        foreach (explode('|', $blob) as $chunk) {
            $parts = explode(':', $chunk, 3);
            if (count($parts) === 3) {
                $out[] = ['id' => (int)$parts[0], 'name' => $parts[1], 'color' => $parts[2]];
            }
        }
        return $out;
    }

    // -------------------------------------------------------------- 投票

    /**
     * 投票。votes_per_day = 1 时自动改票（撤销旧票、投新票）。
     * @return array{ok:bool, message:string, switched:bool}
     */
    public static function vote(array $survey, int $questionId): array
    {
        $q = Db::one("SELECT * FROM questions WHERE id = ? AND survey_id = ? AND status = 'approved' AND merged_into IS NULL",
            [$questionId, $survey['id']]);
        if (!$q) {
            return ['ok' => false, 'message' => '这个问题已经不在列表里了', 'switched' => false];
        }

        $key   = Voter::key();
        $date  = today();
        $quota = max(1, (int)$survey['votes_per_day']);
        $pdo   = Db::pdo();

        // SQLite 的立即写事务把“检查配额 + 改票 + 插入”串行化，避免并发请求超额投票。
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // 已经投过这一题 → 幂等返回
            $exists = Db::val('SELECT COUNT(*) FROM votes WHERE survey_id = ? AND voter_key = ? AND vote_date = ? AND question_id = ?',
                [$survey['id'], $key, $date, $questionId]);
            if ((int)$exists > 0) {
                $pdo->commit();
                return ['ok' => true, 'message' => '你已经投过这一票了', 'switched' => false];
            }

            $used = (int)Db::val('SELECT COUNT(*) FROM votes WHERE survey_id = ? AND voter_key = ? AND vote_date = ?',
                [$survey['id'], $key, $date]);

            $switched = false;
            if ($used >= $quota) {
                if ($quota === 1) {
                    // 单票模式：改票
                    $old = Db::one('SELECT question_id FROM votes WHERE survey_id = ? AND voter_key = ? AND vote_date = ?',
                        [$survey['id'], $key, $date]);
                    Db::run('DELETE FROM votes WHERE survey_id = ? AND voter_key = ? AND vote_date = ?',
                        [$survey['id'], $key, $date]);
                    if ($old) {
                        self::recount((int)$old['question_id']);
                    }
                    $switched = true;
                } else {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => "今天最多投 {$quota} 票，已经投完了", 'switched' => false];
                }
            }

            // 严格模式：同 IP 也算一次
            if (cfg('strict_ip')) {
                $ipUsed = (int)Db::val('SELECT COUNT(*) FROM votes WHERE survey_id = ? AND ip = ? AND vote_date = ? AND voter_key != ?',
                    [$survey['id'], Voter::ip(), $date, $key]);
                if ($ipUsed >= $quota) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => '这个网络今天已经投过票了', 'switched' => false];
                }
            }

            Db::run('INSERT INTO votes(survey_id, question_id, voter_key, ip, ua_hash, vote_date, created_at)
                     VALUES(?,?,?,?,?,?,?)',
                [$survey['id'], $questionId, $key, Voter::ip(), Voter::uaHash(), $date, now()]);
            self::recount($questionId);

            $pdo->commit();
            return ['ok' => true, 'message' => $switched ? '已改投这一题' : '投票成功', 'switched' => $switched];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 撤回今天投给某题的票 */
    public static function unvote(array $survey, int $questionId): array
    {
        $n = Db::run('DELETE FROM votes WHERE survey_id = ? AND voter_key = ? AND vote_date = ? AND question_id = ?',
            [$survey['id'], Voter::key(), today(), $questionId]);
        if ($n > 0) {
            self::recount($questionId);
            return ['ok' => true, 'message' => '已撤回'];
        }
        return ['ok' => false, 'message' => '你今天没有投过这一题'];
    }

    /** 重算某题票数缓存 */
    public static function recount(int $questionId): void
    {
        Db::run('UPDATE questions SET votes_cache = (SELECT COUNT(*) FROM votes WHERE question_id = ?) WHERE id = ?',
            [$questionId, $questionId]);
    }

    public static function recountAll(int $surveyId): void
    {
        Db::run('UPDATE questions
                    SET votes_cache = (SELECT COUNT(*) FROM votes v WHERE v.question_id = questions.id)
                  WHERE survey_id = ?', [$surveyId]);
    }

    // -------------------------------------------------------------- 提问

    /**
     * 访客提交新问题。默认「后发先显」：直接 approved 进入列表。
     * @return array{ok:bool, message:string, question_id:int}
     */
    public static function submitQuestion(array $survey, string $title, string $detail = ''): array
    {
        $title  = self::cleanText($title);
        $detail = self::cleanText($detail);

        $min = (int)cfg('question_min_len', 4);
        $max = (int)cfg('question_max_len', 80);
        $len = mb_strlen($title, 'UTF-8');

        if ($len < $min) {
            return ['ok' => false, 'message' => "问题太短了，至少 {$min} 个字", 'question_id' => 0];
        }
        if ($len > $max) {
            return ['ok' => false, 'message' => "问题太长了，最多 {$max} 个字", 'question_id' => 0];
        }
        if (mb_strlen($detail, 'UTF-8') > (int)cfg('detail_max_len', 200)) {
            return ['ok' => false, 'message' => '补充说明太长了', 'question_id' => 0];
        }
        // 纯链接 / 含多个链接的内容直接挡掉
        if (preg_match('~^\s*(https?://|www\.)~i', $title) === 1
            || preg_match_all('~https?://~i', $title . ' ' . $detail) >= 2) {
            return ['ok' => false, 'message' => '问题里不要只放链接', 'question_id' => 0];
        }
        // 同期重复问题
        $dup = Db::val("SELECT id FROM questions WHERE survey_id = ? AND status != 'hidden' AND LOWER(TRIM(title)) = LOWER(TRIM(?))",
            [$survey['id'], $title]);
        if ($dup) {
            return ['ok' => false, 'message' => '已经有人提过一模一样的问题了，直接投它一票吧', 'question_id' => (int)$dup];
        }

        // 每日提问配额（默认每人每天 1 个）
        $sub = Voter::submitStatus($survey);
        if (!$sub['allowed']) {
            return ['ok' => false, 'message' => $sub['reason'] ?: '今天已经提过问题了', 'question_id' => 0];
        }

        $status = (int)$survey['need_review'] ? 'pending' : 'approved';
        $pdo = Db::pdo();
        // SQLite 的立即写事务把“检查配额 + 插入”串行化，避免并发重复提问。
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            Db::run('INSERT INTO questions(survey_id, title, detail, source, status, created_at, voter_key, ip)
                     VALUES(?,?,?,?,?,?,?,?)',
                [$survey['id'], $title, $detail, 'user', $status, now(), Voter::key(), Voter::ip()]);
            $qid = Db::lastId();

            Db::run('INSERT INTO submissions(survey_id, voter_key, ip, submit_date, question_id, created_at)
                     VALUES(?,?,?,?,?,?)',
                [$survey['id'], Voter::key(), Voter::ip(), today(), $qid, now()]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 唯一索引冲突 = 今天已经提过
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['ok' => false, 'message' => '今天已经提过一个问题了，明天再来', 'question_id' => 0];
            }
            throw $e;
        }

        $msg = $status === 'approved' ? '问题已加入列表' : '问题已提交，通过审核后会显示';
        return ['ok' => true, 'message' => $msg, 'question_id' => $qid];
    }

    /** 去掉控制字符、压缩空白 */
    public static function cleanText(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
        return trim($s);
    }

    // -------------------------------------------------------------- 统计

    public static function stats(int $surveyId): array
    {
        return [
            'questions'   => (int)Db::val("SELECT COUNT(*) FROM questions WHERE survey_id = ? AND status = 'approved' AND merged_into IS NULL", [$surveyId]),
            'user_questions' => (int)Db::val("SELECT COUNT(*) FROM questions WHERE survey_id = ? AND status = 'approved' AND source = 'user'", [$surveyId]),
            'votes'       => (int)Db::val('SELECT COUNT(*) FROM votes WHERE survey_id = ?', [$surveyId]),
            'voters'      => (int)Db::val('SELECT COUNT(DISTINCT voter_key) FROM votes WHERE survey_id = ?', [$surveyId]),
        ];
    }

    /** 每日投票趋势 */
    public static function dailyTrend(int $surveyId, int $days = 14): array
    {
        return Db::all(
            'SELECT vote_date AS d, COUNT(*) AS n, COUNT(DISTINCT voter_key) AS people
               FROM votes WHERE survey_id = ?
              GROUP BY vote_date ORDER BY vote_date DESC LIMIT ?',
            [$surveyId, $days]
        );
    }

    /** 列表数据的版本号，用于前端判断是否需要重绘 */
    public static function listVersion(int $surveyId): string
    {
        $survey = self::find($surveyId);
        $questions = Db::all(
            "SELECT id, title, detail, status, pinned, votes_cache, merged_into, created_at, updated_at
               FROM questions
              WHERE survey_id = ? AND status = 'approved' AND merged_into IS NULL
              ORDER BY id ASC",
            [$surveyId]
        );
        $tags = Db::all(
            "SELECT qt.question_id, t.id, t.name, t.color
               FROM question_tags qt
               JOIN questions q ON q.id = qt.question_id
               JOIN tags t ON t.id = qt.tag_id
              WHERE q.survey_id = ? AND q.status = 'approved' AND q.merged_into IS NULL
              ORDER BY qt.question_id ASC, t.id ASC",
            [$surveyId]
        );
        return hash('sha256', (string)json_encode([
            'survey' => $survey,
            'questions' => $questions,
            'tags' => $tags,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // -------------------------------------------------------------- 标签

    public static function tags(): array
    {
        return Db::all('SELECT * FROM tags ORDER BY sort ASC, id ASC');
    }

    public static function setQuestionTags(int $questionId, array $tagIds): void
    {
        Db::run('DELETE FROM question_tags WHERE question_id = ?', [$questionId]);
        $st = Db::pdo()->prepare('INSERT OR IGNORE INTO question_tags(question_id, tag_id) VALUES(?,?)');
        foreach ($tagIds as $tid) {
            $st->execute([$questionId, (int)$tid]);
        }
        Db::run('UPDATE questions SET updated_at = ? WHERE id = ?', [now(), $questionId]);
    }
}
