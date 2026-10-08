<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Services\CourseCatalog;
use Wwm\Services\DemoAccess;
use Wwm\Services\DemoLead;
use Wwm\Services\DemoLeadEmbed;
use Wwm\Services\DemoLeadRateLimit;
use Wwm\Services\EmailAutomationEnrollment;

final class DemoLeadController
{
    public function show(string $slug): void
    {
        $course = $this->course($slug);
        if ($course === null) {
            $this->notFound();
            return;
        }

        $copy = DemoLead::copy($course);
        header('Cache-Control: private, no-store');
        wwm_render('demo-lead', [
            'pageTitle' => $copy['title'],
            'course' => $course,
            'copy' => $copy,
            'error' => null,
            'success' => false,
            'email' => '',
            'name' => '',
        ]);
    }

    public function submit(string $slug): void
    {
        $course = $this->course($slug);
        if ($course === null) {
            $this->notFound();
            return;
        }

        $copy = DemoLead::copy($course);
        $embed = (string)($_POST['embed'] ?? '') === '1';
        $origin = $this->requestOrigin();

        if ($embed) {
            if (!DemoLead::originAllowed($origin)) {
                $this->respond($embed, $course, $copy, 403, false, 'forbidden', $copy['error_generic'], '', '');
                return;
            }
            $this->cors($origin);
        } elseif (!wwm_verify_csrf(isset($_POST['csrf']) ? (string)$_POST['csrf'] : null)) {
            $this->respond($embed, $course, $copy, 400, false, 'csrf', 'Invalid request.', '', '');
            return;
        }

        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $name = trim(strip_tags((string)($_POST['name'] ?? '')));
        $name = mb_substr($name, 0, 80);
        $company = trim((string)($_POST['company'] ?? ''));

        if ($company !== '') {
            $this->respond($embed, $course, $copy, 200, true, '', $copy['success'], $email, $name);
            return;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $this->respond($embed, $course, $copy, 400, false, 'invalid_email', $copy['error_email'], $email, $name);
            return;
        }

        $ip = wwm_client_ip() ?? 'unknown';
        $pdo = wwm_pdo();
        if (DemoLeadRateLimit::tooMany($pdo, $ip, $email)) {
            $this->respond($embed, $course, $copy, 429, false, 'rate_limited', $copy['error_rate'], $email, $name);
            return;
        }
        DemoLeadRateLimit::hit($pdo, $ip, $email);

        try {
            $result = (new DemoAccess())->grant(
                $email,
                $name,
                (string)$course['slug'],
                DemoAccess::SOURCE_CABINET_FORM,
                'demo-form',
                DemoLead::utmFromPost($_POST),
                null,
                null,
                true,
                true
            );
        } catch (\InvalidArgumentException) {
            $this->respond($embed, $course, $copy, 400, false, 'invalid_email', $copy['error_email'], $email, $name);
            return;
        } catch (\Throwable $e) {
            wwm_log('demo form failed: ' . $e->getMessage());
            $this->respond($embed, $course, $copy, 500, false, 'grant_failed', $copy['error_generic'], $email, $name);
            return;
        }

        if (empty($result['paid'])) {
            EmailAutomationEnrollment::onCabinetDemoForm((int)$result['user_id'], (string)$result['course_slug']);
        }

        wwm_log(sprintf(
            'demo form user_id=%d course=%s paid=%s granted=%s',
            (int)$result['user_id'],
            (string)$result['course_slug'],
            !empty($result['paid']) ? 'yes' : 'no',
            !empty($result['demo_granted']) ? 'yes' : 'no'
        ));

        $this->respond($embed, $course, $copy, 200, true, '', $copy['success'], $email, $name);
    }

    public function embedScript(string $slug): void
    {
        $course = $this->course($slug);
        if ($course === null) {
            http_response_code(404);
            header('Content-Type: application/javascript; charset=utf-8');
            echo '/* unknown course */';
            return;
        }

        $postUrl = wwm_base_url() . '/demo/' . rawurlencode((string)$course['slug']);
        $js = DemoLeadEmbed::script(
            (string)$course['slug'],
            $postUrl,
            DemoLead::copy($course),
            (string)($_GET['button'] ?? '') === '1',
            (string)($_GET['open'] ?? '') === '1',
            (string)($_GET['inline'] ?? '') === '1'
        );

        header('Content-Type: application/javascript; charset=utf-8');
        // Short cache: Tilda embeds need fast updates after deploy.
        header('Cache-Control: public, max-age=60');
        header('X-Content-Type-Options: nosniff');
        echo $js;
    }

    public function preflight(string $slug): void
    {
        $origin = $this->requestOrigin();
        if ($this->course($slug) === null || !DemoLead::originAllowed($origin)) {
            http_response_code(403);
            return;
        }
        $this->cors($origin);
        http_response_code(204);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function course(string $slug): ?array
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?: '';
        if ($slug === '') {
            return null;
        }

        return (new CourseCatalog())->get($slug);
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Course not found';
    }

    private function requestOrigin(): string
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '') {
            return $origin;
        }

        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        $parts = $referer !== '' ? parse_url($referer) : false;
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        return $scheme . '://' . strtolower((string)$parts['host']);
    }

    private function cors(string $origin): void
    {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
    }

    /**
     * @param array<string, mixed> $course
     * @param array<string, string> $copy
     */
    private function respond(
        bool $embed,
        array $course,
        array $copy,
        int $code,
        bool $ok,
        string $error,
        string $message,
        string $email,
        string $name
    ): void {
        header('Cache-Control: private, no-store');
        if ($embed || $this->wantsJson()) {
            wwm_json_response($code, [
                'ok' => $ok,
                'error' => $error,
                'message' => $message,
            ]);
        }

        http_response_code($code);
        wwm_render('demo-lead', [
            'pageTitle' => $copy['title'],
            'course' => $course,
            'copy' => $copy,
            'error' => $ok ? null : $message,
            'success' => $ok,
            'email' => $email,
            'name' => $name,
        ]);
    }

    private function wantsJson(): bool
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json');
    }
}
