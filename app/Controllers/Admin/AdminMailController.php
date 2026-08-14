<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\EmailTemplate;
use Wwm\Services\EmailTemplateCatalog;
use Wwm\Services\EmailTemplateRenderer;
use Wwm\Services\EmailTracker;
use Wwm\Services\EmailWebhookCatalog;
use Wwm\Services\Mailer;

final class AdminMailController
{
    public function index(): void
    {
        Session::requireAdmin();

        $mail = wwm_config()['mail'] ?? [];
        $webhooks = wwm_config()['webhooks'] ?? [];
        $customized = EmailTemplate::customizedIds(wwm_pdo());

        wwm_render_admin('emails', [
            'title' => 'Email templates — Admin',
            'adminNav' => 'emails',
            'templates' => EmailTemplateCatalog::all(),
            'customized' => $customized,
            'mailEnabled' => !empty($mail['enabled']),
            'fromEmail' => (string)($mail['from_email'] ?? ''),
            'webhooksEnabled' => !empty($webhooks['enabled']),
            'templateWebhooks' => $this->templateWebhooks(),
            'saveError' => (string)($_GET['error'] ?? ''),
        ]);
    }

    public function edit(string $id): void
    {
        Session::requireAdmin();

        $meta = EmailTemplateCatalog::find($id);
        if ($meta === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Email template not found.']);
            return;
        }

        $draft = EmailTemplateRenderer::forAdmin($id);
        $loadError = null;
        $builtin = EmailTemplateCatalog::placeholderDraft($id);
        if (trim((string)($draft['html'] ?? '')) === '' && !empty($meta['has_html'])) {
            $draft['html'] = $builtin['html'] ?? null;
            if (!empty($draft['customized'])) {
                $loadError = 'Saved HTML could not be loaded. Showing the built-in layout — save again to repair this template.';
            }
        }
        if (trim((string)($draft['text'] ?? '')) === '') {
            $draft['text'] = (string)($builtin['text'] ?? '');
        }
        if (trim((string)($draft['subject'] ?? '')) === '') {
            $draft['subject'] = (string)($builtin['subject'] ?? '');
        }
        $flash = null;
        if (isset($_GET['saved'])) {
            $flash = 'Template saved.';
        } elseif (isset($_GET['reset'])) {
            $flash = 'Template reset to default.';
        }

        wwm_render_admin('email-edit', [
            'title' => $meta['label'] . ' — Edit email',
            'adminNav' => 'emails',
            'emailTemplate' => $meta,
            'draft' => $draft,
            'variables' => EmailTemplateCatalog::variables($id),
            'webhook' => EmailWebhookCatalog::forTemplate($id),
            'webhooksEnabled' => !empty(wwm_config()['webhooks']['enabled']),
            'message' => $flash,
            'error' => $loadError,
        ]);
    }

    public function update(string $id): void
    {
        Session::requireAdmin();

        $id = trim($id);
        if ($id === '') {
            http_response_code(400);
            wwm_render('error', ['pageTitle' => 'Bad request', 'code' => 400, 'message' => 'Email template id is required.']);
            return;
        }

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            $this->renderEditError($id, 'Invalid request.');
            return;
        }

        if ($this->postPayloadTooLarge()) {
            $this->renderEditError(
                $id,
                'The template is too large for the server upload limit (post_max_size). '
                . 'Try saving a shorter HTML version or ask hosting to raise the limit.'
            );
            return;
        }

        $meta = EmailTemplateCatalog::find($id);
        if ($meta === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Email template not found.']);
            return;
        }

        $subject = trim(wwm_sanitize_utf8((string)($_POST['subject'] ?? '')));
        $bodyText = wwm_sanitize_utf8((string)($_POST['body_text'] ?? ''));
        $bodyHtml = wwm_sanitize_utf8((string)($_POST['body_html'] ?? ''));

        if ($subject === '') {
            $this->renderEditError($id, 'Subject is required.');
            return;
        }
        if (trim($bodyText) === '' && trim($bodyHtml) === '') {
            $this->renderEditError($id, 'Add plain text or HTML content.');
            return;
        }

