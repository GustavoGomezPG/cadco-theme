<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MediaPlanTest extends TestCase
{
    private const TENANT = 'cadcoltd232.sharepoint.com';
    private const SPEC   = 'https://cadcoltd232.sharepoint.com/:b:/s/CADCOMediaLibrary/IQA6LNC31jvdSKTo82ke0rQuAWBlWKWOvNjGJ9nX_R8bIso?e=BZT7cJ';
    private const IMG_A  = 'https://cadcoltd232.sharepoint.com/:i:/s/CADCOMediaLibrary/IQAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const IMG_B  = 'https://cadcoltd232.sharepoint.com/:i:/s/CADCOMediaLibrary/IQAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const VIDEO  = 'https://youtu.be/agce-HkgqCo';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-issue.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-report.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-media.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-media-plan.php';
    }

    private static function row(string $model, array $media = []): array
    {
        return $media + [
            'Model #'        => $model,
            'Product Name'   => 'Product ' . $model,
            'Images URL'     => '',
            'Spec Sheet URL' => '',
            'Manual URL'     => '',
            'Video URL'      => '',
            '__sheet'        => 'COUNTERTOP EQUIPMENT',
            '__row'          => 7,
        ];
    }

    private static function plan(array $rows): \CADCO_Import_Media_Plan
    {
        return \CADCO_Import_Media_Plan::build($rows, self::TENANT);
    }

    public function test_images_keep_the_workbooks_order(): void
    {
        $plan = self::plan([self::row('A', ['Images URL' => "a.jpg\n" . self::IMG_B . "\n" . self::IMG_A])]);
        $keys = $plan->products()['A']['images'];

        self::assertCount(2, $keys);
        self::assertStringEndsWith('IQAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $keys[0], 'first written is first, and so featured');
    }

    public function test_a_shared_document_is_one_source_cited_by_every_model(): void
    {
        $plan = self::plan([
            self::row('A', ['Spec Sheet URL' => self::SPEC]),
            self::row('B', ['Spec Sheet URL' => self::SPEC . '&x=1']),
            self::row('C', ['Spec Sheet URL' => str_replace('e=BZT7cJ', 'e=other', self::SPEC)]),
        ]);

        // One token, whatever parameters ride along with it.
        self::assertCount(1, $plan->files());
        self::assertSame(['A', 'B', 'C'], array_values($plan->files())[0]['models']);
        self::assertSame(
            [['role' => 'spec-sheet', 'key' => array_key_first($plan->files()), 'models' => ['A', 'B', 'C']]],
            $plan->resources()
        );
    }

    public function test_a_video_link_becomes_a_video_resource_whatever_its_column(): void
    {
        $plan = self::plan([self::row('A', ['Manual URL' => self::VIDEO, 'Video URL' => self::VIDEO])]);

        self::assertSame([['role' => 'video', 'key' => 'youtube:agce-HkgqCo']], $plan->products()['A']['resources']);
        self::assertCount(1, $plan->videos());
        self::assertCount(0, $plan->files());
    }

    public function test_a_video_in_the_images_column_is_reported_and_not_used(): void
    {
        $plan = self::plan([self::row('A', ['Images URL' => self::VIDEO])]);

        self::assertSame([], $plan->products()['A']['images']);
        self::assertCount(1, $plan->notices());
        self::assertSame('Images URL', $plan->notices()[0]->column);
    }

    public function test_unusable_links_are_reported_with_where_and_what_to_do(): void
    {
        $plan = self::plan([self::row('A', [
            'Manual URL' => "https://cadcoltd232.sharepoint.com/:f:/s/CADCOMediaLibrary/EopFolder?e=x\nfile:///C:\\Users\\a\\m.pdf",
        ])]);

        $notices = $plan->notices();

        self::assertCount(2, $notices);

        foreach ($notices as $notice) {
            self::assertSame('M', $notice->tier);
            self::assertSame('COUNTERTOP EQUIPMENT', $notice->sheet);
            self::assertSame(7, $notice->row);
            self::assertSame('Manual URL', $notice->column);
            self::assertNotSame('', $notice->fix);
        }

        self::assertSame([], $plan->products()['A']['resources']);
        self::assertTrue($plan->products()['A']['cells']['manual'], 'the cell had links, even if none worked');
    }

    public function test_n_a_is_not_a_notice_but_a_bare_file_name_is(): void
    {
        $plan = self::plan([
            self::row('A', ['Manual URL' => 'n/a', 'Spec Sheet URL' => 'N/A', 'Video URL' => '—']),
            self::row('B', ['Images URL' => 'CAP-F.jpeg']),
        ]);

        self::assertCount(1, $plan->notices());
        self::assertSame('CAP-F.jpeg', $plan->notices()[0]->found);
    }

    public function test_an_old_site_file_is_used_and_still_reported(): void
    {
        $plan = self::plan([self::row('A', ['Manual URL' => 'http://cadco-ltd.com/edit/resources/manuals/x.pdf'])]);

        self::assertCount(1, $plan->files());
        self::assertCount(1, $plan->notices());
    }

    public function test_an_empty_cell_is_recorded_as_no_longer_asked_for(): void
    {
        $plan = self::plan([self::row('A')]);

        self::assertSame([], $plan->products()['A']['cells']);
        self::assertSame([], $plan->products()['A']['images']);
    }

    public function test_relative_links_use_the_tenant_found_in_the_rows(): void
    {
        $rows = [
            self::row('A', ['Images URL' => "a.jpg\n../../:i:/r/sites/CADCOMediaLibrary/x?id=%2Fa.jpg&share=cQaaa"]),
            self::row('B', ['Spec Sheet URL' => self::SPEC]),
        ];

        $plan = \CADCO_Import_Media_Plan::build($rows);

        self::assertSame(self::TENANT, $plan->tenant());
        self::assertCount(1, $plan->products()['A']['images']);
    }

    public function test_counts_add_up(): void
    {
        $plan = self::plan([
            self::row('A', ['Images URL' => self::IMG_A, 'Spec Sheet URL' => self::SPEC, 'Video URL' => self::VIDEO]),
            self::row('B', ['Images URL' => self::IMG_A . "\n" . self::IMG_B, 'Spec Sheet URL' => self::SPEC]),
            self::row('C'),
        ]);

        self::assertSame([
            'products'  => 2,
            'images'    => 3,
            'files'     => 3,
            'videos'    => 1,
            'resources' => 2,
            'notices'   => 0,
        ], $plan->counts());
    }

    public function test_notices_export_through_the_reports_csv_defence(): void
    {
        $plan = self::plan([self::row('A', ['Images URL' => '=HYPERLINK("x")'])]);

        self::assertStringContainsString("'=HYPERLINK", $plan->notices_csv());
    }
}
