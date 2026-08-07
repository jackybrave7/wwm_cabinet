<?php
declare(strict_types=1);

/**
 * Sync Kinescope embed URLs into data/courses/elke-en.json.
 *
 * Usage:
 *   KINESCOPE_API_TOKEN=... php scripts/sync-elke-kinescope.php
 *   php scripts/sync-elke-kinescope.php <api-token>
 *   php scripts/sync-elke-kinescope.php --from-cache
 */

$fromCache = in_array('--from-cache', $argv, true);
$token = getenv('KINESCOPE_API_TOKEN') ?: '';
if (!$fromCache) {
    foreach ($argv as $arg) {
        if ($arg !== '--from-cache' && $arg !== $argv[0]) {
            $token = $arg;
            break;
        }
    }
}
if (!$fromCache && $token === '') {
    fwrite(STDERR, "Set KINESCOPE_API_TOKEN, pass token as argument, or use --from-cache.\n");
    exit(1);
}

const FLOWER_FOLDER = '4cb3b58f-5f3e-424e-8814-4bad58c5b283';
const CITY_FOLDER = '6df1f97b-e7c8-460a-9c5b-a804ae694e61';

/** @return list<array<string, mixed>> */
function kinescope_fetch_folder_videos(string $token, string $folderId): array
{
    $videos = [];
    $page = 1;
    $perPage = 100;

    do {
        $url = 'https://api.kinescope.io/v1/videos?' . http_build_query([
            'folder_id' => $folderId,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            $detail = $curlError !== '' ? " ({$curlError})" : '';
            throw new RuntimeException("Kinescope API error HTTP {$status} for folder {$folderId}{$detail}");
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid JSON from Kinescope API');
        }

        $batch = is_array($json['data'] ?? null) ? $json['data'] : [];
        $videos = array_merge($videos, $batch);
        $page++;
        $totalPages = (int)($json['meta']['pagination']['total_pages'] ?? 1);
    } while ($page <= $totalPages);

    return $videos;
}

/**
 * @param list<array<string, mixed>> $videos
 * @return array<string, string> title => embed_url
 */
function map_de_en_videos(array $videos): array
{
    $map = [];
    foreach ($videos as $video) {
        $title = (string)($video['title'] ?? '');
        if (!str_ends_with($title, '_DE_EN')) {
            continue;
        }
        $embed = (string)($video['embed_link'] ?? '');
        if ($embed === '') {
            continue;
        }
        $map[$title] = $embed;
    }
    return $map;
}

/**
 * @param array<string, string> $flowerMap
 * @param array<string, string> $cityMap
 * @return array<int, string> lesson num => embed_url
 */
function build_lesson_map(array $flowerMap, array $cityMap): array
{
    $lessonMap = [];

    $flowerOrder = [
        1 => 'Flowers_1_DE_EN',
        2 => 'Elke_2_DE_EN',
    ];
    for ($lessonNum = 4; $lessonNum <= 21; $lessonNum++) {
        $flowerOrder[$lessonNum] = 'Flowers_' . ($lessonNum - 1) . '_DE_EN';
    }

    foreach ($flowerOrder as $lessonNum => $title) {
        if (!isset($flowerMap[$title])) {
            throw new RuntimeException("Missing flower video: {$title}");
        }
        $lessonMap[$lessonNum] = $flowerMap[$title];
    }

    $cityOrder = [
        22 => 'Elke_1_DE_EN',
    ];
    for ($lessonNum = 25; $lessonNum <= 37; $lessonNum++) {
        $cityOrder[$lessonNum] = 'Elke_' . ($lessonNum - 22) . '_DE_EN';
    }

    foreach ($cityOrder as $lessonNum => $title) {
        if (!isset($cityMap[$title])) {
            throw new RuntimeException("Missing cityscape video: {$title}");
        }
        $lessonMap[$lessonNum] = $cityMap[$title];
    }

    if (!isset($flowerMap['Elke_2_DE_EN'])) {
        throw new RuntimeException('Missing shared video: Elke_2_DE_EN');
    }
    $lessonMap[23] = $flowerMap['Elke_2_DE_EN'];

    return $lessonMap;
}

function format_duration(float $seconds): string
{
    $total = max(0, (int)round($seconds));
    $h = intdiv($total, 3600);
    $m = intdiv($total % 3600, 60);
    $s = $total % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%d:%02d', $m, $s);
}

/** @return list<array<string, mixed>> */
function load_cached_folder_videos(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Cache file not found: {$path}");
    }
    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid cache JSON: {$path}");
    }
    return is_array($json['data'] ?? null) ? $json['data'] : [];
}

$root = dirname(__DIR__);
$coursePath = $root . '/data/courses/elke-en.json';
$course = json_decode((string)file_get_contents($coursePath), true);
if (!is_array($course)) {
    throw new RuntimeException('Failed to read elke-en.json');
}

if ($fromCache) {
    $flowerVideos = load_cached_folder_videos($root . '/data/kinescope-flower-temp.json');
    $cityVideos = load_cached_folder_videos($root . '/data/kinescope-city-temp.json');
} else {
    $flowerVideos = kinescope_fetch_folder_videos($token, FLOWER_FOLDER);
    $cityVideos = kinescope_fetch_folder_videos($token, CITY_FOLDER);
}

$flowerMap = map_de_en_videos($flowerVideos);
$cityMap = map_de_en_videos($cityVideos);

echo "Flower folder: " . count($flowerMap) . " _DE_EN videos\n";
foreach (array_keys($flowerMap) as $title) {
    echo "  - {$title}\n";
}
echo "Cityscape folder: " . count($cityMap) . " _DE_EN videos\n";
foreach (array_keys($cityMap) as $title) {
    echo "  - {$title}\n";
}

$lessonMap = build_lesson_map($flowerMap, $cityMap);
$skipNums = [3, 24];

$videoByTitle = [];
foreach (array_merge($flowerVideos, $cityVideos) as $video) {
    $videoByTitle[(string)($video['title'] ?? '')] = $video;
}

$updated = 0;
foreach ($course['lessons'] as &$lesson) {
    if (!is_array($lesson)) {
        continue;
    }
    $num = (int)($lesson['num'] ?? 0);
    if (in_array($num, $skipNums, true)) {
        unset($lesson['video']);
        continue;
    }
    if (!isset($lessonMap[$num])) {
        continue;
    }

    $embedUrl = $lessonMap[$num];
    $lesson['video'] = [
        'provider' => 'kinescope',
        'embed_url' => $embedUrl,
    ];
    unset($lesson['html_body']);

    foreach (array_merge($flowerVideos, $cityVideos) as $video) {
        if ((string)($video['embed_link'] ?? '') === $embedUrl) {
            $duration = (float)($video['duration'] ?? 0);
            if ($duration > 0) {
                $lesson['duration'] = format_duration($duration);
            }
            break;
        }
    }

    $updated++;
}
unset($lesson);

file_put_contents(
    $coursePath,
    json_encode($course, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "Updated {$updated} lessons in {$coursePath}\n";
