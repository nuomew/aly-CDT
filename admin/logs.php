<?php
/**
 * 管理员后台 - 操作日志
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

$admin = getCurrentAdmin();
$db = getDb();
$prefix = $db->getPrefix();

// 分页参数
$page = intval($_GET['page'] ?? 1);
$pageSize = 20;
$offset = ($page - 1) * $pageSize;

// 获取总数
$total = $db->fetchColumn("SELECT COUNT(*) FROM `{$prefix}operation_logs`");

// 获取日志列表
$sql = "SELECT * FROM `{$prefix}operation_logs` ORDER BY `created_at` DESC LIMIT {$offset}, {$pageSize}";
$logs = $db->fetchAll($sql);

// 计算总页数
$totalPages = ceil($total / $pageSize);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操作日志 - 阿里云流量查询系统</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <?php $currentPage = 'logs'; include __DIR__ . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <h1>操作日志</h1>
                </div>
                <div class="topbar-right">
                    <span class="admin-name">欢迎，<?php echo htmlspecialchars($admin['username']); ?></span>
                </div>
            </header>
            
            <div class="content">
                <div class="panel">
                    <div class="panel-header">
                        <h3>日志列表</h3>
                        <span class="total-count">共 <?php echo $total; ?> 条记录</span>
                    </div>
                    <div class="panel-body">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>时间</th>
                                    <th>用户</th>
                                    <th>操作</th>
                                    <th>模块</th>
                                    <th>内容</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="7" class="empty-data">暂无操作日志</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo $log['id']; ?></td>
                                    <td><?php echo $log['created_at']; ?></td>
                                    <td><?php echo htmlspecialchars($log['username']); ?></td>
                                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                                    <td><?php echo htmlspecialchars($log['module']); ?></td>
                                    <td><?php echo htmlspecialchars($log['content'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($log['ip']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        
                        <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                            <a href="?page=1" class="page-btn">首页</a>
                            <a href="?page=<?php echo $page - 1; ?>" class="page-btn">上一页</a>
                            <?php endif; ?>
                            
                            <span class="page-info">第 <?php echo $page; ?> / <?php echo $totalPages; ?> 页</span>
                            
                            <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>" class="page-btn">下一页</a>
                            <a href="?page=<?php echo $totalPages; ?>" class="page-btn">末页</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
