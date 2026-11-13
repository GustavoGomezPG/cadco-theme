<?php

/**
 * Reads the media columns' links out of a spreadsheet cell, and works out
 * what each one really points at.
 *
 * The links cannot be used as written, for reasons that all come from how the
 * workbook is maintained:
 *
 * 1. A cell may hold several links, separated by a comma or a line break, and
 *    usually also holds the file's name as display text with the real link
 *    attached as a hyperlink (the reader appends that hyperlink to the cell).
 * 2. Most hyperlinks are *relative* ("../../../:i:/r/sites/..."): Excel stores
 *    a link relative to wherever the workbook was saved, and CADCO save it in
 *    the same SharePoint tenant as the media library. The tenant's host has to
 *    be put back on the front.
 * 3. A SharePoint sharing link as pasted from the browser serves an HTML
 *    viewer, not the file — see resolve() for how the same token is turned
 *    into something downloadable.
 * 4. Links forwarded through CADCO's mail gateway arrive wrapped
 *    (protect.checkpoint.com/...___<target>___...), and other gateways wrap
 *    them their own way. The real target is unwrapped.
 * 5. Some cells hold a path on someone's PC (file:///C:/Users/...), which no
 *    server can reach. Those are reported, not fetched.
 *
 * Pure and WordPress-free so it can be unit-tested directly. Nothing here
 * performs a request: this class decides *what* to fetch, and
 * CADCO_Import_Fetcher fetches it.
 */

declare(strict_types=1);

final class CADCO_Import_Media
{
    /** A link that resolves to a single file that can be downloaded. */
    public const KIND_FILE = 'file';

    /** A link to a video on a streaming site: embedded, never downloaded. */
    public const KIND_VIDEO = 'video';

    /** Points at a folder: there is no single file behind it. */
    public const KIND_FOLDER = 'folder';

    /** Cannot be fetched by a server at all (a PC path, an unresolvable link). */
    public const KIND_UNREACHABLE = 'unreachable';

    /**
     * Problems a link can carry. A link with a problem may still be usable —
     * 'old-site' is fetched today and reported because it will stop working.
     */
    public const PROBLEM_LOCAL_PATH      = 'local-path';
    public const PROBLEM_FOLDER          = 'folder';
    public const PROBLEM_NO_TENANT       = 'relative-without-tenant';
    public const PROBLEM_SHAREPOINT_PATH = 'sharepoint-path';
    public const PROBLEM_OLD_SITE        = 'old-site';
    public const PROBLEM_BAD_VIDEO       = 'bad-video-link';

    /** Hosts of the website being replaced. A file there disappears at launch. */
    private const OLD_SITE_HOSTS = ['cadco-ltd.com', 'www.cadco-ltd.com'];

