<?php
/**
 * 阿里云CDT流量查询类
 * 通过BSS(费用与成本)API查询云数据传输流量使用情况
 * CDT产品本身没有公开OpenAPI，流量数据通过账单API获取
 * BssOpenAPI版本: 2017-12-14
 */

class AliyunCdt
{
    private $accessKeyId;
    private $accessKeySecret;
    private $regionId;
    private $bssEndpoint;
    private $bssApiVersion;
    private $timeout;

    /**
     * 构造函数
     * @param string $accessKeyId     AccessKey ID
     * @param string $accessKeySecret AccessKey Secret
     * @param string $regionId        地域ID，默认cn-hangzhou
     */
    public function __construct($accessKeyId, $accessKeySecret, $regionId = 'cn-hangzhou')
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->regionId = $regionId;
        $this->bssEndpoint = 'business.aliyuncs.com';
        $this->bssApiVersion = '2017-12-14';
        $this->timeout = 30;
    }

    /**
     * 查询CDT公网流量使用情况（通过账单API）
     * @param string $billingCycle 账期 格式: YYYY-MM
     * @param int    $pageNum      页码
     * @param int    $pageSize     每页数量
     * @return array 返回流量数据
     */
    public function describeInternetTraffic($billingCycle = null, $pageNum = 1, $pageSize = 100)
    {
        if (empty($billingCycle)) {
            $billingCycle = date('Y-m');
        }

        $params = [
            'Action' => 'QueryInstanceBill',
            'BillingCycle' => $billingCycle,
            'ProductCode' => 'cdt',
            'PageNum' => $pageNum,
            'PageSize' => $pageSize,
            'IsBillingItem' => 'true'
        ];

        return $this->request($params);
    }

    /**
     * 查询CDT公网流量按日明细
     * @param string $billingDate  账单日期 格式: YYYY-MM-DD，必须指定
     * @param int    $pageNum      页码
     * @param int    $pageSize     每页数量
     * @return array 返回流量数据
     */
    public function describeDailyTraffic($billingDate = null, $pageNum = 1, $pageSize = 300)
    {
        if (empty($billingDate)) {
            $billingDate = date('Y-m-d');
        }

        $billingCycle = substr($billingDate, 0, 7);

        $params = [
            'Action' => 'QueryInstanceBill',
            'BillingCycle' => $billingCycle,
            'BillingDate' => $billingDate,
            'ProductCode' => 'cdt',
            'Granularity' => 'DAILY',
            'PageNum' => $pageNum,
            'PageSize' => $pageSize,
            'IsBillingItem' => 'true'
        ];

        return $this->request($params);
    }

    /**
     * 查询CDT账单总览
     * @param string $billingCycle 账期 格式: YYYY-MM
     * @return array 返回账单总览
     */
    public function describeBillOverview($billingCycle = null)
    {
        if (empty($billingCycle)) {
            $billingCycle = date('Y-m');
        }

        $params = [
            'Action' => 'QueryBillOverview',
            'BillingCycle' => $billingCycle,
            'ProductCode' => 'cdt'
        ];

        return $this->request($params);
    }

    /**
     * 测试API连接是否正常
     * @return array 测试结果
     */
    public function testConnection()
    {
        $params = [
            'Action' => 'QueryAccountBalance'
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
            'Version' => $this->bssApiVersion,
            'AccessKeyId' => $this->accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => $this->generateNonce()
        ];

        $params = array_merge($publicParams, $params);

        $signature = $this->computeSignature($params);
        $params['Signature'] = $signature;

        $url = 'https://' . $this->bssEndpoint . '/?' . http_build_query($params);

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
                'error' => 'JSON解析失败: ' . json_last_error_msg() . '，原始响应: ' . mb_substr($response, 0, 200),
                'code' => -2
            ];
        }

        $code = $result['Code'] ?? null;
        $isSuccess = false;
        
        if ($code === 200 || $code === '200') {
            $isSuccess = true;
        } elseif (is_string($code) && strtolower($code) === 'success') {
            $isSuccess = true;
        } elseif (isset($result['Success']) && $result['Success'] === true) {
            $isSuccess = true;
        }

        if (!$isSuccess && $code !== null) {
            return [
                'success' => false,
                'error' => ($result['Message'] ?? '请求失败') . ' (Code: ' . $code . ')',
                'code' => $code,
                'requestId' => $result['RequestId'] ?? ''
            ];
        }

        return [
            'success' => true,
            'data' => $result['Data'] ?? $result,
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
        curl_setopt($ch, CURLOPT_USERAGENT, 'AliyunBss-PHP-SDK/1.0');
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

        if ($httpCode >= 400) {
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
    }

    /**
     * 格式化流量大小
     * @param int|float $bytes 字节数
     * @param int $decimals 小数位数
     * @return string 格式化后的字符串
     */
    public static function formatBytes($bytes, $decimals = 2)
    {
        $bytes = floatval($bytes);
        if ($bytes < 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $factor = floor((strlen(strval(intval($bytes))) - 1) / 3);

        if ($factor >= count($units)) {
            $factor = count($units) - 1;
        }

        if ($factor == 0) {
            return sprintf("%.{$decimals}f %s", $bytes, $units[0]);
        }

        return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * 格式化GB流量
     * @param int|float $gb GB数值
     * @return string 格式化后的字符串
     */
    public static function formatGB($gb)
    {
        $gb = floatval($gb);
        if ($gb >= 1024) {
            return sprintf("%.2f TB", $gb / 1024);
        }
        return sprintf("%.2f GB", $gb);
    }

    /**
     * 获取时间范围
     * @param string $type 类型: today, week, month, custom
     * @param string $customStart 自定义开始时间
     * @param string $customEnd   自定义结束时间
     * @return array 开始和结束时间
     */
    public static function getTimeRange($type = 'today', $customStart = null, $customEnd = null)
    {
        $timezone = new DateTimeZone('Asia/Shanghai');

        switch ($type) {
            case 'today':
                $start = new DateTime('today', $timezone);
                $end = new DateTime('now', $timezone);
                break;
            case 'yesterday':
                $start = new DateTime('yesterday', $timezone);
                $end = new DateTime('yesterday 23:59:59', $timezone);
                break;
            case 'week':
                $start = new DateTime('-7 days', $timezone);
                $end = new DateTime('now', $timezone);
                break;
            case 'month':
                $start = new DateTime('first day of this month 00:00:00', $timezone);
                $end = new DateTime('now', $timezone);
                break;
            case 'custom':
                $start = $customStart ? new DateTime($customStart, $timezone) : new DateTime('-7 days', $timezone);
                $end = $customEnd ? new DateTime($customEnd, $timezone) : new DateTime('now', $timezone);
                break;
            default:
                $start = new DateTime('today', $timezone);
                $end = new DateTime('now', $timezone);
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'startDisplay' => $start->format('Y-m-d H:i:s'),
            'endDisplay' => $end->format('Y-m-d H:i:s'),
            'billingCycle' => $end->format('Y-m')
        ];
    }

    /**
     * 解析CDT账单数据为流量信息
     * @param array $billData 账单数据
     * @return array 流量信息
     */
    public static function parseTrafficData($billData)
    {
        $trafficList = [];
        $totalUsage = 0;
        $totalCost = 0;

        if (!isset($billData['Items']['Item']) && !isset($billData['Items'])) {
            return [
                'list' => [],
                'totalUsage' => 0,
                'totalCost' => 0,
                'totalUsageFormatted' => '0 GB'
            ];
        }

        $items = $billData['Items']['Item'] ?? $billData['Items'];

        if (isset($items['InstanceID'])) {
            $items = [$items];
        }

        foreach ($items as $item) {
            $usage = floatval($item['Usage'] ?? 0);
            $usageUnit = $item['UsageUnit'] ?? 'GB';
            $cost = floatval($item['PretaxAmount'] ?? 0);

            if (strpos($usageUnit, 'GB') !== false || strpos($usageUnit, 'gb') !== false) {
                $usageGB = $usage;
            } elseif (strpos($usageUnit, 'MB') !== false || strpos($usageUnit, 'mb') !== false) {
                $usageGB = $usage / 1024;
            } elseif (strpos($usageUnit, 'TB') !== false || strpos($usageUnit, 'tb') !== false) {
                $usageGB = $usage * 1024;
            } else {
                $usageGB = $usage;
            }

            $totalUsage += $usageGB;
            $totalCost += $cost;

            $trafficList[] = [
                'instanceId' => $item['InstanceID'] ?? '',
                'region' => $item['Region'] ?? '',
                'billingItem' => $item['BillingItem'] ?? '',
                'productDetail' => $item['ProductDetail'] ?? '',
                'usage' => $usageGB,
                'usageUnit' => 'GB',
                'usageFormatted' => self::formatGB($usageGB),
                'cost' => $cost,
                'listPrice' => $item['ListPrice'] ?? '0',
                'listPriceUnit' => $item['ListPriceUnit'] ?? '',
                'billingDate' => $item['BillingDate'] ?? '',
                'subscriptionType' => $item['SubscriptionType'] ?? '',
                'nickName' => $item['NickName'] ?? '',
                'internetIP' => $item['InternetIP'] ?? '',
                'tag' => $item['Tag'] ?? ''
            ];
        }

        return [
            'list' => $trafficList,
            'totalUsage' => $totalUsage,
            'totalCost' => $totalCost,
            'totalUsageFormatted' => self::formatGB($totalUsage)
        ];
    }
}
