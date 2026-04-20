<?php
/**
 * 管理员后台 - 系统设置
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

$admin = getCurrentAdmin();
$db = getDb();
$prefix = $db->getPrefix();

$error = '';
$success = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = $_POST['settings'] ?? [];
    
    foreach ($settings as $key => $value) {
        setConfig($key, trim($value));
    }
    
    logOperation('update_settings', 'system', '更新系统设置');
    $success = '设置保存成功';
}

// 获取当前设置
$settings = [
    'site_name' => getConfig('site_name', '阿里云流量查询系统'),
    'site_description' => getConfig('site_description', '云数据传输流量监控平台'),
    'cache_enabled' => getConfig('cache_enabled', '1'),
    'cache_ttl' => getConfig('cache_ttl', '300'),
    'refresh_interval' => getConfig('refresh_interval', '60'),
    'timezone' => getConfig('timezone', 'Asia/Shanghai'),
    'auto_refresh_enabled' => getConfig('auto_refresh_enabled', '0'),
    'auto_refresh_interval' => getConfig('auto_refresh_interval', '5')
];

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统设置 - 阿里云流量查询系统</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <?php $currentPage = 'system'; include __DIR__ . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left"><h1>系统设置</h1></div>
                <div class="topbar-right"><span class="admin-name">欢迎，<?php echo htmlspecialchars($admin['username']); ?></span></div>
            </header>
            
            <div class="content">
                <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <div class="panel">
                        <div class="panel-header"><h3>基本设置</h3></div>
                        <div class="panel-body">
                            <div class="form-group">
                                <label for="site_name">网站名称</label>
                                <input type="text" id="site_name" name="settings[site_name]" value="<?php echo htmlspecialchars($settings['site_name']); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="site_description">网站描述</label>
                                <input type="text" id="site_description" name="settings[site_description]" value="<?php echo htmlspecialchars($settings['site_description']); ?>">
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="refresh_interval">前端刷新间隔(秒)</label>
                                    <input type="number" id="refresh_interval" name="settings[refresh_interval]" value="<?php echo htmlspecialchars($settings['refresh_interval']); ?>" min="30" max="300">
                                </div>
                                <div class="form-group">
                                    <label for="timezone">时区</label>
                                    <select id="timezone" name="settings[timezone]">
                                        <option value="Asia/Shanghai" <?php echo $settings['timezone'] === 'Asia/Shanghai' ? 'selected' : ''; ?>>中国标准时间</option>
                                        <option value="UTC" <?php echo $settings['timezone'] === 'UTC' ? 'selected' : ''; ?>>UTC时间</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="panel">
                        <div class="panel-header"><h3>自动刷新设置</h3></div>
                        <div class="panel-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="auto_refresh_enabled">启用自动刷新</label>
                                    <select id="auto_refresh_enabled" name="settings[auto_refresh_enabled]">
                                        <option value="1" <?php echo $settings['auto_refresh_enabled'] === '1' ? 'selected' : ''; ?>>启用</option>
                                        <option value="0" <?php echo $settings['auto_refresh_enabled'] === '0' ? 'selected' : ''; ?>>禁用</option>
                                    </select>
                                    <span class="hint">启用后前端页面会自动定时刷新流量数据</span>
                                </div>
                                <div class="form-group">
                                    <label for="auto_refresh_interval">刷新间隔(分钟)</label>
                                    <input type="number" id="auto_refresh_interval" name="settings[auto_refresh_interval]" value="<?php echo htmlspecialchars($settings['auto_refresh_interval']); ?>" min="1" max="60">
                                    <span class="hint">建议5-10分钟</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions" style="display: flex; justify-content: center; text-align: center;">
                        <button type="submit" class="btn btn-primary">保存设置</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