    /**
     * Every http(s) link in a cell, in the order written.
     *
     * Links are extracted by pattern rather than by exploding on the
     * separator. Splitting on ',' would be wrong the moment a link contains
     * one, and splitting on "\n" alone would miss the comma-separated cells;
     * matching the links themselves handles both, ignores the stray text the
     * workbook keeps in these columns ('n/a', a bare 'BLC-003.png'), and
     * cannot invent a value that was not there.
     *
     * @return list<string>
     */
    public static function urls(string $cell): array
    {
        if (trim($cell) === '') {
            return [];
        }

        // Terminated by whitespace or a comma, so both separators work. The
        // trailing trim drops punctuation a sentence would leave attached;
        // '/' and '=' are kept because share links end in both.
        if (preg_match_all('~https?://[^\s,]+~i', $cell, $matches) === 0) {
            return [];
        }

        $urls = [];

        foreach ($matches[0] as $url) {
            $url = self::trim_link($url);

            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Every link-like thing in a cell, in the order written: absolute links,
     * relative SharePoint links and local file paths alike.
     *
     * urls() only sees http(s) links, which is right for display. This is the
     * superset the media plan needs, because a relative link is a real link
     * once the tenant is known, and a local path is a real mistake that has
     * to be reported rather than silently ignored.
     *
     * @return list<string>
     */
    public static function links(string $cell): array
    {
        if (trim($cell) === '') {
            return [];
        }

        $pattern = '~'
            . 'https?://[^\s,]+'                         // absolute
            . '|file:/{1,3}[^\s,]+'                      // file:///C:/Users/...
            . '|(?<![\w/])[A-Za-z]:\\\\[^\s,]+'          // C:\Users\...
            . '|(?:\.\./)+:[a-z]:/[^\s,]+'                // ../../:i:/r/sites/...
            . '|(?<![\w.])/:[a-z]:/[^\s,]+'              // /:b:/s/Site/token
            . '~i';

        if (preg_match_all($pattern, $cell, $matches) === 0) {
            return [];
        }

        $out = [];

        foreach ($matches[0] as $link) {
            $link = self::trim_link($link);

            if ($link !== '' && !in_array($link, $out, true)) {
                $out[] = $link;
            }
        }

        return $out;
    }

    /**
     * The SharePoint host most of the workbook's absolute links use.
     *
     * Relative links carry no host, and the right one is whichever tenant the
     * workbook was saved in — which is, in practice, the tenant the rest of
     * its links point at. Taking the most frequent host rather than the first
     * keeps one stray link to another tenant from redirecting every relative
     * link in the file.
     *
     * @param iterable<string> $cells
     */
    public static function tenant_host(iterable $cells): string
    {
        $hosts = [];

        foreach ($cells as $cell) {
            foreach (self::links((string) $cell) as $link) {
                $url  = self::unwrap($link);
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));

                if (str_ends_with($host, '.sharepoint.com')) {
                    $hosts[$host] = ($hosts[$host] ?? 0) + 1;
                }
            }
        }

        if ($hosts === []) {
            return '';
        }

        arsort($hosts);

        return (string) array_key_first($hosts);
    }

    /**
     * What a link really points at, and how (or whether) to fetch it.
     *
     * @return array{
     *     source: string,
     *     url: string,
     *     kind: string,
     *     host: string,
     *     fetch: string,
     *     key: string,
     *     video: array{provider: string, id: string}|null,
     *     problem: string
     * }
     */
    public static function resolve(string $link, string $tenant = ''): array
    {
        $link = self::trim_link($link);
        $out  = [
            'source'  => $link,
            'url'     => '',
            'kind'    => self::KIND_UNREACHABLE,
            'host'    => '',
            'fetch'   => '',
            'key'     => '',
            'video'   => null,
            'problem' => '',
        ];

        if ($link === '') {
            return $out;
        }

        // A path on somebody's computer. No server can reach it, however the
        // path happens to be spelled.
        if (preg_match('~^(?:file:|[A-Za-z]:\\\\)~i', $link) === 1) {
            $out['problem'] = self::PROBLEM_LOCAL_PATH;
            $out['key']     = 'local:' . strtolower($link);

            return $out;
        }

        $url = self::unwrap($link);

        // Relative SharePoint link: the tenant goes back on the front.
        if (preg_match('~^(?:\.\./)*/?(:[a-z]:/.*)$~i', $url, $m) === 1) {
            if ($tenant === '') {
                $out['problem'] = self::PROBLEM_NO_TENANT;
                $out['key']     = 'relative:' . $m[1];

                return $out;
            }

            $url = 'https://' . $tenant . '/' . $m[1];
        }

        if (preg_match('~^https?://~i', $url) !== 1) {
            return $out;
        }

        $out['url'] = $url;
        $host       = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (str_ends_with($host, '.sharepoint.com')) {
            return self::resolve_sharepoint($out, $url, $host);
        }

        $video = self::video_of($url);

        if ($video !== null) {
            $out['kind']  = self::KIND_VIDEO;
            $out['host']  = $video['provider'];
            $out['video'] = $video;
            $out['key']   = $video['provider'] . ':' . $video['id'];

            return $out;
        }

        if ($host === 'youtube.com' || str_ends_with($host, '.youtube.com') || $host === 'youtu.be'
            || $host === 'vimeo.com' || str_ends_with($host, '.vimeo.com')
        ) {
            // A streaming-site link with no recognisable video in it — a
            // channel page, a search, a playlist. Nothing to embed.
            $out['host']    = str_contains($host, 'vimeo') ? 'vimeo' : 'youtube';
            $out['problem'] = self::PROBLEM_BAD_VIDEO;
            $out['key']     = 'web:' . self::strip_fragment($url);

            return $out;
        }

        if ($host === 'dropbox.com' || str_ends_with($host, '.dropbox.com')) {
            $out['kind']  = self::KIND_FILE;
            $out['host']  = 'dropbox';
            $out['fetch'] = self::dropbox_download($url);
            $out['key']   = 'dropbox:' . self::without_params(self::strip_fragment($url), ['dl', 'e', 'raw']);

            if (preg_match('~/(?:sh|scl/fo)/~i', $url) === 1) {
                // A shared folder, which downloads as a zip of everything in
                // it — never what a product image or spec sheet wants.
                $out['kind']    = self::KIND_FOLDER;
                $out['fetch']   = '';
                $out['problem'] = self::PROBLEM_FOLDER;
            }

            return $out;
        }

        if ($host === 'drive.google.com' || $host === 'docs.google.com') {
            if (preg_match('~/file/d/([A-Za-z0-9_-]+)|[?&]id=([A-Za-z0-9_-]+)~', $url, $g) === 1) {
                $id           = $g[1] !== '' ? $g[1] : $g[2];
                $out['kind']  = self::KIND_FILE;
                $out['host']  = 'google-drive';
                $out['fetch'] = 'https://drive.google.com/uc?export=download&id=' . $id;
                $out['key']   = 'gdrive:' . $id;

                return $out;
            }

            if (str_contains($url, '/folders/')) {
                $out['kind']    = self::KIND_FOLDER;
                $out['host']    = 'google-drive';
                $out['problem'] = self::PROBLEM_FOLDER;
                $out['key']     = 'web:' . self::strip_fragment($url);

                return $out;
            }
        }

        $out['kind']  = self::KIND_FILE;
        $out['host']  = 'web';
        $out['fetch'] = self::strip_fragment($url);
        $out['key']   = 'web:' . self::strip_fragment($url);

        if (in_array($host, self::OLD_SITE_HOSTS, true)) {
            $out['problem'] = self::PROBLEM_OLD_SITE;
        }

        return $out;
    }

