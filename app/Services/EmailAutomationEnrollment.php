<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\EmailAutomation;
use Wwm\Models\EmailAutomationRun;
use Wwm\Models\EmailAutomationStepEvent;
use Wwm\Services\EmailAutomationRunner;

final class EmailAutomationEnrollment
{
    public const BULK_ENROLL_LIMIT = 5000;

    /**
     * After /api/demo or admin demo grant: enroll into every active demo funnel for that course.
     */
    public static function onDemoGranted(int $userId, string $courseSlug): void
    {
        $courseSlug = preg_replace('/[^a-z0-9\-]/', '', $courseSlug) ?: '';
        if ($courseSlug === '') {
            return;
        }

        $pdo = wwm_pdo();
        foreach (EmailAutomation::findActiveByEntryMode($pdo, EmailAutomation::ENTRY_DEMO_GRANT, $courseSlug) as $automation) {
            self::enrollFromAutomation($pdo, $automation, $userId, $courseSlug, [
                'demo_pre_granted' => true,
            ], 'demo_pre_granted');
        }
    }

    /**
     * After a paid order is recorded (payment webhook).
     */
    public static function onPaymentRecorded(int $userId, string $courseSlug, ?string $paidAt): void
    {
        if ($paidAt === null || trim($paidAt) === '') {
            return;
        }

        $pdo = wwm_pdo();
        $courseSlug = preg_replace('/[^a-z0-9\-]/', '', $courseSlug) ?: '';

        foreach (EmailAutomation::findActiveByEntryMode($pdo, EmailAutomation::ENTRY_PAYMENT_ANY) as $automation) {
            self::enrollFromAutomation($pdo, $automation, $userId, $courseSlug, [
                'trigger' => 'payment',
            ], 'payment_any');
        }

        if ($courseSlug !== '') {
            foreach (EmailAutomation::findActiveByEntryMode($pdo, EmailAutomation::ENTRY_PAYMENT_COURSE, $courseSlug) as $automation) {
                self::enrollFromAutomation($pdo, $automation, $userId, $courseSlug, [
                    'trigger' => 'payment',
                    'payment_course' => $courseSlug,
                ], 'payment_course:' . $courseSlug);
            }
        }
    }

    /**
     * Admin or API: enroll a student into a manual (or any) flow.
     *
     * @return int|null run id, null when automation missing or inactive
     */
    public static function enrollManual(int $automationId, int $userId, string $courseContext = ''): ?int
    {
        $pdo = wwm_pdo();
        $automation = EmailAutomation::find($pdo, $automationId);
        if ($automation === null || !(int)$automation['is_active'] || EmailAutomation::isArchived($automation)) {
            return null;
        }

        $courseContext = preg_replace('/[^a-z0-9\-]/', '', $courseContext) ?: '';
        if ($courseContext === '') {
            $courseContext = preg_replace('/[^a-z0-9\-]/', '', (string)($automation['course_slug'] ?? '')) ?: '';
        }

        return self::enrollFromAutomation($pdo, $automation, $userId, $courseContext, [
            'trigger' => 'manual',
        ], 'manual');
    }

    /**
     * @param list<int> $userIds
     * @return array{created: int, already_active: int, failed: int, total: int}
     */
    public static function enrollBulk(int $automationId, array $userIds, string $courseContext = ''): array
    {
        $created = 0;
        $alreadyActive = 0;
        $failed = 0;
        $pdo = wwm_pdo();
        $automation = EmailAutomation::find($pdo, $automationId);
        if ($automation === null || !(int)$automation['is_active'] || EmailAutomation::isArchived($automation)) {
            return [
                'created' => 0,
                'already_active' => 0,
                'failed' => count($userIds),
                'total' => count($userIds),
            ];
        }

        foreach ($userIds as $userId) {
            $userId = (int)$userId;
            if ($userId <= 0) {
                continue;
            }
            $hadActive = EmailAutomationRun::findActive($pdo, $automationId, $userId) !== null;
            $runId = self::enrollManual($automationId, $userId, $courseContext);
            if ($runId === null) {
                $failed++;
                continue;
            }
            if ($hadActive) {
                $alreadyActive++;
            } else {
                $created++;
            }
        }

        return [
            'created' => $created,
            'already_active' => $alreadyActive,
            'failed' => $failed,
            'total' => count($userIds),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function enrollFromAutomation(
        \PDO $pdo,
        array $automation,
        int $userId,
        string $courseSlug,
        array $context,
        string $detail
    ): ?int {
        $def = EmailAutomation::definition($automation);
        if ($def === null) {
            return null;
        }

        $entry = self::resolveEntryNodeId($def);
        if ($entry === null) {
            return null;
        }

        $automationId = (int)$automation['id'];
        $existing = EmailAutomationRun::findActive($pdo, $automationId, $userId);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $entryMode = EmailAutomation::normalizeEntryMode((string)($automation['entry_mode'] ?? ''));
        if (
            in_array($entryMode, [EmailAutomation::ENTRY_PAYMENT_ANY, EmailAutomation::ENTRY_PAYMENT_COURSE], true)
            && EmailAutomationRun::hasAnyRun($pdo, $automationId, $userId)
        ) {
            return null;
        }

        $runId = EmailAutomationRun::enroll(
            $pdo,
            (int)$automation['id'],
            $userId,
            $courseSlug,
            $entry,
            $context
        );

        $entryNode = $def['nodes'][$entry] ?? null;
        EmailAutomationStepEvent::record($pdo, [
            'automation_id' => (int)$automation['id'],
            'run_id' => $runId,
            'user_id' => $userId,
            'node_id' => $entry,
            'node_type' => is_array($entryNode) ? (string)($entryNode['type'] ?? 'entry') : 'entry',
            'branch' => 'enrolled',
            'detail' => $detail,
        ]);

        wwm_log(sprintf(
            'automation enrolled automation_id=%d user_id=%d course=%s node=%s detail=%s',
            (int)$automation['id'],
            $userId,
            $courseSlug,
            $entry,
            $detail
        ));

        $run = EmailAutomationRun::find($pdo, $runId);
        if ($run !== null) {
            EmailAutomationRunner::processRun($pdo, $run);
        }

        return $runId;
    }

    /**
     * First block only: trigger / start / node with no incoming edge.
     * Never jump to a mid-flow condition (e.g. gate_paid_any_1).
     *
     * @param array<string, mixed> $def
     */
    private static function resolveEntryNodeId(array $def): ?string
    {
        $nodes = $def['nodes'] ?? [];
        if (!is_array($nodes) || $nodes === []) {
            return null;
        }

        if (isset($nodes['start']) && is_array($nodes['start'])) {
            return 'start';
        }

        foreach ($nodes as $nodeId => $node) {
            if (is_array($node) && (string)($node['type'] ?? '') === 'trigger') {
                return (string)$nodeId;
            }
        }

        $incoming = [];
        foreach ($def['edges'] ?? [] as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $to = (string)($edge['to'] ?? '');
            if ($to !== '') {
                $incoming[$to] = true;
            }
        }
        foreach ($nodes as $nodeId => $node) {
            if (is_array($node) && !isset($incoming[(string)$nodeId])) {
                return (string)$nodeId;
            }
        }

        return null;
    }
}
