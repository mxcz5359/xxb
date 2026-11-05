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
    $text = strip_tags($html);
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

if (!isset($payload['url']) || !is_string($payload['url']) || !filter_var($payload['url'], FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid URL provided');
}

$url = $payload['url'];
$parts = parse_url($url);
$scheme = strtolower($parts['scheme'] ?? '');
$host = $parts['host'] ?? '';

if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
    http_response_code(400);
    exit('Only http/https URLs are allowed');
}

// 禁止访问内网/保留地址，防止 SSRF
$hostForLookup = trim($host, '[]');
$ips = filter_var($hostForLookup, FILTER_VALIDATE_IP) ? [$hostForLookup] : (gethostbynamel($hostForLookup) ?: []);
if (empty($ips)) {
    http_response_code(400);
    exit('Unable to resolve host');
}
foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        http_response_code(403);
        exit('Target address is not allowed');
    }
}
$port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// 固定解析结果，防止 DNS rebinding；不跟随跳转，避免跳转到内网地址
curl_setopt($ch, CURLOPT_RESOLVE, ["{$hostForLookup}:{$port}:{$ips[0]}"]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_MAXFILESIZE, 5 * 1024 * 1024);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; ChatBot/1.0; +http://yourbot.com)'); // 设置User-Agent

$html = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

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

$textContent = html_to_text($html);

header('Content-Type: application/json');
echo json_encode([
    'url' => $url,
    'content' => $textContent,
    'content_length' => mb_strlen($textContent)
]);