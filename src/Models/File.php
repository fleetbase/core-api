<?php

namespace Fleetbase\Models;

use Fleetbase\Support\Utils;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\SlugOptions;
use Spatie\Sluggable\HasSlug;
use Mimey\MimeTypes;

class File extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, HasSlug, LogsActivity;

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
    protected $fillable = ['public_id', 'company_uuid', 'uploader_uuid', 'key_uuid', 'key_type', 'path', 'bucket', 'folder', 'etag', 'original_filename', 'type', 'content_type', 'file_size', 'slug', 'caption'];

    /**
     * Dynamic attributes that are appended to object
     *
     * @var array
     */
    protected $appends = ['s3url', 'hash_name'];

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
     * get the s3 url
     *
     * @return string
     */
    public function gets3urlAttribute()
    {
        return 'https://' . $this->bucket . '.s3.amazonaws.com/' . $this->path;
    }

    /**
     * The uploader of this file
     *
     * @var Model
     */
    public function uploader()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determines if authenticated user is the owner of the company
     *
     * @return boolean
     */
    public function getIsUploaderAttribute()
    {
        return session('user') === $this->uploader_uuid;
    }

    /**
     * Sets the owner of the company
     *
     * @return $this
     */
    public function setUploader(User $uploader)
    {
        $this->uploader_uuid = $uploader->uuid;
        return $this;
    }

    /**
     * Generate the file url attribute
     *
     * @var string
     */
    public function getUrlAttribute()
    {
        return url($this->path);
    }

    /**
     * Generate the file url attribute
     *
     * @var string
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
     * @param Illuminate\Http\UploadedFile $file
     * @return \Fleetbase\Models\File
     */
    public static function createFromUpload(UploadedFile $file, $path, $type = null, $size = null)
    {
        $extension = $file->getClientOriginalExtension();

        $data = [
            'company_uuid' => session('company'),
            'uploader_uuid' => session('user'),
            'original_filename' => $file->getClientOriginalName(),
            // 'extension' => $extension,
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
     * @void
     */
    public function setKey($model, $type = null)
    {
        $this->key_uuid = data_get($model, 'uuid');
        $this->key_type = Utils::getMutationType($model);

        if (is_string($type)) {
            $this->type = $type;
        }

        $this->save();

        return $this;
    }

    /**
     * Set the file type
     *
     * @void
     */
    public function setType($type = null)
    {
        $this->type = $type;
        $this->save();

        return $this;
    }

    public static function randomFileName(string $extension)
    {
        $extension = Str::startsWith($extension, '.') ? $extension : '.' . $extension;

        return uniqid() . $extension;
    }
}
