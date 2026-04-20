<?php
/**
 * 管理员后台 - 公共引导文件
 * 处理会话、权限验证等公共逻辑
 */

// 定义常量
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ADMIN_PATH', __DIR__ . DIRECTORY_SEPARATOR);

// 加载配置
$config = require ROOT_PATH . 'config' . DIRECTORY_SEPARATOR . 'config.php';

// 加载核心类
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Helper.php';

// 启动会话
session_name($config['session']['name']);
session_start();

// 检查是否已安装
if (!file_exists(ROOT_PATH . 'install.lock')) {
    echo '<script>window.location.href="../install/";</script>';
    exit;
}

// 获取当前请求的文件名
$currentFile = basename($_SERVER['PHP_SELF']);

// 不需要登录验证的页面
$publicPages = ['login.php', 'logout.php'];

// 验证登录状态
if (!in_array($currentFile, $publicPages)) {
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo '<script>window.location.href="login.php";</script>';
        exit;
    }
}

/**
 * 获取数据库实例
 * @return Database|null 数据库实例
 */
function getDb()
{
    try {
        global $config;
        return Database::getInstance($config['database']);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 获取当前管理员信息
 * @return array|null 管理员信息
 */
function getCurrentAdmin()
{
    if (!isset($_SESSION['admin_id'])) {
        return null;
    }
    
    try {
        $db = getDb();
        if (!$db) {
            return null;
        }
        $prefix = $db->getPrefix();
        
        $sql = "SELECT * FROM `{$prefix}admin_users` WHERE `id` = ? AND `status` = 1";
        return $db->fetchOne($sql, [$_SESSION['admin_id']]);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 记录操作日志
 * @param string $action 操作动作
 * @param string $module 模块名称
 * @param string $content 操作内容
 */
function logOperation($action, $module, $content = '')
{
    try {
        $db = getDb();
        if (!$db) {
            return;
        }
        $prefix = $db->getPrefix();
        
        $admin = getCurrentAdmin();
        
        $sql = "INSERT INTO `{$prefix}operation_logs` 
                (`admin_id`, `username`, `action`, `module`, `content`, `ip`, `user_agent`, `created_at`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $db->query($sql, [
            $admin['id'] ?? null,
            $admin['username'] ?? 'unknown',
            $action,
            $module,
            $content,
            Helper::getClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        // 忽略日志记录错误
    }
}

/**
 * 获取系统配置
 * @param string $key 配置键名
 * @param mixed $default 默认值
 * @return mixed 配置值
 */
function getConfig($key, $default = null)
{
    try {
        $db = getDb();
        if (!$db) {
            return $default;
        }
        $prefix = $db->getPrefix();
        
        $sql = "SELECT `config_value` FROM `{$prefix}system_config` WHERE `config_key` = ?";
        $result = $db->fetchOne($sql, [$key]);
        
        return $result ? $result['config_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * 设置系统配置
 * @param string $key 配置键名
 * @param mixed $value 配置值
 */
function setConfig($key, $value)
{
    try {
        $db = getDb();
        if (!$db) {
            return;
        }
        $prefix = $db->getPrefix();
        
        $sql = "INSERT INTO `{$prefix}system_config` (`config_key`, `config_value`, `updated_at`) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE `config_value` = ?, `updated_at` = NOW()";
        
        $db->query($sql, [$key, $value, $value]);
    } catch (Exception $e) {
        // 忽略配置保存错误
    }
}
