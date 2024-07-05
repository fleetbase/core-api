<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Support\Utils;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mimey\MimeTypes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class File extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasSlug;
    use LogsActivity;
    use SendsWebhooks;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'file';

    /**
     * The database connection to use.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'files';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['public_id', 'company_uuid', 'uploader_uuid', 'subject_uuid', 'subject_type', 'disk', 'path', 'bucket', 'folder', 'meta', 'etag', 'original_filename', 'type', 'content_type', 'file_size', 'slug', 'caption'];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['url', 'hash_name'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'         => Json::class,
        'subject_type' => PolymorphicType::class,
    ];

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('original_filename')
            ->saveSlugsTo('slug');
    }

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['subject_uuid', 'bucket', 'disk', 'path'])->logOnlyDirty();
    }

    /**
     * Get the disk name for the file.
     *
     * @return string the disk name
     */
    public function getDisk(): string
    {
        $disk = $this->disk;

        if (!$disk) {
            $disk = config('filesystems.default', env('FILESYSTEM_DRIVER'));
        }

        return $disk;
    }

    /**
     * Get the filesystem instance for the specified disk.
     *
     * @param string|null $disk The disk name. If null, the default disk will be used.
     *
     * @return \Illuminate\Contracts\Filesystem\Filesystem the filesystem instance
     */
    public function getFilesystem(?string $disk = null): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $disk = $disk ? $disk : $this->getDisk();

        return Storage::disk($disk);
    }

    /**
     * Get the URL for the file.
     *
     * If the file is stored on S3, a temporary signed URL will be generated.
     * If the file is stored locally, the asset URL will be returned.
     *
     * @return string the URL for the file
     */
    public function getUrlAttribute()
    {
        $disk = $this->getDisk();
        /** @var $filesystem \Illuminate\Support\Facades\Storage */
        $filesystem = $this->getFilesystem();

        if ($disk === 's3' || $disk === 'gcs') {
            $url = $filesystem->temporaryUrl($this->path, now()->addMinutes(30));
        } else {
            $url = $filesystem->url($this->path);
        }

        if ($disk === 'local') {
            return asset($url, !app()->environment(['development', 'local']));
        }

        return $url;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function uploader()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sets the uploader for the file using a User object.
     *
     * @param User $uploader the user object that represents the uploader
     *
     * @return File returns this instance to allow for method chaining
     */
    public function setUploader(User $uploader): File
    {
        $this->uploader_uuid = $uploader->uuid;

        return $this;
    }

    /**
     * Retrieves the MIME type of the file based on its extension.
     *
     * @param string|null $extension optional extension to determine the MIME type; defaults to the instance's extension
     *
     * @return string the MIME type of the file
     */
    public function getMimeType($extension = null)
    {
        return static::getFileMimeType($extension ?? $this->extension);
    }

    /**
     * Determines the file extension based on a given MIME type.
     *
     * @param string $mimeType the MIME type for which to find the corresponding file extension
     *
     * @return string the file extension associated with the given MIME type
     */
    public static function getExtensionFromMimeType($mimeType)
    {
        $mimes     = new MimeTypes();
        $extension = $mimes->getExtension($mimeType);

        if (!$extension) {
            $extension = explode('/', $mimeType)[1];
        }

        return $extension;
    }

    /**
     * Retrieves the MIME type associated with a specific file extension.
     *
     * @param string $extension the file extension for which to find the MIME type
     *
     * @return string the MIME type corresponding to the given file extension
     */
    public static function getMimeTypeFromExtension($extension)
    {
        $mimes = new MimeTypes();

        return $mimes->getMimeType($extension);
    }

    /**
     * Retrieves the MIME type for a given file extension.
     *
     * @param string $extension the file extension to look up
     *
     * @return string the MIME type for the given extension
     */
    public static function getFileMimeType($extension)
    {
        return static::getMimeTypeFromExtension($extension);
    }

    /**
     * Creates a new file instance from an uploaded file.
     *
     * @param UploadedFile $file   the uploaded file object
     * @param string       $path   the path where the file is to be stored
     * @param string|null  $type   optional type of the file
     * @param int|null     $size   optional size of the file, defaults to the size from the file object
     * @param string|null  $disk   optional disk where the file is to be stored, defaults to the default filesystem disk
     * @param string|null  $bucket optional bucket on the disk, defaults to the default configuration
     *
     * @return File|null a new file instance or null on failure
     */
    public static function createFromUpload(UploadedFile $file, $path, $type = null, $size = null, $disk = null, $bucket = null): ?File
    {
        $extension = $file->getClientOriginalExtension();

        if (is_null($disk)) {
            $disk = config('filesystems.default');
        }

        if (is_null($bucket)) {
            $bucket = config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket'));
        }

        $data = [
            'company_uuid'      => session('company'),
            'uploader_uuid'     => session('user'),
            'original_filename' => $file->getClientOriginalName(),
            'content_type'      => static::getFileMimeType($extension),
            'disk'              => $disk,
            'path'              => $path,
            'bucket'            => $bucket,
            'type'              => $type,
            'file_size'         => $size ?? $file->getSize(),
        ];

        return static::create($data);
    }

    /**
     * Retrieves the hash name of the file based on its path.
     *
     * @return string the basename of the file path
     */
    public function getHashNameAttribute()
    {
        return basename($this->path);
    }

    /**
     * Sets the subject and type of the file.
     *
     * @param mixed       $model the model associated with the file
     * @param string|null $type  optional type of the file
     *
     * @return File returns this instance to allow for method chaining
     */
    public function setSubject($model, $type = null): File
    {
        $this->subject_uuid = data_get($model, 'uuid');
        $this->subject_type = Utils::getMutationType($model);

        if (is_string($type)) {
            $this->type = $type;
        }

        $this->save();

        return $this;
    }

    /**
     * Sets the key for the file by setting the subject.
     *
     * @param mixed       $model the model to associate with the file
     * @param string|null $type  optional type for the file
     *
     * @return File returns this instance to allow for method chaining
     */
    public function setKey($model, $type = null): File
    {
        return $this->setSubject($model, $type);
    }

    /**
     * Sets the subject's UUID and type from an HTTP request. This method supports updates from different
     * types of subject inputs, either 'subject_uuid' or 'subject_id' along with 'subject_type'.
     * Updates are based on the presence of specific request parameters.
     *
     * @param Request $request the HTTP request object containing the subject data
     *
     * @return File returns this instance to allow for method chaining after updating the subject's UUID and type
     */
    public function setSubjectFromRequest($request): File
    {
        $type = $request->input('subject_type');
        if ($request->has(['subject_uuid', 'subject_type'])) {
            $id = $request->input('subject_uuid');
            $this->update(
                [
                    'subject_uuid' => $id,
                    'subject_type' => Utils::getMutationType($type),
                ]
            );
        }

        if ($request->has(['subject_id', 'subject_type'])) {
            $id = $request->input('subject_id');
            $this->update(
                [
                    'subject_uuid' => Utils::getUuid($type, [
                        'public_id'    => $id,
                        'company_uuid' => session('company'),
                    ]),
                    'subject_type' => Utils::getMutationType($type),
                ]
            );
        }

        if ($request->has(['subject_type']) && $request->missing(['subject_uuid', 'subject_id'])) {
            $this->update(
                [
                    'subject_type' => Utils::getMutationType($type),
                ]
            );
        }

        return $this;
    }

    /**
     * Sets the type of the file.
     *
     * @param string|null $type the type of the file
     *
     * @return File returns this instance to allow for method chaining
     */
    public function setType($type = null): File
    {
        $this->type = $type;
        $this->save();

        return $this;
    }

    /**
     * Generates a random file name with a specified extension.
     *
     * @param string|null $extension the desired file extension; defaults to 'png'
     *
     * @return string the generated random file name with the specified extension
     */
    public static function randomFileName(?string $extension = 'png')
    {
        $extension = Str::startsWith($extension, '.') ? $extension : '.' . $extension;

        return uniqid() . strtolower($extension);
    }

    /**
     * Generates a random file name based on a request, with an optional specified extension.
     *
     * @param Request     $request   the request containing the file
     * @param string|null $extension the desired file extension; defaults to 'png'
     *
     * @return string the generated random file name
     */
    public static function randomFileNameFromRequest($request, ?string $extension = 'png')
    {
        /** @var Request|\Fleetbase\Http\Requests\Internal\UploadFileRequest $request */
        /** @var \Illuminate\Http\File|Symfony\Component\HttpFoundation\File\File|\Symfony\Component\HttpFoundation\File\UploadedFile $file */
        $file = $request->file;

        if ($request->hasFile('file')) {
            $extension = strtolower($file->getClientOriginalExtension());
            $extension = Str::startsWith($extension, '.') ? $extension : '.' . $extension;
            $sqids     = new \Sqids\Sqids();

            return $sqids->encode([strlen($file->hashName()), time()]) . $extension;
        }

        return static::randomFileName($extension);
    }

    /**
     * Retrieves a collection of files based on UUIDs provided via a request.
     *
     * This method extracts an array of UUIDs from the request and retrieves
     * corresponding file models. It is static, allowing it to be called on the class itself
     * without needing an instance of the class.
     *
     * @param Request $request the HTTP request containing the 'files' array with UUIDs
     * @param string  $param   the Param which should hold the array of files
     *
     * @return Collection a collection of File models that match the provided UUIDs
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException if no model is found
     */
    public static function fromRequest(Request $request, string $param = 'files'): Collection
    {
        $ids = $request->array($param);

        return static::whereIn('uuid', $ids)->get();
    }

    /**
     * Retrieves the contents of the file from the specified disk and path.
     *
     * This method utilizes the Laravel Storage facade to access the filesystem configured
     * for the model's specified disk and retrieves the file located at the model's path.
     * Error handling is included to manage cases where the disk or path might not be set,
     * or if the file does not exist on the disk.
     *
     * @return string|null the file contents as a string if found, or null if the file does not exist
     *
     * @throws \InvalidArgumentException                              if the disk or path property is not set
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException if the file does not exist
     */
    public function getContents(): ?string
    {
        if (!isset($this->disk) || !isset($this->path)) {
            throw new \InvalidArgumentException('Disk or path is not specified.');
        }

        return Storage::disk($this->disk)->get($this->path);
    }
}
