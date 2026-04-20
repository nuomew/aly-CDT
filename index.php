<?php
/**
 * 前端首页 - 流量展示页面
 */

define('ROOT_PATH', __DIR__ . DIRECTORY_SEPARATOR);

$config = require ROOT_PATH . 'config' . DIRECTORY_SEPARATOR . 'config.php';

require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'Helper.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunCdt.php';

if (!file_exists(ROOT_PATH . 'install.lock')) {
    echo '<script>window.location.href="install/";</script>';
    exit;
}

try {
    $db = Database::getInstance($config['database']);
} catch (Exception $e) {
    die('数据库连接失败，请检查配置');
}
$prefix = $db->getPrefix();

function getConfigVal($db, $prefix, $key, $default = '') {
    $sql = "SELECT `config_value` FROM `{$prefix}system_config` WHERE `config_key` = ?";
    $result = $db->fetchOne($sql, [$key]);
    return $result ? $result['config_value'] : $default;
}

$siteName = getConfigVal($db, $prefix, 'site_name', '阿里云流量查询系统');
$siteDesc = getConfigVal($db, $prefix, 'site_description', '云数据传输流量监控平台');
$autoRefreshEnabled = getConfigVal($db, $prefix, 'auto_refresh_enabled', '0') === '1';
$autoRefreshInterval = intval(getConfigVal($db, $prefix, 'auto_refresh_interval', '5'));
$lastRefreshTime = getConfigVal($db, $prefix, 'last_refresh_time', '');

