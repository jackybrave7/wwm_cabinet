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
    session_name('wwm_cabinet');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
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
    $file = WWM_ROOT . '/public/assets/' . $relative;
    $version = is_readable($file) ? (string)filemtime($file) : '1';

    return '/assets/' . $relative . '?v=' . $version;
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
        $base = rtrim((string)(wwm_config()['base_url'] ?? ''), '/');
        $path = $base . ($path === '' ? '/' : $path);
    }
    header('Location: ' . $path, true, $code);
    exit;
}

function wwm_course_cover_url(?string $url): ?string
{
    $url = trim((string)$url);
    if ($url === '' || !str_starts_with($url, 'https://')) {
        return null;
    }

    return $url;
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
