<?php
declare(strict_types=1);

/**
 * 极简 SMTP 客户端（无第三方依赖），支持 SSL(465) / STARTTLS(587) / 明文(25)。
 */
final class Mailer
{
    private string $host;
    private int $port;
    private string $secure;
    private string $username;
    private string $password;
    private bool $verifySsl;
    private int $timeout;
    /** @var resource|null */
    private $socket = null;
    private string $ehloCaps = '';
    private string $localName;

    public function __construct(array $settings)
    {
        $this->host = trim((string)($settings['smtp_host'] ?? ''));
        $this->localName = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)(gethostname() ?: 'localhost')) ?: 'localhost';
        $this->port = (int)($settings['smtp_port'] ?? 465);
        $this->secure = in_array($settings['smtp_secure'] ?? 'ssl', ['ssl', 'tls', 'none'], true)
            ? (string)$settings['smtp_secure']
            : 'ssl';
        $this->username = trim((string)($settings['smtp_user'] ?? ''));
        $this->password = (string)($settings['smtp_pass'] ?? '');
        $this->verifySsl = ($settings['verify_ssl'] ?? '1') === '1';
        $this->timeout = (int)($settings['request_timeout'] ?? 20);
    }

    public static function configured(array $settings): bool
    {
        return trim((string)($settings['smtp_host'] ?? '')) !== ''
            && trim((string)($settings['smtp_to'] ?? '')) !== '';
    }

    public function send(string $from, string $fromName, array $recipients, string $subject, string $body): void
    {
        if ($this->host === '') {
            throw new RuntimeException('SMTP 服务器地址未填写');
        }
        if ($recipients === []) {
            throw new RuntimeException('收件人为空');
        }
        if ($this->secure !== 'none' && !extension_loaded('openssl')) {
            throw new RuntimeException('缺少 openssl 扩展，无法使用 ' . strtoupper($this->secure) . '，请改用明文或启用扩展');
        }

        $prefix = $this->secure === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifySsl,
                'verify_peer_name' => $this->verifySsl,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            (float)$this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new RuntimeException('SMTP 连接失败：' . ($errstr ?: '未知错误') . '（' . $errno . '）');
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->assertReply($this->readReply(), [220], '连接');
            $this->handshake();
            if ($this->secure === 'tls') {
                $this->assertReply($this->command('STARTTLS'), [220], 'STARTTLS');
                $crypto = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new RuntimeException('STARTTLS 握手失败');
                }
                $this->handshake();
            }
            $this->authenticate();

            $this->assertReply($this->command('MAIL FROM:<' . $from . '>'), [250], 'MAIL FROM');
            foreach ($recipients as $recipient) {
                $this->assertReply($this->command('RCPT TO:<' . $recipient . '>'), [250, 251], 'RCPT TO');
            }
            $this->assertReply($this->command('DATA'), [354], 'DATA');

            $message = $this->buildMessage($from, $fromName, $recipients, $subject, $body);
            $this->write($message . "\r\n.\r\n");
            $this->assertReply($this->readReply(), [250], '邮件投递');
            $this->command('QUIT');
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    private function handshake(): void
    {
        $name = $this->localName;
        $reply = $this->command('EHLO ' . $name);
        if ($reply['code'] === 250) {
            $this->ehloCaps = $reply['text'];
            return;
        }
        $reply = $this->command('HELO ' . $name);
        $this->assertReply($reply, [250], 'HELO');
        $this->ehloCaps = '';
    }

    private function authenticate(): void
    {
        if ($this->username === '' && $this->password === '') {
            return;
        }
        if ($this->ehloCaps === '' || stripos($this->ehloCaps, 'AUTH') === false) {
            throw new RuntimeException('SMTP 服务器不支持认证，但填写了账号密码');
        }
        if (stripos($this->ehloCaps, 'AUTH LOGIN') !== false) {
            $reply = $this->command('AUTH LOGIN');
            $this->assertReply($reply, [334], 'AUTH LOGIN');
            $this->write(base64_encode($this->username) . "\r\n");
            $this->assertReply($this->readReply(), [334], 'SMTP 用户名');
            $this->write(base64_encode($this->password) . "\r\n");
            $this->assertReply($this->readReply(), [235, 502], 'SMTP 密码');
            return;
        }
        if (stripos($this->ehloCaps, 'AUTH PLAIN') !== false) {
            $token = base64_encode($this->username . "\x00" . $this->username . "\x00" . $this->password);
            $this->assertReply($this->command('AUTH PLAIN ' . $token), [235], 'AUTH PLAIN');
            return;
        }
        throw new RuntimeException('SMTP 服务器未提供 LOGIN/PLAIN 认证方式');
    }

    private function buildMessage(string $from, string $fromName, array $recipients, string $subject, string $body): string
    {
        $fromHeader = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>'
            : $from;
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $fromHeader,
            'To: ' . implode(', ', $recipients),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($this->localName ?: 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n");
    }

    private function command(string $line): array
    {
        $this->write($line . "\r\n");
        return $this->readReply();
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP 连接已断开');
        }
        $written = @fwrite($this->socket, $data);
        if ($written === false || $written === 0) {
            throw new RuntimeException('SMTP 写入失败');
        }
    }

    /**
     * @return array{code:int,text:string}
     */
    private function readReply(): array
    {
        $code = 0;
        $lines = [];
        while (is_resource($this->socket) && !feof($this->socket)) {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                break;
            }
            $line = rtrim($line, "\r\n");
            if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $m)) {
                $code = (int)$m[1];
                $lines[] = $m[3];
                if ($m[2] === ' ') {
                    break;
                }
            }
        }
        $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : ['timed_out' => false];
        if ($code === 0 && !empty($meta['timed_out'])) {
            throw new RuntimeException('SMTP 响应超时');
        }
        return ['code' => $code, 'text' => implode(' ', $lines)];
    }

    private function assertReply(array $reply, array $expected, string $step): void
    {
        if (!in_array($reply['code'], $expected, true)) {
            throw new RuntimeException($step . ' 失败：' . $reply['code'] . ' ' . $reply['text']);
        }
    }
}