<?php

/**
 * Downloads one media file and proves it is what it claims to be.
 *
 * The answer to "did the download work" is never the status code. SharePoint
 * answers a locked, throttled or signed-out request with 200 and an HTML page,
 * so a file is accepted only when its first bytes say it is a PDF, an image or
 * a video. Anything else is refused with the reason, and the page is never
 * imported into the media library.
 *
 * Requests go through wp_safe_remote_get(), which refuses private and
 * loopback addresses: the URLs come from a spreadsheet, and a cell must not
 * be able to make the server fetch its own admin or a metadata endpoint.
 *
 * The pure parts — sniffing, naming — are static and WordPress-free so they
 * are unit-tested directly; fetch() is the one method that needs WordPress.
 */

declare(strict_types=1);

final class CADCO_Import_Fetcher
{
    /**
     * The largest file accepted, in bytes. Large enough for any spec sheet,
     * manual or product photograph; a 300 MB video would sit in every
     * backup of the site for ever. Filterable: cadco_import_media_max_bytes.
     */
    public const MAX_BYTES = 150 * 1024 * 1024;

    /**
     * File types accepted, by what the first bytes say — never by the URL's
     * extension or the server's Content-Type, both of which are routinely
     * wrong ('.PDF' that is a sign-in page; 'application/octet-stream' that
     * is a PNG). SVG is deliberately absent: it is script-capable XML.
     *
     * @var array<string, array{mime: string, ext: string, image: bool}>
     */
    public const TYPES = [
        'pdf'  => ['mime' => 'application/pdf', 'ext' => 'pdf', 'image' => false],
        'jpeg' => ['mime' => 'image/jpeg', 'ext' => 'jpg', 'image' => true],
        'png'  => ['mime' => 'image/png', 'ext' => 'png', 'image' => true],
        'gif'  => ['mime' => 'image/gif', 'ext' => 'gif', 'image' => true],
        'webp' => ['mime' => 'image/webp', 'ext' => 'webp', 'image' => true],
        'mp4'  => ['mime' => 'video/mp4', 'ext' => 'mp4', 'image' => false],
        'mov'  => ['mime' => 'video/quicktime', 'ext' => 'mov', 'image' => false],
    ];

