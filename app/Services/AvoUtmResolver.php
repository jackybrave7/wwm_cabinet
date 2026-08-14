<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Resolves standard utm_* tags from AVO webhook payloads and REST API.
 *
 * AVO stores UTM in contact/advertising stats; webhooks expose advertising_channel_* fields
 * (see autoweboffice.com help: webhooks + accounts API).
 */
final class AvoUtmResolver
{
    /** @var list<string> */
    private const CONTACT_UTM_RESOURCES = [
        'advertisingchannelstatistics',
        'advertisingchannelcontactstatistics',
        'contactadvertisingchannelpage',
        'advertisingchannelcontact',
    ];

    /** @var list<string> */
    private const CHANNEL_PAGE_RESOURCES = [
        'advertisingchannelpage',
        'advertisingchannelpages',
    ];

    private AvoClient $client;

    /** @var array<int, array<string, string>> */
    private array $channelPageCache = [];

    public function __construct(?AvoClient $client = null)
    {
        $this->client = $client ?? new AvoClient();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public function resolve(array $payload): array
    {
        $utm = StudentAttribution::utmFromAvoPayload($payload);
        if (!$this->client->isEnabled()) {
            return $utm;
        }

        $accountId = (int)($payload['id_account'] ?? 0);
        if ($accountId > 0) {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromAccount($accountId));
        }

        $contactId = (int)($payload['id_contact'] ?? 0);
        if ($contactId <= 0) {
            $email = strtolower(trim((string)($payload['email'] ?? '')));
            if ($email !== '') {
                $contactId = (int)($this->client->findContactIdByEmail($email) ?? 0);
            }
        }

        if ($contactId > 0) {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromContact($contactId));
        }

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    private function utmFromAccount(int $accountId): array
    {
        $rows = $this->client->searchRows('accounts', [
            'id_account' => (string)$accountId,
        ], ['pagesize' => 1]);

        if ($rows === []) {
            return [];
        }

        return $this->utmFromAdvertisingData($rows[0]);
    }

    /**
     * First-touch UTM history for contact (oldest transition with data).
     *
     * @return array<string, string>
     */
    private function utmFromContact(int $contactId): array
    {
        $rows = $this->client->searchRows('contacts', [
            'id_contact' => (string)$contactId,
        ], ['pagesize' => 1]);
        if ($rows !== []) {
            $utm = $this->utmFromAdvertisingData($rows[0]);
            if ($utm !== []) {
                return $utm;
            }
        }

        foreach (self::CONTACT_UTM_RESOURCES as $resource) {
            $rows = $this->client->searchRows($resource, [
                'id_contact' => (string)$contactId,
            ], ['pagesize' => 25]);
            if ($rows === []) {
                continue;
            }

            $row = $this->pickFirstTouchRow($rows);
            $utm = $this->utmFromAdvertisingData($row);
            if ($utm !== []) {
                return $utm;
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function pickFirstTouchRow(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $ta = self::rowTimestamp($a);
            $tb = self::rowTimestamp($b);

            return $ta <=> $tb;
        });

        foreach ($rows as $row) {
            if ($this->utmFromAdvertisingData($row) !== []) {
                return $row;
            }
        }

        return $rows[0];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function rowTimestamp(array $data): int
    {
        foreach (['date_transition', 'creation_date', 'date_registration', 'datetime_notify'] as $key) {
            $raw = trim((string)($data[$key] ?? ''));
            if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
                continue;
            }
            $ts = strtotime($raw);
            if ($ts !== false) {
                return $ts;
            }
        }

        return PHP_INT_MAX;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function utmFromAdvertisingData(array $data): array
    {
        $utm = StudentAttribution::utmFromAvoPayload($data);
        $utm = $this->applyFieldAliases($utm, $data);

        $pageId = (int)($data['id_advertising_channel_page'] ?? 0);
        if ($pageId > 0) {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromChannelPage($pageId));
        }

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    private function utmFromChannelPage(int $pageId): array
    {
        if (isset($this->channelPageCache[$pageId])) {
            return $this->channelPageCache[$pageId];
        }

        $utm = [];
        foreach (self::CHANNEL_PAGE_RESOURCES as $resource) {
            $rows = $this->client->searchRows($resource, [
                'id_advertising_channel_page' => (string)$pageId,
            ], ['pagesize' => 1]);
            if ($rows === []) {
                $rows = $this->client->searchRows($resource, [
                    'id' => (string)$pageId,
                ], ['pagesize' => 1]);
            }
            if ($rows === []) {
                continue;
            }

            $utm = $this->utmFromChannelPageRow($rows[0]);
            if ($utm !== []) {
                break;
            }
        }

        $this->channelPageCache[$pageId] = $utm;

        return $utm;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function utmFromChannelPageRow(array $row): array
    {
        $utm = StudentAttribution::utmFromAvoPayload($row);
        $utm = $this->applyFieldAliases($utm, $row);

        $pageName = trim((string)($row['advertising_channel_page'] ?? $row['page'] ?? ''));
        if ($pageName !== '' && !isset($utm['utm_campaign'])) {
            $utm['utm_campaign'] = mb_substr($pageName, 0, 255);
        }

        return $utm;
    }

    /**
     * @param array<string, string> $utm
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function applyFieldAliases(array $utm, array $row): array
    {
        $aliases = [
            'utm_source' => ['advertising_channel_source', 'source', 'utm_source_name'],
            'utm_campaign' => ['advertising_campaign', 'advertising_channel_campaign', 'campaign', 'utm_campaign_name'],
            'utm_medium' => ['advertising_channel_type_traffic', 'medium', 'type_traffic'],
            'utm_term' => ['advertising_channel_keyword', 'keyword'],
            'utm_content' => ['advertising_channel_location', 'location', 'ad_id', 'advertising_channel_ad_id'],
        ];
        foreach ($aliases as $target => $keys) {
            if (isset($utm[$target]) && $utm[$target] !== '') {
                continue;
            }
            foreach ($keys as $key) {
                $value = trim((string)($row[$key] ?? ''));
                if ($value !== '') {
                    $utm[$target] = mb_substr($value, 0, 255);
                    break;
                }
            }
        }

        return $utm;
    }
}
