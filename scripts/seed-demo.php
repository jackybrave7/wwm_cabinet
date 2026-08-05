<?php
declare(strict_types=1);

/**
 * Демо-пользователи для теста ЛК (не для продакшена).
 *
 *   php scripts/seed-demo.php
 *   php scripts/seed-demo.php --paid
 *
 * Logins:
 *   demo@wwm.test / demo-demo-demo  (alvaro + elke-en)
 *   student@example.com / password  (alvaro)
 */
require dirname(__DIR__) . '/app/bootstrap.php';

Wwm\Database::migrate(wwm_pdo());

$paid = in_array('--paid', $argv ?? [], true);
$pdo = wwm_pdo();

function seed_user(\PDO $pdo, string $email, string $password, string $name, bool $paid, array $courseSlugs = ['alvaro']): int
{
    $user = Wwm\Models\User::findByEmail($pdo, $email);
    if ($user === null) {
        $userId = Wwm\Models\User::create($pdo, $email, $password, $name);
        echo "Created user id={$userId} email={$email}" . PHP_EOL;
    } else {
        $userId = (int)$user['id'];
        Wwm\Models\User::updatePassword($pdo, $userId, $password);
        echo "Updated password for user id={$userId} email={$email}" . PHP_EOL;
    }

    $expires = gmdate('c', time() + 48 * 3600);
    foreach ($courseSlugs as $slug) {
        Wwm\Models\Access::grant($pdo, $userId, $slug, 'demo', $expires, 'seed', 'manual');

        if ($paid) {
            Wwm\Models\Access::grant($pdo, $userId, $slug, 'paid', null, 'seed', 'manual-paid');
            echo "  → PAID access to {$slug}" . PHP_EOL;
        } else {
            echo "  → DEMO access to {$slug} (48h)" . PHP_EOL;
        }
    }

    if ($email === 'demo@wwm.test') {
        Wwm\Models\User::setAdmin($pdo, $userId, true);
        echo "  → ADMIN role" . PHP_EOL;
    }

    return $userId;
}

seed_user($pdo, 'demo@wwm.test', 'demo-demo-demo', 'Demo Student', $paid, ['alvaro', 'elke-en']);
seed_user($pdo, 'student@example.com', 'password', 'Test Student', $paid);

echo PHP_EOL . 'Passwords:' . PHP_EOL;
echo '  demo@wwm.test → demo-demo-demo' . PHP_EOL;
echo '  student@example.com → password' . PHP_EOL;
