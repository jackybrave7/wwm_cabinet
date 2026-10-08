<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;

final class DemoLeadRateLimit
{
    private const IP_PER_HOUR = 10;
    private const EMAIL_PER_HOUR = 3;

    public static function tooMany(PDO $pdo, string $ip, string $email): bool
    {
        self::ensureTable($pdo);
        $since = gmdate('c', time() - 3600);

        $byIp = $pdo->prepare('SELECT COUNT(*) FROM demo_lead_attempts WHERE ip = ? AND created_at >= ?');
        $byIp->execute([$ip, $since]);
        if ((int)$byIp->fetchColumn() >= self::IP_PER_HOUR) {
            return true;
        }

        $byEmail = $pdo->prepare('SELECT COUNT(*) FROM demo_lead_attempts WHERE email = ? AND created_at >= ?');
        $byEmail->execute([$email, $since]);

        return (int)$byEmail->fetchColumn() >= self::EMAIL_PER_HOUR;
    }

    public static function hit(PDO $pdo, string $ip, string $email): void
    {
        self::ensureTable($pdo);
        $stmt = $pdo->prepare('INSERT INTO demo_lead_attempts (ip, email, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$ip, $email, gmdate('c')]);

        if (random_int(1, 20) === 1) {
            $cutoff = gmdate('c', time() - 2 * 86400);
            $pdo->prepare('DELETE FROM demo_lead_attempts WHERE created_at < ?')->execute([$cutoff]);
        }
    }

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS demo_lead_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL,
  email TEXT NOT NULL,
  created_at TEXT NOT NULL
);
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_demo_lead_attempts_ip ON demo_lead_attempts (ip, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_demo_lead_attempts_email ON demo_lead_attempts (email, created_at)');
    }
}
