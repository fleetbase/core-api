<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\Internal\DownloadFileRequest;
use Fleetbase\Http\Requests\Internal\UploadBase64FileRequest;
use Fleetbase\Http\Requests\Internal\UploadFileRequest;
use Fleetbase\Models\File;
use Fleetbase\Services\ImageService;
use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'file';

    /**
     * @var ImageService
     */
    protected ImageService $imageService;

    /**
     * Create a new FileController instance.
     */
    public function __construct(ImageService $imageService)
    {
        parent::__construct();
        $this->imageService = $imageService;
    }

    /**
     * Handle file uploads with optional image resizing.
     *
     * @return \Illuminate\Http\Response
     */
    public function upload(UploadFileRequest $request)
    {
        $disk   = $request->input('disk', config('filesystems.default'));
        $bucket = $request->input('bucket', config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket')));
        $type   = $request->input('type');
        $path   = $request->input('path', 'uploads');

        // Image resize parameters
        $resize        = $request->input('resize');
        $resizeWidth   = $request->input('resize_width');
        $resizeHeight  = $request->input('resize_height');
        $resizeMode    = $request->input('resize_mode', 'fit');
        $resizeQuality = $request->input('resize_quality');
        $resizeFormat  = $request->input('resize_format');
        $resizeUpscale = $request->boolean('resize_upscale', false);

        // Generate filename
        $fileName = File::randomFileNameFromRequest($request);

        // Check if image resizing is requested
        $shouldResize = ($resize || $resizeWidth || $resizeHeight) && $this->imageService->isImage($request->file);

        if ($shouldResize) {
            // Resize image
            try {
                if ($resize) {
                    // Use preset
                    $resizedData = $this->imageService->resizePreset(
                        $request->file,
                        $resize,
                        $resizeMode,
                        $resizeQuality,
                        $resizeUpscale
                    );
                } else {
                    // Use explicit dimensions
                    $resizedData = $this->imageService->resize(
                        $request->file,
                        $resizeWidth,
                        $resizeHeight,
                        $resizeMode,
                        $resizeQuality,
                        $resizeFormat,
                        $resizeUpscale
                    );
                }

                // Update filename extension if format changed
                if ($resizeFormat) {
                    $fileName = preg_replace('/\.[^.]+$/', '.' . $resizeFormat, $fileName);
                }

                // Upload resized image
                $fullPath = $path . '/' . $fileName;
                $uploaded = Storage::disk($disk)->put($fullPath, $resizedData);

                if (!$uploaded) {
                    return response()->error('Failed to upload resized image.');
                }

                $storedPath = $fullPath;
                $size       = strlen($resizedData);
            } catch (\Throwable $e) {
                return response()->error('Image resize failed: ' . $e->getMessage());
            }
        } else {
            // Upload original file without resizing
            $size = $request->input('file_size', $request->file->getSize());

            try {
                $storedPath = $request->file->storeAs($path, $fileName, ['disk' => $disk]);
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }

            if ($storedPath === false) {
                return response()->error('File upload failed.');
            }
        }

        // Create file record
        try {
            $file = File::createFromUpload(
                $request->file,
                $storedPath,
                $type,
                $size,
                $disk,
                $bucket
            );

            // Store resize metadata
            if ($shouldResize) {
                $file->setMeta('resized', true);
                $file->setMeta('resize_params', [
                    'preset'  => $resize,
                    'width'   => $resizeWidth,
                    'height'  => $resizeHeight,
                    'mode'    => $resizeMode,
                    'quality' => $resizeQuality,
                    'format'  => $resizeFormat,
                    'upscale' => $resizeUpscale,
                ]);
                $file->save();
            }
        } catch (\Throwable $e) {
            return response()->error($e->getMessage());
        }

        // Set the subject if specified
        $file->setSubjectFromRequest($request);

        // Done ✓
        return response()->json(
            [
                'file' => $file,
            ]
        );
    }

    /**
     * Handle file upload of base64 with optional image resizing.
     *
     * @return \Illuminate\Http\Response
     */
    public function uploadBase64(UploadBase64FileRequest $request)
    {
        $disk        = $request->input('disk', config('filesystems.default'));
        $bucket      = $request->input('bucket', config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket')));
        $data        = $request->input('data');
        $path        = $request->input('path', 'uploads');
        $fileName    = $request->input('file_name');
        $fileType    = $request->input('file_type', 'image');
        $contentType = $request->input('content_type', 'image/png');
        $subjectId   = $request->input('subject_uuid');
        $subjectType = $request->input('subject_type');

        // Image resize parameters
        $resize        = $request->input('resize');
        $resizeWidth   = $request->input('resize_width');
        $resizeHeight  = $request->input('resize_height');
        $resizeMode    = $request->input('resize_mode', 'fit');
        $resizeQuality = $request->input('resize_quality');
        $resizeFormat  = $request->input('resize_format');
        $resizeUpscale = $request->boolean('resize_upscale', false);

        if (!$data) {
            return response()->error('Oops! Looks like no data was provided for upload.', 400);
        }

        // Correct $path for uploads
        if (Str::startsWith($path, 'uploads') && $disk === 'uploads') {
            $path = str_replace('uploads/', '', $path);
        }

        // Decode base64
        $decoded = base64_decode($data);

        // Check if resizing is requested and file is image
        $shouldResize = ($resize || $resizeWidth || $resizeHeight) && str_starts_with($contentType, 'image/');

        if ($shouldResize) {
            try {
                // Create temporary file for Intervention Image
                $tempPath = tempnam(sys_get_temp_dir(), 'img_');
                file_put_contents($tempPath, $decoded);

                // Read and resize
                $image = $this->imageService->manager->read($tempPath);

                if ($resize) {
                    $preset = $this->imageService->getPreset($resize);
                    if ($resizeUpscale) {
                        $image->scale($preset['width'], $preset['height']);
                    } else {
                        $image->scaleDown($preset['width'], $preset['height']);
                    }
                } else {
                    switch ($resizeMode) {
                        case 'crop':
                            if ($resizeUpscale) {
                                $image->cover($resizeWidth, $resizeHeight);
                            } else {
                                $image->coverDown($resizeWidth, $resizeHeight);
                            }
                            break;
                        case 'fit':
                        default:
                            if ($resizeUpscale) {
                                $image->scale($resizeWidth, $resizeHeight);
                            } else {
                                $image->scaleDown($resizeWidth, $resizeHeight);
                            }
                            break;
                    }
                }

                // Encode
                $encoded = $resizeFormat
                    ? $image->toFormat($resizeFormat, $resizeQuality ?? 85)
                    : $image->encode(quality: $resizeQuality ?? 85);

                $decoded = $encoded->toString();

                // Clean up temp file
                unlink($tempPath);

                // Update filename if format changed
                if ($resizeFormat) {
                    $fileName = preg_replace('/\.[^.]+$/', '.' . $resizeFormat, $fileName);
                }
            } catch (\Throwable $e) {
                return response()->error('Image resize failed: ' . $e->getMessage());
            }
        }

        // Upload to storage
        $fullPath = $path . '/' . $fileName;

        try {
            $uploaded = Storage::disk($disk)->put($fullPath, $decoded);
        } catch (\Throwable $e) {
            return response()->error($e->getMessage());
        }

        if (!$uploaded) {
            return response()->error('File upload failed.');
        }

        // Create file record
        try {
            $file = File::create([
                'company_uuid'      => session('company'),
                'uploader_uuid'     => session('user'),
                'subject_uuid'      => $subjectId,
                'subject_type'      => Utils::getMutationType($subjectType),
                'disk'              => $disk,
                'original_filename' => basename($fullPath),
                'extension'         => pathinfo($fullPath, PATHINFO_EXTENSION),
                'content_type'      => $contentType,
                'path'              => $fullPath,
                'bucket'            => $bucket,
                'type'              => $fileType,
                'size'              => strlen($decoded),
            ]);

            // Store resize metadata
            if ($shouldResize) {
                $file->setMeta('resized', true);
                $file->setMeta('resize_params', [
                    'preset'  => $resize,
                    'width'   => $resizeWidth,
                    'height'  => $resizeHeight,
                    'mode'    => $resizeMode,
                    'quality' => $resizeQuality,
                    'format'  => $resizeFormat,
                    'upscale' => $resizeUpscale,
                ]);
                $file->save();
            }
        } catch (\Throwable $e) {
            return response()->error($e->getMessage());
        }

        // Done ✓
        return response()->json(
            [
                'file' => $file,
            ]
        );
    }

    /**
     * Handle file download.
     *
     * @return \Illuminate\Http\Response
     */
    public function download(?string $id, DownloadFileRequest $request)
    {
        $disk = $request->input('disk', config('filesystems.default'));
        $file = File::where('uuid', $id)->first();
        /** @var \Illuminate\Filesystem\FilesystemAdapter $filesystem */
        $filesystem = Storage::disk($disk);

        return $filesystem->download($file->path, $file->original_filename);
    }
}
