<?php

declare(strict_types=1);

namespace ChatBot\Database;

use ChatBot\Core\Logger;

/**
 * 文件/JSON 键值存储驱动
 * 将数据作为 JSON 文件存储在磁盘上。
 */
class FileStore implements KVStoreInterface
{
    private string $storagePath;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->storagePath = $config['storage_path'] ?? __DIR__ . '/../../storage';
        $this->logger = $logger;
        $this->ensureStorageDirectoryExists();
    }

    public function setStoragePath(string $path): void
    {
        $this->storagePath = $path;
        $this->ensureStorageDirectoryExists();
    }

    public function get(string $key)
    {
        return $this->withLock($key, LOCK_SH, fn() => $this->read($key));
    }

    public function set(string $key, $value): bool
    {
        return $this->withLock($key, LOCK_EX, fn() => $this->write($key, $value));
    }

    public function update(string $key, callable $callback)
    {
        return $this->withLock($key, LOCK_EX, function () use ($key, $callback) {
            $newValue = $callback($this->read($key));
            if (!$this->write($key, $newValue)) {
                throw new \RuntimeException("FileStore: 写入失败: {$key}");
            }
            return $newValue;
        });
    }

    public function delete(string $key): bool
    {
        return $this->withLock($key, LOCK_EX, function () use ($key) {
            $filePath = $this->getFilePath($key);
            if (file_exists($filePath)) {
                return unlink($filePath);
            }
            return true; // 如果文件不存在，也视为成功
        });
    }

    public function exists(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        return file_exists($filePath);
    }

    private function read(string $key)
    {
        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        return $content ? json_decode($content, true) : null;
    }

    /**
     * 先写临时文件再 rename，保证读者不会读到写了一半的文件
     */
    private function write(string $key, $value): bool
    {
        $jsonValue = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonValue === false) {
            $this->logger?->error('FileStore: JSON 编码失败', ['key' => $key, 'error' => json_last_error_msg()]);
            return false;
        }

        $filePath = $this->getFilePath($key);
        $tmpPath = $filePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmpPath, $jsonValue) === false || !rename($tmpPath, $filePath)) {
            @unlink($tmpPath);
            $this->logger?->error('FileStore: 写入文件失败', ['key' => $key, 'path' => $filePath]);
            return false;
        }
        return true;
    }

    /**
     * 在键对应的锁文件上加锁后执行回调
     */
    private function withLock(string $key, int $lockType, callable $fn)
    {
        $lockPath = $this->getFilePath($key) . '.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException("FileStore: 无法打开锁文件: {$lockPath}");
        }

        try {
            if (!flock($handle, $lockType)) {
                throw new \RuntimeException("FileStore: 无法获取文件锁: {$lockPath}");
            }
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 根据键名生成一个安全的文件路径
     * @param string $key
     * @return string
     */
    private function getFilePath(string $key): string
    {
        // 使用 hash 来避免非法字符和过长的文件名
        $safeFileName = hash('sha256', $key) . '.json';
        return $this->storagePath . '/' . $safeFileName;
    }

    /**
     * 确保存储目录存在
     */
    private function ensureStorageDirectoryExists(): void
    {
        if (!is_dir($this->storagePath)) {
            if (!mkdir($this->storagePath, 0775, true)) {
                $this->logger?->error('FileStore: 无法创建存储目录', ['path' => $this->storagePath]);
                // 如果无法创建目录，抛出异常可能更合适，因为这是个致命错误
                throw new \RuntimeException("无法创建存储目录: {$this->storagePath}");
            }
        }
    }
}