<?php

namespace Fleetbase\Support;

use Milon\Barcode\DNS2D;
use Milon\Barcode\QRcode;

/**
 * Generates QR codes and other 2D barcodes.
 *
 * Extensions should use this rather than calling `milon/barcode` directly, so the
 * barcode library is owned and versioned in one place.
 */
class Barcode
{
    /**
     * Render a QR code as an SVG document.
     *
     * The code is drawn dark on a white background with a quiet zone around it, so it
     * scans on any page background, including dark themes.
     *
     * @param string $data            the content to encode
     * @param string $errorCorrection L, M, Q or H
     * @param int    $quietZone       the blank border, in modules (the QR spec asks for 4)
     *
     * @throws \InvalidArgumentException if the data cannot be encoded
     */
    public static function qrCodeSvg(string $data, string $errorCorrection = 'M', int $quietZone = 4): string
    {
        $matrix = static::qrCodeMatrix($data, $errorCorrection);
        $rows   = $matrix['num_rows'];
        $cols   = $matrix['num_cols'];
        $width  = $cols + ($quietZone * 2);
        $height = $rows + ($quietZone * 2);

        // One path segment per horizontal run of dark modules keeps the SVG small.
        $path = '';
        for ($row = 0; $row < $rows; $row++) {
            $col = 0;
            while ($col < $cols) {
                if (empty($matrix['bcode'][$row][$col])) {
                    $col++;
                    continue;
                }

                $start = $col;
                while ($col < $cols && !empty($matrix['bcode'][$row][$col])) {
                    $col++;
                }

                $run = $col - $start;
                $path .= 'M' . ($start + $quietZone) . ' ' . ($row + $quietZone) . 'h' . $run . 'v1h-' . $run . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#ffffff"/>'
            . '<path fill="#000000" d="' . $path . '"/>'
            . '</svg>';
    }

    /**
     * Render a QR code as an SVG data URI, ready for an `<img src>`.
     *
     * @param string $data            the content to encode
     * @param string $errorCorrection L, M, Q or H
     */
    public static function qrCodeDataUri(string $data, string $errorCorrection = 'M'): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(static::qrCodeSvg($data, $errorCorrection));
    }

    /**
     * Render a 2D barcode as a base64-encoded PNG, with a transparent background.
     *
     * @param string $data the content to encode
     * @param string $type a `milon/barcode` 2D type, e.g. `QRCODE`, `QRCODE,H`, `PDF417` or `DATAMATRIX`
     * @param int    $w    the width of one module, in pixels
     * @param int    $h    the height of one module, in pixels
     *
     * @return string|false the PNG, or false when no image library is available
     */
    public static function png(string $data, string $type = 'QRCODE', int $w = 3, int $h = 3): string|false
    {
        return (new DNS2D())->setStorPath(sys_get_temp_dir())->getBarcodePNG($data, $type, $w, $h);
    }

    /**
     * Get the module matrix for a QR code.
     *
     * @return array<string, mixed> with `num_rows`, `num_cols` and the `bcode` module rows
     */
    protected static function qrCodeMatrix(string $data, string $errorCorrection): array
    {
        $errorCorrection = strtoupper($errorCorrection);
        if (!in_array($errorCorrection, ['L', 'M', 'Q', 'H'], true)) {
            throw new \InvalidArgumentException('QR code error correction must be L, M, Q or H.');
        }

        $matrix = $data === '' ? false : (new QRcode($data, $errorCorrection))->getBarcodeArray();
        if (!is_array($matrix) || empty($matrix['num_rows'])) {
            throw new \InvalidArgumentException('The data could not be encoded as a QR code.');
        }

        return $matrix;
    }
}
