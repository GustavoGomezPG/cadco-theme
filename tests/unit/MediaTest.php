<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MediaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-media.php';
    }

    private const IMAGE  = 'https://cadcoltd232.sharepoint.com/:i:/s/CADCOMediaLibrary/IQAHsyfKSH6lS5NMutazxLL3AboaPNtVUAw92vTHCOMJ7Uo?e=nSec5k';
    private const DOC    = 'https://cadcoltd232.sharepoint.com/:b:/s/CADCOMediaLibrary/ER5VSKa8iIBFtCljC2QvVFgBye6BsGL6iZCKg7FvCN6ueA?e=fqM4EM';
    private const FOLDER = 'https://cadcoltd232.sharepoint.com/:f:/s/CADCOMediaLibrary/EkhAKGaeBcBKkiVRQV6PiSsBiafGq2Gd6mAYyMcE3jrAyw?e=tMe3pH';

    public function test_a_single_link_reads_as_one_url(): void
    {
        self::assertSame([self::IMAGE], \CADCO_Import_Media::urls(self::IMAGE));
    }

    public function test_an_empty_cell_has_no_urls(): void
    {
        self::assertSame([], \CADCO_Import_Media::urls(''));
        self::assertSame([], \CADCO_Import_Media::urls("   \n  "));
    }

    public function test_comma_separated_links_are_read_in_order(): void
    {
        $urls = \CADCO_Import_Media::urls(self::IMAGE . ', ' . self::DOC);

        self::assertSame([self::IMAGE, self::DOC], $urls);
    }

    public function test_line_separated_links_are_read_in_order(): void
    {
        // ALT+ENTER inside a cell, which is what CADCO are being asked to use.
        $urls = \CADCO_Import_Media::urls(self::DOC . "\n" . self::IMAGE);

        self::assertSame([self::DOC, self::IMAGE], $urls);
    }

    public function test_both_separators_may_be_mixed_in_one_cell(): void
    {
        $cell = self::IMAGE . ",\n" . self::DOC . "\r\n" . self::FOLDER;

        self::assertSame([self::IMAGE, self::DOC, self::FOLDER], \CADCO_Import_Media::urls($cell));
    }

    public function test_the_same_link_twice_is_read_once(): void
    {
        self::assertSame([self::IMAGE], \CADCO_Import_Media::urls(self::IMAGE . ', ' . self::IMAGE));
    }

    public function test_text_that_is_not_a_link_yields_nothing(): void
    {
        // Both really appear in the workbook's media columns.
        self::assertSame([], \CADCO_Import_Media::urls('n/a'));
        self::assertSame([], \CADCO_Import_Media::urls('CG-10+20 300dpi CMYK 5inS sq.jpg'));
    }

    public function test_a_link_is_found_alongside_stray_text(): void
    {
        $urls = \CADCO_Import_Media::urls('see ' . self::DOC . ' (rev 10)');

        self::assertSame([self::DOC], $urls);
    }

    public function test_trailing_sentence_punctuation_is_not_part_of_the_link(): void
    {
        self::assertSame(
            ['https://example.com/spec.pdf'],
            \CADCO_Import_Media::urls('https://example.com/spec.pdf.')
        );
    }

    public function test_a_share_link_is_rewritten_to_the_download_endpoint(): void
    {
        // The viewer URL 403s for anyone not signed in to the CADCO tenant;
        // this endpoint serves the same file anonymously.
        self::assertSame(
            'https://cadcoltd232.sharepoint.com/sites/CADCOMediaLibrary/_layouts/15/download.aspx?share=IQAHsyfKSH6lS5NMutazxLL3AboaPNtVUAw92vTHCOMJ7Uo',
            \CADCO_Import_Media::fetchable(self::IMAGE)
        );
    }

    public function test_a_document_share_link_is_rewritten_the_same_way(): void
    {
        self::assertStringContainsString(
            'download.aspx?share=ER5VSKa8iIBFtCljC2QvVFgBye6BsGL6iZCKg7FvCN6ueA',
            \CADCO_Import_Media::fetchable(self::DOC)
        );
    }

    public function test_a_folder_link_is_not_fetchable(): void
    {
        // Nothing single sits behind it, and a shared folder cannot be listed
        // without a signed-in session.
        self::assertSame('', \CADCO_Import_Media::fetchable(self::FOLDER));
        self::assertTrue(\CADCO_Import_Media::is_folder_link(self::FOLDER));
    }

    public function test_a_file_link_is_not_a_folder_link(): void
    {
        self::assertFalse(\CADCO_Import_Media::is_folder_link(self::IMAGE));
        self::assertFalse(\CADCO_Import_Media::is_folder_link('https://example.com/a.pdf'));
    }

    public function test_a_dropbox_link_is_asked_for_the_file(): void
    {
        self::assertSame(
            'https://www.dropbox.com/s/5jzma29b0bvxv6j/CBC-GG-2-L_.PDF?dl=1',
            \CADCO_Import_Media::fetchable('https://www.dropbox.com/s/5jzma29b0bvxv6j/CBC-GG-2-L_.PDF?dl=0')
        );
    }

    public function test_a_dropbox_link_with_no_dl_parameter_gains_one(): void
    {
        self::assertSame(
            'https://www.dropbox.com/scl/fi/abc/file.pdf?rlkey=xyz&dl=1',
            \CADCO_Import_Media::fetchable('https://www.dropbox.com/scl/fi/abc/file.pdf?rlkey=xyz')
        );
    }

    public function test_an_ordinary_link_is_left_alone(): void
    {
        $url = 'http://cadco-ltd.com/edit/resources/manuals/bakerlux-touch-ovens-manual-rv03.pdf';

        self::assertSame($url, \CADCO_Import_Media::fetchable($url));
    }

    public function test_fetchable_urls_drops_folder_links_and_keeps_the_rest(): void
    {
        $cell = self::IMAGE . "\n" . self::FOLDER . "\n" . self::DOC;
        $out  = \CADCO_Import_Media::fetchable_urls($cell);

        self::assertCount(2, $out, 'the folder link is not fetchable');

        foreach ($out as $url) {
            self::assertStringContainsString('download.aspx?share=', $url);
        }
    }

    public function test_two_links_to_one_file_fetch_once(): void
    {
        // The same token pasted with and without its '?e=' parameter.
        $bare = 'https://cadcoltd232.sharepoint.com/:i:/s/CADCOMediaLibrary/IQAHsyfKSH6lS5NMutazxLL3AboaPNtVUAw92vTHCOMJ7Uo';

        self::assertCount(1, \CADCO_Import_Media::fetchable_urls(self::IMAGE . ', ' . $bare));
    }
}
