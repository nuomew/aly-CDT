<?php
/**
 * 安装向导 - 步骤4: 完成安装
 */

// 创建安装锁文件
$lockFile = ROOT_PATH . 'install.lock';
$lockContent = json_encode([
    'version' => '1.0.0',
    'installed_at' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
]);

file_put_contents($lockFile, $lockContent);

// 生成随机密钥
$secretKey = bin2hex(random_bytes(32));

// 更新.env文件中的APP_SECRET
$envFile = ROOT_PATH . '.env';
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    $envContent = preg_replace(
        '/^APP_SECRET=.*$/m',
        'APP_SECRET=' . $secretKey,
        $envContent
    );
    file_put_contents($envFile, $envContent);
}

?>

<div class="text-center">
    <div class="success-icon">✓</div>
    <h3>安装完成！</h3>
    <p style="color: #6c757d; margin: 15px 0;">
        恭喜您，系统已成功安装！您现在可以开始使用阿里云流量查询系统了。
    </p>
    
    <div class="alert alert-info" style="text-align: left; margin: 20px 0;">
        <strong>安装信息：</strong>
        <ul style="margin: 10px 0 0 20px;">
            <li>系统版本：1.0.0</li>
            <li>安装时间：<?php echo date('Y-m-d H:i:s'); ?></li>
            <li>安全密钥：已自动生成</li>
        </ul>
    </div>
    
    <div class="alert alert-error" style="text-align: left; margin: 20px 0;">
        <strong>安全提示：</strong>
        <ul style="margin: 10px 0 0 20px;">
            <li>请删除或重命名 install 目录，防止被他人访问</li>
            <li>请妥善保管您的管理员账号密码</li>
            <li>请确保 .env 文件不可被外部访问</li>
            <li>建议定期备份数据库</li>
        </ul>
    </div>
    
    <div class="mt-20">
        <a href="../index.php" class="btn btn-primary" style="margin-right: 10px;">访问首页</a>
        <a href="../admin/" class="btn btn-secondary">进入后台</a>
    </div>
</div>
