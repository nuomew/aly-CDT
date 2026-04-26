<?php
/**
 * 自动刷新流量API
 * 用于前端定时调用刷新流量数据并检查阈值
 */

define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require_once ROOT_PATH . 'config' . DIRECTORY_SEPARATOR . 'config.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Helper.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunCdt.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Mailer.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getInstance($config['database']);
    $prefix = $db->getPrefix();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => '数据库连接失败']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'refresh';

switch ($action) {
    case 'refresh':
        refreshTraffic($db, $prefix, $config);
        break;
    
    case 'check_alert':
        checkTrafficAlert($db, $prefix, $config);
        break;
    
    case 'status':
        getStatus($db, $prefix, $config);
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => '未知操作']);
}

/**
 * 刷新流量数据
 */
function refreshTraffic($db, $prefix, $config)
{
    $configs = $db->fetchAll("SELECT * FROM `{$prefix}aliyun_config` WHERE `status` = 1 ORDER BY `is_default` DESC");
    
    if (empty($configs)) {
        echo json_encode(['success' => false, 'error' => '没有可用的阿里云配置']);
        return;
    }

    $success = 0;
    $fail = 0;
    $totalTraffic = 0;
    $today = date('Y-m-d');

    foreach ($configs as $aliyunConfig) {
        $secret = Helper::decrypt($aliyunConfig['access_key_secret']);
        $cdt = new AliyunCdt($aliyunConfig['access_key_id'], $secret, $aliyunConfig['region_id']);
        
        $billingCycle = date('Y-m');
        $result = $cdt->describeInternetTraffic($billingCycle);
        
        if ($result['success']) {
            $trafficData = AliyunCdt::parseTrafficData($result['data']);
            $totalTraffic += $trafficData['totalUsage'];
            
            foreach ($trafficData['list'] as $item) {
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
            $fail++;
        }
    }

    $alertSent = false;
    $alertMessage = '';
    
    $mailEnabled = getConfigValue($db, $prefix, 'mail_enabled', '0');
    if ($mailEnabled === '1' && $totalTraffic > 0) {
        $alertResult = checkAndSendAlert($db, $prefix, $config, $totalTraffic);
        if ($alertResult['sent']) {
            $alertSent = true;
            $alertMessage = $alertResult['message'];
        }
    }

    updateLastRefreshTime($db, $prefix);

    echo json_encode([
        'success' => true,
        'data' => [
            'success_count' => $success,
            'fail_count' => $fail,
            'total_traffic_gb' => round($totalTraffic, 2),
            'alert_sent' => $alertSent,
            'alert_message' => $alertMessage,
            'refresh_time' => date('Y-m-d H:i:s')
        ]
    ]);
}

/**
 * 检查流量阈值并发送提醒
 */
function checkAndSendAlert($db, $prefix, $config, $totalTrafficGB)
{
    $monthStart = date('Y-m-01');
    
    $sql = "SELECT ac.*, 
            COALESCE((SELECT SUM(tr2.traffic_total) 
                FROM `{$prefix}traffic_records` tr2 
                WHERE tr2.config_id = ac.id AND tr2.record_date >= ? 
                AND tr2.id = (SELECT MAX(tr3.id) FROM `{$prefix}traffic_records` tr3 WHERE tr3.config_id = ac.id AND tr3.instance_id = tr2.instance_id AND tr3.record_date >= ?)
            ), 0) as month_traffic
            FROM `{$prefix}aliyun_config` ac 
            WHERE ac.status = 1
            GROUP BY ac.id";
    $configs = $db->fetchAll($sql, [$monthStart, $monthStart]);
    
    if (empty($configs)) {
        return ['sent' => false, 'message' => '没有启用的配置'];
    }
    
    $alertConfigs = [];
    foreach ($configs as $cfg) {
        $trafficGB = $cfg['month_traffic'] / 1024 / 1024 / 1024;
        $maxTraffic = floatval($cfg['max_traffic_gb'] ?? 1000);
        $threshold = intval($cfg['alert_threshold'] ?? 80);
        
        if ($maxTraffic <= 0) continue;
        
        $percent = round(($trafficGB / $maxTraffic) * 100, 1);
        
        if ($percent >= $threshold) {
            $alertConfigs[] = [
                'name' => $cfg['name'],
                'traffic_gb' => $trafficGB,
                'max_traffic_gb' => $maxTraffic,
                'percent' => $percent,
                'threshold' => $threshold
            ];
        }
    }
    
    if (empty($alertConfigs)) {
        return ['sent' => false, 'message' => '所有配置流量正常'];
    }
    
    $lastAlertTime = getConfigValue($db, $prefix, 'last_alert_time', '');
    $today = date('Y-m-d');
    
    if (strpos($lastAlertTime, $today) === 0) {
        return ['sent' => false, 'message' => '今日已发送提醒'];
    }
    
    $mailConfig = [
        'mail_host' => getConfigValue($db, $prefix, 'mail_host', ''),
        'mail_port' => getConfigValue($db, $prefix, 'mail_port', '465'),
        'mail_username' => getConfigValue($db, $prefix, 'mail_username', ''),
        'mail_password' => getConfigValue($db, $prefix, 'mail_password', ''),
        'mail_encryption' => getConfigValue($db, $prefix, 'mail_encryption', 'ssl'),
        'mail_from' => getConfigValue($db, $prefix, 'mail_from', ''),
        'mail_to' => getConfigValue($db, $prefix, 'mail_to', '')
    ];
    
    if (empty($mailConfig['mail_host']) || empty($mailConfig['mail_to'])) {
        return ['sent' => false, 'message' => '邮件配置不完整'];
    }
    
    $sent = Mailer::sendTrafficAlertPerConfig($mailConfig, $alertConfigs, $totalTrafficGB);
    
    if ($sent) {
        setConfigValue($db, $prefix, 'last_alert_time', date('Y-m-d H:i:s'));
        $alertNames = array_column($alertConfigs, 'name');
        return ['sent' => true, 'message' => "已发送邮件提醒，超限配置: " . implode(', ', $alertNames)];
    }
    
    return ['sent' => false, 'message' => '邮件发送失败'];
}

/**
 * 检查流量提醒状态
 */
function checkTrafficAlert($db, $prefix, $config)
{
    $monthStart = date('Y-m-01');
    $today = date('Y-m-d');
    
    $sql = "SELECT SUM(traffic_total) as total FROM `{$prefix}traffic_records` 
            WHERE `record_date` >= ? 
            AND id IN (SELECT MAX(id) FROM `{$prefix}traffic_records` WHERE record_date >= ? GROUP BY config_id, instance_id)";
    $result = $db->fetchOne($sql, [$monthStart, $monthStart]);
    $currentTrafficBytes = $result['total'] ?? 0;
    $currentTrafficGB = $currentTrafficBytes / 1024 / 1024 / 1024;
    
    $maxTrafficGB = floatval(getConfigValue($db, $prefix, 'max_traffic_gb', '1000'));
    $threshold = intval(getConfigValue($db, $prefix, 'traffic_alert_threshold', '80'));
    
    $percent = $maxTrafficGB > 0 ? round(($currentTrafficGB / $maxTrafficGB) * 100, 1) : 0;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'current_traffic_gb' => round($currentTrafficGB, 2),
            'max_traffic_gb' => $maxTrafficGB,
            'percent' => $percent,
            'threshold' => $threshold,
            'need_alert' => $percent >= $threshold,
            'auto_refresh_enabled' => getConfigValue($db, $prefix, 'auto_refresh_enabled', '0') === '1',
            'auto_refresh_interval' => intval(getConfigValue($db, $prefix, 'auto_refresh_interval', '5'))
        ]
    ]);
}

