<?php
/**
 * 后台定时任务脚本
 * 建议通过cron定时调用，例如每5分钟执行一次
 * cron表达式: 每5分钟执行
 * 示例命令: php /path/to/api/cron.php > /dev/null 2>&1
 * 
 * 功能：
 * 1. 自动刷新所有配置的流量数据
 * 2. 检查流量阈值并发送邮件提醒
 * 3. 执行自动关机/开机逻辑
 */

define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require_once ROOT_PATH . 'config' . DIRECTORY_SEPARATOR . 'config.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Helper.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunCdt.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunEcs.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Mailer.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getInstance($config['database']);
    $prefix = $db->getPrefix();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

$results = [
    'time' => date('Y-m-d H:i:s'),
    'refresh' => [],
    'alerts' => [],
    'auto_power' => [],
    'errors' => []
];

$refreshResult = refreshAllTraffic($db, $prefix);
$results['refresh'] = $refreshResult;

$alertResult = checkTrafficAlerts($db, $prefix);
$results['alerts'] = $alertResult;

$powerResult = checkAutoPower($db, $prefix);
$results['auto_power'] = $powerResult;

setConfigValue($db, $prefix, 'last_cron_time', date('Y-m-d H:i:s'));

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

/**
 * 刷新所有配置的流量数据
 */
function refreshAllTraffic($db, $prefix)
{
    $configs = $db->fetchAll("SELECT * FROM `{$prefix}aliyun_config` WHERE `status` = 1 ORDER BY `is_default` DESC");
    
    if (empty($configs)) {
        return ['success' => 0, 'fail' => 0, 'total_traffic_gb' => 0, 'message' => '没有可用的阿里云配置'];
    }

    $success = 0;
    $fail = 0;
    $totalTraffic = 0;
    $errors = [];
    
    $today = date('Y-m-d');
    $billingCycle = date('Y-m');

    foreach ($configs as $aliyunConfig) {
        $secret = Helper::decrypt($aliyunConfig['access_key_secret']);
        if (empty($secret)) {
            $errors[] = "配置 {$aliyunConfig['name']}: AccessKey Secret解密失败";
            $fail++;
            continue;
        }
        
        $cdt = new AliyunCdt($aliyunConfig['access_key_id'], $secret, $aliyunConfig['region_id']);
        
        $result = $cdt->describeInternetTraffic($billingCycle);
        
        if ($result['success']) {
            $trafficData = AliyunCdt::parseTrafficData($result['data']);
            $totalTraffic += $trafficData['totalUsage'];
            
            foreach ($trafficData['list'] as $item) {
                $instanceKey = md5($aliyunConfig['id'] . '_' . $item['instanceId'] . '_' . $billingCycle);
                
                $exists = $db->fetchOne(
                    "SELECT id FROM `{$prefix}traffic_records` WHERE `config_id` = ? AND `instance_id` = ? AND `record_date` = ?",
                    [$aliyunConfig['id'], $item['instanceId'], $today]
                );
                
                $displayName = $aliyunConfig['name'];
                if ($item['billingItem']) {
                    $displayName .= ' - ' . $item['billingItem'];
                }
                
                $trafficBytes = $item['usage'] * 1024 * 1024 * 1024;
                
                if (!$exists) {
                    $db->insert('traffic_records', [
                        'config_id' => $aliyunConfig['id'],
                        'instance_id' => $item['instanceId'],
                        'instance_name' => $displayName,
                        'instance_type' => $item['billingItem'],
                        'region_id' => $aliyunConfig['region_id'],
                        'traffic_in' => 0,
                        'traffic_out' => $trafficBytes,
                        'traffic_total' => $trafficBytes,
                        'record_date' => $today
                    ]);
                } else {
                    $db->query(
                        "UPDATE `{$prefix}traffic_records` SET `traffic_out` = ?, `traffic_total` = ?, `instance_name` = ? WHERE `id` = ?",
                        [$trafficBytes, $trafficBytes, $displayName, $exists['id']]
                    );
                }
            }
            $success++;
        } else {
            $errors[] = "配置 {$aliyunConfig['name']}: " . ($result['error'] ?? '查询失败');
            $fail++;
        }
    }

    setConfigValue($db, $prefix, 'last_refresh_time', date('Y-m-d H:i:s'));

    return [
        'success' => $success,
        'fail' => $fail,
        'total_traffic_gb' => round($totalTraffic, 2),
        'errors' => $errors
    ];
}

