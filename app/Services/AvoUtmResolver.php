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
    /** Resources that may hold per-contact UTM transitions (bl-school supports a subset). */
    private const CONTACT_LINK_RESOURCES = [
        'contactnewsletterlinks',
        'contactadvertisingchannelpage',
    ];

    private const LINK_SEARCH_MAX_PAGES = 5;

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
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromContactRecord($contactId));
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromContactLinks($contactId, $email));
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromAccountsByContact($contactId));
        }

        if ($email !== '' && !$this->hasCoreUtm($utm)) {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromNewsletterLinksByEmail($email));
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromStatisticsByEmail($email));
        }

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    private function utmFromStatisticsByEmail(string $email): array
    {
        if ($this->client->isResourceMissing('advertisingchannelstatistics')) {
            return [];
        }

        $rows = $this->client->searchRows('advertisingchannelstatistics', ['email' => $email], ['pagesize' => 5]);
        if ($rows === []) {
            return [];
        }

        $row = $this->pickOldestRow($rows);

        return $this->utmFromRow($row);
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
    private function utmFromContactRecord(int $contactId): array
    {
        $contact = $this->client->findContactById($contactId);
        if ($contact === null) {
            return [];
        }

        return $this->utmFromRow($contact);
    }

    /**
     * @return array<string, string>
     */
    private function utmFromContactLinks(int $contactId, string $email = ''): array
    {
        $best = [];

        foreach (self::CONTACT_LINK_RESOURCES as $resource) {
            $rows = $this->searchScopedLinkRows($resource, ['id_contact' => (string)$contactId], $contactId, $email);
            $best = $this->mergeBestUtmFromRows($best, $rows);
            if ($this->hasCoreUtm($best)) {
                return $best;
            }
        }

        if ($email !== '' && !$this->hasCoreUtm($best)) {
            $best = StudentAttribution::mergeUtm($best, $this->utmFromNewsletterLinksByEmail($email));
        }

        return $best;
    }

    /**
     * @return array<string, string>
     */
    private function utmFromNewsletterLinksByEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        $rows = $this->searchScopedLinkRows(
            'contactnewsletterlinks',
            ['email' => $email],
            0,
            $email
        );

        return $this->mergeBestUtmFromRows([], $rows);
    }

    /**
     * @param array<string, string> $search
     * @return list<array<string, mixed>>
     */
    private function searchScopedLinkRows(string $resource, array $search, int $contactId, string $email): array
    {
        if ($this->client->isResourceMissing($resource)) {
            return [];
        }

        $email = strtolower(trim($email));
        $matched = [];

        for ($page = 1; $page <= self::LINK_SEARCH_MAX_PAGES; $page++) {
            $batch = $this->client->searchRows($resource, $search, [
                'pagesize' => 25,
                'currentpage' => $page,
            ]);
            if ($batch === []) {
                break;
            }

            $hits = [];
            foreach ($batch as $row) {
                if ($this->rowMatchesContactScope($row, $contactId, $email)) {
                    $hits[] = $row;
                }
            }

            if ($hits === [] && $page === 1) {
                // AVO often ignores search filters and returns the whole table — never paginate that.
                break;
            }

            foreach ($hits as $row) {
                $matched[] = $row;
            }

            if (count($batch) < 25) {
                break;
            }

            if ($this->hasCoreUtm($this->mergeBestUtmFromRows([], $matched))) {
                break;
            }
        }

        return $matched;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowMatchesContactScope(array $row, int $contactId, string $email): bool
    {
        if ($contactId > 0 && (int)($row['id_contact'] ?? 0) === $contactId) {
            return true;
        }

        if ($email !== '') {
            $rowEmail = strtolower(trim((string)($row['email'] ?? '')));

            return $rowEmail !== '' && $rowEmail === $email;
        }

        return false;
    }

    /**
     * @param array<string, string> $current
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function mergeBestUtmFromRows(array $current, array $rows): array
    {
        if ($rows === []) {
            return $current;
        }

        $best = $current;
        $bestScore = $this->utmScore($current);

        foreach ($rows as $row) {
            $candidate = $this->utmFromRow($row);
            $score = $this->utmScore($candidate);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param array<string, string> $utm
     */
    private function utmScore(array $utm): int
    {
        $score = 0;
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            if (trim((string)($utm[$key] ?? '')) !== '') {
                $score++;
            }
        }
        if ($this->hasCoreUtm($utm)) {
            $score += 10;
        }

        return $score;
    }

    /**
     * @return array<string, string>
     */
    private function utmFromAccountsByContact(int $contactId): array
    {
        $rows = $this->client->searchRows('accounts', ['id_contact' => (string)$contactId], [
            'pagesize' => 10,
        ]);
        if ($rows === []) {
            return [];
        }

        usort($rows, static function (array $a, array $b): int {
            return self::rowTimestamp($a) <=> self::rowTimestamp($b);
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
        $row = $this->normalizeRow($row);
        $utm = StudentAttribution::utmFromAvoPayload($row);
        $utm = $this->applyFieldAliases($utm, $row);

        $pageRef = trim((string)($row['id_advertising_channel_page'] ?? ''));
        if ($pageRef !== '' && $pageRef !== '0') {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromChannelPage($pageRef));
        }

        return $utm;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        if (isset($row['item']) && is_array($row['item'])) {
            return $row['item'];
        }

        return $row;
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
