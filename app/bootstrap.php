<?php
declare(strict_types=1);

const WWM_ROOT = __DIR__ . '/..';

$configPath = WWM_ROOT . '/config/config.php';
if (!is_readable($configPath)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "WWM Cabinet: missing config/config.php (copy from config.example.php)\n");
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'WWM Cabinet: missing config/config.php (copy from config.example.php)';
    exit(1);
}

/** @var array<string, mixed> $config */
$config = require $configPath;

date_default_timezone_set((string)($config['timezone'] ?? 'UTC'));

$dataDir = WWM_ROOT . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0750, true);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Wwm\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = WWM_ROOT . '/app/' . $relative . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

$pdo = Wwm\Database::connect((string)$config['db_path']);
Wwm\Database::migrateIfNeeded($pdo);

if (PHP_SAPI !== 'cli') {
    $requestPath = wwm_request_path();
    $requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $isApiRoute = str_starts_with($requestPath, '/api/') || str_starts_with($requestPath, '/t/');

    session_name('wwm_cabinet');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!$isApiRoute && session_status() !== PHP_SESSION_ACTIVE) {
        $readOnlySession = ($requestMethod === 'GET' || $requestMethod === 'HEAD')
            && !wwm_session_needs_write();
        if ($readOnlySession) {
            session_start(['read_and_close' => true]);
        } else {
            session_start();
            Wwm\Services\StudentAttribution::captureFromRequest();
        }
    }
}

function wwm_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    return rtrim($path, '/') ?: '/';
}

