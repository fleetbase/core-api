<?php

use Carbon\Carbon;
use Fleetbase\Auth\OAuth\Drivers\GoogleDriver;
use Fleetbase\Events\OAuthIdentityLinked;
use Fleetbase\Events\OAuthIdentityUnlinked;
use Fleetbase\Listeners\SendOAuthIdentityLinkedNotification;
use Fleetbase\Listeners\SendOAuthIdentityUnlinkedNotification;
use Fleetbase\Models\OAuthIdentity;
use Fleetbase\Models\User;
use Fleetbase\Notifications\OAuthProviderLinked;
use Fleetbase\Notifications\OAuthProviderUnlinked;
use Fleetbase\Services\OAuth\OAuthConfigRepository;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Records what would have been sent instead of sending it.
 */
class OAuthNotificationDispatcherFake implements Dispatcher
{
    /** @var array<int, array{0: mixed, 1: object}> */
    public array $sent = [];

    public bool $fail = false;

    public function send($notifiables, $notification)
    {
        if ($this->fail) {
            throw new RuntimeException('mail server down');
        }

        $this->sent[] = [$notifiables, $notification];
    }

    public function sendNow($notifiables, $notification, ?array $channels = null)
    {
        $this->send($notifiables, $notification);
    }
}

function oauth_notifications_setup(): OAuthNotificationDispatcherFake
{
    bind_test_container([
        'app.name'                 => 'Fleetbase',
        'app.env'                  => 'production',
        'fleetbase.console.host'   => 'console.fleetbase.test',
        'fleetbase.console.secure' => true,
        'oauth.providers'          => ['google' => ['driver' => GoogleDriver::class]],
    ]);

    app()->instance(OAuthConfigRepository::class, new OAuthConfigRepository());
    $dispatcher = new OAuthNotificationDispatcherFake();
    app()->instance(Dispatcher::class, $dispatcher);

    return $dispatcher;
}

function oauth_notifications_user(?string $email = 'ada@example.com'): User
{
    return (new User())->forceFill(['uuid' => 'user-1', 'name' => 'Ada', 'email' => $email]);
}

function oauth_notifications_identity(): OAuthIdentity
{
    return (new OAuthIdentity())->forceFill(['provider' => 'google', 'provider_email' => 'ada@gmail.com']);
}

/**
 * Everything a reader sees, in order.
 */
function oauth_mail_text(MailMessage $mail): string
{
    return implode("\n", array_merge([$mail->subject, $mail->greeting], $mail->introLines, [$mail->actionText, $mail->actionUrl], $mail->outroLines));
}

it('emails the account holder when they link a provider', function () {
    $dispatcher = oauth_notifications_setup();
    $user       = oauth_notifications_user();

    (new SendOAuthIdentityLinkedNotification())->handle(new OAuthIdentityLinked($user, oauth_notifications_identity(), OAuthIdentityLinked::METHOD_MANUAL));

    expect($dispatcher->sent)->toHaveCount(1)
        ->and($dispatcher->sent[0][0])->toBe($user)
        ->and($dispatcher->sent[0][1])->toBeInstanceOf(OAuthProviderLinked::class);
});

it('emails the account holder when a provider is linked automatically', function () {
    $dispatcher = oauth_notifications_setup();

    (new SendOAuthIdentityLinkedNotification())->handle(new OAuthIdentityLinked(oauth_notifications_user(), oauth_notifications_identity(), OAuthIdentityLinked::METHOD_AUTOMATIC));

    expect($dispatcher->sent[0][1]->method)->toBe(OAuthIdentityLinked::METHOD_AUTOMATIC);
});

it('does not email about the provider someone just signed up with', function () {
    $dispatcher = oauth_notifications_setup();

    (new SendOAuthIdentityLinkedNotification())->handle(new OAuthIdentityLinked(oauth_notifications_user(), oauth_notifications_identity(), OAuthIdentityLinked::METHOD_SIGNUP));

    expect($dispatcher->sent)->toBe([]);
});

it('emails the account holder when a provider is removed', function () {
    $dispatcher = oauth_notifications_setup();

    (new SendOAuthIdentityUnlinkedNotification())->handle(new OAuthIdentityUnlinked(oauth_notifications_user(), 'google'));

    expect($dispatcher->sent)->toHaveCount(1)
        ->and($dispatcher->sent[0][1])->toBeInstanceOf(OAuthProviderUnlinked::class)
        ->and($dispatcher->sent[0][1]->provider)->toBe('google');
});

