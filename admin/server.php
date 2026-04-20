<?php
/**
 * 管理员后台 - 服务器管理
 * 管理阿里云ECS实例
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunEcs.php';

$admin = getCurrentAdmin();
$db = getDb();
$prefix = $db->getPrefix();

$action = $_GET['action'] ?? 'list';
$instanceId = $_GET['instance_id'] ?? '';
$configId = intval($_GET['config_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['operation'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $opInstanceId = trim($_POST['instance_id'] ?? '');
    $opConfigId = intval($_POST['config_id'] ?? 0);
    $operation = trim($_POST['operation'] ?? '');
    
    if (empty($opInstanceId) || $opConfigId <= 0) {
        echo json_encode(['success' => false, 'message' => '参数错误：实例ID或配置ID无效']);
        exit;
    }
    
    $validOps = ['start', 'stop', 'reboot', 'reinstall', 'reinstall_async', 'get_status', 'auto_start_reinstall', 'load_images', 'get_vnc_url'];
    if (!in_array($operation, $validOps)) {
        echo json_encode(['success' => false, 'message' => '无效的操作类型: ' . $operation]);
        exit;
    }
    
    $config = $db->fetchOne("SELECT * FROM `{$prefix}aliyun_config` WHERE `id` = ? AND `status` = 1", [$opConfigId]);
    if (!$config) {
        echo json_encode(['success' => false, 'message' => '配置不存在或已禁用']);
        exit;
    }
    
    $secret = Helper::decrypt($config['access_key_secret']);
    if (empty($secret)) {
        echo json_encode(['success' => false, 'message' => 'AccessKey Secret解密失败，请重新配置']);
        exit;
    }
    
    $ecs = new AliyunEcs($config['access_key_id'], $secret, $config['region_id']);
    
    $result = ['success' => false, 'message' => '未知操作'];
    
    switch ($operation) {
        case 'start':
            $apiResult = $ecs->startInstance($opInstanceId);
            if ($apiResult['success']) {
                $result = ['success' => true, 'message' => '开机指令已发送，实例正在启动中'];
                logOperation('start_instance', 'server', "实例 {$opInstanceId} 开机");
            } else {
                $result = ['success' => false, 'message' => '开机失败: ' . ($apiResult['error'] ?? '未知错误')];
            }
            break;
            
        case 'stop':
            $forceStop = isset($_POST['force']) && $_POST['force'] === 'true';
            $apiResult = $ecs->stopInstance($opInstanceId, $forceStop);
            if ($apiResult['success']) {
                $result = ['success' => true, 'message' => $forceStop ? '强制关机指令已发送，实例正在停止中' : '关机指令已发送，实例正在停止中'];
                logOperation('stop_instance', 'server', "实例 {$opInstanceId} " . ($forceStop ? '强制关机' : '关机'));
            } else {
                $result = ['success' => false, 'message' => ($forceStop ? '强制关机失败: ' : '关机失败: ') . ($apiResult['error'] ?? '未知错误')];
            }
            break;
            
        case 'reboot':
            $forceStop = isset($_POST['force']) && $_POST['force'] === 'true';
            $apiResult = $ecs->rebootInstance($opInstanceId, $forceStop);
            if ($apiResult['success']) {
                $result = ['success' => true, 'message' => $forceStop ? '强制重启指令已发送，实例正在重启中' : '重启指令已发送，实例正在重启中'];
                logOperation('reboot_instance', 'server', "实例 {$opInstanceId} " . ($forceStop ? '强制重启' : '重启'));
            } else {
                $result = ['success' => false, 'message' => ($forceStop ? '强制重启失败: ' : '重启失败: ') . ($apiResult['error'] ?? '未知错误')];
            }
            break;
            
        case 'reinstall':
        case 'reinstall_async':
            $imageId = trim($_POST['image_id'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $keyPairName = trim($_POST['key_pair_name'] ?? '');
            $systemDiskSize = intval($_POST['system_disk_size'] ?? 0);
            
            if (empty($imageId)) {
                $result = ['success' => false, 'message' => '请选择镜像'];
                break;
            }
            if (empty($password) && empty($keyPairName)) {
                $result = ['success' => false, 'message' => '请设置登录密码或选择密钥对'];
                break;
            }
            
            $instanceInfo = $ecs->describeInstanceAttribute($opInstanceId);
            if (!$instanceInfo['success']) {
                $result = ['success' => false, 'message' => '获取实例状态失败: ' . ($instanceInfo['error'] ?? '未知错误')];
                break;
            }
            
            $currentStatus = $instanceInfo['data']['Status'] ?? '';
            
            if ($currentStatus === 'Running') {
                $stopResult = $ecs->stopInstance($opInstanceId);
                if (!$stopResult['success']) {
                    $result = ['success' => false, 'message' => '停止实例失败: ' . ($stopResult['error'] ?? '未知错误')];
                    break;
                }
                $result = [
                    'success' => true, 
                    'message' => '任务已提交，正在停止实例...',
                    'status' => 'stopping',
                    'instance_id' => $opInstanceId,
                    'config_id' => $opConfigId,
                    'image_id' => $imageId,
                    'password' => $password,
                    'key_pair_name' => $keyPairName,
                    'system_disk_size' => $systemDiskSize
                ];
                logOperation('reinstall_system', 'server', "实例 {$opInstanceId} 开始重装流程，正在停止实例");
            } elseif ($currentStatus === 'Stopped') {
                $apiResult = $ecs->replaceSystemDisk($opInstanceId, $imageId, $password, $keyPairName, $systemDiskSize);
                if ($apiResult['success']) {
                    $result = [
                        'success' => true, 
                        'message' => '任务已提交，正在重装系统...',
                        'status' => 'reinstalling',
                        'instance_id' => $opInstanceId,
                        'config_id' => $opConfigId
                    ];
                    logOperation('reinstall_system', 'server', "实例 {$opInstanceId} 重装系统，镜像: {$imageId}");
                } else {
                    $result = ['success' => false, 'message' => '重装系统失败: ' . ($apiResult['error'] ?? '未知错误')];
                }
            } else {
                $result = ['success' => false, 'message' => "实例当前状态为 {$currentStatus}，无法重装系统，请等待实例停止后再试"];
            }
            break;
            
        case 'get_status':
            $statusResult = $ecs->describeInstanceAttribute($opInstanceId);
            if ($statusResult['success']) {
                $status = $statusResult['data']['Status'] ?? '';
                $result = [
                    'success' => true, 
                    'status' => $status,
                    'status_text' => AliyunEcs::formatStatus($status),
                    'instance_id' => $opInstanceId
                ];
            } else {
                $result = ['success' => false, 'message' => '获取状态失败: ' . ($statusResult['error'] ?? '未知错误')];
            }
            break;
            
        case 'auto_start_reinstall':
            $reinstallImageId = trim($_POST['image_id'] ?? '');
            $reinstallPassword = trim($_POST['password'] ?? '');
            $reinstallKeyPair = trim($_POST['key_pair_name'] ?? '');
            $reinstallDiskSize = intval($_POST['system_disk_size'] ?? 0);
            $currentPhase = trim($_POST['phase'] ?? '');
            
            $statusResult = $ecs->describeInstanceAttribute($opInstanceId);
            if (!$statusResult['success']) {
                $result = ['success' => false, 'message' => '获取状态失败: ' . ($statusResult['error'] ?? '未知错误')];
                break;
            }
            
            $status = $statusResult['data']['Status'] ?? '';
            
            if ($currentPhase === 'stopping') {
                if ($status === 'Stopped') {
                    if (empty($reinstallImageId)) {
                        $result = ['success' => false, 'message' => '缺少镜像ID'];
                        break;
                    }
                    $apiResult = $ecs->replaceSystemDisk($opInstanceId, $reinstallImageId, $reinstallPassword, $reinstallKeyPair, $reinstallDiskSize);
                    if ($apiResult['success']) {
                        $result = [
                            'success' => true, 
                            'message' => '实例已停止，正在重装系统...',
                            'status' => 'reinstalling',
                            'instance_id' => $opInstanceId
                        ];
                        logOperation('reinstall_system', 'server', "实例 {$opInstanceId} 重装系统，镜像: {$reinstallImageId}");
                    } else {
                        $result = ['success' => false, 'message' => '重装系统失败: ' . ($apiResult['error'] ?? '未知错误')];
                    }
                } else {
                    $result = [
                        'success' => true, 
                        'message' => '正在等待实例停止...',
                        'status' => 'stopping',
                        'current_status' => $status
                    ];
                }
            } elseif ($currentPhase === 'reinstalling') {
                $operationLocks = $statusResult['data']['OperationLocks']['LockReason'] ?? [];
                $isLocked = false;
                if (is_array($operationLocks) && !empty($operationLocks)) {
                    foreach ($operationLocks as $lock) {
                        if (isset($lock['LockReason'])) {
                            $isLocked = true;
                            break;
                        }
                    }
                }
                
                if ($status === 'Stopped' && !$isLocked) {
                    $startResult = $ecs->startInstance($opInstanceId);
                    if ($startResult['success']) {
                        $result = [
                            'success' => true, 
                            'message' => '重装完成，正在开机...',
                            'status' => 'starting',
                            'instance_id' => $opInstanceId
                        ];
                        logOperation('start_instance', 'server', "实例 {$opInstanceId} 重装完成后自动开机");
                    } else {
                        $result = ['success' => false, 'message' => '开机失败: ' . ($startResult['error'] ?? '未知错误')];
                    }
                } else {
                    $statusText = $isLocked ? '实例正在重装中...' : '正在重装系统，请稍候...';
                    $result = [
                        'success' => true, 
                        'message' => $statusText,
                        'status' => 'reinstalling',
                        'current_status' => $status,
                        'is_locked' => $isLocked
                    ];
                }
            } elseif ($currentPhase === 'starting') {
                if ($status === 'Running') {
                    $result = [
                        'success' => true, 
                        'message' => '重装系统完成，实例已启动！',
                        'status' => 'completed',
                        'instance_id' => $opInstanceId
                    ];
                } else {
                    $result = [
                        'success' => true, 
                        'message' => '实例正在启动...',
                        'status' => 'starting',
                        'current_status' => $status
                    ];
                }
            } else {
                $result = ['success' => false, 'message' => '未知阶段'];
            }
            break;
            
        case 'load_images':
            $imageOwnerAlias = trim($_POST['image_owner_alias'] ?? 'system');
            $ostype = trim($_POST['ostype'] ?? '');
            $apiResult = $ecs->describeImages($imageOwnerAlias, '', $ostype, 1, 100);
            if ($apiResult['success']) {
                $images = $apiResult['data']['Images']['Image'] ?? [];
                if (isset($images['ImageId'])) {
                    $images = [$images];
                }
                $imageList = [];
                foreach ($images as $img) {
                    $imageList[] = [
                        'imageId' => $img['ImageId'] ?? '',
                        'osName' => $img['OSName'] ?? ($img['OSNameEn'] ?? ''),
                        'osType' => $img['OSType'] ?? '',
                        'architecture' => $img['Architecture'] ?? '',
                        'imageVersion' => $img['ImageVersion'] ?? '',
                        'size' => $img['Size'] ?? '',
                        'imageOwnerAlias' => $img['ImageOwnerAlias'] ?? '',
                        'description' => $img['Description'] ?? '',
                        'productName' => $img['ProductCode'] ? ($img['OSName'] ?? '') : ''
                    ];
                }
                $result = ['success' => true, 'data' => $imageList, 'total' => count($imageList)];
            } else {
                $result = ['success' => false, 'message' => '查询镜像失败: ' . ($apiResult['error'] ?? '未知错误')];
            }
            break;
            
        case 'get_vnc_url':
            $vncResult = $ecs->describeInstanceVncUrl($opInstanceId);
            if ($vncResult['success']) {
                $vncUrl = $vncResult['data']['VncUrl'] ?? '';
                if (!empty($vncUrl)) {
                    $instanceInfo = $ecs->describeInstanceAttribute($opInstanceId);
                    $osType = 'linux';
                    if ($instanceInfo['success']) {
                        $osName = $instanceInfo['data']['OSName'] ?? '';
                        $osType = AliyunEcs::getOsType($osName);
                    }
                    $result = [
                        'success' => true, 
                        'vnc_url' => $vncUrl,
                        'instance_id' => $opInstanceId,
                        'region_id' => $config['region_id'],
                        'os_type' => $osType
                    ];
                    logOperation('vnc_connect', 'server', "实例 {$opInstanceId} 获取VNC连接地址");
                } else {
                    $result = ['success' => false, 'message' => '获取VNC地址失败: 返回地址为空'];
                }
            } else {
                $result = ['success' => false, 'message' => '获取VNC地址失败: ' . ($vncResult['error'] ?? '未知错误')];
            }
            break;
    }
    
    echo json_encode($result);
    exit;
}

$configs = $db->fetchAll("SELECT * FROM `{$prefix}aliyun_config` WHERE `status` = 1 ORDER BY `is_default` DESC, `id` ASC");

$allInstances = [];
$selectedConfig = null;

if ($action === 'detail' && !empty($instanceId) && $configId > 0) {
    $selectedConfig = $db->fetchOne("SELECT * FROM `{$prefix}aliyun_config` WHERE `id` = ? AND `status` = 1", [$configId]);
    if ($selectedConfig) {
        $secret = Helper::decrypt($selectedConfig['access_key_secret']);
        $ecs = new AliyunEcs($selectedConfig['access_key_id'], $secret, $selectedConfig['region_id']);
        
        $listResult = $ecs->describeInstances();
        $instanceDetail = null;
        
        if ($listResult['success'] && isset($listResult['data']['Instances']['Instance'])) {
            $instances = $listResult['data']['Instances']['Instance'];
            if (isset($instances['InstanceId'])) {
                $instances = [$instances];
            }
            foreach ($instances as $inst) {
                if (($inst['InstanceId'] ?? '') === $instanceId) {
                    $instanceDetail = $inst;
                    break;
                }
            }
        }
        
        if (!$instanceDetail) {
            $detailResult = $ecs->describeInstanceAttribute($instanceId);
            if ($detailResult['success']) {
                $instanceDetail = $detailResult['data'];
            }
        }
        
        $diskResult = $ecs->describeDisks($instanceId);
        
        $disks = [];
        if ($diskResult['success'] && isset($diskResult['data']['Disks']['Disk'])) {
            $disks = $diskResult['data']['Disks']['Disk'];
            if (isset($disks['DiskId'])) {
                $disks = [$disks];
            }
        }
    }
} else {
    foreach ($configs as $cfg) {
        $secret = Helper::decrypt($cfg['access_key_secret']);
        $ecs = new AliyunEcs($cfg['access_key_id'], $secret, $cfg['region_id']);
        $result = $ecs->describeInstances();
        
        if ($result['success'] && isset($result['data']['Instances']['Instance'])) {
            $instances = $result['data']['Instances']['Instance'];
            if (isset($instances['InstanceId'])) {
                $instances = [$instances];
            }
            foreach ($instances as $inst) {
                $inst['_config_id'] = $cfg['id'];
                $inst['_config_name'] = $cfg['name'];
                $inst['_region_id'] = $cfg['region_id'];
                $allInstances[] = $inst;
            }
        }
    }
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

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务器管理 - 阿里云流量查询系统</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .server-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .server-card {
            flex: 1 1 calc(50% - 10px);
            min-width: 380px;
            max-width: 100%;
            background: #fff;
            border-radius: 16px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .server-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }
        
        .server-card-header {
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .server-name {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .server-os-icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        
        .server-os-icon.linux {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #fff;
        }
        
        .server-os-icon.windows {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: #fff;
        }
        
        .server-status {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .server-status.running {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        
        .server-status.stopped {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        
        .server-status.starting, .server-status.stopping, .server-status.rebooting {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }
        
        .server-status.pending {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }
        
        .server-card-body {
            padding: 20px 24px;
        }
        
        .server-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        
        .server-info-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .server-info-label {
            font-size: 12px;
            color: #6b7280;
        }
        
        .server-info-value {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a2e;
            word-break: break-all;
        }
        
        .server-card-footer {
            padding: 16px 24px;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .server-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .server-btn:hover {
            transform: translateY(-1px);
        }
        
        .server-btn.start {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        
        .server-btn.start:hover {
            background: rgba(16, 185, 129, 0.2);
        }
        
        .server-btn.stop {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        
        .server-btn.stop:hover {
            background: rgba(239, 68, 68, 0.2);
        }
        
        .server-btn.reboot {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }
        
        .server-btn.reboot:hover {
            background: rgba(59, 130, 246, 0.2);
        }
        
        .server-btn.detail {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
        }
        
        .server-btn.detail:hover {
            background: rgba(102, 126, 234, 0.2);
        }
        
        .server-btn.force {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }
        
        .server-btn.force:hover {
            background: rgba(245, 158, 11, 0.2);
        }
        
        .server-btn.reinstall {
            background: rgba(139, 92, 246, 0.1);
            color: #7c3aed;
        }
        
        .server-btn.reinstall:hover {
            background: rgba(139, 92, 246, 0.2);
        }
        
        .server-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .detail-title {
            font-size: 22px;
            font-weight: 800;
            color: #1a1a2e;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        .detail-panel {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        
        .detail-panel-header {
            padding: 16px 24px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-size: 15px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .detail-panel-body {
            padding: 20px 24px;
        }
        
        .detail-info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.03);
        }
        
        .detail-info-row:last-child {
            border-bottom: none;
        }
        
        .detail-info-label {
            color: #6b7280;
            font-size: 13px;
        }
        
        .detail-info-value {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 13px;
            text-align: right;
            word-break: break-all;
            max-width: 60%;
        }
        
        .disk-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .disk-table th {
            text-align: left;
            padding: 10px 12px;
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .disk-table td {
            padding: 10px 12px;
            font-size: 13px;
            color: #1a1a2e;
            border-bottom: 1px solid rgba(0, 0, 0, 0.03);
        }
        
        .action-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
        }
        
        .action-btn.start {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
        }
        
        .action-btn.stop {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: #fff;
        }
        
        .action-btn.reboot {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #fff;
        }
        
        .action-btn.force-stop {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #fff;
        }
        
        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 14px 24px;
            border-radius: 12px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            z-index: 10000;
            animation: slideIn 0.3s ease;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }
        
        .toast.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .toast.error { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .toast.info { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .confirm-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .confirm-dialog {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        
        .confirm-title {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 12px;
        }
        
        .confirm-message {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        
        .confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        .confirm-btn {
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .confirm-btn.cancel {
            background: #f3f4f6;
            color: #374151;
        }
        
        .confirm-btn.confirm {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: #fff;
        }
        
        .confirm-btn.confirm.start-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .confirm-btn.confirm.reboot-btn {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
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
        
        .reinstall-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9998;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .reinstall-dialog {
            background: #fff;
            border-radius: 16px;
            max-width: 680px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        
        .reinstall-dialog-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: #fff;
            border-radius: 16px 16px 0 0;
            z-index: 1;
        }
        
        .reinstall-dialog-title {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .reinstall-dialog-close {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: #f3f4f6;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
        }
        
        .reinstall-dialog-close:hover {
            background: #e5e7eb;
        }
        
        .reinstall-dialog-body {
            padding: 24px;
        }
        
        .reinstall-warning {
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.2);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #92400e;
            line-height: 1.6;
        }
        
        .reinstall-form-group {
            margin-bottom: 18px;
        }
        
        .reinstall-form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        
        .reinstall-form-label .required {
            color: #ef4444;
            margin-left: 2px;
        }
        
        .reinstall-form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            color: #1a1a2e;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        
        .reinstall-form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .reinstall-form-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            color: #1a1a2e;
            box-sizing: border-box;
            background: #fff;
            cursor: pointer;
        }
        
        .reinstall-form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .reinstall-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 14px;
            background: #f3f4f6;
            border-radius: 10px;
            padding: 4px;
        }
        
        .reinstall-tab {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            background: transparent;
            color: #6b7280;
            transition: all 0.2s;
            text-align: center;
        }
        
        .reinstall-tab.active {
            background: #fff;
            color: #667eea;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .reinstall-tab:hover:not(.active) {
            color: #374151;
        }
        
        .image-list {
            max-height: 240px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }
        
        .image-list-loading {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .image-item {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            cursor: pointer;
            transition: background 0.15s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .image-item:hover {
            background: rgba(102, 126, 234, 0.04);
        }
        
        .image-item.selected {
            background: rgba(102, 126, 234, 0.08);
            border-left: 3px solid #667eea;
        }
        
        .image-item:last-child {
            border-bottom: none;
        }
        
        .image-item-info {
            flex: 1;
        }
        
        .image-item-name {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .image-item-meta {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }
        
        .image-item-id {
            font-size: 11px;
            color: #667eea;
            font-weight: 600;
            background: rgba(102, 126, 234, 0.08);
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        .image-search {
            width: 100%;
            padding: 8px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 10px;
            box-sizing: border-box;
        }
        
        .image-search:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .reinstall-dialog-footer {
            padding: 16px 24px;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            position: sticky;
            bottom: 0;
            background: #fff;
            border-radius: 0 0 16px 16px;
        }
        
        .reinstall-submit-btn {
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: #fff;
            transition: all 0.2s;
        }
        
        .reinstall-submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .reinstall-submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .reinstall-cancel-btn {
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            background: #f3f4f6;
            color: #374151;
            transition: all 0.2s;
        }
        
        .reinstall-cancel-btn:hover {
            background: #e5e7eb;
        }
        
        .auth-type-switch {
            display: flex;
            gap: 12px;
            margin-bottom: 10px;
        }
        
        .auth-type-option {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #374151;
            cursor: pointer;
        }
        
        .auth-type-option input[type="radio"] {
            accent-color: #667eea;
        }
        
        @media (max-width: 768px) {
            .server-grid .server-card {
                flex: 1 1 100%;
            }
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php $currentPage = 'server'; include __DIR__ . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <h1>服务器管理</h1>
                </div>
                <div class="topbar-right">
                    <a href="?action=list" class="btn btn-primary btn-sm" onclick="refreshList(); return false;">刷新列表</a>
                </div>
            </header>
            
            <div class="content">
                <?php if ($action === 'detail' && isset($instanceDetail)): ?>
                <div class="detail-header">
                    <div>
                        <h2 class="detail-title"><?php echo htmlspecialchars($instanceDetail['InstanceName'] ?? $instanceId); ?></h2>
                        <p style="color: #6b7280; font-size: 14px; margin-top: 4px;">
                            实例ID: <?php echo htmlspecialchars($instanceId); ?> · 
                            配置: <?php echo htmlspecialchars($selectedConfig['name'] ?? ''); ?> · 
                            地域: <?php echo $regions[$selectedConfig['region_id']] ?? $selectedConfig['region_id']; ?>
                        </p>
                    </div>
                    <div class="action-bar">
                        <?php $status = $instanceDetail['Status'] ?? 'Stopped'; ?>
                        <button class="action-btn start" onclick="doOperation('start', '<?php echo $instanceId; ?>', <?php echo $configId; ?>)" <?php echo $status === 'Running' ? 'disabled' : ''; ?>>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            开机
                        </button>
                        <button class="action-btn stop" onclick="confirmOperation('stop', '<?php echo $instanceId; ?>', <?php echo $configId; ?>)" <?php echo $status !== 'Running' ? 'disabled' : ''; ?>>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12"/></svg>
                            关机
                        </button>
                        <button class="action-btn reboot" onclick="confirmOperation('reboot', '<?php echo $instanceId; ?>', <?php echo $configId; ?>)" <?php echo $status !== 'Running' ? 'disabled' : ''; ?>>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                            重启
                        </button>
                        <button class="action-btn force-stop" onclick="confirmOperation('stop', '<?php echo $instanceId; ?>', <?php echo $configId; ?>, true)" <?php echo $status !== 'Running' ? 'disabled' : ''; ?>>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                            强制关机
                        </button>
                        <button class="action-btn" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: #fff;" onclick="openReinstall('<?php echo $instanceId; ?>', <?php echo $configId; ?>, '<?php echo AliyunEcs::getOsType($instanceDetail['OSName'] ?? ''); ?>')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                            重装系统
                        </button>
                        <button class="action-btn" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); color: #fff;" onclick="openVnc('<?php echo $instanceId; ?>', <?php echo $configId; ?>)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                            VNC
                        </button>
                        <a href="?action=list" class="action-btn" style="background: #f3f4f6; color: #374151;">返回列表</a>
                    </div>
                </div>
                
                <div class="detail-grid">
                    <div class="detail-panel">
                        <div class="detail-panel-header">基本信息</div>
                        <div class="detail-panel-body">
                            <div class="detail-info-row">
                                <span class="detail-info-label">实例状态</span>
                                <span class="detail-info-value"><span class="server-status <?php echo strtolower($status); ?>"><?php echo AliyunEcs::formatStatus($status); ?></span></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">实例规格</span>
                                <span class="detail-info-value"><?php echo htmlspecialchars($instanceDetail['InstanceType'] ?? '-'); ?> (<?php echo AliyunEcs::formatInstanceType($instanceDetail['InstanceType'] ?? ''); ?>)</span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">CPU</span>
                                <span class="detail-info-value"><?php echo $instanceDetail['Cpu'] ?? '-'; ?> 核</span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">内存</span>
                                <span class="detail-info-value"><?php echo isset($instanceDetail['Memory']) ? ($instanceDetail['Memory'] / 1024) : '-'; ?> GB</span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">操作系统</span>
                                <span class="detail-info-value"><?php echo htmlspecialchars($instanceDetail['OSName'] ?? $instanceDetail['OSNameEn'] ?? '-'); ?></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">付费类型</span>
                                <span class="detail-info-value"><?php echo AliyunEcs::formatChargeType($instanceDetail['InstanceChargeType'] ?? ''); ?></span>
                            </div>
                            <?php if (($instanceDetail['InstanceChargeType'] ?? '') === 'PrePaid'): ?>
                            <div class="detail-info-row">
                                <span class="detail-info-label">到期时间</span>
                                <span class="detail-info-value" style="color: <?php echo strtotime($instanceDetail['ExpiredTime'] ?? '') < strtotime('+7 days') ? '#dc2626' : '#1a1a2e'; ?>;"><?php echo $instanceDetail['ExpiredTime'] ?? '-'; ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="detail-info-row">
                                <span class="detail-info-label">创建时间</span>
                                <span class="detail-info-value"><?php echo $instanceDetail['CreationTime'] ?? '-'; ?></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">网络类型</span>
                                <span class="detail-info-value"><?php echo AliyunEcs::formatNetworkType($instanceDetail['InstanceNetworkType'] ?? ''); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-panel">
                        <div class="detail-panel-header">网络信息</div>
                        <div class="detail-panel-body">
                            <?php
                            $publicIp = $instanceDetail['PublicIpAddress']['IpAddress'] ?? [];
                            $privateIp = $instanceDetail['VpcAttributes']['PrivateIpAddress']['IpAddress'] ?? ($instanceDetail['InnerIpAddress']['IpAddress'] ?? []);
                            $eip = $instanceDetail['EipAddress']['IpAddress'] ?? '';
                            ?>
                            <div class="detail-info-row">
                                <span class="detail-info-label">公网IP</span>
                                <span class="detail-info-value"><?php 
                                    if (!empty($eip)) {
                                        echo 'EIP: ' . $eip;
                                    } elseif (!empty($publicIp)) {
                                        echo implode(', ', $publicIp);
                                    } else {
                                        echo '-';
                                    }
                                ?></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">内网IP</span>
                                <span class="detail-info-value"><?php echo !empty($privateIp) ? implode(', ', $privateIp) : '-'; ?></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">VPC ID</span>
                                <span class="detail-info-value"><?php echo htmlspecialchars($instanceDetail['VpcAttributes']['VpcId'] ?? '-'); ?></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">交换机ID</span>
                                <span class="detail-info-value"><?php echo htmlspecialchars($instanceDetail['VpcAttributes']['VSwitchId'] ?? '-'); ?></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">安全组</span>
                                <span class="detail-info-value"><?php 
                                    $sgs = $instanceDetail['SecurityGroupIds']['SecurityGroupId'] ?? [];
                                    echo !empty($sgs) ? implode(', ', $sgs) : '-'; 
                                ?></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">带宽计费方式</span>
                                <span class="detail-info-value"><?php 
                                    $internetChargeType = $instanceDetail['InternetChargeType'] ?? '';
                                    if (empty($internetChargeType) && isset($instanceDetail['EipAddress']['InternetChargeType'])) {
                                        $internetChargeType = $instanceDetail['EipAddress']['InternetChargeType'];
                                    }
                                    if (empty($internetChargeType) && isset($instanceDetail['NetworkInterfaces']['NetworkInterface'])) {
                                        $nis = $instanceDetail['NetworkInterfaces']['NetworkInterface'];
                                        if (isset($nis[0]['Type']) && $nis[0]['Type'] === 'Elastic') {
                                            $internetChargeType = 'PayByTraffic';
                                        }
                                    }
                                    echo !empty($internetChargeType) ? AliyunEcs::formatChargeType($internetChargeType) : '-';
                                ?></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">带宽峰值</span>
                                <span class="detail-info-value"><?php 
                                    $bandwidth = $instanceDetail['InternetMaxBandwidthOut'] ?? 0;
                                    if ($bandwidth <= 0 && isset($instanceDetail['EipAddress']['Bandwidth'])) {
                                        $bandwidth = $instanceDetail['EipAddress']['Bandwidth'];
                                    }
                                    echo intval($bandwidth) > 0 ? intval($bandwidth) . ' Mbps' : '-';
                                ?></span>
                            </div>
                            <div class="detail-info-row">
                                <span class="detail-info-label">地域</span>
                                <span class="detail-info-value"><?php echo $regions[$instanceDetail['RegionId'] ?? ''] ?? ($instanceDetail['RegionId'] ?? '-'); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-panel" style="grid-column: 1 / -1;">
                        <div class="detail-panel-header">磁盘信息</div>
                        <div class="detail-panel-body">
                            <?php if (empty($disks)): ?>
                            <p style="color: #6b7280; text-align: center; padding: 20px;">暂无磁盘信息</p>
                            <?php else: ?>
                            <table class="disk-table">
                                <thead>
                                    <tr>
                                        <th>磁盘ID</th>
                                        <th>磁盘名称</th>
                                        <th>类型</th>
                                        <th>大小</th>
                                        <th>类别</th>
                                        <th>状态</th>
                                        <th>创建时间</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($disks as $disk): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($disk['DiskId'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($disk['DiskName'] ?? '-'); ?></td>
                                        <td><?php echo ($disk['Type'] ?? '') === 'system' ? '系统盘' : '数据盘'; ?></td>
                                        <td><?php echo ($disk['Size'] ?? '0'); ?> GB</td>
                                        <td><?php echo ($disk['Category'] ?? '') === 'cloud_efficiency' ? '高效云盘' : (($disk['Category'] ?? '') === 'cloud_ssd' ? 'SSD云盘' : (($disk['Category'] ?? '') === 'cloud_essd' ? 'ESSD云盘' : ($disk['Category'] ?? '-'))); ?></td>
                                        <td><?php echo AliyunEcs::formatStatus($disk['Status'] ?? ''); ?></td>
                                        <td><?php echo $disk['CreationTime'] ?? '-'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="panel">
                    <div class="panel-header">
                        <h3>云服务器 ECS 实例列表</h3>
                        <span style="color: #6b7280; font-size: 13px;">共 <?php echo count($allInstances); ?> 台实例</span>
                    </div>
                    <?php if (empty($allInstances)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🖥️</div>
                        <p>暂无ECS实例数据</p>
                        <p style="font-size: 13px; margin-top: 8px;">请确保已添加阿里云AccessKey配置且配置了正确的地域</p>
                    </div>
                    <?php else: ?>
                    <div class="panel-body">
                        <div class="server-grid">
                            <?php foreach ($allInstances as $inst): ?>
                            <?php 
                                $status = $inst['Status'] ?? 'Stopped';
                                $statusClass = strtolower($status);
                                $osType = AliyunEcs::getOsType($inst['OSName'] ?? '');
                                $publicIps = $inst['PublicIpAddress']['IpAddress'] ?? [];
                                $privateIps = $inst['VpcAttributes']['PrivateIpAddress']['IpAddress'] ?? ($inst['InnerIpAddress']['IpAddress'] ?? []);
                                $eipAddr = $inst['EipAddress']['IpAddress'] ?? '';
                                $expiredTime = $inst['ExpiredTime'] ?? '';
                                $isExpiring = !empty($expiredTime) && strtotime($expiredTime) < strtotime('+7 days');
                            ?>
                            <div class="server-card">
                                <div class="server-card-header">
                                    <div class="server-name">
                                        <div class="server-os-icon <?php echo $osType; ?>">
                                            <?php echo $osType === 'linux' ? '🐧' : ($osType === 'windows' ? '🪟' : '💻'); ?>
                                        </div>
                                        <?php echo htmlspecialchars($inst['InstanceName'] ?? $inst['InstanceId']); ?>
                                    </div>
                                    <span class="server-status <?php echo $statusClass; ?>"><?php echo AliyunEcs::formatStatus($status); ?></span>
                                </div>
                                <div class="server-card-body">
                                    <div class="server-info-grid">
                                        <div class="server-info-item">
                                            <span class="server-info-label">实例ID</span>
                                            <span class="server-info-value" style="font-size: 11px;"><?php echo htmlspecialchars($inst['InstanceId']); ?></span>
                                        </div>
                                        <div class="server-info-item">
                                            <span class="server-info-label">实例规格</span>
                                            <span class="server-info-value"><?php echo htmlspecialchars($inst['InstanceType'] ?? '-'); ?></span>
                                        </div>
                                        <div class="server-info-item">
                                            <span class="server-info-label">公网IP</span>
                                            <span class="server-info-value"><?php echo !empty($publicIps) ? implode(', ', $publicIps) : (!empty($eipAddr) ? $eipAddr . ' (EIP)' : '-'); ?></span>
                                        </div>
                                        <div class="server-info-item">
                                            <span class="server-info-label">内网IP</span>
                                            <span class="server-info-value"><?php echo !empty($privateIps) ? implode(', ', $privateIps) : '-'; ?></span>
                                        </div>
                                        <div class="server-info-item">
                                            <span class="server-info-label">配置来源</span>
                                            <span class="server-info-value"><?php echo htmlspecialchars($inst['_config_name']); ?></span>
                                        </div>
                                        <div class="server-info-item">
                                            <span class="server-info-label">付费类型</span>
                                            <span class="server-info-value"><?php echo AliyunEcs::formatChargeType($inst['InstanceChargeType'] ?? ''); ?></span>
                                        </div>
                                        <?php if (!empty($expiredTime)): ?>
                                        <div class="server-info-item" style="grid-column: 1 / -1;">
                                            <span class="server-info-label">到期时间</span>
                                            <span class="server-info-value" style="color: <?php echo $isExpiring ? '#dc2626' : '#1a1a2e'; ?>;<?php echo $isExpiring ? ' font-weight: 700;' : ''; ?>"><?php echo $expiredTime; ?><?php echo $isExpiring ? ' ⚠️ 即将到期' : ''; ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="server-card-footer">
                                    <button class="server-btn start" onclick="doOperation('start', '<?php echo $inst['InstanceId']; ?>', <?php echo $inst['_config_id']; ?>)" <?php echo $status === 'Running' ? 'disabled' : ''; ?>>▶ 开机</button>
                                    <button class="server-btn stop" onclick="confirmOperation('stop', '<?php echo $inst['InstanceId']; ?>', <?php echo $inst['_config_id']; ?>)" <?php echo $status !== 'Running' ? 'disabled' : ''; ?>>■ 关机</button>
                                    <button class="server-btn reboot" onclick="confirmOperation('reboot', '<?php echo $inst['InstanceId']; ?>', <?php echo $inst['_config_id']; ?>)" <?php echo $status !== 'Running' ? 'disabled' : ''; ?>>↻ 重启</button>
                                    <button class="server-btn reinstall" onclick="openReinstall('<?php echo $inst['InstanceId']; ?>', <?php echo $inst['_config_id']; ?>, '<?php echo $osType; ?>')">⟳ 重装</button>
                                    <button class="server-btn" style="background: rgba(6, 182, 212, 0.1); color: #0891b2;" onclick="openVnc('<?php echo $inst['InstanceId']; ?>', <?php echo $inst['_config_id']; ?>)">🖥 VNC</button>
                                    <a href="?action=detail&instance_id=<?php echo $inst['InstanceId']; ?>&config_id=<?php echo $inst['_config_id']; ?>" class="server-btn detail">详情</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <div id="confirmOverlay" class="confirm-overlay" style="display: none;">
        <div class="confirm-dialog">
            <div class="confirm-title" id="confirmTitle">确认操作</div>
            <div class="confirm-message" id="confirmMessage">确定要执行此操作吗？</div>
            <div class="confirm-actions">
                <button class="confirm-btn cancel" onclick="hideConfirm()">取消</button>
                <button class="confirm-btn confirm" id="confirmBtn" onclick="executeConfirm()">确定</button>
            </div>
        </div>
    </div>
    
    <div id="reinstallOverlay" class="reinstall-overlay" style="display: none;">
        <div class="reinstall-dialog">
            <div class="reinstall-dialog-header">
                <div class="reinstall-dialog-title">🔄 重装系统</div>
                <button class="reinstall-dialog-close" onclick="closeReinstall()">✕</button>
            </div>
            <div class="reinstall-dialog-body">
                <div class="reinstall-warning">
                    ⚠️ <strong>危险操作：</strong>重装系统会格式化系统盘，系统盘上的所有数据将被清除且不可恢复！请确保已备份重要数据。
                </div>
                
                <div class="reinstall-form-group">
                    <label class="reinstall-form-label">镜像类型</label>
                    <div class="reinstall-tabs" id="imageTypeTabs">
                        <button class="reinstall-tab active" onclick="switchImageType('system', this)">公共镜像</button>
                        <button class="reinstall-tab" onclick="switchImageType('self', this)">自定义镜像</button>
                        <button class="reinstall-tab" onclick="switchImageType('others', this)">共享镜像</button>
                    </div>
                </div>
                
                <div class="reinstall-form-group">
                    <label class="reinstall-form-label">操作系统筛选</label>
                    <select class="reinstall-form-select" id="reinstallOsFilter" onchange="loadImages()">
                        <option value="">全部</option>
                        <option value="linux">Linux</option>
                        <option value="windows">Windows</option>
                    </select>
                </div>
                
                <div class="reinstall-form-group">
                    <label class="reinstall-form-label">搜索镜像</label>
                    <input type="text" class="image-search" id="imageSearchInput" placeholder="输入镜像名称或ID搜索..." oninput="filterImages()">
                </div>
                
                <div class="reinstall-form-group">
                    <label class="reinstall-form-label">选择镜像 <span class="required">*</span></label>
                    <div class="image-list" id="imageListContainer">
                        <div class="image-list-loading">点击上方标签加载镜像列表...</div>
                    </div>
                    <input type="hidden" id="selectedImageId" value="">
                    <div id="selectedImageInfo" style="margin-top: 8px; font-size: 12px; color: #667eea; font-weight: 600;"></div>
                </div>
                
                <div class="reinstall-form-group">
                    <label class="reinstall-form-label">登录方式 <span class="required">*</span></label>
                    <div class="auth-type-switch">
                        <label class="auth-type-option">
                            <input type="radio" name="authType" value="password" checked onchange="switchAuthType('password')"> 密码登录
                        </label>
                        <label class="auth-type-option">
                            <input type="radio" name="authType" value="keypair" onchange="switchAuthType('keypair')"> 密钥对登录
                        </label>
                    </div>
                </div>
                
                <div id="passwordAuthGroup">
                    <div class="reinstall-form-group">
                        <label class="reinstall-form-label">登录密码 <span class="required">*</span></label>
                        <input type="password" class="reinstall-form-input" id="reinstallPassword" placeholder="8-30位，需包含大小写字母、数字和特殊符号">
                    </div>
                    <div class="reinstall-form-group">
                        <label class="reinstall-form-label">确认密码 <span class="required">*</span></label>
                        <input type="password" class="reinstall-form-input" id="reinstallPasswordConfirm" placeholder="再次输入密码">
                    </div>
                </div>
                
                <div id="keypairAuthGroup" style="display: none;">
                    <div class="reinstall-form-group">
                        <label class="reinstall-form-label">密钥对名称 <span class="required">*</span></label>
                        <input type="text" class="reinstall-form-input" id="reinstallKeyPair" placeholder="输入密钥对名称">
                    </div>
                </div>
                
                <div class="reinstall-form-group" id="diskSizeGroup" style="display: none;">
                    <label class="reinstall-form-label">系统盘大小 (GB)</label>
                    <input type="number" class="reinstall-form-input" id="reinstallDiskSize" placeholder="留空则保持原大小不变" min="20" max="500">
                    <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">仅支持扩大系统盘容量，不能缩小。最小20GB</div>
                </div>
            </div>
            <div class="reinstall-dialog-footer">
                <button class="reinstall-cancel-btn" onclick="closeReinstall()">取消</button>
                <button class="reinstall-submit-btn" id="reinstallSubmitBtn" onclick="submitReinstall()">确认重装</button>
            </div>
        </div>
    </div>
    
    <script>
        var pendingOperation = null;
        var isOperating = false;
        var reinstallState = {
            instanceId: '',
            configId: 0,
            osType: '',
            currentImageType: 'system',
            allImages: [],
            selectedImageId: ''
        };
        
        function showToast(message, type) {
            var toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(function() {
                toast.style.animation = 'slideIn 0.3s ease reverse';
                setTimeout(function() { toast.remove(); }, 300);
            }, 3000);
        }
        
        function doOperation(operation, instanceId, configId, force) {
            if (isOperating) return;
            isOperating = true;
            
            var formData = new FormData();
            formData.append('operation', operation);
            formData.append('instance_id', instanceId);
            formData.append('config_id', configId);
            if (force) {
                formData.append('force', 'true');
            }
            
            var opNames = {'start': '开机', 'stop': force ? '强制关机' : '关机', 'reboot': force ? '强制重启' : '重启'};
            showToast('正在执行' + (opNames[operation] || operation) + '操作...', 'info');
            
            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                if (!response.ok) {
                    return response.text().then(function(text) {
                        try {
                            return JSON.parse(text);
                        } catch(e) {
                            return {success: false, message: '服务器返回错误 (HTTP ' + response.status + ')'};
                        }
                    });
                }
                return response.json();
            })
            .then(function(data) {
                isOperating = false;
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(function() { location.reload(); }, 2500);
                } else {
                    showToast(data.message || '操作失败', 'error');
                }
            })
            .catch(function(err) {
                isOperating = false;
                showToast('请求失败: ' + err.message, 'error');
            });
        }
        
        function confirmOperation(operation, instanceId, configId, force) {
            pendingOperation = {
                operation: operation,
                instanceId: instanceId,
                configId: configId,
                force: force || false
            };
            
            var titles = {
                'stop': '确认关机',
                'reboot': '确认重启'
            };
            var messages = {
                'stop': force ? '确定要强制关机吗？强制关机可能导致数据丢失，请确保已保存重要数据。' : '确定要关机吗？请确保已保存重要数据。',
                'reboot': force ? '确定要强制重启吗？强制重启可能导致数据丢失。' : '确定要重启吗？实例将短暂不可用。'
            };
            
            document.getElementById('confirmTitle').textContent = titles[operation] || '确认操作';
            document.getElementById('confirmMessage').textContent = messages[operation] || '确定要执行此操作吗？';
            
            var btn = document.getElementById('confirmBtn');
            btn.className = 'confirm-btn confirm';
            if (operation === 'reboot') btn.classList.add('reboot-btn');
            
            document.getElementById('confirmOverlay').style.display = 'flex';
        }
        
        function hideConfirm() {
            document.getElementById('confirmOverlay').style.display = 'none';
            pendingOperation = null;
        }
        
        function executeConfirm() {
            if (pendingOperation) {
                var op = pendingOperation;
                hideConfirm();
                doOperation(op.operation, op.instanceId, op.configId, op.force);
            }
        }
        
        function refreshList() {
            location.reload();
        }
        
        function openVnc(instanceId, configId) {
            var vncUrl = 'vnc.php?instance_id=' + encodeURIComponent(instanceId) + '&config_id=' + configId;
            var vncWindow = window.open(vncUrl, 'vnc_' + instanceId, 'width=1280,height=800,menubar=no,toolbar=no,location=no,status=no');
            if (vncWindow) {
                vncWindow.focus();
            } else {
                showToast('请允许弹出窗口以打开VNC连接', 'error');
            }
        }
        
        document.getElementById('confirmOverlay').addEventListener('click', function(e) {
            if (e.target === this) hideConfirm();
        });
        
        function openReinstall(instanceId, configId, osType) {
            reinstallState.instanceId = instanceId;
            reinstallState.configId = configId;
            reinstallState.osType = osType;
            reinstallState.selectedImageId = '';
            reinstallState.allImages = [];
            
            document.getElementById('selectedImageId').value = '';
            document.getElementById('selectedImageInfo').textContent = '';
            document.getElementById('reinstallPassword').value = '';
            document.getElementById('reinstallPasswordConfirm').value = '';
            document.getElementById('reinstallKeyPair').value = '';
            document.getElementById('reinstallDiskSize').value = '';
            document.getElementById('imageSearchInput').value = '';
            document.getElementById('reinstallOsFilter').value = osType === 'linux' ? 'linux' : (osType === 'windows' ? 'windows' : '');
            
            document.getElementById('reinstallOverlay').style.display = 'flex';
            
            switchImageType('system', document.querySelector('.reinstall-tab.active'));
        }
        
        function closeReinstall() {
            document.getElementById('reinstallOverlay').style.display = 'none';
        }
        
        function switchImageType(type, tabEl) {
            reinstallState.currentImageType = type;
            
            var tabs = document.querySelectorAll('.reinstall-tab');
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('active');
            }
            if (tabEl) tabEl.classList.add('active');
            
            var diskSizeGroup = document.getElementById('diskSizeGroup');
            if (type === 'system') {
                diskSizeGroup.style.display = 'none';
                document.getElementById('reinstallDiskSize').value = '';
            } else {
                diskSizeGroup.style.display = 'block';
            }
            
            loadImages();
        }
        
        function loadImages() {
            var container = document.getElementById('imageListContainer');
            container.innerHTML = '<div class="image-list-loading">⏳ 正在加载镜像列表...</div>';
            reinstallState.selectedImageId = '';
            document.getElementById('selectedImageId').value = '';
            document.getElementById('selectedImageInfo').textContent = '';
            
            var formData = new FormData();
            formData.append('operation', 'load_images');
            formData.append('instance_id', reinstallState.instanceId);
            formData.append('config_id', reinstallState.configId);
            formData.append('image_owner_alias', reinstallState.currentImageType);
            formData.append('ostype', document.getElementById('reinstallOsFilter').value);
            
            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    reinstallState.allImages = data.data || [];
                    renderImageList(reinstallState.allImages);
                } else {
                    container.innerHTML = '<div class="image-list-loading" style="color: #ef4444;">❌ ' + (data.message || '加载失败') + '</div>';
                }
            })
            .catch(function(err) {
                container.innerHTML = '<div class="image-list-loading" style="color: #ef4444;">❌ 请求失败: ' + err.message + '</div>';
            });
        }
        
        function renderImageList(images) {
            var container = document.getElementById('imageListContainer');
            
            if (!images || images.length === 0) {
                container.innerHTML = '<div class="image-list-loading">📭 暂无可用镜像</div>';
                return;
            }
            
            var html = '';
            for (var i = 0; i < images.length; i++) {
                var img = images[i];
                var isSelected = img.imageId === reinstallState.selectedImageId;
                var osIcon = img.osType === 'windows' ? '🪟' : '🐧';
                var meta = [];
                if (img.architecture) meta.push(img.architecture);
                if (img.imageVersion) meta.push(img.imageVersion);
                if (img.size) meta.push(img.size + 'GB');
                
                html += '<div class="image-item' + (isSelected ? ' selected' : '') + '" onclick="selectImage(\'' + img.imageId + '\', \'' + escapeHtml(img.osName) + '\')">';
                html += '<div class="image-item-info">';
                html += '<div class="image-item-name">' + osIcon + ' ' + escapeHtml(img.osName || img.imageId) + '</div>';
                html += '<div class="image-item-meta">' + (meta.length > 0 ? meta.join(' · ') : img.imageId) + '</div>';
                html += '</div>';
                html += '<div class="image-item-id">' + img.imageId + '</div>';
                html += '</div>';
            }
            
            container.innerHTML = html;
        }
        
        function selectImage(imageId, osName) {
            reinstallState.selectedImageId = imageId;
            document.getElementById('selectedImageId').value = imageId;
            document.getElementById('selectedImageInfo').textContent = '✅ 已选择: ' + osName + ' (' + imageId + ')';
            
            var items = document.querySelectorAll('.image-item');
            for (var i = 0; i < items.length; i++) {
                items[i].classList.remove('selected');
            }
            
            var allItems = document.querySelectorAll('.image-item');
            for (var j = 0; j < allItems.length; j++) {
                var idEl = allItems[j].querySelector('.image-item-id');
                if (idEl && idEl.textContent === imageId) {
                    allItems[j].classList.add('selected');
                }
            }
        }
        
        function filterImages() {
            var keyword = document.getElementById('imageSearchInput').value.toLowerCase().trim();
            if (!keyword) {
                renderImageList(reinstallState.allImages);
                return;
            }
            
            var filtered = [];
            for (var i = 0; i < reinstallState.allImages.length; i++) {
                var img = reinstallState.allImages[i];
                if ((img.osName && img.osName.toLowerCase().indexOf(keyword) !== -1) ||
                    (img.imageId && img.imageId.toLowerCase().indexOf(keyword) !== -1) ||
                    (img.description && img.description.toLowerCase().indexOf(keyword) !== -1)) {
                    filtered.push(img);
                }
            }
            renderImageList(filtered);
        }
        
        function switchAuthType(type) {
            if (type === 'password') {
                document.getElementById('passwordAuthGroup').style.display = 'block';
                document.getElementById('keypairAuthGroup').style.display = 'none';
            } else {
                document.getElementById('passwordAuthGroup').style.display = 'none';
                document.getElementById('keypairAuthGroup').style.display = 'block';
            }
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
        
        function submitReinstall() {
            var imageId = reinstallState.selectedImageId;
            if (!imageId) {
                showToast('请选择镜像', 'error');
                return;
            }
            
            var authType = document.querySelector('input[name="authType"]:checked').value;
            var password = '';
            var keyPairName = '';
            
            if (authType === 'password') {
                password = document.getElementById('reinstallPassword').value;
                var confirmPassword = document.getElementById('reinstallPasswordConfirm').value;
                if (!password) {
                    showToast('请输入登录密码', 'error');
                    return;
                }
                if (password !== confirmPassword) {
                    showToast('两次输入的密码不一致', 'error');
                    return;
                }
                if (password.length < 8 || password.length > 30) {
                    showToast('密码长度需为8-30位', 'error');
                    return;
                }
            } else {
                keyPairName = document.getElementById('reinstallKeyPair').value.trim();
                if (!keyPairName) {
                    showToast('请输入密钥对名称', 'error');
                    return;
                }
            }
            
            var diskSize = parseInt(document.getElementById('reinstallDiskSize').value) || 0;
            
            var submitBtn = document.getElementById('reinstallSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = '正在提交...';
            
            var formData = new FormData();
            formData.append('operation', 'reinstall');
            formData.append('instance_id', reinstallState.instanceId);
            formData.append('config_id', reinstallState.configId);
            formData.append('image_id', imageId);
            formData.append('password', password);
            formData.append('key_pair_name', keyPairName);
            formData.append('system_disk_size', diskSize);
            
            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                submitBtn.disabled = false;
                submitBtn.textContent = '确认重装';
                if (data.success) {
                    closeReinstall();
                    showProgressDialog(data.message, data.status, {
                        instanceId: reinstallState.instanceId,
                        configId: reinstallState.configId,
                        imageId: imageId,
                        password: password,
                        keyPairName: keyPairName,
                        diskSize: diskSize
                    });
                } else {
                    showToast(data.message || '重装系统失败', 'error');
                }
            })
            .catch(function(err) {
                submitBtn.disabled = false;
                submitBtn.textContent = '确认重装';
                showToast('请求失败: ' + err.message, 'error');
            });
        }
        
        var progressDialogTimer = null;
        var progressDialogData = null;
        
        function showProgressDialog(message, status, taskData) {
            progressDialogData = taskData;
            
            var overlay = document.getElementById('progressOverlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'progressOverlay';
                overlay.className = 'progress-overlay';
                overlay.innerHTML = '<div class="progress-dialog">' +
                    '<div class="progress-dialog-header">' +
                    '<div class="progress-dialog-title">🔄 重装系统进度</div>' +
                    '</div>' +
                    '<div class="progress-dialog-body">' +
                    '<div class="progress-status-icon" id="progressStatusIcon">⏳</div>' +
                    '<div class="progress-message" id="progressMessage">' + message + '</div>' +
                    '<div class="progress-steps">' +
                    '<div class="progress-step" id="step1"><span class="step-icon">1</span><span class="step-text">停止实例</span></div>' +
                    '<div class="progress-step" id="step2"><span class="step-icon">2</span><span class="step-text">重装系统</span></div>' +
                    '<div class="progress-step" id="step3"><span class="step-icon">3</span><span class="step-text">自动开机</span></div>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
                document.body.appendChild(overlay);
                
                var style = document.createElement('style');
                style.textContent = '.progress-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center}' +
                '.progress-dialog{background:#fff;border-radius:16px;max-width:420px;width:90%;box-shadow:0 20px 40px rgba(0,0,0,0.2)}' +
                '.progress-dialog-header{padding:20px 24px;border-bottom:1px solid rgba(0,0,0,0.05)}' +
                '.progress-dialog-title{font-size:18px;font-weight:700;color:#1a1a2e}' +
                '.progress-dialog-body{padding:24px;text-align:center}' +
                '.progress-status-icon{font-size:48px;margin-bottom:16px}' +
                '.progress-message{font-size:14px;color:#374151;margin-bottom:24px;line-height:1.6}' +
                '.progress-steps{display:flex;justify-content:center;gap:16px;margin-bottom:16px}' +
                '.progress-step{display:flex;flex-direction:column;align-items:center;gap:6px;opacity:0.4}' +
                '.progress-step.active{opacity:1}' +
                '.progress-step.completed{opacity:1}' +
                '.progress-step.completed .step-icon{background:linear-gradient(135deg,#10b981 0%,#059669 100%);color:#fff}' +
                '.progress-step.active .step-icon{background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%);color:#fff;animation:pulse 1.5s infinite}' +
                '.step-icon{width:32px;height:32px;border-radius:50%;background:#e5e7eb;color:#6b7280;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700}' +
                '.step-text{font-size:12px;color:#6b7280}' +
                '@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.1)}}';
                document.head.appendChild(style);
            }
            
            updateProgressUI(message, status);
            overlay.style.display = 'flex';
            
            pollReinstallStatus(status);
        }
        
        function updateProgressUI(message, status) {
            document.getElementById('progressMessage').textContent = message;
            
            var icon = '⏳';
            if (status === 'stopping') {
                icon = '🛑';
            } else if (status === 'reinstalling') {
                icon = '🔄';
            } else if (status === 'starting') {
                icon = '🚀';
            } else if (status === 'completed') {
                icon = '✅';
            } else if (status === 'error') {
                icon = '❌';
            }
            document.getElementById('progressStatusIcon').textContent = icon;
            
            var step1 = document.getElementById('step1');
            var step2 = document.getElementById('step2');
            var step3 = document.getElementById('step3');
            
            step1.className = 'progress-step';
            step2.className = 'progress-step';
            step3.className = 'progress-step';
            
            if (status === 'stopping') {
                step1.classList.add('active');
            } else if (status === 'reinstalling') {
                step1.classList.add('completed');
                step2.classList.add('active');
            } else if (status === 'starting') {
                step1.classList.add('completed');
                step2.classList.add('completed');
                step3.classList.add('active');
            } else if (status === 'completed') {
                step1.classList.add('completed');
                step2.classList.add('completed');
                step3.classList.add('completed');
            }
        }
        
        function pollReinstallStatus(currentPhase) {
            if (progressDialogTimer) {
                clearTimeout(progressDialogTimer);
            }
            
            var formData = new FormData();
            formData.append('operation', 'auto_start_reinstall');
            formData.append('instance_id', progressDialogData.instanceId);
            formData.append('config_id', progressDialogData.configId);
            formData.append('image_id', progressDialogData.imageId);
            formData.append('password', progressDialogData.password);
            formData.append('key_pair_name', progressDialogData.keyPairName);
            formData.append('system_disk_size', progressDialogData.diskSize);
            formData.append('phase', currentPhase);
            
            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    var newStatus = data.status;
                    updateProgressUI(data.message, newStatus);
                    
                    if (newStatus === 'completed') {
                        showToast('重装系统完成！', 'success');
                        setTimeout(function() { location.reload(); }, 2000);
                    } else if (newStatus !== 'error') {
                        progressDialogTimer = setTimeout(function() {
                            pollReinstallStatus(newStatus);
                        }, 5000);
                    }
                } else {
                    updateProgressUI(data.message || '操作失败', 'error');
                    showToast(data.message || '操作失败', 'error');
                }
            })
            .catch(function(err) {
                updateProgressUI('请求失败: ' + err.message, 'error');
                showToast('请求失败: ' + err.message, 'error');
            });
        }
    </script>
</body>
</html>
