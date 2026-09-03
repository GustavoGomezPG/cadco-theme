<?php

/**
 * Reads the media columns' links out of a spreadsheet cell.
 *
 * Two jobs, both of which exist because the workbook's links cannot be used
 * as written:
 *
 * 1. A cell may hold several links, separated by a comma or a line break.
 * 2. A SharePoint sharing link as pasted from the browser serves an HTML
 *    viewer, not the file — see fetchable() for what that costs and how the
 *    same token is turned into something downloadable.
 *
 * Pure and WordPress-free so it can be unit-tested directly. Nothing here
 * performs a request: this class decides *what* to fetch, and the phase 2
 * fetcher decides when.
 */

declare(strict_types=1);

final class CADCO_Import_Media
{
    /**
     * Every link in a cell, in the order written.
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
            $url = rtrim($url, '.;:)]”"\'');

            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * The SharePoint sharing-link shape: host, marker, scope, site, token.
     *
     * The marker says what was shared — ':i:' an image, ':b:' a document,
     * ':f:' a folder — and the last path segment is the share token.
     */
    private const SHARE = '~^https?://([^/]+\.sharepoint\.com)/(:[a-z]:)/[a-z]/([^/]+)/([^/?#]+)~i';

    /**
     * A URL that actually serves the file, or '' when nothing can.
     *
     * CADCO made the media library link-shareable, but the links in the
     * workbook are viewer URLs: fetched server-side, every one of them
     * redirects through Authenticate.aspx to login.microsoftonline.com and
     * ends in 403, with or without '&download=1'. The library's own download
     * endpoint serves the same file anonymously when handed the token, so
     * that is what the fetcher must ask for.
     *
     * Returns '' for a folder link. There is no single file behind one, and
     * a shared folder's contents cannot be listed without a signed-in
     * session — both the SharePoint and Graph 'shares' endpoints answer 401 —
     * so a folder link is an outstanding asset, not a fetch to retry.
     *
     * Dropbox is rewritten too: its share links serve a preview page unless
     * asked for the file with dl=1.
     */
    public static function fetchable(string $url): string
    {
        if (preg_match(self::SHARE, $url, $m) === 1) {
            [, $host, $marker, $site, $token] = $m;

            if (strtolower($marker) === ':f:') {
                return '';
            }

            return sprintf('https://%s/sites/%s/_layouts/15/download.aspx?share=%s', $host, $site, $token);
        }

        if (stripos($url, 'dropbox.com') !== false) {
            $url = preg_replace('~([?&])dl=\d+~i', '$1dl=1', $url, -1, $replaced);

            return $replaced > 0
                ? (string) $url
                : $url . (str_contains((string) $url, '?') ? '&' : '?') . 'dl=1';
        }

        return $url;
    }

    /**
     * Whether a link points at a SharePoint folder rather than a file.
     *
     * Separate from fetchable() returning '' so the report can say which of
     * the two it is: a folder link is something to ask CADCO to re-share,
     * where an unfetchable link of any other kind is ours to investigate.
     */
    public static function is_folder_link(string $url): bool
    {
        return preg_match(self::SHARE, $url, $m) === 1 && strtolower($m[2]) === ':f:';
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
}
