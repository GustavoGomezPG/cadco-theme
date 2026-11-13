<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fetcher's decisions: what a download is, why one was refused, and
 * what the file is called. The network call itself needs WordPress and is
 * covered end to end.
 */
final class FetcherTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/class-cadco-import-fetcher.php';
    }

    #[DataProvider('heads')]
    public function test_a_file_is_known_by_its_first_bytes(string $head, string $type): void
    {
        self::assertSame($type, \CADCO_Import_Fetcher::sniff($head));
    }

    public static function heads(): array
    {
        return [
            'pdf'                 => ["%PDF-1.7\n%\xE2\xE3", 'pdf'],
            'pdf after junk'      => ["\r\n\r\n%PDF-1.4", 'pdf'],
            'jpeg'                => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", 'jpeg'],
            'png'                 => ["\x89PNG\r\n\x1A\n\x00\x00", 'png'],
            'gif'                 => ['GIF89a....', 'gif'],
            'webp'                => ['RIFF....WEBPVP8 ', 'webp'],
            'mp4'                 => ["\x00\x00\x00\x20ftypisom\x00\x00", 'mp4'],
            'quicktime'           => ["\x00\x00\x00\x14ftypqt  \x00\x00", 'mov'],
            'sharepoint page'     => ['<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"', ''],
            'svg is refused'      => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg">', ''],
            'empty'               => ['', ''],
            'zip (docx) refused'  => ["PK\x03\x04\x14\x00", ''],
        ];
    }

    public function test_a_sharepoint_access_denied_page_says_so_and_is_not_retried(): void
    {
        $page = '<html><head><title>Error</title></head><body>Sorry, you cannot access this document.</body></html>';

        self::assertStringContainsString('Anyone with the link', \CADCO_Import_Fetcher::explain_refusal($page, 200));
        self::assertFalse(\CADCO_Import_Fetcher::retryable($page, 200));
    }

    public function test_a_sign_in_page_is_retried_because_sharepoint_throttles_that_way(): void
    {
        $page = '<html><script>window.location="https://login.microsoftonline.com/common/oauth2"</script></html>';

        self::assertStringContainsString('sign-in', \CADCO_Import_Fetcher::explain_refusal($page, 200));
        self::assertTrue(\CADCO_Import_Fetcher::retryable($page, 200));
    }

    public function test_http_failures_are_explained_and_only_transient_ones_retried(): void
    {
        self::assertStringContainsString('not there', \CADCO_Import_Fetcher::explain_refusal('', 404));
        self::assertFalse(\CADCO_Import_Fetcher::retryable('', 404));
        self::assertStringContainsString('refused access', \CADCO_Import_Fetcher::explain_refusal('', 403));
        self::assertTrue(\CADCO_Import_Fetcher::retryable('', 503));
        self::assertTrue(\CADCO_Import_Fetcher::retryable('', 429));
    }

    public function test_the_name_the_server_gives_wins(): void
    {
        self::assertSame(
            'Cadco Panini Grills.pdf',
            \CADCO_Import_Fetcher::file_name("attachment; filename*=UTF-8''Cadco%20Panini%20Grills.pdf", 'https://x/_layouts/15/download.aspx?share=a', 'k', 'pdf')
        );
        self::assertSame(
            'spec.pdf',
            \CADCO_Import_Fetcher::file_name('attachment; filename="spec.pdf"', 'https://x/a', 'k', 'pdf')
        );
    }

    public function test_a_sharepoint_link_names_the_file_from_its_id_path(): void
    {
        $url = 'https://cadcoltd232.sharepoint.com/:b:/r/sites/S/Shared%20Documents/Forms/AllItems.aspx?id=%2Fsites%2FS%2FShared%20Documents%2FWT%2D5%20Manual%20RV00%2Epdf&share=abc';

        self::assertSame('WT-5 Manual RV00.pdf', \CADCO_Import_Fetcher::file_name('', $url, 'k', 'pdf'));
    }

    public function test_the_extension_always_follows_what_the_bytes_are(): void
    {
        // A '.PDF' link that served a JPEG is a JPEG.
        self::assertSame('photo.jpg', \CADCO_Import_Fetcher::file_name('', 'https://x.test/files/photo.PDF', 'k', 'jpeg'));
    }

    public function test_a_name_that_cannot_be_found_falls_back_to_the_source_key(): void
    {
        $name = \CADCO_Import_Fetcher::file_name('', 'https://x.sharepoint.com/sites/S/_layouts/15/download.aspx?share=abc', 'sp:x:abc', 'png');

        self::assertMatchesRegularExpression('~^cadco-media-[0-9a-f]{12}\.png$~', $name);
    }

    public function test_unsafe_characters_are_removed_and_long_names_bounded(): void
    {
        $name = \CADCO_Import_Fetcher::file_name('attachment; filename="../../wp-config<script>.pdf"', 'https://x', 'k', 'pdf');

        self::assertStringNotContainsString('/', $name);
        self::assertStringNotContainsString('<', $name);
        self::assertStringEndsWith('.pdf', $name);

        $long = \CADCO_Import_Fetcher::file_name('attachment; filename="' . str_repeat('a', 300) . '.pdf"', 'https://x', 'k', 'pdf');

        self::assertLessThanOrEqual(124, strlen($long));
    }
}
