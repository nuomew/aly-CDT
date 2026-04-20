<?php
/**
 * 安装向导 - 入口文件
 * 检测安装状态，引导用户完成安装
 */

// 定义常量
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('INSTALL_PATH', __DIR__ . DIRECTORY_SEPARATOR);

// 启动session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 检查是否已安装
$lockFile = ROOT_PATH . 'install.lock';

if (file_exists($lockFile)) {
    die('系统已安装，如需重新安装请删除 install.lock 文件');
}

// 当前步骤
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;

// 步骤配置
$steps = [
    1 => '环境检测',
    2 => '数据库配置',
    3 => '管理员设置',
    4 => '完成安装'
];

// 当前步骤标题
$currentStepTitle = $steps[$step] ?? '安装向导';

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 - <?php echo $currentStepTitle; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 800px;
            width: 100%;
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .steps {
            display: flex;
            justify-content: center;
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        
        .step-item {
            display: flex;
            align-items: center;
            padding: 0 15px;
        }
        
        .step-number {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 8px;
            font-size: 14px;
        }
        
        .step-item.active .step-number {
            background: #667eea;
            color: #fff;
        }
        
        .step-item.completed .step-number {
            background: #28a745;
            color: #fff;
        }
        
        .step-text {
            font-size: 14px;
            color: #6c757d;
        }
        
        .step-item.active .step-text {
            color: #667eea;
            font-weight: 500;
        }
        
        .step-item.completed .step-text {
            color: #28a745;
        }
        
        .step-divider {
            width: 40px;
            height: 2px;
            background: #dee2e6;
            margin: 0 5px;
        }
        
        .content {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group .hint {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .actions {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }
        
        .check-list {
            list-style: none;
        }
        
        .check-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        
        .check-item.success {
            background: #d4edda;
            color: #155724;
        }
        
        .check-item.error {
            background: #f8d7da;
            color: #721c24;
        }
        
        .check-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 14px;
        }
        
        .check-item.success .check-icon {
            background: #28a745;
            color: #fff;
        }
        
        .check-item.error .check-icon {
            background: #dc3545;
            color: #fff;
        }
        
        .check-item.pending .check-icon {
            background: #ffc107;
            color: #212529;
        }
        
        .check-text {
            flex: 1;
        }
        
        .check-status {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 4px;
        }
        
        .check-item.success .check-status {
            background: #28a745;
            color: #fff;
        }
        
        .check-item.error .check-status {
            background: #dc3545;
            color: #fff;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .loading.show {
            display: block;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: #28a745;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
            color: #fff;
        }
        
        .text-center {
            text-align: center;
        }
        
        .mt-20 {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>阿里云流量查询系统</h1>
            <p>安装向导 - <?php echo $currentStepTitle; ?></p>
        </div>
        
        <div class="steps">
            <?php foreach ($steps as $num => $title): ?>
                <div class="step-item <?php echo $num < $step ? 'completed' : ($num == $step ? 'active' : ''); ?>">
                    <div class="step-number">
                        <?php echo $num < $step ? '✓' : $num; ?>
                    </div>
                    <span class="step-text"><?php echo $title; ?></span>
                </div>
                <?php if ($num < count($steps)): ?>
                    <div class="step-divider"></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        
        <div class="content">
            <?php
            // 根据步骤加载对应内容
            switch ($step) {
                case 1:
                    include INSTALL_PATH . 'step1.php';
                    break;
                case 2:
                    include INSTALL_PATH . 'step2.php';
                    break;
                case 3:
                    include INSTALL_PATH . 'step3.php';
                    break;
                case 4:
                    include INSTALL_PATH . 'step4.php';
                    break;
                default:
                    echo '<div class="alert alert-error">无效的安装步骤</div>';
            }
            ?>
        </div>
    </div>
</body>
</html>
