<?php
/**
 * 管理员后台 - 阿里云配置管理
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunCdt.php';

$admin = getCurrentAdmin();
$db = getDb();
$prefix = $db->getPrefix();

$checkField = $db->fetchOne("SHOW COLUMNS FROM `{$prefix}aliyun_config` LIKE 'max_traffic_gb'");
if (!$checkField) {
    $db->query("ALTER TABLE `{$prefix}aliyun_config` 
                ADD COLUMN `max_traffic_gb` DECIMAL(10,2) DEFAULT 200.00 COMMENT '最大流量限制(GB)' AFTER `remark`,
                ADD COLUMN `alert_threshold` TINYINT(3) DEFAULT 80 COMMENT '流量提醒阈值(%)' AFTER `max_traffic_gb`");
}

$checkShutdownField = $db->fetchOne("SHOW COLUMNS FROM `{$prefix}aliyun_config` LIKE 'shutdown_threshold'");
if (!$checkShutdownField) {
    $db->query("ALTER TABLE `{$prefix}aliyun_config` 
                ADD COLUMN `shutdown_threshold` TINYINT(3) DEFAULT 95 COMMENT '自动关机阈值(%)' AFTER `alert_threshold`");
}

$checkAutoField = $db->fetchOne("SHOW COLUMNS FROM `{$prefix}aliyun_config` LIKE 'auto_shutdown'");
if (!$checkAutoField) {
    $db->query("ALTER TABLE `{$prefix}aliyun_config` 
                ADD COLUMN `auto_shutdown` TINYINT(1) DEFAULT 0 COMMENT '是否自动关机' AFTER `alert_threshold`,
                ADD COLUMN `auto_start_day` TINYINT(2) DEFAULT 1 COMMENT '自动开机日期(每月几号1-31)' AFTER `auto_shutdown`,
                ADD COLUMN `auto_start_hour` TINYINT(2) DEFAULT 0 COMMENT '自动开机小时(0-23)' AFTER `auto_start_day`,
                ADD COLUMN `auto_start_minute` TINYINT(2) DEFAULT 0 COMMENT '自动开机分钟(0-59)' AFTER `auto_start_hour`,
                ADD COLUMN `last_auto_shutdown` DATETIME NULL COMMENT '最后自动关机时间' AFTER `auto_start_minute`,
                ADD COLUMN `last_auto_start` DATETIME NULL COMMENT '最后自动开机时间' AFTER `last_auto_shutdown`");
}

$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$error = '';
$success = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $accessKeyId = trim($_POST['access_key_id'] ?? '');
    $accessKeySecret = $_POST['access_key_secret'] ?? '';
    $regionId = trim($_POST['region_id'] ?? 'cn-hangzhou');
    $status = intval($_POST['status'] ?? 1);
    $isDefault = intval($_POST['is_default'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');
    $maxTrafficGb = floatval($_POST['max_traffic_gb'] ?? 200);
    $alertThreshold = intval($_POST['alert_threshold'] ?? 80);
    $shutdownThreshold = intval($_POST['shutdown_threshold'] ?? 95);
    $autoShutdown = intval($_POST['auto_shutdown'] ?? 0);
    $autoStartDay = intval($_POST['auto_start_day'] ?? 1);
    $autoStartHour = intval($_POST['auto_start_hour'] ?? 0);
    $autoStartMinute = intval($_POST['auto_start_minute'] ?? 0);
    
    if ($maxTrafficGb <= 0) $maxTrafficGb = 200;
    if ($alertThreshold < 1 || $alertThreshold > 100) $alertThreshold = 80;
    if ($shutdownThreshold < 1 || $shutdownThreshold > 100) $shutdownThreshold = 95;
    if ($autoStartDay < 1 || $autoStartDay > 31) $autoStartDay = 1;
    if ($autoStartHour < 0 || $autoStartHour > 23) $autoStartHour = 0;
    if ($autoStartMinute < 0 || $autoStartMinute > 59) $autoStartMinute = 0;
    
    if (empty($name) || empty($accessKeyId)) {
        $error = '请填写配置名称和AccessKey ID';
    } else {
        // 加密存储AccessKey Secret
        $encryptedSecret = Helper::encrypt($accessKeySecret);
        
        if ($action === 'add') {
            // 新增配置
            if ($isDefault) {
                // 取消其他默认配置
                $db->query("UPDATE `{$prefix}aliyun_config` SET `is_default` = 0");
            }
            
            $sql = "INSERT INTO `{$prefix}aliyun_config` 
                    (`name`, `access_key_id`, `access_key_secret`, `region_id`, `status`, `is_default`, `remark`, `max_traffic_gb`, `alert_threshold`, `shutdown_threshold`, `auto_shutdown`, `auto_start_day`, `auto_start_hour`, `auto_start_minute`, `created_at`) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $db->query($sql, [$name, $accessKeyId, $encryptedSecret, $regionId, $status, $isDefault, $remark, $maxTrafficGb, $alertThreshold, $shutdownThreshold, $autoShutdown, $autoStartDay, $autoStartHour, $autoStartMinute]);
            
            logOperation('add_config', 'aliyun', '添加阿里云配置: ' . $name);
            $success = '配置添加成功';
            
        } elseif ($action === 'edit' && $id > 0) {
            // 编辑配置
            if ($isDefault) {
                $db->query("UPDATE `{$prefix}aliyun_config` SET `is_default` = 0");
            }
            
            $sql = "UPDATE `{$prefix}aliyun_config` 
                    SET `name` = ?, `access_key_id` = ?, `region_id` = ?, `status` = ?, `is_default` = ?, `remark` = ?, `max_traffic_gb` = ?, `alert_threshold` = ?, `shutdown_threshold` = ?, `auto_shutdown` = ?, `auto_start_day` = ?, `auto_start_hour` = ?, `auto_start_minute` = ?, `updated_at` = NOW() 
                    WHERE `id` = ?";
            
            $params = [$name, $accessKeyId, $regionId, $status, $isDefault, $remark, $maxTrafficGb, $alertThreshold, $shutdownThreshold, $autoShutdown, $autoStartDay, $autoStartHour, $autoStartMinute, $id];
            
            // 如果填写了新的Secret则更新
            if (!empty($accessKeySecret)) {
                $sql = "UPDATE `{$prefix}aliyun_config` 
                        SET `name` = ?, `access_key_id` = ?, `access_key_secret` = ?, `region_id` = ?, `status` = ?, `is_default` = ?, `remark` = ?, `max_traffic_gb` = ?, `alert_threshold` = ?, `shutdown_threshold` = ?, `auto_shutdown` = ?, `auto_start_day` = ?, `auto_start_hour` = ?, `auto_start_minute` = ?, `updated_at` = NOW() 
                        WHERE `id` = ?";
                $params = [$name, $accessKeyId, $encryptedSecret, $regionId, $status, $isDefault, $remark, $maxTrafficGb, $alertThreshold, $shutdownThreshold, $autoShutdown, $autoStartDay, $autoStartHour, $autoStartMinute, $id];
            }
            
            $db->query($sql, $params);
            
            logOperation('edit_config', 'aliyun', '编辑阿里云配置: ' . $name);
            $success = '配置更新成功';
        }
        
        $action = 'list';
    }
}

// 处理删除
if ($action === 'delete' && $id > 0) {
    $config = $db->fetchOne("SELECT * FROM `{$prefix}aliyun_config` WHERE `id` = ?", [$id]);
    
    if ($config) {
        $db->query("DELETE FROM `{$prefix}aliyun_config` WHERE `id` = ?", [$id]);
        logOperation('delete_config', 'aliyun', '删除阿里云配置: ' . $config['name']);
        $success = '配置删除成功';
    }
    
    $action = 'list';
}

// 处理测试连接
if ($action === 'test' && $id > 0) {
    $config = $db->fetchOne("SELECT * FROM `{$prefix}aliyun_config` WHERE `id` = ?", [$id]);
    
    if ($config) {
        $secret = Helper::decrypt($config['access_key_secret']);
        $cdt = new AliyunCdt($config['access_key_id'], $secret, $config['region_id']);
        
        $result = $cdt->testConnection();
        
        if ($result['success']) {
            $success = '连接测试成功，API认证通过';
        } else {
            $error = '连接测试失败: ' . $result['error'];
        }
    }
    
    $action = 'list';
}

// 获取配置列表（包含本月流量统计 - 取最新记录的值）
$monthStart = date('Y-m-01');
$sql = "SELECT ac.*, 
        COALESCE((SELECT tr2.traffic_total FROM `{$prefix}traffic_records` tr2 WHERE tr2.config_id = ac.id AND tr2.record_date >= ? ORDER BY tr2.record_date DESC LIMIT 1), 0) as month_traffic 
        FROM `{$prefix}aliyun_config` ac 
        ORDER BY ac.is_default DESC, ac.id DESC";
$configs = $db->fetchAll($sql, [$monthStart]);

// 获取编辑的配置
$editConfig = null;
if ($action === 'edit' && $id > 0) {
    $editConfig = $db->fetchOne("SELECT * FROM `{$prefix}aliyun_config` WHERE `id` = ?", [$id]);
}

// 地域列表
$regions = [
    'cn-hangzhou' => '华东1(杭州)',
    'cn-shanghai' => '华东2(上海)',
    'cn-qingdao' => '华北1(青岛)',
    'cn-beijing' => '华北2(北京)',
    'cn-zhangjiakou' => '华北3(张家口)',
    'cn-huhehaote' => '华北5(呼和浩特)',
    'cn-wulanchabu' => '华北6(乌兰察布)',
    'cn-shenzhen' => '华南1(深圳)',
    'cn-heyuan' => '华南2(河源)',
    'cn-guangzhou' => '华南3(广州)',
    'cn-chengdu' => '西南1(成都)',
    'cn-nanjing' => '华东5(南京)',
    'cn-fuzhou' => '华东6(福州)',
    'cn-hongkong' => '中国香港',
    'ap-southeast-1' => '新加坡',
    'ap-southeast-2' => '澳大利亚(悉尼)',
    'ap-southeast-3' => '马来西亚(吉隆坡)',
    'ap-southeast-5' => '印度尼西亚(雅加达)',
    'ap-southeast-6' => '菲律宾(马尼拉)',
    'ap-southeast-7' => '泰国(曼谷)',
    'ap-northeast-1' => '日本(东京)',
    'ap-northeast-2' => '韩国(首尔)',
    'ap-south-1' => '印度(孟买)',
    'us-east-1' => '美国(弗吉尼亚)',
    'us-west-1' => '美国(硅谷)',
    'eu-west-1' => '英国(伦敦)',
    'eu-central-1' => '德国(法兰克福)',
    'me-east-1' => '阿联酋(迪拜)',
    'me-central-1' => '沙特(利雅得)'
];

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>阿里云配置 - 阿里云流量查询系统</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <?php $currentPage = 'config'; include __DIR__ . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>
        
        <main class="main-content">
            <!-- 顶部栏 -->
            <header class="topbar">
                <div class="topbar-left">
                    <h1>阿里云配置</h1>
                </div>
                <div class="topbar-right">
                    <span class="admin-name">欢迎，<?php echo htmlspecialchars($admin['username']); ?></span>
                </div>
            </header>
            
            <!-- 内容区 -->
            <div class="content">
                <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <?php if ($action === 'list'): ?>
                <!-- 配置列表 -->
                <div class="panel">
                    <div class="panel-header">
                        <h3>配置列表</h3>
                        <a href="?action=add" class="btn btn-primary btn-sm">添加配置</a>
                    </div>
                    <div class="panel-body">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>配置名称</th>
                                    <th>AccessKey ID</th>
                                    <th>地域</th>
                                    <th>流量使用</th>
                                    <th>状态</th>
                                    <th>默认</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($configs)): ?>
                                <tr>
                                    <td colspan="8" class="empty-data">暂无配置，请添加阿里云AccessKey配置</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($configs as $config): ?>
                                <?php 
                                    $trafficGB = $config['month_traffic'] / 1024 / 1024 / 1024;
                                    $maxTraffic = floatval($config['max_traffic_gb'] ?? 1000);
                                    $trafficPercent = $maxTraffic > 0 ? min(100, round(($trafficGB / $maxTraffic) * 100, 1)) : 0;
                                    $threshold = intval($config['alert_threshold'] ?? 80);
                                    $barColor = $trafficPercent >= $threshold ? '#ef4444' : ($trafficPercent >= $threshold * 0.8 ? '#f59e0b' : '#10b981');
                                ?>
                                <tr>
                                    <td><?php echo $config['id']; ?></td>
                                    <td><?php echo htmlspecialchars($config['name']); ?></td>
                                    <td><code><?php echo htmlspecialchars(substr($config['access_key_id'], 0, 8) . '****'); ?></code></td>
                                    <td><?php echo $regions[$config['region_id']] ?? $config['region_id']; ?></td>
                                    <td>
                                        <div style="min-width: 120px;">
                                            <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                                                <span><?php echo number_format($trafficGB, 2); ?> / <?php echo number_format($maxTraffic, 0); ?> GB</span>
                                                <span style="color: <?php echo $barColor; ?>; font-weight: 600;"><?php echo $trafficPercent; ?>%</span>
                                            </div>
                                            <div style="height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                                <div style="height: 100%; width: <?php echo $trafficPercent; ?>%; background: <?php echo $barColor; ?>; border-radius: 3px; transition: width 0.3s;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $config['status'] ? 'success' : 'danger'; ?>">
                                            <?php echo $config['status'] ? '启用' : '禁用'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($config['is_default']): ?>
                                        <span class="badge badge-primary">默认</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions">
                                        <a href="?action=test&id=<?php echo $config['id']; ?>" class="btn btn-sm btn-info" title="测试连接">测试</a>
                                        <a href="?action=edit&id=<?php echo $config['id']; ?>" class="btn btn-sm btn-warning" title="编辑">编辑</a>
                                        <a href="?action=delete&id=<?php echo $config['id']; ?>" class="btn btn-sm btn-danger" title="删除" onclick="return confirm('确定要删除此配置吗？')">删除</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <?php elseif ($action === 'add' || $action === 'edit'): ?>
                <!-- 添加/编辑表单 -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><?php echo $action === 'add' ? '添加配置' : '编辑配置'; ?></h3>
                    </div>
                    <div class="panel-body">
                        <form method="post" action="?action=<?php echo $action; ?><?php echo $id ? '&id=' . $id : ''; ?>">
                            <div class="form-group">
                                <label for="name">配置名称 <span class="required">*</span></label>
                                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($editConfig['name'] ?? ''); ?>" required placeholder="请输入配置名称">
                            </div>
                            
                            <div class="form-group">
                                <label for="access_key_id">AccessKey ID <span class="required">*</span></label>
                                <input type="text" id="access_key_id" name="access_key_id" value="<?php echo htmlspecialchars($editConfig['access_key_id'] ?? ''); ?>" required placeholder="请输入阿里云AccessKey ID">
                                <span class="hint">在阿里云控制台创建AccessKey获取</span>
                            </div>
                            
                            <div class="form-group">
                                <label for="access_key_secret">AccessKey Secret <?php echo $action === 'add' ? '<span class="required">*</span>' : ''; ?></label>
                                <input type="password" id="access_key_secret" name="access_key_secret" <?php echo $action === 'add' ? 'required' : ''; ?> placeholder="<?php echo $action === 'edit' ? '留空则不修改' : '请输入阿里云AccessKey Secret'; ?>">
                                <span class="hint">请妥善保管AccessKey Secret，系统将加密存储</span>
                            </div>
                            
                            <div class="form-group">
                                <label for="region_id">地域</label>
                                <select id="region_id" name="region_id">
                                    <?php foreach ($regions as $code => $name): ?>
                                    <option value="<?php echo $code; ?>" <?php echo ($editConfig['region_id'] ?? 'cn-hangzhou') === $code ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="status">状态</label>
                                    <select id="status" name="status">
                                        <option value="1" <?php echo ($editConfig['status'] ?? 1) ? 'selected' : ''; ?>>启用</option>
                                        <option value="0" <?php echo isset($editConfig['status']) && !$editConfig['status'] ? 'selected' : ''; ?>>禁用</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="is_default">设为默认</label>
                                    <select id="is_default" name="is_default">
                                        <option value="0" <?php echo !($editConfig['is_default'] ?? 0) ? 'selected' : ''; ?>>否</option>
                                        <option value="1" <?php echo ($editConfig['is_default'] ?? 0) ? 'selected' : ''; ?>>是</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="remark">备注</label>
                                <textarea id="remark" name="remark" rows="3" placeholder="请输入备注信息"><?php echo htmlspecialchars($editConfig['remark'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="max_traffic_gb">最大流量(GB) <span class="required">*</span></label>
                                    <input type="number" id="max_traffic_gb" name="max_traffic_gb" value="<?php echo htmlspecialchars($editConfig['max_traffic_gb'] ?? '200'); ?>" min="1" step="0.01" required>
                                    <span class="hint">该AccessKey的每月流量限制</span>
                                </div>
                                <div class="form-group">
                                    <label for="alert_threshold">提醒阈值(%) <span class="required">*</span></label>
                                    <input type="number" id="alert_threshold" name="alert_threshold" value="<?php echo htmlspecialchars($editConfig['alert_threshold'] ?? '80'); ?>" min="1" max="100" required>
                                    <span class="hint">流量达到此百分比时发送邮件提醒</span>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="shutdown_threshold">关机阈值(%) <span class="required">*</span></label>
                                    <input type="number" id="shutdown_threshold" name="shutdown_threshold" value="<?php echo htmlspecialchars($editConfig['shutdown_threshold'] ?? '95'); ?>" min="1" max="100" required>
                                    <span class="hint">流量达到此百分比时自动关机</span>
                                </div>
                                <div class="form-group">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-top: 24px;">
                                        <input type="checkbox" id="auto_shutdown" name="auto_shutdown" value="1" <?php echo !empty($editConfig['auto_shutdown']) ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                                        <span>启用自动关机</span>
                                    </label>
                                    <span class="hint">开启后，流量达到关机阈值时自动关闭ECS实例</span>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="auto_start_day">定时开机日期</label>
                                    <select id="auto_start_day" name="auto_start_day">
                                        <?php for ($d = 1; $d <= 31; $d++): ?>
                                        <option value="<?php echo $d; ?>" <?php echo intval($editConfig['auto_start_day'] ?? 1) === $d ? 'selected' : ''; ?>>
                                            每月<?php echo $d; ?>日
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="hint">每月几号自动开机</span>
                                </div>
                                <div class="form-group">
                                    <label for="auto_start_hour">定时开机时间</label>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <select id="auto_start_hour" name="auto_start_hour" style="width: 60px; padding: 8px 4px; text-align: center;">
                                            <?php for ($h = 0; $h <= 23; $h++): ?>
                                            <option value="<?php echo $h; ?>" <?php echo intval($editConfig['auto_start_hour'] ?? 0) === $h ? 'selected' : ''; ?>>
                                                <?php echo str_pad($h, 2, '0', STR_PAD_LEFT); ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                        <span>时</span>
                                        <select id="auto_start_minute" name="auto_start_minute" style="width: 60px; padding: 8px 4px; text-align: center;">
                                            <?php for ($m = 0; $m <= 59; $m++): ?>
                                            <option value="<?php echo $m; ?>" <?php echo intval($editConfig['auto_start_minute'] ?? 0) === $m ? 'selected' : ''; ?>>
                                                <?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                        <span>分</span>
                                    </div>
                                    <span class="hint">自动关机后，到设定时间自动开机</span>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">保存</button>
                                <a href="?action=list" class="btn btn-secondary">取消</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
