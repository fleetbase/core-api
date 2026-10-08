<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Mail\VerificationMail;
use Fleetbase\Services\SmsService;
use Fleetbase\Support\Utils;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasSubject;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Twilio\Support\Laravel\Facade as Twilio;
use Illuminate\Support\Arr;
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

    /**
     * Outcomes of {@see check()}.
     */
    public const CHECK_VALID   = 'valid';
    public const CHECK_INVALID = 'invalid';
    public const CHECK_EXPIRED = 'expired';
    public const CHECK_LOCKED  = 'locked';

    /**
     * The plain code of a code made by {@see issue()}. It lives on this instance only, so the
     * caller can send it once; the database keeps an HMAC of it.
     */
    public ?string $plainCode = null;

    /** on boot generate code, unless one was set already (a hashed code from {@see issue()}) */
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (blank($model->code)) {
                $model->code = random_int(100000, 999999);
            }
        });
    }

    /**
     * Issue a code that is stored hashed, for flows where a leaked table must not give away
     * live codes. The plain code is on the returned instance's `plainCode`; sending it is up
     * to the caller.
     *
     * Options: `expireAfter` (default 10 minutes from now), `meta` (merged into the code's meta)
     * and `status` (default 'active').
     *
     * @param mixed $subject the model the code is for, or null
     */
    public static function issue($subject, string $for, array $options = []): static
    {
        $plainCode = (string) random_int(100000, 999999);

        $verifyCode             = new static();
        $verifyCode->for        = $for;
        $verifyCode->status     = data_get($options, 'status', 'active');
        $verifyCode->expires_at = data_get($options, 'expireAfter', Carbon::now()->addMinutes(10));
        $verifyCode->code       = static::hashCode($plainCode);
        $verifyCode->meta       = array_merge((array) data_get($options, 'meta', []), ['hashed' => true, 'attempts' => 0]);

        if ($subject) {
            $verifyCode->setSubject($subject, false);
        }

        $verifyCode->save();
        $verifyCode->plainCode = $plainCode;

        return $verifyCode;
    }

    /**
     * The HMAC a hashed code is stored as, keyed by the app key.
     */
    public static function hashCode(string $plainCode): string
    {
        return hash_hmac('sha256', $plainCode, (string) config('app.key', ''));
    }

    /**
     * Check a plain code against this one. A wrong code counts an attempt, and the code locks
     * itself on the last allowed attempt, so it can't be guessed further.
     *
     * Read the code without the expiry scope to tell an expired code apart: the scope hides
     * expired rows from queries.
     */
    public function check(string $plainCode, int $maxAttempts = 3): string
    {
        if ($this->status === 'locked') {
            return self::CHECK_LOCKED;
        }

        if ($this->hasExpired()) {
            return self::CHECK_EXPIRED;
        }

        $plainCode = trim($plainCode);
        $expected  = $this->getMeta('hashed') === true ? static::hashCode($plainCode) : $plainCode;
        if (hash_equals((string) $this->code, $expected)) {
            return self::CHECK_VALID;
        }

        $attempts = (int) $this->getMeta('attempts', 0) + 1;
        $this->setMeta('attempts', $attempts);
        if ($attempts >= $maxAttempts) {
            $this->status = 'locked';
        }
        $this->save();

        return $this->status === 'locked' ? self::CHECK_LOCKED : self::CHECK_INVALID;
    }

    /**
     * How many wrong codes {@see check()} still allows.
     */
    public function attemptsLeft(int $maxAttempts = 3): int
    {
        return max(0, $maxAttempts - (int) $this->getMeta('attempts', 0));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * Generates a verification code object for the specified subject and context.
     * This method can optionally save the generated verification code to the database.
     *
     * @param mixed  $subject The subject for which the verification code is generated. Could be a User model or similar.
     * @param string $for     The context or purpose for generating the verification code. Default is 'general_verification'.
     * @param bool   $save    Determines whether to persist the generated verification code to the database. Default is true.
     *
     * @return static returns an instance of the verification code with optional subject and purpose set
     */
    public static function generateFor($subject = null, $for = 'general_verification', $save = true)
    {
        $verifyCode         = new static();
        $verifyCode->for    = $for;
        $verifyCode->status = 'pending';  // Set default status

        if ($subject) {
            $verifyCode->setSubject($subject, false);
        }

        if ($save) {
            $verifyCode->save();
        }

        return $verifyCode;
    }

    /**
     * Generates and sends an email verification code for a specified subject. This method configures and sends an email
     * containing the verification code.
     *
     * @param mixed  $subject the subject (typically a User model) to whom the verification email will be sent
     * @param string $for     Context or purpose of the verification. Default is 'email_verification'.
     * @param array  $options Options to customize the verification process. Can include 'expireAfter' to set a custom expiration,
     *                        'meta' for additional metadata, 'subject' for the email subject, and 'content' for the email content.
     *
     * @return static returns the verification code instance after persisting it to the database and sending the email
     *
     * @throws \Exception throws an exception if the email cannot be sent
     */
    public static function generateEmailVerificationFor($subject, $for = 'email_verification', array $options = [])
    {
        $expireAfter                  = data_get($options, 'expireAfter');
        $verificationCode             = static::generateFor($subject, $for, false);
        $verificationCode->expires_at = $expireAfter === null ? Carbon::now()->addHour() : $expireAfter;
        $verificationCode->meta       = data_get($options, 'meta', []);
        $verificationCode->status     = data_get($options, 'status', 'active');
        $verificationCode->save();

        if (isset($subject->email)) {
            // See if subject option passed is callable
            $mailableSubject = data_get($options, 'subject');
            if (is_callable($mailableSubject)) {
                $options['subject'] = $mailableSubject($verificationCode);
            }

            // See if content or messageCallback passed
            $content = Utils::or($options, ['content', 'messageCallback']);
            if (is_callable($content)) {
                $content = $content($verificationCode);
            }

            // Initialize the mailable definition
            $mail = new VerificationMail($verificationCode, $content);

            // Apply any additional Mail facade parameters
            $mailer = Mail::to(data_get($options, 'to', $subject));
            foreach (Arr::except($options, ['content', 'expireAfter', 'messageCallback', 'meta', 'status', 'subject', 'to']) as $key => $value) {
                if (method_exists($mailer, $key)) {
                    $mailer->$key($value);
                }
            }

            $mailer->send($mail);
        }

        return $verificationCode;
    }

    /**
     * Generates and sends an SMS verification code for a specified subject. This method handles the creation and dispatch
     * of an SMS containing the verification code.
     *
     * @param mixed  $subject the subject (typically a User model) to whom the SMS will be sent
     * @param string $for     Context or purpose of the verification. Default is 'phone_verification'.
     * @param array  $options Options to customize the verification process. Can include 'expireAfter' to set a custom expiration,
     *                        'meta' for additional metadata, and 'messageCallback' to customize the SMS message content.
     *
     * @return static returns the verification code instance after persisting it to the database and sending the SMS
     *
     * @throws \Exception throws an exception if the SMS cannot be sent
     */
    public static function generateSmsVerificationFor($subject, $for = 'phone_verification', array $options = [])
    {
        $expireAfter                  = data_get($options, 'expireAfter');
        $verificationCode             = static::generateFor($subject, $for, false);
        $verificationCode->expires_at = $expireAfter === null ? Carbon::now()->addHour() : $expireAfter;
        $verificationCode->meta       = data_get($options, 'meta', []);
        $verificationCode->save();

        // Get message
        $message         = 'Your ' . config('app.name') . ' verification code is ' . $verificationCode->code;
        $messageCallback = data_get($options, 'messageCallback');
        if (is_callable($messageCallback)) {
            $message = $messageCallback($verificationCode);
        }

        // SMS service options
        $smsOptions = [];

        // Check for company-specific sender ID
        $companyUuid = data_get($options, 'company_uuid') ?? session('company') ?? data_get($subject, 'company_uuid');
        if ($companyUuid) {
            $company = Company::select(['uuid', 'options'])->find($companyUuid);

            if ($company) {
                $enabled  = Utils::castBoolean($company->getOption('alpha_numeric_sender_id_enabled'));
                $senderId = $company->getOption('alpha_numeric_sender_id');

                if ($enabled && !empty($senderId)) {
                    // Alphanumeric sender IDs are Twilio-specific
                    // Do NOT set in $smsOptions['from'] as it would be passed to all providers
                    $smsOptions['twilioParams']['from'] = $senderId;
                }
            }
        }

        // Allow explicit provider selection
        $provider = data_get($options, 'provider');

        // Send SMS using SmsService with automatic provider routing
        if ($subject->phone) {
            try {
                $smsService = new SmsService();
                $smsService->send($subject->phone, $message, $smsOptions, $provider);
            } catch (\Throwable $e) {
                // Log error but don't fail the verification code generation
                \Illuminate\Support\Facades\Log::error('Failed to send SMS verification', [
                    'phone' => $subject->phone,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        return $verificationCode;
    }
}
