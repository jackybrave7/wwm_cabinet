<?php
declare(strict_types=1);

/**
 * Administrator guide (shown at /admin/guide).
 *
 * When you add or change admin features, update this file and bump meta.revision.
 * See .cursor/rules/admin-guide.mdc
 *
 * @return array{
 *   meta: array{title: string, revision: string, changelog?: string},
 *   sections: list<array{
 *     id: string,
 *     title: string,
 *     access: string,
 *     body: list<array<string, mixed>>
 *   }>
 * }
 */
return [
    'meta' => [
        'title' => 'Administrator guide',
        'revision' => '2026-11-24',
        'changelog' => 'The admin interface and this guide are in English.',
    ],
    'sections' => [
        [
            'id' => 'start',
            'title' => 'Where to start',
            'access' => 'panel',
            'body' => [
                ['type' => 'p', 'text' => 'The WWM cabinet is the student course on one side and the school admin panel on the other. Students use the main site; administrators use the sections in the left menu.'],
                ['type' => 'p', 'text' => 'At the bottom of the menu, “← Student view” opens the course the way a student sees it.'],
                ['type' => 'info', 'text' => 'The menu only shows sections your account can open. If something is missing, ask a super-admin to grant access.'],
            ],
        ],
        [
            'id' => 'roles',
            'title' => 'Roles and access',
            'access' => 'panel',
            'body' => [
                ['type' => 'p', 'text' => 'Permissions are checkboxes on an administrator (Administrators) or an email list in the server config (super-admins from config).'],
                ['type' => 'ul', 'items' => [
                    'Dashboard — demos, payments, and Yandex Metrica (any admin access).',
                    'Courses — course structure, lessons, demo lessons, order, and content.',
                    'Students — accounts, access, manual demo or payment grants, correspondence.',
                    'Emails and Automations — super-admin only: email templates and sequences after a demo.',
                    'Broadcasts — one-off mailings (super-admin, or the Broadcasts permission).',
                    'Analytics — Yandex Metrica counter settings (super-admin).',
                    'Administrators — the team (super-admin only).',
                ]],
            ],
        ],
        [
            'id' => 'dashboard',
            'title' => 'Dashboard',
            'access' => 'dashboard',
            'body' => [
                ['type' => 'p', 'text' => 'A summary of demo grants and full access for the selected period, demo-to-payment conversion, and a chart by day. Cabinet numbers come from internal events; visits and goals come from Yandex Metrica when a counter is connected in Analytics.'],
                ['type' => 'ul', 'items' => [
                    'Period: yesterday, 7/30/90 days, a year, all time, or a custom range with a start and end date.',
                    'Chart grouping: by day or by month (for long periods).',
                    'Changing the period reloads the page. Metrica can take a moment to load the first time.',
                ]],
                ['type' => 'link', 'href' => '/admin/dashboard', 'label' => 'Open Dashboard'],
            ],
        ],
        [
            'id' => 'courses',
            'title' => 'Courses and lessons',
            'access' => 'courses',
            'body' => [
                ['type' => 'p', 'text' => 'Each course is a JSON description (sections, lessons, Kinescope video, HTML materials). The admin editor changes lesson order, demo flags, titles, and inserts.'],
                ['type' => 'ul', 'items' => [
                    'A demo lesson is the one that opens with demo access. It matters for “has not opened the lesson” reminders.',
                    'Do not publish a draft to production without checking it in Student view.',
                    'Large course edits on the server are sometimes built by scripts from Kinescope — check with a developer.',
                ]],
                ['type' => 'link', 'href' => '/admin/courses', 'label' => 'Course list'],
            ],
        ],
        [
            'id' => 'students',
            'title' => 'Students',
            'access' => 'students',
            'body' => [
                ['type' => 'p', 'text' => 'Search by email and name. A student card shows course access, demo, cabinet email history, and manual actions.'],
                ['type' => 'ul', 'items' => [
                    'Demo and payment usually arrive from the AVO/Tilda webhook. Duplicate orders in AVO are an integration concern, not a cabinet one.',
                    'Grant access by hand only for exceptions, and check the course slug.',
                    'The email tab shows sends with open tracking when tracking is on.',
                ]],
                ['type' => 'link', 'href' => '/admin/students', 'label' => 'Student list'],
            ],
        ],
        [
            'id' => 'emails',
            'title' => 'Email templates',
            'access' => 'emails',
            'body' => [
                ['type' => 'p', 'text' => 'Transactional templates (demo, reminders, discounts). HTML editor; variables are filled in when the letter is sent. Preview and a test send are on the template page.'],
                ['type' => 'ul', 'items' => [
                    'Demo and payment letters are often sent by the /api/demo and /api/payment webhooks — do not turn them off without a replacement.',
                    'Before a wide change, preview the letter and send one test to your own email.',
                    'Buttons and layout are repaired automatically before sending. Still check the preview.',
                ]],
                ['type' => 'warning', 'text' => 'Templates with webhook=true are called from AVO. If you turn Automations on, make sure the same letter is not sent twice (AVO business process and the cabinet).'],
                ['type' => 'link', 'href' => '/admin/emails', 'label' => 'Template list'],
            ],
        ],
        [
            'id' => 'automations',
            'title' => 'Email automations',
            'access' => 'automations',
            'body' => [
                ['type' => 'p', 'text' => 'Email sequences: pauses, conditions, letters, and a staff notice. A demo expires on the course timer (demo_hours) — there is no “revoke demo” block. Flows live in the database; a newly imported one is off by default.'],
                ['type' => 'ul', 'items' => [
                    '<strong>Blocks:</strong> Start — entry type and the process course (in the block properties). Grant demo — course, and skips when the course is already bought or a demo is already active. Pause — a duration, or wait until a date and time. Condition — yes and no branches (any payment, this course, an active demo, the lesson opened). Email — a template and a course slug; a cross-sell letter requires a course; optional “do not send if this course is already bought”. Staff — checkboxes for administrators (plus super-admins from config), subject and text with placeholders. End — a label for statistics (converted / left without buying).',
                    'While Active is off, students do not enter the sequence, cron sends nothing, and demo works as before through AVO.',
                    'When you turn Active on, turn off the matching business process in AVO. Right after entry the student moves through blocks until the first pause. After that, cron every 10 minutes: `/usr/bin/php8.1 /home/b/brateclis/my_worldwatercolormasters_art/scripts/run-email-automations.php >> /home/b/brateclis/automations.log 2>&1` (the same script also sets AVO logged_in tags from the queue after a fast sign-in). If someone is stuck on Start from before this deploy, use Run due steps on the process list.',
                    'Editor: a visual flow (blocks on the left, canvas, properties on the right) plus JSON under Advanced. Saving syncs the definition the engine uses.',
                    'Who is in the process: the “Students in this process” block on the editor page, and the Active runs column in the list. A student card has a Processes section. <strong>Remove</strong> stops an active run: cron will not send more letters. History stays (status “Removed”). A demo or a manual enroll after removal starts the sequence from the beginning; payment processes do not enroll the same person again.',
                    'A demo from the student card and <code>/api/demo</code> enroll when the process is on, the entry type is demo, and the course slug matches. Otherwise enroll the email by hand on the process page (suggestions start at 3 characters).',
                    'The public cabinet form <code>/demo/{slug}</code> (on a landing page, the script <code>/demo/{slug}/embed.js</code>) enrolls only processes whose entry type is <strong>Cabinet demo form (this course)</strong> and the same course. Entry “Demo for a course (AVO / API)” does not start from that form. The process must be Active.',
                    'Elke ENG funnel (<code>elke-en-demo-subscription</code>): 48-hour demo, reminders inside that window, then a 40% coupon for 24 hours after the demo, and a final letter 3 hours before the coupon ends. Updating the flow by <code>meta.flow_revision</code> does not reset active runs or their <code>current_node_id</code>.',
                    'On the canvas: counters on blocks (waiting / passed); a click opens the student list. Numbers appear when the flow is active.',
                    'AVO JSON import is a starting layout. Edit it in the cabinet after that.',
                    'Process list: <strong>Copy</strong> — a full copy of the flow (new slug, off); <strong>Archive</strong> — archive and turn off (the Archive tab); <strong>Delete</strong> — only an inactive process with no active runs (the history in the database is deleted too).',
                    'A bulk enroll is on <strong>Students</strong>: “Enroll in a process”. One student can be added by email on the process page.',
                    'The <strong>Post-purchase cross-sell</strong> process (slug <code>post-purchase-cross-sell</code>): entry “After any payment”, then after 30 days offers for Elke → La Fe → Alvaro → Angus (already bought courses are skipped), promo code <code>WWM5</code> (−50%, 48 hours), a reminder 5 hours before it ends; letters include unsubscribe the same way broadcasts do. Turn Active on after you have checked the flow; the promo code must be set up in AVO/payment.',
                ]],
                ['type' => 'warning', 'text' => 'After a deploy that changes the database schema, run `php8.1 scripts/migrate.php` once on the server. An FTP deploy does not run the migration by itself.'],
                ['type' => 'link', 'href' => '/admin/automations', 'label' => 'Automation list'],
            ],
        ],
        [
            'id' => 'broadcasts',
            'title' => 'Broadcasts',
            'access' => 'broadcasts',
            'body' => [
                ['type' => 'p', 'text' => 'One-off email campaigns for an audience (course, demo, payment, and so on). The editor is similar to letters; before sending, preview the letter and check the audience size.'],
                ['type' => 'ul', 'items' => [
                    'After sending, open the broadcast card: delivery, opens, links.',
                    'The letter preview uses a safe iframe. If the layout breaks, look for unclosed tags in the HTML.',
                ]],
                ['type' => 'link', 'href' => '/admin/broadcasts', 'label' => 'Broadcasts'],
            ],
        ],
        [
            'id' => 'analytics',
            'title' => 'Analytics (Metrica)',
            'access' => 'settings',
            'body' => [
                ['type' => 'p', 'text' => 'The Yandex Metrica counter id and the host filter for Dashboard visits. Without a counter the Metrica charts stay empty; cabinet numbers remain.'],
                ['type' => 'link', 'href' => '/admin/settings', 'label' => 'Analytics settings'],
            ],
        ],
        [
            'id' => 'admins',
            'title' => 'Team administrators',
            'access' => 'admins',
            'body' => [
                ['type' => 'p', 'text' => 'Create admin accounts, reset a password, and set Students / Courses / Broadcasts / Super. An email listed in config admin_emails cannot be removed from super-admins in the UI.'],
                ['type' => 'ul', 'items' => [
                    'Grant only the permissions someone needs.',
                    'Super admin is full access, including emails and automations.',
                ]],
                ['type' => 'link', 'href' => '/admin/admins', 'label' => 'Administrators'],
            ],
        ],
        [
            'id' => 'support',
            'title' => 'If something goes wrong',
            'access' => 'panel',
            'body' => [
                ['type' => 'ul', 'items' => [
                    '403 in the admin — missing permission; check the flags in Administrators.',
                    'A letter did not arrive — server log, the student card, and the spam folder; for automations, check that the flow is active and cron is running.',
                    'After a code update on the server — migrate.php, and if needed scripts/test-email-automations.php (with a developer).',
                ]],
                ['type' => 'p', 'text' => 'Technical details of the integrations (AVO, Tilda, Stripe) live in the bl-school repository and with the person who runs the infrastructure.'],
            ],
        ],
    ],
];
