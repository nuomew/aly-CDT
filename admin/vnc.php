<?php
/**
 * 管理员后台 - VNC远程连接
 * 通过阿里云VNC连接ECS实例
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunEcs.php';

$admin = getCurrentAdmin();
$db = getDb();
$prefix = $db->getPrefix();

$instanceId = trim($_GET['instance_id'] ?? '');
$configId = intval($_GET['config_id'] ?? 0);

if (empty($instanceId) || $configId <= 0) {
    die('参数错误：实例ID或配置ID无效');
}

$config = $db->fetchOne("SELECT * FROM `{$prefix}aliyun_config` WHERE `id` = ? AND `status` = 1", [$configId]);
if (!$config) {
    die('配置不存在或已禁用');
}

$secret = Helper::decrypt($config['access_key_secret']);
if (empty($secret)) {
    die('AccessKey Secret解密失败，请重新配置');
}

$ecs = new AliyunEcs($config['access_key_id'], $secret, $config['region_id']);

$vncResult = $ecs->describeInstanceVncUrl($instanceId);
if (!$vncResult['success']) {
    die('获取VNC地址失败: ' . ($vncResult['error'] ?? '未知错误'));
}

$vncUrl = $vncResult['data']['VncUrl'] ?? '';
if (empty($vncUrl)) {
    die('获取VNC地址失败: 返回地址为空');
}

$instanceInfo = $ecs->describeInstanceAttribute($instanceId);
$instanceName = $instanceId;
$osType = 'linux';
if ($instanceInfo['success']) {
    $instanceName = $instanceInfo['data']['InstanceName'] ?? $instanceId;
    $osName = $instanceInfo['data']['OSName'] ?? '';
    $osType = AliyunEcs::getOsType($osName);
}

$regions = [
    'cn-hangzhou' => '华东1(杭州)',
    'cn-shanghai' => '华东2(上海)',
    'cn-qingdao' => '华北1(青岛)',
    'cn-beijing' => '华北2(北京)',
    'cn-zhangjiakou' => '华北3(张家口)',
    'cn-huhehaote' => '华北5(呼和浩特)',
    'cn-wulanchabu' => '华北6(乌兰察布)',
    'cn-shenzhen' => '华南1(深圳)',
    'cn-heyuan' => '华南2(河源)',
    'cn-guangzhou' => '华南3(广州)',
    'cn-chengdu' => '西南1(成都)',
    'cn-nanjing' => '华东5(南京)',
    'cn-fuzhou' => '华东6(福州)',
    'cn-hongkong' => '中国香港',
    'ap-southeast-1' => '新加坡',
    'ap-southeast-2' => '澳大利亚(悉尼)',
    'ap-southeast-3' => '马来西亚(吉隆坡)',
    'ap-southeast-5' => '印度尼西亚(雅加达)',
    'ap-southeast-6' => '菲律宾(马尼拉)',
    'ap-southeast-7' => '泰国(曼谷)',
    'ap-northeast-1' => '日本(东京)',
    'ap-northeast-2' => '韩国(首尔)',
    'ap-south-1' => '印度(孟买)',
    'us-east-1' => '美国(弗吉尼亚)',
    'us-west-1' => '美国(硅谷)',
    'eu-west-1' => '英国(伦敦)',
    'eu-central-1' => '德国(法兰克福)',
    'me-east-1' => '阿联酋(迪拜)',
    'me-central-1' => '沙特(利雅得)'
];

$fullVncUrl = 'https://g.alicdn.com/aliyun/ecs-console-vnc2/0.0.8/index.html?vncUrl=' . urlencode($vncUrl) . '&instanceId=' . urlencode($instanceId) . '&isWindows=' . ($osType === 'windows' ? 'true' : 'false');

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VNC远程连接 - <?php echo htmlspecialchars($instanceName); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            height: 100%;
            overflow: hidden;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #0f172a;
            display: flex;
            flex-direction: column;
        }
        
        .vnc-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            flex-shrink: 0;
            height: 60px;
        }
        
        .vnc-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .vnc-title-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .vnc-title-text {
            color: #fff;
        }
        
        .vnc-title-name {
            font-size: 16px;
            font-weight: 700;
        }
        
        .vnc-title-id {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 2px;
        }
        
        .vnc-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .vnc-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .vnc-badge.os {
            background: rgba(102, 126, 234, 0.2);
            color: #a5b4fc;
        }
        
        .vnc-badge.region {
            background: rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
        }
        
        .vnc-actions {
            display: flex;
            gap: 10px;
        }
        
        .vnc-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .vnc-btn.refresh {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        
        .vnc-btn.refresh:hover {
            background: rgba(59, 130, 246, 0.3);
        }
        
        .vnc-btn.close {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        
        .vnc-btn.close:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        
        .vnc-tips {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
            padding: 8px 16px;
            font-size: 12px;
            color: #fcd34d;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
            height: 36px;
        }
        
        .vnc-tips svg {
            flex-shrink: 0;
        }
        
        .vnc-container {
            flex: 1;
            position: relative;
            overflow: hidden;
        }
        
        .vnc-iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
            background: #000;
        }
        
        .vnc-loading {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            z-index: 10;
            transition: opacity 0.3s;
        }
        
        .vnc-loading.hidden {
            opacity: 0;
            pointer-events: none;
        }
        
        .vnc-loading-spinner {
            width: 48px;
            height: 48px;
            border: 3px solid rgba(102, 126, 234, 0.2);
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .vnc-loading-text {
            color: #94a3b8;
            font-size: 14px;
            margin-top: 16px;
        }
        
        @media (max-width: 768px) {
            .vnc-header {
                flex-direction: column;
                gap: 12px;
                padding: 12px 16px;
                height: auto;
            }
            
            .vnc-info {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .vnc-actions {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="vnc-header">
        <div class="vnc-title">
            <div class="vnc-title-icon">🖥️</div>
            <div class="vnc-title-text">
                <div class="vnc-title-name"><?php echo htmlspecialchars($instanceName); ?></div>
                <div class="vnc-title-id"><?php echo htmlspecialchars($instanceId); ?></div>
            </div>
        </div>
        <div class="vnc-info">
            <span class="vnc-badge os">
                <?php echo $osType === 'windows' ? '🪟' : '🐧'; ?>
                <?php echo $osType === 'windows' ? 'Windows' : 'Linux'; ?>
            </span>
            <span class="vnc-badge region">
                🌍 <?php echo $regions[$config['region_id']] ?? $config['region_id']; ?>
            </span>
        </div>
        <div class="vnc-actions">
            <button class="vnc-btn refresh" onclick="refreshVnc()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M23 4v6h-6M1 20v-6h6"/>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                </svg>
                刷新连接
            </button>
            <button class="vnc-btn close" onclick="closeWindow()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
                关闭
            </button>
        </div>
    </div>
    
    <div class="vnc-tips">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span>VNC连接地址有效期为15秒，如连接失败请点击"刷新连接"重新获取。Linux系统请使用控制台登录，Windows系统可通过远程桌面或控制台操作。</span>
    </div>
    
    <div class="vnc-container">
        <div class="vnc-loading" id="vncLoading">
            <div class="vnc-loading-spinner"></div>
            <div class="vnc-loading-text">正在建立VNC连接...</div>
        </div>
        <iframe class="vnc-iframe" id="vncIframe" src="<?php echo htmlspecialchars($fullVncUrl); ?>"></iframe>
    </div>
    
    <script>
        document.getElementById('vncIframe').onload = function() {
            setTimeout(function() {
                document.getElementById('vncLoading').classList.add('hidden');
            }, 500);
        };
        
        function refreshVnc() {
            location.reload();
        }
        
        function closeWindow() {
            if (window.opener) {
                window.close();
            } else {
                window.location.href = 'server.php';
            }
        }
    </script>
</body>
</html>