it('skips an account with no email address', function () {
    $dispatcher = oauth_notifications_setup();

    (new SendOAuthIdentityLinkedNotification())->handle(new OAuthIdentityLinked(oauth_notifications_user(null), oauth_notifications_identity()));
    (new SendOAuthIdentityUnlinkedNotification())->handle(new OAuthIdentityUnlinked(oauth_notifications_user(null), 'google'));

    expect($dispatcher->sent)->toBe([]);
});

it('never lets a mail failure fail the link or the removal', function () {
    $dispatcher       = oauth_notifications_setup();
    $dispatcher->fail = true;

    (new SendOAuthIdentityLinkedNotification())->handle(new OAuthIdentityLinked(oauth_notifications_user(), oauth_notifications_identity()));
    (new SendOAuthIdentityUnlinkedNotification())->handle(new OAuthIdentityUnlinked(oauth_notifications_user(), 'google'));

    expect(true)->toBeTrue();
});

it('says which provider and account were linked, and what to do if it was not you', function () {
    oauth_notifications_setup();
    Carbon::setTestNow('2026-09-21 14:30:00');

    $text = oauth_mail_text((new OAuthProviderLinked('google', 'ada@gmail.com'))->toMail(oauth_notifications_user()));

    Carbon::setTestNow();

    expect($text)->toContain('Google was linked to your Fleetbase account')
        ->toContain('Hello, Ada')
        ->toContain('Google account (ada@gmail.com)')
        ->toContain('Mon, Sep 21, 2026 2:30 PM UTC')
        ->toContain('Review sign-in methods')
        ->toContain('https://console.fleetbase.test/account/auth')
        ->toContain('remove Google from your sign-in methods and change your password');
});

it('explains an automatic link', function () {
    oauth_notifications_setup();

    $text = oauth_mail_text((new OAuthProviderLinked('google', 'ada@gmail.com', OAuthIdentityLinked::METHOD_AUTOMATIC))->toMail(oauth_notifications_user()));

    expect($text)->toContain('You signed in to Fleetbase with your Google account (ada@gmail.com)')
        ->toContain('same verified email address');
});

it('says which provider was removed, and what to do if it was not you', function () {
    oauth_notifications_setup();

    $text = oauth_mail_text((new OAuthProviderUnlinked('google'))->toMail(oauth_notifications_user()));

    expect($text)->toContain('Google was removed from your Fleetbase account')
        ->toContain('can no longer sign in with Google')
        ->toContain('change your password');
});

it('names a provider that is no longer configured by its id', function () {
    oauth_notifications_setup();

    $text = oauth_mail_text((new OAuthProviderUnlinked('okta'))->toMail(oauth_notifications_user()));

    expect($text)->toContain('Okta was removed');
});

it('is registered for both events', function () {
    // Read from source: the framework's base provider is not installed for unit tests.
    $source = (string) file_get_contents(__DIR__ . '/../../../src/Providers/EventServiceProvider.php');

    expect($source)->toMatch('/Events\\\\OAuthIdentityLinked::class\s*=>\s*\[\\\\Fleetbase\\\\Listeners\\\\SendOAuthIdentityLinkedNotification::class\]/')
        ->and($source)->toMatch('/Events\\\\OAuthIdentityUnlinked::class\s*=>\s*\[\\\\Fleetbase\\\\Listeners\\\\SendOAuthIdentityUnlinkedNotification::class\]/');
});

it('sends both notices by mail only', function () {
    oauth_notifications_setup();
    $user = oauth_notifications_user();

    expect((new OAuthProviderLinked('google', 'ada@gmail.com'))->via($user))->toBe(['mail'])
        ->and((new OAuthProviderUnlinked('google'))->via($user))->toBe(['mail']);
});

it('records what was linked and removed, and when, in the array form', function () {
    oauth_notifications_setup();
    Carbon::setTestNow('2026-09-21 14:30:00');

    $linked   = (new OAuthProviderLinked('google', 'ada@gmail.com', OAuthIdentityLinked::METHOD_AUTOMATIC))->toArray(oauth_notifications_user());
    $unlinked = (new OAuthProviderUnlinked('google'))->toArray(oauth_notifications_user());

    Carbon::setTestNow();

    expect($linked)->toBe([
        'provider'       => 'google',
        'provider_email' => 'ada@gmail.com',
        'method'         => OAuthIdentityLinked::METHOD_AUTOMATIC,
        'linked_at'      => 'Mon, Sep 21, 2026 2:30 PM UTC',
    ])->and($unlinked)->toBe([
        'provider'    => 'google',
        'unlinked_at' => 'Mon, Sep 21, 2026 2:30 PM UTC',
    ]);
});
