<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

/**
 * Idempotency / cross-source keys for paid grants (e.g. tilda:order:…, tilda:payment:…).
 * Used so Tilda and AVO webhooks for the same purchase grant access and send email once.
 */
final class PaymentExternalKey
{
    private const KEY_PATTERN = '/tilda:(?:order|payment|tran|fp):[^\s,;]+/u';

    /**
     * @param list<string> $keys
     */
    public static function anyExist(PDO $pdo, array $keys): bool
    {
        $keys = self::normalizeList($keys);
        if ($keys === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare(
            "SELECT 1 FROM payment_external_keys WHERE external_key IN ($placeholders) LIMIT 1"
        );
        $stmt->execute($keys);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param list<string> $keys
     */
    public static function storeMany(
        PDO $pdo,
        array $keys,
        int $userId,
        string $courseSlug,
        string $source = 'tilda'
    ): void {
        $keys = self::normalizeList($keys);
        if ($keys === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO payment_external_keys
                (external_key, user_id, course_slug, source, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $now = gmdate('c');
        $source = mb_substr(trim($source), 0, 32);
        if ($source === '') {
            $source = 'tilda';
        }

        foreach ($keys as $key) {
            $stmt->execute([$key, $userId, $courseSlug, $source, $now]);
        }
    }

    /**
     * Collect tilda:* keys from a webhook payload (explicit fields + AVO invoice comment).
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function collectFromPayload(array $payload): array
    {
        $keys = [];
        $add = static function (string $key) use (&$keys): void {
            $key = trim($key);
            if ($key === '' || !str_starts_with($key, 'tilda:')) {
                return;
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        };

        $idem = trim((string)($payload['idempotency_key'] ?? ''));
        if ($idem !== '') {
            $add($idem);
        }

        if (isset($payload['external_keys']) && is_array($payload['external_keys'])) {
            foreach ($payload['external_keys'] as $raw) {
                if (is_string($raw) || is_int($raw) || is_float($raw)) {
                    $add((string)$raw);
                }
            }
        }

        $account = trim((string)($payload['id_account'] ?? $payload['source_ref'] ?? ''));
        if (str_starts_with($account, 'tilda:')) {
            $add($account);
        }

        $orderId = trim((string)($payload['order_id'] ?? ''));
        if ($orderId !== '') {
            $add('tilda:order:' . $orderId);
        }

        $paymentId = trim((string)($payload['payment_id'] ?? ''));
        if ($paymentId !== '') {
            $add('tilda:payment:' . $paymentId);
        }

        foreach (['account_comment', 'comment', 'notes', 'description', 'primecanie'] as $field) {
            $text = trim((string)($payload[$field] ?? ''));
            if ($text === '') {
                continue;
            }
            if (preg_match_all(self::KEY_PATTERN, $text, $matches) && isset($matches[0])) {
                foreach ($matches[0] as $match) {
                    $add((string)$match);
                }
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function normalizeList(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $key = trim((string)$key);
            if ($key === '' || !str_starts_with($key, 'tilda:')) {
                continue;
            }
            if (!in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