/**
 * 检查流量阈值并发送提醒
 */
function checkTrafficAlerts($db, $prefix)
{
    $monthStart = date('Y-m-01');
    
    $sql = "SELECT ac.*, 
            COALESCE(SUM(tr.traffic_total), 0) as month_traffic
            FROM `{$prefix}aliyun_config` ac 
            LEFT JOIN `{$prefix}traffic_records` tr ON ac.id = tr.config_id AND tr.record_date >= ? 
            WHERE ac.status = 1
            GROUP BY ac.id";
    $configs = $db->fetchAll($sql, [$monthStart]);
    
    if (empty($configs)) {
        return ['sent' => false, 'message' => '没有启用的配置'];
    }
    
    $mailConfig = getDefaultMailConfig($db, $prefix);
    if (!$mailConfig) {
        return ['sent' => false, 'message' => '没有默认邮箱配置'];
    }
    
    $alertTemplate = getMailTemplate($db, $prefix, 'traffic_alert');
    if (!$alertTemplate) {
        return ['sent' => false, 'message' => '没有流量提醒模板'];
    }
    
    $alertConfigs = [];
    $totalTrafficGB = 0;
    
    foreach ($configs as $cfg) {
        $trafficGB = $cfg['month_traffic'] / 1024 / 1024 / 1024;
        $totalTrafficGB += $trafficGB;
        $maxTraffic = floatval($cfg['max_traffic_gb'] ?? 200);
        $threshold = intval($cfg['alert_threshold'] ?? 80);
        
        if ($maxTraffic <= 0) continue;
        
        $percent = round(($trafficGB / $maxTraffic) * 100, 1);
        
        if ($percent >= $threshold) {
            $alertConfigs[] = [
                'id' => $cfg['id'],
                'name' => $cfg['name'],
                'traffic_gb' => round($trafficGB, 2),
                'max_traffic_gb' => $maxTraffic,
                'percent' => $percent,
                'threshold' => $threshold
            ];
        }
    }
    
    if (empty($alertConfigs)) {
        return ['sent' => false, 'message' => '所有配置流量正常'];
    }
    
    $today = date('Y-m-d');
    $sentConfigs = [];
    
    foreach ($alertConfigs as $alertCfg) {
        $lastAlertKey = 'last_alert_' . $alertCfg['id'];
        $lastAlertTime = getConfigValue($db, $prefix, $lastAlertKey, '');
        
        if (strpos($lastAlertTime, $today) === 0) {
            continue;
        }
        
        $subject = $alertTemplate['subject'];
        $body = $alertTemplate['body'];
        
        $variables = [
            '{config_name}' => $alertCfg['name'],
            '{traffic_used}' => number_format($alertCfg['traffic_gb'], 2),
            '{traffic_max}' => number_format($alertCfg['max_traffic_gb'], 0),
            '{percent}' => $alertCfg['percent'],
            '{threshold}' => $alertCfg['threshold'],
            '{alert_time}' => date('Y-m-d H:i:s')
        ];
        
        foreach ($variables as $key => $value) {
            $subject = str_replace($key, $value, $subject);
            $body = str_replace($key, $value, $body);
        }
        
        $password = Helper::decrypt($mailConfig['smtp_password']);
        $mailer = new Mailer(
            $mailConfig['smtp_host'],
            $mailConfig['smtp_port'],
            $mailConfig['smtp_username'],
            $password,
            $mailConfig['smtp_encryption'],
            $mailConfig['from_email']
        );
        
        $recipients = array_map('trim', explode(',', $mailConfig['to_emails']));
        $sent = $mailer->send($recipients, $subject, $body, $mailConfig['from_name'] ?: '阿里云流量监控系统');
        
        if ($sent) {
            setConfigValue($db, $prefix, $lastAlertKey, date('Y-m-d H:i:s'));
            $sentConfigs[] = $alertCfg['name'];
        }
    }
    
    if (!empty($sentConfigs)) {
        return ['sent' => true, 'message' => "已发送邮件提醒: " . implode(', ', $sentConfigs)];
    }
    
    return ['sent' => false, 'message' => '今日已发送过提醒'];
}

/**
 * 检查自动开关机
 */
