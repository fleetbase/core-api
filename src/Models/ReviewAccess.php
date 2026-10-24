<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Facades\Hash;

/**
 * App Review access for one subject (a Storefront store, an organization's Navigator app,
 * ...): whether app store reviewers may sign in to it with its type's fixed review number
 * and the code its owner generated. See Fleetbase\Support\AppReview. The code is only ever
 * stored hashed.
 *
 * @property string                          $uuid
 * @property string|null                     $company_uuid
 * @property string                          $subject_type      the type registered with AppReview, e.g. `storefront-store`
 * @property string                          $subject_uuid
 * @property bool                            $enabled
 * @property string|null                     $code
 * @property \Illuminate\Support\Carbon|null $code_generated_at
 * @property \Illuminate\Support\Carbon|null $enabled_at
 * @property string|null                     $updated_by_uuid
 */
class ReviewAccess extends Model
{
    use HasUuid;

    protected $table = 'review_accesses';

    protected $fillable = ['company_uuid', 'subject_type', 'subject_uuid', 'enabled', 'code', 'code_generated_at', 'enabled_at', 'updated_by_uuid'];

    protected $hidden = ['code'];

    protected $casts = [
        'enabled'           => 'boolean',
        'code_generated_at' => 'datetime',
        'enabled_at'        => 'datetime',
    ];

    /**
     * Turned on and holding a code: the only state in which a reviewer can sign in.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->hasCode();
    }

    public function hasCode(): bool
    {
        return filled($this->code);
    }

    /**
     * Replace the code with the given one, stored hashed.
     */
    public function setPlainCode(string $code): void
    {
        $this->code              = Hash::make($code);
        $this->code_generated_at = now();
    }

    public function checkCode(string $code): bool
    {
        return $this->hasCode() && $code !== '' && Hash::check($code, $this->code);
    }
}
