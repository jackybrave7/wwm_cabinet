<?php

declare(strict_types=1);



namespace Wwm\Models;



use PDO;



final class EmailAutomation

{

    public const ENTRY_DEMO_GRANT = 'demo_grant';

    public const ENTRY_MANUAL = 'manual';

    public const ENTRY_PAYMENT_ANY = 'payment_any';

    public const ENTRY_PAYMENT_COURSE = 'payment_course';



    /**

     * @return array<string, string>

     */

    public static function entryModeLabels(): array

    {

        return [

            self::ENTRY_DEMO_GRANT => 'Демо по курсу (AVO / API)',

            self::ENTRY_MANUAL => 'Вручную (допродажи, сегменты)',

            self::ENTRY_PAYMENT_ANY => 'После любой оплаты',

            self::ENTRY_PAYMENT_COURSE => 'После оплаты выбранного курса',

        ];

    }



    public static function normalizeEntryMode(string $raw): string

    {

        $raw = trim($raw);

        $allowed = [

            self::ENTRY_DEMO_GRANT,

            self::ENTRY_MANUAL,

            self::ENTRY_PAYMENT_ANY,

            self::ENTRY_PAYMENT_COURSE,

        ];



        return in_array($raw, $allowed, true) ? $raw : self::ENTRY_DEMO_GRANT;

    }



    public static function entryModeRequiresCourseSlug(string $entryMode): bool

    {

        return in_array($entryMode, [self::ENTRY_DEMO_GRANT, self::ENTRY_PAYMENT_COURSE], true);

    }



    /**

     * @return list<array<string, mixed>>

     */

    public static function listLaunchable(PDO $pdo): array

