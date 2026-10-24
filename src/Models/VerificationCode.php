<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Mail\VerificationMail;
use Fleetbase\Services\SmsService;
use Fleetbase\Support\AppReview;
use Fleetbase\Support\Sms\SmsContext;
use Fleetbase\Support\Sms\SmsGuard;
use Fleetbase\Support\Sms\SmsLedger;
use Fleetbase\Support\Sms\SmsReservation;
use Fleetbase\Support\Utils;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasSubject;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Twilio\Support\Laravel\Facade as Twilio;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
    protected $fillable = ['subject_uuid', 'subject_type', 'code', 'for', 'expires_at', 'meta', 'status', 'company_uuid', 'identity', 'channel', 'ip_address', 'user_agent', 'sms_send_uuid', 'consumed_at'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
        'meta'        => Json::class,
    ];

    public const STATUS_USED   = 'used';
    public const STATUS_LOCKED = 'locked';

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
     * Mark a code used so it cannot verify again. The row stays, for the ledger.
     */
    public function consume(): void
    {
        $this->status = self::STATUS_USED;
        if (static::supportsTracingColumns()) {
            $this->consumed_at = Carbon::now();
        }
        $this->setMeta('consumed_at', Carbon::now()->toIso8601String());
        $this->save();
    }

    /**
     * The newest live code filed under an identity for a purpose: not expired (the default
     * scope), not used, not locked. Looks at the `identity` column where the table has it
     * and at `meta->identity` otherwise, so rows written before the column exists resolve.
     */
    public static function findActiveFor(string $for, string $identity, ?string $subjectUuid = null): ?static
    {
        $query = static::query()->where('for', $for);

        if (static::supportsTracingColumns()) {
            $query->where(function ($query) use ($identity) {
                $query->where('identity', $identity)->orWhere('meta->identity', $identity);
            });
        } else {
            $query->where('meta->identity', $identity);
        }

        if ($subjectUuid) {
            $query->where('subject_uuid', $subjectUuid);
        }

        return $query
            ->where(function ($query) {
                $query->whereNull('status')->orWhereNotIn('status', [self::STATUS_USED, self::STATUS_LOCKED]);
            })
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * The newest live code for a subject and purpose.
     */
    public static function findActiveForSubject(string $for, string $subjectUuid): ?static
    {
        return static::query()
            ->where('for', $for)
            ->where('subject_uuid', $subjectUuid)
            ->where(function ($query) {
                $query->whereNull('status')->orWhereNotIn('status', [self::STATUS_USED, self::STATUS_LOCKED]);
            })
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Whether the table has the tracing columns added in October 2026 (company, identity,
     * channel, address, ledger link, consumed_at). An install that has not migrated yet,
     * and a test schema, keep working with `meta` alone. Remembered in the cache for five
     * minutes, so a migration is picked up without a restart.
     */
    public static function supportsTracingColumns(): bool
    {
        $model = new static();
        $check = function () use ($model): bool {
            try {
                return (bool) $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'identity');
            } catch (\Throwable $e) {
                return false;
            }
        };

        try {
            return (bool) Cache::remember('verification_codes:tracing_columns:' . ($model->getConnectionName() ?: 'default'), 300, $check);
        } catch (\Throwable $e) {
            return $check();
        }
    }

    /**
     * What a code row keeps about who asked for it, set only where the table has the columns.
     */
    protected static function tracingAttributes(?string $companyUuid, string $channel, ?string $identity, ?SmsReservation $reservation = null): array
    {
        if (!static::supportsTracingColumns()) {
            return [];
        }

        $request = null;
        try {
            $request = request();
        } catch (\Throwable $e) {
            // no request: a console or queued send
        }

        return [
            'company_uuid'  => $companyUuid ?: null,
            'identity'      => $identity ?: null,
            'channel'       => $channel,
            'ip_address'    => $reservation?->context->ip ?? ($request ? $request->ip() : null),
            'user_agent'    => $reservation?->context->userAgent ?? ($request && $request->userAgent() ? substr((string) $request->userAgent(), 0, 250) : null),
            'sms_send_uuid' => $reservation?->sendUuid,
        ];
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
        $meta                         = (array) data_get($options, 'meta', []);
        $verificationCode             = static::generateFor($subject, $for, false);
        $verificationCode->expires_at = $expireAfter === null ? Carbon::now()->addHour() : $expireAfter;
        $verificationCode->meta       = $meta;
        $verificationCode->status     = data_get($options, 'status', 'active');
        $verificationCode->forceFill(static::tracingAttributes(
            data_get($options, 'company_uuid') ?? session('company') ?? data_get($subject, 'company_uuid'),
            'email',
            (string) (data_get($meta, 'identity') ?? data_get($subject, 'email') ?? '')
        ));
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
     *                        'meta' for additional metadata, 'messageCallback' to customize the SMS message content, and 'phone'
     *                        to text another number than the subject's own (a number the subject is changing to).
     *
     * @return static returns the verification code instance after persisting it to the database and sending the SMS.
     *                Without a number to text (no 'phone' option and none on the subject) the code is saved but not sent.
     *
     * @throws \Fleetbase\Exceptions\SmsBlockedException when the SMS guard refuses the send; no code row is written
     * @throws \Exception                                throws an exception if the SMS cannot be sent
     */
    public static function generateSmsVerificationFor($subject, $for = 'phone_verification', array $options = [])
    {
        $expireAfter = data_get($options, 'expireAfter');
        $destination = data_get($options, 'phone') ?: data_get($subject, 'phone');
        $companyUuid = data_get($options, 'company_uuid') ?? session('company') ?? data_get($subject, 'company_uuid');

        // A number reserved for fiction (an App Review number) is never texted: the code is
        // saved as usual, but nothing is reserved or sent. App reviewers sign in with the code
        // their app's owner set up instead (Fleetbase\Support\AppReview).
        $fictional = $destination && AppReview::isFictionalPhone((string) $destination);

        // Ask the guard BEFORE the code row exists: a refused send must leave nothing
        // behind, and the budgets must be counted even when the provider later fails.
        $reservation = null;
        if ($destination && !$fictional) {
            $reservation = SmsGuard::make()->reserve(SmsContext::resolve((string) $destination, [
                'purpose'      => $for,
                'company_uuid' => $companyUuid,
                'source'       => data_get($options, 'source'),
                'subject'      => $subject,
            ]));
        }

        $meta = (array) data_get($options, 'meta', []);
        if ($reservation && $reservation->isGuarded()) {
            // Who asked for this code and from where, so an incident traces from the table.
            $meta['trace'] = $reservation->trace();
        }

        // With `hashed`, the row keeps an HMAC of the code (as issue() does) and the plain
        // code exists only in the message and on the returned instance's `plainCode`.
        $plainCode = null;
        if (data_get($options, 'hashed')) {
            $plainCode        = (string) random_int(100000, 999999);
            $meta['hashed']   = true;
            $meta['attempts'] = 0;
        }

        $identity                     = (string) (data_get($meta, 'identity') ?? data_get($meta, 'phone') ?? $destination ?? '');
        $verificationCode             = static::generateFor($subject, $for, false);
        $verificationCode->expires_at = $expireAfter === null ? ($plainCode !== null ? Carbon::now()->addMinutes(10) : Carbon::now()->addHour()) : $expireAfter;
        $verificationCode->meta       = $meta;
        $verificationCode->status     = data_get($options, 'status', $plainCode !== null ? 'active' : $verificationCode->status);
        if ($plainCode !== null) {
            $verificationCode->code = static::hashCode($plainCode);
        }
        $verificationCode->forceFill(static::tracingAttributes($companyUuid, 'sms', $identity, $reservation));
        $verificationCode->save();
        $verificationCode->plainCode = $plainCode;

        if ($reservation && $reservation->sendUuid) {
            SmsLedger::update($reservation->sendUuid, ['verification_code_uuid' => $verificationCode->uuid]);
        }

        // Get message. A message callback reads `->code` off the instance it is given; for
        // a hashed code it gets a copy carrying the plain code, never the stored HMAC.
        $forMessage = $verificationCode;
        if ($plainCode !== null) {
            $forMessage       = clone $verificationCode;
            $forMessage->code = $plainCode;
        }

        $message         = 'Your ' . config('app.name') . ' verification code is ' . $forMessage->code;
        $messageCallback = data_get($options, 'messageCallback');
        if (is_callable($messageCallback)) {
            $message = $messageCallback($forMessage);
        }

        // SMS service options, including the reservation so the send is counted once
        $smsOptions = ['sms_reservation' => $reservation];

        // Check for company-specific sender ID
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
        if ($destination && !$fictional) {
            try {
                $smsService = new SmsService();
                $smsService->send($destination, $message, $smsOptions, $provider);
            } catch (\Throwable $e) {
                // Log error but don't fail the verification code generation
                \Illuminate\Support\Facades\Log::error('Failed to send SMS verification', [
                    'phone' => $destination,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        return $verificationCode;
    }
}
