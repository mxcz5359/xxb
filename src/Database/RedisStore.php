<?php

declare(strict_types=1);

namespace ChatBot\Database;

use ChatBot\Core\Logger;
use Redis;
use RedisException;

/**
 * Redis 键值存储驱动
 * 需要 PHP 的 `redis` 扩展。
 */
class RedisStore implements KVStoreInterface
{
    private const LOCK_TTL_MS = 10000;
    private const LOCK_WAIT_SECONDS = 10;

    private ?Redis $redis;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->logger = $logger;
        
        if (!class_exists('Redis')) {
            $this->logger?->error('RedisStore: PHP Redis 扩展未安装或未启用。');
            throw new \RuntimeException("PHP Redis 扩展未安装或未启用。");
        }

        $this->redis = new Redis();
        try {
            // 使用 pconnect 实现持久连接，提高性能
            $this->redis->pconnect($config['host'], $config['port']);
            
            if (!empty($config['password'])) {
                $this->redis->auth($config['password']);
            }
            if (isset($config['database'])) {
                $this->redis->select($config['database']);
            }
        } catch (RedisException $e) {
            $this->logger?->error('RedisStore: 无法连接到 Redis 服务器', ['error' => $e->getMessage()]);
            $this->redis = null; // 连接失败，将 redis 实例置为 null
        }
    }

    public function get(string $key)
    {
        if (!$this->redis) return null;

        $value = $this->redis->get($key);
        return $value ? json_decode($value, true) : null;
    }

    public function set(string $key, $value): bool
    {
        if (!$this->redis) return false;

        $jsonValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->redis->set($key, $jsonValue);
    }

    public function update(string $key, callable $callback)
    {
        if (!$this->redis) {
            throw new \RuntimeException('RedisStore: Redis 未连接');
        }

        // 互斥锁：SET NX PX 加锁，超时自动释放，避免进程崩溃导致死锁
        $lockKey = "lock:{$key}";
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        while (!$this->redis->set($lockKey, $token, ['nx', 'px' => self::LOCK_TTL_MS])) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException("RedisStore: 获取锁超时: {$key}");
            }
            usleep(random_int(5000, 20000));
        }

        try {
            $raw = $this->redis->get($key);
            $newValue = $callback($raw ? json_decode($raw, true) : null);
            $this->redis->set($key, json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $newValue;
        } finally {
            // 只释放自己持有的锁
            $this->redis->eval(
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                [$lockKey, $token],
                1
            );
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->redis) return false;

        return $this->redis->del($key) > 0;
    }

    public function exists(string $key): bool
    {
        if (!$this->redis) return false;

        return (bool)$this->redis->exists($key);
    }
}