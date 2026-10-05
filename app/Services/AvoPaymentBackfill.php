<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\Payment;
use Wwm\Models\User;

/**
 * Sync paid AVO accounts into cabinet payments (+ optional paid access).
 */
final class AvoPaymentBackfill
{
    private const SOURCE = 'avo-backfill';

    /**
     * @param array{
     *   since_ts?: int,
     *   until_ts?: int,
     *   dry_run?: bool,
     *   limit?: int,
     *   pause_micros?: int,
     *   grant_access?: bool,
     *   record_payments?: bool,
     *   send_email?: bool,
     *   goods_id?: int
     * } $options
     * @return array<string, int|bool|string>
     */
    public function run(PDO $pdo, array $options): array
    {
        $dryRun = !array_key_exists('dry_run', $options) || !empty($options['dry_run']);
        $sinceTs = max(0, (int)($options['since_ts'] ?? 0));
        $untilTs = max(0, (int)($options['until_ts'] ?? 0));
        $limit = max(0, (int)($options['limit'] ?? 0));
        $pauseMicros = max(0, (int)($options['pause_micros'] ?? 80000));
        $grantAccess = !array_key_exists('grant_access', $options) || !empty($options['grant_access']);
        $recordPayments = !array_key_exists('record_payments', $options) || !empty($options['record_payments']);
        $sendEmail = !empty($options['send_email']);
        $goodsFilter = (int)($options['goods_id'] ?? 0);

        $stats = [
            'dry_run' => $dryRun,
            'disabled' => false,
            'since_utc' => $sinceTs > 0 ? gmdate('c', $sinceTs) : '',
            'until_utc' => $untilTs > 0 ? gmdate('c', $untilTs) : '',
            'goods_scanned' => 0,
            'accounts_fetched' => 0,
            'accounts_paid' => 0,
            'skipped_not_paid' => 0,
            'skipped_date' => 0,
            'skipped_email' => 0,
            'skipped_goods' => 0,
            'skipped_existing' => 0,
            'would_apply' => 0,
            'grants_applied' => 0,
            'payments_recorded' => 0,
            'errors' => 0,
        ];

        $client = new AvoClient();
        if (!$client->isEnabled()) {
            $stats['disabled'] = true;

            return $stats;
        }

        $goodsMap = AvoSalesLinks::goodsMap();
        if ($goodsMap === []) {
            throw new \RuntimeException('No id_goods → course mapping in courses/config');
        }

        if ($goodsFilter > 0) {
            if (!isset($goodsMap[$goodsFilter])) {
                throw new \InvalidArgumentException('Unknown goods_id: ' . $goodsFilter);
            }
            $goodsMap = [$goodsFilter => $goodsMap[$goodsFilter]];
        }

        /** @var array<int, array<string, mixed>> */
        $byAccount = [];
        foreach (array_keys($goodsMap) as $goodsId) {
            $stats['goods_scanned']++;
            $batch = $client->searchAllPages('accounts', ['id_goods' => (string)$goodsId], 100, $pauseMicros);
            foreach ($batch as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $accountId = AvoAccountRow::accountId($row);
                if ($accountId <= 0) {
                    continue;
                }
                $byAccount[$accountId] = $row;
            }
        }
        $stats['accounts_fetched'] = count($byAccount);

        /** @var array<int, true> */
        $allowedGoods = [];
        foreach (array_keys($goodsMap) as $gid) {
            $allowedGoods[(int)$gid] = true;
        }

        $utmResolver = new AvoUtmResolver();
        $applied = 0;

        foreach ($byAccount as $row) {
            if (!$this->isPaidAccount($row)) {
                $stats['skipped_not_paid']++;
                continue;
            }
            $stats['accounts_paid']++;

            $timeline = AvoAccountRow::accessTimelineIso($row, true);
            $paidTs = $this->isoToTimestamp($timeline['paid'] ?? null);
            if ($paidTs <= 0) {
                $paidTs = AvoAccountRow::orderTimestamp($row);
            }
            if ($sinceTs > 0 && ($paidTs <= 0 || $paidTs < $sinceTs)) {
                $stats['skipped_date']++;
                continue;
            }
            if ($untilTs > 0 && $paidTs > $untilTs) {
                $stats['skipped_date']++;
                continue;
            }

            $email = AvoAccountRow::email($row);
            if ($email === '') {
                $stats['skipped_email']++;
                continue;
            }

            $goodsIds = AvoAccountRow::matchingGoodsIds($row, $allowedGoods);
            if ($goodsIds === []) {
                $stats['skipped_goods']++;
                continue;
            }

            foreach ($goodsIds as $idGoods) {
                $courseSlug = $goodsMap[$idGoods] ?? '';
                if ($courseSlug === '') {
                    $stats['skipped_goods']++;
                    continue;
                }

                $accountId = AvoAccountRow::accountId($row);
                if ($this->paymentExists($pdo, (string)$accountId, $courseSlug)) {
                    $stats['skipped_existing']++;
                    continue;
                }

                if ($limit > 0 && $applied >= $limit) {
                    break 2;
                }

                $stats['would_apply']++;
                $name = AvoContactName::resolveFromPayload($row);
                $contactId = AvoAccountRow::contactId($row);
                $payload = $this->webhookPayload($row, $email, $accountId, $idGoods, $contactId);

                if ($dryRun) {
                    $this->logLine('dry-run', $accountId, $email, $courseSlug, $idGoods, $timeline['paid'] ?? '');
                    $applied++;
                    continue;
                }

                try {
                    $userId = 0;
                    if ($grantAccess) {
                        $grant = (new PaidAccess())->grant(
                            $email,
                            $name,
                            $courseSlug,
                            self::SOURCE,
                            (string)$accountId,
                            $utmResolver->resolve($payload),
                            $contactId > 0 ? $contactId : null,
                            $sendEmail ? null : false,
                            $timeline['ordered'],
                            $timeline['paid']
                        );
                        $userId = (int)$grant['user_id'];
                        if (!empty($grant['paid_granted']) || !empty($grant['already_paid'])) {
                            $stats['grants_applied']++;
                        }
                    } else {
                        $user = User::findByEmail($pdo, $email);
                        if ($user === null) {
                            throw new \RuntimeException('user missing (use grant access or create user first)');
                        }
                        $userId = (int)$user['id'];
                    }

                    if ($recordPayments && $userId > 0) {
                        $ok = PaymentRecorder::recordFromWebhook(
                            $pdo,
                            $userId,
                            $courseSlug,
                            self::SOURCE,
                            $payload,
                            $timeline['ordered'],
                            $timeline['paid'],
                            $contactId > 0 ? $contactId : null
                        );
                        if ($ok) {
                            $stats['payments_recorded']++;
                        }
                    }

                    $this->logLine('ok', $accountId, $email, $courseSlug, $idGoods, $timeline['paid'] ?? '');
                    $applied++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->logLine(
                        'error',
                        $accountId,
                        $email,
                        $courseSlug,
                        $idGoods,
                        $e->getMessage()
                    );
                }
            }
        }

        return $stats;
    }

