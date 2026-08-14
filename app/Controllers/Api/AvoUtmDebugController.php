<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

use Wwm\Services\AvoClient;
use Wwm\Services\AvoUtmResolver;

final class AvoUtmDebugController
{
    public function show(): void
    {
        WebhookAuth::requireDemo();

        try {
            $email = strtolower(trim((string)($_GET['email'] ?? '')));
            $contactId = (int)($_GET['id_contact'] ?? 0);

            if ($email === '' && $contactId <= 0) {
                wwm_json_response(400, ['ok' => false, 'error' => 'email_or_id_contact_required']);
            }

            $client = new AvoClient();
            if ($contactId <= 0 && $email !== '') {
                $contactId = (int)($client->findContactIdByEmail($email) ?? 0);
            }

            $payload = [];
            if ($contactId > 0) {
                $payload['id_contact'] = $contactId;
            }
            if ($email !== '') {
                $payload['email'] = $email;
            }

            $resolver = new AvoUtmResolver($client);
            $utm = $resolver->resolve($payload);

            wwm_json_response(200, [
                'ok' => true,
                'email' => $email !== '' ? $email : null,
                'contact_id' => $contactId > 0 ? $contactId : null,
                'utm' => $utm,
                'debug' => $resolver->resolveDebug($payload),
                'avo_last_error' => $client->lastError(),
            ]);
        } catch (\Throwable $e) {
            wwm_log('avo utm debug failed: ' . $e->getMessage());
            wwm_json_response(500, [
                'ok' => false,
                'error' => 'debug_failed',
                'message' => $e->getMessage(),
            ]);
        }
    }
}
