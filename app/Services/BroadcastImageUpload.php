<?php
declare(strict_types=1);

namespace Wwm\Services;

final class BroadcastImageUpload
{
    private const MAX_BYTES = 3_145_728;

    /**
     * @return array{ok: true, url: string, path: string}|array{ok: false, error: string}
     */
    public static function storeFromUpload(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => self::uploadErrorMessage($error)];
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Upload failed.'];
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Image must be 3 MB or smaller.'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => '',
        };
        if ($ext === '') {
            return ['ok' => false, 'error' => 'Only JPEG, PNG, GIF, or WebP images are allowed.'];
        }

        $name = 'b_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $savedPath = self::saveToWebroots($tmp, $name);
        if ($savedPath === null) {
            return ['ok' => false, 'error' => 'Could not save uploaded image.'];
        }

        $publicPath = '/assets/broadcasts/' . $name;

        return [
            'ok' => true,
            'url' => wwm_base_url() . $publicPath,
            'path' => $publicPath,
        ];
    }

    /**
     * Spaceweb docroot is public_html (FTP deploy mirrors public → public_html).
     *
     * @return list<string>
     */
    private static function webBroadcastDirectories(): array
    {
        $dirs = [];
        if (is_dir(WWM_ROOT . '/public_html') || is_dir(WWM_ROOT . '/public_html/assets')) {
            $dirs[] = WWM_ROOT . '/public_html/assets/broadcasts';
        }
        $dirs[] = WWM_ROOT . '/public/assets/broadcasts';

        return array_values(array_unique($dirs));
    }

    private static function saveToWebroots(string $tmpPath, string $filename): ?string
    {
        $primaryDest = null;
        foreach (self::webBroadcastDirectories() as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }
            $dest = $dir . '/' . $filename;
            if ($primaryDest === null) {
                if (!move_uploaded_file($tmpPath, $dest)) {
                    return null;
                }
                $primaryDest = $dest;
                continue;
            }
            @copy($primaryDest, $dest);
        }

        return $primaryDest;
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image exceeds server upload limit.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'No image file was uploaded.',
            default => 'Upload failed.',
        };
    }
}
