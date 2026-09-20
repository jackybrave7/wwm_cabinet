<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Models\EmailSuppression;
use Wwm\Models\User;
use Wwm\Services\BroadcastUnsubscribe;

final class BroadcastUnsubscribeController
{
    public function show(): void
    {
        $token = (string)($_GET['t'] ?? '');
        $verified = BroadcastUnsubscribe::verifyToken($token);
        if ($verified === null) {
            http_response_code(400);
            wwm_render('error', [
                'pageTitle' => 'Invalid link',
                'code' => 400,
                'message' => 'This unsubscribe link is invalid or expired.',
            ]);
            return;
        }

        if ($this->isOneClickPost()) {
            $this->performUnsubscribe($verified['user_id'], $verified['email']);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Unsubscribed';
            return;
        }

        $user = User::findById(wwm_pdo(), $verified['user_id']);
        wwm_render('unsubscribe', [
            'pageTitle' => 'Unsubscribe',
            'email' => $verified['email'],
            'token' => $token,
            'name' => is_array($user) ? trim((string)($user['name'] ?? '')) : '',
        ]);
    }

    public function confirm(): void
    {
        $token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
        $verified = BroadcastUnsubscribe::verifyToken($token);
        if ($verified === null) {
            http_response_code(400);
            wwm_render('error', [
                'pageTitle' => 'Invalid link',
                'code' => 400,
                'message' => 'This unsubscribe link is invalid or expired.',
            ]);
            return;
        }

        if ($this->isOneClickPost()) {
            $this->performUnsubscribe($verified['user_id'], $verified['email']);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Unsubscribed';
            return;
        }

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/email/unsubscribe?t=' . rawurlencode($token) . '&error=csrf');
        }

        $this->performUnsubscribe($verified['user_id'], $verified['email']);
        wwm_render('unsubscribe', [
            'pageTitle' => 'Unsubscribed',
            'email' => $verified['email'],
            'token' => $token,
            'done' => true,
        ]);
    }

    private function isOneClickPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return false;
        }
        $list = $_POST['List-Unsubscribe'] ?? $_SERVER['HTTP_LIST_UNSUBSCRIBE'] ?? '';
        if (is_string($list) && stripos($list, 'One-Click') !== false) {
            return true;
        }

        return isset($_POST['List-Unsubscribe']) && (string)$_POST['List-Unsubscribe'] === 'One-Click';
    }

    private function performUnsubscribe(int $userId, string $email): void
    {
        EmailSuppression::suppress(wwm_pdo(), $email, $userId, 'unsubscribe');
        wwm_log('marketing unsubscribe user_id=' . $userId . ' email=' . $email);
    }
}
