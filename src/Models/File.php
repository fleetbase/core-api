<?php

namespace Fleetbase\Models;

use Fleetbase\Support\Utils;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\SlugOptions;
use Spatie\Sluggable\HasSlug;
use Mimey\MimeTypes;
use Vinkla\Hashids\Facades\Hashids;

class File extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, HasSlug, LogsActivity, SendsWebhooks;

    /**
     * The type of public Id to generate
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
    protected $fillable = ['public_id', 'company_uuid', 'uploader_uuid', 'subject_uuid', 'subject_type', 'path', 'bucket', 'folder', 'etag', 'original_filename', 'type', 'content_type', 'file_size', 'slug', 'caption'];

    /**
     * Dynamic attributes that are appended to object
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
     * Properties which activity needs to be logged
     *
     * @var array
     */
    protected static $logAttributes = '*';

    /**
     * Do not log empty changed
     *
     * @var boolean
     */
    protected static $submitEmptyLogs = false;

    /**
     * The name of the subject to log
     *
     * @var string
     */
    protected static $logName = 'file';

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
     * Get the File url attribute.
     *
     * @return string
     */
    public function getUrlAttribute()
    {
        $disk = env('FILESYSTEM_DRIVER');
        /** @var \Illuminate\Support\Facades\Storage $filesystem */
        $filesystem = Storage::disk($disk);
        $url = $filesystem->url($this->path);

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
     * Sets the uploader of the file.
     *
     * @return \Fleetbase\Models\File
     */
    public function setUploader(User $uploader): File
    {
        $this->uploader_uuid = $uploader->uuid;
        return $this;
    }

    /**
     * Generate the file url attribute.
     *
     * @return string
     */
    public function getMimeType($extension = null)
    {
        return static::getFileMimeType($extension ?? $this->extension);
    }

    public static function getExtensionFromMimeType($mimeType)
    {
        $mimes = new MimeTypes();
        $extension = $mimes->getExtension($mimeType);

        if (!$extension) {
            $extension = explode('/', $mimeType)[1];
        }

        return $extension;
    }

    public static function getMimeTypeFromExtension($extension)
    {
        $mimes = new MimeTypes();

        return $mimes->getMimeType($extension);
    }

    /**
     * Generate the file url attribute
     *
     * @var string
     */
    public static function getFileMimeType($extension)
    {
        return static::getMimeTypeFromExtension($extension);
    }

    /**
     * Create a new file from uploaded file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return \Fleetbase\Models\File
     */
    public static function createFromUpload(UploadedFile $file, $path, $type = null, $size = null)
    {
        $extension = $file->getClientOriginalExtension();

        $data = [
            'company_uuid' => session('company'),
            'uploader_uuid' => session('user'),
            'original_filename' => $file->getClientOriginalName(),
            'content_type' => static::getFileMimeType($extension),
            'path' => $path,
            'bucket' => config('filesystems.disks.s3.bucket'),
            'type' => $type,
            'file_size' => $size ?? $file->getSize(),
        ];

        return static::create($data);
    }

    public function getHashNameAttribute()
    {
        return basename($this->path);
    }

    /**
     * Assosciates the file to another model
     *
     * @return \Fleetbase\Models\File
     */
    public function setKey($model, $type = null): File
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
     * Set the file type
     *
     * @return \Fleetbase\Models\File
     */
    public function setType($type = null): File
    {
        $this->type = $type;
        $this->save();

        return $this;
    }

    public static function randomFileName(?string $extension = 'png')
    {
        $extension = Str::startsWith($extension, '.') ? $extension : '.' . $extension;

        return uniqid() . strtolower($extension);
    }

    public static function randomFileNameFromRequest(Request $request, ?string $extension = 'png')
    {
        /** @var \Illuminate\Http\File|Symfony\Component\HttpFoundation\File\File $file */
        $file = $request->file;

        if ($request->hasFile('file')) {
            $extension = strtolower($file->getClientOriginalExtension());
            $extension = Str::startsWith($extension, '.') ? $extension : '.' . $extension;

            return Hashids::encode(strlen($file->hashName()), time()) . $extension;
        }

        return static::randomFileName($extension);
    }
}