function checkAutoPower($db, $prefix)
{
    $now = time();
    $currentDay = intval(date('j'));
    $currentHour = intval(date('G'));
    $currentMinute = intval(date('i'));
    $today = date('Y-m-d');
    
    $results = [
        'shutdown' => [],
        'startup' => [],
        'errors' => []
    ];
    
    $configs = $db->fetchAll("SELECT * FROM `{$prefix}aliyun_config` WHERE `status` = 1");
    
    foreach ($configs as $config) {
        $configId = $config['id'];
        $configName = $config['name'];
        $autoShutdown = intval($config['auto_shutdown'] ?? 0);
        $autoStartDay = intval($config['auto_start_day'] ?? 1);
        $autoStartHour = intval($config['auto_start_hour'] ?? 0);
        $autoStartMinute = intval($config['auto_start_minute'] ?? 0);
        $maxTrafficGb = floatval($config['max_traffic_gb'] ?? 200);
        $shutdownThreshold = intval($config['shutdown_threshold'] ?? 95);
        $lastAutoShutdown = $config['last_auto_shutdown'] ?? null;
        $lastAutoStart = $config['last_auto_start'] ?? null;
        
        $secret = Helper::decrypt($config['access_key_secret']);
        if (empty($secret)) {
            $results['errors'][] = "配置 {$configName}: AccessKey Secret解密失败";
            continue;
        }
        
        $ecs = new AliyunEcs($config['access_key_id'], $secret, $config['region_id']);
        
        if ($currentDay === $autoStartDay && $currentHour === $autoStartHour && $currentMinute === $autoStartMinute) {
            $alreadyStartedToday = false;
            if ($lastAutoStart) {
                $lastStartDay = substr($lastAutoStart, 0, 10);
                if ($lastStartDay === $today) {
                    $alreadyStartedToday = true;
                }
            }
            
            if (!$alreadyStartedToday) {
                $instances = $ecs->describeInstances();
                if ($instances['success']) {
                    $instanceList = $instances['data']['Instances']['Instance'] ?? [];
                    if (isset($instanceList['InstanceId'])) {
                        $instanceList = [$instanceList];
                    }
                    
                    $startedCount = 0;
                    foreach ($instanceList as $inst) {
                        $status = $inst['Status'] ?? '';
                        if ($status === 'Stopped') {
                            $startResult = $ecs->startInstance($inst['InstanceId']);
                            if ($startResult['success']) {
                                $startedCount++;
                            } else {
                                $results['errors'][] = "配置 {$configName} 实例 {$inst['InstanceId']} 开机失败: " . ($startResult['error'] ?? '未知错误');
                            }
                        }
                    }
                    
                    if ($startedCount > 0) {
                        $db->query("UPDATE `{$prefix}aliyun_config` SET `last_auto_start` = NOW() WHERE `id` = ?", [$configId]);
                        $results['startup'][] = "配置 {$configName}: 已启动 {$startedCount} 台实例";
                    }
                }
            }
        }
        
        if ($autoShutdown) {
            $cdt = new AliyunCdt($config['access_key_id'], $secret, $config['region_id']);
            $trafficResult = $cdt->describeInternetTraffic(date('Y-m'));
            
            if ($trafficResult['success']) {
                $trafficData = AliyunCdt::parseTrafficData($trafficResult['data']);
                $totalGB = $trafficData['totalUsage'];
                $trafficPercent = $maxTrafficGb > 0 ? ($totalGB / $maxTrafficGb) * 100 : 0;
                
                if ($trafficPercent >= $shutdownThreshold) {
                    $alreadyShutdownToday = false;
                    if ($lastAutoShutdown) {
                        $lastShutdownDay = substr($lastAutoShutdown, 0, 10);
                        if ($lastShutdownDay === $today) {
                            $alreadyShutdownToday = true;
                        }
                    }
                    
                    if (!$alreadyShutdownToday) {
                        $instances = $ecs->describeInstances();
                        if ($instances['success']) {
                            $instanceList = $instances['data']['Instances']['Instance'] ?? [];
                            if (isset($instanceList['InstanceId'])) {
                                $instanceList = [$instanceList];
                            }
                            
                            $stoppedCount = 0;
                            foreach ($instanceList as $inst) {
                                $status = $inst['Status'] ?? '';
                                if ($status === 'Running') {
                                    $stopResult = $ecs->stopInstance($inst['InstanceId']);
                                    if ($stopResult['success']) {
                                        $stoppedCount++;
                                    } else {
                                        $results['errors'][] = "配置 {$configName} 实例 {$inst['InstanceId']} 关机失败: " . ($stopResult['error'] ?? '未知错误');
                                    }
                                }
                            }
                            
                            if ($stoppedCount > 0) {
                                $db->query("UPDATE `{$prefix}aliyun_config` SET `last_auto_shutdown` = NOW() WHERE `id` = ?", [$configId]);
                                
                                sendShutdownNotification($db, $prefix, $config, $totalGB, $maxTrafficGb, $trafficPercent, $shutdownThreshold, $stoppedCount);
                                
                                $results['shutdown'][] = "配置 {$configName}: 流量已达 " . round($trafficPercent, 1) . "%，已关闭 {$stoppedCount} 台实例";
                            }
                        }
                    }
                }
            }
        }
    }
    
    return $results;
}