function wwm_session_needs_write(): bool
{
    $path = wwm_request_path();
    if (in_array($path, ['/auth/magic', '/logout'], true)) {
        return true;
    }
    if ($path === '/login'
        && trim((string)($_GET['email'] ?? '')) !== ''
        && (string)($_GET['password'] ?? '') !== '') {
        return true;
    }
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
        if (trim((string)($_GET[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function wwm_client_ip(): ?string
{
    $candidates = [];

    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $candidates[] = trim($part);
        }
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = (string)$_SERVER['REMOTE_ADDR'];
    }

    foreach ($candidates as $ip) {
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return null;
}

function wwm_config(): array
{
    global $config;
    return $config;
}

function wwm_pdo(): PDO
{
    global $pdo;
    return $pdo;
}

function wwm_log(string $message): void
{
    $cfg = wwm_config();
    if (empty($cfg['log_enabled'])) {
        return;
    }
    $line = '[' . date('c') . '] ' . $message;
    error_log('wwm-cabinet: ' . $message);
    $file = (string)($cfg['log_file'] ?? '');
    if ($file !== '') {
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function wwm_escape(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function wwm_asset_url(string $path): string
{
    $relative = ltrim($path, '/');
    $candidates = [
        WWM_ROOT . '/public_html/assets/' . $relative,
        WWM_ROOT . '/public/assets/' . $relative,
    ];
    $version = (string)(wwm_config()['asset_version'] ?? '');
    foreach ($candidates as $file) {
        if (is_readable($file)) {
            $version = (string)filemtime($file);
            break;
        }
    }
    if ($version === '') {
        $version = '1';
    }
    $configVersion = (string)(wwm_config()['asset_version'] ?? '');
    if ($configVersion !== '') {
        $version .= '-' . $configVersion;
    }

    return '/assets/' . $relative . '?v=' . rawurlencode($version);
}

function wwm_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function wwm_verify_csrf(?string $token): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $expected !== '' && hash_equals($expected, $token);
}

function wwm_redirect(string $path, int $code = 302): never
{
    if (!str_starts_with($path, 'http')) {
        $path = wwm_base_url() . ($path === '' ? '/' : $path);
    }
    header('Location: ' . $path, true, $code);
    exit;
}

function wwm_base_url(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $base = rtrim((string)(wwm_config()['base_url'] ?? ''), '/');
    if ($base !== '' && str_starts_with($base, 'http')) {
        return $resolved = $base;
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
        return $resolved = ($https ? 'https' : 'http') . '://' . $host;
    }

    return $resolved = 'https://my.worldwatercolormasters.art';
}

function wwm_sanitize_internal_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return '/';
    }

    return $path;
}

function wwm_login_url(string $email, ?string $password = null, string $next = '/'): string
{
    $email = trim($email);
    $next = wwm_sanitize_internal_path($next);
    $params = ['email' => $email];
    if ($password !== null && $password !== '') {
        $params['password'] = $password;
    }
    if ($next !== '/') {
        $params['next'] = $next;
    }

    $base = wwm_base_url();
    return $base . '/login?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function wwm_course_cover_url(?string $url): ?string
{
    $url = trim((string)$url);
    if ($url === '' || !str_starts_with($url, 'https://')) {
        return null;
    }

    return $url;
}

function wwm_email_logo_url(): string
{
    $configured = trim((string)(wwm_config()['email_logo_url'] ?? wwm_config()['email_sale_logo_url'] ?? ''));
    if ($configured !== '' && str_starts_with($configured, 'https://')) {
        return $configured;
    }

    return 'https://static.tildacdn.com/tild3666-3831-4932-b039-356262326639/World_Watercolor_Mas.jpg';
}

function wwm_email_logo_block_html(string $siteUrl = 'https://worldwatercolormasters.art'): string
{
    $href = htmlspecialchars($siteUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '<a href="' . $href . '" style="text-decoration:none;display:block;width:100%;text-align:center;">'
        . '<span class="email-logo" style="display:block;width:100%;text-align:center;'
        . 'font-family:\'Playfair Display\',Georgia,\'Times New Roman\',serif;font-weight:900;font-size:36px;'
        . 'line-height:1.15;color:#1a110a;letter-spacing:-0.02em;">'
        . 'World Watercolor <em style="font-style:italic;font-weight:400;color:#c0440e;">Masters</em>'
        . '</span></a>'
        . '<p style="margin:10px 0 0;font-size:12px;line-height:1.4;color:#6e6e6e;text-align:center;width:100%;">'
        . 'by Bratec Lis School</p>';
}

function wwm_email_logo_row_html(string $siteUrl = 'https://worldwatercolormasters.art'): string
{
    return '<tr><td class="pad" align="center" style="padding:32px 40px 8px;background:#ffffff;width:100%;">'
        . wwm_email_logo_block_html($siteUrl)
        . '</td></tr>';
}

/**
 * @return list<string>
 */
function wwm_email_logo_legacy_urls(): array
{
    return [
        'https://f1.autoweboffice.ru/bl-school/Watercolor_masters/World%20Watercolor%20Masters.jpg',
        'https://f1.autoweboffice.ru/bl-school/Watercolor_masters/World Watercolor Masters.jpg',
        'https://static.tildacdn.com/tild3666-3831-4932-b039-356262326639/World_Watercolor_Mas.jpg',
    ];
}

function wwm_normalize_email_logo_html(?string $html, bool $usePlaceholder = false): ?string
{
    if ($html === null || $html === '') {
        return $html;
    }

    unset($usePlaceholder);

    $logoRow = wwm_email_logo_row_html();
    $out = $html;

    $replaced = preg_replace(
        '#<tr><td class="pad" align="center" style="padding:(?:28|32)px 40px (?:4|8)px;background:#ffffff;[^"]*">\s*'
        . '(?:<a[^>]*>\s*<img[^>]*>\s*</a>|'
        . '<a[^>]*>\s*<span[^>]*>World Watercolor.*?</span>\s*</a>)'
        . '(?:\s*<p style="margin:[^"]*">by Bratec Lis School</p>)?'
        . '\s*</td></tr>#is',
        $logoRow,
        $out,
        1
    );
    if (is_string($replaced)) {
        $out = $replaced;
    }

    foreach (wwm_email_logo_legacy_urls() as $old) {
        $out = str_replace($old, '', $out);
    }

    $out = preg_replace(
        '#https?://f1\.autoweboffice\.ru/bl-school/Watercolor_masters/World[^"\'>\s]*#i',
        '',
        $out
    ) ?? $out;

    return $out;
}

function wwm_repair_email_html(?string $html): ?string
{
    if ($html === null || $html === '') {
        return $html;
    }

    $out = $html;
    foreach (['table', 'tr', 'td', 'th', 'p', 'a', 'span', 'div'] as $tag) {
        $out = preg_replace('/<\s+' . $tag . '\b/i', '<' . $tag, $out) ?? $out;
        $out = preg_replace('/<\s+\/' . $tag . '\b/i', '</' . $tag, $out) ?? $out;
    }

    $out = preg_replace('/&\s+#(\d+);/', '&#$1;', $out) ?? $out;
    $out = preg_replace('/&\s+#x([0-9a-f]+);/i', '&#x$1;', $out) ?? $out;

    $couponOpen = '<td style="padding:20px 24px;text-align:center;">';
    $couponTable = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="margin:8px 0 20px;background:#faf6f0;border-radius:8px;border:1px solid #e5e5e5;"><tr>'
        . $couponOpen;
    if (str_contains($out, $couponOpen) && !str_contains($out, 'Your coupon code</p>')) {
        // partial corruption — skip
    } elseif (
        str_contains($out, $couponOpen)
        && !preg_match('/<table[^>]*>\s*<tr>\s*' . preg_quote($couponOpen, '/') . '/i', $out)
    ) {
        $out = str_replace($couponOpen, $couponTable, $out);
    }

    $credTd = '<td style="padding:20px 24px;">';
    $credDiv = '<div style="margin-top:8px;padding:20px 24px;background:#faf6f0;border-radius:8px;border:1px solid #e5e5e5;">';
    if (
        str_contains($out, 'Sign-in details</p>')
        && str_contains($out, $credTd)
        && !str_contains($out, '<!-- credentials:start -->')
        && !str_contains($out, $credDiv)
    ) {
        $out = str_replace($credTd, $credDiv, $out);
        $out = str_replace('</td></tr></table><!-- credentials:end -->', '</div><!-- credentials:end -->', $out);
        $out = preg_replace(
            '#<table role="presentation" width="100%"[^>]*>\s*<tr>\s*'
            . preg_quote($credDiv, '#')
            . '#i',
            '<!-- credentials:start -->' . $credDiv,
            $out,
            1
        ) ?? $out;
    }

    return wwm_normalize_email_logo_html($out);
}

/**
 * @return list<string>
 */
function wwm_email_html_issues(?string $html): array
{
    if ($html === null || trim($html) === '') {
        return [];
    }

    $issues = [];
    if (!str_starts_with(ltrim($html), '<!DOCTYPE')) {
        $issues[] = 'missing doctype';
    }
    if (preg_match('/<\s+(td|tr|table|p|a|span|div)\b/i', $html)) {
        $issues[] = 'broken tag spacing (visual editor corruption)';
    }
    if (preg_match('/&\s+#/i', $html)) {
        $issues[] = 'broken html entity';
    }
    if (preg_match('/\{\{[^}]+\}\}/', $html)) {
        $issues[] = 'unresolved placeholder';
    }
    if (str_contains($html, 'f1.autoweboffice.ru/bl-school/Watercolor_masters/World')) {
        $issues[] = 'legacy image logo url';
    }
    if (!str_contains($html, 'World Watercolor')) {
        $issues[] = 'missing text logo';
    }
    if (preg_match('/<img[^>]+src="[^"]*(?:logo|Watercolor)[^"]*"/i', $html)) {
        $issues[] = 'image logo in header';
    }

    $openTables = substr_count(strtolower($html), '<table');
    $closeTables = substr_count(strtolower($html), '</table>');
    if ($openTables !== $closeTables) {
        $issues[] = 'unbalanced table tags (' . $openTables . ' open, ' . $closeTables . ' close)';
    }

    $openTr = substr_count(strtolower($html), '<tr');
    $closeTr = substr_count(strtolower($html), '</tr>');
    if ($openTr !== $closeTr) {
        $issues[] = 'unbalanced tr tags (' . $openTr . ' open, ' . $closeTr . ' close)';
    }

    return $issues;
}

function wwm_email_sale_logo_url(): string
{
    return wwm_email_logo_url();
}

function wwm_email_logo_url_for_template(string $templateId): string
{
    return wwm_email_logo_url();
}

function wwm_email_sample_cover_url(): string
{
    return 'https://f1.autoweboffice.ru/bl-school/Watercolor_masters/Elke_Memmler/elkeflowers.jpg';
}

function wwm_render(string $template, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $templateFile = WWM_ROOT . '/templates/' . $template . '.php';
    if (!is_readable($templateFile)) {
        http_response_code(500);
        echo 'Template not found';
        exit;
    }
    ob_start();
    require $templateFile;
    $content = (string)ob_get_clean();
    $title = $pageTitle ?? wwm_config()['app_name'] ?? 'WWM';
    $layout = $layout ?? 'layout';
    require WWM_ROOT . '/templates/' . $layout . '.php';
}

function wwm_render_admin(string $template, array $vars = []): void
{
    $vars['layout'] = 'admin/layout';
    $vars['adminNav'] = $vars['adminNav'] ?? '';
    wwm_render('admin/' . $template, $vars);
}

function wwm_json_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wwm_normalize_video_embed_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        $url = 'https://' . ltrim($url, '/');
    }

    if (preg_match('/kinescope\.io\/embed\/([a-zA-Z0-9_-]+)/i', $url, $match)) {
        return 'https://kinescope.io/embed/' . $match[1];
    }
    if (preg_match('/kinescope\.io\/([a-zA-Z0-9_-]+)/i', $url, $match) && strcasecmp($match[1], 'embed') !== 0) {
        return 'https://kinescope.io/embed/' . $match[1];
    }
    if (preg_match('/player\.vimeo\.com\/video\/(\d+)/i', $url, $match)) {
        return 'https://player.vimeo.com/video/' . $match[1];
    }
    if (preg_match('/vimeo\.com\/(\d+)/i', $url, $match)) {
        return 'https://player.vimeo.com/video/' . $match[1];
    }
    if (preg_match('/youtube(?:-nocookie)?\.com\/embed\/([^?&\/]+)/i', $url, $match)) {
        return 'https://www.youtube.com/embed/' . $match[1];
    }
    if (preg_match('/[?&]v=([^&]+)/', $url, $match) && preg_match('/youtube/i', $url)) {
        return 'https://www.youtube.com/embed/' . $match[1];
    }
    if (preg_match('/youtu\.be\/([^?&\/]+)/i', $url, $match)) {
        return 'https://www.youtube.com/embed/' . $match[1];
    }

    return $url;
}

function wwm_sanitize_video_embed_url(string $url): ?string
{
    $url = wwm_normalize_video_embed_url($url);
    if ($url === null) {
        return null;
    }
    if (!str_starts_with($url, 'https://')) {
        return null;
    }

    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    $allowedHosts = [
        'kinescope.io',
        'player.vimeo.com',
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
    ];
    $hostOk = false;
    foreach ($allowedHosts as $allowed) {
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            $hostOk = true;
            break;
        }
    }
    if (!$hostOk) {
        return null;
    }

    if (preg_match('/kinescope\.io\/embed\//i', $url)) {
        return $url;
    }
    if (preg_match('/player\.vimeo\.com\/video\//i', $url)) {
        return $url;
    }
    if (preg_match('/youtube(?:-nocookie)?\.com\/embed\//i', $url)) {
        return $url;
    }

    return null;
}

function wwm_sanitize_lesson_image_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (str_starts_with($url, '/')) {
        if (!preg_match('#^/assets/courses/[a-z0-9\-./_]+$#i', $url)) {
            return null;
        }
        return $url;
    }

    if (!str_starts_with($url, 'https://')) {
        return null;
    }

    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    $allowedHosts = [
        'my.worldwatercolormasters.art',
        'worldwatercolormasters.art',
        'localhost',
        '127.0.0.1',
    ];
    foreach ($allowedHosts as $allowed) {
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
            if (preg_match('#^/assets/courses/[a-z0-9\-./_]+$#i', $path)) {
                return $path;
            }
            return null;
        }
    }

    return null;
}

/** @return list<string> */
function wwm_lesson_allowed_classes(): array
{
    return [
        'materials-sheet',
        'materials-intro',
        'materials-items',
        'reference-panel',
        'reference-media',
        'reference-download',
    ];
}

function wwm_sanitize_lesson_opening_tag(string $tag, string $attrs): string
{
    $tag = strtolower($tag);
    $parts = [];

    if (preg_match('/\bclass=["\']([^"\']+)["\']/i', $attrs, $classMatch)) {
        $allowed = wwm_lesson_allowed_classes();
        $classes = array_values(array_intersect(
            preg_split('/\s+/', trim($classMatch[1])) ?: [],
            $allowed
        ));
        if ($classes !== []) {
            $parts[] = 'class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
    }

    if ($tag === 'a') {
        if (preg_match('/\bhref=["\']([^"\']+)["\']/i', $attrs, $hrefMatch)) {
            $href = wwm_sanitize_lesson_image_url($hrefMatch[1]);
            if ($href !== null) {
                $parts[] = 'href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        if (preg_match('/\bdownload=["\']([^"\']*)["\']/i', $attrs, $downloadMatch)) {
            $download = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($downloadMatch[1]));
            if ($download !== '') {
                $parts[] = 'download="' . htmlspecialchars($download, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
    }

    return '<' . $tag . ($parts !== [] ? ' ' . implode(' ', $parts) : '') . '>';
}

function wwm_sanitize_lesson_html(?string $html): string
{
    $html = trim((string)$html);
    if ($html === '') {
        return '';
    }

    $videoBlocks = [];
    $imageBlocks = [];
    $html = preg_replace_callback(
        '/<img[^>]*>/i',
        static function (array $matches) use (&$imageBlocks): string {
            $tag = $matches[0];
            if (!preg_match('/\bsrc=["\']([^"\']+)["\']/i', $tag, $srcMatch)) {
                return '';
            }
            $src = wwm_sanitize_lesson_image_url($srcMatch[1]);
            if ($src === null) {
                return '';
            }
            $alt = '';
            if (preg_match('/\balt=["\']([^"\']*)["\']/i', $tag, $altMatch)) {
                $alt = htmlspecialchars($altMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $altAttr = $alt !== '' ? ' alt="' . $alt . '"' : ' alt=""';
            $safeSrc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $imageBlocks[] = '<img src="' . $safeSrc . '"' . $altAttr . ' loading="lazy" class="lesson-image">';
            return '%%WWIMG' . (count($imageBlocks) - 1) . '%%';
        },
        $html
    ) ?? $html;

    $html = preg_replace_callback(
        '/<div[^>]*\bvideo-block\b[^>]*>.*?<\/div>/is',
        static function (array $matches) use (&$videoBlocks): string {
            $block = $matches[0];
            if (!preg_match('/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $block, $iframe)) {
                return '';
            }
            $src = wwm_sanitize_video_embed_url($iframe[1]);
            if ($src === null) {
                return '';
            }
            $title = '';
            if (preg_match('/\btitle=["\']([^"\']*)["\']/i', $block, $titleMatch)) {
                $title = htmlspecialchars($titleMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $titleAttr = $title !== '' ? ' title="' . $title . '"' : '';
            $safeSrc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $videoBlocks[] = '<div class="video-block"><iframe src="' . $safeSrc . '" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen loading="lazy"' . $titleAttr . '></iframe></div>';
            return '%%WWVIDEO' . (count($videoBlocks) - 1) . '%%';
        },
        $html
    ) ?? $html;

    $openTags = [];
    $html = preg_replace_callback(
        '/<(div|ol|p|a|span)\b([^>]*)>/i',
        static function (array $matches) use (&$openTags): string {
            $openTags[] = wwm_sanitize_lesson_opening_tag($matches[1], $matches[2] ?? '');
            return '%%WWOTAG' . (count($openTags) - 1) . '%%';
        },
        $html
    ) ?? $html;

    $allowed = '<h2><h3><p><strong><em><b><i><u><ul><ol><li><a><br><div><span>';
    $html = strip_tags($html, $allowed);

    foreach ($openTags as $i => $tag) {
        $html = str_replace('%%WWOTAG' . $i . '%%', $tag, $html);
    }

    foreach ($videoBlocks as $i => $block) {
        $html = str_replace('%%WWVIDEO' . $i . '%%', $block, $html);
    }
    foreach ($imageBlocks as $i => $block) {
        $html = str_replace('%%WWIMG' . $i . '%%', $block, $html);
    }

    return trim($html);
}

function wwm_finalize_lesson_html(?string $html): string
{
    $html = wwm_sanitize_lesson_html($html);
    if ($html === '') {
        return '';
    }

    $prev = '';
    while ($html !== $prev) {
        $prev = $html;
        $html = preg_replace(
            '/(<div class="video-block"><iframe src="([^"]+)"[^>]*><\/iframe><\/div>)\s*\1/',
            '$1',
            $html
        ) ?? $html;
    }

    $html = preg_replace('/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', $html) ?? $html;
    $html = preg_replace('/<div>(\s|&nbsp;|<br\s*\/?>)*<\/div>/i', '', $html) ?? $html;

    if (!preg_match('/<(div|ol|ul|h2|h3)\b/i', $html)) {
        $html = preg_replace_callback(
            '/>([^<]+)</',
            static function (array $matches): string {
                $text = trim($matches[1]);
                if ($text === '') {
                    return '><';
                }

                return '><p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><';
            },
            $html
        ) ?? $html;
    }

    return trim($html);
}

/**
 * @param array<string, mixed> $lesson
 */
function wwm_lesson_body_html(array $lesson): string
{
    $html = trim((string)($lesson['html_body'] ?? ''));
    if ($html !== '') {
        return wwm_finalize_lesson_html($html);
    }

    $parts = [];
    $video = $lesson['video'] ?? null;
    if (is_array($video)) {
        $src = wwm_sanitize_video_embed_url((string)($video['embed_url'] ?? ''));
        if ($src !== null) {
            $safeSrc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = '<div class="video-block"><iframe src="' . $safeSrc . '" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen loading="lazy"></iframe></div>';
        }
    }

    $description = trim((string)($lesson['description'] ?? ''));
    if ($description !== '') {
        $parts[] = '<p>' . nl2br(wwm_escape($description)) . '</p>';
    }

    return implode("\n", $parts);
}
