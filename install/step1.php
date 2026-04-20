<?php
/**
 * 安装向导 - 步骤1: 环境检测
 */

// 环境检测项
$checks = [];

// PHP版本检测
$phpVersion = PHP_VERSION;
$phpRequired = '7.2.0';
$phpCheck = version_compare($phpVersion, $phpRequired, '>=');
$checks[] = [
    'name' => 'PHP版本',
    'required' => '>= ' . $phpRequired,
    'current' => $phpVersion,
    'status' => $phpCheck ? 'success' : 'error',
    'message' => $phpCheck ? '符合要求' : 'PHP版本过低，请升级到 ' . $phpRequired . ' 或更高版本'
];

// MySQL扩展检测
$mysqlCheck = extension_loaded('pdo_mysql');
$checks[] = [
    'name' => 'PDO MySQL扩展',
    'required' => '必须安装',
    'current' => $mysqlCheck ? '已安装' : '未安装',
    'status' => $mysqlCheck ? 'success' : 'error',
    'message' => $mysqlCheck ? '已安装' : '请安装PDO MySQL扩展'
];

// cURL扩展检测
$curlCheck = extension_loaded('curl');
$checks[] = [
    'name' => 'cURL扩展',
    'required' => '必须安装',
    'current' => $curlCheck ? '已安装' : '未安装',
    'status' => $curlCheck ? 'success' : 'error',
    'message' => $curlCheck ? '已安装' : '请安装cURL扩展'
];

// OpenSSL扩展检测
$opensslCheck = extension_loaded('openssl');
$checks[] = [
    'name' => 'OpenSSL扩展',
    'required' => '必须安装',
    'current' => $opensslCheck ? '已安装' : '未安装',
    'status' => $opensslCheck ? 'success' : 'error',
    'message' => $opensslCheck ? '已安装' : '请安装OpenSSL扩展'
];

// JSON扩展检测
$jsonCheck = extension_loaded('json');
$checks[] = [
    'name' => 'JSON扩展',
    'required' => '必须安装',
    'current' => $jsonCheck ? '已安装' : '未安装',
    'status' => $jsonCheck ? 'success' : 'error',
    'message' => $jsonCheck ? '已安装' : '请安装JSON扩展'
];

// 目录权限检测
$dirs = [
    ROOT_PATH . 'config',
    ROOT_PATH . 'assets',
    ROOT_PATH . 'cache'
];

foreach ($dirs as $dir) {
    $dirName = basename($dir);
    $exists = is_dir($dir);
    $writable = $exists ? is_writable($dir) : false;
    
    // 如果目录不存在，尝试创建
    if (!$exists) {
        @mkdir($dir, 0755, true);
        $exists = is_dir($dir);
        $writable = $exists ? is_writable($dir) : false;
    }
    
    $checks[] = [
        'name' => '目录: ' . $dirName,
        'required' => '可写',
        'current' => $writable ? '可写' : ($exists ? '不可写' : '不存在'),
        'status' => $writable ? 'success' : 'error',
        'message' => $writable ? '权限正常' : '请设置目录权限为可写'
    ];
}

// .env文件检测
$envExample = ROOT_PATH . '.env.example';
$envFile = ROOT_PATH . '.env';
$envCheck = file_exists($envFile);

if (!$envCheck && file_exists($envExample)) {
    // 尝试复制.env.example到.env
    @copy($envExample, $envFile);
    $envCheck = file_exists($envFile);
}

$checks[] = [
    'name' => '配置文件(.env)',
    'required' => '必须存在',
    'current' => $envCheck ? '已创建' : '未创建',
    'status' => $envCheck ? 'success' : 'error',
    'message' => $envCheck ? '配置文件已就绪' : '请复制.env.example为.env'
];

// 计算是否可以继续
$canContinue = true;
foreach ($checks as $check) {
    if ($check['status'] === 'error') {
        $canContinue = false;
        break;
    }
}

?>

<h3>环境检测</h3>
<p class="hint" style="margin-bottom: 20px; color: #6c757d;">
    系统将检测您的服务器环境是否满足安装要求
</p>

<ul class="check-list">
    <?php foreach ($checks as $check): ?>
    <li class="check-item <?php echo $check['status']; ?>">
        <div class="check-icon">
            <?php 
            echo $check['status'] === 'success' ? '✓' : ($check['status'] === 'error' ? '✗' : '!');
            ?>
        </div>
        <div class="check-text">
            <strong><?php echo $check['name']; ?></strong>
            <div style="font-size: 12px; color: #6c757d; margin-top: 3px;">
                要求: <?php echo $check['required']; ?> | 当前: <?php echo $check['current']; ?>
            </div>
        </div>
        <span class="check-status"><?php echo $check['message']; ?></span>
    </li>
    <?php endforeach; ?>
</ul>

<div class="actions">
    <div></div>
    <div>
        <?php if ($canContinue): ?>
        <a href="?step=2" class="btn btn-primary">下一步</a>
        <?php else: ?>
        <button class="btn btn-primary" disabled onclick="alert('请先解决以上环境问题')">下一步</button>
        <?php endif; ?>
    </div>
</div>
