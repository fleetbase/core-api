<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Image Processing Driver
    |--------------------------------------------------------------------------
    |
    | Intervention Image supports "GD Library" and "Imagick" to process images
    | internally. Depending on your PHP setup, you can choose one of them.
    |
    | Supported: "gd", "imagick"
    |
    | Note: The driver is auto-detected based on available extensions.
    | Imagick is preferred if available (better quality and more features).
    |
    */

    'driver' => env('IMAGE_DRIVER', extension_loaded('imagick') ? 'imagick' : 'gd'),

    /*
    |--------------------------------------------------------------------------
    | Default Quality
    |--------------------------------------------------------------------------
    |
    | The default quality for image compression (1-100).
    | Higher values mean better quality but larger file sizes.
    |
    | Recommended: 85 (good balance between quality and size)
    |
    */

    'default_quality' => env('IMAGE_DEFAULT_QUALITY', 85),

    /*
    |--------------------------------------------------------------------------
    | Allow Upscaling
    |--------------------------------------------------------------------------
    |
    | Whether to allow upscaling small images to larger dimensions.
    | When false, images smaller than the target size are left unchanged.
    |
    | Recommended: false (prevents quality loss from upscaling)
    |
    */

    'allow_upscale' => env('IMAGE_ALLOW_UPSCALE', false),

    /*
    |--------------------------------------------------------------------------
    | Maximum Dimensions
    |--------------------------------------------------------------------------
    |
    | Safety limits for image dimensions to prevent memory exhaustion.
    |
    */

    'max_width' => env('IMAGE_MAX_WIDTH', 10000),
    'max_height' => env('IMAGE_MAX_HEIGHT', 10000),

    /*
    |--------------------------------------------------------------------------
    | Resize Presets
    |--------------------------------------------------------------------------
    |
    | Predefined dimension presets for common use cases.
    | These act as maximum dimensions (images won't be upscaled by default).
    |
    | Usage: resize=thumb, resize=sm, resize=md, etc.
    |
    */

    'presets' => [
        'thumb' => [
            'width'  => 150,
            'height' => 150,
            'name'   => 'Thumbnail',
        ],
        'sm' => [
            'width'  => 320,
            'height' => 240,
            'name'   => 'Small',
        ],
        'md' => [
            'width'  => 640,
            'height' => 480,
            'name'   => 'Medium',
        ],
        'lg' => [
            'width'  => 1024,
            'height' => 768,
            'name'   => 'Large',
        ],
        'xl' => [
            'width'  => 1920,
            'height' => 1080,
            'name'   => 'Extra Large',
        ],
        '2xl' => [
            'width'  => 2560,
            'height' => 1440,
            'name'   => '2K',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Formats
    |--------------------------------------------------------------------------
    |
    | Image formats that can be used for conversion.
    |
    */

    'formats' => [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
        'bmp',
        'avif',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resize Modes
    |--------------------------------------------------------------------------
    |
    | Available resize modes:
    |
    | - fit: Resize to fit within dimensions, maintain aspect ratio (default)
    | - crop: Crop to exact dimensions, maintain aspect ratio
    | - stretch: Stretch to exact dimensions, ignore aspect ratio
    | - contain: Fit within dimensions with padding
    |
    */

    'modes' => [
        'fit',
        'crop',
        'stretch',
        'contain',
    ],
];