/**
 * 获取系统状态
 */
function getStatus($db, $prefix, $config)
{
    $lastRefresh = getConfigValue($db, $prefix, 'last_refresh_time', '');
    
    $monthStart = date('Y-m-01');
    $sql = "SELECT SUM(traffic_total) as total FROM `{$prefix}traffic_records` 
            WHERE `record_date` >= ? 
            AND id IN (SELECT MAX(id) FROM `{$prefix}traffic_records` WHERE record_date >= ? GROUP BY config_id, instance_id)";
    $result = $db->fetchOne($sql, [$monthStart, $monthStart]);
    $currentTrafficGB = ($result['total'] ?? 0) / 1024 / 1024 / 1024;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'last_refresh_time' => $lastRefresh,
            'current_traffic_gb' => round($currentTrafficGB, 2),
            'auto_refresh_enabled' => getConfigValue($db, $prefix, 'auto_refresh_enabled', '0') === '1',
            'auto_refresh_interval' => intval(getConfigValue($db, $prefix, 'auto_refresh_interval', '5')),
            'server_time' => date('Y-m-d H:i:s')
        ]
    ]);
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

/**
 * 更新最后刷新时间
 */
function updateLastRefreshTime($db, $prefix)
{
    setConfigValue($db, $prefix, 'last_refresh_time', date('Y-m-d H:i:s'));
}
