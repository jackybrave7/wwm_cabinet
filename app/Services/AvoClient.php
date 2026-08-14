<?php
declare(strict_types=1);

namespace Wwm\Services;

final class AvoClient
{
    private ?string $lastError = null;

    /** @var array<string, mixed> */
    private array $cfg;

    public function __construct(?array $config = null)
    {
        $config ??= wwm_config();
        $avo = $config['avo'] ?? [];
        $this->cfg = is_array($avo) ? $avo : [];
    }

    public function isEnabled(): bool
    {
        if (empty($this->cfg['enabled'])) {
            return false;
        }

        $shop = trim((string)($this->cfg['shop_id'] ?? ''));
        $keySet = trim((string)($this->cfg['api_key_set'] ?? ''));
        $keyGet = trim((string)($this->cfg['api_key_get'] ?? ''));

        return $shop !== '' && ($keySet !== '' || $keyGet !== '');
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function tagId(string $name): int
    {
        $tags = $this->cfg['tags'] ?? [];
        if (!is_array($tags)) {
            return 0;
        }

        return (int)($tags[$name] ?? 0);
    }

    public function findContactIdByEmail(string $email): ?int
    {
        $contact = $this->findContactByEmail($email);
        if ($contact === null) {
            return null;
        }

        $id = (int)($contact['id_contact'] ?? $contact['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findContactByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $rows = $this->searchRows('contacts', ['email' => $email], ['pagesize' => 5]);
        foreach ($rows as $row) {
            $rowEmail = strtolower(trim((string)($row['email'] ?? '')));
            if ($rowEmail === '' || $rowEmail === $email) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findContactById(int $contactId): ?array
    {
        if ($contactId <= 0) {
            return null;
        }

        foreach (['id_contact', 'id'] as $key) {
            $rows = $this->searchRows('contacts', [$key => (string)$contactId], ['pagesize' => 1]);
            if ($rows !== []) {
                return $rows[0];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResourceById(string $resource, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $response = $this->request('GET', $resource, ['id' => (string)$id], null, 'get');
        if ($response === null || $response === []) {
            return null;
        }

        foreach ($this->extractRows($response) as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    public function contactHasTag(int $contactId, int $tagId): bool
    {
        if ($contactId <= 0 || $tagId <= 0) {
            return false;
        }

        $response = $this->request('GET', 'contacttaglnk', [
            'search' => [
                'id_contact' => (string)$contactId,
                'id_contact_tag' => (string)$tagId,
            ],
            'param' => ['pagesize' => 1],
        ], null, 'get');
        if ($response === null) {
            return false;
        }

        foreach ($this->extractRows($response) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int)($row['id_contact'] ?? 0) === $contactId
                && (int)($row['id_contact_tag'] ?? 0) === $tagId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, scalar> $search
     * @param array<string, scalar> $param
     * @return list<array<string, mixed>>
     */
    public function searchRows(string $resource, array $search = [], array $param = []): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        if (!isset($param['pagesize'])) {
            $param['pagesize'] = 5;
        }
        $limit = max(1, (int)$param['pagesize']);

        $response = $this->request('GET', $resource, [
            'search' => $search,
            'param' => $param,
        ], null, 'get');
        if ($response === null) {
            return [];
        }

        $rows = [];
        foreach ($this->extractRows($response) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    public function assignTag(int $contactId, int $tagId): bool
    {
        if ($contactId <= 0 || $tagId <= 0) {
            $this->lastError = 'invalid_contact_or_tag';
            return false;
        }

        if ($this->contactHasTag($contactId, $tagId)) {
            return true;
        }

        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<root><item>'
            . '<id_contact>' . $contactId . '</id_contact>'
            . '<id_contact_tag>' . $tagId . '</id_contact_tag>'
            . '</item></root>';

        $response = $this->request('POST', 'contacttaglnk', [], $xml, 'set');
        if ($response === null) {
            return false;
        }

        if ($response === []) {
            return $this->contactHasTag($contactId, $tagId);
        }

        foreach ($this->extractRows($response) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int)($row['id_contact'] ?? 0) === $contactId
                && (int)($row['id_contact_tag'] ?? 0) === $tagId) {
                return true;
            }
        }

        if (isset($response['id_contact'], $response['id_contact_tag'])
            && (int)$response['id_contact'] === $contactId
            && (int)$response['id_contact_tag'] === $tagId) {
            return true;
        }

        return $this->contactHasTag($contactId, $tagId);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|list<mixed>|null
     */
    private function request(string $method, string $resource, array $query, ?string $xmlBody, string $keyType): ?array
    {
        $this->lastError = null;
        $shop = trim((string)($this->cfg['shop_id'] ?? ''));
        $key = $keyType === 'set'
            ? trim((string)($this->cfg['api_key_set'] ?? ''))
            : trim((string)($this->cfg['api_key_get'] ?? ''));

        if ($shop === '' || $key === '') {
            $this->lastError = 'avo_not_configured';
            return null;
        }

        if ($keyType === 'set' && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            // use set key
        } elseif ($method === 'GET') {
            // use get key (already selected)
        } elseif ($keyType === 'set' && $method === 'GET') {
            $key = trim((string)($this->cfg['api_key_get'] ?? $key));
        }

        $params = ['r' => 'api/rest/' . $resource, 'key' => $key, 'donotfallonerror' => '1'];
        foreach ($query as $name => $value) {
            if ($name === 'search' && is_array($value)) {
                foreach ($value as $searchKey => $searchValue) {
                    $params['search[' . $searchKey . ']'] = (string)$searchValue;
                }
                continue;
            }
            if ($name === 'param' && is_array($value)) {
                foreach ($value as $paramKey => $paramValue) {
                    $params['param[' . $paramKey . ']'] = (string)$paramValue;
                }
                continue;
            }
            $params[$name] = is_scalar($value) ? (string)$value : '';
        }

        $url = 'https://' . rawurlencode($shop) . '.autoweboffice.ru/?' . http_build_query($params);
        $headers = ['Accept: application/json'];
        if ($xmlBody !== null) {
            $headers[] = 'Content-type: text/xml;charset=utf-8';
            $headers[] = 'Cache-Control: no-cache';
            $headers[] = 'Pragma: no-cache';
        }

        $body = $this->http($method, $url, $headers, $xmlBody);
        if ($body === null) {
            return null;
        }

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->lastError = 'invalid_json_response';
            wwm_log('avo api invalid json (' . $resource . '): ' . mb_substr($body, 0, 500));
            return null;
        }

        if ($this->isList($decoded) && count($decoded) > 25) {
            $decoded = array_slice($decoded, 0, 25);
        }

        return $decoded;
    }

    /**
     * @param list<string> $headers
     */
    private function http(string $method, string $url, array $headers, ?string $body): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_POSTFIELDS => $body,
            ]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (!is_string($response)) {
                $this->lastError = $error !== '' ? $error : 'curl_failed';
                return null;
            }

            if (strlen($response) > 1048576) {
                $this->lastError = 'response_too_large';
                wwm_log('avo api response too large ' . $method . ' ' . $url);
                return null;
            }

            if ($status >= 400) {
                $this->lastError = 'http_' . $status;
                wwm_log('avo api http ' . $status . ' ' . $method . ' ' . $url . ' body=' . mb_substr($response, 0, 300));
                return null;
            }

            return $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        if (!is_string($response)) {
            $this->lastError = 'http_failed';
            return null;
        }

        if (strlen($response) > 1048576) {
            $this->lastError = 'response_too_large';
            wwm_log('avo api response too large ' . $method . ' ' . $url);
            return null;
        }

        if ($status >= 400) {
            $this->lastError = 'http_' . $status;
            wwm_log('avo api http ' . $status . ' ' . $method . ' ' . $url . ' body=' . mb_substr($response, 0, 300));
            return null;
        }

        return $response;
    }

    /**
     * @param array<string, mixed>|list<mixed> $response
     * @return list<mixed>
     */
    private function extractRows(array $response): array
    {
        if ($this->isList($response)) {
            return $response;
        }

        foreach ([
            'contacttaglnk',
            'contacts',
            'accounts',
            'contactnewsletterlinks',
            'advertisingchannelstatistics',
            'advertisingchannelcontactstatistics',
            'contactadvertisingchannelpage',
            'advertisingchannelcontact',
            'advertisingchannelpage',
            'advertisingchannelpages',
            'advertisingchannel',
            'data',
            'rows',
            'items',
            'result',
        ] as $key) {
            if (!isset($response[$key]) || !is_array($response[$key])) {
                continue;
            }
            $value = $response[$key];
            if ($this->isList($value)) {
                return $value;
            }
            return [$value];
        }

        if (isset($response['id_contact']) || isset($response['id_contact_tag'])
            || isset($response['id_account']) || isset($response['id_advertising_channel_page'])
            || isset($response['utm_source'])) {
            return [$response];
        }

        return [];
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
