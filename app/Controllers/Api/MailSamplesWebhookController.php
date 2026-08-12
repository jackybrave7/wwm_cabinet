<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

use Wwm\Services\EmailSampleSender;

final class MailSamplesWebhookController
{
    public function send(): void
    {
        WebhookAuth::requireDemo();

        $email = trim((string)($_GET['email'] ?? $_POST['email'] ?? ''));
        $name = trim((string)($_GET['name'] ?? $_POST['name'] ?? ''));

        if ($email === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'email_required']);
        }

        try {
            $results = (new EmailSampleSender())->sendAll($email, $name !== '' ? $name : null);
        } catch (\InvalidArgumentException $e) {
            wwm_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
        }

        $sent = 0;
        $failed = 0;
        foreach ($results as $row) {
            if ($row['ok']) {
                $sent++;
            } else {
                $failed++;
            }
        }

        wwm_json_response(200, [
            'ok' => $failed === 0,
            'email' => $email,
            'sent' => $sent,
            'failed' => $failed,
            'results' => $results,
        ]);
    }
}
