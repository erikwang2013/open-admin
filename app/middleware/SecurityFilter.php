<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Web/API 安全攻击检测拦截中间件
 *
 * 检测并拦截: XSS、SQL注入、路径遍历、命令注入、跨站请求
 * 全局执行，在 Cors 之后、RateLimit 之前
 */
class SecurityFilter implements MiddlewareInterface
{
    /** 拦截时返回的 HTTP 状态码 */
    private const BLOCK_CODE = 403;

    /**
     * 各类攻击特征模式
     * 键为攻击类别，值为正则表达式数组
     */
    private const PATTERNS = [
        'XSS' => [
            // 完整 script 标签
            '/<\s*\/?\s*s\s*c\s*r\s*i\s*p\s*t\b/i',
            // 内联事件处理器
            '/\bon\w+\s*=\s*[\"\']?\s*(?:javascript|vbscript):/i',
            // javascript/vbscript 协议
            '/(?:javascript|vbscript)\s*:\s*(?:[^\s]*\s*)?(?:eval|alert|prompt|confirm|document\.cookie|location\s*=)/i',
            // data:text/html 协议注入
            '/data\s*:\s*text\s*\/\s*html\s*(?:;base64)?\s*,/i',
            // 表达式注入
            '/\{\{.*?\}\}/',
        ],
        'SQL注入' => [
            // UNION SELECT 语句
            '/\bUNION\s+(?:ALL\s+)?SELECT\b/i',
            // 经典 OR 注入
            '/(?:[\"\']\s*OR\s+[\"\']?\s*\d+\s*=\s*\d+|[\"\']\s*OR\s+[\"\']?1[\"\']?\s*=\s*[\"\']?1)/i',
            // DROP/ALTER/TRUNCATE 语句
            '/\b(?:DROP|ALTER|TRUNCATE)\s+(?:TABLE|DATABASE|INDEX|VIEW)\b/i',
            // 系统存储过程
            '/\b(?:xp_cmdshell|sp_executesql|sp_addsrvrolemember)\b/i',
            // 系统表探测
            '/\b(?:INFORMATION_SCHEMA|sys\.(?:tables|columns|databases)|pg_class|sqlite_master|mysql\.(?:user|db))\b/i',
            // 注释符注入常见的绕过
            '/(?:[\"\'])\s*(?:--|#)\s*[\"\']?\s*(?:OR|AND|SELECT|INSERT|UPDATE|DELETE|DROP)/i',
        ],
        '路径遍历' => [
            // 目录回溯
            '/(?:\.\.\/|\.\.\\\){2,}/',
            // 敏感系统文件
            '/\/(?:etc\/(?:passwd|shadow|hosts)|proc\/self|boot\.ini|win\.ini|WEB-INF|\.env|\.git\/)/i',
            // 空字节注入
            '/%00/',
        ],
        '命令注入' => [
            // 管道 + 命令
            '/[;|&]\s*(?:ls|cat|rm|wget|curl|nc|bash|sh|cmd|powershell|python|perl)\b/i',
            // 反引号命令替换
            '/`[^`]*\b(?:cat|ls|id|whoami|pwd|rm|wget|curl)\b[^`]*`/',
            // $() 命令替换
            '/\$\(\s*(?:cat|ls|id|whoami|rm|wget|curl)\b/i',
            // 常见远程下载攻击
            '/(?:wget|curl)\s+.*(?:\b-o\b|\b-O\b|pipe|bash|python).*\bhttps?:\/\//i',
        ],
        '恶意文件上传' => [
            // PHP/可执行文件扩展名伪装成图片
            '/\.(?:php\d?|phtml|phar|cgi|pl|py|jsp|asp)x?\.(?:png|jpg|gif|pdf)/i',
            // 双扩展名绕过
            '/\.php\s*$/m',
        ],
    ];

    public function process(Request $request, callable $handler): Response
    {
        // 收集待检测数据
        $inputs = $this->collectInputs($request);

        foreach ($inputs as $source => $values) {
            if (!is_array($values) && !is_string($values)) {
                continue;
            }

            if (is_string($values)) {
                $values = [$values];
            }

            foreach ($values as $key => $value) {
                if (!is_string($value) || empty($value)) {
                    continue;
                }

                $blocked = $this->scan($value);
                if ($blocked !== null) {
                    // 记录拦截日志
                    $this->logBlock($request, $blocked, (string) $key, $source, substr($value, 0, 200));
                    return response('<h1>403 Forbidden</h1>', self::BLOCK_CODE);
                }
            }
        }

        // CSRF 检查: 浏览器发起的写操作需验证 Origin/Referer
        $csrfError = $this->checkCsrf($request);
        if ($csrfError) {
            return response('<h1>403 Forbidden</h1>', self::BLOCK_CODE);
        }

        return $handler($request);
    }

    /**
     * 收集所有用户可控的输入数据
     */
    private function collectInputs(Request $request): array
    {
        return [
            'path'  => $request->path(),
            'query' => $request->queryString(),
            'body'  => $request->all(),
            'headers.Referer'   => $request->header('Referer', ''),
            'headers.User-Agent' => $request->header('User-Agent', ''),
            'headers.Cookie'    => $request->header('Cookie', ''),
            'headers.X-Forwarded-For' => $request->header('X-Forwarded-For', ''),
        ];
    }

    /**
     * 扫描单个字符串，返回命中类别名或 null
     */
    private function scan(string $value): ?string
    {
        foreach (self::PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $value) === 1) {
                    return $category;
                }
            }
        }
        return null;
    }

    /**
     * CSRF 检查 — 浏览器写操作验证 Origin/Referer 与 Host 一致
     */
    private function checkCsrf(Request $request): bool
    {
        $method = $request->method();
        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            return false; // GET 无需 CSRF 检查
        }

        $host = $request->host(true); // 含端口
        $origin = $request->header('Origin', '');
        $referer = $request->header('Referer', '');

        // 无 Origin 且无 Referer — 可能是原生 App 或非浏览器客户端，放行
        if ($origin === '' && $referer === '') {
            return false;
        }

        // 检查 Origin
        if ($origin !== '') {
            $originHost = parse_url($origin, PHP_URL_HOST);
            if ($originHost && !str_ends_with($originHost, ltrim(parse_url('http://' . $host, PHP_URL_HOST) ?: $host, 'www.'))) {
                // 不匹配也不一定是攻击（www vs non-www），放宽处理
                // 仅当 Origin 是完全不同的域名时才拦截
                if (!str_contains($originHost, '.' . ltrim(parse_url('http://' . $host, PHP_URL_HOST) ?: $host, 'www.'))
                    && $originHost !== ltrim(parse_url('http://' . $host, PHP_URL_HOST) ?: $host, 'www.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 记录被拦截的攻击请求
     */
    private function logBlock(Request $request, string $category, string $field, string $source, string $payload): void
    {
        // 写入 webman 日志
        $logData = sprintf(
            "[SECURITY] %s attack blocked | IP: %s | Path: %s | Field: %s | Source: %s | Payload: %s",
            $category,
            $request->getRealIp(),
            $request->path(),
            "{$source}.{$field}",
            $source,
            $payload
        );

        // 安全日志独立于业务日志
        @file_put_contents(
            runtime_path() . '/logs/security.log',
            date('Y-m-d H:i:s') . ' ' . $logData . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
