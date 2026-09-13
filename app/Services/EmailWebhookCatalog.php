<?php
declare(strict_types=1);

namespace Wwm\Services;

final class EmailWebhookCatalog
{
    /**
     * @return array{url: string, token_label: string, endpoint: string, post_sample: string, includes_attribution: bool}|null
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

        $includesAttribution = !empty($spec['attribution']);

        return [
            'url' => rtrim(wwm_base_url(), '/') . $spec['path'] . '?' . self::query($query),
            'token_label' => $spec['token_label'],
            'endpoint' => $spec['path'],
            'post_sample' => $includesAttribution ? WebhookAttributionParams::postBodySample($query) : '',
            'includes_attribution' => $includesAttribution,
        ];
    }

    /**
     * @param array<string, string> $params
     */
    public static function query(array $params): string
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
     * @return array{path: string, token_key: string, token_label: string, attribution?: bool, params: array<string, string>}|null
     */
    private static function spec(string $templateId): ?array
    {
        $attribution = WebhookAttributionParams::forAvoWebhook();

        return match ($templateId) {
            'demo' => [
                'path' => '/api/demo',
                'token_key' => 'demo_token',
                'token_label' => 'WWM_WEBHOOK_DEMO_TOKEN',
                'attribution' => true,
                'params' => array_merge([
                    'email' => '{email}',
                    'name' => '{name}',
                    'course' => 'elke-en',
                    'id_contact' => '{id_contact}',
                    'id_goods' => '{id_goods}',
                ], $attribution),
            ],
            'paid' => [
                'path' => '/api/payment',
                'token_key' => 'payment_token',
                'token_label' => 'WWM_WEBHOOK_PAYMENT_TOKEN',
                'attribution' => true,
                'params' => array_merge([
                    'email' => '{email}',
                    'name' => '{name}',
                    'id_contact' => '{id_contact}',
                    'id_account' => '{id_account}',
                    'id_goods' => '{id_goods}',
                ], $attribution),
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
