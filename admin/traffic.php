<?php
/**
 * 管理员后台 - 流量统计
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunCdt.php';

$admin = getCurrentAdmin();
$db = getDb();
$prefix = $db->getPrefix();

// 获取阿里云配置
$configs = $db->fetchAll("SELECT * FROM `{$prefix}aliyun_config` WHERE `status` = 1 ORDER BY `is_default` DESC");

// 处理刷新流量数据
if (isset($_GET['action']) && $_GET['action'] === 'refresh') {
    $success = 0;
    $fail = 0;
    $today = date('Y-m-d');
    
    foreach ($configs as $config) {
        $secret = Helper::decrypt($config['access_key_secret']);
        $cdt = new AliyunCdt($config['access_key_id'], $secret, $config['region_id']);
        
        $billingCycle = date('Y-m');
        $result = $cdt->describeInternetTraffic($billingCycle);
        
        if ($result['success']) {
            $trafficData = AliyunCdt::parseTrafficData($result['data']);
            
            foreach ($trafficData['list'] as $item) {
                $exists = $db->fetchOne(
                    "SELECT id FROM `{$prefix}traffic_records` WHERE `config_id` = ? AND `instance_id` = ? AND `record_date` = ?",
                    [$config['id'], $item['instanceId'], $today]
                );
                
                $displayName = $config['name'];
                if ($item['billingItem']) {
                    $displayName .= ' - ' . $item['billingItem'];
                }
                
                $trafficBytes = $item['usage'] * 1024 * 1024 * 1024;
                
                if (!$exists) {
                    $db->insert('traffic_records', [
                        'config_id' => $config['id'],
                        'instance_id' => $item['instanceId'],
                        'instance_name' => $displayName,
                        'instance_type' => $item['billingItem'],
                        'region_id' => $config['region_id'],
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
    
    logOperation('refresh_traffic', 'traffic', "刷新流量数据: 成功{$success}个, 失败{$fail}个");
    echo '<script>window.location.href="traffic.php?msg=refresh_success";</script>';
    exit;
}

// 获取流量统计
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$configId = intval($_GET['config_id'] ?? 0);

$where = "WHERE `record_date` BETWEEN ? AND ?";
$params = [$startDate, $endDate];

if ($configId > 0) {
    $where .= " AND `config_id` = ?";
    $params[] = $configId;
}

// 按日期统计
$sql = "SELECT `record_date`, SUM(`traffic_in`) as traffic_in, SUM(`traffic_out`) as traffic_out, SUM(`traffic_total`) as traffic_total 
        FROM `{$prefix}traffic_records` 
        {$where} 
        GROUP BY `record_date` 
        ORDER BY `record_date` ASC";
$dailyStats = $db->fetchAll($sql, $params);

// 按实例统计 - 取每个实例最新记录的值
$sql = "SELECT `instance_id`, `instance_name`, `traffic_in`, `traffic_out`, `traffic_total` 
        FROM `{$prefix}traffic_records` 
        WHERE `id` IN (
            SELECT MAX(id) FROM `{$prefix}traffic_records` 
            WHERE `record_date` BETWEEN ? AND ? 
            GROUP BY `instance_id`
        )
        ORDER BY `traffic_total` DESC 
        LIMIT 20";
$instanceStats = $db->fetchAll($sql, [$startDate, $endDate]);

// 总计 - 取最新日期所有记录的SUM
$sql = "SELECT SUM(`traffic_in`) as traffic_in, SUM(`traffic_out`) as traffic_out, SUM(`traffic_total`) as traffic_total 
        FROM `{$prefix}traffic_records` 
        WHERE `record_date` = (SELECT MAX(record_date) FROM `{$prefix}traffic_records` WHERE `record_date` BETWEEN ? AND ?)";
$totalStats = $db->fetchOne($sql, [$startDate, $endDate]);

// 今日流量
$today = date('Y-m-d');
$sql = "SELECT SUM(`traffic_in`) as traffic_in, SUM(`traffic_out`) as traffic_out, SUM(`traffic_total`) as traffic_total 
        FROM `{$prefix}traffic_records` 
        WHERE `record_date` = ?";
$todayStats = $db->fetchOne($sql, [$today]);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>流量统计 - 阿里云流量查询系统</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="admin-wrapper">
        <?php $currentPage = 'traffic'; include __DIR__ . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <h1>流量统计</h1>
                </div>
                <div class="topbar-right">
                    <a href="?action=refresh" class="btn btn-primary btn-sm" onclick="return confirm('确定要刷新流量数据吗？')">刷新数据</a>
                </div>
            </header>
            
            <div class="content">
                <!-- 筛选条件 -->
                <div class="panel">
                    <div class="panel-body">
                        <form method="get" action="" class="filter-form">
                            <div class="filter-row">
                                <div class="filter-item">
                                    <label>开始日期</label>
                                    <input type="date" name="start_date" value="<?php echo $startDate; ?>">
                                </div>
                                <div class="filter-item">
                                    <label>结束日期</label>
                                    <input type="date" name="end_date" value="<?php echo $endDate; ?>">
                                </div>
                                <div class="filter-item">
                                    <label>阿里云配置</label>
                                    <select name="config_id">
                                        <option value="0">全部</option>
                                        <?php foreach ($configs as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $configId == $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-item">
                                    <button type="submit" class="btn btn-primary btn-sm">查询</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- 统计卡片 -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo AliyunCdt::formatBytes($todayStats['traffic_total'] ?? 0); ?></div>
                            <div class="stat-label">今日流量</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo AliyunCdt::formatBytes($totalStats['traffic_in'] ?? 0); ?></div>
                            <div class="stat-label">入流量</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="19" x2="12" y2="5"></line>
                                <polyline points="5 12 12 5 19 12"></polyline>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo AliyunCdt::formatBytes($totalStats['traffic_out'] ?? 0); ?></div>
                            <div class="stat-label">出流量</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                                <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo AliyunCdt::formatBytes($totalStats['traffic_total'] ?? 0); ?></div>
                            <div class="stat-label">总流量</div>
                        </div>
                    </div>
                </div>
                
                <!-- 流量趋势图 -->
                <div class="panel">
                    <div class="panel-header">
                        <h3>流量趋势</h3>
                    </div>
                    <div class="panel-body">
                        <canvas id="trafficChart" height="300"></canvas>
                    </div>
                </div>
                
                <!-- 实例流量排行 -->
                <div class="panel">
                    <div class="panel-header">
                        <h3>流量排行 TOP 20</h3>
                    </div>
                    <div class="panel-body">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>排名</th>
                                    <th>配置名称</th>
                                    <th>入流量</th>
                                    <th>出流量</th>
                                    <th>总流量</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($instanceStats)): ?>
                                <tr>
                                    <td colspan="5" class="empty-data">暂无数据，请先点击"刷新数据"获取最新流量信息</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($instanceStats as $i => $stat): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($stat['instance_name'] ?: $stat['instance_id']); ?></strong></td>
                                    <td><?php echo AliyunCdt::formatBytes($stat['traffic_in']); ?></td>
                                    <td><?php echo AliyunCdt::formatBytes($stat['traffic_out']); ?></td>
                                    <td><strong><?php echo AliyunCdt::formatBytes($stat['traffic_total']); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // 流量趋势图
        const ctx = document.getElementById('trafficChart').getContext('2d');
        const dailyData = <?php echo json_encode($dailyStats); ?>;
        
        const labels = dailyData.map(item => item.record_date);
        const inTraffic = dailyData.map(item => (item.traffic_in / 1024 / 1024 / 1024).toFixed(2));
        const outTraffic = dailyData.map(item => (item.traffic_out / 1024 / 1024 / 1024).toFixed(2));
        
        new Chart(ctx, {
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
                        tension: 0.4
                    },
                    {
                        label: '出流量 (GB)',
                        data: outTraffic,
                        borderColor: '#ee0979',
                        backgroundColor: 'rgba(238, 9, 121, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: '流量 (GB)'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
