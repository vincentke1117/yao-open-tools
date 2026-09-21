<?php
declare(strict_types=1);

/**
 * SQLite 连接与自动迁移。
 * 数据库文件首次访问时自动创建，表结构按 user_version 逐版本升级。
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = (string)cfg('db_path', APP_ROOT . '/data/tokpoll.sqlite');
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("数据目录无法创建：{$dir}");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("数据目录不可写，请给 {$dir} 加写权限（755 + 属主为 Web 用户）");
        }

        // 兼容：项目早期叫 poll-lite，数据库文件名是 poll.sqlite。
        // 升级后如果新文件还不存在、旧文件还在，就自动改名接管，数据不丢。
        if (!is_file($path)) {
            $legacy = $dir . '/poll.sqlite';
            if (is_file($legacy)) {
                @rename($legacy, $path);
                foreach (['-wal', '-shm'] as $suffix) {
                    if (is_file($legacy . $suffix)) {
                        @rename($legacy . $suffix, $path . $suffix);
                    }
                }
            }
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        return $pdo;
    }

    /** 执行未应用的迁移 */
    public static function migrate(): void
    {
        $pdo = self::pdo();
        $current = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        $migrations = require APP_DIR . '/migrations.php';

        foreach ($migrations as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                foreach ($statements as $sql) {
                    $pdo->exec($sql);
                }
                $pdo->exec('PRAGMA user_version = ' . (int)$version);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw new RuntimeException("数据库迁移 v{$version} 失败：" . $e->getMessage(), 0, $e);
            }
        }
    }

    /** 查询多行 */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** 查询单行 */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** 查询单值 */
    public static function val(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    /** 执行写操作，返回影响行数 */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastId(): int
    {
        return (int)self::pdo()->lastInsertId();
    }
}
