<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Resolves standard utm_* tags from AVO webhook payloads and REST API.
 *
 * Note: AVO contacts REST resource does not expose advertising_channel_* fields.
 * UTM data is available on accounts (orders) and contactnewsletterlinks.
 */
final class AvoUtmResolver
{
    /** @var list<string> */
    private const CONTACT_LINK_RESOURCES = [
        'contactnewsletterlinks',
        'contactadvertisingchannelpage',
        'advertisingchannelcontactstatistics',
        'advertisingchannelcontact',
        'advertisingchannelstatistics',
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

        $email = strtolower(trim((string)($payload['email'] ?? '')));

        $accountId = (int)($payload['id_account'] ?? 0);
        if ($accountId > 0) {
            $rows = $this->client->searchRows('accounts', ['id_account' => (string)$accountId], ['pagesize' => 1]);
            if ($rows !== []) {
                $utm = StudentAttribution::mergeUtm($utm, $this->utmFromRow($rows[0]));
            }
        } elseif ($email !== '') {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromAccountsByEmail($email));
        }

        $contactId = (int)($payload['id_contact'] ?? 0);
        if ($contactId <= 0 && $email !== '') {
            $contactId = (int)($this->client->findContactIdByEmail($email) ?? 0);
        }

        if ($contactId > 0) {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromContactLinks($contactId));
        }

        if ($email !== '' && !$this->hasCoreUtm($utm)) {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromStatisticsByEmail($email));
        }

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    private function utmFromStatisticsByEmail(string $email): array
    {
        $utm = [];
        foreach (['advertisingchannelstatistics', 'advertisingchannelcontactstatistics'] as $resource) {
            $rows = $this->client->searchRows($resource, ['email' => $email], ['pagesize' => 5]);
            if ($rows === []) {
                continue;
            }
            $row = $this->pickOldestRow($rows);
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromRow($row));
            if ($this->hasCoreUtm($utm)) {
                break;
            }
        }

        return $utm;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function resolveDebug(array $payload): array
    {
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $contactId = (int)($payload['id_contact'] ?? 0);
        if ($contactId <= 0 && $email !== '') {
            $contactId = (int)($this->client->findContactIdByEmail($email) ?? 0);
        }

        $sources = [];
        if ($email !== '') {
            foreach ($this->client->searchRows('accounts', ['email' => $email], ['pagesize' => 5]) as $i => $row) {
                $sources['accounts'][$i] = $this->summarizeRow($row);
            }
        }
        if ($contactId > 0) {
            foreach (self::CONTACT_LINK_RESOURCES as $resource) {
                $rows = $this->client->searchRows($resource, [
                    'id_contact' => (string)$contactId,
                ], ['pagesize' => 5]);
                $sources[$resource] = [
                    'rows' => count($rows),
                    'samples' => array_map(fn (array $row): array => $this->summarizeRow($row), $rows),
                ];
            }
        }

        return [
            'avo_enabled' => $this->client->isEnabled(),
            'email' => $email !== '' ? $email : null,
            'contact_id' => $contactId > 0 ? $contactId : null,
            'sources' => $sources,
            'resolved_utm' => $this->resolve($payload),
            'last_error' => $this->client->lastError(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function utmFromAccountsByEmail(string $email): array
    {
        $rows = $this->client->searchRows('accounts', ['email' => $email], [
            'pagesize' => 10,
        ]);
        if ($rows === []) {
            return [];
        }

        usort($rows, static function (array $a, array $b): int {
            return self::rowTimestamp($b) <=> self::rowTimestamp($a);
        });

        $utm = [];
        foreach ($rows as $row) {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromRow($row));
            if ($this->hasCoreUtm($utm)) {
                break;
            }
        }

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    private function utmFromContactLinks(int $contactId): array
    {
        $utm = [];

        foreach (self::CONTACT_LINK_RESOURCES as $resource) {
            $rows = $this->client->searchRows($resource, [
                'id_contact' => (string)$contactId,
            ], ['pagesize' => 5]);
            if ($rows === []) {
                continue;
            }

            $row = $this->pickOldestRow($rows);
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromRow($row));
            if ($this->hasCoreUtm($utm)) {
                break;
            }
        }

        return $utm;
    }

    /**
     * @param array<string, string> $utm
     */
    private function hasCoreUtm(array $utm): bool
    {
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            if (trim((string)($utm[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function pickOldestRow(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            return self::rowTimestamp($a) <=> self::rowTimestamp($b);
        });

        return $rows[0];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function rowTimestamp(array $data): int
    {
        foreach (['date_transition', 'date_of_order', 'creation_date', 'date_registration', 'datetime_notify', 'confirmed_date'] as $key) {
            $raw = trim((string)($data[$key] ?? ''));
            if ($raw === '' || strncmp($raw, '0000-00-00', 10) === 0) {
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
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function utmFromRow(array $row): array
    {
        $utm = StudentAttribution::utmFromAvoPayload($row);
        $utm = $this->applyFieldAliases($utm, $row);

        $pageRef = trim((string)($row['id_advertising_channel_page'] ?? ''));
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
        if (isset($this->channelPageCache[$pageRef])) {
            return $this->channelPageCache[$pageRef];
        }

        $utm = [];
        foreach (['advertisingchannelpage', 'advertisingchannelpages', 'advertisingchannel'] as $resource) {
            foreach (['id_advertising_channel_page', 'id', 'advertising_channel_page', 'advertising_channel'] as $searchKey) {
                $rows = $this->client->searchRows($resource, [$searchKey => $pageRef], ['pagesize' => 1]);
                if ($rows === []) {
                    continue;
                }

                $utm = $this->applyFieldAliases(StudentAttribution::utmFromAvoPayload($rows[0]), $rows[0]);
                $pageName = trim((string)($rows[0]['advertising_channel_page'] ?? $rows[0]['page'] ?? ''));
                if ($pageName !== '' && !isset($utm['utm_campaign'])) {
                    $utm['utm_campaign'] = mb_substr($pageName, 0, 255);
                }
                break 2;
            }
        }

        $this->channelPageCache[$pageRef] = $utm;

        return $utm;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summarizeRow(array $row): array
    {
        $keys = [
            'id_account',
            'id_contact',
            'email',
            'id_advertising_channel_page',
            'advertising_channel_keyword',
            'advertising_channel_location',
            'advertising_channel_type_traffic',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'date_of_order',
            'creation_date',
        ];
        $summary = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = trim((string)$row[$key]);
            if ($value !== '') {
                $summary[$key] = $value;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, string> $utm
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function applyFieldAliases(array $utm, array $row): array
    {
        $aliases = [
            'utm_source' => ['advertising_channel_source', 'advertising_channel', 'source'],
            'utm_campaign' => ['advertising_campaign', 'advertising_channel_campaign', 'advertising_channel_page', 'campaign'],
            'utm_medium' => ['advertising_channel_type_traffic', 'medium', 'type_traffic'],
            'utm_term' => ['advertising_channel_keyword', 'keyword'],
            'utm_content' => ['advertising_channel_location', 'location', 'ad_id'],
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
