<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CADCO_Import_Media::resolve() and friends, against the link shapes the
 * real workbooks contain.
 */
final class MediaResolveTest extends TestCase
{
    private const TENANT = 'cadcoltd232.sharepoint.com';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/class-cadco-import-media.php';
    }

    private static function resolve(string $link, string $tenant = self::TENANT): array
    {
        return \CADCO_Import_Media::resolve($link, $tenant);
    }

    public function test_a_short_sharepoint_link_downloads_through_the_site_endpoint(): void
    {
        $r = self::resolve('https://cadcoltd232.sharepoint.com/:b:/s/CADCOMediaLibrary/IQA6LNC31jvdSKTo82ke0rQuAWBlWKWOvNjGJ9nX_R8bIso?e=BZT7cJ');

        self::assertSame('file', $r['kind']);
        self::assertSame('sharepoint', $r['host']);
        self::assertSame(
            'https://cadcoltd232.sharepoint.com/sites/CADCOMediaLibrary/_layouts/15/download.aspx?share=IQA6LNC31jvdSKTo82ke0rQuAWBlWKWOvNjGJ9nX_R8bIso',
            $r['fetch']
        );
        self::assertSame('', $r['problem']);
    }

    public function test_the_e_parameter_does_not_change_a_links_identity(): void
    {
        $a = self::resolve('https://cadcoltd232.sharepoint.com/:b:/s/CADCOMediaLibrary/IQA8VvhOPag1R4FRHiYlds0cAc3ac-8iDmJiQPRY-lGxdk4?e=IjaMIL');
        $b = self::resolve('https://cadcoltd232.sharepoint.com/:b:/s/CADCOMediaLibrary/IQA8VvhOPag1R4FRHiYlds0cAc3ac-8iDmJiQPRY-lGxdk4?e=ODHvCy');

        self::assertSame($a['key'], $b['key']);
    }

    public function test_the_long_library_form_uses_its_share_parameter(): void
    {
        // What the address bar gives when a file is opened from the library.
        $link = 'https://cadcoltd232.sharepoint.com/:b:/r/sites/CADCOMediaLibrary/Shared%20Documents/Forms/AllItems.aspx'
            . '?id=%2Fsites%2FCADCOMediaLibrary%2FShared%20Documents%2FWarmers%2FWT%2D5%2DHD%20Manual%20RV00%2Epdf'
            . '&parent=%2Fsites%2FCADCOMediaLibrary%2FShared%20Documents&p=true'
            . '&share=cQpLYZ1DPkxdRbyHLYQ2dAbNEgUD9cKOk%2DRWEgjTHLYyeaAtzg';

        $r = self::resolve($link);

        self::assertSame('file', $r['kind']);
        self::assertSame(
            'https://cadcoltd232.sharepoint.com/sites/CADCOMediaLibrary/_layouts/15/download.aspx?share=cQpLYZ1DPkxdRbyHLYQ2dAbNEgUD9cKOk-RWEgjTHLYyeaAtzg',
            $r['fetch'],
            'the token is decoded once and re-encoded, so %2D arrives as -'
        );
    }

    public function test_an_ampersand_inside_a_library_path_does_not_break_the_share_parameter(): void
    {
        $link = 'https://cadcoltd232.sharepoint.com/:b:/r/sites/CADCOMediaLibrary/Shared%20Documents/Forms/AllItems.aspx'
            . '?id=%2Fsites%2FCADCOMediaLibrary%2FCarts%2FStandard%20Mobile%20Grab%20%26%20Go%20Cart%20Accessories.pdf'
            . '&share=cQoWhEWQ0HWNT6kyjo9vJF9fEgUDL52ihKhCOkCqCjSQQeIiGw';

        self::assertStringEndsWith('share=cQoWhEWQ0HWNT6kyjo9vJF9fEgUDL52ihKhCOkCqCjSQQeIiGw', self::resolve($link)['fetch']);
    }

    public function test_a_relative_link_is_completed_with_the_tenant(): void
    {
        $r = self::resolve('../../../../:i:/r/sites/CADCOMediaLibrary/Shared%20Documents/Forms/AllItems.aspx?id=%2Fx.jpeg&share=cQpQTSLXFtowTZo%2DKoqzJIbrEgUDsPBhjup%2DOZY5J19Fn2KfoQ');

        self::assertSame('file', $r['kind']);
        self::assertStringStartsWith('https://cadcoltd232.sharepoint.com/:i:/r/sites/', $r['url']);
        self::assertStringEndsWith('download.aspx?share=cQpQTSLXFtowTZo-KoqzJIbrEgUDsPBhjup-OZY5J19Fn2KfoQ', $r['fetch']);
    }

    public function test_a_relative_link_without_a_tenant_is_reported_not_guessed(): void
    {
        $r = self::resolve('../../:i:/r/sites/CADCOMediaLibrary/x?share=abc', '');

        self::assertSame('unreachable', $r['kind']);
        self::assertSame(\CADCO_Import_Media::PROBLEM_NO_TENANT, $r['problem']);
        self::assertSame('', $r['fetch']);
    }

    public function test_a_folder_link_is_a_folder(): void
    {
        $r = self::resolve('https://cadcoltd232.sharepoint.com/:f:/s/CADCOMediaLibrary/Eop7HvOeIhVHj0xCOqs5sDYBhfvxwG-EfIhKClKykkZlcQ?e=wRo4bC');

        self::assertSame('folder', $r['kind']);
        self::assertSame(\CADCO_Import_Media::PROBLEM_FOLDER, $r['problem']);
        self::assertSame('', $r['fetch']);
    }

    public function test_a_library_path_without_a_share_token_needs_a_sharing_link(): void
    {
        $r = self::resolve('https://cadcoltd232.sharepoint.com/sites/CADCOMediaLibrary/Shared%20Documents/spec.pdf');

        self::assertSame(\CADCO_Import_Media::PROBLEM_SHAREPOINT_PATH, $r['problem']);
        self::assertSame('', $r['fetch']);
    }

    public function test_a_onedrive_personal_link_downloads_from_the_personal_site(): void
    {
        $r = self::resolve('https://contoso-my.sharepoint.com/:b:/g/personal/jo_contoso_com/EaBcDeFg123?e=x');

        self::assertSame('https://contoso-my.sharepoint.com/personal/jo_contoso_com/_layouts/15/download.aspx?share=EaBcDeFg123', $r['fetch']);
    }

    #[DataProvider('local_paths')]
    public function test_a_path_on_a_pc_is_unreachable(string $path): void
    {
        $r = self::resolve($path);

        self::assertSame('unreachable', $r['kind']);
        self::assertSame(\CADCO_Import_Media::PROBLEM_LOCAL_PATH, $r['problem']);
    }

    public static function local_paths(): array
    {
        return [
            'file url with a share token inside' => ['file:///C:\Users\apenn\AppData\Local\Microsoft\:i:\s\CADCOMediaLibrary\IQAG9IGxsQmGTLi0fbXiHRqnAQUXr86b_66T5kJF8wIdn4o%3fe=DOgZmM'],
            'windows path'                       => ['C:\Users\apenn\Desktop\spec.pdf'],
            'file url, forward slashes'          => ['file:///Users/someone/spec.pdf'],
        ];
    }

    public function test_a_check_point_wrapped_link_is_unwrapped(): void
    {
        $wrapped = 'https://protect.checkpoint.com/v2/r01/___https:/www.youtube.com/watch?v=XKwBdP2apU0%23action=share___.YXAzOmNhZGNvbHRkOmM6bzpkYTpjMmE';

        $r = self::resolve($wrapped);

        self::assertSame('video', $r['kind']);
        self::assertSame(['provider' => 'youtube', 'id' => 'XKwBdP2apU0'], $r['video']);
    }

    public function test_a_check_point_wrapped_sharepoint_link_downloads(): void
    {
        $wrapped = 'https://protect.checkpoint.com/v2/r01/___https:/cadcoltd232.sharepoint.com/:b:/s/CADCOMediaLibrary/IQA6LNC31jvdSKTo82ke0rQuAWBlWKWOvNjGJ9nX_R8bIso?e=BZT7cJ___.YXAzOmNhZGNv';

        self::assertStringEndsWith('download.aspx?share=IQA6LNC31jvdSKTo82ke0rQuAWBlWKWOvNjGJ9nX_R8bIso', self::resolve($wrapped)['fetch']);
    }

    public function test_safe_links_and_url_defense_are_unwrapped(): void
    {
        $target = 'https://www.dropbox.com/s/abc/manual.pdf?dl=0';

        self::assertSame($target, \CADCO_Import_Media::unwrap(
            'https://nam02.safelinks.protection.outlook.com/?url=' . rawurlencode($target) . '&data=05%7C01&reserved=0'
        ));
        self::assertSame($target, \CADCO_Import_Media::unwrap('https://urldefense.com/v3/__' . $target . '__;!!abc$'));
    }

    public function test_a_link_wrapped_twice_is_unwrapped_twice(): void
    {
        $inner = 'https://protect.checkpoint.com/v2/r01/___https:/youtu.be/agce-HkgqCo___.YXAz';
        $outer = 'https://nam02.safelinks.protection.outlook.com/?url=' . rawurlencode($inner);

        self::assertSame('youtube:agce-HkgqCo', self::resolve($outer)['key']);
    }

    #[DataProvider('youtube_links')]
    public function test_every_youtube_address_form_names_the_video(string $url): void
    {
        self::assertSame(['provider' => 'youtube', 'id' => 'agce-HkgqCo'], \CADCO_Import_Media::video_of($url));
    }

    public static function youtube_links(): array
    {
        return [
            'short'          => ['https://youtu.be/agce-HkgqCo'],
            'short with t'   => ['https://youtu.be/agce-HkgqCo?t=12'],
            'watch'          => ['https://www.youtube.com/watch?v=agce-HkgqCo'],
            'watch fragment' => ['https://www.youtube.com/watch?v=agce-HkgqCo#action=share'],
            'watch params'   => ['https://www.youtube.com/watch?feature=youtu.be&v=agce-HkgqCo&t=3'],
            'mobile'         => ['https://m.youtube.com/watch?v=agce-HkgqCo'],
            'embed'          => ['https://www.youtube.com/embed/agce-HkgqCo?rel=0'],
            'shorts'         => ['https://www.youtube.com/shorts/agce-HkgqCo'],
            'nocookie'       => ['https://www.youtube-nocookie.com/embed/agce-HkgqCo'],
        ];
    }

    public function test_a_youtube_page_with_no_video_is_reported(): void
    {
        $r = self::resolve('https://www.youtube.com/@CadcoLtd/videos');

        self::assertSame(\CADCO_Import_Media::PROBLEM_BAD_VIDEO, $r['problem']);
        self::assertSame('', $r['fetch']);
    }

    public function test_a_vimeo_link_is_a_video(): void
    {
        $r = self::resolve('https://vimeo.com/123456789');

        self::assertSame('video', $r['kind']);
        self::assertSame('vimeo:123456789', $r['key']);
        self::assertSame('https://vimeo.com/123456789', \CADCO_Import_Media::video_url($r['video']));
    }

    public function test_the_canonical_youtube_address_is_the_watch_page(): void
    {
        self::assertSame(
            'https://www.youtube.com/watch?v=agce-HkgqCo',
            \CADCO_Import_Media::video_url(['provider' => 'youtube', 'id' => 'agce-HkgqCo'])
        );
    }

    public function test_dropbox_odd_dl_values_all_become_a_download(): void
    {
        // dl=14 and dl=7 both appear in real workbooks.
        foreach (['dl=0', 'dl=14', 'dl=7', 'raw=1'] as $param) {
            $r = self::resolve('https://www.dropbox.com/scl/fi/eizwobjka6big0v299frv/BL-water-tank-kit-half-size.png?rlkey=abc&' . $param);

            self::assertMatchesRegularExpression('~[?&]dl=1$~', $r['fetch'], $param);
            self::assertSame(1, preg_match_all('~[?&](?:dl|raw)=~', $r['fetch']), $param . ': exactly one download parameter');
        }
    }

    public function test_dropbox_links_differing_only_in_dl_are_one_source(): void
    {
        $a = self::resolve('https://www.dropbox.com/s/abc/manual.pdf?dl=0');
        $b = self::resolve('https://www.dropbox.com/s/abc/manual.pdf?dl=1');

        self::assertSame($a['key'], $b['key']);
    }

    public function test_a_dropbox_folder_is_a_folder(): void
    {
        self::assertSame('folder', self::resolve('https://www.dropbox.com/sh/abc123/AAA?dl=0')['kind']);
        self::assertSame('folder', self::resolve('https://www.dropbox.com/scl/fo/abc123/AAA?rlkey=x&dl=0')['kind']);
    }

    public function test_a_google_drive_file_downloads_through_its_export_address(): void
    {
        $r = self::resolve('https://drive.google.com/file/d/1AbCdEfGhIjKlMnOp/view?usp=sharing');

        self::assertSame('https://drive.google.com/uc?export=download&id=1AbCdEfGhIjKlMnOp', $r['fetch']);
    }

    public function test_a_file_on_the_old_site_is_used_and_flagged(): void
    {
        $r = self::resolve('http://cadco-ltd.com/edit/resources/manuals/bakerlux-touch-ovens-manual-rv03.pdf');

        self::assertSame('file', $r['kind']);
        self::assertSame(\CADCO_Import_Media::PROBLEM_OLD_SITE, $r['problem']);
        self::assertSame('http://cadco-ltd.com/edit/resources/manuals/bakerlux-touch-ovens-manual-rv03.pdf', $r['fetch']);
    }

    public function test_links_finds_relative_and_local_links_alongside_text(): void
    {
        $cell = "CPG-10.jpg\n../../:i:/r/sites/CADCOMediaLibrary/x?share=abc\nfile:///C:\\Users\\a\\b.pdf, https://youtu.be/agce-HkgqCo";

        self::assertSame([
            '../../:i:/r/sites/CADCOMediaLibrary/x?share=abc',
            'file:///C:\\Users\\a\\b.pdf',
            'https://youtu.be/agce-HkgqCo',
        ], \CADCO_Import_Media::links($cell));
    }

    public function test_links_ignores_a_file_name_and_n_a(): void
    {
        self::assertSame([], \CADCO_Import_Media::links('CAP-F.jpeg'));
        self::assertSame([], \CADCO_Import_Media::links('n/a'));
    }

    public function test_the_tenant_is_the_most_cited_sharepoint_host(): void
    {
        $cells = [
            'https://cadcoltd232.sharepoint.com/:b:/s/A/one',
            'https://other.sharepoint.com/:b:/s/A/two',
            "x\nhttps://cadcoltd232.sharepoint.com/:i:/s/A/three",
            'https://protect.checkpoint.com/v2/r01/___https:/cadcoltd232.sharepoint.com/:b:/s/A/four___.x',
        ];

        self::assertSame('cadcoltd232.sharepoint.com', \CADCO_Import_Media::tenant_host($cells));
        self::assertSame('', \CADCO_Import_Media::tenant_host(['n/a', 'https://youtu.be/agce-HkgqCo']));
    }

    public function test_an_empty_link_resolves_to_nothing(): void
    {
        $r = self::resolve('');

        self::assertSame('unreachable', $r['kind']);
        self::assertSame('', $r['key']);
    }
}
