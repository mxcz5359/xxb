<?php

declare(strict_types=1);

namespace ChatBot\Services;

use ChatBot\Core\Logger;

/**
 * 图片生成服务
 * 调用 OpenAI 兼容的 /images/generations 接口 (OpenAI、SiliconFlow 及大多数中转均可用)。
 */
class ImageService
{
    private array $config;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->config = $config['tools']['image_generation'] ?? [];
        $this->logger = $logger;
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']) && !empty($this->config['endpoint']) && !empty($this->config['model']);
    }

    /**
     * 根据提示词生成一张图片
     *
     * @return array{content: string, mime: string}|null
     */
    public function generate(string $prompt): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $payload = ['model' => $this->config['model'], 'prompt' => $prompt, 'n' => 1];
        if (!empty($this->config['size'])) {
            $payload['size'] = $this->config['size'];
        }
        $payload = array_merge($payload, $this->config['extra_params'] ?? []);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->config['endpoint']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($this->config['api_key'] ?? ''),
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)($this->config['timeout'] ?? 120));
        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $response = is_string($responseJson) ? json_decode($responseJson, true) : null;
        // OpenAI 格式: data[0].url / data[0].b64_json；SiliconFlow 格式: images[0].url
        $item = $response['data'][0] ?? $response['images'][0] ?? null;
        if ($httpCode !== 200 || !is_array($item)) {
            $this->logger?->error('ImageService: 图片生成失败', [
                'http_code' => $httpCode,
                'error' => $error,
                'response' => is_string($responseJson) ? mb_substr($responseJson, 0, 1000) : null,
            ]);
            return null;
        }

        if (!empty($item['b64_json'])) {
            $content = base64_decode($item['b64_json'], true);
        } elseif (!empty($item['url'])) {
            $content = $this->download($item['url']);
        } else {
            $content = false;
        }

        if (!is_string($content) || $content === '') {
            $this->logger?->error('ImageService: 无法获取生成的图片内容', ['item_keys' => array_keys($item)]);
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'image/png';
        if (!str_starts_with($mime, 'image/')) {
            $this->logger?->error('ImageService: 返回的内容不是图片', ['mime' => $mime]);
            return null;
        }

        return ['content' => $content, 'mime' => $mime];
    }

    private function download(string $url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200 ? $content : false;
    }
}
