<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Maps AVO product sales (id_goods) onto cabinet courses and builds webhook URLs
 * to paste into AVO (product «Дополнительно» or autofunnel «Отправить вебхук»).
 */
final class AvoSalesLinks
{
    /**
     * @return array<int, string> id_goods => course slug
     */
    public static function goodsMap(): array
    {
        $map = [];
        $configMap = wwm_config()['avo_goods_to_course'] ?? [];
        if (is_array($configMap)) {
            foreach ($configMap as $goodsId => $slug) {
                $goodsId = (int)$goodsId;
                $slug = preg_replace('/[^a-z0-9\-]/', '', (string)$slug) ?? '';
                if ($goodsId > 0 && $slug !== '') {
                    $map[$goodsId] = $slug;
                }
            }
        }

        foreach ((new CourseCatalog())->all() as $course) {
            $goodsId = (int)($course['avo_goods_id'] ?? 0);
            $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($course['slug'] ?? '')) ?? '';
            if ($goodsId > 0 && $slug !== '') {
                $map[$goodsId] = $slug;
            }
        }

        return $map;
    }

    public static function slugForGoodsId(int $goodsId): ?string
    {
        if ($goodsId <= 0) {
            return null;
        }

        $map = self::goodsMap();
        return isset($map[$goodsId]) ? (string)$map[$goodsId] : null;
    }

    public static function shouldSendPaidEmail(string $courseSlug, ?array $course = null): bool
    {
        $course ??= (new CourseCatalog())->getAdmin($courseSlug);
        if (is_array($course) && array_key_exists('paid_email', $course)) {
            return (bool)$course['paid_email'];
        }

        $slugs = wwm_config()['paid_email_slugs'] ?? null;
        if (is_array($slugs) && $slugs !== []) {
            return in_array($courseSlug, $slugs, true);
        }

        return false;
    }

    /**
     * Same URL can be pasted on every AVO product. id_goods in the query is a
     * fallback when the POST body does not include it.
     *
     * @return array{url: string, token_label: string, endpoint: string}|null
     */
    public static function paymentWebhook(?int $goodsId = null): ?array
    {
        $params = [];
        if ($goodsId !== null && $goodsId > 0) {
            $params['id_goods'] = (string)$goodsId;
        }

        return self::buildWebhook('/api/payment', 'payment_token', 'WWM_WEBHOOK_PAYMENT_TOKEN', $params);
    }

    /**
     * @return array{url: string, token_label: string, endpoint: string}|null
     */
    public static function demoWebhook(string $courseSlug): ?array
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', $courseSlug) ?? '';
        if ($slug === '') {
            return null;
        }

        return self::buildWebhook('/api/demo', 'demo_token', 'WWM_WEBHOOK_DEMO_TOKEN', [
            'email' => '{email}',
            'name' => '{name}',
            'course' => $slug,
            'id_contact' => '{id_contact}',
        ]);
    }

    /**
     * @param array<string, string> $params
     * @return array{url: string, token_label: string, endpoint: string}|null
     */
    private static function buildWebhook(string $path, string $tokenKey, string $tokenLabel, array $params): ?array
    {
        $webhooks = wwm_config()['webhooks'] ?? [];
        $token = trim((string)($webhooks[$tokenKey] ?? ''));
        if ($token === '') {
            return null;
        }

        $query = array_merge(['token' => $token], $params);

        return [
            'url' => rtrim(wwm_base_url(), '/') . $path . '?' . EmailWebhookCatalog::query($query),
            'token_label' => $tokenLabel,
            'endpoint' => $path,
        ];
    }
}
