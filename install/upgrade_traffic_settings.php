<?php
/**
 * 数据库升级脚本 - 添加独立流量设置
 * 为每个阿里云配置添加独立的流量限制和提醒阈值
 */

define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Helper.php';

$config = require ROOT_PATH . 'config' . DIRECTORY_SEPARATOR . 'database.php';

try {
    $db = Database::getInstance($config);
    $prefix = $db->getPrefix();
    
    $sql = "SHOW COLUMNS FROM `{$prefix}aliyun_config` LIKE 'max_traffic_gb'";
    $result = $db->fetchOne($sql);
    
    if (!$result) {
        $alterSql = "ALTER TABLE `{$prefix}aliyun_config` 
                     ADD COLUMN `max_traffic_gb` DECIMAL(10,2) DEFAULT 1000.00 COMMENT '最大流量限制(GB)' AFTER `remark`,
                     ADD COLUMN `alert_threshold` TINYINT(3) DEFAULT 80 COMMENT '流量提醒阈值(%)' AFTER `max_traffic_gb`";
        
        $db->query($alterSql);
        echo "成功添加 max_traffic_gb 和 alert_threshold 字段\n";
    } else {
        echo "字段已存在，无需升级\n";
    }
    
    echo "升级完成！\n";
    
} catch (Exception $e) {
    echo "升级失败: " . $e->getMessage() . "\n";
}
