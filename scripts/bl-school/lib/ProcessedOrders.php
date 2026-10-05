<?php
declare(strict_types=1);

/**
 * Файловое хранилище обработанных заказов (идемпотентность).
 *
 * Поддерживает ранний claim под flock: параллельные webhook'и с одним
 * платежом не создают два счёта в АВО (гонка «has → create → put»).
 */
final class ProcessedOrders
{
    private string $file;

    /** Максимальный возраст «processing» записи, сек. После — можно перехватить. */
    private int $staleProcessingSeconds;

    public function __construct(string $file, int $staleProcessingSeconds = 120)
    {
        $this->file = $file;
        $this->staleProcessingSeconds = max(30, $staleProcessingSeconds);
    }

    public function has(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        $record = $this->get($key);
        return $record !== null && $this->isTerminal($record);
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $data = $this->withLock(function (array $data) use ($key): array {
            return ['__result' => $data[$key] ?? null, '__data' => $data];
        });
        $result = $data['__result'] ?? null;
        return is_array($result) ? $result : null;
    }

    /**
     * Найти первую завершённую запись среди ключей.
     *
     * @param list<string> $keys
     * @return array<string, mixed>|null
     */
    public function findCompleted(array $keys): ?array
    {
        foreach ($this->normalizeKeys($keys) as $key) {
            $record = $this->get($key);
            if ($record !== null && $this->isTerminal($record)) {
                return $record;
            }
        }
        return null;
    }

    /**
     * Атомарно занять ключи перед созданием счёта в АВО.
     *
     * @param list<string> $keys
     * @param array<string, mixed> $meta
     * @return array{
     *   status: 'claimed'|'exists'|'busy',
     *   record: array<string, mixed>|null,
     *   key: string
     * }
     */
    public function claim(array $keys, array $meta = []): array
    {
        $keys = $this->normalizeKeys($keys);
        if ($keys === []) {
            return ['status' => 'claimed', 'record' => null, 'key' => ''];
        }

        $now = time();
        $primary = $keys[0];

        $out = $this->withLock(function (array $data) use ($keys, $meta, $now, $primary): array {
            foreach ($keys as $key) {
                if (!isset($data[$key]) || !is_array($data[$key])) {
                    continue;
                }
                $existing = $data[$key];
                if ($this->isTerminal($existing)) {
                    return [
                        '__result' => [
                            'status' => 'exists',
                            'record' => $existing,
                            'key' => $key,
                        ],
                        '__data' => $data,
                    ];
                }
                if ($this->isActiveProcessing($existing, $now)) {
                    return [
                        '__result' => [
                            'status' => 'busy',
                            'record' => $existing,
                            'key' => $key,
                        ],
                        '__data' => $data,
                    ];
                }
            }

            $record = array_merge($meta, [
                'status' => 'processing',
                'claimed_at' => date('c'),
                'claimed_ts' => $now,
                'keys' => $keys,
            ]);

            foreach ($keys as $key) {
                $data[$key] = $record;
            }

            return [
                '__result' => [
                    'status' => 'claimed',
                    'record' => $record,
                    'key' => $primary,
                ],
                '__data' => $data,
            ];
        }, true);

        /** @var array{status: string, record: array<string, mixed>|null, key: string} $result */
        $result = $out['__result'];
        return $result;
    }

    /**
     * Пометить ключи успешно обработанными (после создания/оплаты счёта).
     *
     * @param list<string> $keys
     * @param array<string, mixed> $meta
     */
    public function complete(array $keys, array $meta): void
    {
        $keys = $this->normalizeKeys($keys);
        if ($keys === []) {
            return;
        }

        $this->withLock(function (array $data) use ($keys, $meta): array {
            $record = array_merge($meta, [
                'status' => 'done',
                'saved_at' => date('c'),
                'keys' => $keys,
            ]);
            foreach ($keys as $key) {
                $prev = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
                $data[$key] = array_merge($prev, $record);
            }
            return ['__data' => $data];
        }, true);
    }

    /**
     * Снять claim при ошибке АВО, чтобы Tilda-ретрай мог повторить обработку.
     *
     * @param list<string> $keys
     */
    public function release(array $keys): void
    {
        $keys = $this->normalizeKeys($keys);
        if ($keys === []) {
            return;
        }

        $this->withLock(function (array $data) use ($keys): array {
            foreach ($keys as $key) {
                if (!isset($data[$key]) || !is_array($data[$key])) {
                    continue;
                }
                if (($data[$key]['status'] ?? '') === 'processing') {
                    unset($data[$key]);
                }
            }
            return ['__data' => $data];
        }, true);
    }

    /** @param array<string, mixed> $meta */
    public function put(string $key, array $meta): void
    {
        if ($key === '') {
            return;
        }
        $this->complete([$key], $meta);
    }

    /**
     * @param callable(array<string, array<string, mixed>>): array $fn
     * @return array<string, mixed>
     */
    private function withLock(callable $fn, bool $write = false): array
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $lockPath = $this->file . '.lock';
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) {
            // fallback без lock — лучше слабая идемпотентность, чем полный отказ
            $data = $this->readUnlocked();
            $result = $fn($data);
            if ($write && isset($result['__data']) && is_array($result['__data'])) {
                $this->writeUnlocked($result['__data']);
            }
            return $result;
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                $data = $this->readUnlocked();
                return $fn($data);
            }

            $data = $this->readUnlocked();
            $result = $fn($data);
            if ($write && isset($result['__data']) && is_array($result['__data'])) {
                /** @var array<string, array<string, mixed>> $newData */
                $newData = $result['__data'];
                $this->writeUnlocked($newData);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function readUnlocked(): array
    {
        if (!is_readable($this->file)) {
            return [];
        }
        $raw = file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, array<string, mixed>> $data */
    private function writeUnlocked(array $data): void
    {
        if (count($data) > 5000) {
            $data = array_slice($data, -4000, null, true);
        }

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }

        $tmp = $this->file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @file_put_contents($this->file, $json, LOCK_EX);
            return;
        }
        if (!@rename($tmp, $this->file)) {
            @file_put_contents($this->file, $json, LOCK_EX);
            @unlink($tmp);
        }
    }

    /** @param array<string, mixed> $record */
    private function isTerminal(array $record): bool
    {
        $status = (string)($record['status'] ?? '');
        if ($status === 'done' || $status === 'exists') {
            return (int)($record['id_account'] ?? 0) > 0;
        }
        // Обратная совместимость: старые записи без status, но с id_account
        if ($status === '' && !empty($record['id_account'])) {
            return true;
        }
        return false;
    }

    /** @param array<string, mixed> $record */
    private function isActiveProcessing(array $record, int $now): bool
    {
        if (($record['status'] ?? '') !== 'processing') {
            return false;
        }
        $claimedTs = (int)($record['claimed_ts'] ?? 0);
        if ($claimedTs <= 0) {
            $claimedAt = (string)($record['claimed_at'] ?? '');
            $claimedTs = $claimedAt !== '' ? (int)strtotime($claimedAt) : 0;
        }
        if ($claimedTs <= 0) {
            return true;
        }
        return ($now - $claimedTs) < $this->staleProcessingSeconds;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function normalizeKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $key = trim((string)$key);
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = $key;
        }
        return array_values($out);
    }
}
