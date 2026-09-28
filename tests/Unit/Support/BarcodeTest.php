<?php

use Fleetbase\Support\Barcode;

class BarcodeFixedMatrix extends Barcode
{
    protected static function qrCodeMatrix(string $data, string $errorCorrection): array
    {
        return [
            'num_rows' => 3,
            'num_cols' => 3,
            'bcode'    => [
                [1, 1, 0],
                [0, 0, 0],
                [1, 0, 1],
            ],
        ];
    }
}

test('barcode renders qr modules as merged runs on a white background with a quiet zone', function () {
    expect(BarcodeFixedMatrix::qrCodeSvg('anything', 'M', 1))->toBe(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 5 5" shape-rendering="crispEdges">'
        . '<rect width="100%" height="100%" fill="#ffffff"/>'
        . '<path fill="#000000" d="M1 1h2v1h-2zM1 3h1v1h-1zM3 3h1v1h-1z"/>'
        . '</svg>'
    );
});

test('barcode encodes real qr codes with the standard quiet zone', function () {
    $url = 'otpauth://totp/Fleetbase:user%40example.com?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Fleetbase';
    $svg = @Barcode::qrCodeSvg($url);

    preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $size);

    // A QR code is 21 + 4n modules square, plus 4 blank modules on each side
    expect((int) $size[1])->toBe((int) $size[2])
        ->and(((int) $size[1] - 8 - 21) % 4)->toBe(0)
        ->and($svg)->toContain('fill="#ffffff"')
        ->and($svg)->toContain('<path fill="#000000" d="M4 4h7v1h-7z');

    // Each render may pick a different (equally valid) QR mask, so compare the size only
    $dataUri = @Barcode::qrCodeDataUri($url);

    expect($dataUri)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($dataUri, 26)))->toContain('viewBox="0 0 ' . $size[1] . ' ' . $size[2] . '"');
});

test('barcode rejects unencodable input and unknown error correction levels', function () {
    expect(fn () => Barcode::qrCodeSvg(''))->toThrow(InvalidArgumentException::class, 'The data could not be encoded as a QR code.')
        ->and(fn () => Barcode::qrCodeSvg('data', 'X'))->toThrow(InvalidArgumentException::class, 'QR code error correction must be L, M, Q or H.');
});

test('barcode renders png barcodes through milon', function () {
    $png = @Barcode::png('order-123', 'QRCODE');

    expect($png)->toBeString()
        ->and(substr(base64_decode($png), 1, 3))->toBe('PNG');
})->skip(!function_exists('imagecreate') && !extension_loaded('imagick'), 'needs GD or Imagick');
