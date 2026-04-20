<?php
/**
 * 自动开关机定时任务
 * 建议每分钟执行一次: * * * * * php /path/to/api/auto_power.php
 * 
 * 功能：
 * 1. 检查流量是否达到阈值，如果达到且开启自动关机，则关闭所有ECS实例
 * 2. 检查是否到达定时开机时间，如果是则开启所有ECS实例
 */

define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require_once ROOT_PATH . 'config' . DIRECTORY_SEPARATOR . 'config.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Helper.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunCdt.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunEcs.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDb();
$prefix = $db->getPrefix();
$now = time();
$currentDay = intval(date('j'));
$currentHour = intval(date('G'));
$currentMinute = intval(date('i'));
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$results = [
    'time' => date('Y-m-d H:i:s'),
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
    $maxTrafficGb = floatval($config['max_traffic_gb'] ?? 1000);
    $alertThreshold = intval($config['alert_threshold'] ?? 80);
    $lastAutoShutdown = $config['last_auto_shutdown'] ?? null;
    $lastAutoStart = $config['last_auto_start'] ?? null;
    
    $secret = Helper::decrypt($config['access_key_secret']);
    if (empty($secret)) {
        $results['errors'][] = "配置 {$configName}: AccessKey Secret解密失败";
        continue;
    }
    
    $ecs = new AliyunEcs($config['access_key_id'], $secret, $config['region_id']);
    
    // 检查是否到达定时开机时间
    if ($currentDay === $autoStartDay && $currentHour === $autoStartHour && $currentMinute === $autoStartMinute) {
        // 检查今天是否已经执行过自动开机
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
                
                $db->query("UPDATE `{$prefix}aliyun_config` SET `last_auto_start` = NOW() WHERE `id` = ?", [$configId]);
                $results['startup'][] = "配置 {$configName}: 已启动 {$startedCount} 台实例";
            } else {
                $results['errors'][] = "配置 {$configName}: 查询实例失败 - " . ($instances['error'] ?? '未知错误');
            }
        }
    }
    
    // 检查流量是否达到阈值需要自动关机
    if ($autoShutdown) {
        $cdt = new AliyunCdt($config['access_key_id'], $secret, $config['region_id']);
        $trafficResult = $cdt->getMonthTraffic();
        
        if ($trafficResult['success']) {
            $totalBytes = $trafficResult['data']['total'] ?? 0;
            $totalGB = $totalBytes / 1024 / 1024 / 1024;
            $trafficPercent = $maxTrafficGb > 0 ? ($totalGB / $maxTrafficGb) * 100 : 0;
            
            if ($trafficPercent >= $alertThreshold) {
                // 检查今天是否已经执行过自动关机
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
                            $results['shutdown'][] = "配置 {$configName}: 流量已达 {$trafficPercent}%，已关闭 {$stoppedCount} 台实例";
                        }
                    } else {
                        $results['errors'][] = "配置 {$configName}: 查询实例失败 - " . ($instances['error'] ?? '未知错误');
                    }
                }
            }
        } else {
            $results['errors'][] = "配置 {$configName}: 查询流量失败 - " . ($trafficResult['error'] ?? '未知错误');
        }
    }
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
