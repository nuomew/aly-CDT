<?php
/**
 * API接口 - 流量数据查询
 */

// 定义常量
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

// 加载配置
$config = require ROOT_PATH . 'config' . DIRECTORY_SEPARATOR . 'config.php';

// 加载核心类
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Helper.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunCdt.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// 只允许GET和POST请求
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    Helper::error('不支持的请求方法', 405);
}

// 获取操作类型
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // 验证API密钥
    $apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!empty($config['api_key']) && $apiKey !== $config['api_key']) {
        Helper::error('无效的API密钥', 401);
    }

    $db = Database::getInstance($config['database']);
    $prefix = $db->getPrefix();
    
    switch ($action) {
        case 'overview':
            // 获取概览数据
            $today = date('Y-m-d');
            $monthStart = date('Y-m-01');
            
            // 今日流量
            $sql = "SELECT SUM(traffic_in) as traffic_in, SUM(traffic_out) as traffic_out, SUM(traffic_total) as traffic_total 
                    FROM `{$prefix}traffic_records` WHERE `record_date` = ?";
            $todayStats = $db->fetchOne($sql, [$today]);
            
            // 本月流量
            $sql = "SELECT SUM(traffic_in) as traffic_in, SUM(traffic_out) as traffic_out, SUM(traffic_total) as traffic_total 
                    FROM `{$prefix}traffic_records` WHERE `record_date` >= ?";
            $monthStats = $db->fetchOne($sql, [$monthStart]);
            
            Helper::success([
                'today' => [
                    'in' => $todayStats['traffic_in'] ?? 0,
                    'out' => $todayStats['traffic_out'] ?? 0,
                    'total' => $todayStats['traffic_total'] ?? 0,
                    'in_formatted' => AliyunCdt::formatBytes($todayStats['traffic_in'] ?? 0),
                    'out_formatted' => AliyunCdt::formatBytes($todayStats['traffic_out'] ?? 0),
                    'total_formatted' => AliyunCdt::formatBytes($todayStats['traffic_total'] ?? 0)
                ],
                'month' => [
                    'in' => $monthStats['traffic_in'] ?? 0,
                    'out' => $monthStats['traffic_out'] ?? 0,
                    'total' => $monthStats['traffic_total'] ?? 0,
                    'in_formatted' => AliyunCdt::formatBytes($monthStats['traffic_in'] ?? 0),
                    'out_formatted' => AliyunCdt::formatBytes($monthStats['traffic_out'] ?? 0),
                    'total_formatted' => AliyunCdt::formatBytes($monthStats['traffic_total'] ?? 0)
                ]
            ]);
            break;
            
        case 'trend':
            // 获取趋势数据
            $days = intval($_GET['days'] ?? 7);
            $days = min($days, 31);
            
            $sql = "SELECT `record_date`, SUM(`traffic_in`) as traffic_in, SUM(`traffic_out`) as traffic_out, SUM(`traffic_total`) as traffic_total 
                    FROM `{$prefix}traffic_records` 
                    WHERE `record_date` >= DATE_SUB(CURDATE(), INTERVAL ? DAY) 
                    GROUP BY `record_date` 
                    ORDER BY `record_date` ASC";
            $data = $db->fetchAll($sql, [$days]);
            
            Helper::success($data);
            break;
            
        case 'ranking':
            // 获取排行数据
            $limit = intval($_GET['limit'] ?? 10);
            $limit = min($limit, 50);
            $days = intval($_GET['days'] ?? 7);
            
            $sql = "SELECT `instance_id`, `instance_name`, 
                    SUM(`traffic_in`) as traffic_in, 
                    SUM(`traffic_out`) as traffic_out, 
                    SUM(`traffic_total`) as traffic_total 
                    FROM `{$prefix}traffic_records` 
                    WHERE `record_date` >= DATE_SUB(CURDATE(), INTERVAL ? DAY) 
                    GROUP BY `instance_id`, `instance_name` 
                    ORDER BY `traffic_total` DESC 
                    LIMIT ?";
            $data = $db->fetchAll($sql, [$days, $limit]);
            
            Helper::success($data);
            break;
            
        case 'instances':
            // 获取实例列表
            $sql = "SELECT DISTINCT `instance_id`, `instance_name`, `instance_type`, `region_id` 
                    FROM `{$prefix}traffic_records` 
                    ORDER BY `instance_id` ASC";
            $data = $db->fetchAll($sql);
            
            Helper::success($data);
            break;
            
        case 'detail':
            // 获取实例详情
            $instanceId = $_GET['instance_id'] ?? '';
            $days = intval($_GET['days'] ?? 7);
            
            if (empty($instanceId)) {
                Helper::error('缺少实例ID参数');
            }
            
            $sql = "SELECT * FROM `{$prefix}traffic_records` 
                    WHERE `instance_id` = ? AND `record_date` >= DATE_SUB(CURDATE(), INTERVAL ? DAY) 
                    ORDER BY `record_date` ASC";
            $data = $db->fetchAll($sql, [$instanceId, $days]);
            
            Helper::success($data);
            break;
            
        default:
            Helper::error('未知的操作类型');
    }
    
} catch (Exception $e) {
    Helper::error('服务器错误: ' . $e->getMessage(), 500);
}
