<?php
declare(strict_types=1);

/**
 * 数据库迁移。键为版本号，值为该版本要执行的 SQL 语句数组。
 * 新增字段/表时，追加一个更大的版本号即可，已部署的库会自动升级。
 */
return [

    1 => [
        // ---- 调研期次 ----
        "CREATE TABLE IF NOT EXISTS surveys (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            title           TEXT    NOT NULL,
            subtitle        TEXT    NOT NULL DEFAULT '',
            description     TEXT    NOT NULL DEFAULT '',
            status          TEXT    NOT NULL DEFAULT 'draft',   -- draft | active | closed | archived
            starts_at       TEXT,
            ends_at         TEXT,
            allow_submit    INTEGER NOT NULL DEFAULT 1,         -- 是否允许访客补充问题
            need_review     INTEGER NOT NULL DEFAULT 0,         -- 1 = 先审后显
            votes_per_day   INTEGER NOT NULL DEFAULT 1,
            submits_per_day INTEGER NOT NULL DEFAULT 1,
            created_at      TEXT    NOT NULL DEFAULT '',
            updated_at      TEXT    NOT NULL DEFAULT ''
        )",
        "CREATE INDEX IF NOT EXISTS idx_surveys_status ON surveys(status, id DESC)",

        // ---- 候选问题 ----
        "CREATE TABLE IF NOT EXISTS questions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            survey_id   INTEGER NOT NULL,
            title       TEXT    NOT NULL,
            detail      TEXT    NOT NULL DEFAULT '',
            source      TEXT    NOT NULL DEFAULT 'admin',       -- admin | user
            status      TEXT    NOT NULL DEFAULT 'approved',    -- approved | pending | hidden
            pinned      INTEGER NOT NULL DEFAULT 0,
            votes_cache INTEGER NOT NULL DEFAULT 0,
            merged_into INTEGER,                                -- 合并到哪个问题
            voter_key   TEXT    NOT NULL DEFAULT '',
            ip          TEXT    NOT NULL DEFAULT '',
            created_at  TEXT    NOT NULL DEFAULT ''
        )",
        "CREATE INDEX IF NOT EXISTS idx_questions_survey ON questions(survey_id, status, pinned DESC, votes_cache DESC)",

        // ---- 标签 ----
        "CREATE TABLE IF NOT EXISTS tags (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            name  TEXT NOT NULL UNIQUE,
            color TEXT NOT NULL DEFAULT 'blue',
            sort  INTEGER NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS question_tags (
            question_id INTEGER NOT NULL,
            tag_id      INTEGER NOT NULL,
            PRIMARY KEY (question_id, tag_id)
        )",

        // ---- 投票 ----
        // 唯一索引保证「同一期 + 同一人 + 同一天 + 同一题」只能有一条
        // 每天能投几题由 surveys.votes_per_day 在代码层控制
        "CREATE TABLE IF NOT EXISTS votes (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            survey_id   INTEGER NOT NULL,
            question_id INTEGER NOT NULL,
            voter_key   TEXT    NOT NULL,
            ip          TEXT    NOT NULL DEFAULT '',
            ua_hash     TEXT    NOT NULL DEFAULT '',
            vote_date   TEXT    NOT NULL,
            created_at  TEXT    NOT NULL DEFAULT ''
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS uq_vote_daily ON votes(survey_id, voter_key, vote_date, question_id)",
        "CREATE INDEX IF NOT EXISTS idx_votes_question ON votes(question_id)",
        "CREATE INDEX IF NOT EXISTS idx_votes_ip ON votes(survey_id, ip, vote_date)",
        "CREATE INDEX IF NOT EXISTS idx_votes_survey_date ON votes(survey_id, vote_date)",

        // ---- 每日提问配额 ----
        "CREATE TABLE IF NOT EXISTS submissions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            survey_id   INTEGER NOT NULL,
            voter_key   TEXT    NOT NULL,
            ip          TEXT    NOT NULL DEFAULT '',
            submit_date TEXT    NOT NULL,
            question_id INTEGER,
            created_at  TEXT    NOT NULL DEFAULT ''
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS uq_submit_daily ON submissions(survey_id, voter_key, submit_date, question_id)",
        "CREATE INDEX IF NOT EXISTS idx_submit_daily ON submissions(survey_id, voter_key, submit_date)",
        "CREATE INDEX IF NOT EXISTS idx_submit_ip ON submissions(survey_id, ip, submit_date)",

        // ---- 杂项 ----
        "CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS audit_log (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            action     TEXT NOT NULL,
            target     TEXT NOT NULL DEFAULT '',
            detail     TEXT NOT NULL DEFAULT '',
            ip         TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS rate_limit (
            k            TEXT PRIMARY KEY,
            hits         INTEGER NOT NULL DEFAULT 0,
            window_start INTEGER NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            ip           TEXT PRIMARY KEY,
            fails        INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER NOT NULL DEFAULT 0
        )",
    ],

    2 => [
        // 问题修改时间用于前台轮询识别标题、标签、置顶等非投票变化。
        "ALTER TABLE questions ADD COLUMN updated_at TEXT NOT NULL DEFAULT ''",
    ],

];
