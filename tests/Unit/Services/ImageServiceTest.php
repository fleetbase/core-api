<?php

use Fleetbase\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Intervention\Image\Exceptions\DecoderException;
use Psr\Log\NullLogger;

function image_service_upload(int $width = 80, int $height = 40, string $name = 'source.png'): UploadedFile
{
    $path  = tempnam(sys_get_temp_dir(), 'fleetbase-image-');
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 40, 200));
    imagepng($image, $path);
    imagedestroy($image);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

function image_service_text_upload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fleetbase-text-');
    file_put_contents($path, 'not an image');

    return new UploadedFile($path, 'notes.txt', 'text/plain', null, true);
}

function image_service_boot(array $config = []): ImageService
{
    $container = bind_test_container(array_merge([
        'image.presets' => [
            'thumb' => ['width' => 20, 'height' => 20, 'name' => 'Thumbnail'],
            'md'    => ['width' => 64, 'height' => 32, 'name' => 'Medium'],
        ],
        'image.default_quality' => 70,
        'image.allow_upscale'   => false,
    ], $config));
    $container->instance('log', new NullLogger());
    Facade::clearResolvedInstances();

    return new ImageService();
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('image service detects images and reports dimensions with safe failure fallback', function () {
    $service = image_service_boot();
    $image   = image_service_upload(90, 45);
    $text    = image_service_text_upload();

    expect($service->isImage($image))->toBeTrue()
        ->and($service->isImage($text))->toBeFalse()
        ->and($service->getDimensions($image))->toBe([
            'width'  => 90,
            'height' => 45,
        ]);
});

test('image service exposes configured presets and falls back to md for unknown preset names', function () {
    $service = image_service_boot();
    $image   = image_service_upload(120, 80);

    $preset  = $service->getPreset('thumb');
    $all     = $service->getPresets();
    $resized = $service->resizePreset($image, 'missing-preset', 'fit', 80, true);

    expect($preset)->toBe(['width' => 20, 'height' => 20, 'name' => 'Thumbnail'])
        ->and($all)->toHaveKeys(['thumb', 'md'])
        ->and(getimagesizefromstring($resized))->toMatchArray([
            0 => 48,
            1 => 32,
        ]);
});

test('image service resizes using supported modes and output formats', function (string $mode, ?string $format, int $width, int $height, int $expectedWidth, int $expectedHeight) {
    $service = image_service_boot();
    $image   = image_service_upload(120, 80);

    $resized    = $service->resize($image, $width, $height, $mode, 85, $format, true);
    $dimensions = getimagesizefromstring($resized);

    expect($resized)->toBeString()
        ->and(strlen($resized))->toBeGreaterThan(0)
        ->and($dimensions[0])->toBe($expectedWidth)
        ->and($dimensions[1])->toBe($expectedHeight);
})->with([
    'fit png'     => ['fit', 'png', 60, 40, 60, 40],
    'crop jpg'    => ['crop', 'jpg', 50, 50, 50, 50],
    'stretch png' => ['stretch', 'png', 30, 30, 30, 30],
    'contain png' => ['contain', 'png', 70, 70, 70, 70],
]);

test('image service avoids upscaling smaller files unless explicitly allowed', function () {
    $service = image_service_boot();
    $image   = image_service_upload(30, 20);

    $notUpscaled = $service->resize($image, 80, 80, 'fit', null, 'png', false);
    $upscaled    = $service->resize($image, 80, 80, 'fit', null, 'png', true);

    expect(getimagesizefromstring($notUpscaled))->toMatchArray([
        0 => 30,
        1 => 20,
    ])->and(getimagesizefromstring($upscaled))->toMatchArray([
        0 => 80,
        1 => 53,
    ]);
});

test('image service rethrows resize failures after logging context', function () {
    $service = image_service_boot();
    $upload  = image_service_upload();
    unlink($upload->getRealPath());

    expect(fn () => $service->resize($upload, 20, 20))->toThrow(DecoderException::class);
});