    /**
     * What a file is, from its first bytes. '' when it is none of TYPES.
     */
    public static function sniff(string $head): string
    {
        if (str_starts_with($head, '%PDF-')) {
            return 'pdf';
        }

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }

        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'gif';
        }

        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }

        // ISO base media: a box size, then 'ftyp', then the brand.
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);

            return $brand === 'qt  ' ? 'mov' : 'mp4';
        }

        // A PDF may carry a little junk before its header; the spec allows
        // the marker anywhere in the first 1024 bytes.
        $pos = strpos(substr($head, 0, 1024), '%PDF-');

        return $pos !== false ? 'pdf' : '';
    }

    /**
     * Why a body that is not a file was refused, in words an operator can act
     * on. SharePoint's pages say which of its failures it is.
     */
    public static function explain_refusal(string $head, int $status): string
    {
        $text = strtolower($head);

        if ($status === 404 || $status === 410) {
            return 'The file is not there any more (HTTP ' . $status . ').';
        }

        if ($status === 401 || $status === 403) {
            return 'The host refused access (HTTP ' . $status . '). The file\'s sharing is probably not set to "Anyone with the link".';
        }

        if ($status >= 500) {
            return 'The host had an error (HTTP ' . $status . '). Try the import again later.';
        }

        if ($status !== 200 && $status !== 0) {
            return 'Unexpected answer from the host (HTTP ' . $status . ').';
        }

        if (str_contains($text, 'cannot access this') || str_contains($text, 'access denied') || str_contains($text, 'accessdenied')) {
            return 'SharePoint says the file cannot be accessed. Its sharing is not set to "Anyone with the link".';
        }

        if (str_contains($text, 'login.microsoftonline.com') || str_contains($text, 'sign in')) {
            return 'SharePoint asked for a sign-in. The file\'s sharing is not set to "Anyone with the link", or SharePoint is limiting requests.';
        }

        if (str_contains($text, '<html') || str_contains($text, '<!doctype')) {
            return 'The link opened a web page, not a file.';
        }

        return 'The download is not a PDF, image or video file.';
    }

    /**
     * Whether a refused body is worth one more try. SharePoint sends a
     * sign-in page when it is throttling, which clears in seconds; a page
     * saying access is denied does not.
     */
    public static function retryable(string $head, int $status): bool
    {
        if ($status === 429 || $status >= 500 || $status === 0) {
            return true;
        }

        $text = strtolower($head);

        return str_contains($text, 'login.microsoftonline.com') && !str_contains($text, 'cannot access this');
    }

    /**
     * A safe file name for the media library.
     *
     * Preference order: the name the server gave (Content-Disposition), the
     * file name inside a SharePoint link's id= path, the last path segment,
     * and finally the source key's hash. The extension is always the one the
     * sniffed type implies, so 'spec.PDF.html' cannot arrive as HTML.
     */
    public static function file_name(string $disposition, string $url, string $key, string $type): string
    {
        $name = '';

        if ($disposition !== '') {
            if (preg_match("~filename\\*\\s*=\\s*(?:UTF-8|utf-8)?''([^;]+)~i", $disposition, $m) === 1) {
                $name = rawurldecode(trim($m[1], " \"'"));
            } elseif (preg_match('~filename\s*=\s*"?([^";]+)"?~i', $disposition, $m) === 1) {
                $name = trim($m[1]);
            }
        }

        if ($name === '') {
            $query = (string) parse_url($url, PHP_URL_QUERY);
            parse_str($query, $params);
            $id = isset($params['id']) && is_string($params['id']) ? $params['id'] : '';

            if ($id !== '') {
                $name = basename(str_replace('\\', '/', $id));
            }
        }

        if ($name === '') {
            $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

            if (!str_contains($path, '/_layouts/')) {
                $name = basename($path);
            }
        }

        $base = (string) preg_replace('~\.[A-Za-z0-9]{1,5}$~', '', $name);
        $base = (string) preg_replace('~[^\pL\pN ._()+-]+~u', '-', $base);
        $base = trim((string) preg_replace('~[\s-]{2,}~u', ' ', $base), " .-");

        if ($base === '') {
            $base = 'cadco-media-' . substr(hash('sha256', $key), 0, 12);
        }

        // Keep names readable but bounded: file systems and URLs both have
        // limits, and a SharePoint path can be several hundred characters.
        if (mb_strlen($base) > 120) {
            $base = rtrim(mb_substr($base, 0, 120), ' .-');
        }

        $ext = self::TYPES[$type]['ext'] ?? 'bin';

        return $base . '.' . $ext;
    }

    /**
     * Download $url into a temporary file.
     *
     * $origin is the link as the workbook gave it, used only to name the
     * file when the server does not: a SharePoint sharing link carries the
     * file's name in its id= path, where the download address does not.
     *
     * @return array{ok: bool, path: string, type: string, mime: string, name: string, size: int, sha256: string, error: string, retryable: bool}
     */
    public static function fetch(string $url, string $key, string $origin = '', int $attempts = 2): array
    {
        $result = [
            'ok'        => false,
            'path'      => '',
            'type'      => '',
            'mime'      => '',
            'name'      => '',
            'size'      => 0,
            'sha256'    => '',
            'error'     => '',
            'retryable' => false,
        ];

        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $max = (int) apply_filters('cadco_import_media_max_bytes', self::MAX_BYTES);

        for ($attempt = 1; $attempt <= max(1, $attempts); $attempt++) {
            $tmp = wp_tempnam('cadco-media');

            $response = wp_safe_remote_get($url, [
                'timeout'             => 45,
                'redirection'         => 6,
                'stream'              => true,
                'filename'            => $tmp,
                'limit_response_size' => $max + 1,
                // SharePoint and Dropbox serve their HTML viewers, not the
                // file, to clients they do not recognise as a browser.
                'user-agent'          => 'Mozilla/5.0 (compatible; CADCO-Catalogue-Importer/1.0; +https://cadco-ltd.com)',
                'headers'             => ['Accept' => '*/*'],
            ]);

            if (is_wp_error($response)) {
                self::discard($tmp);
                $result['error']     = 'The download failed: ' . $response->get_error_message();
                $result['retryable'] = true;

                if ($attempt < $attempts) {
                    sleep(2 * $attempt);
                    continue;
                }

                return $result;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $size   = is_file($tmp) ? (int) filesize($tmp) : 0;
            $head   = $size > 0 ? (string) file_get_contents($tmp, false, null, 0, 2048) : '';
            $type   = $status === 200 ? self::sniff($head) : '';

            if ($size > $max) {
                self::discard($tmp);
                $result['error'] = sprintf('The file is larger than the %d MB limit.', (int) round($max / 1048576));

                return $result;
            }

            if ($type === '') {
                self::discard($tmp);
                $result['error']     = self::explain_refusal($head, $status);
                $result['retryable'] = self::retryable($head, $status);

                if ($result['retryable'] && $attempt < $attempts) {
                    sleep(3 * $attempt);
                    continue;
                }

                return $result;
            }

            $disposition = (string) wp_remote_retrieve_header($response, 'content-disposition');

            return [
                'ok'        => true,
                'path'      => $tmp,
                'type'      => $type,
                'mime'      => self::TYPES[$type]['mime'],
                'name'      => self::file_name($disposition, $origin !== '' ? $origin : $url, $key, $type),
                'size'      => $size,
                'sha256'    => (string) hash_file('sha256', $tmp),
                'error'     => '',
                'retryable' => false,
            ];
        }

        return $result;
    }

    public static function discard(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
