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
        'contactadvertisingchannelpage',
        'contactnewsletterlinks',
        'advertisingchannelcontactstatistics',
        'advertisingchannelstatistics',
        'advertisingchannelcontact',
    ];

    /** @var list<string> */
    private const CHANNEL_PAGE_RESOURCES = [
        'advertisingchannelpage',
        'advertisingchannelpages',
    ];

    private AvoClient $client;

    /** @var array<string, array<string, string>> */
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function resolveDebug(array $payload): array
    {
        $debug = [
            'avo_enabled' => $this->client->isEnabled(),
            'payload_utm' => StudentAttribution::utmFromAvoPayload($payload),
            'contact_id' => (int)($payload['id_contact'] ?? 0),
            'sources' => [],
            'resolved_utm' => [],
            'last_error' => null,
        ];

        if (!$this->client->isEnabled()) {
            return $debug;
        }

        $contactId = $debug['contact_id'];
        if ($contactId <= 0) {
            $email = strtolower(trim((string)($payload['email'] ?? '')));
            if ($email !== '') {
                $contactId = (int)($this->client->findContactIdByEmail($email) ?? 0);
                $debug['contact_id'] = $contactId;
            }
        }

        $utm = $debug['payload_utm'];

        $accountId = (int)($payload['id_account'] ?? 0);
        if ($accountId > 0) {
            $rows = $this->client->searchRows('accounts', ['id_account' => (string)$accountId], ['pagesize' => 1]);
            $accountUtm = $rows === [] ? [] : $this->utmFromAdvertisingData($rows[0]);
            $debug['sources']['account'] = ['rows' => count($rows), 'utm' => $accountUtm];
            $utm = StudentAttribution::mergeUtm($utm, $accountUtm);
        }

        if ($contactId > 0) {
            $contact = $this->client->findContactById($contactId);
            $contactUtm = $contact === null ? [] : $this->utmFromAdvertisingData($contact);
            $debug['sources']['contact'] = [
                'found' => $contact !== null,
                'page_ref' => $contact === null ? null : ($contact['id_advertising_channel_page'] ?? null),
                'utm' => $contactUtm,
            ];
            $utm = StudentAttribution::mergeUtm($utm, $contactUtm);

            foreach (self::CONTACT_UTM_RESOURCES as $resource) {
                $rows = $this->client->searchRows($resource, [
                    'id_contact' => (string)$contactId,
                ], ['pagesize' => 25]);
                $resourceUtm = [];
                foreach ($rows as $row) {
                    $resourceUtm = StudentAttribution::mergeUtm($resourceUtm, $this->utmFromAdvertisingData($row));
                }
                $debug['sources'][$resource] = ['rows' => count($rows), 'utm' => $resourceUtm];
                $utm = StudentAttribution::mergeUtm($utm, $resourceUtm);
            }
        }

        $debug['resolved_utm'] = $utm;
        $debug['last_error'] = $this->client->lastError();

        return $debug;
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
        $utm = [];

        $contact = $this->client->findContactById($contactId);
        if ($contact !== null) {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromAdvertisingData($contact));
        }

        foreach (self::CONTACT_UTM_RESOURCES as $resource) {
            $rows = $this->client->searchRows($resource, [
                'id_contact' => (string)$contactId,
            ], ['pagesize' => 50]);
            if ($rows === []) {
                continue;
            }

            if (in_array($resource, ['contactadvertisingchannelpage', 'contactnewsletterlinks'], true)) {
                $rows = [$this->pickFirstTouchRow($rows)];
            }

            foreach ($rows as $row) {
                $utm = StudentAttribution::mergeUtm($utm, $this->utmFromAdvertisingData($row));
            }
        }

        return $utm;
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
        foreach (['date_transition', 'creation_date', 'date_registration', 'datetime_notify', 'confirmed_date'] as $key) {
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

        $pageRef = trim((string)($data['id_advertising_channel_page'] ?? ''));
        if ($pageRef !== '' && $pageRef !== '0') {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromChannelPage($pageRef));
        }

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    private function utmFromChannelPage(string $pageRef): array
    {
        $pageRef = trim($pageRef);
        if ($pageRef === '') {
            return [];
        }

        if (isset($this->channelPageCache[$pageRef])) {
            return $this->channelPageCache[$pageRef];
        }

        $utm = [];
        $pageRow = null;
        $searchKeys = ['id_advertising_channel_page', 'id', 'advertising_channel_page'];
        foreach (self::CHANNEL_PAGE_RESOURCES as $resource) {
            foreach ($searchKeys as $searchKey) {
                $rows = $this->client->searchRows($resource, [$searchKey => $pageRef], ['pagesize' => 1]);
                if ($rows === []) {
                    continue;
                }

                $pageRow = $rows[0];
                $utm = $this->utmFromChannelPageRow($pageRow);
                if ($utm !== []) {
                    break 2;
                }
            }
        }

        if (!isset($utm['utm_source']) && is_array($pageRow)) {
            $channelId = (int)($pageRow['id_advertising_channel'] ?? 0);
            if ($channelId > 0) {
                $utm = StudentAttribution::mergeUtm($utm, $this->utmFromAdvertisingChannel($channelId));
            }
        }

        $this->channelPageCache[$pageRef] = $utm;

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    private function utmFromAdvertisingChannel(int $channelId): array
    {
        $row = $this->client->getResourceById('advertisingchannel', $channelId);
        if ($row === null) {
            $rows = $this->client->searchRows('advertisingchannel', [
                'id_advertising_channel' => (string)$channelId,
            ], ['pagesize' => 1]);
            $row = $rows[0] ?? null;
        }
        if ($row === null) {
            return [];
        }

        return $this->applyFieldAliases([], $row);
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
            'utm_source' => ['advertising_channel_source', 'advertising_channel', 'source', 'utm_source_name'],
            'utm_campaign' => ['advertising_campaign', 'advertising_channel_campaign', 'advertising_channel_page', 'campaign', 'utm_campaign_name'],
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
