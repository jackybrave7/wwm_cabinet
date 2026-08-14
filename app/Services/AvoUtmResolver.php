<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Resolves standard utm_* tags from AVO webhook payloads and REST API.
 */
final class AvoUtmResolver
{
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
            $rows = $this->client->searchRows('accounts', ['id_account' => (string)$accountId], ['pagesize' => 1]);
            if ($rows !== []) {
                $utm = StudentAttribution::mergeUtm($utm, $this->utmFromRow($rows[0]));
            }
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
        $contactId = (int)($payload['id_contact'] ?? 0);
        if ($contactId <= 0) {
            $email = strtolower(trim((string)($payload['email'] ?? '')));
            if ($email !== '') {
                $contactId = (int)($this->client->findContactIdByEmail($email) ?? 0);
            }
        }

        return [
            'avo_enabled' => $this->client->isEnabled(),
            'contact_id' => $contactId,
            'resolved_utm' => $this->resolve($payload),
            'last_error' => $this->client->lastError(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function utmFromContact(int $contactId): array
    {
        $utm = [];

        $contact = $this->client->findContactById($contactId);
        if ($contact !== null) {
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromRow($contact));
        }

        foreach (['contactadvertisingchannelpage', 'contactnewsletterlinks'] as $resource) {
            $rows = $this->client->searchRows($resource, [
                'id_contact' => (string)$contactId,
            ], ['pagesize' => 5]);
            if ($rows === []) {
                continue;
            }

            $row = $this->pickOldestRow($rows);
            $utm = StudentAttribution::mergeUtm($utm, $this->utmFromRow($row));
        }

        return $utm;
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
        foreach (['date_transition', 'creation_date', 'date_registration', 'datetime_notify', 'confirmed_date'] as $key) {
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
        foreach (['advertisingchannelpage', 'advertisingchannelpages'] as $resource) {
            foreach (['id_advertising_channel_page', 'id'] as $searchKey) {
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
