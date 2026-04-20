<?php
/**
 * 管理员后台 - 登录页面
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        try {
            $db = getDb();
            if (!$db) {
                $error = '数据库连接失败，请检查配置';
            } else {
                $prefix = $db->getPrefix();
                
                $sql = "SELECT * FROM `{$prefix}admin_users` WHERE `username` = ? AND `status` = 1";
                $admin = $db->fetchOne($sql, [$username]);
                
                if ($admin && Helper::passwordVerify($password, $admin['password'])) {
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_logged_in'] = true;
                    
                    $sql = "UPDATE `{$prefix}admin_users` SET `login_ip` = ?, `login_time` = NOW() WHERE `id` = ?";
                    $db->query($sql, [Helper::getClientIp(), $admin['id']]);
                    
                    logOperation('login', 'auth', '管理员登录成功');
                    
                    echo '<script>window.location.href="index.php";</script>';
                    exit;
                } else {
                    $error = '用户名或密码错误';
                    try {
                        logOperation('login_failed', 'auth', '登录失败，用户名: ' . $username);
                    } catch (Exception $e) {
                    }
                }
            }
        } catch (Exception $e) {
            $error = '系统错误，请稍后重试';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - 阿里云流量查询系统</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'PingFang SC', 'Microsoft YaHei', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            position: relative;
            overflow: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 30% 30%, rgba(102, 126, 234, 0.15) 0%, transparent 50%),
                        radial-gradient(circle at 70% 70%, rgba(118, 75, 162, 0.15) 0%, transparent 50%),
                        radial-gradient(circle at 50% 50%, rgba(79, 172, 254, 0.1) 0%, transparent 50%);
            animation: bgMove 20s ease-in-out infinite;
        }
        
        @keyframes bgMove {
            0%, 100% { transform: translate(0, 0); }
            25% { transform: translate(-5%, 5%); }
            50% { transform: translate(5%, -5%); }
            75% { transform: translate(-3%, -3%); }
        }
        
        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            pointer-events: none;
        }
        
        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            animation: float 15s infinite;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) rotate(720deg);
                opacity: 0;
            }
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4),
                        0 0 0 1px rgba(255, 255, 255, 0.1);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
            position: relative;
            z-index: 10;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        }
        
        .login-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -30%;
            width: 80%;
            height: 80%;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
        }
        
        .login-logo {
            width: 70px;
            height: 70px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            position: relative;
            z-index: 1;
        }
        
        .login-logo svg {
            width: 36px;
            height: 36px;
        }
        
        .login-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }
        
        .login-header p {
            opacity: 0.9;
            font-size: 14px;
            position: relative;
            z-index: 1;
        }
        
        .login-body {
            padding: 40px 30px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper svg {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: #6b7280;
            transition: color 0.3s;
        }
        
        .form-group input {
            width: 100%;
            padding: 16px 16px 16px 50px;
            border: 2px solid rgba(0, 0, 0, 0.08);
            border-radius: 14px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8fafc;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }
        
        .form-group input:focus + svg,
        .input-wrapper:focus-within svg {
            color: #667eea;
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(102, 126, 234, 0.5);
        }
        
        .btn-primary:active {
            transform: translateY(-1px);
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        
        .alert-error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(248, 113, 113, 0.1) 100%);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .alert-error svg {
            flex-shrink: 0;
        }
        
        .login-footer {
            text-align: center;
            padding: 24px 30px;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
            font-size: 13px;
            color: #6b7280;
            background: linear-gradient(180deg, rgba(248, 250, 252, 1) 0%, rgba(255, 255, 255, 1) 100%);
        }
        
        .login-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 480px) {
            .login-container {
                border-radius: 20px;
            }
            
            .login-header {
                padding: 30px 20px;
            }
            
            .login-body {
                padding: 30px 20px;
            }
            
            .login-header h1 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="particles">
        <div class="particle" style="left: 10%; animation-delay: 0s; animation-duration: 12s;"></div>
        <div class="particle" style="left: 20%; animation-delay: 2s; animation-duration: 14s;"></div>
        <div class="particle" style="left: 30%; animation-delay: 4s; animation-duration: 16s;"></div>
        <div class="particle" style="left: 40%; animation-delay: 1s; animation-duration: 13s;"></div>
        <div class="particle" style="left: 50%; animation-delay: 3s; animation-duration: 15s;"></div>
        <div class="particle" style="left: 60%; animation-delay: 5s; animation-duration: 11s;"></div>
        <div class="particle" style="left: 70%; animation-delay: 2.5s; animation-duration: 17s;"></div>
        <div class="particle" style="left: 80%; animation-delay: 4.5s; animation-duration: 14s;"></div>
        <div class="particle" style="left: 90%; animation-delay: 1.5s; animation-duration: 12s;"></div>
    </div>
    
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                </svg>
            </div>
            <h1>阿里云流量查询系统</h1>
            <p>管理员登录</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
            <div class="alert alert-error">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" placeholder="请输入用户名" required autofocus>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="请输入密码" required>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">登 录</button>
            </form>
        </div>
        
        <div class="login-footer">
            &copy; <?php echo date('Y'); ?> 阿里云流量查询系统
        </div>
    </div>
</body>
</html>
