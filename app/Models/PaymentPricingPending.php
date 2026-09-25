<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class PaymentPricingPending
{
    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS payment_pricing_pending (
  avo_account_id TEXT NOT NULL,
  course_slug TEXT NOT NULL,
  email TEXT NOT NULL,
  amount_original REAL,
  currency_original TEXT NOT NULL DEFAULT '',
  amount_rub REAL,
  fx_rate REAL,
  created_at TEXT NOT NULL,
  PRIMARY KEY (avo_account_id, course_slug)
);
SQL);
    }

    /**
     * @param array{
     *   avo_account_id: string,
     *   course_slug: string,
     *   email: string,
     *   amount_original: ?float,
     *   currency_original: string,
     *   amount_rub: ?float,
     *   fx_rate: ?float
     * } $data
     */
    public static function store(PDO $pdo, array $data): void
    {
        self::ensureTable($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO payment_pricing_pending (
                avo_account_id, course_slug, email, amount_original, currency_original, amount_rub, fx_rate, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(avo_account_id, course_slug) DO UPDATE SET
                email = excluded.email,
                amount_original = excluded.amount_original,
                currency_original = excluded.currency_original,
                amount_rub = excluded.amount_rub,
                fx_rate = excluded.fx_rate,
                created_at = excluded.created_at'
        );
        $stmt->execute([
            $data['avo_account_id'],
            $data['course_slug'],
            strtolower(trim($data['email'])),
            $data['amount_original'],
            $data['currency_original'],
            $data['amount_rub'],
            $data['fx_rate'],
            gmdate('c'),
        ]);
    }

    public static function applyForPayment(PDO $pdo, string $avoAccountId, string $courseSlug): void
    {
        self::ensureTable($pdo);
        $stmt = $pdo->prepare(
            'SELECT * FROM payment_pricing_pending WHERE avo_account_id = ? AND course_slug = ? LIMIT 1'
        );
        $stmt->execute([$avoAccountId, $courseSlug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }

        Payment::applyPricingFields($pdo, $avoAccountId, $courseSlug, [
            'amount_original' => isset($row['amount_original']) ? (float)$row['amount_original'] : null,
            'currency_original' => (string)($row['currency_original'] ?? ''),
            'amount_rub' => isset($row['amount_rub']) ? (float)$row['amount_rub'] : null,
            'fx_rate' => isset($row['fx_rate']) ? (float)$row['fx_rate'] : null,
        ]);

        $del = $pdo->prepare('DELETE FROM payment_pricing_pending WHERE avo_account_id = ? AND course_slug = ?');
        $del->execute([$avoAccountId, $courseSlug]);
    }
}
