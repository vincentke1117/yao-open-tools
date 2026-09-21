<?php
declare(strict_types=1);

/**
 * 全局引导文件：加载配置、设置时区与错误处理、启动 Session、引入各模块。
 * 所有入口文件（public/index.php、api.php、admin.php）第一行都应 require 本文件。
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('PUBLIC_DIR', APP_ROOT . '/public');

// ---- 加载配置 ----------------------------------------------------------
$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    $example = APP_ROOT . '/config.php.example';
    if (is_file($example) && @copy($example, $configFile)) {
        // 首次运行自动生成一份默认配置，方便开箱即用
    } else {
        http_response_code(500);
        exit('缺少 config.php，请复制 config.php.example 为 config.php 后重试。');
    }
}
/** @var array $CONFIG */
$CONFIG = require $configFile;

if (!is_array($CONFIG)) {
    http_response_code(500);
    exit('config.php 格式错误：应当 return 一个数组。');
}

$GLOBALS['CONFIG'] = $CONFIG;

/** 读取配置项 */
function cfg(string $key, $default = null) {
    return $GLOBALS['CONFIG'][$key] ?? $default;
}

// ---- 时区与错误处理 ----------------------------------------------------
date_default_timezone_set((string)cfg('timezone', 'Asia/Shanghai'));

if (cfg('debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

set_exception_handler(function (Throwable $e): void {
    error_log('[tokpoll] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '错误：' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    if (cfg('debug')) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage() . "\n\n" . $e->getTraceAsString();
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>出错了</title>'
           . '<div style="font:17px/1.6 -apple-system,PingFang SC,sans-serif;padding:60px;text-align:center;color:#1d1d1f">'
           . '<h1 style="font-size:28px;font-weight:600">页面出了点问题</h1>'
           . '<p style="color:#6e6e73">请稍后再试，或联系管理员。</p></div>';
    }
    exit;
});

// ---- Session -----------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('TOKPOLLSESS');
    session_start();
}

// ---- 模块 --------------------------------------------------------------
require_once APP_DIR . '/helpers.php';
require_once APP_DIR . '/Db.php';
require_once APP_DIR . '/Voter.php';
require_once APP_DIR . '/Survey.php';
require_once APP_DIR . '/Admin.php';

// 首次访问时自动建表 / 升级
Db::migrate();
