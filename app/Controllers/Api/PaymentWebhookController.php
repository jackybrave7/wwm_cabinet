<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

use Wwm\Models\PaymentExternalKey;
use Wwm\Models\User;
use Wwm\Services\AvoAccountRow;
use Wwm\Services\AvoAdvertisingSnapshot;
use Wwm\Services\AvoContactName;
use Wwm\Services\AvoUtmResolver;
use Wwm\Services\AvoWebhookPayload;
use Wwm\Services\DemoAccess;
use Wwm\Services\PaidAccess;
use Wwm\Services\PaymentRecorder;
use Wwm\Services\StudentAttribution;
use Wwm\Services\TildaPaymentPayload;

final class PaymentWebhookController
{
    /**
     * Tilda → bl-school → cabinet: grant paid access immediately (no AVO invoice required).
     * POST /api/payment/webhook
     */
    public function webhook(): void
    {
        WebhookAuth::requirePayment();

        $payload = AvoWebhookPayload::read();
        if (TildaPaymentPayload::isPing($payload)) {
            $this->respondStatus('ok');
        }
        if (TildaPaymentPayload::isNative($payload)) {
            $payload = TildaPaymentPayload::normalize($payload);
        }
        $source = strtolower(trim((string)($payload['source'] ?? '')));
        if ($source !== 'tilda') {
            wwm_json_response(400, ['ok' => false, 'error' => 'source_tilda_required']);
        }

        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            $name = AvoContactName::resolveFromPayload($payload);
        }
        $courseSlug = DemoAccess::resolveCourseSlug(
            isset($payload['course']) ? (string)$payload['course'] : null,
            isset($payload['id_goods']) ? (int)$payload['id_goods'] : null
        );
        $externalKeys = PaymentExternalKey::collectFromPayload($payload);
        $accountId = trim((string)($payload['id_account'] ?? $payload['source_ref'] ?? ''));
        if ($accountId === '' && $externalKeys !== []) {
            $accountId = $externalKeys[0];
        }
        if ($accountId !== '' && trim((string)($payload['id_account'] ?? '')) === '') {
            $payload['id_account'] = $accountId;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            wwm_json_response(400, ['ok' => false, 'error' => 'email_required']);
        }
        if ($courseSlug === null || $courseSlug === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'course_required']);
        }

        $pdo = wwm_pdo();

        // Same purchase already processed via Tilda keys → no second grant/email.
        if ($externalKeys !== [] && PaymentExternalKey::anyExist($pdo, $externalKeys)) {
            $user = User::findByEmail($pdo, $email);
            if ($user !== null) {
                PaymentExternalKey::storeMany($pdo, $externalKeys, (int)$user['id'], $courseSlug, 'tilda');
                $this->recordPaymentQuietly(
                    $pdo,
                    (int)$user['id'],
                    $courseSlug,
                    'tilda',
                    $payload,
                    null,
                    null,
                    null
                );
            }
            $this->respondStatus('already_paid', [
                'already_paid' => true,
                'paid_granted' => false,
                'email_sent' => false,
                'course_slug' => $courseSlug,
            ]);
        }

        try {
            $paidAt = gmdate('c');
            $result = (new PaidAccess())->grant(
                $email,
                $name,
                $courseSlug,
                'tilda',
                $accountId !== '' ? $accountId : ($externalKeys[0] ?? null),
                [],
                null,
                null,
                $paidAt,
                $paidAt
            );
            $userId = (int)$result['user_id'];
            PaymentExternalKey::storeMany($pdo, $externalKeys, $userId, $courseSlug, 'tilda');
            $paymentRecorded = $this->recordPaymentQuietly(
                $pdo,
                $userId,
                $courseSlug,
                'tilda',
                $payload,
                $paidAt,
                $paidAt,
                null
            );
            if ($paymentRecorded && empty($result['already_paid'])) {
                \Wwm\Services\EmailAutomationEnrollment::onPaymentRecorded(
                    $userId,
                    $courseSlug,
                    $paidAt
                );
            }
        } catch (\InvalidArgumentException $e) {
            wwm_json_response(400, ['ok' => false, 'error' => 'invalid_email']);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Course not found') {
                wwm_json_response(404, ['ok' => false, 'error' => 'course_not_found']);
            }
            wwm_log('tilda payment webhook failed: ' . $e->getMessage());
            wwm_json_response(500, ['ok' => false, 'error' => 'grant_failed']);
        }

        $status = !empty($result['already_paid']) ? 'already_paid' : 'ok';
        $this->respondStatus($status, [
            'already_paid' => !empty($result['already_paid']),
            'paid_granted' => !empty($result['paid_granted']),
            'email_sent' => !empty($result['email_sent']),
            'course_slug' => $courseSlug,
            'user_id' => $result['user_id'] ?? null,
            'payment_recorded' => $paymentRecorded ?? false,
        ]);
    }

    /**
     * AVO «счёт оплачен» → cabinet (existing endpoint /api/payment).
     */
    public function grant(): void
    {
        WebhookAuth::requirePayment();

        $payload = AvoWebhookPayload::read();
        if (!AvoWebhookPayload::isPaidAccountStatus($payload)) {
            wwm_json_response(200, ['ok' => true, 'skipped' => true, 'reason' => 'not_paid']);
        }

        $email = trim((string)($payload['email'] ?? ''));
        $name = AvoContactName::resolveFromPayload($payload);
        $courseSlug = DemoAccess::resolveCourseSlug(
            isset($payload['course']) ? (string)$payload['course'] : null,
            isset($payload['id_goods']) ? (int)$payload['id_goods'] : null
        );
        $source = trim((string)($payload['source'] ?? 'avo'));
        $sourceRef = trim((string)($payload['source_ref'] ?? $payload['order_ref'] ?? ''));
        $avoContactId = (int)($payload['id_contact'] ?? 0);
        $sendEmail = $this->parseSendEmail($payload['send_email'] ?? null);
        $externalKeys = PaymentExternalKey::collectFromPayload($payload);

        if ($source === '') {
            $source = 'avo';
        }

        if ($email === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'email_required']);
        }

        if ($courseSlug === null || $courseSlug === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'course_required']);
        }

        if ($sourceRef !== '' && trim((string)($payload['id_account'] ?? '')) === '') {
            $payload['id_account'] = $sourceRef;
        }

        $pdo = wwm_pdo();

        // Tilda already granted (keys in invoice comment) → no second access/email.
        if ($externalKeys !== [] && PaymentExternalKey::anyExist($pdo, $externalKeys)) {
            $user = User::findByEmail($pdo, strtolower($email));
            $userId = $user !== null ? (int)$user['id'] : 0;
            $paymentRecorded = false;
            if ($userId > 0) {
                PaymentExternalKey::storeMany($pdo, $externalKeys, $userId, $courseSlug, $source);
                $timeline = AvoAccountRow::accessTimelineIso($payload, true);
                $paymentRecorded = $this->recordPaymentQuietly(
                    $pdo,
                    $userId,
                    $courseSlug,
                    $source,
                    $payload,
                    $timeline['ordered'],
                    $timeline['paid'],
                    $avoContactId > 0 ? $avoContactId : null
                );
            }
            wwm_json_response(200, [
                'ok' => true,
                'status' => 'already_paid',
                'already_paid' => true,
                'paid_granted' => false,
                'email_sent' => false,
                'course_slug' => $courseSlug,
                'user_id' => $userId > 0 ? $userId : null,
                'payment_recorded' => $paymentRecorded,
            ]);
        }

        try {
            $timeline = AvoAccountRow::accessTimelineIso($payload, true);
            $result = (new PaidAccess())->grant(
                $email,
                $name,
                $courseSlug,
                $source,
                $sourceRef !== '' ? $sourceRef : null,
                (new AvoUtmResolver())->resolve($payload),
                $avoContactId > 0 ? $avoContactId : null,
                $sendEmail,
                $timeline['ordered'],
                $timeline['paid']
            );
            $userId = (int)$result['user_id'];
            if ($externalKeys !== []) {
                PaymentExternalKey::storeMany($pdo, $externalKeys, $userId, $courseSlug, $source);
            }
            AvoAdvertisingSnapshot::captureFromPayload($pdo, $userId, $payload);
            StudentAttribution::backfillUtmStatus($pdo, $userId);
            $paymentRecorded = PaymentRecorder::recordFromWebhook(
                $pdo,
                $userId,
                $courseSlug,
                $source,
                $payload,
                $timeline['ordered'],
                $timeline['paid'],
                $avoContactId > 0 ? $avoContactId : null
            );
            if ($paymentRecorded && empty($result['already_paid'])) {
                \Wwm\Services\EmailAutomationEnrollment::onPaymentRecorded(
                    $userId,
                    $courseSlug,
                    $timeline['paid']
                );
            }
        } catch (\InvalidArgumentException $e) {
            wwm_json_response(400, ['ok' => false, 'error' => 'invalid_email']);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Course not found') {
                wwm_json_response(404, ['ok' => false, 'error' => 'course_not_found']);
            }
            wwm_log('payment webhook failed: ' . $e->getMessage());
            wwm_json_response(500, ['ok' => false, 'error' => 'grant_failed']);
        }

        $status = !empty($result['already_paid']) ? 'already_paid' : 'ok';
        wwm_json_response(200, [
            'ok' => true,
            'status' => $status,
            'payment_recorded' => $paymentRecorded ?? false,
        ] + $result);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function respondStatus(string $status, array $extra = []): void
    {
        // Spec: HTTP 200 body ok | already_paid (also JSON for clients that parse it).
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        // Prefer plain status word; keep JSON details in log only when needed.
        if ($extra !== []) {
            wwm_log('payment webhook status=' . $status . ' ' . json_encode($extra, JSON_UNESCAPED_UNICODE));
        }
        echo $status === 'already_paid' ? 'already_paid' : 'ok';
        exit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordPaymentQuietly(
        \PDO $pdo,
        int $userId,
        string $courseSlug,
        string $source,
        array $payload,
        ?string $orderedAt,
        ?string $paidAt,
        ?int $avoContactId
    ): bool {
        try {
            return PaymentRecorder::recordFromWebhook(
                $pdo,
                $userId,
                $courseSlug,
                $source,
                $payload,
                $orderedAt,
                $paidAt,
                $avoContactId
            );
        } catch (\Throwable $e) {
            wwm_log('payment record skipped: ' . $e->getMessage());

            return false;
        }
    }

    private function parseSendEmail(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }
}
