<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\EmailAutomation;
use Wwm\Models\EmailAutomationRun;
use Wwm\Models\EmailAutomationStepEvent;

final class EmailAutomationEnrollment
{
    /**
     * After /api/demo grant: start funnel after demo is already granted (skip duplicate grant + email).
     */
    public static function onDemoGranted(int $userId, string $courseSlug): void
    {
        $pdo = wwm_pdo();
        // Only automations with is_active = 1 in admin; draft flows never enroll or send.
        $automation = EmailAutomation::findActiveForCourse($pdo, $courseSlug);
        if ($automation === null) {
            return;
        }

        $def = EmailAutomation::definition($automation);
        if ($def === null) {
            return;
        }

        $entry = 'gate_paid_any_1';
        if (!isset($def['nodes'][$entry])) {
            $entry = 'start';
        }

        $runId = EmailAutomationRun::enroll(
            $pdo,
            (int)$automation['id'],
            $userId,
            $courseSlug,
            $entry,
            ['demo_pre_granted' => true]
        );

        $entryNode = $def['nodes'][$entry] ?? null;
        EmailAutomationStepEvent::record($pdo, [
            'automation_id' => (int)$automation['id'],
            'run_id' => $runId,
            'user_id' => $userId,
            'node_id' => $entry,
            'node_type' => is_array($entryNode) ? (string)($entryNode['type'] ?? 'entry') : 'entry',
            'branch' => 'enrolled',
            'detail' => 'demo_pre_granted',
        ]);

        wwm_log(sprintf(
            'automation enrolled automation_id=%d user_id=%d course=%s node=%s',
            (int)$automation['id'],
            $userId,
            $courseSlug,
            $entry
        ));
    }
}