    /**
     * A URL that actually serves the file, or '' when nothing can.
     *
     * Kept for callers that only need the download address; resolve() is
     * the full answer.
     */
    public static function fetchable(string $url): string
    {
        $resolved = self::resolve($url);

        // A plain link that is not a file the importer recognises is still
        // returned as-is: that is the address to try.
        if ($resolved['kind'] === self::KIND_VIDEO) {
            return $resolved['url'];
        }

        return $resolved['fetch'];
    }

    /**
     * Whether a link points at a folder rather than a file.
     *
     * Separate from fetchable() returning '' so the report can say which of
     * the two it is: a folder link is something to ask CADCO to re-share,
     * where an unfetchable link of any other kind is ours to investigate.
     */
    public static function is_folder_link(string $url): bool
    {
        return self::resolve($url)['kind'] === self::KIND_FOLDER;
    }

    /**
     * Every fetchable link in a cell, deduplicated, in the order written.
     *
     * @return list<string>
     */
    public static function fetchable_urls(string $cell): array
    {
        $out = [];

        foreach (self::urls($cell) as $url) {
            $fetch = self::fetchable($url);

            if ($fetch !== '' && !in_array($fetch, $out, true)) {
                $out[] = $fetch;
            }
        }

        return $out;
    }

    /**
     * The real target of a link a mail gateway has rewritten, or the link
     * unchanged. Applied repeatedly, because a link forwarded twice is
     * wrapped twice.
     */
    public static function unwrap(string $link): string
    {
        for ($i = 0; $i < 5; $i++) {
            $next = self::unwrap_once($link);

            if ($next === $link) {
                break;
            }

            $link = $next;
        }

        return $link;
    }

