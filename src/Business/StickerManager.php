<?php

declare(strict_types=1);

namespace ChatBot\Business;

use ChatBot\Core\Logger;
use ChatBot\Database\KVStoreInterface;
use ChatBot\Services\TelegramAPI;

/**
 * 表情包 (贴纸) 管理
 * 贴纸来源：配置的贴纸包 (stickers.sets) + 群友在群里发过的贴纸 (stickers.learn_from_chat)。
 * 回复模型按 emoji 选择情绪，这里负责找到对应的贴纸 file_id。
 */
class StickerManager
{
    private const SET_CACHE_TTL = 86400;
    private const SET_RETRY_AFTER = 600;

    private KVStoreInterface $db;
    private TelegramAPI $tg;
    private array $config;
    private ?Logger $logger;

    public function __construct(KVStoreInterface $db, TelegramAPI $tg, array $config, ?Logger $logger = null)
    {
        $this->db = $db;
        $this->tg = $tg;
        $this->config = $config['stickers'] ?? [];
        $this->logger = $logger;
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    /**
     * 记录群友发送的贴纸，供 bot 之后使用
     */
    public function remember(int $chatId, array $sticker): void
    {
        if (!$this->isEnabled() || empty($this->config['learn_from_chat']) || empty($sticker['file_id'])) {
            return;
        }

        $uniqueId = $sticker['file_unique_id'] ?? $sticker['file_id'];
        $entry = [
            'file_id' => $sticker['file_id'],
            'file_unique_id' => $uniqueId,
            'emoji' => $sticker['emoji'] ?? '',
        ];
        $max = max(1, (int)($this->config['max_learned'] ?? 200));

        try {
            $this->db->update($this->getChatKey($chatId), static function ($stickers) use ($entry, $uniqueId, $max) {
                $stickers = array_values(array_filter(
                    is_array($stickers) ? $stickers : [],
                    static fn($s) => ($s['file_unique_id'] ?? '') !== $uniqueId
                ));
                $stickers[] = $entry;
                return array_slice($stickers, -$max);
            });
        } catch (\Throwable $e) {
            $this->logger?->error('StickerManager: 记录贴纸失败', ['error' => $e->getMessage()], $chatId);
        }
    }

    /**
     * 当前群可用的 emoji 列表 (按贴纸数量从多到少)
     *
     * @return string[]
     */
    public function getEmojis(int $chatId, int $limit = 40): array
    {
        $counts = [];
        foreach ($this->getStickers($chatId) as $sticker) {
            $emoji = self::normalize($sticker['emoji'] ?? '');
            if ($emoji !== '') {
                $counts[$emoji] = ($counts[$emoji] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_slice(array_map('strval', array_keys($counts)), 0, $limit);
    }

    /**
     * 随机挑一个与 emoji 匹配的贴纸
     */
    public function pick(int $chatId, string $emoji): ?string
    {
        $emoji = self::normalize($emoji);
        if ($emoji === '') {
            return null;
        }

        $candidates = array_values(array_filter(
            $this->getStickers($chatId),
            static fn($s) => self::normalize($s['emoji'] ?? '') === $emoji
        ));
        if (!$candidates) {
            $this->logger?->debug('StickerManager: 没有与 emoji 匹配的贴纸', ['emoji' => $emoji], $chatId);
            return null;
        }

        return $candidates[array_rand($candidates)]['file_id'];
    }

    private function getStickers(int $chatId): array
    {
        $all = [];
        foreach ($this->config['sets'] ?? [] as $setName) {
            $all = array_merge($all, $this->loadSet((string)$setName));
        }
        $learned = $this->db->get($this->getChatKey($chatId));
        if (is_array($learned)) {
            $all = array_merge($all, $learned);
        }

        // 贴纸包和群友贴纸可能有重复，按 file_unique_id 去重
        $stickers = [];
        foreach ($all as $sticker) {
            if (!empty($sticker['file_id'])) {
                $stickers[$sticker['file_unique_id'] ?? $sticker['file_id']] = $sticker;
            }
        }
        return array_values($stickers);
    }

    /**
     * 读取贴纸包 (缓存一天，接口失败时继续使用旧缓存)
     */
    private function loadSet(string $name): array
    {
        $key = "sticker_set_{$name}";
        $cached = $this->db->get($key);
        if (is_array($cached) && time() < (int)($cached['expires_at'] ?? 0)) {
            return $cached['stickers'] ?? [];
        }

        $response = $this->tg->getStickerSet($name);
        if (!isset($response['result']['stickers']) || !is_array($response['result']['stickers'])) {
            // 失败时沿用旧缓存，并且 10 分钟内不再重试，避免每次回复都卡在这个请求上
            $this->logger?->warning('StickerManager: 获取贴纸包失败，请检查 stickers.sets 里的名字', ['set' => $name]);
            $stickers = is_array($cached) ? ($cached['stickers'] ?? []) : [];
            $this->db->set($key, ['expires_at' => time() + self::SET_RETRY_AFTER, 'stickers' => $stickers]);
            return $stickers;
        }
        if (($response['result']['sticker_type'] ?? 'regular') !== 'regular') {
            $this->logger?->warning('StickerManager: 只支持普通贴纸包，不支持自定义 emoji 包', ['set' => $name]);
            $this->db->set($key, ['expires_at' => time() + self::SET_CACHE_TTL, 'stickers' => []]);
            return [];
        }

        $stickers = array_map(static fn($s) => [
            'file_id' => $s['file_id'],
            'file_unique_id' => $s['file_unique_id'] ?? $s['file_id'],
            'emoji' => $s['emoji'] ?? '',
        ], $response['result']['stickers']);
        $this->db->set($key, ['expires_at' => time() + self::SET_CACHE_TTL, 'stickers' => $stickers]);
        return $stickers;
    }

    private function getChatKey(int $chatId): string
    {
        return "stickers_chat_{$chatId}";
    }

    private static function normalize(string $emoji): string
    {
        return trim(str_replace("\u{FE0F}", '', $emoji));
    }
}
