<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Auth\Password;
use Wwm\Models\Access;
use Wwm\Models\User;

/**
 * One-off import of students + access from AVO orders before a cutoff date.
 * Silent: no emails. New users get random passwords (reset via /forgot later).
 */
final class AvoLegacyImport
{
    private const SOURCE = 'avo-import';

    /**
     * @param array{
     *   before_ts: int,
     *   dry_run: bool,
     *   pause_micros: int,
     * } $options
     * @return array<string, int|bool|string>
     */
    public function run(PDO $pdo, array $options): array
    {
        $beforeTs = (int)($options['before_ts'] ?? 0);
        $dryRun = !empty($options['dry_run']);
        $pauseMicros = max(0, (int)($options['pause_micros'] ?? 100000));

        $stats = [
            'dry_run' => $dryRun,
            'before_utc' => $beforeTs > 0 ? gmdate('c', $beforeTs) : '',
            'disabled' => false,
            'goods_scanned' => 0,
            'orders_fetched' => 0,
            'orders_in_window' => 0,
            'orders_skipped_date' => 0,
            'orders_skipped_email' => 0,
            'orders_skipped_goods' => 0,
            'students_touched' => 0,
            'users_created' => 0,
            'users_updated' => 0,
            'paid_grants' => 0,
            'demo_grants' => 0,
            'access_unchanged' => 0,
            'skipped_admin' => 0,
            'import_category_id' => 0,
            'category_goods_ids' => 0,
            'orders_unmapped_goods' => 0,
        ];

        $client = new AvoClient();
        if (!$client->isEnabled()) {
            $stats['disabled'] = true;

            return $stats;
        }

        if ($beforeTs <= 0) {
            throw new \InvalidArgumentException('before_ts required');
        }

        $goodsMap = AvoSalesLinks::goodsMap();
        if ($goodsMap === []) {
            throw new \RuntimeException('No id_goods → course mapping');
        }

        $catalog = new CourseCatalog();
        $categoryHelper = new AvoGoodsCategory();
        $categoryId = (int)($options['import_goods_category_id'] ?? 0);
        if ($categoryId <= 0) {
            $categoryId = AvoGoodsCategory::importCategoryId();
        }
        $stats['import_category_id'] = $categoryId;

        $extraGoodsIds = $categoryId > 0
            ? $categoryHelper->goodsIdsInCategory($client, $categoryId, $pauseMicros)
            : [];
        $stats['category_goods_ids'] = count($extraGoodsIds);

        /** @var list<int> */
        $scanGoodsIds = array_values(array_unique(array_merge(array_keys($goodsMap), $extraGoodsIds)));

        if (PHP_SAPI === 'cli') {
            echo 'Goods to scan: ' . count($scanGoodsIds);
            if ($categoryId > 0) {
                echo ' (AVO category id ' . $categoryId . ', ' . count($extraGoodsIds) . ' in category)';
            }
            echo PHP_EOL;
        }

        /** @var array<string, array{name: string, contact_id: int, utm: array<string, string>, courses: array<string, array{type: string, account_id: int, order_ts: int}>}> */
        $byEmail = [];

        /** @var array<int, true> */
        $seenAccounts = [];

        foreach ($scanGoodsIds as $goodsId) {
            $stats['goods_scanned']++;
            $courseSlug = $goodsMap[$goodsId] ?? '';
            if (PHP_SAPI === 'cli') {
                $label = $courseSlug !== '' ? $courseSlug : 'no cabinet slug';
                echo 'AVO id_goods ' . $goodsId . ' (' . $label . ')…' . PHP_EOL;
                if (function_exists('flush')) {
                    flush();
                }
            }
            $rows = $client->searchAllPages('accounts', ['id_goods' => (string)$goodsId], 100, $pauseMicros);
            if (PHP_SAPI === 'cli') {
                echo '  orders fetched: ' . count($rows) . PHP_EOL;
            }
            $stats['orders_fetched'] += count($rows);

            foreach ($rows as $row) {
                $accountId = AvoAccountRow::accountId($row);
                if ($accountId > 0 && isset($seenAccounts[$accountId])) {
                    continue;
                }
                if ($accountId > 0) {
                    $seenAccounts[$accountId] = true;
                }

                $orderTs = AvoAccountRow::orderTimestamp($row);
                if ($orderTs <= 0 || $orderTs >= $beforeTs) {
                    $stats['orders_skipped_date']++;
                    continue;
                }

                $email = AvoAccountRow::email($row);
                if ($email === '') {
                    $stats['orders_skipped_email']++;
                    continue;
                }

                $goodsIds = AvoAccountRow::goodsIds($row, $goodsMap);
                if ($goodsIds === []) {
                    // AVO often omits id_goods in list rows when search[id_goods] was used.
                    $goodsIds = [$goodsId];
                }

                $stats['orders_in_window']++;
                $isPaid = AvoAccountRow::isPaid($row);
                $name = AvoContactName::resolveFromPayload($row);
                $contactId = AvoAccountRow::contactId($row);
                // UTM only from the order row — no extra AVO API (avoids 404 noise and slow import).
                $utm = StudentAttribution::utmFromAvoPayload($row);

                if (!isset($byEmail[$email])) {
                    $byEmail[$email] = [
                        'name' => $name,
                        'contact_id' => $contactId,
                        'utm' => $utm,
                        'courses' => [],
                    ];
                } else {
                    if ($name !== '') {
                        $byEmail[$email]['name'] = $name;
                    }
                    if ($contactId > 0) {
                        $byEmail[$email]['contact_id'] = $contactId;
                    }
                    $byEmail[$email]['utm'] = StudentAttribution::mergeUtm($byEmail[$email]['utm'], $utm);
                }

                foreach ($goodsIds as $idGoods) {
                    $slug = $goodsMap[$idGoods] ?? '';
                    if ($slug === '') {
                        $stats['orders_unmapped_goods']++;
                        continue;
                    }
                    $type = $isPaid ? 'paid' : 'demo';
                    $existing = $byEmail[$email]['courses'][$slug] ?? null;
                    if ($existing === null) {
                        $byEmail[$email]['courses'][$slug] = [
                            'type' => $type,
                            'account_id' => $accountId,
                            'order_ts' => $orderTs,
                        ];
                        continue;
                    }
                    if ($existing['type'] === 'paid') {
                        continue;
                    }
                    if ($type === 'paid') {
                        $byEmail[$email]['courses'][$slug] = [
                            'type' => 'paid',
                            'account_id' => $accountId,
                            'order_ts' => $orderTs,
                        ];
                        continue;
                    }
                    if ($orderTs >= (int)$existing['order_ts']) {
                        $byEmail[$email]['courses'][$slug] = [
                            'type' => 'demo',
                            'account_id' => $accountId,
                            'order_ts' => $orderTs,
                        ];
                    }
                }
                if ($byEmail[$email]['courses'] === []) {
                    unset($byEmail[$email]);
                }
            }
        }

        $stats['students_touched'] = count($byEmail);

        foreach ($byEmail as $email => $bundle) {
            $user = User::findByEmail($pdo, $email);
            if ($user !== null && self::isStaff($user)) {
                $stats['skipped_admin']++;
                continue;
            }

            if ($dryRun) {
                if ($user === null) {
                    $stats['users_created']++;
                } else {
                    $stats['users_updated']++;
                }
                foreach ($bundle['courses'] as $slug => $grant) {
                    if ($grant['type'] === 'paid') {
                        $stats['paid_grants']++;
                    } else {
                        $stats['demo_grants']++;
                    }
                }
                continue;
            }

            $created = false;
            if ($user === null) {
                $plain = Password::generateReadable(12);
                $userId = User::create($pdo, $email, $plain, (string)$bundle['name']);
                $user = User::findById($pdo, $userId);
                $created = true;
                $stats['users_created']++;
            } else {
                $stats['users_updated']++;
                if ((string)$bundle['name'] !== '') {
                    User::updateName($pdo, (int)$user['id'], (string)$bundle['name']);
                }
            }

            if ($user === null) {
                continue;
            }

            $userId = (int)$user['id'];
            $contactId = (int)$bundle['contact_id'];
            if ($contactId > 0) {
                User::setAvoFlags($pdo, $userId, ['avo_contact_id' => $contactId]);
            } elseif ($contactId <= 0) {
                $found = $client->findContactIdByEmail($email);
                if ($found !== null && $found > 0) {
                    User::setAvoFlags($pdo, $userId, ['avo_contact_id' => $found]);
                }
            }

            StudentAttribution::recordForUser($pdo, $userId, $created, $bundle['utm'], false);

            foreach ($bundle['courses'] as $courseSlug => $grant) {
                $hasPaidRow = self::userHasPaidGrant($pdo, $userId, $courseSlug);

                if ($grant['type'] === 'demo' && $hasPaidRow) {
                    $stats['access_unchanged']++;
                    continue;
                }

                if ($grant['type'] === 'paid') {
                    $existingPaid = Access::findGrant($pdo, $userId, $courseSlug, 'paid');
                    if ($existingPaid !== null
                        && (string)($existingPaid['source'] ?? '') === self::SOURCE
                        && (string)($existingPaid['source_ref'] ?? '') === (string)$grant['account_id']
                    ) {
                        $stats['access_unchanged']++;
                        continue;
                    }
                    Access::grant(
                        $pdo,
                        $userId,
                        $courseSlug,
                        'paid',
                        null,
                        self::SOURCE,
                        $grant['account_id'] > 0 ? (string)$grant['account_id'] : null
                    );
                    $stats['paid_grants']++;
                    continue;
                }

                $course = $catalog->getAdmin($courseSlug);
                $demoHours = (int)($course['demo_hours'] ?? wwm_config()['demo_hours'] ?? 48);
                if ($demoHours < 1) {
                    $demoHours = 48;
                }
                $expiresAt = gmdate('c', (int)$grant['order_ts'] + $demoHours * 3600);

                $existingDemo = Access::findGrant($pdo, $userId, $courseSlug, 'demo');
                if ($existingDemo !== null
                    && (string)($existingDemo['source'] ?? '') === self::SOURCE
                    && (string)($existingDemo['source_ref'] ?? '') === (string)$grant['account_id']
                    && (string)($existingDemo['expires_at'] ?? '') === $expiresAt
                ) {
                    $stats['access_unchanged']++;
                    continue;
                }

                Access::grant(
                    $pdo,
                    $userId,
                    $courseSlug,
                    'demo',
                    $expiresAt,
                    self::SOURCE,
                    $grant['account_id'] > 0 ? (string)$grant['account_id'] : null
                );
                $stats['demo_grants']++;
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function isStaff(array $user): bool
    {
        return !empty($user['is_admin'])
            || !empty($user['admin_super'])
            || !empty($user['admin_students'])
            || !empty($user['admin_courses']);
    }

    private static function userHasPaidGrant(PDO $pdo, int $userId, string $courseSlug): bool
    {
        $row = Access::findGrant($pdo, $userId, $courseSlug, 'paid');

        return $row !== null;
    }

}