        EmailTemplate::save(
            wwm_pdo(),
            $id,
            $subject,
            $bodyText,
            trim($bodyHtml) !== '' ? wwm_repair_email_html($bodyHtml) : null
        );

        wwm_redirect('/admin/emails/' . rawurlencode($id) . '/edit?saved=1');
    }

    public function save(string $id): void
    {
        $this->update($id);
    }

    public function saveLegacy(): void
    {
        $id = trim((string)($_POST['template_id'] ?? ''));
        if ($id === '') {
            wwm_redirect('/admin/emails?error=save');
            return;
        }

        $this->update($id);
    }

    public function reset(string $id): void
    {
        Session::requireAdmin();

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            $this->renderEditError($id, 'Invalid request.');
            return;
        }

        if (EmailTemplateCatalog::find($id) === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Email template not found.']);
            return;
        }

        EmailTemplate::delete(wwm_pdo(), $id);
        wwm_redirect('/admin/emails?reset=1');
    }

    public function preview(string $id): void
    {
        Session::requireAdmin();

        $meta = EmailTemplateCatalog::find($id);
        if ($meta === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Email template not found.']);
            return;
        }

        try {
            $message = EmailTemplateCatalog::preview($id);
        } catch (\InvalidArgumentException) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Email template not found.']);
            return;
        }

        wwm_render_admin('email-preview', [
            'title' => $meta['label'] . ' — Email preview',
            'adminNav' => 'emails',
            'emailTemplate' => $meta,
            'message' => $message,
            'webhook' => EmailWebhookCatalog::forTemplate($id),
            'webhooksEnabled' => !empty(wwm_config()['webhooks']['enabled']),
        ]);
    }

    public function test(): void
    {
        Session::requireAdmin();

        $to = trim((string)($_GET['to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            wwm_json_response(400, ['ok' => false, 'error' => 'invalid_email']);
        }

        $cfg = wwm_config()['mail'] ?? [];
        $smtpUser = trim((string)($cfg['smtp_user'] ?? ''));
        $smtpPass = (string)($cfg['smtp_pass'] ?? '');
        $message = EmailTemplateRenderer::render('test', ['base_url' => wwm_base_url()]);
        $sent = EmailTracker::compose(null, $to, 'test', $message['subject'])
            ->deliver($message['text'], $message['html'], []);

        wwm_json_response(200, [
            'ok' => $sent,
            'mail_enabled' => !empty($cfg['enabled']),
            'smtp_host' => (string)($cfg['smtp_host'] ?? ''),
            'smtp_user' => $smtpUser,
            'smtp_pass_length' => strlen($smtpPass),
            'from_email' => (string)($cfg['from_email'] ?? ''),
            'error' => Mailer::lastError(),
        ]);
    }

    private function renderEditError(string $id, string $error): void
    {
        $meta = EmailTemplateCatalog::find($id);
        if ($meta === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Email template not found.']);
            return;
        }

        wwm_render_admin('email-edit', [
            'title' => $meta['label'] . ' — Edit email',
            'adminNav' => 'emails',
            'emailTemplate' => $meta,
            'draft' => [
                'subject' => (string)($_POST['subject'] ?? ''),
                'text' => (string)($_POST['body_text'] ?? ''),
                'html' => (string)($_POST['body_html'] ?? ''),
                'customized' => true,
            ],
            'variables' => EmailTemplateCatalog::variables($id),
            'webhook' => EmailWebhookCatalog::forTemplate($id),
            'webhooksEnabled' => !empty(wwm_config()['webhooks']['enabled']),
            'message' => null,
            'error' => $error,
        ]);
    }

    /**
     * @return array<string, array{url: string, token_label: string, endpoint: string}|null>
     */
    private function templateWebhooks(): array
    {
        $webhooks = [];
        foreach (EmailTemplateCatalog::all() as $template) {
            $id = (string)$template['id'];
            $webhooks[$id] = EmailWebhookCatalog::forTemplate($id);
        }

        return $webhooks;
    }

    private function postPayloadTooLarge(): bool
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            return false;
        }

        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length <= 0) {
            return false;
        }

        return $_POST === [] && $_FILES === [];
    }
}
