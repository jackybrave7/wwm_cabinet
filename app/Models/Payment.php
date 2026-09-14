<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class Payment
{
    /**
     * @param array{
     *   user_id: int,
     *   avo_account_id: string,
     *   course_slug: string,
     *   id_goods: ?int,
     *   amount: ?float,
     *   currency: string,
     *   source: string,
     *   ordered_at: ?string,
     *   paid_at: ?string,
     *   utm_source: ?string,
     *   utm_medium: ?string,
     *   utm_campaign: ?string,
     *   utm_term: ?string,
     *   utm_content: ?string,
     *   ad_snapshot: ?string,
     *   avo_contact_id: ?int
     * } $data
     */
    public static function upsert(PDO $pdo, array $data): bool
    {
        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO payments (
                user_id, avo_account_id, course_slug, id_goods, amount, currency, source,
                ordered_at, paid_at, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                ad_snapshot, avo_contact_id, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(avo_account_id, course_slug) DO UPDATE SET
                user_id = excluded.user_id,
                id_goods = COALESCE(excluded.id_goods, payments.id_goods),
                amount = COALESCE(excluded.amount, payments.amount),
                currency = CASE WHEN excluded.currency != \'\' THEN excluded.currency ELSE payments.currency END,
                source = excluded.source,
                ordered_at = COALESCE(excluded.ordered_at, payments.ordered_at),
                paid_at = COALESCE(excluded.paid_at, payments.paid_at),
                utm_source = COALESCE(excluded.utm_source, payments.utm_source),
                utm_medium = COALESCE(excluded.utm_medium, payments.utm_medium),
                utm_campaign = COALESCE(excluded.utm_campaign, payments.utm_campaign),
                utm_term = COALESCE(excluded.utm_term, payments.utm_term),
                utm_content = COALESCE(excluded.utm_content, payments.utm_content),
                ad_snapshot = COALESCE(excluded.ad_snapshot, payments.ad_snapshot),
                avo_contact_id = COALESCE(excluded.avo_contact_id, payments.avo_contact_id),
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            $data['user_id'],
            $data['avo_account_id'],
            $data['course_slug'],
            $data['id_goods'],
            $data['amount'],
            $data['currency'],
            $data['source'],
            $data['ordered_at'],
            $data['paid_at'],
            $data['utm_source'],
            $data['utm_medium'],
            $data['utm_campaign'],
            $data['utm_term'],
            $data['utm_content'],
            $data['ad_snapshot'],
            $data['avo_contact_id'],
            $now,
            $now,
        ]);

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM payments WHERE user_id = ? ORDER BY COALESCE(paid_at, ordered_at, created_at) DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    public static function formatAmount(?float $amount, string $currency = ''): string
    {
        if ($amount === null || $amount <= 0) {
            return '—';
        }
        $formatted = number_format($amount, 2, '.', ' ');
        if ($currency !== '' && !str_starts_with($currency, 'id:')) {
            return $formatted . ' ' . $currency;
        }

        return $formatted;
    }
}
