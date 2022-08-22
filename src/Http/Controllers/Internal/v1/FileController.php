<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Fleetbase\Models\File;
use Fleetbase\Support\Utils;
use Vinkla\Hashids\Facades\Hashids;

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
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function upload(Request $request)
    {
        // additional attributes
        $type = $request->input('type');
        $size = $request->input('file_size');
        $path = $request->input('path');
        $keyType = $request->input('key_type');
        $keyId = $request->input('key_uuid');

        // make sure request has file
        if (!$request->hasFile('file')) {
            return response()->json(['errors' => ['Oops! Looks like no file was actually uploaded.']], 400);
        }

        // make sure file is valid
        if (!$request->file('file')->isValid()) {
            return response()->json(['errors' => ['Oops! The file you\'ve uploaded is not valid.']], 400);
        }

        $uploadPath = $path ?? 'uploads';
        $fileName = Hashids::encode(strlen($request->file->hashName()), time()) . '.' . $request->file->getClientOriginalExtension();
        $path = $request->file->storePubliclyAs($uploadPath, $fileName, 's3');
        $file = File::createFromUpload($request->file, $path, $type, $size);

        // if we have key_uuid and type
        if ($request->has(['key_uuid', 'key_type'])) {
            $file->update([
                'key_uuid' => $keyId,
                'key_type' => Utils::getMutationType($keyType)
            ]);
        } else if ($keyType) {
            $file->update([
                'key_type' => Utils::getMutationType($keyType)
            ]);
        }

        // done
        return response()->json([
            'file' => $file,
        ]);
    }

    /**
     * Handle file upload of base64
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function uploadBase64(Request $request)
    {
        $data = $request->input('data');
        $path = $request->input('path') ?? 'uploads';
        $fileName = $request->input('fileName');
        $fileType = $request->input('fileType', 'image');
        $contentType = $request->input('contentType');
        $keyId = $request->input('key_uuid');
        $keyType = $request->input('key_type');

        if (!$data) {
            return response()->json(['errors' => ['Oops! Looks like nodata was provided for upload.']], 400);
        }

        // set the bucket path
        $bucketPath = $path . '/' . $fileName;

        // upload file to path
        Storage::disk('s3')->put($bucketPath, base64_decode($data), 'public');

        // create the file model
        // create file record for upload
        $file = File::create([
            'company_uuid' => session('company'),
            'uploader_uuid' => session('user'),
            'key_uuid' => $keyId,
            'key_type' => $keyType ? Utils::getModelClassName($keyType) : NULL,
            'name' => basename($bucketPath),
            'original_filename' => basename($bucketPath),
            'extension' => 'png',
            'content_type' => $contentType ?? 'image/png',
            'path' => $bucketPath,
            'bucket' => config('filesystems.disks.s3.bucket'),
            'type' => $fileType,
            'size' => Utils::getBase64ImageSize($data)
        ]);

        // done
        return response()->json([
            'file' => $file,
        ]);
    }

    /**
     * Handle file uploads
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function download($id)
    {
        $file = File::where('uuid', $id)->first();

        if (!$file) {
            return response()->json(
                [
                    'errors' => ['File not found for download'],
                ],
                400
            );
        }

        // $headers = [
        //     'Content-Type'        => 'Content-Type: application/zip',
        //     'Content-Disposition' => 'attachment; filename="' . $file->name . '"',
        // ];

        return Storage::disk('s3')->download($file->path, $file->name);
    }
}
