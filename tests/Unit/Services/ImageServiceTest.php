<?php

use Fleetbase\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Intervention\Image\Exceptions\DecoderException;
use Psr\Log\NullLogger;

class ImageServiceFakeImage
{
    public array $calls = [];

    public function __construct(private int $width, private int $height)
    {
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function cover(?int $width, ?int $height): self
    {
        $this->calls[] = ['cover', $width, $height];

        return $this;
    }

    public function coverDown(?int $width, ?int $height): self
    {
        $this->calls[] = ['coverDown', $width, $height];

        return $this;
    }

    public function resize(?int $width, ?int $height): self
    {
        $this->calls[] = ['resize', $width, $height];

        return $this;
    }

    public function scaleDown(?int $width, ?int $height): self
    {
        $this->calls[] = ['scaleDown', $width, $height];

        return $this;
    }

    public function contain(?int $width, ?int $height): self
    {
        $this->calls[] = ['contain', $width, $height];

        return $this;
    }

    public function containDown(?int $width, ?int $height): self
    {
        $this->calls[] = ['containDown', $width, $height];

        return $this;
    }

    public function scale(?int $width, ?int $height): self
    {
        $this->calls[] = ['scale', $width, $height];

        return $this;
    }

    public function toPng(): object
    {
        return $this->encoded('png');
    }

    public function toGif(): object
    {
        return $this->encoded('gif');
    }

    public function toWebp(int $quality): object
    {
        return $this->encoded('webp:' . $quality);
    }

    public function toAvif(int $quality): object
    {
        return $this->encoded('avif:' . $quality);
    }

    public function toBitmap(): object
    {
        return $this->encoded('bmp');
    }

    public function toJpeg(int $quality): object
    {
        return $this->encoded('jpg:' . $quality);
    }

    private function encoded(string $payload): object
    {
        return new class($payload) {
            public function __construct(private string $payload)
            {
            }

            public function toString(): string
            {
                return $this->payload;
            }
        };
    }
}

class ImageServiceFakeReader extends ImageService
{
    public function __construct(private ImageServiceFakeImage $image, private bool $throws = false)
    {
        $this->presets        = ['md' => ['width' => 64, 'height' => 32, 'name' => 'Medium']];
        $this->defaultQuality = 70;
        $this->allowUpscale   = false;
    }

    public function read(string $path): mixed
    {
        if ($this->throws) {
            throw new RuntimeException('decoder failed');
        }

        return $this->image;
    }
}

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

test('image service returns zero dimensions when decoding fails', function () {
    $service = new ImageServiceFakeReader(new ImageServiceFakeImage(10, 10), true);
    $upload  = image_service_text_upload();

    expect($service->getDimensions($upload))->toBe([
        'width'  => 0,
        'height' => 0,
    ]);
});

test('image service skips width only and height only upscales using default encoding', function (?int $width, ?int $height, string $expected) {
    $image   = new ImageServiceFakeImage(30, 20);
    $service = new ImageServiceFakeReader($image);
    $upload  = image_service_upload(30, 20, 'source.webp');

    $encoded = $service->resize($upload, $width, $height, 'fit', 65, null, false);

    expect($encoded)->toBe($expected)
        ->and($image->calls)->toBe([]);
})->with([
    'width only'  => [80, null, 'jpg:65'],
    'height only' => [null, 80, 'jpg:65'],
]);

test('image service dispatches non-upscale resize modes and explicit image formats', function (string $mode, string $format, array $expectedCall, string $expectedEncoding) {
    $image   = new ImageServiceFakeImage(120, 80);
    $service = new ImageServiceFakeReader($image);
    $upload  = image_service_upload(120, 80, 'source.jpg');

    $encoded = $service->resize($upload, 40, 30, $mode, 55, $format, false);

    expect($image->calls)->toBe([$expectedCall])
        ->and($encoded)->toBe($expectedEncoding);
})->with([
    'crop gif'       => ['crop', 'gif', ['coverDown', 40, 30], 'gif'],
    'stretch webp'   => ['stretch', 'webp', ['scaleDown', 40, 30], 'webp:55'],
    'contain avif'   => ['contain', 'avif', ['containDown', 40, 30], 'avif:55'],
    'fit bmp'        => ['fit', 'bmp', ['scaleDown', 40, 30], 'bmp'],
    'unknown format' => ['fit', 'tiff', ['scaleDown', 40, 30], 'jpg:55'],
]);

test('image service dispatches upscale resize modes and original extension encoders', function (string $mode, string $extension, array $expectedCall, string $expectedEncoding) {
    $image   = new ImageServiceFakeImage(40, 30);
    $service = new ImageServiceFakeReader($image);
    $upload  = image_service_upload(40, 30, 'source.' . $extension);

    $encoded = $service->resize($upload, 80, 60, $mode, 45, null, true);

    expect($image->calls)->toBe([$expectedCall])
        ->and($encoded)->toBe($expectedEncoding);
})->with([
    'crop png'     => ['crop', 'png', ['cover', 80, 60], 'png'],
    'stretch gif'  => ['stretch', 'gif', ['resize', 80, 60], 'gif'],
    'contain webp' => ['contain', 'webp', ['contain', 80, 60], 'webp:45'],
    'fit avif'     => ['fit', 'avif', ['scale', 80, 60], 'avif:45'],
    'default bmp'  => ['unknown-mode', 'bmp', ['scale', 80, 60], 'bmp'],
    'default jpg'  => ['fit', 'jpg', ['scale', 80, 60], 'jpg:45'],
]);
