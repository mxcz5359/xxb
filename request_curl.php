<?php

// +----------------------------------------------------------------------
// | 异步 cURL Worker
// | 职责：接收URL，获取网页内容，并进行基础的文本提取
// +----------------------------------------------------------------------

declare(strict_types=1);

function html_to_text(string $html): string
{
    $html = preg_replace('/<script.*?<\/script>/is', '', $html);
    $html = preg_replace('/<style.*?<\/style>/is', '', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return mb_substr(trim($text), 0, 2000);
}

require_once __DIR__ . '/src/Core/CurlHelper.php';

$config = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (!\ChatBot\Core\CurlHelper::isValidWorkerRequest((string)($config['worker']['secret'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}

$payloadJson = file_get_contents('php://input');
$payload = json_decode($payloadJson, true);

if (!isset($payload['url']) || !is_string($payload['url'])) {
    http_response_code(400);
    exit('Invalid URL provided');
}

$url = $payload['url'];
$html = false;
$contentType = '';
// 手动跟随跳转 (最多 3 次)，每一跳都重新做内网地址校验
for ($redirects = 0; ; $redirects++) {
    $target = validate_public_url($url);
    if (is_string($target)) {
        http_response_code(403);
        echo json_encode(['error' => $target]);
        exit;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($target['resolve']) {
        // 固定解析结果，防止 DNS rebinding
        curl_setopt($ch, CURLOPT_RESOLVE, [$target['resolve']]);
    }
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_MAXFILESIZE, 5 * 1024 * 1024);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; ChatBot/1.0)');

    $html = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($html === false || $httpCode < 300 || $httpCode >= 400 || !$redirectUrl) {
        break;
    }
    if ($redirects >= 3) {
        http_response_code(502);
        echo json_encode(['error' => 'Too many redirects']);
        exit;
    }
    $url = $redirectUrl;
}

if ($html === false) {
    http_response_code(500);
    echo json_encode(['error' => "cURL Error: " . $error]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => "HTTP Error: " . $httpCode]);
    exit;
}

$textContent = html_to_text(to_utf8($html, $contentType));

header('Content-Type: application/json');
echo json_encode([
    'url' => $url,
    'content' => $textContent,
    'content_length' => mb_strlen($textContent)
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

/**
 * 校验 URL 只指向公网地址，成功返回 ['resolve' => CURLOPT_RESOLVE 条目或 null]，失败返回错误信息
 *
 * @return array{resolve: ?string}|string
 */
function validate_public_url(string $url)
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return 'Invalid URL';
    }
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = trim($parts['host'] ?? '', '[]');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return 'Only http/https URLs are allowed';
    }

    $isIp = (bool)filter_var($host, FILTER_VALIDATE_IP);
    $ips = $isIp ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        return 'Unable to resolve host';
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'Target address is not allowed';
        }
    }

    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    return ['resolve' => $isIp ? null : "{$host}:{$port}:{$ips[0]}"];
}

/**
 * 把 GBK 等非 UTF-8 网页转成 UTF-8
 */
function to_utf8(string $html, string $contentType): string
{
    if (mb_check_encoding($html, 'UTF-8')) {
        return $html;
    }
    $charset = 'GB18030';
    if (preg_match('/charset=["\']?([\w-]+)/i', $contentType . ' ' . substr($html, 0, 4096), $m)) {
        $charset = strtoupper($m[1]);
        if (in_array($charset, ['GB2312', 'GBK'], true)) {
            $charset = 'GB18030';
        }
    }
    try {
        return mb_convert_encoding($html, 'UTF-8', $charset);
    } catch (\ValueError $e) {
        return mb_convert_encoding($html, 'UTF-8', 'UTF-8');
    }
}