    private function paymentExists(PDO $pdo, string $avoAccountId, string $courseSlug): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM payments WHERE avo_account_id = ? AND course_slug = ? LIMIT 1'
        );
        $stmt->execute([$avoAccountId, $courseSlug]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isPaidAccount(array $row): bool
    {
        if (isset($row['id_account_status'])) {
            return (int)$row['id_account_status'] === 5;
        }

        return AvoAccountRow::isPaid($row);
    }

    private function isoToTimestamp(?string $iso): int
    {
        $iso = trim((string)$iso);
        if ($iso === '') {
            return 0;
        }
        $ts = strtotime($iso);

        return $ts !== false ? $ts : 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function webhookPayload(
        array $row,
        string $email,
        int $accountId,
        int $idGoods,
        int $contactId
    ): array {
        $rub = $this->accountRub($row);

        return array_merge($row, [
            'email' => $email,
            'id_account' => $accountId,
            'id_goods' => $idGoods,
            'id_account_status' => 5,
            'source' => self::SOURCE,
            'source_ref' => (string)$accountId,
            'amount' => $rub,
            'sum' => $rub,
            'amount_rub' => $rub,
            'currency' => 'RUB',
            'id_contact' => $contactId > 0 ? $contactId : ($row['id_contact'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function accountRub(array $row): ?float
    {
        foreach (['account_sum', 'sum', 'amount', 'total'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $raw = str_replace([' ', ','], ['', '.'], trim((string)$row[$key]));
            if ($raw === '' || !is_numeric($raw)) {
                continue;
            }
            $num = (float)$raw;
            if ($num >= 0) {
                return $num;
            }
        }

        return null;
    }

    private function logLine(
        string $kind,
        int $accountId,
        string $email,
        string $courseSlug,
        int $idGoods,
        string $extra
    ): void {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        echo sprintf(
            "[%s] account=%d %s course=%s goods=%d %s\n",
            $kind,
            $accountId,
            $email,
            $courseSlug,
            $idGoods,
            $extra
        );
    }
}
