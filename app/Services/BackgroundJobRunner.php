<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\BackgroundJob;
use Wwm\Models\User;

final class BackgroundJobRunner
{
    public const BATCH_SIZE = 25;
    public const MAX_ATTEMPTS = 5;

    public static function processDue(?\PDO $pdo = null, int $limit = self::BATCH_SIZE): int
    {
        $pdo ??= wwm_pdo();
        $jobs = BackgroundJob::claimDue($pdo, $limit);
        $done = 0;

        foreach ($jobs as $job) {
            $id = (int)$job['id'];
            $type = (string)($job['job_type'] ?? '');
            $attempts = (int)($job['attempts'] ?? 0);
            try {
                self::runOne($pdo, $type, BackgroundJob::payload($job));
                BackgroundJob::markDone($pdo, $id);
                $done++;
            } catch (\Throwable $e) {
                $retry = $attempts < self::MAX_ATTEMPTS;
                BackgroundJob::markFailed($pdo, $id, $e->getMessage(), $retry);
                wwm_log(sprintf(
                    'background job id=%d type=%s failed attempt=%d retry=%s: %s',
                    $id,
                    $type,
                    $attempts,
                    $retry ? 'yes' : 'no',
                    $e->getMessage()
                ));
            }
        }

        if ($done > 0 || $jobs !== []) {
            wwm_log(sprintf(
                'background jobs pulse at=%s claimed=%d done=%d',
                gmdate('c'),
                count($jobs),
                $done
            ));
        }

        return $done;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function runOne(\PDO $pdo, string $type, array $payload): void
    {
        switch ($type) {
            case BackgroundJob::TYPE_LOGIN_FOLLOWUP:
                self::runLoginFollowup($pdo, $payload);
                return;
            case BackgroundJob::TYPE_AVO_LOGGED_IN:
                $userId = (int)($payload['user_id'] ?? 0);
                if ($userId <= 0) {
                    throw new \InvalidArgumentException('missing user_id');
                }
                AvoEngagementSync::onLogin($userId, false);
                return;
            case BackgroundJob::TYPE_AVO_DEMO_OPENED:
                $userId = (int)($payload['user_id'] ?? 0);
                if ($userId <= 0) {
                    throw new \InvalidArgumentException('missing user_id');
                }
                AvoEngagementSync::onDemoLessonOpen($userId, false);
                return;
            case BackgroundJob::TYPE_META_CAPI:
                MetaCapi::sendFromJob($pdo, $payload);
                return;
            default:
                throw new \InvalidArgumentException('unknown job_type=' . $type);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function runLoginFollowup(\PDO $pdo, array $payload): void
    {
        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('missing user_id');
        }

        $ip = isset($payload['ip']) ? trim((string)$payload['ip']) : '';
        if ($ip !== '') {
            $geo = GeoIp::lookup($ip);
            User::recordAttribution($pdo, $userId, [
                'ip' => $ip,
                'country' => $geo['country'],
                'city' => $geo['city'],
                'utm' => [],
            ]);
        }

        AvoEngagementSync::onLogin($userId, false);

        if (!empty($payload['backfill_utm'])) {
            StudentAttribution::backfillUtmFromAvo($pdo, $userId);
        }
    }
}
