<?php
/**
 * 辅助函数类
 * 提供常用的辅助方法
 */

class Helper
{
    /**
     * 安全加密字符串
     * @param string $string 待加密字符串
     * @param string $key    加密密钥
     * @return string 加密后的字符串
     */
    public static function encrypt($string, $key = '')
    {
        if (empty($key)) {
            $key = isset($_SERVER['APP_SECRET']) ? $_SERVER['APP_SECRET'] : 'default_secret_key';
        }
        
        $ivLength = openssl_cipher_iv_length('AES-256-CBC');
        $iv = openssl_random_pseudo_bytes($ivLength);
        
        $encrypted = openssl_encrypt($string, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        
        return base64_encode($iv . $encrypted);
    }

    /**
     * 解密字符串
     * @param string $string 加密的字符串
     * @param string $key    加密密钥
     * @return string|false 解密后的字符串
     */
    public static function decrypt($string, $key = '')
    {
        if (empty($key)) {
            $key = isset($_SERVER['APP_SECRET']) ? $_SERVER['APP_SECRET'] : 'default_secret_key';
        }
        
        $string = base64_decode($string);
        
        $ivLength = openssl_cipher_iv_length('AES-256-CBC');
        $iv = substr($string, 0, $ivLength);
        $encrypted = substr($string, $ivLength);
        
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * 生成密码哈希
     * @param string $password 密码
     * @return string 密码哈希
     */
    public static function passwordHash($password)
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * 验证密码
     * @param string $password 密码
     * @param string $hash     密码哈希
     * @return bool 是否匹配
     */
    public static function passwordVerify($password, $hash)
    {
        return password_verify($password, $hash);
    }

    /**
     * 生成随机字符串
     * @param int $length 长度
     * @return string 随机字符串
     */
    public static function randomString($length = 32)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $str;
    }

    /**
     * 安全过滤输入
     * @param string $string 输入字符串
     * @return string 过滤后的字符串
     */
    public static function sanitize($string)
    {
        $string = trim($string);
        $string = stripslashes($string);
        $string = htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
        
        return $string;
    }

    /**
     * JSON响应
     * @param array $data 数据
     * @param int $code HTTP状态码
     */
    public static function jsonResponse($data, $code = 200)
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 成功响应
     * @param mixed $data 数据
     * @param string $message 消息
     */
    public static function success($data = null, $message = '操作成功')
    {
        self::jsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * 错误响应
     * @param string $message 错误消息
     * @param int $code 错误码
     */
    public static function error($message = '操作失败', $code = 400)
    {
        self::jsonResponse([
            'success' => false,
            'message' => $message,
            'code' => $code
        ], $code);
    }

    /**
     * 重定向
     * @param string $url 目标URL
     */
    public static function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * 获取客户端IP
     * @return string IP地址
     */
    public static function getClientIp()
    {
        $ip = '';
        
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // 处理多个IP的情况
        if (strpos($ip, ',') !== false) {
            $ips = explode(',', $ip);
            $ip = trim($ips[0]);
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /**
     * 检查是否是AJAX请求
     * @return bool 是否是AJAX请求
     */
    public static function isAjax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * 获取当前URL
     * @return string 当前URL
     */
    public static function currentUrl()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }

    /**
     * 格式化日期时间
     * @param string $datetime 日期时间
     * @param string $format   格式
     * @return string 格式化后的日期时间
     */
    public static function formatDateTime($datetime, $format = 'Y-m-d H:i:s')
    {
        $time = strtotime($datetime);
        return $time ? date($format, $time) : '';
    }

    /**
     * 相对时间
     * @param string $datetime 日期时间
     * @return string 相对时间
     */
    public static function timeAgo($datetime)
    {
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) {
            return '刚刚';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . '分钟前';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . '小时前';
        } elseif ($diff < 2592000) {
            return floor($diff / 86400) . '天前';
        } else {
            return date('Y-m-d', $time);
        }
    }

    /**
     * 验证邮箱格式
     * @param string $email 邮箱
     * @return bool 是否有效
     */
    public static function validateEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * 验证URL格式
     * @param string $url URL
     * @return bool 是否有效
     */
    public static function validateUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 数组转XML
     * @param array $data 数据
     * @param string $root 根节点名称
     * @return string XML字符串
     */
    public static function arrayToXml($data, $root = 'root')
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><' . $root . '/>');
        
        self::arrayToXmlRecursive($data, $xml);
        
        return $xml->asXML();
    }

    /**
     * 递归数组转XML
     * @param array $data 数据
     * @param SimpleXMLElement $xml XML对象
     */
    private static function arrayToXmlRecursive($data, $xml)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $subnode = $xml->addChild($key);
                self::arrayToXmlRecursive($value, $subnode);
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }
    }

    /**
     * 调试输出
     * @param mixed $data 数据
     * @param bool $exit 是否退出
     */
    public static function debug($data, $exit = true)
    {
        echo '<pre>';
        print_r($data);
        echo '</pre>';
        
        if ($exit) {
            exit;
        }
    }
}
