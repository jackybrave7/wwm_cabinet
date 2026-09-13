<?php
declare(strict_types=1);

namespace Wwm\Services;

final class AvoContactTimeline
{
    /**
     * @param array<string, mixed>|null $contact
     */
    public static function registeredAtIso(?array $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        foreach (['date_registration', 'creation_date', 'created_at', 'date_of_registration'] as $key) {
            $value = trim((string)($contact[$key] ?? ''));
            if ($value === '' || str_starts_with($value, '0000-00-00')) {
                continue;
            }
            $ts = strtotime($value);
            if ($ts !== false) {
                return gmdate('c', $ts);
            }
        }

        return null;
    }
}