    private static function unwrap_once(string $link): string
    {
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));

        // Check Point: .../v2/r01/___<target>___.<signature>, where the
        // target's scheme has been collapsed to one slash ('https:/www...').
        if ($host === 'protect.checkpoint.com' || str_ends_with($host, '.checkpoint.com')) {
            if (preg_match('~___(.+?)___~s', $link, $m) === 1) {
                $target = preg_replace('~^(https?):/(?!/)~i', '$1://', $m[1]);

                return self::decode_once((string) $target);
            }
        }

        // Microsoft Defender Safe Links: the target is the url= parameter.
        if (str_ends_with($host, 'safelinks.protection.outlook.com')) {
            $target = self::query_param($link, 'url');

            if ($target !== '') {
                return $target;
            }
        }

        // Proofpoint URL Defense v3: https://urldefense.com/v3/__<target>__;...
        if ($host === 'urldefense.com' || $host === 'urldefense.proofpoint.com') {
            if (preg_match('~/v3/__(.+?)__;~s', $link, $m) === 1) {
                return $m[1];
            }

            $target = self::query_param($link, 'u');

            if ($target !== '') {
                // v2 encodes '/' as '_' and '%' as '-'.
                return rawurldecode(strtr($target, ['-' => '%', '_' => '/']));
            }
        }

        return $link;
    }

    /**
     * The provider and id of a streaming-site video, or null.
     *
     * @return array{provider: string, id: string}|null
     */
    public static function video_of(string $url): ?array
    {
        $url  = self::decode_once($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host === 'youtu.be') {
            $id = trim($path, '/');
            $id = explode('/', $id)[0];

            return self::youtube_id($id) ? ['provider' => 'youtube', 'id' => $id] : null;
        }

        if ($host === 'youtube.com' || str_ends_with($host, '.youtube.com') || $host === 'youtube-nocookie.com' || str_ends_with($host, '.youtube-nocookie.com')) {
            $v = self::query_param($url, 'v');

            if (self::youtube_id($v)) {
                return ['provider' => 'youtube', 'id' => $v];
            }

            if (preg_match('~^/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})(?:[/?#]|$)~', $path, $m) === 1) {
                return ['provider' => 'youtube', 'id' => $m[1]];
            }

            return null;
        }

        if ($host === 'vimeo.com' || str_ends_with($host, '.vimeo.com')) {
            if (preg_match('~/(?:video/)?(\d{5,})(?:[/?#]|$)~', $path, $m) === 1) {
                return ['provider' => 'vimeo', 'id' => $m[1]];
            }
        }

        return null;
    }

    /**
     * The canonical watch address for a video, the form the resource stores.
     *
     * @param array{provider: string, id: string} $video
     */
    public static function video_url(array $video): string
    {
        return $video['provider'] === 'vimeo'
            ? 'https://vimeo.com/' . $video['id']
            : 'https://www.youtube.com/watch?v=' . $video['id'];
    }

    /**
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private static function resolve_sharepoint(array $out, string $url, string $host): array
    {
        $out['host'] = 'sharepoint';
        $path        = (string) parse_url($url, PHP_URL_PATH);

        // Sharing links: /:<kind>:/<scope>/<...>. The kind says what was
        // shared — ':i:' an image, ':b:' a PDF, ':f:' a folder, ':v:' a
        // video and so on — and that is what tells a folder from a file.
        if (preg_match('~^/:([a-z]):/([a-z])/(.*)$~i', $path, $m) === 1) {
            [, $marker, $scope, $rest] = $m;
            $marker = strtolower($marker);
            $scope  = strtolower($scope);

            $token = '';
            $base  = '';

            // The long form a browser's address bar gives when a file is
            // opened from the library: /:b:/r/sites/<site>/Shared Documents/
            // Forms/AllItems.aspx?id=<path>&share=<token>. The share token is
            // a query parameter, and the site is the path segment after
            // /sites/.
            $shareParam = self::query_param($url, 'share');

            if ($shareParam !== '') {
                $token = $shareParam;

                if (preg_match('~^(sites|teams|personal)/([^/]+)~i', $rest, $s) === 1) {
                    $base = '/' . strtolower($s[1]) . '/' . $s[2];
                }
            } elseif ($scope === 's' && preg_match('~^([^/]+)/([^/?#]+)~', $rest, $s) === 1) {
                // /:b:/s/<site>/<token>
                $base  = '/sites/' . $s[1];
                $token = $s[2];
            } elseif ($scope === 't' && preg_match('~^([^/]+)/([^/?#]+)~', $rest, $s) === 1) {
                // /:b:/t/<team site>/<token>
                $base  = '/teams/' . $s[1];
                $token = $s[2];
            } elseif ($scope === 'g' && preg_match('~^(personal|sites|teams)/([^/]+)/([^/?#]+)~i', $rest, $s) === 1) {
                // /:b:/g/personal/<user>/<token> (OneDrive) and friends
                $base  = '/' . strtolower($s[1]) . '/' . $s[2];
                $token = $s[3];
            }

            if ($marker === 'f') {
                $out['kind']    = self::KIND_FOLDER;
                $out['problem'] = self::PROBLEM_FOLDER;
                $out['key']     = 'sp:' . $host . ':' . ($token !== '' ? $token : $path);

                return $out;
            }

            if ($token === '') {
                // /:b:/r/sites/... with no share token is a redirect to the
                // file's path inside the library, which only a signed-in
                // member of the tenant can open.
                $out['problem'] = self::PROBLEM_SHAREPOINT_PATH;
                $out['key']     = 'sp:' . $host . ':' . strtolower(rawurldecode($path));

                return $out;
            }

            $out['kind']  = self::KIND_FILE;
            $out['fetch'] = sprintf('https://%s%s/_layouts/15/download.aspx?share=%s', $host, $base, rawurlencode($token));
            $out['key']   = 'sp:' . $host . ':' . $token;

            return $out;
        }

        // Anything else on SharePoint — a library path, an AllItems view, a
        // _layouts page — is only reachable when signed in.
        $out['problem'] = self::PROBLEM_SHAREPOINT_PATH;
        $out['key']     = 'sp:' . $host . ':' . strtolower(rawurldecode($path)) . '?' . (string) parse_url($url, PHP_URL_QUERY);

        return $out;
    }

    private static function dropbox_download(string $url): string
    {
        $url = self::strip_fragment($url);
        $url = (string) preg_replace('~([?&])raw=\d+~i', '$1dl=1', $url);
        $url = (string) preg_replace('~([?&])dl=[^&]*~i', '$1dl=1', $url, -1, $replaced);

        return $replaced > 0
            ? $url
            : $url . (str_contains($url, '?') ? '&' : '?') . 'dl=1';
    }

    private static function youtube_id(string $id): bool
    {
        return preg_match('~^[A-Za-z0-9_-]{11}$~', $id) === 1;
    }

    /**
     * One query parameter, decoded. '' when absent.
     */
    private static function query_param(string $url, string $name): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ($query === '') {
            return '';
        }

        foreach (explode('&', $query) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');

            if (strcasecmp(rawurldecode($k), $name) === 0) {
                return rawurldecode(str_replace('+', '%20', $v));
            }
        }

        return '';
    }

    /**
     * @param list<string> $drop
     */
    private static function without_params(string $url, array $drop): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ($query === '') {
            return $url;
        }

        $kept = [];

        foreach (explode('&', $query) as $pair) {
            $k = strtolower(rawurldecode(explode('=', $pair, 2)[0]));

            if (!in_array($k, $drop, true) && $pair !== '') {
                $kept[] = $pair;
            }
        }

        $base = substr($url, 0, (int) strpos($url, '?'));

        return $kept === [] ? $base : $base . '?' . implode('&', $kept);
    }

    private static function strip_fragment(string $url): string
    {
        $hash = strpos($url, '#');

        return $hash === false ? $url : substr($url, 0, $hash);
    }

    /**
     * A gateway-wrapped target arrives with its fragment marker encoded
     * ('watch?v=<id>%23action=share'), which would otherwise read as part of
     * the last query value. Only '%23' is decoded: every other escape in a
     * target belongs to it — '%26' inside a SharePoint path ("Grab %26 Go")
     * is a literal ampersand in a file name, and decoding it would split the
     * query string in the wrong place.
     */
    private static function decode_once(string $url): string
    {
        return str_ireplace('%23', '#', $url);
    }

    private static function trim_link(string $link): string
    {
        return rtrim(trim($link), '.;:)]”"\'');
    }
}
