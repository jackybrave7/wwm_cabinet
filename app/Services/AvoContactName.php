<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\User;

/**
 * AVO contact fields: name = first name, last_name = surname, middle_name = patronymic.
 */
final class AvoContactName
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function resolveFromPayload(array $payload): string
    {
        $first = self::pick($payload, ['name', 'firstname', 'first_name', 'imya']);
        $middle = self::pick($payload, ['middle_name', 'patronymic', 'otchestvo']);
        $last = self::pick($payload, ['last_name', 'surname', 'lastname', 'family_name', 'familia']);
        $fallback = self::pick($payload, ['full_name', 'contact_name', 'fio']);

        return self::compose($first, $middle, $last, $fallback);
    }

    /**
     * @param array<string, mixed> $contact
     */
    public static function resolveFromContact(array $contact): string
    {
        return self::resolveFromPayload($contact);
    }

    public static function shouldReplace(string $existing, string $incoming): bool
    {
        $existing = trim($existing);
        $incoming = trim($incoming);
        if ($incoming === '') {
            return false;
        }
        if ($existing === '') {
            return true;
        }
        if (strcasecmp($existing, $incoming) === 0) {
            return false;
        }

        $existingWords = preg_split('/\s+/u', $existing, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $incomingWords = preg_split('/\s+/u', $incoming, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($incomingWords) > count($existingWords)) {
            return true;
        }

        return mb_strlen($incoming) > mb_strlen($existing);
    }

    public static function syncForUser(PDO $pdo, int $userId, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $user = User::findById($pdo, $userId);
        if ($user === null) {
            return false;
        }

        $existing = trim((string)($user['name'] ?? ''));
        if (!self::shouldReplace($existing, $name)) {
            return false;
        }

        User::updateName($pdo, $userId, $name);

        return true;
    }

    public static function backfillFromAvo(PDO $pdo, int $userId): bool
    {
        $user = User::findById($pdo, $userId);
        if ($user === null) {
            return false;
        }

        $client = new AvoClient();
        if (!$client->isEnabled()) {
            return false;
        }

        $contactId = (int)($user['avo_contact_id'] ?? 0);
        $contact = null;
        if ($contactId > 0) {
            $contact = $client->findContactById($contactId);
        }
        if ($contact === null) {
            $contact = $client->findContactByEmail((string)$user['email']);
        }
        if ($contact === null) {
            return false;
        }

        $name = self::resolveFromContact($contact);
        if ($name === '') {
            return false;
        }

        $updated = self::syncForUser($pdo, $userId, $name);
        $foundId = (int)($contact['id_contact'] ?? $contact['id'] ?? 0);
        if ($foundId > 0 && $contactId <= 0) {
            User::setAvoFlags($pdo, $userId, ['avo_contact_id' => $foundId]);
        }

        return $updated;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private static function pick(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function compose(string $first, string $middle, string $last, string $fallbackFull = ''): string
    {
        $first = trim($first);
        $middle = trim($middle);
        $last = trim($last);
        $fallbackFull = trim($fallbackFull);

        if ($first !== '' && $last !== '') {
            if (self::containsToken($first, $last)) {
                return $first;
            }
            if (self::containsToken($last, $first)) {
                return $last;
            }

            $parts = array_values(array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== ''));

            return implode(' ', $parts);
        }

        if ($first !== '') {
            return $middle !== '' ? trim($first . ' ' . $middle) : $first;
        }

        if ($last !== '') {
            return $middle !== '' ? trim($middle . ' ' . $last) : $last;
        }

        return $fallbackFull;
    }

    private static function containsToken(string $haystack, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return preg_match('/\b' . preg_quote($token, '/') . '\b/iu', $haystack) === 1;
    }
}
