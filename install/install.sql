-- 阿里云流量查询系统 - 数据库结构
-- 兼容 MySQL 5.6.50+
-- 字符集: utf8mb4

-- 设置字符集
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 系统配置表
-- ----------------------------
DROP TABLE IF EXISTS `at_system_config`;
CREATE TABLE `at_system_config` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `config_key` VARCHAR(50) NOT NULL COMMENT '配置键名',
  `config_value` TEXT COMMENT '配置值',
  `config_desc` VARCHAR(255) DEFAULT NULL COMMENT '配置描述',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表';

-- ----------------------------
-- 管理员表
-- ----------------------------
DROP TABLE IF EXISTS `at_admin_users`;
CREATE TABLE `at_admin_users` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `username` VARCHAR(50) NOT NULL COMMENT '用户名',
  `password` VARCHAR(255) NOT NULL COMMENT '密码(加密)',
  `email` VARCHAR(100) DEFAULT NULL COMMENT '邮箱',
  `nickname` VARCHAR(50) DEFAULT NULL COMMENT '昵称',
  `avatar` VARCHAR(255) DEFAULT NULL COMMENT '头像',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态: 1启用 0禁用',
  `login_ip` VARCHAR(50) DEFAULT NULL COMMENT '最后登录IP',
  `login_time` DATETIME DEFAULT NULL COMMENT '最后登录时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表';

