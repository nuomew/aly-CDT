<?php
/**
 * 安装向导 - 步骤2: 数据库配置
 */

// 启动session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = false;

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbPrefix = trim($_POST['db_prefix'] ?? 'at_');
    
    // 验证必填项
    if (empty($dbName) || empty($dbUser)) {
        $error = '请填写数据库名称和用户名';
    } else {
        // 测试数据库连接
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // 创建数据库（如果不存在）
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
            
            // 读取并执行SQL文件
            $sqlFile = INSTALL_PATH . 'install.sql';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                
                // 替换表前缀
                $sql = str_replace('at_', $dbPrefix, $sql);
                
                // 执行SQL
                $pdo->exec($sql);
            }
            
            // 生成随机密钥
            $secretKey = bin2hex(random_bytes(32));
            $currentTime = date('Y-m-d H:i:s');
            
            // 直接生成新的.env文件内容
            $envContent = <<<ENV
# 阿里云流量查询系统 - 环境配置
# 自动生成于 {$currentTime}

# 数据库配置
DB_HOST={$dbHost}
DB_PORT={$dbPort}
DB_NAME={$dbName}
DB_USER={$dbUser}
DB_PASS={$dbPass}
DB_PREFIX={$dbPrefix}

# 阿里云配置
ALIYUN_ACCESS_KEY_ID=
ALIYUN_ACCESS_KEY_SECRET=
ALIYUN_REGION_ID=cn-hangzhou

# 系统配置
APP_DEBUG=false
APP_URL=http://localhost
APP_NAME=阿里云流量查询系统

# 安全配置
APP_SECRET={$secretKey}
ENV;
            
            // 写入.env文件
            $envFile = ROOT_PATH . '.env';
            $writeResult = file_put_contents($envFile, $envContent);
            
            if ($writeResult === false) {
                $error = '配置文件写入失败，请检查目录权限';
            } else {
                // 保存数据库配置到session
                $_SESSION['install_db_config'] = [
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'name' => $dbName,
                    'user' => $dbUser,
                    'pass' => $dbPass,
                    'prefix' => $dbPrefix
                ];
                
                $success = true;
            }
            
        } catch (PDOException $e) {
            $error = '数据库连接失败: ' . $e->getMessage();
        }
    }
}

?>

<h3>数据库配置</h3>
<p class="hint" style="margin-bottom: 20px; color: #6c757d;">
    请填写您的MySQL数据库连接信息
</p>

<?php if ($error): ?>
<div class="alert alert-error">
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
    数据库配置成功！正在跳转...
</div>
<script>setTimeout(function(){ location.href='?step=3'; }, 2000);</script>
<?php else: ?>

<form method="post" action="">
    <div class="form-row">
        <div class="form-group">
            <label for="db_host">数据库主机</label>
            <input type="text" id="db_host" name="db_host" value="localhost" required>
            <span class="hint">通常为 localhost</span>
        </div>
        <div class="form-group">
            <label for="db_port">端口</label>
            <input type="text" id="db_port" name="db_port" value="3306" required>
            <span class="hint">MySQL默认端口 3306</span>
        </div>
    </div>
    
    <div class="form-group">
        <label for="db_name">数据库名称</label>
        <input type="text" id="db_name" name="db_name" placeholder="请输入数据库名称" required>
        <span class="hint">如果数据库不存在，系统将自动创建</span>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label for="db_user">数据库用户名</label>
            <input type="text" id="db_user" name="db_user" placeholder="请输入用户名" required>
        </div>
        <div class="form-group">
            <label for="db_pass">数据库密码</label>
            <input type="password" id="db_pass" name="db_pass" placeholder="请输入密码">
        </div>
    </div>
    
    <div class="form-group">
        <label for="db_prefix">表前缀</label>
        <input type="text" id="db_prefix" name="db_prefix" value="at_">
        <span class="hint">表前缀用于区分不同应用的数据表</span>
    </div>
    
    <div class="actions">
        <a href="?step=1" class="btn btn-secondary">上一步</a>
        <button type="submit" class="btn btn-primary">测试并安装</button>
    </div>
</form>

<?php endif; ?>
