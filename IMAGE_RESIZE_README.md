# Image Resizing Feature

## Installation

**Required:** Install Intervention Image library

```bash
composer require intervention/image
```

## Overview

This feature adds automatic image resizing capabilities to the file upload endpoints. Images can be resized on-the-fly during upload using presets or custom dimensions.

## Features

- ✅ **Smart resizing** - Never upscales by default (prevents quality loss)
- ✅ **Multiple presets** - thumb, sm, md, lg, xl, 2xl
- ✅ **Custom dimensions** - Specify exact width/height
- ✅ **Multiple modes** - fit, crop, stretch, contain
- ✅ **Format conversion** - Convert to jpg, png, webp, avif, etc.
- ✅ **Quality control** - Adjust compression quality (1-100)
- ✅ **Auto-detection** - Uses Imagick if available, falls back to GD
- ✅ **Backward compatible** - All parameters are optional

## API Usage

### Upload with Preset

```bash
# Resize to preset dimensions (max 1920x1080)
curl -X POST http://localhost/internal/v1/files/upload \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@large-image.jpg" \
  -F "resize=xl"
```

### Upload with Custom Dimensions

```bash
# Resize to max width 800px (height auto-calculated)
curl -X POST http://localhost/internal/v1/files/upload \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@large-image.jpg" \
  -F "resize_width=800" \
  -F "resize_mode=fit"
```

### Upload with Format Conversion

```bash
# Resize and convert to WebP
curl -X POST http://localhost/internal/v1/files/upload \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@image.png" \
  -F "resize=lg" \
  -F "resize_format=webp" \
  -F "resize_quality=90"
```

### Base64 Upload with Resize

```bash
curl -X POST http://localhost/internal/v1/files/upload-base64 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "data": "BASE64_ENCODED_IMAGE",
    "file_name": "photo.jpg",
    "resize": "md",
    "resize_mode": "crop"
  }'
```

## Parameters

| Parameter | Type | Values | Description |
|-----------|------|--------|-------------|
| `resize` | string | thumb, sm, md, lg, xl, 2xl | Preset dimensions |
| `resize_width` | integer | 1-10000 | Custom width in pixels |
| `resize_height` | integer | 1-10000 | Custom height in pixels |
| `resize_mode` | string | fit, crop, stretch, contain | Resize behavior |
| `resize_quality` | integer | 1-100 | Compression quality (default: 85) |
| `resize_format` | string | jpg, png, webp, gif, bmp, avif | Output format |
| `resize_upscale` | boolean | true, false | Allow upscaling (default: false) |

## Presets

| Preset | Dimensions | Use Case |
|--------|------------|----------|
| `thumb` | 150x150 | Thumbnails, avatars |
| `sm` | 320x240 | Mobile screens |
| `md` | 640x480 | Tablets, small displays |
| `lg` | 1024x768 | Desktop displays |
| `xl` | 1920x1080 | Full HD displays |
| `2xl` | 2560x1440 | 2K displays |

## Resize Modes

### fit (default)
Fits image within dimensions, maintains aspect ratio. No cropping.

```
Original: 4000x3000
Target: 1920x1080
Result: 1440x1080 (scaled to fit height)
```

### crop
Crops to exact dimensions, maintains aspect ratio.

```
Original: 4000x3000
Target: 1920x1080
Result: 1920x1080 (cropped from center)
```

### stretch
Stretches to exact dimensions, ignores aspect ratio.

```
Original: 4000x3000
Target: 1920x1080
Result: 1920x1080 (distorted)
```

### contain
Fits within dimensions with padding.

```
Original: 4000x3000
Target: 1920x1080
Result: 1440x1080 (with padding)
```

## Smart Resizing Behavior

By default, images are **never upscaled** to prevent quality loss:

| Original Size | Preset | Result | Notes |
|---------------|--------|--------|-------|
| 4000x3000 | xl | 1920x1440 | ✅ Scaled down |
| 800x600 | xl | 800x600 | ✅ Unchanged (already smaller) |
| 200x150 | xl | 200x150 | ✅ Not upscaled |

To force upscaling:

```bash
curl -X POST http://localhost/internal/v1/files/upload \
  -F "file=@small-image.jpg" \
  -F "resize=xl" \
  -F "resize_upscale=true"
```

⚠️ **Warning:** Upscaling can result in pixelated, low-quality images.

## Configuration

Edit `config/image.php` to customize:

```php
return [
    'driver' => 'imagick',  // or 'gd'
    'default_quality' => 85,
    'allow_upscale' => false,
    'presets' => [
        // Add custom presets
        'custom' => [
            'width' => 1280,
            'height' => 720,
            'name' => 'Custom Size',
        ],
    ],
];
```

## Environment Variables

```env
IMAGE_DRIVER=imagick
IMAGE_DEFAULT_QUALITY=85
IMAGE_ALLOW_UPSCALE=false
IMAGE_MAX_WIDTH=10000
IMAGE_MAX_HEIGHT=10000
```

## Examples

### Profile Photo Upload

```javascript
// Frontend: Upload user profile photo
const formData = new FormData();
formData.append('file', photoFile);
formData.append('resize', 'md');
formData.append('resize_mode', 'crop');
formData.append('resize_format', 'webp');

fetch('/internal/v1/files/upload', {
  method: 'POST',
  headers: { 'Authorization': `Bearer ${token}` },
  body: formData
});
```

### Product Image Upload

```javascript
// Upload product image, ensure it's not too large
const formData = new FormData();
formData.append('file', productImage);
formData.append('resize', 'xl');  // Max 1920x1080
formData.append('resize_mode', 'fit');
formData.append('resize_quality', 90);

fetch('/internal/v1/files/upload', {
  method: 'POST',
  headers: { 'Authorization': `Bearer ${token}` },
  body: formData
});
```

### Thumbnail Generation

```javascript
// Generate square thumbnail
const formData = new FormData();
formData.append('file', imageFile);
formData.append('resize', 'thumb');  // 150x150
formData.append('resize_mode', 'crop');

fetch('/internal/v1/files/upload', {
  method: 'POST',
  headers: { 'Authorization': `Bearer ${token}` },
  body: formData
});
```

## Performance

Typical processing times (on modern server):

| Original Size | Preset | Processing Time | Final Size |
|---------------|--------|-----------------|------------|
| 4000x3000 (3MB) | xl | ~200ms | ~500KB |
| 4000x3000 (3MB) | lg | ~150ms | ~200KB |
| 4000x3000 (3MB) | md | ~100ms | ~80KB |
| 800x600 (200KB) | xl | ~10ms | 200KB (unchanged) |

## Troubleshooting

### Check Image Library Installation

```bash
php -m | grep -E "gd|imagick"
```

### Test Image Processing

```bash
php -r "
if (extension_loaded('imagick')) {
    echo 'Imagick is available';
} elseif (extension_loaded('gd')) {
    echo 'GD is available';
} else {
    echo 'No image library available';
}
"
```

### Install Image Libraries

```bash
# Ubuntu/Debian
sudo apt-get install php-gd php-imagick
sudo systemctl restart php-fpm

# Verify
php -m | grep -E "gd|imagick"
```

## Metadata

Resized images have metadata stored in the `meta` field:

```json
{
  "resized": true,
  "resize_params": {
    "preset": "xl",
    "width": null,
    "height": null,
    "mode": "fit",
    "quality": 85,
    "format": null,
    "upscale": false
  }
}
```

## Backward Compatibility

All resize parameters are **optional**. Existing upload code continues to work without changes:

```bash
# Old code (still works)
curl -X POST http://localhost/internal/v1/files/upload \
  -F "file=@image.jpg"

# New code (with resize)
curl -X POST http://localhost/internal/v1/files/upload \
  -F "file=@image.jpg" \
  -F "resize=lg"
```

## Security

- ✅ Maximum dimensions enforced (10000x10000)
- ✅ File type validation
- ✅ Memory limit checks
- ✅ Input validation on all parameters

## Support

For issues or questions, refer to:
- Intervention Image docs: https://image.intervention.io/
- Laravel file storage: https://laravel.com/docs/filesystem