$checkField = $db->fetchOne("SHOW COLUMNS FROM `{$prefix}aliyun_config` LIKE 'max_traffic_gb'");
if (!$checkField) {
    $db->query("ALTER TABLE `{$prefix}aliyun_config` 
                ADD COLUMN `max_traffic_gb` DECIMAL(10,2) DEFAULT 1000.00 COMMENT '最大流量限制(GB)' AFTER `remark`,
                ADD COLUMN `alert_threshold` TINYINT(3) DEFAULT 80 COMMENT '流量提醒阈值(%)' AFTER `max_traffic_gb`");
}

$monthStart = date('Y-m-01');

$configCount = $db->fetchOne("SELECT COUNT(*) as count FROM `{$prefix}aliyun_config` WHERE `status` = 1");
$totalConfigCount = $db->fetchOne("SELECT COUNT(*) as count FROM `{$prefix}aliyun_config`");

$sql = "SELECT ac.*, 
        COALESCE((SELECT tr2.traffic_total FROM `{$prefix}traffic_records` tr2 WHERE tr2.config_id = ac.id AND tr2.record_date >= ? ORDER BY tr2.record_date DESC LIMIT 1), 0) as month_traffic,
        COALESCE((SELECT tr3.traffic_out FROM `{$prefix}traffic_records` tr3 WHERE tr3.config_id = ac.id AND tr3.record_date >= ? ORDER BY tr3.record_date DESC LIMIT 1), 0) as month_out
        FROM `{$prefix}aliyun_config` ac 
        WHERE ac.status = 1
        ORDER BY ac.is_default DESC, ac.id ASC";
$configs = $db->fetchAll($sql, [$monthStart, $monthStart]);

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// 今日流量 - 取最新日期的记录（阿里云返回的是累计值）
$sql = "SELECT SUM(traffic_out) as traffic_out, SUM(traffic_total) as traffic_total 
        FROM `{$prefix}traffic_records` 
        WHERE `record_date` = (SELECT MAX(record_date) FROM `{$prefix}traffic_records`)";
$todayStats = $db->fetchOne($sql);
$todayStats = $todayStats ?: ['traffic_out' => 0, 'traffic_total' => 0];

// 昨日流量 - 取昨天日期的记录
$sql = "SELECT SUM(traffic_out) as traffic_out, SUM(traffic_total) as traffic_total 
        FROM `{$prefix}traffic_records` WHERE `record_date` = ?";
$yesterdayStats = $db->fetchOne($sql, [$yesterday]);
$yesterdayStats = $yesterdayStats ?: ['traffic_out' => 0, 'traffic_total' => 0];

// 本月流量 - 取最新日期的记录（阿里云返回的是月度累计值）
$sql = "SELECT SUM(traffic_out) as traffic_out, SUM(traffic_total) as traffic_total 
        FROM `{$prefix}traffic_records` 
        WHERE `record_date` = (SELECT MAX(record_date) FROM `{$prefix}traffic_records` WHERE `record_date` >= ?)";
$latestStats = $db->fetchOne($sql, [$monthStart]);
$monthStats = [
    'traffic_out' => $latestStats['traffic_out'] ?? 0,
    'traffic_total' => $latestStats['traffic_total'] ?? 0
];

$sql = "SELECT `record_date`, SUM(`traffic_out`) as traffic_out, SUM(`traffic_total`) as traffic_total 
        FROM `{$prefix}traffic_records` 
        WHERE `record_date` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
        GROUP BY `record_date` 
        ORDER BY `record_date` ASC";
$weeklyStats = $db->fetchAll($sql);

$sql = "SELECT ac.name, tr.traffic_out, tr.traffic_total 
        FROM `{$prefix}traffic_records` tr
        JOIN `{$prefix}aliyun_config` ac ON tr.config_id = ac.id
        WHERE tr.record_date = (SELECT MAX(record_date) FROM `{$prefix}traffic_records` WHERE `record_date` >= ?)
        ORDER BY tr.traffic_total DESC 
        LIMIT 10";
$instanceRanking = $db->fetchAll($sql, [$monthStart]);

$totalMaxTraffic = 0;
$totalCurrentTraffic = 0;
foreach ($configs as $cfg) {
    $totalMaxTraffic += floatval($cfg['max_traffic_gb'] ?? 1000);
    $totalCurrentTraffic += $cfg['month_traffic'] / 1024 / 1024 / 1024;
}
$totalTrafficPercent = $totalMaxTraffic > 0 ? min(100, round(($totalCurrentTraffic / $totalMaxTraffic) * 100, 1)) : 0;

$todayTotalGB = ($todayStats['traffic_total'] ?? 0) / 1024 / 1024 / 1024;
$yesterdayTotalGB = ($yesterdayStats['traffic_total'] ?? 0) / 1024 / 1024 / 1024;
$dailyUsageGB = $todayTotalGB - $yesterdayTotalGB;
if ($dailyUsageGB < 0) $dailyUsageGB = $todayTotalGB;

$monthTotalGB = ($monthStats['traffic_total'] ?? 0) / 1024 / 1024 / 1024;
$monthOutGB = ($monthStats['traffic_out'] ?? 0) / 1024 / 1024 / 1024;

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteName); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($siteDesc); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --danger-gradient: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        
        html { scroll-behavior: smooth; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            color: #1a1a2e;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .header {
            background: var(--primary-gradient);
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
        }
        
        .header-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 64px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff;
            text-decoration: none;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .logo-text {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .nav {
            display: flex;
            gap: 8px;
        }
        
        .nav-link {
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }
        
        .main {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .page-header {
            margin-bottom: 24px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 800;
            color: #1a1a2e;
            margin-bottom: 8px;
        }
        
        .page-desc {
            color: #6b7280;
            font-size: 15px;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--card-shadow-hover);
        }
        
        .stat-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-icon svg {
            width: 24px;
            height: 24px;
            fill: #fff;
        }
        
        .stat-icon.in { background: var(--success-gradient); }
        .stat-icon.out { background: var(--warning-gradient); }
        .stat-icon.total { background: var(--primary-gradient); }
        .stat-icon.month { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        
        .stat-label {
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #1a1a2e;
            margin-top: 4px;
        }
        
        .section {
            margin-bottom: 24px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .panel {
            background: #fff;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }
        
        .panel-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .panel-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .panel-body {
            padding: 24px;
        }
        
        .config-gauges {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .config-gauge-card {
            flex: 1 1 calc(50% - 10px);
            min-width: 300px;
            max-width: 100%;
            background: linear-gradient(135deg, #f8fafc 0%, #fff 100%);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .config-gauges .config-gauge-card:only-child {
            flex: 1 1 100%;
        }
        
        .config-gauge-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--card-shadow-hover);
        }
        
        .config-gauge-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .config-gauge-name {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .config-gauge-badge {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .config-gauge-badge.safe {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        
        .config-gauge-badge.warning {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }
        
        .config-gauge-badge.danger {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        
        .config-gauge-body {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .config-gauge-circle {
            position: relative;
            width: 100px;
            height: 100px;
            flex-shrink: 0;
        }
        
        .config-gauge-circle svg {
            transform: rotate(-90deg);
        }
        
        .gauge-bg-sm {
            fill: none;
            stroke: #e5e7eb;
            stroke-width: 8;
        }
        
        .gauge-fill-sm {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .gauge-center-sm {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        
        .gauge-value-sm {
            font-size: 20px;
            font-weight: 800;
            color: #1a1a2e;
        }
        
        .gauge-unit-sm {
            font-size: 10px;
            color: #6b7280;
        }
        
        .config-gauge-stats {
            flex: 1;
        }
        
        .config-gauge-stat {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .config-gauge-stat-label {
            color: #6b7280;
        }
        
        .config-gauge-stat-value {
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .config-gauge-progress {
            margin-top: 16px;
        }
        
        .config-gauge-progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .config-gauge-progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .config-gauge-progress-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 11px;
            color: #6b7280;
        }
        
        .total-gauge-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px;
        }
        
        .total-gauge-wrapper {
            position: relative;
            width: 200px;
            height: 200px;
        }
        
        .total-gauge-wrapper svg {
            transform: rotate(-90deg);
        }
        
        .gauge-bg-lg {
            fill: none;
            stroke: #e5e7eb;
            stroke-width: 12;
        }
        
        .gauge-fill-lg {
            fill: none;
            stroke-width: 12;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease;
        }
        
        .gauge-center-lg {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        
        .gauge-value-lg {
            font-size: 36px;
            font-weight: 800;
            color: #1a1a2e;
            line-height: 1;
        }
        
        .gauge-unit-lg {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .gauge-label {
            font-size: 16px;
            font-weight: 600;
            color: #374151;
            margin-top: 16px;
        }
        
        .gauge-info {
            display: flex;
            gap: 32px;
            margin-top: 24px;
        }
        
        .gauge-info-item {
            text-align: center;
        }
        
        .gauge-info-value {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .gauge-info-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        .chart-container {
            height: 354px;
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .ranking-list {
            padding: 0;
        }
        
        .ranking-item {
            display: flex;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: background 0.2s;
        }
        
        .ranking-item:last-child {
            border-bottom: none;
        }
        
        .ranking-item:hover {
            background: rgba(102, 126, 234, 0.04);
        }
        
        .ranking-position {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            margin-right: 16px;
        }
        
        .ranking-position.gold {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: #fff;
        }
        
        .ranking-position.silver {
            background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%);
            color: #fff;
        }
        
        .ranking-position.bronze {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            color: #fff;
        }
        
        .ranking-position.normal {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .ranking-name {
            flex: 1;
            font-size: 14px;
            font-weight: 500;
            color: #1a1a2e;
        }
        
        .ranking-values {
            display: flex;
            gap: 24px;
            font-size: 13px;
        }
        
        .ranking-value {
            color: #6b7280;
        }
        
        .ranking-value.total {
            font-weight: 700;
            color: #667eea;
        }
        
        .footer {
            background: #fff;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 20px 0;
            margin-top: 40px;
        }
        
        .footer-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: #6b7280;
        }
        
        .update-time {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .update-time::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .empty-state-text {
            font-size: 15px;
            margin-bottom: 20px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            color: #fff;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .header-inner {
                flex-direction: column;
                height: auto;
                padding: 16px;
                gap: 12px;
            }
            .nav {
                width: 100%;
                justify-content: center;
            }
            .stats-row {
                grid-template-columns: 1fr;
            }
            .config-gauges .config-gauge-card {
                flex: 1 1 100%;
            }
            .main {
                padding: 16px;
            }
            .footer-inner {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-inner">
            <a href="/" class="logo">
                <div class="logo-icon">📊</div>
                <span class="logo-text"><?php echo htmlspecialchars($siteName); ?></span>
            </a>
            <nav class="nav">
                <a href="#overview" class="nav-link active">概览</a>
                <a href="#trend" class="nav-link">趋势</a>
                <a href="#ranking" class="nav-link">排行</a>
                <a href="admin/" class="nav-link">管理后台</a>
            </nav>
        </div>
    </header>
    
    <main class="main">
        <div class="page-header">
            <h1 class="page-title">流量监控中心</h1>
            <p class="page-desc"><?php echo htmlspecialchars($siteDesc); ?> · 实时监控您的阿里云流量使用情况</p>
        </div>
        
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon in">
                        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm0 18c-3.31 0-6-2.69-6-6 0-3.32 2.69-6 6-6s6 2.68 6 6c0 3.31-2.69 6-6 6z"/></svg>
                    </div>
                    <span class="stat-label">启用/总配置数</span>
                </div>
                <div class="stat-value"><?php echo $configCount['count']; ?> / <?php echo $totalConfigCount['count']; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon out">
                        <svg viewBox="0 0 24 24"><path d="M4 12l1.41 1.41L11 7.83V20h2V7.83l5.58 5.59L20 12l-8-8-8 8z"/></svg>
                    </div>
                    <span class="stat-label">今日流量</span>
                </div>
                <div class="stat-value"><?php echo AliyunCdt::formatBytes($todayStats['traffic_total'] ?? 0); ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon total">
                        <svg viewBox="0 0 24 24"><path d="M20 12l-1.41-1.41L13 16.17V4h-2v12.17l-5.58-5.59L4 12l8 8 8-8z"/></svg>
                    </div>
                    <span class="stat-label">昨日流量</span>
                </div>
                <div class="stat-value"><?php echo AliyunCdt::formatBytes($yesterdayStats['traffic_total'] ?? 0); ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon month">
                        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
                    </div>
                    <span class="stat-label">本月流量</span>
                </div>
                <div class="stat-value"><?php echo AliyunCdt::formatBytes($monthStats['traffic_total'] ?? 0); ?></div>
            </div>
        </div>
        
        <section id="overview" class="section">
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">各配置流量使用情况</h3>
                </div>
                <?php if (empty($configs)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <p class="empty-state-text">暂无启用的配置，请先在后台添加阿里云AccessKey</p>
                    <a href="admin/config.php?action=add" class="btn btn-primary">添加配置</a>
                </div>
                <?php else: ?>
                <div class="panel-body">
                    <div class="config-gauges">
                        <?php foreach ($configs as $cfg): ?>
                        <?php 
                            $trafficGB = $cfg['month_traffic'] / 1024 / 1024 / 1024;
                            $maxTraffic = floatval($cfg['max_traffic_gb'] ?? 1000);
                            $threshold = intval($cfg['alert_threshold'] ?? 80);
                            $percent = $maxTraffic > 0 ? min(100, round(($trafficGB / $maxTraffic) * 100, 1)) : 0;
                            $inGB = $cfg['month_in'] / 1024 / 1024 / 1024;
                            $outGB = $cfg['month_out'] / 1024 / 1024 / 1024;
                            $remaining = max(0, $maxTraffic - $trafficGB);
                            
                            $statusClass = $percent >= $threshold ? 'danger' : ($percent >= $threshold * 0.8 ? 'warning' : 'safe');
                            $statusText = $percent >= $threshold ? '超限预警' : ($percent >= $threshold * 0.8 ? '接近阈值' : '正常');
                            $barColor = $percent >= $threshold ? '#ef4444' : ($percent >= $threshold * 0.8 ? '#f59e0b' : '#10b981');
                            $circumference = 2 * 3.14159 * 42;
                            $dashoffset = $circumference * (1 - $percent / 100);
                        ?>
                        <div class="config-gauge-card">
                            <div class="config-gauge-header">
                                <span class="config-gauge-name"><?php echo htmlspecialchars($cfg['name']); ?></span>
                                <span class="config-gauge-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            </div>
                            <div class="config-gauge-body">
                                <div class="config-gauge-circle">
                                    <svg width="100" height="100" viewBox="0 0 100 100">
                                        <circle class="gauge-bg-sm" cx="50" cy="50" r="42"/>
                                        <circle class="gauge-fill-sm" cx="50" cy="50" r="42"
                                                style="stroke: <?php echo $barColor; ?>; stroke-dasharray: <?php echo $circumference; ?>; stroke-dashoffset: <?php echo $dashoffset; ?>;"/>
                                    </svg>
                                    <div class="gauge-center-sm">
                                        <div class="gauge-value-sm"><?php echo $percent; ?>%</div>
                                        <div class="gauge-unit-sm">已使用</div>
                                    </div>
                                </div>
                                <div class="config-gauge-stats">
                                    <div class="config-gauge-stat">
                                        <span class="config-gauge-stat-label">已使用</span>
                                        <span class="config-gauge-stat-value"><?php echo number_format($trafficGB, 2); ?> GB</span>
                                    </div>
                                    <div class="config-gauge-stat">
                                        <span class="config-gauge-stat-label">总配额</span>
                                        <span class="config-gauge-stat-value"><?php echo number_format($maxTraffic, 0); ?> GB</span>
                                    </div>
                                    <div class="config-gauge-stat">
                                        <span class="config-gauge-stat-label">剩余</span>
                                        <span class="config-gauge-stat-value" style="color: <?php echo $barColor; ?>;"><?php echo number_format($remaining, 2); ?> GB</span>
                                    </div>
                                    <div class="config-gauge-stat">
                                        <span class="config-gauge-stat-label">提醒阈值</span>
                                        <span class="config-gauge-stat-value"><?php echo $threshold; ?>%</span>
                                    </div>
                                </div>
                            </div>
                            <div class="config-gauge-progress">
                                <div class="config-gauge-progress-bar">
                                    <div class="config-gauge-progress-fill" style="width: <?php echo $percent; ?>%; background: <?php echo $barColor; ?>;"></div>
                                </div>
                                <div class="config-gauge-progress-labels">
                                    <span>入站: <?php echo number_format($inGB, 2); ?> GB</span>
                                    <span>出站: <?php echo number_format($outGB, 2); ?> GB</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
        
        <div class="dashboard-grid">
            <section id="total" class="section">
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">总体流量统计</h3>
                    </div>
                    <div class="total-gauge-container">
                        <div class="total-gauge-wrapper">
                            <svg width="200" height="200" viewBox="0 0 200 200">
                                <defs>
                                    <linearGradient id="gradient-normal" x1="0%" y1="0%" x2="100%" y2="0%">
                                        <stop offset="0%" style="stop-color:#11998e"/>
                                        <stop offset="100%" style="stop-color:#38ef7d"/>
                                    </linearGradient>
                                    <linearGradient id="gradient-warning" x1="0%" y1="0%" x2="100%" y2="0%">
                                        <stop offset="0%" style="stop-color:#f59e0b"/>
                                        <stop offset="100%" style="stop-color:#fbbf24"/>
                                    </linearGradient>
                                    <linearGradient id="gradient-danger" x1="0%" y1="0%" x2="100%" y2="0%">
                                        <stop offset="0%" style="stop-color:#ef4444"/>
                                        <stop offset="100%" style="stop-color:#f87171"/>
                                    </linearGradient>
                                </defs>
                                <circle class="gauge-bg-lg" cx="100" cy="100" r="85"/>
                                <circle class="gauge-fill-lg" cx="100" cy="100" r="85"
                                        style="stroke: url(#gradient-<?php echo $totalTrafficPercent >= 80 ? 'danger' : ($totalTrafficPercent >= 60 ? 'warning' : 'normal'); ?>); stroke-dasharray: <?php echo 2 * 3.14159 * 85; ?>; stroke-dashoffset: <?php echo 2 * 3.14159 * 85 * (1 - $totalTrafficPercent / 100); ?>;"/>
                            </svg>
                            <div class="gauge-center-lg">
                                <div class="gauge-value-lg"><?php echo $totalTrafficPercent; ?>%</div>
                                <div class="gauge-unit-lg">已使用</div>
                            </div>
                        </div>
                        <div class="gauge-label">总流量使用率</div>
                        <div class="gauge-info">
                            <div class="gauge-info-item">
                                <div class="gauge-info-value"><?php echo number_format($totalCurrentTraffic, 1); ?> GB</div>
                                <div class="gauge-info-label">已使用</div>
                            </div>
                            <div class="gauge-info-item">
                                <div class="gauge-info-value"><?php echo number_format($totalMaxTraffic, 1); ?> GB</div>
                                <div class="gauge-info-label">总配额</div>
                            </div>
                            <div class="gauge-info-item">
                                <div class="gauge-info-value"><?php echo number_format(max(0, $totalMaxTraffic - $totalCurrentTraffic), 1); ?> GB</div>
                                <div class="gauge-info-label">剩余</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            
            <section id="trend" class="section">
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">近7天流量趋势</h3>
                    </div>
                    <div class="panel-body">
                        <div class="chart-container">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        
        <section id="ranking" class="section">
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">流量排行 TOP 10</h3>
                </div>
                <?php if (empty($instanceRanking)): ?>
                <div class="empty-state">
                    <p class="empty-state-text">暂无数据</p>
                </div>
                <?php else: ?>
                <div class="ranking-list">
                    <?php foreach ($instanceRanking as $i => $instance): ?>
                    <div class="ranking-item">
                        <div class="ranking-position <?php echo $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : 'normal')); ?>">
                            <?php echo $i + 1; ?>
                        </div>
                        <div class="ranking-name"><?php echo htmlspecialchars($instance['name']); ?></div>
                        <div class="ranking-values">
                            <span class="ranking-value">出: <?php echo AliyunCdt::formatBytes($instance['traffic_out']); ?></span>
                            <span class="ranking-value total"><?php echo AliyunCdt::formatBytes($instance['traffic_total']); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
    
    <footer class="footer">
        <div class="footer-inner">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?></p>
            <p class="update-time">数据更新: <span id="refresh-time"><?php echo $lastRefreshTime ?: date('Y-m-d H:i:s'); ?></span></p>
        </div>
    </footer>
    
    <script>
        const weeklyData = <?php echo json_encode($weeklyStats); ?>;
        const autoRefreshEnabled = <?php echo $autoRefreshEnabled ? 'true' : 'false'; ?>;
        const autoRefreshInterval = <?php echo $autoRefreshInterval; ?>;
        
        const labels = weeklyData.map(item => item.record_date.substring(5));
        const inTraffic = weeklyData.map(item => (item.traffic_in / 1024 / 1024 / 1024).toFixed(2));
        const outTraffic = weeklyData.map(item => (item.traffic_out / 1024 / 1024 / 1024).toFixed(2));
        
        const ctx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: '入流量 (GB)',
                        data: inTraffic,
                        borderColor: '#11998e',
                        backgroundColor: 'rgba(17, 153, 142, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#11998e',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    },
                    {
                        label: '出流量 (GB)',
                        data: outTraffic,
                        borderColor: '#f093fb',
                        backgroundColor: 'rgba(240, 147, 251, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#f093fb',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        position: 'top', 
                        labels: { 
                            usePointStyle: true, 
                            padding: 20,
                            font: { size: 12, weight: '500' }
                        } 
                    },
                    tooltip: { 
                        mode: 'index', 
                        intersect: false,
                        backgroundColor: 'rgba(26, 26, 46, 0.9)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        cornerRadius: 8
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        title: { display: true, text: '流量 (GB)', font: { size: 12 } }, 
                        grid: { color: 'rgba(0, 0, 0, 0.05)' } 
                    },
                    x: { 
                        grid: { display: false },
                        ticks: { font: { size: 11 } }
                    }
                },
                interaction: { mode: 'nearest', axis: 'x', intersect: false }
            }
        });
        
        function refreshData() {
            fetch('api/auto_refresh.php?action=refresh')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('refresh-time').textContent = data.data.refresh_time;
                        console.log('数据刷新成功');
                        setTimeout(function() { location.reload(); }, 1000);
                    }
                })
                .catch(error => console.error('请求失败:', error));
        }
        
        if (autoRefreshEnabled && autoRefreshInterval > 0) {
            setInterval(refreshData, autoRefreshInterval * 60 * 1000);
        }
        
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>
