<?php

namespace Fleetbase\Support\SocketCluster;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The channel prefixes core owns.
 *
 * Company-wide data is visible to the company's users and API credentials. Driver and
 * customer principals only reach chats they take part in and their own user channel.
 */
class CoreChannelResolvers
{
    public static function register(SocketChannelRegistry $registry): void
    {
        $registry->register('company', [static::class, 'company']);
        $registry->register('api', [static::class, 'api']);
        $registry->register('user', [static::class, 'user']);
        $registry->register('test', [static::class, 'test']);
        $registry->register('install', [static::class, 'install']);
        $registry->register('uninstall', [static::class, 'install']);
        $registry->registerModel('chat', ChatChannel::class, [static::class, 'participatesInChannel']);
        $registry->registerModel('chat_channel', ChatChannel::class, [static::class, 'participatesInChannel']);
        $registry->registerModel('chat_participant', ChatParticipant::class, [static::class, 'isParticipant']);
        $registry->registerModel('chat_message', ChatMessage::class, [static::class, 'participatesInMessage']);
        $registry->registerModel('file', File::class);
    }

    /**
     * `company.{uuid|public_id}`: the principal's own company.
     */
    public static function company(SocketPrincipal $principal, string $id): bool
    {
        if (!$principal->isCompanyScoped() || $principal->cid === null) {
            return false;
        }

        $company = ModelChannelResolver::find(Company::class, $id, $principal);

        return $company !== null && $company->uuid === $principal->cid;
    }

    /**
     * `api.{id}`: an API credential of the principal's company, or the id of a personal access
     * token owned by one of its users.
     */
    public static function api(SocketPrincipal $principal, string $id): bool
    {
        if (!$principal->isCompanyScoped() || $principal->cid === null) {
            return false;
        }

        if (ctype_digit($id)) {
            $token = PersonalAccessToken::on(ModelChannelResolver::connection($principal))->find((int) $id);

            return $token !== null && $token->tokenable instanceof User && static::isMember($principal, $token->tokenable->uuid);
        }

        $credential = ApiCredential::on(ModelChannelResolver::connection($principal))->where('uuid', $id)->first()
            ?? ApiCredential::on($principal->env === 'test' ? null : 'sandbox')->where('uuid', $id)->first();

        return $credential !== null && $credential->company_uuid === $principal->cid;
    }

    /**
     * `user.{uuid|public_id}`: a member of the principal's company. Drivers and customers only
     * reach their own user channel, which the local self rules already allow.
     */
    public static function user(SocketPrincipal $principal, string $id): bool
    {
        if (!$principal->isCompanyScoped() || $principal->cid === null) {
            return false;
        }

        $user = ModelChannelResolver::find(User::class, $id, $principal);

        return $user !== null && static::isMember($principal, $user->uuid);
    }

    /**
     * `test.{user uuid}`: the admin socket test channel, for that user or a system admin.
     */
    public static function test(SocketPrincipal $principal, string $id): bool
    {
        return $principal->adm || $principal->owns($id);
    }

    /**
     * `install.{company uuid}.*` and `uninstall.{company uuid}.*`: extension install progress.
     */
    public static function install(SocketPrincipal $principal, string $id): bool
    {
        return $principal->isCompanyScoped() && $principal->cid !== null && str_starts_with($id, $principal->cid . '.');
    }

    public static function participatesInChannel(SocketPrincipal $principal, ChatChannel $chatChannel): bool
    {
        return static::isChatParticipant($principal, $chatChannel->uuid);
    }

    public static function isParticipant(SocketPrincipal $principal, ChatParticipant $participant): bool
    {
        return $principal->owns((string) $participant->user_uuid);
    }

    public static function participatesInMessage(SocketPrincipal $principal, ChatMessage $message): bool
    {
        return static::isChatParticipant($principal, $message->chat_channel_uuid);
    }

    /**
     * Whether one of the principal's own ids takes part in the chat channel.
     */
    public static function isChatParticipant(SocketPrincipal $principal, ?string $chatChannelUuid): bool
    {
        if (!$chatChannelUuid || $principal->ids === []) {
            return false;
        }

        return ChatParticipant::on(ModelChannelResolver::connection($principal))
            ->where('chat_channel_uuid', $chatChannelUuid)
            ->whereIn('user_uuid', $principal->ids)
            ->exists();
    }

    /**
     * Whether the user belongs to the principal's company.
     */
    public static function isMember(SocketPrincipal $principal, ?string $userUuid): bool
    {
        if (!$userUuid || $principal->cid === null) {
            return false;
        }

        $connection = ModelChannelResolver::connection($principal);

        return CompanyUser::on($connection)->where('user_uuid', $userUuid)->where('company_uuid', $principal->cid)->exists()
            || User::on($connection)->where('uuid', $userUuid)->where('company_uuid', $principal->cid)->exists();
    }
}
