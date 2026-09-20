<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\Access;
use Wwm\Models\EmailAutomation;
use Wwm\Models\EmailAutomationRun;
use Wwm\Models\EmailAutomationStepEvent;
use Wwm\Models\User;
use Wwm\Models\EmailSuppression;
use Wwm\Services\EmailTemplateCatalog;
use Wwm\Services\Mailer;
use Wwm\Services\MarketingEmailDelivery;

final class EmailAutomationRunner
{
    public const BATCH_SIZE = 40;

    public static function processDue(?\PDO $pdo = null): int
    {
        $pdo ??= wwm_pdo();
        $runs = EmailAutomationRun::dueRuns($pdo, self::BATCH_SIZE);
        $processed = 0;

        foreach ($runs as $run) {
            try {
                self::processRun($pdo, $run);
                $processed++;
            } catch (\Throwable $e) {
                wwm_log('automation run ' . ($run['id'] ?? '?') . ' failed: ' . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * @param array<string, mixed> $run
     */
    public static function processRun(\PDO $pdo, array $run): void
    {
        $automation = EmailAutomation::find($pdo, (int)$run['automation_id']);
        if ($automation === null || !(int)$automation['is_active']) {
            EmailAutomationRun::cancel($pdo, (int)$run['id']);

            return;
        }

        $def = EmailAutomation::definition($automation);
        if ($def === null) {
            EmailAutomationRun::cancel($pdo, (int)$run['id']);

            return;
        }

        $userId = (int)$run['user_id'];
        $user = User::findById($pdo, $userId);
        if ($user === null) {
            EmailAutomationRun::cancel($pdo, (int)$run['id']);

            return;
        }

        $courseSlug = (string)($run['course_slug'] ?? $automation['course_slug'] ?? '');
        $nodeId = (string)$run['current_node_id'];
        $steps = 0;

        while ($nodeId !== '' && $steps < 24) {
            $steps++;
            $node = $def['nodes'][$nodeId] ?? null;
            if (!is_array($node)) {
                EmailAutomationRun::complete($pdo, (int)$run['id']);

                return;
            }

            $type = (string)($node['type'] ?? '');
            if ($type === 'end') {
                self::logStep($pdo, $run, $nodeId, $type, null, 'completed');
                EmailAutomationRun::complete($pdo, (int)$run['id']);

                return;
            }

            if ($type === 'delay') {
                $seconds = max(0, (int)($node['seconds'] ?? 0));
                $next = self::nextNode($def, $nodeId, 'next');
                if ($next === null) {
                    EmailAutomationRun::complete($pdo, (int)$run['id']);

                    return;
                }
                $wakeAt = gmdate('c', time() + $seconds);
                self::logStep($pdo, $run, $nodeId, $type, 'scheduled', 'until=' . $wakeAt);
                EmailAutomationRun::saveProgress($pdo, (int)$run['id'], $next, $wakeAt);
                wwm_log(sprintf('automation delay run=%d sleep=%ds until=%s', (int)$run['id'], $seconds, $wakeAt));

                return;
            }

            if ($type === 'condition') {
                $branch = self::evaluateCondition($pdo, $userId, $courseSlug, $node) ? 'yes' : 'no';
                self::logStep($pdo, $run, $nodeId, $type, $branch, (string)($node['condition'] ?? ''));
                $next = self::nextNode($def, $nodeId, $branch) ?? self::nextNode($def, $nodeId, 'next');
                if ($next === null) {
                    EmailAutomationRun::complete($pdo, (int)$run['id']);

                    return;
                }
                $nodeId = $next;
                continue;
            }

            if ($type === 'trigger') {
                self::logStep($pdo, $run, $nodeId, $type, null, null);
                $next = self::nextNode($def, $nodeId, 'next');
                if ($next === null) {
                    EmailAutomationRun::complete($pdo, (int)$run['id']);

                    return;
                }
                $nodeId = $next;
                continue;
            }

            self::logStep($pdo, $run, $nodeId, $type, null, null);

            if ($type === 'grant_demo') {
                $context = EmailAutomationRun::context($run);
                if (empty($context['demo_pre_granted'])) {
                    $slug = (string)($node['course_slug'] ?? $courseSlug);
                    $sendEmail = !empty($node['send_email']);
                    try {
                        (new DemoAccess())->grant(
                            (string)$user['email'],
                            trim((string)($user['name'] ?? '')),
                            $slug,
                            'automation',
                            'automation-' . (int)$run['automation_id'],
                            [],
                            null,
                            null
                        );
                    } catch (\Throwable $e) {
                        wwm_log('automation grant_demo: ' . $e->getMessage());
                    }
                    if (!$sendEmail) {
                        // DemoAccess always emails on grant; flag reserved for future split.
                    }
                }
            } elseif ($type === 'send_template') {
                $template = (string)($node['template'] ?? '');
                $templateCourse = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? $courseSlug)) ?: $courseSlug;
                if ($template !== '' && EmailTemplateCatalog::find($template) !== null) {
                    try {
                        if (MarketingEmailDelivery::isMarketingTemplate($template)) {
                            if (EmailSuppression::isSuppressed($pdo, (string)$user['email'])) {
                                self::logStep($pdo, $run, $nodeId, $type, 'skipped', 'suppressed');
                                EmailAutomationRun::complete($pdo, (int)$run['id']);

                                return;
                            }
                            (new CabinetMail())->sendAutomationMarketingTemplate(
                                $template,
                                $user,
                                $templateCourse,
                                (int)$automation['id']
                            );
                        } else {
                            (new CabinetMail())->sendTemplate(
                                $template,
                                (string)$user['email'],
                                trim((string)($user['name'] ?? '')) ?: null,
                                $templateCourse
                            );
                        }
                    } catch (\Throwable $e) {
                        wwm_log('automation send_template ' . $template . ': ' . $e->getMessage());
                    }
                }
            } elseif ($type === 'revoke_demo') {
                $slug = (string)($node['course_slug'] ?? $courseSlug);
                Access::revoke($pdo, $userId, $slug, 'demo');
            } elseif ($type === 'notify_staff') {
                self::notifyStaff($automation, $user, $courseSlug, (string)($node['label'] ?? ''));
            } else {
                wwm_log('automation unknown node type ' . $type . ' id=' . $nodeId);
            }

            $next = self::nextNode($def, $nodeId, 'next');
            if ($next === null) {
                EmailAutomationRun::complete($pdo, (int)$run['id']);

                return;
            }
            $nodeId = $next;
        }

        EmailAutomationRun::saveProgress($pdo, (int)$run['id'], $nodeId, gmdate('c'));
    }

    /**
     * @param array<string, mixed> $def
     */
    private static function nextNode(array $def, string $fromId, string $branch): ?string
    {
        foreach ($def['edges'] ?? [] as $edge) {
            if (!is_array($edge) || (string)($edge['from'] ?? '') !== $fromId) {
                continue;
            }
            $edgeBranch = (string)($edge['branch'] ?? 'next');
            if ($edgeBranch === $branch || ($branch === 'next' && $edgeBranch === 'next')) {
                return (string)($edge['to'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function evaluateCondition(\PDO $pdo, int $userId, string $courseSlug, array $node): bool
    {
        $condition = (string)($node['condition'] ?? '');
        $slug = (string)($node['course_slug'] ?? $courseSlug);
        $stateMap = Access::stateMapForUser($pdo, $userId);

        return match ($condition) {
            'has_paid_any' => self::hasPaidAny($stateMap),
            'has_paid_course' => !empty($stateMap[$slug]['has_paid']),
            'demo_lesson_opened' => self::demoLessonOpened($pdo, $userId, $slug),
            default => false,
        };
    }

    /**
     * @param array<string, array{has_paid?: bool}> $stateMap
     */
    private static function hasPaidAny(array $stateMap): bool
    {
        foreach ($stateMap as $state) {
            if (!empty($state['has_paid'])) {
                return true;
            }
        }

        return false;
    }

    private static function demoLessonOpened(\PDO $pdo, int $userId, string $courseSlug): bool
    {
        $user = User::findById($pdo, $userId);
        if ($user === null) {
            return false;
        }
        if (!empty($user['avo_demo_opened_tagged'])) {
            return true;
        }
        $engagement = (new StudentEngagement())->forEmail((string)$user['email'], $courseSlug);

        return !empty($engagement['demo_lesson_opened']);
    }

    /**
     * @param array<string, mixed> $automation
     * @param array<string, mixed> $user
     */
    private static function notifyStaff(array $automation, array $user, string $courseSlug, string $label): void
    {
        $message = sprintf(
            'Automation «%s» (%s): student %s <%s>, course %s. Step: %s',
            (string)$automation['title'],
            (string)$automation['slug'],
            (string)($user['name'] ?? ''),
            (string)$user['email'],
            $courseSlug,
            $label !== '' ? $label : 'notify_staff'
        );
        wwm_log($message);

        $cfg = wwm_config()['mail'] ?? [];
        $to = trim((string)($cfg['staff_notify_email'] ?? ''));
        if ($to === '' || empty($cfg['enabled'])) {
            return;
        }

        Mailer::send($to, 'WWM automation notice', $message . "\n");
    }

    /**
     * @param array<string, mixed> $run
     */
    private static function logStep(
        \PDO $pdo,
        array $run,
        string $nodeId,
        string $nodeType,
        ?string $branch,
        ?string $detail
    ): void {
        EmailAutomationStepEvent::record($pdo, [
            'automation_id' => (int)$run['automation_id'],
            'run_id' => (int)$run['id'],
            'user_id' => (int)$run['user_id'],
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'branch' => $branch,
            'detail' => $detail,
        ]);
    }
}
