<?php

namespace Fleetbase\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

class ImageService
{
    protected ImageManager $manager;

    protected array $presets;

    protected int $defaultQuality;

    protected bool $allowUpscale;

    /**
     * Create a new ImageService instance.
     */
    public function __construct()
    {
        // Auto-detect best driver (prefer Imagick for better quality)
        $driver = extension_loaded('imagick') ? new ImagickDriver() : new GdDriver();

        $this->manager = new ImageManager($driver);

        // Load configuration
        $this->presets        = config('image.presets', $this->getDefaultPresets());
        $this->defaultQuality = config('image.default_quality', 85);
        $this->allowUpscale   = config('image.allow_upscale', false);

        Log::info('ImageService initialized', [
            'driver'          => extension_loaded('imagick') ? 'imagick' : 'gd',
            'presets'         => array_keys($this->presets),
            'default_quality' => $this->defaultQuality,
        ]);
    }

    /**
     * Check if file is an image.
     */
    public function isImage(UploadedFile $file): bool
    {
        $mimeType = $file->getMimeType();

        return $mimeType && str_starts_with($mimeType, 'image/');
    }

    /**
     * Get image dimensions.
     */
    public function getDimensions(UploadedFile $file): array
    {
        try {
            $image = $this->manager->read($file->getRealPath());

            return [
                'width'  => $image->width(),
                'height' => $image->height(),
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to get image dimensions', [
                'file'  => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return ['width' => 0, 'height' => 0];
        }
    }

    /**
     * Resize image with smart behavior (never upscale by default).
     */
    public function resize(
        UploadedFile $file,
        ?int $width = null,
        ?int $height = null,
        string $mode = 'fit',
        ?int $quality = null,
        ?string $format = null,
        ?bool $allowUpscale = null,
    ): string {
        $quality           = $quality ?? $this->defaultQuality;
        $allowUpscale      = $allowUpscale ?? $this->allowUpscale;
        $originalExtension = $file->getClientOriginalExtension();

        try {
            $image = $this->manager->read($file->getRealPath());

            // Get original dimensions
            $originalWidth  = $image->width();
            $originalHeight = $image->height();

            Log::debug('Resizing image', [
                'original' => "{$originalWidth}x{$originalHeight}",
                'target'   => "{$width}x{$height}",
                'mode'     => $mode,
                'upscale'  => $allowUpscale,
            ]);

            // Check if resize is needed
            if (!$allowUpscale) {
                // Don't resize if image is already smaller
                if ($width && $height) {
                    if ($originalWidth <= $width && $originalHeight <= $height) {
                        Log::debug('Image already smaller than target, skipping resize');

                        return $this->encodeImage($image, $format, $quality, $originalExtension);
                    }
                } elseif ($width && $originalWidth <= $width) {
                    Log::debug('Image width already smaller, skipping resize');

                    return $this->encodeImage($image, $format, $quality);
                } elseif ($height && $originalHeight <= $height) {
                    Log::debug('Image height already smaller, skipping resize');

                    return $this->encodeImage($image, $format, $quality);
                }
            }

            // Apply resize based on mode
            switch ($mode) {
                case 'crop':
                    // Crop to exact dimensions
                    if ($allowUpscale) {
                        $image->cover($width, $height);
                    } else {
                        $image->coverDown($width, $height);
                    }
                    break;

                case 'stretch':
                    // Stretch to exact dimensions (ignores aspect ratio)
                    if ($allowUpscale) {
                        $image->resize($width, $height);
                    } else {
                        $image->scaleDown($width, $height);
                    }
                    break;

                case 'contain':
                    // Fit within dimensions with padding
                    if ($allowUpscale) {
                        $image->contain($width, $height);
                    } else {
                        $image->containDown($width, $height);
                    }
                    break;

                case 'fit':
                default:
                    // Fit within dimensions, maintain aspect ratio
                    if ($allowUpscale) {
                        $image->scale($width, $height);
                    } else {
                        $image->scaleDown($width, $height);
                    }
                    break;
            }

            $result = $this->encodeImage($image, $format, $quality, $originalExtension);

            Log::info('Image resized successfully', [
                'final_size' => strlen($result) . ' bytes',
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('Image resize failed', [
                'file'  => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Resize using preset (smart behavior).
     */
    public function resizePreset(
        UploadedFile $file,
        string $preset,
        string $mode = 'fit',
        ?int $quality = null,
        ?bool $allowUpscale = null,
    ): string {
        $dimensions = $this->presets[$preset] ?? $this->presets['md'];

        Log::debug('Using preset', [
            'preset'     => $preset,
            'dimensions' => $dimensions,
        ]);

        return $this->resize(
            $file,
            $dimensions['width'],
            $dimensions['height'],
            $mode,
            $quality,
            null,
            $allowUpscale
        );
    }

    /**
     * Get preset dimensions.
     */
    public function getPreset(string $preset): ?array
    {
        return $this->presets[$preset] ?? null;
    }

    /**
     * Get all available presets.
     */
    public function getPresets(): array
    {
        return $this->presets;
    }

    /**
     * Encode image to format.
     */
    protected function encodeImage($image, ?string $format, int $quality, ?string $originalExtension = null): string
    {
        if ($format) {
            Log::debug('Converting image format', ['format' => $format, 'quality' => $quality]);

            return $image->toFormat($format, $quality)->toString();
        }

        // Use original extension or default to jpg
        $extension = $originalExtension ?? 'jpg';

        switch (strtolower($extension)) {
            case 'png':
                return $image->toPng()->toString();
            case 'gif':
                return $image->toGif()->toString();
            case 'webp':
                return $image->toWebp($quality)->toString();
            case 'avif':
                return $image->toAvif($quality)->toString();
            case 'bmp':
                return $image->toBitmap()->toString();
            default:
                return $image->toJpeg($quality)->toString();
        }
    }

    /**
     * Get default presets.
     */
    protected function getDefaultPresets(): array
    {
        return [
            'thumb' => ['width' => 150,  'height' => 150,   'name' => 'Thumbnail'],
            'sm'    => ['width' => 320,  'height' => 240,   'name' => 'Small'],
            'md'    => ['width' => 640,  'height' => 480,   'name' => 'Medium'],
            'lg'    => ['width' => 1024, 'height' => 768,   'name' => 'Large'],
            'xl'    => ['width' => 1920, 'height' => 1080,  'name' => 'Extra Large'],
            '2xl'   => ['width' => 2560, 'height' => 1440,  'name' => '2K'],
        ];
    }
}
