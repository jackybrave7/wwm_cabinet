<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Normalizes AVO outbound webhook bodies (serialized PHP, form POST, JSON, GET).
 */
final class AvoWebhookPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($method === 'POST' && str_contains($contentType, 'application/json')) {
            $raw = self::readRawBody();
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return self::normalize($decoded);
            }
        }

        if ($_POST !== []) {
            return self::normalize($_POST);
        }

        if ($method === 'POST') {
            $raw = self::readRawBody();
            if (is_string($raw) && $raw !== '') {
                $decoded = @unserialize($raw, ['allowed_classes' => false]);
                if (is_array($decoded)) {
                    return self::normalize(self::flattenAvoRow($decoded));
                }
            }
        }

        return self::normalize($_GET);
    }

    private static function readRawBody(): string
    {
        $raw = file_get_contents('php://input');
        return is_string($raw) ? $raw : '';
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function flattenAvoRow(array $data): array
    {
        if (isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalize(array $payload): array
    {
        if (!isset($payload['id_goods']) && isset($payload['lines']) && is_array($payload['lines'])) {
            foreach ($payload['lines'] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $idGoods = (int)($line['id_goods'] ?? 0);
                if ($idGoods > 0) {
                    $payload['id_goods'] = $idGoods;
                    break;
                }
            }
        }

        if (!isset($payload['source_ref']) && isset($payload['id_account'])) {
            $payload['source_ref'] = (string)$payload['id_account'];
        }

        return self::fillMissingFromQuery($payload);
    }

    /**
     * Product-level AVO URLs can carry id_goods / course in the query string
     * even when the POST body omits them.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function fillMissingFromQuery(array $payload): array
    {
        if ($_GET === []) {
            return $payload;
        }

        foreach (['id_goods', 'course', 'email', 'name', 'id_contact', 'id_account', 'id_account_status'] as $key) {
            if (!self::isBlank($payload[$key] ?? null) || !isset($_GET[$key])) {
                continue;
            }
            $value = $_GET[$key];
            if (is_scalar($value) && (string)$value !== '') {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    private static function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value === 0;
        }

        return false;
    }

    public static function isPaidAccountStatus(array $payload): bool
    {
        if (!isset($payload['id_account_status'])) {
            return true;
        }

        return (int)$payload['id_account_status'] === 5;
    }
}
