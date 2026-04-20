<?php
/**
 * 管理员后台 - 退出登录
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

// 记录日志
logOperation('logout', 'auth', '管理员退出登录');

// 清除会话
$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

Helper::redirect('login.php');
