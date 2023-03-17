<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\Internal\DownloadFileRequest;
use Fleetbase\Http\Requests\Internal\UploadBase64FileRequest;
use Fleetbase\Http\Requests\Internal\UploadFileRequest;
use Fleetbase\Models\File;
use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends FleetbaseController
{
    /**
     * The resource to query
     *
     * @var string
     */
    public $resource = 'file';

    /**
     * Handle file uploads
     *
     * @param \Fleetbase\Http\Requests\Internal\UploadFileRequest $request
     * @return \Illuminate\Http\Response
     */
    public function upload(UploadFileRequest $request)
    {
        $disk = env('FILESYSTEM_DRIVER');
        $type = $request->input('type');
        $size = $request->input('file_size', $request->file->getSize());
        $path = $request->input('path', 'uploads');
        $visibility = $request->input('visibility', 'public');
        $subjectId = $request->input('subject_uuid');
        $subjectType = $request->input('subject_type');

        // Correct $path for uploads
        if (Str::startsWith($path, 'uploads') && $disk === 'uploads') {
            $path = str_replace('uploads/', '', $path);
        }

        // Upload file and create record
        $fileName = File::randomFileNameFromRequest($request);
        $path = $request->file->storeAs(
            $path,
            $fileName,
            [
                'disk' => $disk,
                'visibility' => $visibility
            ]
        );

        // \Fleetbase\Models\File $file
        $file = File::createFromUpload($request->file, $path, $type, $size);

        // if we have subject_uuid and type
        if ($request->has(['subject_uuid', 'subject_type'])) {
            $file->update(
                [
                    'subject_uuid' => $subjectId,
                    'subject_type' => Utils::getMutationType($subjectType)
                ]
            );
        } else if ($subjectType) {
            $file->update(
                [
                    'subject_type' => Utils::getMutationType($subjectType)
                ]
            );
        }

        // done
        return response()->json(
            [
                'file' => $file,
            ]
        );
    }

    /**
     * Handle file upload of base64
     *
     * @param \Fleetbase\Http\Requests\Internal\UploadBase64FileRequest $request
     * @return \Illuminate\Http\Response
     */
    public function uploadBase64(UploadBase64FileRequest $request)
    {
        $disk = env('FILESYSTEM_DRIVER');
        $data = $request->input('data');
        $path = $request->input('path', 'uploads');
        $visibility = $request->input('visibility', 'public');
        $fileName = $request->input('file_name');
        $fileType = $request->input('file_type', 'image');
        $contentType = $request->input('content_type', 'image/png');
        $subjectId = $request->input('subject_uuid');
        $subjectType = $request->input('subject_type');

        if (!$data) {
            return response()->json(['errors' => ['Oops! Looks like nodata was provided for upload.']], 400);
        }

        // Correct $path for uploads
        if (Str::startsWith($path, 'uploads') && $disk === 'uploads') {
            $path = str_replace('uploads/', '', $path);
        }

        // set the full file path
        $fullPath = $path . '/' . $fileName;

        // upload file to path
        Storage::disk($disk)->put($fullPath, base64_decode($data), $visibility);

        // create the file model
        // create file record for upload
        $file = File::create([
            'company_uuid' => session('company'),
            'uploader_uuid' => session('user'),
            'subject_uuid' => $subjectId,
            'subject_type' => Utils::getMutationType($subjectType),
            'name' => basename($fullPath),
            'original_filename' => basename($fullPath),
            'extension' => 'png',
            'content_type' => $contentType,
            'path' => $fullPath,
            'bucket' => config('filesystems.disks.s3.bucket'),
            'type' => $fileType,
            'size' => Utils::getBase64ImageSize($data)
        ]);

        // done
        return response()->json(
            [
                'file' => $file,
            ]
        );
    }

    /**
     * Handle file uploads
     *
     * @param string $id
     * @param \Fleetbase\Http\Requests\Internal\UploadFileRequest $request
     * @return \Illuminate\Http\Response
     */
    public function download(?string $id, DownloadFileRequest $request)
    {
        $disk = env('FILESYSTEM_DRIVER');
        $file = File::where('uuid', $id)->first();

        return Storage::disk($disk)->download($file->path, $file->name);
    }
}
