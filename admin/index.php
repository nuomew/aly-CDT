<?php
/**
 * 管理员后台 - 首页
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

$admin = getCurrentAdmin();
$db = getDb();
$prefix = $db->getPrefix();

$stats = [];

$sql = "SELECT COUNT(*) as count FROM `{$prefix}aliyun_config` WHERE `status` = 1";
$result = $db->fetchOne($sql);
$stats['config_count'] = $result['count'];

$sql = "SELECT COUNT(*) as count FROM `{$prefix}aliyun_config`";
$result = $db->fetchOne($sql);
$stats['config_total'] = $result['count'];

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$dayBefore = date('Y-m-d', strtotime('-2 day'));
$monthStart = date('Y-m-01');

$todayTraffic = 0;
$yesterdayTraffic = 0;

$todaySql = "SELECT instance_id, traffic_total FROM `{$prefix}traffic_records` 
             WHERE `record_date` >= ? AND id IN (SELECT MAX(id) FROM `{$prefix}traffic_records` WHERE `record_date` >= ? GROUP BY instance_id)";
$todayRecords = $db->fetchAll($todaySql, [$monthStart, $monthStart]);
$todayMap = [];
foreach ($todayRecords as $r) {
    $todayMap[$r['instance_id']] = floatval($r['traffic_total']);
}

$ySql = "SELECT instance_id, traffic_total FROM `{$prefix}traffic_records` 
         WHERE `record_date` >= ? AND `record_date` <= ? AND id IN (SELECT MAX(id) FROM `{$prefix}traffic_records` WHERE `record_date` >= ? AND `record_date` <= ? GROUP BY instance_id, DATE(record_date)) AND DATE(record_date) = ?";
$yesterdayRecords = $db->fetchAll($ySql, [$monthStart, $yesterday, $monthStart, $yesterday, $yesterday]);
$yesterdayMap = [];
foreach ($yesterdayRecords as $r) {
    $yesterdayMap[$r['instance_id']] = floatval($r['traffic_total']);
}
$dayBeforeRecords = $db->fetchAll($ySql, [$monthStart, $dayBefore, $monthStart, $dayBefore, $dayBefore]);
$dayBeforeMap = [];
foreach ($dayBeforeRecords as $r) {
    $dayBeforeMap[$r['instance_id']] = floatval($r['traffic_total']);
}

foreach ($todayMap as $instanceId => $todayVal) {
    $yVal = isset($yesterdayMap[$instanceId]) ? $yesterdayMap[$instanceId] : 0;
    $todayTraffic += max(0, $todayVal - $yVal);
}
foreach ($yesterdayMap as $instanceId => $yVal) {
    $dbVal = isset($dayBeforeMap[$instanceId]) ? $dayBeforeMap[$instanceId] : 0;
    $yesterdayTraffic += max(0, $yVal - $dbVal);
}

$stats['today_traffic'] = ['traffic_out' => 0, 'traffic_total' => $todayTraffic];
$stats['yesterday_traffic'] = ['traffic_out' => 0, 'traffic_total' => $yesterdayTraffic];

// 本月流量 - 取最新日期的记录（阿里云返回的是月度累计值）
$sql = "SELECT SUM(traffic_out) as traffic_out, SUM(traffic_total) as traffic_total 
        FROM `{$prefix}traffic_records` 
        WHERE `record_date` = (SELECT MAX(record_date) FROM `{$prefix}traffic_records` WHERE `record_date` >= ?)";
$result = $db->fetchOne($sql, [$monthStart]);
$stats['month_traffic'] = $result ?: ['traffic_out' => 0, 'traffic_total' => 0];

$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
$sql = "SELECT SUM(traffic_out) as traffic_out, SUM(traffic_total) as traffic_total 
        FROM `{$prefix}traffic_records` 
        WHERE `record_date` = (SELECT MAX(record_date) FROM `{$prefix}traffic_records` WHERE `record_date` BETWEEN ? AND ?)";
$result = $db->fetchOne($sql, [$lastMonthStart, $lastMonthEnd]);
$stats['last_month_traffic'] = $result ?: ['traffic_out' => 0, 'traffic_total' => 0];

$sql = "SELECT COUNT(DISTINCT `record_date`) as days FROM `{$prefix}traffic_records` WHERE `record_date` >= ?";
$result = $db->fetchOne($sql, [$monthStart]);
$stats['record_days'] = $result['days'];

$sql = "SELECT COUNT(*) as count FROM `{$prefix}traffic_records` WHERE `record_date` >= ?";
$result = $db->fetchOne($sql, [$monthStart]);
$stats['record_count'] = $result['count'];

$sql = "SELECT `record_date`, SUM(`traffic_total`) as traffic_total 
        FROM `{$prefix}traffic_records` 
        WHERE `record_date` >= DATE_SUB(CURDATE(), INTERVAL 8 DAY) AND `record_date` >= ?
        AND id IN (SELECT MAX(id) FROM `{$prefix}traffic_records` WHERE `record_date` >= DATE_SUB(CURDATE(), INTERVAL 8 DAY) AND `record_date` >= ? GROUP BY instance_id, DATE(record_date))
        GROUP BY `record_date` 
        ORDER BY `record_date` ASC";
$dailyCumulative = $db->fetchAll($sql, [$monthStart, $monthStart]);

$trendData = [];
$prevCum = 0;
foreach ($dailyCumulative as $i => $d) {
    $curCum = floatval($d['traffic_total']);
    $dailyUsage = $i == 0 ? 0 : max(0, $curCum - $prevCum);
    $trendData[] = [
        'record_date' => $d['record_date'],
        'traffic_total' => $dailyUsage
    ];
    $prevCum = $curCum;
}

$sql = "SELECT ac.name, tr.traffic_total 
        FROM `{$prefix}traffic_records` tr
        JOIN `{$prefix}aliyun_config` ac ON tr.config_id = ac.id
        WHERE tr.record_date = (SELECT MAX(record_date) FROM `{$prefix}traffic_records` WHERE `record_date` >= ?)
        ORDER BY tr.traffic_total DESC
        LIMIT 5";
$topInstances = $db->fetchAll($sql, [$monthStart]);

$sql = "SELECT ac.*, 
        COALESCE((SELECT tr2.traffic_total FROM `{$prefix}traffic_records` tr2 WHERE tr2.config_id = ac.id AND tr2.record_date >= ? ORDER BY tr2.record_date DESC LIMIT 1), 0) as month_traffic,
        COALESCE((SELECT tr3.traffic_out FROM `{$prefix}traffic_records` tr3 WHERE tr3.config_id = ac.id AND tr3.record_date >= ? ORDER BY tr3.record_date DESC LIMIT 1), 0) as month_out
        FROM `{$prefix}aliyun_config` ac 
        WHERE ac.status = 1
        ORDER BY ac.is_default DESC, ac.id ASC";
$configTraffic = $db->fetchAll($sql, [$monthStart, $monthStart]);

$todayTotal = ($stats['today_traffic']['traffic_total'] ?? 0) / 1024 / 1024 / 1024;
$yesterdayTotal = ($stats['yesterday_traffic']['traffic_total'] ?? 0) / 1024 / 1024 / 1024;
$dayChange = $yesterdayTotal > 0 ? round(($todayTotal - $yesterdayTotal) / $yesterdayTotal * 100, 1) : 0;

$monthTotal = ($stats['month_traffic']['traffic_total'] ?? 0) / 1024 / 1024 / 1024;
$lastMonthTotal = ($stats['last_month_traffic']['traffic_total'] ?? 0) / 1024 / 1024 / 1024;
$monthChange = $lastMonthTotal > 0 ? round(($monthTotal - $lastMonthTotal) / $lastMonthTotal * 100, 1) : 0;

$monthOutTraffic = ($stats['month_traffic']['traffic_out'] ?? 0) / 1024 / 1024 / 1024;
$monthTotal = $monthOutTraffic;

$chartLabels = [];
$chartData = [];
foreach ($trendData as $row) {
    $chartLabels[] = date('m/d', strtotime($row['record_date']));
    $chartData[] = round($row['traffic_total'] / 1024 / 1024 / 1024, 2);
}

$avgDailyTraffic = $stats['record_days'] > 0 ? $monthTotal / $stats['record_days'] : 0;
$remainingDays = date('t') - date('j');
$predictedTraffic = $monthTotal + ($avgDailyTraffic * $remainingDays);

$totalMaxTraffic = 0;
$totalCurrentTraffic = 0;
foreach ($configTraffic as $cfg) {
    $totalMaxTraffic += floatval($cfg['max_traffic_gb'] ?? 1000);
    $totalCurrentTraffic += $cfg['month_traffic'] / 1024 / 1024 / 1024;
}
$totalTrafficPercent = $totalMaxTraffic > 0 ? min(100, round(($totalCurrentTraffic / $totalMaxTraffic) * 100, 1)) : 0;

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>控制台 - 阿里云流量查询系统</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .gauge-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 30px;
        }
        
        .gauge-wrapper {
            position: relative;
            width: 200px;
            height: 200px;
        }
        
        .gauge-circle {
            transform: rotate(-90deg);
        }
        
        .gauge-bg {
            fill: none;
            stroke: #e5e7eb;
            stroke-width: 12;
        }
        
        .gauge-fill {
            fill: none;
            stroke-width: 12;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease;
        }
        
        .gauge-fill.normal {
            stroke: url(#gradient-normal);
        }
        
        .gauge-fill.warning {
            stroke: url(#gradient-warning);
        }
        
        .gauge-fill.danger {
            stroke: url(#gradient-danger);
        }
        
        .gauge-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        
        .gauge-value {
            font-size: 36px;
            font-weight: 800;
            color: #1a1a2e;
            line-height: 1;
        }
        
        .gauge-unit {
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
            gap: 24px;
            margin-top: 20px;
        }
        
        .gauge-info-item {
            text-align: center;
        }
        
        .gauge-info-value {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .gauge-info-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        .traffic-breakdown {
            padding: 24px;
        }
        
        .breakdown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .breakdown-title {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .breakdown-total {
            font-size: 24px;
            font-weight: 800;
            color: #667eea;
        }
        
        .breakdown-bars {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .breakdown-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .breakdown-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .breakdown-item-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
        }
        
        .breakdown-item-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        .breakdown-item-dot.in {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .breakdown-item-dot.out {
            background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%);
        }
        
        .breakdown-item-value {
            font-size: 14px;
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .breakdown-bar {
            height: 12px;
            background: #f3f4f6;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .breakdown-bar-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.5s ease;
        }
        
        .breakdown-bar-fill.in {
            background: linear-gradient(90deg, #11998e 0%, #38ef7d 100%);
        }
        
        .breakdown-bar-fill.out {
            background: linear-gradient(90deg, #ee0979 0%, #ff6a00 100%);
        }
        
        .chart-container {
            padding: 24px;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-title {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .chart-wrapper {
            height: 250px;
            position: relative;
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
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
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
        
        .ranking-value {
            font-size: 14px;
            font-weight: 700;
            color: #667eea;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .mini-stat {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .mini-stat-value {
            font-size: 22px;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 4px;
        }
        
        .mini-stat-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .prediction-box {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-radius: 12px;
            padding: 16px 20px;
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .prediction-label {
            font-size: 13px;
            color: #6b7280;
        }
        
        .prediction-value {
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
        }
        
        .config-gauges {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            padding: 24px;
        }
        
        .config-gauge-card {
            flex: 1 1 calc(50% - 10px);
            min-width: 280px;
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
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
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
        
        .config-gauge-circle .gauge-bg-sm {
            fill: none;
            stroke: #e5e7eb;
            stroke-width: 8;
        }
        
        .config-gauge-circle .gauge-fill-sm {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .config-gauge-circle .gauge-center-sm {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        
        .config-gauge-circle .gauge-value-sm {
            font-size: 20px;
            font-weight: 800;
            color: #1a1a2e;
        }
        
        .config-gauge-circle .gauge-unit-sm {
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
        
        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            .config-gauges .config-gauge-card {
                flex: 1 1 100%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php $currentPage = 'index'; include __DIR__ . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <h1>控制台</h1>
                </div>
                <div class="topbar-right">
                    <span class="admin-name">欢迎，<?php echo htmlspecialchars($admin['username']); ?></span>
                </div>
            </header>
            
            <div class="content">
                <div class="stats-row">
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo $stats['config_count']; ?> / <?php echo $stats['config_total']; ?></div>
                        <div class="mini-stat-label">启用/总配置数</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo number_format($todayTotal, 2); ?> GB</div>
                        <div class="mini-stat-label">今日流量</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo number_format($yesterdayTotal, 2); ?> GB</div>
                        <div class="mini-stat-label">昨日流量</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo number_format($monthTotal, 2); ?> GB</div>
                        <div class="mini-stat-label">本月流量</div>
                    </div>
                </div>
                
                <div class="panel">
                    <div class="panel-header">
                        <h3>各配置流量使用仪表盘</h3>
                        <a href="config.php" class="view-all">管理配置</a>
                    </div>
                    <?php if (empty($configTraffic)): ?>
                    <div style="padding: 60px; text-align: center; color: #6b7280;">
                        <p>暂无启用的配置</p>
                        <a href="config.php?action=add" class="btn btn-primary" style="margin-top: 16px;">添加配置</a>
                    </div>
                    <?php else: ?>
                    <div class="config-gauges">
                        <?php foreach ($configTraffic as $index => $cfg): ?>
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
                    <?php endif; ?>
                </div>
                
                <div class="dashboard-grid">
                    <div class="panel">
                        <div class="panel-header">
                            <h3>总体流量统计</h3>
                        </div>
                        <div class="gauge-container">
                            <div class="gauge-wrapper">
                                <svg class="gauge-circle" width="200" height="200" viewBox="0 0 200 200">
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
                                    <circle class="gauge-bg" cx="100" cy="100" r="85"/>
                                    <circle class="gauge-fill <?php echo $totalTrafficPercent >= 80 ? 'danger' : ($totalTrafficPercent >= 60 ? 'warning' : 'normal'); ?>" 
                                            cx="100" cy="100" r="85"
                                            stroke-dasharray="<?php echo 2 * 3.14159 * 85; ?>"
                                            stroke-dashoffset="<?php echo 2 * 3.14159 * 85 * (1 - $totalTrafficPercent / 100); ?>"/>
                                </svg>
                                <div class="gauge-center">
                                    <div class="gauge-value"><?php echo $totalTrafficPercent; ?>%</div>
                                    <div class="gauge-unit">已使用</div>
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
                            <div class="prediction-box">
                                <span class="prediction-label">预计月底流量</span>
                                <span class="prediction-value"><?php echo number_format($predictedTraffic, 2); ?> GB</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="panel">
                        <div class="panel-header">
                            <h3>流量统计</h3>
                        </div>
                        <div class="traffic-breakdown">
                            <div class="breakdown-header">
                                <span class="breakdown-title">本月出站流量</span>
                                <span class="breakdown-total"><?php echo number_format($monthTotal, 2); ?> GB</span>
                            </div>
                            <div class="breakdown-bars">
                                <div class="breakdown-item">
                                    <div class="breakdown-item-header">
                                        <span class="breakdown-item-label">
                                            <span class="breakdown-item-dot out"></span>
                                            出站流量
                                        </span>
                                        <span class="breakdown-item-value"><?php echo number_format($monthOutTraffic, 2); ?> GB</span>
                                    </div>
                                    <div class="breakdown-bar">
                                        <div class="breakdown-bar-fill out" style="width: 100%"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid rgba(0,0,0,0.05);">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                                    <span style="font-size: 14px; color: #6b7280;">今日 vs 昨日</span>
                                    <span style="font-size: 14px; font-weight: 600; color: <?php echo $dayChange >= 0 ? '#059669' : '#dc2626'; ?>;">
                                        <?php echo $dayChange >= 0 ? '+' : ''; ?><?php echo $dayChange; ?>%
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="font-size: 14px; color: #6b7280;">本月 vs 上月</span>
                                    <span style="font-size: 14px; font-weight: 600; color: <?php echo $monthChange >= 0 ? '#059669' : '#dc2626'; ?>;">
                                        <?php echo $monthChange >= 0 ? '+' : ''; ?><?php echo $monthChange; ?>%
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-grid">
                    <div class="panel">
                        <div class="panel-header">
                            <h3>近7天流量趋势</h3>
                        </div>
                        <div class="chart-container">
                            <div class="chart-wrapper">
                                <canvas id="trendChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="panel">
                        <div class="panel-header">
                            <h3>实例流量排行 TOP 5</h3>
                            <a href="traffic.php" class="view-all">查看全部</a>
                        </div>
                        <div class="ranking-list">
                            <?php if (empty($topInstances)): ?>
                            <div style="padding: 40px; text-align: center; color: #6b7280;">暂无数据</div>
                            <?php else: ?>
                            <?php foreach ($topInstances as $index => $instance): ?>
                            <div class="ranking-item">
                                <div class="ranking-position <?php echo $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : 'normal')); ?>">
                                    <?php echo $index + 1; ?>
                                </div>
                                <div class="ranking-name"><?php echo htmlspecialchars($instance['name']); ?></div>
                                <div class="ranking-value"><?php echo number_format($instance['traffic_total'] / 1024 / 1024 / 1024, 2); ?> GB</div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        var ctx = document.getElementById('trendChart').getContext('2d');
        var trendChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: '每日流量 (GB)',
                    data: <?php echo json_encode($chartData); ?>,
                    backgroundColor: 'rgba(102, 126, 234, 0.6)',
                    borderColor: '#667eea',
                    borderWidth: 2,
                    borderRadius: 6,
                    hoverBackgroundColor: 'rgba(102, 126, 234, 0.8)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(26, 26, 46, 0.9)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                return '使用: ' + context.parsed.y + ' GB';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#6b7280'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            color: '#6b7280',
                            callback: function(value) {
                                return value + ' GB';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
