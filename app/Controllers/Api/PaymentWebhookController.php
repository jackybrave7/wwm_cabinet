<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

use Wwm\Services\AvoAccountRow;
use Wwm\Services\AvoAdvertisingSnapshot;
use Wwm\Services\AvoContactName;
use Wwm\Services\AvoUtmResolver;
use Wwm\Services\AvoWebhookPayload;
use Wwm\Services\DemoAccess;
use Wwm\Services\PaidAccess;
use Wwm\Services\PaymentRecorder;
use Wwm\Services\StudentAttribution;

final class PaymentWebhookController
{
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
            $pdo = wwm_pdo();
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
            if ($paymentRecorded) {
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

        wwm_json_response(200, ['ok' => true, 'payment_recorded' => $paymentRecorded ?? false] + $result);
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
