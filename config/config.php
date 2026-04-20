<?php
/**
 * 系统主配置文件
 * 包含系统全局配置项
 */

// 定义应用常量
define('APP_VERSION', '1.0.0');
define('APP_NAME', '阿里云流量查询系统');
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 加载环境变量（不使用putenv）
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // 跳过注释行
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // 分割键值
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        
        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        
        // 移除引号
        if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
            (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
            $value = substr($value, 1, -1);
        }
        
        // 设置环境变量（不使用putenv）
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
    
    return true;
}

// 加载.env文件
loadEnv(ROOT_PATH . '.env');

// 获取环境变量值的辅助函数（不使用getenv）
function env($key, $default = '') {
    // 优先从$_SERVER获取，然后$_ENV
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    return $default;
}

// 错误报告设置（在加载.env之后）
if (env('APP_DEBUG') === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// 系统配置数组
$config = [
    // 应用配置
    'app' => [
        'name' => env('APP_NAME', APP_NAME),
        'version' => APP_VERSION,
        'debug' => env('APP_DEBUG') === 'true',
        'url' => env('APP_URL', 'http://localhost'),
        'secret' => env('APP_SECRET', 'default_secret_key_change_me')
    ],
    
    // 数据库配置
    'database' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'aliyun_traffic'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
        'prefix' => env('DB_PREFIX', 'at_'),
        'charset' => 'utf8mb4'
    ],
    
    // 阿里云配置
    'aliyun' => [
        'access_key_id' => env('ALIYUN_ACCESS_KEY_ID', ''),
        'access_key_secret' => env('ALIYUN_ACCESS_KEY_SECRET', ''),
        'region_id' => env('ALIYUN_REGION_ID', 'cn-hangzhou'),
        'endpoint' => 'cdt.aliyuncs.com',
        'api_version' => '2021-08-13'
    ],
    
    // 缓存配置
    'cache' => [
        'enabled' => true,
        'ttl' => 300,
        'prefix' => 'aliyun_traffic_'
    ],
    
    // 会话配置
    'session' => [
        'lifetime' => 7200,
        'name' => 'AT_SESSION'
    ]
];

return $config;
