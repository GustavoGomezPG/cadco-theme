<?php

/**
 * A picture of a document's first page, for its card in the resource centre.
 *
 * A spec sheet's or manual's card otherwise shows an empty tinted box. The
 * first page is what a reader recognises the document by, so that is what
 * the card shows.
 *
 * Rendering a PDF needs a renderer on the server, and which one exists varies
 * by host, so two are tried in turn:
 *
 * 1. Imagick with Ghostscript — what WordPress itself uses for PDF thumbnails,
 *    and what managed WordPress hosts usually provide.
 * 2. Poppler's pdftoppm — common on Linux and macOS development machines,
 *    used only when PHP may run programs.
 *
 * Neither is required. Without both, render() returns '' and the card shows
 * the default cover for its kind of document (assets/img/cadco-doc-cover.svg).
 */

declare(strict_types=1);

final class CADCO_Import_Preview
{
    /** Width of the rendered page, in pixels: crisp on a 342px card at 2x. */
    private const WIDTH = 900;

    /**
     * Render the first page of $pdf to a temporary JPEG. Returns its path, or
     * '' when no renderer is available or the PDF cannot be read.
     */
    public static function render(string $pdf): string
    {
        if (!is_readable($pdf)) {
            return '';
        }

        $out = self::with_imagick($pdf);

        return $out !== '' ? $out : self::with_pdftoppm($pdf);
    }

    private static function with_imagick(string $pdf): string
    {
        if (!class_exists('Imagick')) {
            return '';
        }

        try {
            $image = new \Imagick();
            // Resolution before reading, or the page is rasterised at 72dpi
            // and scaled up into a blur.
            $image->setResolution(150, 150);
            $image->readImage($pdf . '[0]');
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            $image->thumbnailImage(self::WIDTH, 0);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);

            $out = self::temp();
            $image->writeImage($out);
            $image->clear();

            return self::valid($out) ? $out : '';
        } catch (\Throwable $e) {
            // No Ghostscript delegate, an encrypted PDF, a damaged file: the
            // next renderer, or the default cover.
            return '';
        }
    }

    private static function with_pdftoppm(string $pdf): string
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return '';
        }

        $binary = '';

        foreach (['/opt/homebrew/bin/pdftoppm', '/usr/local/bin/pdftoppm', '/usr/bin/pdftoppm'] as $candidate) {
            if (is_executable($candidate)) {
                $binary = $candidate;
                break;
            }
        }

        if ($binary === '') {
            return '';
        }

        $base = self::temp('');
        $cmd  = sprintf(
            '%s -f 1 -l 1 -singlefile -jpeg -jpegopt quality=85 -scale-to-x %d -scale-to-y -1 %s %s 2>/dev/null',
            escapeshellarg($binary),
            self::WIDTH,
            escapeshellarg($pdf),
            escapeshellarg($base)
        );

        exec($cmd, $output, $status);

        $out = $base . '.jpg';

        if ($status !== 0 || !self::valid($out)) {
            if (is_file($out)) {
                @unlink($out);
            }

            return '';
        }

        return $out;
    }

    private static function temp(string $ext = '.jpg'): string
    {
        $dir  = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir() . '/';
        $name = 'cadco-preview-' . bin2hex(random_bytes(6));

        return $dir . $name . $ext;
    }

    private static function valid(string $path): bool
    {
        return is_file($path) && filesize($path) > 1000
            && str_starts_with((string) file_get_contents($path, false, null, 0, 3), "\xFF\xD8\xFF");
    }
}
