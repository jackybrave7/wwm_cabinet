<?php
declare(strict_types=1);

namespace Wwm\Services;

final class EmailWebhookCatalog
{
    /**
     * @return array{url: string, token_label: string, endpoint: string}|null
     */
    public static function forTemplate(string $templateId): ?array
    {
        $spec = self::spec($templateId);
        if ($spec === null) {
            return null;
        }

        $webhooks = wwm_config()['webhooks'] ?? [];
        $token = trim((string)($webhooks[$spec['token_key']] ?? ''));
        if ($token === '') {
            return null;
        }

        $query = array_merge(
            ['token' => $token],
            $spec['params']
        );

        return [
            'url' => rtrim(wwm_base_url(), '/') . $spec['path'] . '?' . self::buildAvoQuery($query),
            'token_label' => $spec['token_label'],
            'endpoint' => $spec['path'],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private static function buildAvoQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            $encodedKey = rawurlencode((string)$key);
            if (self::isAvoMacro($value)) {
                $parts[] = $encodedKey . '=' . $value;
                continue;
            }
            $parts[] = $encodedKey . '=' . rawurlencode((string)$value);
        }

        return implode('&', $parts);
    }

    private static function isAvoMacro(string $value): bool
    {
        return preg_match('/^\{[a-z0-9_]+\}$/i', $value) === 1;
    }

    /**
     * @return array{path: string, token_key: string, token_label: string, params: array<string, string>}|null
     */
    private static function spec(string $templateId): ?array
    {
        return match ($templateId) {
            'demo' => [
                'path' => '/api/demo',
                'token_key' => 'demo_token',
                'token_label' => 'WWM_WEBHOOK_DEMO_TOKEN',
                'params' => [
                    'email' => '{email}',
                    'name' => '{name}',
                    'course' => 'elke-en',
                    'id_contact' => '{id_contact}',
                ],
            ],
            'paid' => [
                'path' => '/api/payment',
                'token_key' => 'payment_token',
                'token_label' => 'WWM_WEBHOOK_PAYMENT_TOKEN',
                'params' => [
                    'email' => '{email}',
                    'name' => '{name}',
                    'id_goods' => '188',
                    'id_contact' => '{id_contact}',
                    'id_account' => '{id_account}',
                ],
            ],
            'reminder_demo_no_login', 'reminder_demo_no_lesson', 'reminder_demo_expiring',
            'sale_demo_discount_24h', 'sale_demo_discount_3h' => [
                'path' => '/api/mail',
                'token_key' => 'demo_token',
                'token_label' => 'WWM_WEBHOOK_DEMO_TOKEN',
                'params' => [
                    'template' => $templateId,
                    'email' => '{email}',
                    'name' => '{name}',
                    'course' => 'elke-en',
                    'id_contact' => '{id_contact}',
                ],
            ],
            default => null,
        };
    }
}
