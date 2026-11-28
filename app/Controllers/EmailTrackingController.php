<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Services\EmailTracker;
use Wwm\Services\ForgotPasswordRequest;

final class EmailTrackingController
{
    public function open(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            http_response_code(404);
            return;
        }

        EmailTracker::recordOpen($token);

        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo EmailTracker::openPixelBody();
    }

    public function click(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            http_response_code(404);
            wwm_render('error', [
                'pageTitle' => 'Not found',
                'code' => 404,
                'message' => 'Link not found.',
            ]);
            return;
        }

        $link = EmailTracker::openTrackedLink($token);
        $target = is_array($link) ? (string)$link['target_url'] : '';
        if ($target === '') {
            http_response_code(404);
            wwm_render('error', [
                'pageTitle' => 'Not found',
                'code' => 404,
                'message' => 'Link not found or expired.',
            ]);
            return;
        }

        if (!str_starts_with($target, 'http://') && !str_starts_with($target, 'https://') && !str_starts_with($target, '/')) {
            http_response_code(400);
            wwm_render('error', [
                'pageTitle' => 'Invalid link',
                'code' => 400,
                'message' => 'Invalid redirect target.',
            ]);
            return;
        }

        $target = EmailTracker::safeRedirectTarget($target);
        $forgot = ForgotPasswordRequest::redirectForTrackedForgot(
            $target,
            (string)($link['to_email'] ?? '')
        );
        wwm_redirect($forgot ?? $target);
    }
}
