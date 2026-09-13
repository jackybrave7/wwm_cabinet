<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Query/body fields for AVO outbound webhooks so cabinet can store UTM without heavy API backfill.
 */
final class WebhookAttributionParams
{
    /**
     * @return array<string, string>
     */
    public static function forAvoWebhook(): array
    {
        return [
            'utm_source' => '{utm_source}',
            'utm_medium' => '{utm_medium}',
            'utm_campaign' => '{utm_campaign}',
            'utm_term' => '{utm_term}',
            'utm_content' => '{utm_content}',
            'advertising_channel_source' => '{advertising_channel_source}',
            'advertising_channel_type_traffic' => '{advertising_channel_type_traffic}',
            'advertising_channel_keyword' => '{advertising_channel_keyword}',
            'advertising_channel_location' => '{advertising_channel_location}',
            'id_advertising_channel_page' => '{id_advertising_channel_page}',
        ];
    }

    /**
     * @param array<string, string> $params
     */
    public static function postBodySample(array $params): string
    {
        $body = [];
        foreach ($params as $key => $value) {
            if ($key === 'token') {
                continue;
            }
            $body[$key] = $value;
        }

        return json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
