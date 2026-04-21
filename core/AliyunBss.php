<?php
/**
 * 阿里云BSS账单查询类
 * 通过BssOpenAPI查询账号账单、账单总览、账户余额等
 * BssOpenAPI版本: 2017-12-14
 * Endpoint: business.aliyuncs.com
 */

class AliyunBss
{
    private $accessKeyId;
    private $accessKeySecret;
    private $endpoint;
    private $apiVersion;
    private $timeout;

    /**
     * 构造函数
     * @param string $accessKeyId     AccessKey ID
     * @param string $accessKeySecret AccessKey Secret
     */
    public function __construct($accessKeyId, $accessKeySecret)
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->endpoint = 'business.aliyuncs.com';
        $this->apiVersion = '2017-12-14';
        $this->timeout = 30;
    }

    /**
     * 查询账号账单（按资源所有者汇总）
     * @param string $billingCycle 账期 YYYY-MM
     * @param string $billingDate  账单日期 YYYY-MM-DD（Granularity=DAILY时必填）
     * @param string $granularity  统计粒度 MONTHLY|DAILY
     * @param string $productCode  产品代码
     * @param int    $pageNum      页码
     * @param int    $pageSize     每页数量
     * @return array 查询结果
     */
    public function queryAccountBill($billingCycle, $billingDate = '', $granularity = 'MONTHLY', $productCode = '', $pageNum = 1, $pageSize = 50)
    {
        $params = [
            'Action' => 'QueryAccountBill',
            'BillingCycle' => $billingCycle,
            'Granularity' => $granularity,
            'PageNum' => $pageNum,
            'PageSize' => $pageSize
        ];

        if (!empty($billingDate) && $granularity === 'DAILY') {
            $params['BillingDate'] = $billingDate;
        }

        if (!empty($productCode)) {
            $params['ProductCode'] = $productCode;
        }

        return $this->request($params);
    }

    /**
     * 查询账单总览
     * @param string $billingCycle 账期 YYYY-MM
     * @param string $productCode  产品代码
     * @return array 查询结果
     */
    public function queryBillOverview($billingCycle, $productCode = '')
    {
        $params = [
            'Action' => 'QueryBillOverview',
            'BillingCycle' => $billingCycle
        ];

        if (!empty($productCode)) {
            $params['ProductCode'] = $productCode;
        }

        return $this->request($params);
    }

    /**
     * 查询实例账单
     * @param string $billingCycle   账期 YYYY-MM
     * @param string $billingDate    账单日期 YYYY-MM-DD
     * @param string $granularity    统计粒度 MONTHLY|DAILY
     * @param string $productCode    产品代码
     * @param bool   $isBillingItem  是否按计费项查询
     * @param int    $pageNum        页码
     * @param int    $pageSize       每页数量
     * @return array 查询结果
     */
    public function queryInstanceBill($billingCycle, $billingDate = '', $granularity = 'MONTHLY', $productCode = '', $isBillingItem = false, $pageNum = 1, $pageSize = 50)
    {
        $params = [
            'Action' => 'QueryInstanceBill',
            'BillingCycle' => $billingCycle,
            'Granularity' => $granularity,
            'PageNum' => $pageNum,
            'PageSize' => $pageSize,
            'IsBillingItem' => $isBillingItem ? 'true' : 'false'
        ];

        if (!empty($billingDate) && $granularity === 'DAILY') {
            $params['BillingDate'] = $billingDate;
        }

        if (!empty($productCode)) {
            $params['ProductCode'] = $productCode;
        }

        return $this->request($params);
    }

    /**
     * 查询账户余额
     * @return array 查询结果
     */
    public function queryAccountBalance()
    {
        $params = [
            'Action' => 'QueryAccountBalance'
        ];

        return $this->request($params);
    }

    /**
     * 查询账户收支明细
     * @param string $createTimeStart 开始时间 YYYY-MM-DD HH:mm:ss
     * @param string $createTimeEnd   结束时间 YYYY-MM-DD HH:mm:ss
     * @param int    $pageNum         页码
     * @param int    $pageSize        每页数量
     * @return array 查询结果
     */
    public function queryAccountTransactionDetails($createTimeStart = '', $createTimeEnd = '', $pageNum = 1, $pageSize = 50)
    {
        $params = [
            'Action' => 'QueryAccountTransactionDetails',
            'PageNum' => $pageNum,
            'PageSize' => $pageSize
        ];

        if (!empty($createTimeStart)) {
            $params['CreateTimeStart'] = $createTimeStart;
        }
        if (!empty($createTimeEnd)) {
            $params['CreateTimeEnd'] = $createTimeEnd;
        }

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
            'Version' => $this->apiVersion,
            'AccessKeyId' => $this->accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => $this->generateNonce()
        ];

        $params = array_merge($publicParams, $params);

        $signature = $this->computeSignature($params);
        $params['Signature'] = $signature;

        $url = 'https://' . $this->endpoint . '/?' . http_build_query($params);

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
                'error' => 'JSON解析失败: ' . json_last_error_msg(),
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
     * 格式化金额
     * @param float  $amount   金额
     * @param string $currency 币种 CNY|USD
     * @param int    $decimals 小数位数
     * @return string 格式化后的金额
     */
    public static function formatAmount($amount, $currency = 'CNY', $decimals = 2)
    {
        $amount = floatval($amount);
        $symbol = ($currency === 'USD') ? '$' : '¥';
        $code = ($currency === 'USD') ? 'USD' : 'CNY';
        if ($amount == 0) {
            return '0.00 ' . $code;
        }
        return number_format($amount, $decimals) . ' ' . $code;
    }

    /**
     * 格式化订阅类型
     * @param string $type 订阅类型
     * @return string 中文描述
     */
    public static function formatSubscriptionType($type)
    {
        $map = [
            'Subscription' => '包年包月',
            'PayAsYouGo' => '按量付费',
            'PrePaid' => '包年包月',
            'PostPaid' => '按量付费'
        ];
        return $map[$type] ?? $type;
    }

    /**
     * 设置超时时间
     * @param int $timeout 超时时间(秒)
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }
}
