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

    /** Default max lateness for send_template before skipped_stale (seconds). */
    public const DEFAULT_STALE_SECONDS = 7200;

    /**
     * Cron tick: process due runs with a process-wide lock and at most one email per run.
     */
    public static function processDue(?\PDO $pdo = null): int
    {
        $pdo ??= wwm_pdo();
        $lockDir = WWM_ROOT . '/data/locks';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        $lockPath = $lockDir . '/email-automations.lock';
        $lockFh = @fopen($lockPath, 'c+');
        if ($lockFh === false) {
            wwm_log('automation processDue: cannot open lock file');

            return 0;
        }
        if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
            fclose($lockFh);
            wwm_log('automation processDue skipped: lock busy at=' . gmdate('c'));

            return 0;
        }

        $processed = 0;
        try {
            $runs = EmailAutomationRun::dueRuns($pdo, self::BATCH_SIZE);
            foreach ($runs as $run) {
                try {
                    self::processRun($pdo, $run, ['max_emails' => 1]);
                    $processed++;
                } catch (\Throwable $e) {
                    wwm_log('automation run ' . ($run['id'] ?? '?') . ' failed: ' . $e->getMessage());
                }
            }
            wwm_log(sprintf(
                'automation processDue pulse at=%s processed=%d due=%d',
                gmdate('c'),
                $processed,
                count($runs)
            ));
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }

        return $processed;
    }

    /**
     * Admin: skip the current pause and continue until the next pause / end.
     * For condition forks, pass $forcedBranch = yes|no (null = evaluate automatically).
     *
     * @return array{ok: bool, error?: string, node?: string}
     */
    public static function advanceManually(\PDO $pdo, int $runId, ?string $forcedBranch = null): array
    {
        $run = EmailAutomationRun::find($pdo, $runId);
        if ($run === null || (string)($run['status'] ?? '') !== 'active') {
            return ['ok' => false, 'error' => 'run_not_active'];
        }

        $automation = EmailAutomation::find($pdo, (int)$run['automation_id']);
        if ($automation === null || !(int)$automation['is_active'] || EmailAutomation::isArchived($automation)) {
            return ['ok' => false, 'error' => 'automation_inactive'];
        }

        $def = EmailAutomation::definition($automation);
        if ($def === null) {
            return ['ok' => false, 'error' => 'invalid_definition'];
        }

        if ($forcedBranch !== null && $forcedBranch !== '') {
            $forcedBranch = strtolower(trim($forcedBranch));
            if (!in_array($forcedBranch, ['yes', 'no', 'auto'], true)) {
                return ['ok' => false, 'error' => 'invalid_branch'];
            }
            if ($forcedBranch === 'auto') {
                $forcedBranch = null;
            }
        } else {
            $forcedBranch = null;
        }

        $nodeId = (string)$run['current_node_id'];
        $node = $def['nodes'][$nodeId] ?? null;
        if (!is_array($node)) {
            return ['ok' => false, 'error' => 'invalid_node'];
        }

        $context = EmailAutomationRun::context($run);
        unset($context['manual_branch']);

        $type = (string)($node['type'] ?? '');
        $forkNodeId = $nodeId;
        if ($type === 'delay') {
            $next = self::nextNode($def, $nodeId, 'next');
            if ($next === null || $next === '') {
                EmailAutomationRun::complete($pdo, (int)$run['id']);

                return ['ok' => true, 'node' => 'end'];
            }
            $armed = is_array($context['delay_until'] ?? null) ? $context['delay_until'] : [];
            unset($armed[$nodeId]);
            $context['delay_until'] = $armed;
            $forkNodeId = $next;
            self::logStep($pdo, $run, $nodeId, 'delay', 'manual_skip', 'admin_advance');
            $forkNode = $def['nodes'][$forkNodeId] ?? null;
            if ($forcedBranch !== null && is_array($forkNode) && (string)($forkNode['type'] ?? '') === 'condition') {
                $context['manual_branch'] = $forcedBranch;
            }
            EmailAutomationRun::saveProgress($pdo, (int)$run['id'], $next, gmdate('c'), $context);
            $run = EmailAutomationRun::find($pdo, $runId);
            if ($run === null) {
                return ['ok' => false, 'error' => 'run_not_active'];
            }
        } else {
            $forkNode = $def['nodes'][$forkNodeId] ?? null;
            if ($forcedBranch !== null && is_array($forkNode) && (string)($forkNode['type'] ?? '') === 'condition') {
                $context['manual_branch'] = $forcedBranch;
            }
            // Ensure due now so processRun continues from this node.
            EmailAutomationRun::saveProgress(
                $pdo,
                (int)$run['id'],
                $nodeId,
                gmdate('c'),
                $context
            );
            $run = EmailAutomationRun::find($pdo, $runId);
            if ($run === null) {
                return ['ok' => false, 'error' => 'run_not_active'];
            }
        }

        try {
            self::processRun($pdo, $run);
        } catch (\Throwable $e) {
            wwm_log('automation manual advance run=' . $runId . ' failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'advance_failed'];
        }

        $after = EmailAutomationRun::find($pdo, $runId);

        return [
            'ok' => true,
            'node' => is_array($after) ? (string)($after['current_node_id'] ?? '') : '',
        ];
    }

    /**
     * Branches leaving a node (yes/no/…) — used in admin UI for manual advance.
     *
     * @param array<string, mixed> $def
     * @return list<string>
     */
    public static function forkBranches(array $def, string $nodeId): array
    {
        $branches = [];
        foreach ($def['edges'] ?? [] as $edge) {
            if (!is_array($edge) || (string)($edge['from'] ?? '') !== $nodeId) {
                continue;
            }
            $branch = (string)($edge['branch'] ?? 'next');
            if ($branch === 'next' || $branch === '') {
                continue;
            }
            if (!in_array($branch, $branches, true)) {
                $branches[] = $branch;
            }
        }

        return $branches;
    }

    /**
     * If the run is on a delay, the node it would enter after a push.
     *
     * @param array<string, mixed> $def
     */
    public static function nodeAfterDelay(array $def, string $delayNodeId): ?string
    {
        $next = self::nextNode($def, $delayNodeId, 'next');

        return ($next !== null && $next !== '') ? $next : null;
    }

    /**
     * Condition node the admin must choose a branch for when pushing this run, if any.
     *
     * @param array<string, mixed> $def
     */
    public static function pendingForkNodeId(array $def, string $currentNodeId): ?string
    {
        $node = $def['nodes'][$currentNodeId] ?? null;
        if (!is_array($node)) {
            return null;
        }
        $type = (string)($node['type'] ?? '');
        if ($type === 'condition' && self::forkBranches($def, $currentNodeId) !== []) {
            return $currentNodeId;
        }
        if ($type === 'delay') {
            $next = self::nodeAfterDelay($def, $currentNodeId);
            if ($next === null) {
                return null;
            }
            $nextNode = $def['nodes'][$next] ?? null;
            if (is_array($nextNode)
                && (string)($nextNode['type'] ?? '') === 'condition'
                && self::forkBranches($def, $next) !== []
            ) {
                return $next;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $run
     * @param array{max_emails?: int|null} $options max_emails=1 for cron; null = unlimited (enroll / admin)
     */
    public static function processRun(\PDO $pdo, array $run, array $options = []): void
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
        $maxEmails = array_key_exists('max_emails', $options) ? $options['max_emails'] : null;
        $emailsSent = 0;

        while ($nodeId !== '' && $steps < 24) {
            $steps++;
            $node = $def['nodes'][$nodeId] ?? null;
            if (!is_array($node)) {
                EmailAutomationRun::complete($pdo, (int)$run['id']);

                return;
            }

            $type = (string)($node['type'] ?? '');
            if ($type === 'end') {
                $outcome = trim((string)($node['outcome'] ?? 'completed'));
                if ($outcome === '') {
                    $outcome = 'completed';
                }
                self::logStep($pdo, $run, $nodeId, $type, null, 'outcome=' . $outcome);
                EmailAutomationRun::complete($pdo, (int)$run['id']);

                return;
            }

            if ($type === 'delay') {
                $next = self::handleDelay($pdo, $run, $def, $nodeId, $node);
                if ($next === null) {
                    return;
                }
                $nodeId = $next;
                continue;
            }

            if ($type === 'condition') {
                $context = EmailAutomationRun::context($run);
                $manual = strtolower(trim((string)($context['manual_branch'] ?? '')));
                if (in_array($manual, ['yes', 'no'], true)) {
                    $branch = $manual;
                    unset($context['manual_branch']);
                    $run['context_json'] = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                    EmailAutomationRun::saveProgress(
                        $pdo,
                        (int)$run['id'],
                        $nodeId,
                        (string)($run['next_run_at'] ?? gmdate('c')),
                        $context
                    );
                    self::logStep(
                        $pdo,
                        $run,
                        $nodeId,
                        $type,
                        $branch,
                        'manual:' . (string)($node['condition'] ?? '')
                    );
                } else {
                    $branch = self::evaluateCondition($pdo, $userId, $courseSlug, $node) ? 'yes' : 'no';
                    self::logStep($pdo, $run, $nodeId, $type, $branch, (string)($node['condition'] ?? ''));
                }
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

            if ($type === 'send_template') {
                // One outbound email per cron tick; park here for the next processDue.
                if ($maxEmails !== null && $emailsSent >= (int)$maxEmails) {
                    $context = EmailAutomationRun::context($run);
                    EmailAutomationRun::saveProgress(
                        $pdo,
                        (int)$run['id'],
                        $nodeId,
                        gmdate('c'),
                        $context
                    );

                    return;
                }
                $result = self::handleSendTemplate(
                    $pdo,
                    $run,
                    $def,
                    $automation,
                    $user,
                    $userId,
                    $courseSlug,
                    $nodeId,
                    $node
                );
                if ($result['stop']) {
                    return;
                }
                if (!empty($result['sent'])) {
                    $emailsSent++;
                }
                $nodeId = (string)($result['next'] ?? '');
                if ($nodeId === '') {
                    return;
                }
                // Continue so a following delay can be armed in the same tick.
                continue;
            }

            self::logStep($pdo, $run, $nodeId, $type, null, null);

            if ($type === 'grant_demo') {
                $context = EmailAutomationRun::context($run);
                $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? $courseSlug)) ?: $courseSlug;
                $state = Access::courseState($pdo, $userId, $slug);
                $skipPaid = !isset($node['skip_if_paid']) || !empty($node['skip_if_paid']);
                $skipDemo = !isset($node['skip_if_demo_active']) || !empty($node['skip_if_demo_active']);

                if (!empty($context['demo_pre_granted'])) {
                    self::logStep($pdo, $run, $nodeId, $type, 'skipped', 'demo_pre_granted');
                } elseif ($skipPaid && !empty($state['has_paid'])) {
                    self::logStep($pdo, $run, $nodeId, $type, 'skipped', 'already_paid');
                } elseif ($skipDemo && !empty($state['demo_active'])) {
                    self::logStep($pdo, $run, $nodeId, $type, 'skipped', 'demo_already_active');
                } else {
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
                }
            } elseif ($type === 'revoke_demo') {
                // Demo access expires by course demo_hours / expires_at — early revoke is not used.
                self::logStep(
                    $pdo,
                    $run,
                    $nodeId,
                    $type,
                    'skipped',
                    'demo_expires_by_timer'
                );
                wwm_log(sprintf(
                    'automation revoke_demo skipped run=%d user=%d (demo expires automatically)',
                    (int)$run['id'],
                    $userId
                ));
            } elseif ($type === 'notify_staff') {
                self::notifyStaff($automation, $user, $courseSlug, $node);
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
     * Send (or skip) a template node. On success: log sent, then advance current_node_id
     * before returning so a crash after send cannot resend the same step.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $def
     * @param array<string, mixed> $automation
     * @param array<string, mixed> $user
     * @param array<string, mixed> $node
     * @return array{next: ?string, sent: bool, stop: bool}
     */
    private static function handleSendTemplate(
        \PDO $pdo,
        array &$run,
        array $def,
        array $automation,
        array $user,
        int $userId,
        string $courseSlug,
        string $nodeId,
        array $node
    ): array {
        $template = (string)($node['template'] ?? '');
        $templateCourse = preg_replace('/[^a-z0-9\-]/', '', (string)($node['course_slug'] ?? $courseSlug)) ?: $courseSlug;
        $next = self::nextNode($def, $nodeId, 'next');

        $enrolledAt = (string)($run['enrolled_at'] ?? '');
        if (EmailAutomationStepEvent::hasBranch($pdo, (int)$run['id'], $nodeId, 'sent', $enrolledAt !== '' ? $enrolledAt : null)) {
            self::advanceAfterSend($pdo, $run, $nodeId, $next, false);

            return ['next' => $next, 'sent' => false, 'stop' => $next === null];
        }

        if (!empty($node['skip_if_paid_course']) && $templateCourse !== '') {
            $paidState = Access::courseState($pdo, $userId, $templateCourse);
            if (!empty($paidState['has_paid'])) {
                self::logStep($pdo, $run, $nodeId, 'send_template', 'skipped', 'already_paid_course');
                self::advanceAfterSend($pdo, $run, $nodeId, $next, false);
                if ($next === null) {
                    EmailAutomationRun::complete($pdo, (int)$run['id']);

                    return ['next' => null, 'sent' => false, 'stop' => true];
                }

                return ['next' => $next, 'sent' => false, 'stop' => false];
            }
        }

        $staleReason = self::staleSkipReason($run, $node, $template);
        if ($staleReason !== null) {
            self::logStep($pdo, $run, $nodeId, 'send_template', 'skipped_stale', $staleReason);
            self::advanceAfterSend($pdo, $run, $nodeId, $next, false);
            if ($next === null) {
                EmailAutomationRun::complete($pdo, (int)$run['id']);

                return ['next' => null, 'sent' => false, 'stop' => true];
            }

            return ['next' => $next, 'sent' => false, 'stop' => false];
        }

        if ($template === '' || EmailTemplateCatalog::find($template) === null) {
            self::logStep($pdo, $run, $nodeId, 'send_template', 'skipped', 'missing_template');
            self::advanceAfterSend($pdo, $run, $nodeId, $next, false);
            if ($next === null) {
                EmailAutomationRun::complete($pdo, (int)$run['id']);

                return ['next' => null, 'sent' => false, 'stop' => true];
            }

            return ['next' => $next, 'sent' => false, 'stop' => false];
        }

        try {
            if (MarketingEmailDelivery::isMarketingTemplate($template)) {
                if (EmailSuppression::isSuppressed($pdo, (string)$user['email'])) {
                    self::logStep($pdo, $run, $nodeId, 'send_template', 'skipped', 'suppressed');
                    EmailAutomationRun::complete($pdo, (int)$run['id']);

                    return ['next' => null, 'sent' => false, 'stop' => true];
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
            self::logStep($pdo, $run, $nodeId, 'send_template', 'failed', $e->getMessage());
            // Do not advance — retry next cron tick.
            return ['next' => null, 'sent' => false, 'stop' => true];
        }

        if ($template === 'sale_demo_discount_24h') {
            $context = EmailAutomationRun::context($run);
            $context['coupon_expires_at'] = gmdate('c', time() + 24 * 3600);
            $context['coupon_code'] = trim((string)(wwm_config()['sale_coupon_code'] ?? 'SPECWWM4')) ?: 'SPECWWM4';
            $run['context_json'] = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            EmailAutomationRun::saveProgress(
                $pdo,
                (int)$run['id'],
                $nodeId,
                (string)($run['next_run_at'] ?? gmdate('c')),
                $context
            );
        }

        // Record send before advancing so a crash after this cannot double-send.
        self::logStep($pdo, $run, $nodeId, 'send_template', 'sent', $template);
        self::advanceAfterSend($pdo, $run, $nodeId, $next, true);
        if ($next === null) {
            EmailAutomationRun::complete($pdo, (int)$run['id']);

            return ['next' => null, 'sent' => true, 'stop' => true];
        }

        return ['next' => $next, 'sent' => true, 'stop' => false];
    }

    /**
     * @param array<string, mixed> $run
     */
    private static function advanceAfterSend(
        \PDO $pdo,
        array &$run,
        string $fromNodeId,
        ?string $next,
        bool $sent
    ): void {
        if ($next === null || $next === '') {
            return;
        }
        $context = EmailAutomationRun::context($run);
        $when = gmdate('c');
        $run['current_node_id'] = $next;
        $run['next_run_at'] = $when;
        $run['context_json'] = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        EmailAutomationRun::saveProgress($pdo, (int)$run['id'], $next, $when, $context);
        unset($fromNodeId, $sent);
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $node
     */
    private static function staleSkipReason(array $run, array $node, string $template): ?string
    {
        $dueRaw = (string)($run['next_run_at'] ?? '');
        $dueTs = $dueRaw !== '' ? strtotime($dueRaw) : false;
        if ($dueTs === false) {
            return null;
        }
        $lateness = time() - $dueTs;
        if ($lateness <= 0) {
            return null;
        }

        $max = self::staleLimitSeconds($node, $template);
        if ($lateness <= $max) {
            return null;
        }

        return sprintf('lateness=%ds limit=%ds template=%s', $lateness, $max, $template);
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function staleLimitSeconds(array $node, string $template): int
    {
        if (isset($node['stale_after_seconds'])) {
            return max(0, (int)$node['stale_after_seconds']);
        }

        $byTemplate = self::templateStaleSeconds($template);
        if ($byTemplate !== null) {
            return $byTemplate;
        }

        $cfg = wwm_config()['email_automations'] ?? [];
        if (is_array($cfg) && isset($cfg['stale_after_seconds'])) {
            return max(0, (int)$cfg['stale_after_seconds']);
        }

        return self::DEFAULT_STALE_SECONDS;
    }

    private static function templateStaleSeconds(string $template): ?int
    {
        // Time-copy in the body must still be roughly true.
        return match ($template) {
            'sale_demo_discount_24h' => 7200,
            'sale_demo_discount_3h' => 3600,
            'reminder_demo_expiring' => 7200,
            default => null,
        };
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
            'has_active_demo' => !empty($stateMap[$slug]['demo_active']),
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
        // AVO tag alone is not a view: signup auto-login used to set it without a lesson open.
        $engagement = (new StudentEngagement())->forEmail((string)$user['email'], $courseSlug);

        return !empty($engagement['demo_lesson_opened']);
    }

    /**
     * Stay on the delay node until next_run_at. Return next node id after wake, or null to stop.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $def
     * @param array<string, mixed> $node
     */
    private static function handleDelay(\PDO $pdo, array &$run, array $def, string $nodeId, array $node): ?string
    {
        $next = self::nextNode($def, $nodeId, 'next');
        if ($next === null) {
            EmailAutomationRun::complete($pdo, (int)$run['id']);

            return null;
        }

        $context = EmailAutomationRun::context($run);
        $armed = is_array($context['delay_until'] ?? null) ? $context['delay_until'] : [];
        $until = (string)($armed[$nodeId] ?? '');
        $wakeTs = $until !== '' ? strtotime($until) : false;
        $dueTs = strtotime((string)($run['next_run_at'] ?? ''));
        $now = time();

        if ($until !== '') {
            $stillSleeping = ($wakeTs !== false && $wakeTs > $now)
                && ($dueTs === false || $dueTs > $now);
            if ($stillSleeping) {
                return null;
            }

            unset($armed[$nodeId]);
            $context['delay_until'] = $armed;
            $run['context_json'] = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $run['current_node_id'] = $next;
            $run['next_run_at'] = gmdate('c');
            self::logStep($pdo, $run, $nodeId, 'delay', 'done', $until !== '' ? 'until=' . $until : null);
            EmailAutomationRun::saveProgress($pdo, (int)$run['id'], $next, $run['next_run_at'], $context);
            wwm_log(sprintf('automation delay done run=%d node=%s', (int)$run['id'], $nodeId));

            return $next;
        }

        $seconds = max(0, (int)($node['seconds'] ?? 0));
        $fromKey = trim((string)($node['wake_from_context'] ?? ''));
        if ($fromKey !== '') {
            $base = strtotime((string)($context[$fromKey] ?? ''));
            $lead = max(0, (int)($node['wake_lead_seconds'] ?? 0));
            if ($base !== false && $base > 0) {
                $seconds = max(0, $base - $lead - $now);
            }
        }
        if ($seconds === 0) {
            // Zero-length delay (legacy passthrough nodes): continue immediately.
            self::logStep($pdo, $run, $nodeId, 'delay', 'done', 'until=immediate');
            $run['current_node_id'] = $next;
            $run['next_run_at'] = gmdate('c');
            EmailAutomationRun::saveProgress($pdo, (int)$run['id'], $next, $run['next_run_at'], $context);

            return $next;
        }
        $wakeAt = gmdate('c', $now + $seconds);
        $armed[$nodeId] = $wakeAt;
        $context['delay_until'] = $armed;
        $run['context_json'] = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $run['current_node_id'] = $nodeId;
        $run['next_run_at'] = $wakeAt;
        self::logStep($pdo, $run, $nodeId, 'delay', 'scheduled', 'until=' . $wakeAt);
        EmailAutomationRun::saveProgress($pdo, (int)$run['id'], $nodeId, $wakeAt, $context);
        wwm_log(sprintf('automation delay run=%d sleep=%ds until=%s', (int)$run['id'], $seconds, $wakeAt));

        return null;
    }

    /**
     * @param array<string, mixed> $automation
     * @param array<string, mixed> $user
     * @param array<string, mixed> $node
     */
    private static function notifyStaff(array $automation, array $user, string $courseSlug, array $node): void
    {
        $stepLabel = trim((string)($node['label'] ?? ''));
        if ($stepLabel === '') {
            $stepLabel = 'notify_staff';
        }

        $vars = [
            '{{student_name}}' => trim((string)($user['name'] ?? '')),
            '{{student_email}}' => (string)$user['email'],
            '{{course_slug}}' => $courseSlug,
            '{{automation_title}}' => (string)($automation['title'] ?? ''),
            '{{automation_slug}}' => (string)($automation['slug'] ?? ''),
            '{{step_label}}' => $stepLabel,
            '{{admin_student_url}}' => wwm_base_url() . '/admin/students?q=' . rawurlencode((string)$user['email']),
        ];

        $defaultBody = sprintf(
            "Student: %s <%s>\nCourse: %s\nFlow: %s (%s)\nStep: %s\n\nAdmin:\n%s",
            $vars['{{student_name}}'],
            $vars['{{student_email}}'],
            $courseSlug,
            $vars['{{automation_title}}'],
            $vars['{{automation_slug}}'],
            $stepLabel,
            $vars['{{admin_student_url}}']
        );

        $bodyTemplate = trim((string)($node['notify_body'] ?? ''));
        $body = $bodyTemplate !== '' ? self::applyNotifyTemplate($bodyTemplate, $vars) : $defaultBody;

        $subjectTemplate = trim((string)($node['notify_subject'] ?? ''));
        $subject = $subjectTemplate !== ''
            ? self::applyNotifyTemplate($subjectTemplate, $vars)
            : 'WWM automation: ' . $stepLabel;

        wwm_log('automation staff notify: ' . str_replace("\n", ' ', $body));

        $recipients = StaffNotifyRecipients::resolveFromNode($node);
        if ($recipients === []) {
            $recipients = self::resolveStaffRecipientsFallback();
        }
        if ($recipients === []) {
            return;
        }

        $cfg = wwm_config()['mail'] ?? [];
        if (empty($cfg['enabled'])) {
            return;
        }

        foreach ($recipients as $to) {
            Mailer::send($to, $subject, $body . "\n");
        }
    }

    /**
     * @return list<string>
     */
    private static function resolveStaffRecipientsFallback(): array
    {
        $cfg = wwm_config()['mail'] ?? [];
        $fallback = trim((string)($cfg['staff_notify_email'] ?? ''));
        if ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
            return [$fallback];
        }

        return [];
    }

    /**
     * @param array<string, string> $vars
     */
    private static function applyNotifyTemplate(string $template, array $vars): string
    {
        return str_replace(array_keys($vars), array_values($vars), $template);
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
