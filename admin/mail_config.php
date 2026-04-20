<?php
/**
 * 管理员后台 - 邮箱配置与模板管理
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Mailer.php';

$admin = getCurrentAdmin();
$db = getDb();
$prefix = $db->getPrefix();

$checkTable = $db->fetchOne("SHOW TABLES LIKE '{$prefix}mail_config'");
if (!$checkTable) {
    $db->query("CREATE TABLE `{$prefix}mail_config` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
        `config_name` VARCHAR(50) NOT NULL COMMENT '配置名称',
        `smtp_host` VARCHAR(100) NOT NULL COMMENT 'SMTP服务器地址',
        `smtp_port` INT(5) NOT NULL DEFAULT 465 COMMENT 'SMTP端口',
        `smtp_encryption` VARCHAR(10) DEFAULT 'ssl' COMMENT '加密方式: ssl/tls/none',
        `smtp_username` VARCHAR(100) NOT NULL COMMENT 'SMTP用户名',
        `smtp_password` VARCHAR(255) NOT NULL COMMENT 'SMTP密码(加密存储)',
        `from_email` VARCHAR(100) NOT NULL COMMENT '发件人邮箱',
        `from_name` VARCHAR(100) DEFAULT NULL COMMENT '发件人名称',
        `to_emails` TEXT COMMENT '收件人邮箱(多个用逗号分隔)',
        `is_default` TINYINT(1) DEFAULT 0 COMMENT '是否默认: 1是 0否',
        `status` TINYINT(1) DEFAULT 1 COMMENT '状态: 1启用 0禁用',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱配置表'");
}

$checkTemplateTable = $db->fetchOne("SHOW TABLES LIKE '{$prefix}mail_template'");
if (!$checkTemplateTable) {
    $db->query("CREATE TABLE `{$prefix}mail_template` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
        `template_key` VARCHAR(50) NOT NULL COMMENT '模板标识',
        `template_name` VARCHAR(100) NOT NULL COMMENT '模板名称',
        `subject` VARCHAR(255) NOT NULL COMMENT '邮件主题',
        `body` TEXT NOT NULL COMMENT '邮件正文(HTML)',
        `variables` TEXT COMMENT '可用变量说明(JSON)',
        `status` TINYINT(1) DEFAULT 1 COMMENT '状态: 1启用 0禁用',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_template_key` (`template_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件模板表'");
    
    $defaultTemplates = [
        [
            'key' => 'traffic_alert',
            'name' => '流量提醒模板',
            'subject' => '【流量提醒】{config_name} 流量已达 {percent}%',
            'body' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;">
<div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
<h2 style="color: #f59e0b; margin-top: 0;">⚠️ 流量使用提醒</h2>
<p>您好，</p>
<p>您的阿里云配置 <strong>{config_name}</strong> 流量使用已达到预设阈值，请注意控制流量使用。</p>
<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">配置名称</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;">{config_name}</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">当前使用流量</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;">{traffic_used} GB</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">最大流量限制</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;">{traffic_max} GB</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">使用比例</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right; color: #f59e0b;">{percent}%</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">提醒阈值</td><td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right;">{threshold}%</td></tr>
<tr><td style="padding: 10px; color: #666;">提醒时间</td><td style="padding: 10px; text-align: right;">{alert_time}</td></tr>
</table>
<p style="color: #999; font-size: 12px;">此邮件由系统自动发送，请勿回复。</p>
</div>
</body>
</html>',
            'variables' => '["config_name", "traffic_used", "traffic_max", "percent", "threshold", "alert_time"]'
        ],
        [
            'key' => 'auto_shutdown',
            'name' => '自动关机模板',
            'subject' => '【紧急通知】{config_name} 流量已达 {percent}% 已自动关机',
            'body' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;">
<div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
<h2 style="color: #ef4444; margin-top: 0;">🚨 自动关机通知</h2>
<p>您好，</p>
<p>您的阿里云配置 <strong>{config_name}</strong> 流量已达到自动关机阈值，系统已自动关闭该账号下的所有ECS实例，避免超出流量收费。</p>
<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">配置名称</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;">{config_name}</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">当前使用流量</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;">{traffic_used} GB</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">最大流量限制</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;">{traffic_max} GB</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">使用比例</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right; color: #ef4444;">{percent}%</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">关机阈值</td><td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right;">{shutdown_threshold}%</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">已关闭实例</td><td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right;">{shutdown_count} 台</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">关机时间</td><td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right;">{shutdown_time}</td></tr>
<tr><td style="padding: 10px; color: #666;">定时开机时间</td><td style="padding: 10px; text-align: right;">每月{auto_start_day}日 {auto_start_hour}:{auto_start_minute}</td></tr>
</table>
<p style="color: #666; background: #fef3cd; padding: 15px; border-radius: 8px;">如需提前开机，请登录管理后台手动操作。</p>
<p style="color: #999; font-size: 12px; margin-top: 20px;">此邮件由系统自动发送，请勿回复。</p>
</div>
</body>
</html>',
            'variables' => '["config_name", "traffic_used", "traffic_max", "percent", "shutdown_threshold", "shutdown_count", "shutdown_time", "auto_start_day", "auto_start_hour", "auto_start_minute"]'
        ]
    ];
    
    foreach ($defaultTemplates as $tpl) {
        $sql = "INSERT INTO `{$prefix}mail_template` (`template_key`, `template_name`, `subject`, `body`, `variables`, `status`, `created_at`) 
                VALUES (?, ?, ?, ?, ?, 1, NOW())";
        $db->query($sql, [$tpl['key'], $tpl['name'], $tpl['subject'], $tpl['body'], $tpl['variables']]);
    }
}

$tab = $_GET['tab'] ?? 'config';
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$error = '';
$success = '';

if ($tab === 'config') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['config_submit'])) {
        $configName = trim($_POST['config_name'] ?? '');
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = intval($_POST['smtp_port'] ?? 465);
        $smtpEncryption = trim($_POST['smtp_encryption'] ?? 'ssl');
        $smtpUsername = trim($_POST['smtp_username'] ?? '');
        $smtpPassword = $_POST['smtp_password'] ?? '';
        $fromEmail = trim($_POST['from_email'] ?? '');
        $fromName = trim($_POST['from_name'] ?? '');
        $toEmails = trim($_POST['to_emails'] ?? '');
        $isDefault = intval($_POST['is_default'] ?? 0);
        $status = intval($_POST['status'] ?? 1);
        
        if (empty($configName) || empty($smtpHost) || empty($smtpUsername) || empty($fromEmail)) {
            $error = '请填写必填项';
        } else {
            if ($isDefault) {
                $db->query("UPDATE `{$prefix}mail_config` SET `is_default` = 0");
            }
            
            if ($action === 'add') {
                $encryptedPassword = Helper::encrypt($smtpPassword);
                
                $sql = "INSERT INTO `{$prefix}mail_config` 
                        (`config_name`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_username`, `smtp_password`, `from_email`, `from_name`, `to_emails`, `is_default`, `status`, `created_at`) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $db->query($sql, [$configName, $smtpHost, $smtpPort, $smtpEncryption, $smtpUsername, $encryptedPassword, $fromEmail, $fromName, $toEmails, $isDefault, $status]);
                
                logOperation('add_mail_config', 'mail', '添加邮箱配置: ' . $configName);
                $success = '邮箱配置添加成功';
                
            } elseif ($action === 'edit' && $id > 0) {
                $sql = "UPDATE `{$prefix}mail_config` 
                        SET `config_name` = ?, `smtp_host` = ?, `smtp_port` = ?, `smtp_encryption` = ?, `smtp_username` = ?, `from_email` = ?, `from_name` = ?, `to_emails` = ?, `is_default` = ?, `status` = ?, `updated_at` = NOW() 
                        WHERE `id` = ?";
                
                $params = [$configName, $smtpHost, $smtpPort, $smtpEncryption, $smtpUsername, $fromEmail, $fromName, $toEmails, $isDefault, $status, $id];
                
                if (!empty($smtpPassword)) {
                    $encryptedPassword = Helper::encrypt($smtpPassword);
                    $sql = "UPDATE `{$prefix}mail_config` 
                            SET `config_name` = ?, `smtp_host` = ?, `smtp_port` = ?, `smtp_encryption` = ?, `smtp_username` = ?, `smtp_password` = ?, `from_email` = ?, `from_name` = ?, `to_emails` = ?, `is_default` = ?, `status` = ?, `updated_at` = NOW() 
                            WHERE `id` = ?";
                    $params = [$configName, $smtpHost, $smtpPort, $smtpEncryption, $smtpUsername, $encryptedPassword, $fromEmail, $fromName, $toEmails, $isDefault, $status, $id];
                }
                
                $db->query($sql, $params);
                
                logOperation('edit_mail_config', 'mail', '编辑邮箱配置: ' . $configName);
                $success = '邮箱配置更新成功';
            }
            
            $action = 'list';
        }
    }
    
    if ($action === 'delete' && $id > 0) {
        $config = $db->fetchOne("SELECT * FROM `{$prefix}mail_config` WHERE `id` = ?", [$id]);
        
        if ($config) {
            $db->query("DELETE FROM `{$prefix}mail_config` WHERE `id` = ?", [$id]);
            logOperation('delete_mail_config', 'mail', '删除邮箱配置: ' . $config['config_name']);
            $success = '邮箱配置删除成功';
        }
        
        $action = 'list';
    }
    
    if ($action === 'test' && $id > 0) {
        $config = $db->fetchOne("SELECT * FROM `{$prefix}mail_config` WHERE `id` = ?", [$id]);
        
        if ($config) {
            $password = Helper::decrypt($config['smtp_password']);
            $mailer = new Mailer(
                $config['smtp_host'],
                $config['smtp_port'],
                $config['smtp_username'],
                $password,
                $config['smtp_encryption'],
                $config['from_email']
            );
            
            $testEmail = $config['to_emails'] ? explode(',', $config['to_emails'])[0] : $config['from_email'];
            $result = $mailer->send($testEmail, '【测试邮件】邮箱配置测试', '<h2>邮箱配置测试成功</h2><p>这是一封测试邮件，发送时间：' . date('Y-m-d H:i:s') . '</p>', $config['from_name'] ?: '阿里云流量监控系统');
            
            if ($result) {
                $success = '测试邮件发送成功，请检查收件箱';
            } else {
                $error = '测试邮件发送失败: ' . $mailer->getLastError();
            }
        }
        
        $action = 'list';
    }
}

if ($tab === 'template') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['template_submit'])) {
        $templateName = trim($_POST['template_name'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = $_POST['body'] ?? '';
        $status = intval($_POST['status'] ?? 1);
        
        if (empty($templateName) || empty($subject) || empty($body)) {
            $error = '请填写必填项';
        } else {
            if ($action === 'edit' && $id > 0) {
                $sql = "UPDATE `{$prefix}mail_template` 
                        SET `template_name` = ?, `subject` = ?, `body` = ?, `status` = ?, `updated_at` = NOW() 
                        WHERE `id` = ?";
                
                $db->query($sql, [$templateName, $subject, $body, $status, $id]);
                
                logOperation('edit_mail_template', 'mail', '编辑邮件模板: ' . $templateName);
                $success = '邮件模板更新成功';
            }
            
            $action = 'list';
        }
    }
}

$configs = $db->fetchAll("SELECT * FROM `{$prefix}mail_config` ORDER BY `is_default` DESC, `id` DESC");
$templates = $db->fetchAll("SELECT * FROM `{$prefix}mail_template` ORDER BY `id` ASC");

$editConfig = null;
$editTemplate = null;

if ($tab === 'config' && $action === 'edit' && $id > 0) {
    $editConfig = $db->fetchOne("SELECT * FROM `{$prefix}mail_config` WHERE `id` = ?", [$id]);
}

if ($tab === 'template' && $action === 'edit' && $id > 0) {
    $editTemplate = $db->fetchOne("SELECT * FROM `{$prefix}mail_template` WHERE `id` = ?", [$id]);
}

$encryptionOptions = ['ssl' => 'SSL', 'tls' => 'TLS', 'none' => '无加密'];

$variableDescriptions = [
    'config_name' => '配置名称',
    'traffic_used' => '已使用流量(GB)',
    'traffic_max' => '最大流量(GB)',
    'percent' => '使用百分比',
    'threshold' => '提醒阈值(%)',
    'shutdown_threshold' => '关机阈值(%)',
    'alert_time' => '提醒时间',
    'shutdown_time' => '关机时间',
    'shutdown_count' => '已关闭实例数',
    'auto_start_day' => '定时开机日期',
    'auto_start_hour' => '定时开机小时',
    'auto_start_minute' => '定时开机分钟'
];

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮箱配置 - 阿里云流量查询系统</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .tabs-container {
            margin-bottom: 20px;
        }
        .tabs-nav {
            display: flex;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 20px;
        }
        .tab-btn {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 15px;
            color: #6b7280;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }
        .tab-btn:hover {
            color: #3b82f6;
        }
        .tab-btn.active {
            color: #3b82f6;
            border-bottom-color: #3b82f6;
            font-weight: 600;
        }
        .template-editor {
            width: 100%;
            min-height: 400px;
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
            line-height: 1.5;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            resize: vertical;
        }
        .variable-list {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .variable-list h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #333;
        }
        .variable-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .variable-tag {
            display: inline-flex;
            align-items: center;
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-family: Consolas, Monaco, monospace;
            cursor: pointer;
        }
        .variable-tag span {
            color: #666;
            margin-left: 6px;
            font-family: Arial, sans-serif;
        }
        .template-preview {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 20px;
            margin-top: 20px;
        }
        .template-preview h4 {
            margin: 0 0 15px 0;
            font-size: 14px;
            color: #333;
        }
        .preview-frame {
            width: 100%;
            min-height: 300px;
            border: 1px solid #eee;
            border-radius: 4px;
            background: #f5f5f5;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php $currentPage = 'mail_config'; include __DIR__ . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <h1>邮箱配置</h1>
                </div>
                <div class="topbar-right">
                    <span class="admin-name">欢迎，<?php echo htmlspecialchars($admin['username']); ?></span>
                </div>
            </header>
            
            <div class="content">
                <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <div class="tabs-container">
                    <div class="tabs-nav">
                        <a href="?tab=config" class="tab-btn <?php echo $tab === 'config' ? 'active' : ''; ?>">邮箱配置</a>
                        <a href="?tab=template" class="tab-btn <?php echo $tab === 'template' ? 'active' : ''; ?>">邮件模板</a>
                    </div>
                </div>
                
                <?php if ($tab === 'config'): ?>
                
                <?php if ($action === 'list'): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h3>邮箱配置列表</h3>
                        <a href="?tab=config&action=add" class="btn btn-primary btn-sm">添加配置</a>
                    </div>
                    <div class="panel-body">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>配置名称</th>
                                    <th>SMTP服务器</th>
                                    <th>发件人邮箱</th>
                                    <th>收件人邮箱</th>
                                    <th>状态</th>
                                    <th>默认</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($configs)): ?>
                                <tr>
                                    <td colspan="8" class="empty-data">暂无邮箱配置，请添加SMTP邮箱配置</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($configs as $config): ?>
                                <tr>
                                    <td><?php echo $config['id']; ?></td>
                                    <td><?php echo htmlspecialchars($config['config_name']); ?></td>
                                    <td><?php echo htmlspecialchars($config['smtp_host']); ?>:<?php echo $config['smtp_port']; ?></td>
                                    <td><?php echo htmlspecialchars($config['from_email']); ?></td>
                                    <td><?php echo htmlspecialchars($config['to_emails'] ?: '-'); ?></td>
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
                                        <a href="?tab=config&action=test&id=<?php echo $config['id']; ?>" class="btn btn-sm btn-info" title="发送测试邮件">测试</a>
                                        <a href="?tab=config&action=edit&id=<?php echo $config['id']; ?>" class="btn btn-sm btn-warning" title="编辑">编辑</a>
                                        <a href="?tab=config&action=delete&id=<?php echo $config['id']; ?>" class="btn btn-sm btn-danger" title="删除" onclick="return confirm('确定要删除此邮箱配置吗？')">删除</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <?php elseif ($action === 'add' || $action === 'edit'): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h3><?php echo $action === 'add' ? '添加邮箱配置' : '编辑邮箱配置'; ?></h3>
                    </div>
                    <div class="panel-body">
                        <form method="post" action="?tab=config&action=<?php echo $action; ?><?php echo $id ? '&id=' . $id : ''; ?>">
                            <input type="hidden" name="config_submit" value="1">
                            <div class="form-group">
                                <label for="config_name">配置名称 <span class="required">*</span></label>
                                <input type="text" id="config_name" name="config_name" value="<?php echo htmlspecialchars($editConfig['config_name'] ?? ''); ?>" required placeholder="例如：QQ邮箱、阿里企业邮箱">
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="smtp_host">SMTP服务器 <span class="required">*</span></label>
                                    <input type="text" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($editConfig['smtp_host'] ?? ''); ?>" required placeholder="例如：smtp.qq.com">
                                </div>
                                <div class="form-group">
                                    <label for="smtp_port">端口 <span class="required">*</span></label>
                                    <input type="number" id="smtp_port" name="smtp_port" value="<?php echo htmlspecialchars($editConfig['smtp_port'] ?? '465'); ?>" min="1" max="65535" required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="smtp_encryption">加密方式</label>
                                    <select id="smtp_encryption" name="smtp_encryption">
                                        <?php foreach ($encryptionOptions as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo ($editConfig['smtp_encryption'] ?? 'ssl') === $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="smtp_username">SMTP用户名 <span class="required">*</span></label>
                                    <input type="text" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($editConfig['smtp_username'] ?? ''); ?>" required placeholder="通常是邮箱地址">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="smtp_password">SMTP密码 <?php echo $action === 'add' ? '<span class="required">*</span>' : ''; ?></label>
                                <input type="password" id="smtp_password" name="smtp_password" <?php echo $action === 'add' ? 'required' : ''; ?> placeholder="<?php echo $action === 'edit' ? '留空则不修改' : '请输入SMTP密码或授权码'; ?>">
                                <span class="hint">部分邮箱需要使用授权码而非登录密码</span>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="from_email">发件人邮箱 <span class="required">*</span></label>
                                    <input type="email" id="from_email" name="from_email" value="<?php echo htmlspecialchars($editConfig['from_email'] ?? ''); ?>" required placeholder="发件人邮箱地址">
                                </div>
                                <div class="form-group">
                                    <label for="from_name">发件人名称</label>
                                    <input type="text" id="from_name" name="from_name" value="<?php echo htmlspecialchars($editConfig['from_name'] ?? ''); ?>" placeholder="例如：阿里云流量监控系统">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="to_emails">收件人邮箱</label>
                                <input type="text" id="to_emails" name="to_emails" value="<?php echo htmlspecialchars($editConfig['to_emails'] ?? ''); ?>" placeholder="多个收件人用逗号分隔">
                                <span class="hint">接收告警邮件的邮箱地址，多个用逗号分隔</span>
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
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">保存</button>
                                <a href="?tab=config" class="btn btn-secondary">取消</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php elseif ($tab === 'template'): ?>
                
                <?php if ($action === 'list'): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h3>邮件模板列表</h3>
                    </div>
                    <div class="panel-body">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>模板标识</th>
                                    <th>模板名称</th>
                                    <th>邮件主题</th>
                                    <th>状态</th>
                                    <th>更新时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($templates)): ?>
                                <tr>
                                    <td colspan="7" class="empty-data">暂无邮件模板</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($templates as $tpl): ?>
                                <tr>
                                    <td><?php echo $tpl['id']; ?></td>
                                    <td><code><?php echo htmlspecialchars($tpl['template_key']); ?></code></td>
                                    <td><?php echo htmlspecialchars($tpl['template_name']); ?></td>
                                    <td><?php echo htmlspecialchars($tpl['subject']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $tpl['status'] ? 'success' : 'danger'; ?>">
                                            <?php echo $tpl['status'] ? '启用' : '禁用'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $tpl['updated_at']; ?></td>
                                    <td class="actions">
                                        <a href="?tab=template&action=edit&id=<?php echo $tpl['id']; ?>" class="btn btn-sm btn-warning" title="编辑">编辑</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <?php elseif ($action === 'edit' && $editTemplate): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h3>编辑邮件模板 - <?php echo htmlspecialchars($editTemplate['template_name']); ?></h3>
                    </div>
                    <div class="panel-body">
                        <form method="post" action="?tab=template&action=edit&id=<?php echo $id; ?>">
                            <input type="hidden" name="template_submit" value="1">
                            <div class="form-group">
                                <label for="template_name">模板名称</label>
                                <input type="text" id="template_name" name="template_name" value="<?php echo htmlspecialchars($editTemplate['template_name']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="subject">邮件主题 <span class="required">*</span></label>
                                <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($editTemplate['subject']); ?>" required>
                            </div>
                            
                            <?php
                            $variables = json_decode($editTemplate['variables'] ?? '[]', true);
                            if (!empty($variables)):
                            ?>
                            <div class="variable-list">
                                <h4>可用变量（点击复制）</h4>
                                <div class="variable-tags">
                                    <?php foreach ($variables as $var): ?>
                                    <span class="variable-tag" onclick="copyVariable('{<?php echo $var; ?>}')">
                                        {<?php echo $var; ?>}
                                        <span><?php echo $variableDescriptions[$var] ?? $var; ?></span>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="body">邮件正文(HTML) <span class="required">*</span></label>
                                <textarea id="body" name="body" class="template-editor" required><?php echo htmlspecialchars($editTemplate['body']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="tpl_status">状态</label>
                                <select id="tpl_status" name="status">
                                    <option value="1" <?php echo $editTemplate['status'] ? 'selected' : ''; ?>>启用</option>
                                    <option value="0" <?php echo !$editTemplate['status'] ? 'selected' : ''; ?>>禁用</option>
                                </select>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">保存</button>
                                <button type="button" class="btn btn-info" onclick="previewTemplate()">预览</button>
                                <a href="?tab=template" class="btn btn-secondary">取消</a>
                            </div>
                        </form>
                        
                        <div class="template-preview" id="previewContainer" style="display: none;">
                            <h4>邮件预览</h4>
                            <iframe id="previewFrame" class="preview-frame"></iframe>
                        </div>
                    </div>
                </div>
                
                <script>
                function copyVariable(text) {
                    navigator.clipboard.writeText(text).then(function() {
                        alert('已复制: ' + text);
                    });
                }
                
                function previewTemplate() {
                    var body = document.getElementById('body').value;
                    var previewFrame = document.getElementById('previewFrame');
                    var previewContainer = document.getElementById('previewContainer');
                    
                    var sampleData = {
                        '{config_name}': '示例配置',
                        '{traffic_used}': '150.50',
                        '{traffic_max}': '200',
                        '{percent}': '75.25',
                        '{threshold}': '80',
                        '{shutdown_threshold}': '95',
                        '{alert_time}': '<?php echo date('Y-m-d H:i:s'); ?>',
                        '{shutdown_time}': '<?php echo date('Y-m-d H:i:s'); ?>',
                        '{shutdown_count}': '3',
                        '{auto_start_day}': '1',
                        '{auto_start_hour}': '00',
                        '{auto_start_minute}': '00'
                    };
                    
                    var previewHtml = body;
                    for (var key in sampleData) {
                        previewHtml = previewHtml.split(key).join(sampleData[key]);
                    }
                    
                    var doc = previewFrame.contentDocument || previewFrame.contentWindow.document;
                    doc.open();
                    doc.write(previewHtml);
                    doc.close();
                    
                    previewContainer.style.display = 'block';
                }
                </script>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