/**
 * 发送关机通知邮件
 */
function sendShutdownNotification($db, $prefix, $config, $totalGB, $maxTrafficGb, $trafficPercent, $shutdownThreshold, $stoppedCount)
{
    $mailConfig = getDefaultMailConfig($db, $prefix);
    if (!$mailConfig) {
        return false;
    }
    
    $shutdownTemplate = getMailTemplate($db, $prefix, 'auto_shutdown');
    if (!$shutdownTemplate) {
        return false;
    }
    
    $subject = $shutdownTemplate['subject'];
    $body = $shutdownTemplate['body'];
    
    $variables = [
        '{config_name}' => $config['name'],
        '{traffic_used}' => number_format($totalGB, 2),
        '{traffic_max}' => number_format($maxTrafficGb, 0),
        '{percent}' => round($trafficPercent, 1),
        '{shutdown_threshold}' => $shutdownThreshold,
        '{shutdown_count}' => $stoppedCount,
        '{shutdown_time}' => date('Y-m-d H:i:s'),
        '{auto_start_day}' => $config['auto_start_day'] ?? 1,
        '{auto_start_hour}' => str_pad($config['auto_start_hour'] ?? 0, 2, '0', STR_PAD_LEFT),
        '{auto_start_minute}' => str_pad($config['auto_start_minute'] ?? 0, 2, '0', STR_PAD_LEFT)
    ];
    
    foreach ($variables as $key => $value) {
        $subject = str_replace($key, $value, $subject);
        $body = str_replace($key, $value, $body);
    }
    
    $password = Helper::decrypt($mailConfig['smtp_password']);
    $mailer = new Mailer(
        $mailConfig['smtp_host'],
        $mailConfig['smtp_port'],
        $mailConfig['smtp_username'],
        $password,
        $mailConfig['smtp_encryption'],
        $mailConfig['from_email']
    );
    
    $recipients = array_map('trim', explode(',', $mailConfig['to_emails']));
    return $mailer->send($recipients, $subject, $body, $mailConfig['from_name'] ?: '阿里云流量监控系统');
}

/**
 * 获取默认邮箱配置
 */
function getDefaultMailConfig($db, $prefix)
{
    $config = $db->fetchOne("SELECT * FROM `{$prefix}mail_config` WHERE `status` = 1 AND `is_default` = 1 LIMIT 1");
    
    if (!$config) {
        $config = $db->fetchOne("SELECT * FROM `{$prefix}mail_config` WHERE `status` = 1 LIMIT 1");
    }
    
    return $config;
}

/**
 * 获取邮件模板
 */
function getMailTemplate($db, $prefix, $templateKey)
{
    return $db->fetchOne("SELECT * FROM `{$prefix}mail_template` WHERE `template_key` = ? AND `status` = 1", [$templateKey]);
}

/**
 * 获取配置值
 */
function getConfigValue($db, $prefix, $key, $default = '')
{
    $sql = "SELECT `config_value` FROM `{$prefix}system_config` WHERE `config_key` = ?";
    $result = $db->fetchOne($sql, [$key]);
    return $result ? $result['config_value'] : $default;
}

/**
 * 设置配置值
 */
function setConfigValue($db, $prefix, $key, $value)
{
    $sql = "INSERT INTO `{$prefix}system_config` (`config_key`, `config_value`, `updated_at`) 
            VALUES (?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE `config_value` = ?, `updated_at` = NOW()";
    $db->query($sql, [$key, $value, $value]);
}
