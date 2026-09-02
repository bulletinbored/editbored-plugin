<?php
/**
 * LinkCardCache — File-based cache for link card metadata.
 * Caches title/description fetched from remote URLs to avoid repeated HTTP calls.
 */

class LinkCardCache {
    private string $cachePath;
    private int $ttl;

    public function __construct(string $cacheDir = null, int $ttl = 86400) {
        $cacheDir = $cacheDir ?? dirname(__DIR__, 2) . '/data/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $this->cachePath = rtrim($cacheDir, '/') . '/link-card-cache.json';
        $this->ttl = $ttl;
    }

    public function get(string $url): ?array {
        $cache = $this->load();
        $key = $this->key($url);
        if (!isset($cache[$key])) {
            return null;
        }
        if ((time() - (int)($cache[$key]['timestamp'] ?? 0)) > $this->ttl) {
            unset($cache[$key]);
            $this->save($cache);
            return null;
        }
        return $cache[$key]['data'] ?? null;
    }

    public function set(string $url, array $data): void {
        $cache = $this->load();
        $key = $this->key($url);
        $cache[$key] = [
            'data' => $data,
            'timestamp' => time(),
        ];
        $this->save($cache);
    }

    public function flush(): int {
        if (!file_exists($this->cachePath)) {
            return 0;
        }
        $count = count($this->load());
        @unlink($this->cachePath);
        return $count;
    }

    public function purgeExpired(): int {
        $cache = $this->load();
        $before = count($cache);
        $now = time();
        foreach ($cache as $key => $entry) {
            if (($now - (int)($entry['timestamp'] ?? 0)) > $this->ttl) {
                unset($cache[$key]);
            }
        }
        $removed = $before - count($cache);
        if ($removed > 0) {
            $this->save($cache);
        }
        return $removed;
    }

    private function key(string $url): string {
        return md5($url);
    }

    private function load(): array {
        if (!file_exists($this->cachePath)) {
            return [];
        }
        $data = file_get_contents($this->cachePath);
        if ($data === false || $data === '') {
            return [];
        }
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function save(array $cache): void {
        file_put_contents($this->cachePath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
