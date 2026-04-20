<?php
/**
 * 阿里云ECS管理类
 * 通过ECS OpenAPI管理云服务器
 * API版本: 2014-05-26
 */

class AliyunEcs
{
    private $accessKeyId;
    private $accessKeySecret;
    private $regionId;
    private $ecsEndpoint;
    private $ecsApiVersion;
    private $timeout;

    /**
     * 构造函数
     * @param string $accessKeyId     AccessKey ID
     * @param string $accessKeySecret AccessKey Secret
     * @param string $regionId        地域ID
     */
    public function __construct($accessKeyId, $accessKeySecret, $regionId = 'cn-hangzhou')
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->regionId = $regionId;
        $this->ecsEndpoint = $this->getEndpoint($regionId);
        $this->ecsApiVersion = '2014-05-26';
        $this->timeout = 30;
    }

    /**
     * 查询ECS实例列表
     * @param int    $pageNum  页码
     * @param int    $pageSize 每页数量
     * @param string $status   实例状态过滤
     * @return array 实例列表
     */
    public function describeInstances($pageNum = 1, $pageSize = 50, $status = '')
    {
        $params = [
            'Action' => 'DescribeInstances',
            'RegionId' => $this->regionId,
            'PageNumber' => $pageNum,
            'PageSize' => $pageSize
        ];

        if (!empty($status)) {
            $params['Status'] = $status;
        }

        return $this->request($params);
    }

    /**
     * 查询单个ECS实例详情
     * @param string $instanceId 实例ID
     * @return array 实例详情
     */
    public function describeInstanceAttribute($instanceId)
    {
        $params = [
            'Action' => 'DescribeInstanceAttribute',
            'InstanceId' => $instanceId,
            'RegionId' => $this->regionId
        ];

        return $this->request($params);
    }

    /**
     * 启动ECS实例
     * @param string $instanceId 实例ID
     * @return array 操作结果
     */
    public function startInstance($instanceId)
    {
        $params = [
            'Action' => 'StartInstance',
            'InstanceId' => $instanceId,
            'RegionId' => $this->regionId
        ];

        return $this->request($params);
    }

    /**
     * 停止ECS实例
     * @param string $instanceId      实例ID
     * @param bool   $forceStop       是否强制停止
     * @param bool   $hibernate       是否休眠
     * @return array 操作结果
     */
    public function stopInstance($instanceId, $forceStop = false, $hibernate = false)
    {
        $params = [
            'Action' => 'StopInstance',
            'InstanceId' => $instanceId,
            'RegionId' => $this->regionId,
            'ForceStop' => $forceStop ? 'true' : 'false',
            'Hibernate' => $hibernate ? 'true' : 'false'
        ];

        return $this->request($params);
    }

    /**
     * 重启ECS实例
     * @param string $instanceId 实例ID
     * @param bool   $forceStop  是否强制重启
     * @return array 操作结果
     */
    public function rebootInstance($instanceId, $forceStop = false)
    {
        $params = [
            'Action' => 'RebootInstance',
            'InstanceId' => $instanceId,
            'RegionId' => $this->regionId,
            'ForceReboot' => $forceStop ? 'true' : 'false'
        ];

        return $this->request($params);
    }

    /**
     * 查询实例状态
     * @param string $instanceId 实例ID
     * @return array 实例状态
     */
    public function describeInstanceStatus($instanceId)
    {
        $params = [
            'Action' => 'DescribeInstanceStatus',
            'RegionId' => $this->regionId,
            'InstanceId.1' => $instanceId
        ];

        return $this->request($params);
    }

    /**
     * 查询实例的VNC远程连接地址
     * @param string $instanceId 实例ID
     * @return array VNC连接信息
     */
    public function describeInstanceVncUrl($instanceId)
    {
        $params = [
            'Action' => 'DescribeInstanceVncUrl',
            'InstanceId' => $instanceId,
            'RegionId' => $this->regionId
        ];

        return $this->request($params);
    }

    /**
     * 查询云盘列表
     * @param string $instanceId 实例ID(可选)
     * @return array 云盘列表
     */
    public function describeDisks($instanceId = '')
    {
        $params = [
            'Action' => 'DescribeDisks',
            'RegionId' => $this->regionId,
            'PageSize' => 50
        ];

        if (!empty($instanceId)) {
            $params['InstanceId'] = $instanceId;
        }

        return $this->request($params);
    }

    /**
     * 查询安全组列表
     * @param string $vpcId VPC ID(可选)
     * @return array 安全组列表
     */
    public function describeSecurityGroups($vpcId = '')
    {
        $params = [
            'Action' => 'DescribeSecurityGroups',
            'RegionId' => $this->regionId,
            'PageSize' => 50
        ];

        if (!empty($vpcId)) {
            $params['VpcId'] = $vpcId;
        }

        return $this->request($params);
    }

    /**
     * 查询实例公网IP地址
     * @param string $instanceId 实例ID
     * @return array 公网IP信息
     */
    public function describeEipAddresses($instanceId = '')
    {
        $params = [
            'Action' => 'DescribeEipAddresses',
            'RegionId' => $this->regionId,
            'PageSize' => 50
        ];

        if (!empty($instanceId)) {
            $params['AssociatedInstanceType'] = 'EcsInstance';
            $params['AssociatedInstanceId'] = $instanceId;
        }

        return $this->request($params);
    }

    /**
     * 查询可用地域列表
     * @return array 地域列表
     */
    public function describeRegions()
    {
        $params = [
            'Action' => 'DescribeRegions'
        ];

        return $this->request($params);
    }

    /**
     * 修改实例名称
     * @param string $instanceId   实例ID
     * @param string $instanceName 新名称
     * @return array 操作结果
     */
    public function modifyInstanceAttribute($instanceId, $instanceName = '', $description = '')
    {
        $params = [
            'Action' => 'ModifyInstanceAttribute',
            'InstanceId' => $instanceId
        ];

        if (!empty($instanceName)) {
            $params['InstanceName'] = $instanceName;
        }

        if (!empty($description)) {
            $params['Description'] = $description;
        }

        return $this->request($params);
    }

    /**
     * 查询可用镜像列表
     * @param string $imageOwnerAlias 镜像类型: system(公共), self(自定义), others(共享), marketplace(市场)
     * @param string $imageType       镜像用途: 可选值 raw, snapshot
     * @param string $ostype          操作系统类型: linux, windows
     * @param int    $pageNum         页码
     * @param int    $pageSize        每页数量
     * @return array 镜像列表
     */
    public function describeImages($imageOwnerAlias = '', $imageType = '', $ostype = '', $pageNum = 1, $pageSize = 50)
    {
        $params = [
            'Action' => 'DescribeImages',
            'RegionId' => $this->regionId,
            'PageNumber' => $pageNum,
            'PageSize' => $pageSize
        ];

        if (!empty($imageOwnerAlias)) {
            $params['ImageOwnerAlias'] = $imageOwnerAlias;
        }

        if (!empty($imageType)) {
            $params['ImageType'] = $imageType;
        }

        if (!empty($ostype)) {
            $params['OSType'] = $ostype;
        }

        return $this->request($params);
    }

    /**
     * 更换系统盘(重装系统)
     * @param string $instanceId   实例ID
     * @param string $imageId      镜像ID
     * @param string $password     新密码(可选，与KeyPairName二选一)
     * @param string $keyPairName  密钥对名称(可选，与Password二选一)
     * @param int    $systemDiskSize 系统盘大小GB(可选，默认不变)
     * @return array 操作结果
     */
    public function replaceSystemDisk($instanceId, $imageId, $password = '', $keyPairName = '', $systemDiskSize = 0)
    {
        $params = [
            'Action' => 'ReplaceSystemDisk',
            'InstanceId' => $instanceId,
            'ImageId' => $imageId,
            'RegionId' => $this->regionId
        ];

        if (!empty($password)) {
            $params['Password'] = $password;
        }

        if (!empty($keyPairName)) {
            $params['KeyPairName'] = $keyPairName;
        }

        if ($systemDiskSize > 0) {
            $params['SystemDisk.Size'] = $systemDiskSize;
        }

        return $this->request($params);
    }

    /**
     * 查询密钥对列表
     * @param int $pageNum  页码
     * @param int $pageSize 每页数量
     * @return array 密钥对列表
     */
    public function describeKeyPairs($pageNum = 1, $pageSize = 50)
    {
        $params = [
            'Action' => 'DescribeKeyPairs',
            'RegionId' => $this->regionId,
            'PageNumber' => $pageNum,
            'PageSize' => $pageSize
        ];

        return $this->request($params);
    }

    /**
     * 发送API请求
     * @param array $params 请求参数
     * @return array 返回结果
     */
    private function request($params)
    {
        $publicParams = [
            'Format' => 'JSON',
            'Version' => $this->ecsApiVersion,
            'AccessKeyId' => $this->accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => $this->generateNonce()
        ];

        $params = array_merge($publicParams, $params);

        $signature = $this->computeSignature($params);
        $params['Signature'] = $signature;

        $url = 'https://' . $this->ecsEndpoint . '/?' . http_build_query($params);

        $response = $this->httpGet($url);

        if ($response === false) {
            return [
                'success' => false,
                'error' => 'HTTP请求失败，请检查服务器网络和curl扩展',
                'code' => -1
            ];
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'JSON解析失败: ' . json_last_error_msg() . '，原始响应: ' . mb_substr($response, 0, 500),
                'code' => -2
            ];
        }

        if (isset($result['Code'])) {
            $errorMsg = $result['Message'] ?? '请求失败';
            if (isset($result['Recommend'])) {
                $errorMsg .= ' 建议: ' . $result['Recommend'];
            }
            return [
                'success' => false,
                'error' => $errorMsg . ' (Code: ' . $result['Code'] . ')',
                'code' => $result['Code'],
                'requestId' => $result['RequestId'] ?? '',
                'hostId' => $result['HostId'] ?? ''
            ];
        }

        return [
            'success' => true,
            'data' => $result,
            'requestId' => $result['RequestId'] ?? ''
        ];
    }

    /**
     * 计算API签名
     * @param array $params 请求参数
     * @return string 签名字符串
     */
    private function computeSignature($params)
    {
        ksort($params);

        $queryString = '';
        foreach ($params as $key => $value) {
            $queryString .= '&' . $this->percentEncode($key) . '=' . $this->percentEncode($value);
        }

        $stringToSign = 'GET&%2F&' . $this->percentEncode(substr($queryString, 1));
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret . '&', true));

        return $signature;
    }

    /**
     * URL编码
     * @param string $str 待编码字符串
     * @return string 编码后字符串
     */
    private function percentEncode($str)
    {
        $res = urlencode($str);
        $res = str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $res);
        return $res;
    }

    /**
     * 生成随机字符串
     * @return string 随机字符串
     */
    private function generateNonce()
    {
        return md5(uniqid(mt_rand(), true));
    }

    /**
     * HTTP GET请求
     * @param string $url 请求URL
     * @return string|false 响应内容
     */
    private function httpGet($url)
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'AliyunECS-PHP-SDK/1.0');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $error) {
            return false;
        }

        if ($response === false) {
            return false;
        }

        return $response;
    }

    /**
     * 设置超时时间
     * @param int $timeout 超时时间(秒)
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    /**
     * 设置地域
     * @param string $regionId 地域ID
     */
    public function setRegion($regionId)
    {
        $this->regionId = $regionId;
        $this->ecsEndpoint = $this->getEndpoint($regionId);
    }

    /**
     * 根据地域获取ECS API端点
     * @param string $regionId 地域ID
     * @return string API端点
     */
    private function getEndpoint($regionId)
    {
        $endpointMap = [
            'cn-hangzhou' => 'ecs.cn-hangzhou.aliyuncs.com',
            'cn-shanghai' => 'ecs.cn-shanghai.aliyuncs.com',
            'cn-qingdao' => 'ecs.cn-qingdao.aliyuncs.com',
            'cn-beijing' => 'ecs.cn-beijing.aliyuncs.com',
            'cn-zhangjiakou' => 'ecs.cn-zhangjiakou.aliyuncs.com',
            'cn-huhehaote' => 'ecs.cn-huhehaote.aliyuncs.com',
            'cn-wulanchabu' => 'ecs.cn-wulanchabu.aliyuncs.com',
            'cn-shenzhen' => 'ecs.cn-shenzhen.aliyuncs.com',
            'cn-heyuan' => 'ecs.cn-heyuan.aliyuncs.com',
            'cn-guangzhou' => 'ecs.cn-guangzhou.aliyuncs.com',
            'cn-chengdu' => 'ecs.cn-chengdu.aliyuncs.com',
            'cn-nanjing' => 'ecs.cn-nanjing.aliyuncs.com',
            'cn-fuzhou' => 'ecs.cn-fuzhou.aliyuncs.com',
            'cn-hongkong' => 'ecs.cn-hongkong.aliyuncs.com',
            'ap-southeast-1' => 'ecs.ap-southeast-1.aliyuncs.com',
            'ap-southeast-2' => 'ecs.ap-southeast-2.aliyuncs.com',
            'ap-southeast-3' => 'ecs.ap-southeast-3.aliyuncs.com',
            'ap-southeast-5' => 'ecs.ap-southeast-5.aliyuncs.com',
            'ap-southeast-6' => 'ecs.ap-southeast-6.aliyuncs.com',
            'ap-southeast-7' => 'ecs.ap-southeast-7.aliyuncs.com',
            'ap-northeast-1' => 'ecs.ap-northeast-1.aliyuncs.com',
            'ap-northeast-2' => 'ecs.ap-northeast-2.aliyuncs.com',
            'ap-south-1' => 'ecs.ap-south-1.aliyuncs.com',
            'us-east-1' => 'ecs.us-east-1.aliyuncs.com',
            'us-west-1' => 'ecs.us-west-1.aliyuncs.com',
            'eu-west-1' => 'ecs.eu-west-1.aliyuncs.com',
            'eu-central-1' => 'ecs.eu-central-1.aliyuncs.com',
            'me-east-1' => 'ecs.me-east-1.aliyuncs.com',
            'me-central-1' => 'ecs.me-central-1.aliyuncs.com'
        ];

        if (isset($endpointMap[$regionId])) {
            return $endpointMap[$regionId];
        }

        return 'ecs.' . $regionId . '.aliyuncs.com';
    }

    /**
     * 解析实例状态为中文
     * @param string $status 状态英文
     * @return string 状态中文
     */
    public static function formatStatus($status)
    {
        $map = [
            'Pending' => '创建中',
            'Running' => '运行中',
            'Starting' => '启动中',
            'Stopping' => '停止中',
            'Stopped' => '已停止',
            'Rebooting' => '重启中',
            'Deleted' => '已删除',
            'Suspended' => '已暂停',
            'Migrating' => '迁移中'
        ];
        return $map[$status] ?? $status;
    }

    /**
     * 获取实例状态对应的CSS类名
     * @param string $status 状态英文
     * @return string CSS类名
     */
    public static function getStatusClass($status)
    {
        $map = [
            'Pending' => 'warning',
            'Running' => 'success',
            'Starting' => 'info',
            'Stopping' => 'warning',
            'Stopped' => 'danger',
            'Rebooting' => 'info',
            'Deleted' => 'danger',
            'Suspended' => 'warning',
            'Migrating' => 'info'
        ];
        return $map[$status] ?? 'secondary';
    }

    /**
     * 解析实例类型为中文描述
     * @param string $instanceType 实例类型
     * @return string 中文描述
     */
    public static function formatInstanceType($instanceType)
    {
        $map = [
            'ecs.t5' => '突发性能',
            'ecs.t6' => '突发性能',
            'ecs.s6' => '共享标准',
            'ecs.c5' => '计算型',
            'ecs.c6' => '计算型',
            'ecs.c7' => '计算型',
            'ecs.r5' => '内存型',
            'ecs.r6' => '内存型',
            'ecs.r7' => '内存型',
            'ecs.g5' => 'GPU计算型',
            'ecs.g6' => 'GPU计算型',
            'ecs.gn6' => 'GPU计算型',
            'ecs.d1' => '大数据型',
            'ecs.i2' => '本地SSD型',
            'ecs.hfc6' => '高主频计算型',
            'ecs.hfg6' => '高主频通用型',
            'ecs.ebmg6' => '弹性裸金属'
        ];

        foreach ($map as $prefix => $label) {
            if (strpos($instanceType, $prefix) === 0) {
                return $label;
            }
        }

        return '通用型';
    }

    /**
     * 解析网络类型为中文
     * @param string $type 网络类型
     * @return string 中文
     */
    public static function formatNetworkType($type)
    {
        $map = [
            'vpc' => '专有网络',
            'classic' => '经典网络'
        ];
        return $map[$type] ?? $type;
    }

    /**
     * 解析付费类型为中文
     * @param string $type 付费类型
     * @return string 中文
     */
    public static function formatChargeType($type)
    {
        $map = [
            'PrePaid' => '包年包月',
            'PostPaid' => '按量付费',
            'Subscription' => '包年包月',
            'PayAsYouGo' => '按量付费',
            'PayByBandwidth' => '按固定带宽',
            'PayByTraffic' => '按使用流量'
        ];
        return $map[$type] ?? $type;
    }

    /**
     * 解析操作系统类型
     * @param string $osName 操作系统名称
     * @return string 类型标识 linux/windows
     */
    public static function getOsType($osName)
    {
        if (empty($osName)) return 'unknown';
        if (stripos($osName, 'windows') !== false || stripos($osName, 'win') !== false) {
            return 'windows';
        }
        if (stripos($osName, 'centos') !== false || stripos($osName, 'ubuntu') !== false ||
            stripos($osName, 'debian') !== false || stripos($osName, 'linux') !== false ||
            stripos($osName, 'alibaba') !== false || stripos($osName, 'alios') !== false ||
            stripos($osName, 'fedora') !== false || stripos($osName, 'suse') !== false ||
            stripos($osName, 'redhat') !== false || stripos($osName, 'rocky') !== false ||
            stripos($osName, 'almalinux') !== false || stripos($osName, 'openSUSE') !== false) {
            return 'linux';
        }
        return 'unknown';
    }
}
