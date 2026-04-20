<?php
/**
 * 邮件发送类
 * 使用SMTP协议发送邮件（不依赖第三方库）
 */

class Mailer
{
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $from;
    private $timeout;
    private $lastError;

    /**
     * 构造函数
     * @param string $host       SMTP服务器
     * @param int    $port       端口
     * @param string $username   用户名
     * @param string $password   密码
     * @param string $encryption 加密方式 ssl/tls/none
     * @param string $from       发件人邮箱
     */
    public function __construct($host, $port, $username, $password, $encryption = 'ssl', $from = '')
    {
        $this->host = $host;
        $this->port = intval($port);
        $this->username = $username;
        $this->password = $password;
        $this->encryption = $encryption;
        $this->from = $from ?: $username;
        $this->timeout = 30;
        $this->lastError = '';
    }

    /**
     * 发送邮件
     * @param string|array $to      收件人
     * @param string       $subject 主题
     * @param string       $body    正文
     * @param string       $fromName 发件人名称
     * @return bool 是否成功
     */
    public function send($to, $subject, $body, $fromName = '')
    {
        $this->lastError = '';

        if (is_array($to)) {
            $to = implode(', ', $to);
        }

        $socket = $this->connect();
        if (!$socket) {
            return false;
        }

        try {
            $response = $this->readResponse($socket);
            if (!$this->isSuccess($response, 220)) {
                throw new Exception('连接失败: ' . $response);
            }

            $this->sendCommand($socket, 'EHLO ' . $this->host);
            $response = $this->readResponse($socket);
            if (!$this->isSuccess($response, 250)) {
                throw new Exception('EHLO失败: ' . $response);
            }

            if ($this->encryption === 'tls') {
                $this->sendCommand($socket, 'STARTTLS');
                $response = $this->readResponse($socket);
                if (!$this->isSuccess($response, 220)) {
                    throw new Exception('STARTTLS失败: ' . $response);
                }
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                
                $this->sendCommand($socket, 'EHLO ' . $this->host);
                $response = $this->readResponse($socket);
            }

            $this->sendCommand($socket, 'AUTH LOGIN');
            $response = $this->readResponse($socket);
            if (!$this->isSuccess($response, 334)) {
                throw new Exception('AUTH失败: ' . $response);
            }

            $this->sendCommand($socket, base64_encode($this->username));
            $response = $this->readResponse($socket);
            if (!$this->isSuccess($response, 334)) {
                throw new Exception('用户名验证失败: ' . $response);
            }

            $this->sendCommand($socket, base64_encode($this->password));
            $response = $this->readResponse($socket);
            if (!$this->isSuccess($response, 235)) {
                throw new Exception('密码验证失败: ' . $response);
            }

            $this->sendCommand($socket, 'MAIL FROM: <' . $this->from . '>');
            $response = $this->readResponse($socket);
            if (!$this->isSuccess($response, 250)) {
                throw new Exception('MAIL FROM失败: ' . $response);
            }

            $recipients = array_map('trim', explode(',', $to));
            foreach ($recipients as $recipient) {
                $this->sendCommand($socket, 'RCPT TO: <' . $recipient . '>');
                $response = $this->readResponse($socket);
                if (!$this->isSuccess($response, 250) && !$this->isSuccess($response, 251)) {
                    throw new Exception('RCPT TO失败: ' . $response);
                }
            }

            $this->sendCommand($socket, 'DATA');
            $response = $this->readResponse($socket);
            if (!$this->isSuccess($response, 354)) {
                throw new Exception('DATA失败: ' . $response);
            }

            $headers = [];
            $headers[] = 'From: ' . ($fromName ? $this->encodeHeader($fromName) . ' ' : '') . '<' . $this->from . '>';
            $headers[] = 'To: ' . $to;
            $headers[] = 'Subject: ' . $this->encodeHeader($subject);
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $headers[] = 'Date: ' . date('r');
            $headers[] = 'X-Mailer: AliyunTrafficMailer/1.0';

            $message = implode("\r\n", $headers) . "\r\n\r\n";
            $message .= chunk_split(base64_encode($body));

            $this->sendCommand($socket, $message . "\r\n.");
            $response = $this->readResponse($socket);
            if (!$this->isSuccess($response, 250)) {
                throw new Exception('发送失败: ' . $response);
            }

            $this->sendCommand($socket, 'QUIT');
            fclose($socket);

            return true;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            fclose($socket);
            return false;
        }
    }

    /**
     * 连接SMTP服务器
     * @return resource|false
     */
    private function connect()
    {
        $protocol = $this->encryption === 'ssl' ? 'ssl://' : '';
        $address = $protocol . $this->host . ':' . $this->port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $socket = @stream_socket_client($address, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            $this->lastError = "连接失败: {$errstr} ({$errno})";
            return false;
        }

        stream_set_timeout($socket, $this->timeout);

        return $socket;
    }

    /**
     * 发送命令
     * @param resource $socket
     * @param string   $command
     */
    private function sendCommand($socket, $command)
    {
        fwrite($socket, $command . "\r\n");
    }

