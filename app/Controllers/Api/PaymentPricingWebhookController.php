<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

use Wwm\Models\Payment;
use Wwm\Models\PaymentPricingPending;
use Wwm\Models\User;
use Wwm\Services\AvoWebhookPayload;
use Wwm\Services\DemoAccess;

final class PaymentPricingWebhookController
{
    public function store(): void
    {
        WebhookAuth::requirePayment();

        $payload = AvoWebhookPayload::read();
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $accountId = trim((string)($payload['id_account'] ?? $payload['source_ref'] ?? ''));
        $courseSlug = DemoAccess::resolveCourseSlug(
            isset($payload['course']) ? (string)$payload['course'] : null,
            isset($payload['id_goods']) ? (int)$payload['id_goods'] : null
        );

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            wwm_json_response(400, ['ok' => false, 'error' => 'email_required']);
        }
        if ($accountId === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'id_account_required']);
        }
        if ($courseSlug === null || $courseSlug === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'course_required']);
        }

        $pricing = Payment::pricingFromPayload($payload);
        $pdo = wwm_pdo();

        $user = User::findByEmail($pdo, $email);
        if ($user !== null && Payment::applyPricingFields($pdo, $accountId, $courseSlug, $pricing)) {
            wwm_log(sprintf(
                'payment pricing applied user_id=%d account=%s course=%s',
                (int)$user['id'],
                $accountId,
                $courseSlug
            ));
            wwm_json_response(200, ['ok' => true, 'applied' => true]);
        }

        PaymentPricingPending::store($pdo, [
            'avo_account_id' => $accountId,
            'course_slug' => $courseSlug,
            'email' => $email,
            'amount_original' => $pricing['amount_original'],
            'currency_original' => $pricing['currency_original'],
            'amount_rub' => $pricing['amount_rub'],
            'fx_rate' => $pricing['fx_rate'],
        ]);

        wwm_json_response(200, ['ok' => true, 'applied' => false, 'queued' => true]);
    }
}
