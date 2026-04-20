<?php
/**
 * 安装向导 - 步骤3: 管理员设置
 */

// 启动session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Helper.php';

$error = '';
$success = false;

// 从session获取数据库配置
$dbConfig = $_SESSION['install_db_config'] ?? null;

if (!$dbConfig) {
    $error = '请先完成数据库配置';
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbConfig) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $email = trim($_POST['email'] ?? '');
    
    // 验证
    if (strlen($username) < 3) {
        $error = '用户名至少3个字符';
    } elseif (strlen($password) < 6) {
        $error = '密码至少6个字符';
    } elseif ($password !== $passwordConfirm) {
        $error = '两次输入的密码不一致';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } else {
        try {
            // 连接数据库 - 使用session中的配置
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['name']
            );
            
            $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // 生成密码哈希
            $passwordHash = Helper::passwordHash($password);
            
            // 插入管理员
            $prefix = $dbConfig['prefix'];
            $sql = "INSERT INTO `{$prefix}admin_users` (`username`, `password`, `email`, `status`, `created_at`) 
                    VALUES (?, ?, ?, 1, NOW())";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$username, $passwordHash, $email]);
            
            // 记录安装日志
            $sql = "INSERT INTO `{$prefix}operation_logs` (`username`, `action`, `module`, `content`, `ip`, `created_at`) 
                    VALUES (?, 'install', 'system', '系统安装完成', ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$username, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
            
            $success = true;
            
        } catch (PDOException $e) {
            $error = '数据库操作失败: ' . $e->getMessage();
        }
    }
}

?>

<h3>管理员设置</h3>
<p class="hint" style="margin-bottom: 20px; color: #6c757d;">
    请设置管理员账号信息，用于登录后台管理系统
</p>

<?php if ($error): ?>
<div class="alert alert-error">
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
    管理员账号创建成功！正在跳转...
</div>
<script>setTimeout(function(){ location.href='?step=4'; }, 2000);</script>
<?php elseif (!$dbConfig): ?>
<div class="alert alert-error">
    数据库配置丢失，请返回上一步重新配置
</div>
<div class="actions">
    <a href="?step=2" class="btn btn-primary">返回数据库配置</a>
</div>
<?php else: ?>

<form method="post" action="">
    <div class="form-group">
        <label for="username">管理员用户名</label>
        <input type="text" id="username" name="username" placeholder="请输入用户名" required minlength="3" maxlength="50">
        <span class="hint">用户名长度3-50个字符</span>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label for="password">登录密码</label>
            <input type="password" id="password" name="password" placeholder="请输入密码" required minlength="6">
            <span class="hint">密码至少6个字符</span>
        </div>
        <div class="form-group">
            <label for="password_confirm">确认密码</label>
            <input type="password" id="password_confirm" name="password_confirm" placeholder="请再次输入密码" required>
        </div>
    </div>
    
    <div class="form-group">
        <label for="email">管理员邮箱 (可选)</label>
        <input type="email" id="email" name="email" placeholder="请输入邮箱地址">
        <span class="hint">用于接收系统通知和找回密码</span>
    </div>
    
    <div class="actions">
        <a href="?step=2" class="btn btn-secondary">上一步</a>
        <button type="submit" class="btn btn-primary">创建管理员</button>
    </div>
</form>

<?php endif; ?>
