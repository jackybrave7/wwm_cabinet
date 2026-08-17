<?php
declare(strict_types=1);

/**
 * Optional bridge: AVO → bl-school → WWM Cabinet (only if AVO cannot call my.* directly).
 *
 * Preferred: AVO → https://my.worldwatercolormasters.art/api/demo?email=...&token=...
 *
 * Deploy here only as: public/api/avo-demo-cabinet.php
 */
const WWM_CABINET_DEMO_URL = 'https://my.worldwatercolormasters.art/api/demo';
const WWM_CABINET_DEMO_TOKEN = 'CHANGE_ME_SAME_AS_WWM_WEBHOOK_DEMO_TOKEN';

header('Content-Type: application/json; charset=utf-8');

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
if ($token === '' || !hash_equals(WWM_CABINET_DEMO_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token']);
    exit;
}

$email = trim((string)($_GET['email'] ?? $_POST['email'] ?? ''));
$name = resolveAvoContactName(array_merge(
    extractAvoPayloadRow() ?? [],
    [
        'name' => trim((string)($_GET['name'] ?? $_POST['name'] ?? '')),
        'last_name' => trim((string)($_GET['last_name'] ?? $_POST['last_name'] ?? '')),
    ]
));
$course = trim((string)($_GET['course'] ?? $_POST['course'] ?? ''));
$idGoods = (int)($_GET['id_goods'] ?? $_POST['id_goods'] ?? 0);

if ($email === '') {
    $email = extractEmailFromAvoPayload();
}

if ($email === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'email_required']);
    exit;
}

$payload = [
    'email' => $email,
    'name' => $name,
    'source' => 'avo',
    'source_ref' => trim((string)($_GET['source_ref'] ?? $_POST['source_ref'] ?? 'autofunnel')),
];

$idContact = (int)($_GET['id_contact'] ?? $_POST['id_contact'] ?? 0);
if ($idContact <= 0) {
    $row = extractAvoPayloadRow();
    if (is_array($row)) {
        $idContact = (int)($row['id_contact'] ?? 0);
    }
}
if ($idContact > 0) {
    $payload['id_contact'] = $idContact;
}

if ($course !== '') {
    $payload['course'] = $course;
} elseif ($idGoods > 0) {
    $payload['id_goods'] = $idGoods;
} else {
    $payload['course'] = 'elke-en';
}

$response = forwardToCabinet($payload);
http_response_code($response['status']);
echo $response['body'];
exit;

function extractEmailFromAvoPayload(): string
{
    $data = extractAvoPayloadRow();
    if ($data === null) {
        return '';
    }

    return trim((string)($data['email'] ?? ''));
}

/**
 * @return array<string, mixed>|null
 */
function extractAvoPayloadRow(): ?array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $data = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($data)) {
        return null;
    }

    $first = $data[0] ?? $data;
    return is_array($first) ? $first : null;
}

/**
 * @param array<string, mixed> $payload
 * @return array{status: int, body: string}
 */
function forwardToCabinet(array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['status' => 500, 'body' => json_encode(['ok' => false, 'error' => 'encode_failed'])];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init(WWM_CABINET_DEMO_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-WWM-Demo-Token: ' . WWM_CABINET_DEMO_TOKEN,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $body !== '') {
            return ['status' => $status > 0 ? $status : 502, 'body' => $body];
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'X-WWM-Demo-Token: ' . WWM_CABINET_DEMO_TOKEN,
            ]),
            'content' => $json,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents(WWM_CABINET_DEMO_URL, false, $context);
    $status = 502;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
        $status = (int)$m[1];
    }

    return [
        'status' => $status,
        'body' => is_string($body) && $body !== '' ? $body : json_encode(['ok' => false, 'error' => 'cabinet_unreachable']),
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function resolveAvoContactName(array $payload): string
{
    $first = trim((string)($payload['name'] ?? ''));
    $last = trim((string)($payload['last_name'] ?? $payload['surname'] ?? ''));
    $middle = trim((string)($payload['middle_name'] ?? ''));

    if ($first !== '' && $last !== '') {
        return trim($first . ($middle !== '' ? ' ' . $middle : '') . ' ' . $last);
    }

    return $first !== '' ? $first : $last;
}
