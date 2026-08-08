<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Services\EmailTracker;

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

        $target = EmailTracker::clickTarget($token);
        if ($target === null || $target === '') {
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

        wwm_redirect($target);
    }
}