-- ----------------------------
-- 阿里云配置表
-- ----------------------------
DROP TABLE IF EXISTS `at_aliyun_config`;
CREATE TABLE `at_aliyun_config` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name` VARCHAR(100) NOT NULL COMMENT '配置名称',
  `access_key_id` VARCHAR(100) NOT NULL COMMENT 'AccessKey ID',
  `access_key_secret` VARCHAR(255) NOT NULL COMMENT 'AccessKey Secret(加密)',
  `region_id` VARCHAR(50) NOT NULL DEFAULT 'cn-hangzhou' COMMENT '地域ID',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态: 1启用 0禁用',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否默认: 1是 0否',
  `remark` VARCHAR(255) DEFAULT NULL COMMENT '备注',
  `max_traffic_gb` DECIMAL(10,2) DEFAULT 200.00 COMMENT '最大流量限制(GB)',
  `alert_threshold` TINYINT(3) DEFAULT 80 COMMENT '流量提醒阈值(%)',
  `shutdown_threshold` TINYINT(3) DEFAULT 95 COMMENT '自动关机阈值(%)',
  `auto_shutdown` TINYINT(1) DEFAULT 0 COMMENT '是否自动关机: 1是 0否',
  `auto_start_day` TINYINT(2) DEFAULT 1 COMMENT '自动开机日期(每月几号1-31)',
  `auto_start_hour` TINYINT(2) DEFAULT 0 COMMENT '自动开机小时(0-23)',
  `auto_start_minute` TINYINT(2) DEFAULT 0 COMMENT '自动开机分钟(0-59)',
  `last_auto_shutdown` DATETIME DEFAULT NULL COMMENT '最后自动关机时间',
  `last_auto_start` DATETIME DEFAULT NULL COMMENT '最后自动开机时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='阿里云配置表';

-- ----------------------------
-- 流量记录表
-- ----------------------------
DROP TABLE IF EXISTS `at_traffic_records`;
CREATE TABLE `at_traffic_records` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `config_id` INT(11) UNSIGNED NOT NULL COMMENT '阿里云配置ID',
  `instance_id` VARCHAR(100) DEFAULT NULL COMMENT '实例ID',
  `instance_name` VARCHAR(255) DEFAULT NULL COMMENT '实例名称',
  `instance_type` VARCHAR(50) DEFAULT NULL COMMENT '实例类型',
  `region_id` VARCHAR(50) DEFAULT NULL COMMENT '地域ID',
  `traffic_in` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '入流量(字节)',
  `traffic_out` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '出流量(字节)',
  `traffic_total` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '总流量(字节)',
  `bandwidth_in` DECIMAL(10,2) UNSIGNED DEFAULT 0.00 COMMENT '入带宽(Mbps)',
  `bandwidth_out` DECIMAL(10,2) UNSIGNED DEFAULT 0.00 COMMENT '出带宽(Mbps)',
  `record_date` DATE NOT NULL COMMENT '记录日期',
  `record_hour` TINYINT(2) DEFAULT NULL COMMENT '记录小时(0-23)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_config_id` (`config_id`),
  KEY `idx_instance_id` (`instance_id`),
  KEY `idx_record_date` (`record_date`),
  KEY `idx_date_instance` (`record_date`, `instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='流量记录表';

-- ----------------------------
-- 操作日志表
-- ----------------------------
DROP TABLE IF EXISTS `at_operation_logs`;
CREATE TABLE `at_operation_logs` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `admin_id` INT(11) UNSIGNED DEFAULT NULL COMMENT '管理员ID',
  `username` VARCHAR(50) DEFAULT NULL COMMENT '用户名',
  `action` VARCHAR(100) NOT NULL COMMENT '操作动作',
  `module` VARCHAR(50) DEFAULT NULL COMMENT '模块名称',
  `content` TEXT COMMENT '操作内容',
  `ip` VARCHAR(50) DEFAULT NULL COMMENT 'IP地址',
  `user_agent` VARCHAR(255) DEFAULT NULL COMMENT '用户代理',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志表';

-- ----------------------------
-- 邮箱配置表
-- ----------------------------
DROP TABLE IF EXISTS `at_mail_config`;
CREATE TABLE `at_mail_config` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱配置表';

-- ----------------------------
-- 邮件模板表
-- ----------------------------
DROP TABLE IF EXISTS `at_mail_template`;
CREATE TABLE `at_mail_template` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件模板表';

-- ----------------------------
-- 安装锁表
-- ----------------------------
DROP TABLE IF EXISTS `at_install_lock`;
CREATE TABLE `at_install_lock` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `lock_key` VARCHAR(50) NOT NULL COMMENT '锁键名',
  `lock_value` VARCHAR(255) NOT NULL COMMENT '锁值',
  `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '安装时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lock_key` (`lock_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安装锁表';

-- ----------------------------
-- 初始数据
-- ----------------------------

-- 插入默认系统配置
INSERT INTO `at_system_config` (`config_key`, `config_value`, `config_desc`) VALUES
('site_name', '阿里云流量查询系统', '网站名称'),
('site_description', '云数据传输流量监控平台', '网站描述'),
('cache_enabled', '1', '是否启用缓存'),
('cache_ttl', '300', '缓存时间(秒)'),
('refresh_interval', '60', '前端刷新间隔(秒)'),
('timezone', 'Asia/Shanghai', '时区设置');

-- 插入默认邮件模板
INSERT INTO `at_mail_template` (`template_key`, `template_name`, `subject`, `body`, `variables`, `status`) VALUES
('traffic_alert', '流量提醒模板', '【流量提醒】{config_name} 流量已达 {percent}%', '<!DOCTYPE html>
<html>
<head><meta charset=\"UTF-8\"></head>
<body style=\"font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;\">
<div style=\"max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);\">
<h2 style=\"color: #f59e0b; margin-top: 0;\">⚠️ 流量使用提醒</h2>
<p>您好，</p>
<p>您的阿里云配置 <strong>{config_name}</strong> 流量使用已达到预设阈值，请注意控制流量使用。</p>
<table style=\"width: 100%; border-collapse: collapse; margin: 20px 0;\">
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">配置名称</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;\">{config_name}</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">当前使用流量</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;\">{traffic_used} GB</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">最大流量限制</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;\">{traffic_max} GB</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">使用比例</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right; color: #f59e0b;\">{percent}%</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">提醒阈值</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; text-align: right;\">{threshold}%</td></tr>
<tr><td style=\"padding: 10px; color: #666;\">提醒时间</td><td style=\"padding: 10px; text-align: right;\">{alert_time}</td></tr>
</table>
<p style=\"color: #999; font-size: 12px;\">此邮件由系统自动发送，请勿回复。</p>
</div>
</body>
</html>', '[\"config_name\", \"traffic_used\", \"traffic_max\", \"percent\", \"threshold\", \"alert_time\"]', 1),
('auto_shutdown', '自动关机模板', '【紧急通知】{config_name} 流量已达 {percent}% 已自动关机', '<!DOCTYPE html>
<html>
<head><meta charset=\"UTF-8\"></head>
<body style=\"font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;\">
<div style=\"max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);\">
<h2 style=\"color: #ef4444; margin-top: 0;\">🚨 自动关机通知</h2>
<p>您好，</p>
<p>您的阿里云配置 <strong>{config_name}</strong> 流量已达到自动关机阈值，系统已自动关闭该账号下的所有ECS实例，避免超出流量收费。</p>
<table style=\"width: 100%; border-collapse: collapse; margin: 20px 0;\">
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">配置名称</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;\">{config_name}</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">当前使用流量</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;\">{traffic_used} GB</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">最大流量限制</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;\">{traffic_max} GB</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">使用比例</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right; color: #ef4444;\">{percent}%</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">关机阈值</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; text-align: right;\">{shutdown_threshold}%</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">已关闭实例</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; text-align: right;\">{shutdown_count} 台</td></tr>
<tr><td style=\"padding: 10px; border-bottom: 1px solid #eee; color: #666;\">关机时间</td><td style=\"padding: 10px; border-bottom: 1px solid #eee; text-align: right;\">{shutdown_time}</td></tr>
<tr><td style=\"padding: 10px; color: #666;\">定时开机时间</td><td style=\"padding: 10px; text-align: right;\">每月{auto_start_day}日 {auto_start_hour}:{auto_start_minute}</td></tr>
</table>
<p style=\"color: #666; background: #fef3cd; padding: 15px; border-radius: 8px;\">如需提前开机，请登录管理后台手动操作。</p>
<p style=\"color: #999; font-size: 12px; margin-top: 20px;\">此邮件由系统自动发送，请勿回复。</p>
</div>
</body>
</html>', '[\"config_name\", \"traffic_used\", \"traffic_max\", \"percent\", \"shutdown_threshold\", \"shutdown_count\", \"shutdown_time\", \"auto_start_day\", \"auto_start_hour\", \"auto_start_minute\"]', 1);

SET FOREIGN_KEY_CHECKS = 1;