    {

        $stmt = $pdo->query(

            'SELECT id, title, slug, course_slug, entry_mode, is_active

             FROM email_automations

             WHERE (archived_at IS NULL OR archived_at = \'\')

             ORDER BY is_active DESC, title ASC, id ASC'

        );

        return $stmt ? ($stmt->fetchAll() ?: []) : [];

    }



    public static function listAll(PDO $pdo, bool $archivedOnly = false): array

    {

        $where = $archivedOnly

            ? 'WHERE a.archived_at IS NOT NULL AND a.archived_at != \'\''

            : 'WHERE a.archived_at IS NULL OR a.archived_at = \'\'';

        $stmt = $pdo->query(

            'SELECT a.*,

                (SELECT COUNT(*) FROM email_automation_runs r WHERE r.automation_id = a.id AND r.status = \'active\') AS active_runs

             FROM email_automations a

             ' . $where . '

             ORDER BY a.updated_at DESC, a.id DESC'

        );



        return $stmt ? ($stmt->fetchAll() ?: []) : [];

    }



    public static function isArchived(array $row): bool

    {

        $at = trim((string)($row['archived_at'] ?? ''));



        return $at !== '';

    }



    public static function find(PDO $pdo, int $id): ?array

    {

        $stmt = $pdo->prepare('SELECT * FROM email_automations WHERE id = ?');

        $stmt->execute([$id]);

        $row = $stmt->fetch();



        return is_array($row) ? $row : null;

    }



    public static function findBySlug(PDO $pdo, string $slug): ?array

    {

        $slug = trim($slug);

        if ($slug === '') {

            return null;

        }

        $stmt = $pdo->prepare('SELECT * FROM email_automations WHERE slug = ?');

        $stmt->execute([$slug]);

        $row = $stmt->fetch();



        return is_array($row) ? $row : null;

    }



    public static function findActiveForCourse(PDO $pdo, string $courseSlug): ?array

    {

        $courseSlug = trim($courseSlug);

        if ($courseSlug === '') {

            return null;

        }

        $stmt = $pdo->prepare(

            'SELECT * FROM email_automations

             WHERE course_slug = ? AND is_active = 1 AND entry_mode = ?

               AND (archived_at IS NULL OR archived_at = \'\')

             ORDER BY id ASC LIMIT 1'

        );

        $stmt->execute([$courseSlug, self::ENTRY_DEMO_GRANT]);

        $row = $stmt->fetch();



        return is_array($row) ? $row : null;

    }



    /**

     * @return list<array<string, mixed>>

     */

    public static function findActiveByEntryMode(PDO $pdo, string $entryMode, ?string $courseSlug = null): array

    {

        $entryMode = self::normalizeEntryMode($entryMode);

        $sql = 'SELECT * FROM email_automations

                WHERE is_active = 1 AND entry_mode = ?

                  AND (archived_at IS NULL OR archived_at = \'\')';

        $params = [$entryMode];

        if ($entryMode === self::ENTRY_PAYMENT_COURSE) {

            $courseSlug = trim((string)$courseSlug);

            if ($courseSlug === '') {

                return [];

            }

            $sql .= ' AND course_slug = ?';

            $params[] = $courseSlug;

        }

        $sql .= ' ORDER BY id ASC';

        $stmt = $pdo->prepare($sql);

        $stmt->execute($params);



        return $stmt->fetchAll() ?: [];

    }



    /**

     * @return array<string, mixed>|null decoded definition

     */

    public static function definition(array $row): ?array

    {

        $raw = (string)($row['definition_json'] ?? '');

        if ($raw === '') {

            return null;

        }

        $decoded = json_decode($raw, true);



        return is_array($decoded) ? $decoded : null;

    }



    /**

     * @return array<string, mixed>

     */

    public static function blankDefinition(string $courseSlug = ''): array

    {

        $courseSlug = preg_replace('/[^a-z0-9\-]/', '', $courseSlug) ?: '';



        return [

            'meta' => [

                'version' => 1,

                'course_slug' => $courseSlug,

            ],

            'nodes' => [

                'start' => [

                    'type' => 'trigger',

                    'label' => 'Start',

                    'node_id' => 'start',

                ],

                'end' => [

                    'type' => 'end',

                    'label' => 'End',

                    'node_id' => 'end',

                ],

            ],

            'edges' => [

                ['from' => 'start', 'to' => 'end', 'branch' => 'next'],

            ],

        ];

    }



    /**

     * @param array{

     *   slug: string,

     *   title: string,

     *   description?: string,

     *   course_slug: string,

     *   entry_mode?: string,

     *   is_active?: bool,

     *   definition_json: string,

     *   avo_export_json?: ?string

     * } $data

     */

    public static function create(PDO $pdo, array $data): int

    {

        $now = gmdate('c');

        $entryMode = self::normalizeEntryMode((string)($data['entry_mode'] ?? self::ENTRY_DEMO_GRANT));

        $pdo->prepare(

            'INSERT INTO email_automations (

                slug, title, description, course_slug, entry_mode, is_active,

                definition_json, avo_export_json, created_at, updated_at

             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'

        )->execute([

            $data['slug'],

            $data['title'],

            $data['description'] ?? '',

            $data['course_slug'],

            $entryMode,

            !empty($data['is_active']) ? 1 : 0,

            $data['definition_json'],

            $data['avo_export_json'] ?? null,

            $now,

            $now,

        ]);



        return (int)$pdo->lastInsertId();

    }



    public static function createBlank(

        PDO $pdo,

        string $title,

        string $entryMode,

        string $courseSlug = ''

    ): int {

        $title = trim($title);

        if ($title === '') {

            $title = 'New automation';

        }

        $entryMode = self::normalizeEntryMode($entryMode);

        $courseSlug = preg_replace('/[^a-z0-9\-]/', '', $courseSlug) ?: '';

        if (self::entryModeRequiresCourseSlug($entryMode) && $courseSlug === '') {

            throw new \InvalidArgumentException('course_slug_required');

        }



        $slug = self::uniqueSlug($pdo, $title);

        $definition = self::blankDefinition($courseSlug);



        return self::create($pdo, [

            'slug' => $slug,

            'title' => $title,

            'description' => '',

            'course_slug' => $courseSlug,

            'entry_mode' => $entryMode,

            'is_active' => false,

            'definition_json' => json_encode(

                $definition,

                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT

            ) ?: '{}',

        ]);

    }



    /**

     * @param array{

     *   title?: string,

     *   description?: string,

     *   course_slug?: string,

     *   entry_mode?: string,

     *   is_active?: bool,

     *   definition_json?: string,

     *   avo_export_json?: ?string

     * } $data

     */

    public static function update(PDO $pdo, int $id, array $data): void

    {

        $row = self::find($pdo, $id);

        if ($row === null) {

            return;

        }



        $entryMode = isset($data['entry_mode'])

            ? self::normalizeEntryMode((string)$data['entry_mode'])

            : self::normalizeEntryMode((string)($row['entry_mode'] ?? self::ENTRY_DEMO_GRANT));



        $pdo->prepare(

            'UPDATE email_automations SET

                title = ?, description = ?, course_slug = ?, entry_mode = ?, is_active = ?,

                definition_json = ?, avo_export_json = COALESCE(?, avo_export_json), updated_at = ?

             WHERE id = ?'

        )->execute([

            $data['title'] ?? $row['title'],

            $data['description'] ?? $row['description'],

            $data['course_slug'] ?? $row['course_slug'],

            $entryMode,

            isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : (int)$row['is_active'],

            $data['definition_json'] ?? $row['definition_json'],

            $data['avo_export_json'] ?? null,

            gmdate('c'),

            $id,

        ]);

    }



    public static function uniqueSlug(PDO $pdo, string $base): string

    {

        $slug = preg_replace('/[^a-z0-9\-]/', '-', strtolower(trim($base))) ?: 'flow';

        $slug = trim(preg_replace('/-+/', '-', $slug), '-');

        if ($slug === '') {

            $slug = 'flow';

        }

        if (self::findBySlug($pdo, $slug) === null) {

            return $slug;

        }

        $n = 2;

        while ($n < 500) {

            $candidate = $slug . '-' . $n;

            if (self::findBySlug($pdo, $candidate) === null) {

                return $candidate;

            }

            $n += 1;

        }



        return $slug . '-' . bin2hex(random_bytes(3));

    }



    public static function duplicate(PDO $pdo, int $id): ?int

    {

        $row = self::find($pdo, $id);

        if ($row === null) {

            return null;

        }

        $baseSlug = (string)$row['slug'] . '-copy';

        $slug = self::uniqueSlug($pdo, $baseSlug);

        $title = trim((string)$row['title']);

        if ($title !== '' && !str_ends_with($title, '(копия)')) {

            $title .= ' (копия)';

        }



        return self::create($pdo, [

            'slug' => $slug,

            'title' => $title !== '' ? $title : 'Copy',

            'description' => (string)$row['description'],

            'course_slug' => (string)$row['course_slug'],

            'entry_mode' => (string)($row['entry_mode'] ?? self::ENTRY_DEMO_GRANT),

            'is_active' => false,

            'definition_json' => (string)$row['definition_json'],

            'avo_export_json' => $row['avo_export_json'] ?? null,

        ]);

    }



    public static function archive(PDO $pdo, int $id): bool

    {

        $row = self::find($pdo, $id);

        if ($row === null || self::isArchived($row)) {

            return false;

        }

        $now = gmdate('c');

        $pdo->prepare(

            'UPDATE email_automations SET archived_at = ?, is_active = 0, updated_at = ? WHERE id = ?'

        )->execute([$now, $now, $id]);



        return true;

    }



    public static function unarchive(PDO $pdo, int $id): bool

    {

        $row = self::find($pdo, $id);

        if ($row === null || !self::isArchived($row)) {

            return false;

        }

        $now = gmdate('c');

        $pdo->prepare(

            'UPDATE email_automations SET archived_at = NULL, updated_at = ? WHERE id = ?'

        )->execute([$now, $id]);



        return true;

    }



    public static function delete(PDO $pdo, int $id): bool

    {

        $row = self::find($pdo, $id);

        if ($row === null) {

            return false;

        }

        if (!empty($row['is_active'])) {

            return false;

        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM email_automation_runs WHERE automation_id = ? AND status = \'active\'');

        $stmt->execute([$id]);

        if ((int)$stmt->fetchColumn() > 0) {

            return false;

        }

        $pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$id]);



        return true;

    }

}