    /**
     * 读取响应
     * @param resource $socket
     * @return string
     */
    private function readResponse($socket)
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return trim($response);
    }

    /**
     * 检查响应是否成功
     * @param string $response
     * @param int    $expectedCode
     * @return bool
     */
    private function isSuccess($response, $expectedCode)
    {
        return substr($response, 0, 3) == $expectedCode;
    }

    /**
     * 编码邮件头
     * @param string $str
     * @return string
     */
    private function encodeHeader($str)
    {
        if (preg_match('/[^\x20-\x7E]/', $str)) {
            return '=?UTF-8?B?' . base64_encode($str) . '?=';
        }
        return $str;
    }

    /**
     * 获取最后的错误
     * @return string
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * 设置超时时间
     * @param int $timeout
     */
    public function setTimeout($timeout)
    {
        $this->timeout = intval($timeout);
    }

    /**
     * 发送流量提醒邮件
     * @param array  $config     系统配置
     * @param float  $currentGB  当前流量(GB)
     * @param float  $maxGB      最大流量(GB)
     * @param int    $percent    使用百分比
     * @return bool
     */
    public static function sendTrafficAlert($config, $currentGB, $maxGB, $percent)
    {
        $mailer = new self(
            $config['mail_host'],
            $config['mail_port'],
            $config['mail_username'],
            $config['mail_password'],
            $config['mail_encryption'],
            $config['mail_from']
        );

        $subject = '【流量提醒】阿里云流量使用已达 ' . $percent . '%';
        
        $body = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;">
<div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
<h2 style="color: #e74c3c; margin-top: 0;">⚠️ 流量使用提醒</h2>
<p>您好，</p>
<p>您的阿里云流量使用已达到预设阈值，请注意控制流量使用。</p>
<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">当前使用流量</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;">' . number_format($currentGB, 2) . ' GB</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">最大流量限制</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right;">' . number_format($maxGB, 2) . ' GB</td></tr>
<tr><td style="padding: 10px; border-bottom: 1px solid #eee; color: #666;">使用比例</td><td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; text-align: right; color: #e74c3c;">' . $percent . '%</td></tr>
<tr><td style="padding: 10px; color: #666;">提醒时间</td><td style="padding: 10px; text-align: right;">' . date('Y-m-d H:i:s') . '</td></tr>
</table>
<p style="color: #999; font-size: 12px;">此邮件由系统自动发送，请勿回复。</p>
</div>
</body>
</html>';

        $recipients = array_map('trim', explode(',', $config['mail_to']));
        
        return $mailer->send($recipients, $subject, $body, '阿里云流量监控系统');
    }

    /**
     * 发送每个配置独立的流量提醒邮件
     * @param array $config        系统配置
     * @param array $alertConfigs  超限配置列表
     * @param float $totalTraffic  总流量(GB)
     * @return bool
     */
    public static function sendTrafficAlertPerConfig($config, $alertConfigs, $totalTraffic)
    {
        $mailer = new self(
            $config['mail_host'],
            $config['mail_port'],
            $config['mail_username'],
            $config['mail_password'],
            $config['mail_encryption'],
            $config['mail_from']
        );

        $subject = '【流量提醒】' . count($alertConfigs) . '个配置流量已达阈值';
        
        $configRows = '';
        foreach ($alertConfigs as $cfg) {
            $statusColor = $cfg['percent'] >= 100 ? '#e74c3c' : '#f39c12';
            $configRows .= '<tr>';
            $configRows .= '<td style="padding: 12px; border-bottom: 1px solid #eee; font-weight: 500;">' . htmlspecialchars($cfg['name']) . '</td>';
            $configRows .= '<td style="padding: 12px; border-bottom: 1px solid #eee; text-align: right;">' . number_format($cfg['traffic_gb'], 2) . ' GB</td>';
            $configRows .= '<td style="padding: 12px; border-bottom: 1px solid #eee; text-align: right;">' . number_format($cfg['max_traffic_gb'], 0) . ' GB</td>';
            $configRows .= '<td style="padding: 12px; border-bottom: 1px solid #eee; text-align: right; color: ' . $statusColor . '; font-weight: bold;">' . $cfg['percent'] . '%</td>';
            $configRows .= '<td style="padding: 12px; border-bottom: 1px solid #eee; text-align: right;">' . $cfg['threshold'] . '%</td>';
            $configRows .= '</tr>';
        }
        
        $body = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;">
<div style="max-width: 700px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
<h2 style="color: #e74c3c; margin-top: 0;">⚠️ 流量使用提醒</h2>
<p>您好，</p>
<p>以下 <strong>' . count($alertConfigs) . '</strong> 个阿里云配置的流量使用已达到预设阈值，请注意控制流量使用。</p>
<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
<thead>
<tr style="background: #f8f9fa;">
<th style="padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6;">配置名称</th>
<th style="padding: 12px; text-align: right; border-bottom: 2px solid #dee2e6;">已使用</th>
<th style="padding: 12px; text-align: right; border-bottom: 2px solid #dee2e6;">配额</th>
<th style="padding: 12px; text-align: right; border-bottom: 2px solid #dee2e6;">使用率</th>
<th style="padding: 12px; text-align: right; border-bottom: 2px solid #dee2e6;">阈值</th>
</tr>
</thead>
<tbody>
' . $configRows . '
</tbody>
</table>
<div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 20px;">
<p style="margin: 0; color: #666;">总流量使用：<strong style="color: #333;">' . number_format($totalTraffic, 2) . ' GB</strong></p>
<p style="margin: 10px 0 0 0; color: #999; font-size: 12px;">提醒时间：' . date('Y-m-d H:i:s') . '</p>
</div>
<p style="color: #999; font-size: 12px; margin-top: 20px;">此邮件由系统自动发送，请勿回复。</p>
</div>
</body>
</html>';

        $recipients = array_map('trim', explode(',', $config['mail_to']));
        
        return $mailer->send($recipients, $subject, $body, '阿里云流量监控系统');
    }
}
