<?php

namespace Fleetbase\Models;

use Aloha\Twilio\Support\Laravel\Facade as Twilio;
use Fleetbase\Casts\Json;
use Fleetbase\Mail\VerifyEmail;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasSubject;
use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class VerificationCode extends Model
{
    use HasUuid;
    use Expirable;
    use HasSubject;
    use HasMetaAttributes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'verification_codes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['subject_uuid', 'subject_type', 'code', 'for', 'expires_at', 'meta', 'status'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'meta'       => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /** on boot generate code */
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->code = mt_rand(100000, 999999);
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /** static method to generate for a subject on the fly */
    public static function generateFor($subject = null, $for = 'general_verification', $save = true)
    {
        $verifyCode      = new static();
        $verifyCode->for = $for;

        if ($subject) {
            $verifyCode->setSubject($subject, false);
        }

        if ($save) {
            $verifyCode->save();
        }

        return $verifyCode;
    }

    /** static method to generate code for email verification */
    public static function generateEmailVerificationFor($subject, $for = 'email_verification', \Closure $messageCallback = null, $meta = [])
    {
        $verifyCode             = static::generateFor($subject, $for, false);
        $verifyCode->expires_at = Carbon::now()->addHour();
        $verifyCode->meta       = $meta;
        $verifyCode->save();

        $emailSubject = $messageCallback ? $messageCallback($verifyCode) : null;

        if (isset($subject->email)) {
            Mail::to($subject)->send(new VerifyEmail($verifyCode, $emailSubject, $subject));
        }

        return $verifyCode;
    }

    /** static method to generate code for phone verification */
    public static function generateSmsVerificationFor($subject, $for = 'phone_verification', \Closure $messageCallback = null, $meta = [])
    {
        $verifyCode             = static::generateFor($subject, $for, false);
        $verifyCode->expires_at = Carbon::now()->addHour();
        $verifyCode->meta       = $meta;
        $verifyCode->save();

        if ($subject->phone) {
            Twilio::message($subject->phone, $messageCallback ? $messageCallback($verifyCode) : "Your Fleetbase verification code is {$verifyCode->code}");
        }

        return $verifyCode;
    }
}
