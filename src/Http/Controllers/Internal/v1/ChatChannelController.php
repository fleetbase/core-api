<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Resources\User as UserResource;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

class ChatChannelController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'chat_channel';

    /**
     * Creates a chat channel and optional initial participants.
     *
     * @return \Fleetbase\Http\Resources\ChatChannel|\Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        try {
            $name         = $request->input('chatChannel.name');
            $meta         = $request->input('chatChannel.meta', []);
            $participants = $request->array('chatChannel.participants');

            $chatChannel = ChatChannel::create([
                'company_uuid'    => session('company'),
                'created_by_uuid' => session('user'),
                'name'            => $name,
                'meta'            => $meta,
            ]);

            foreach ($participants as $userId) {
                $user = User::where('uuid', $userId)->orWhere('public_id', $userId)->first();

                if (!$user || $user->uuid === session('user')) {
                    continue;
                }

                ChatParticipant::firstOrCreate([
                    'company_uuid'      => session('company'),
                    'user_uuid'         => $user->uuid,
                    'chat_channel_uuid' => $chatChannel->uuid,
                ]);
            }

            $chatChannel->refresh();
            $chatChannel->load(['participants.user', 'lastMessage']);
            $this->resource::wrap($this->resourceSingularlName);

            return new $this->resource($chatChannel);
        } catch (\Exception $e) {
            return response()->error(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Unable to create chat channel.');
        }
    }

    /**
     * Query users available for a new or existing chat channel.
     *
     * @return \Fleetbase\Http\Resources\UserCollection
     */
    public function getAvailableParticipants(Request $request)
    {
        $query         = $request->input('query');
        $chatChannelId = $request->input('channel');
        $chatChannel   = $chatChannelId ? ChatChannel::where('uuid', $chatChannelId)->orWhere('public_id', $chatChannelId)->first() : null;

        $users = User::whereHas('companyUsers', function ($query) {
            $query->where('company_uuid', session('company'));
        })
            ->where('uuid', '!=', session('user'))
            ->when($query, function ($builder) use ($query) {
                $builder->search($query);
            });

        if ($chatChannel) {
            $participantUserUuids = $chatChannel->participants()->pluck('user_uuid');
            $users->whereNotIn('uuid', $participantUserUuids);
        }

        return UserResource::collection($users->limit(25)->get());
    }

    /**
     * Retrieves the unread message count for a specific chat channel.
     *
     * This method fetches a chat channel by its UUID and calculates the unread messages
     * for the authenticated user. It returns a JSON response with the count or an error
     * message if the chat channel is not found.
     *
     * @param string  $channelId the UUID of the chat channel
     * @param Request $request   the incoming request instance, containing the user information
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUnreadCountForChannel(string $channelId, Request $request)
    {
        $chatChannel = ChatChannel::where('uuid', $channelId)->first();
        if (!$chatChannel) {
            return response()->json(['error' => 'Chat channel not found.'], 404);
        }

        $unreadCount = $chatChannel->getUnreadMessageCountForUser($request->user());

        return response()->json(['unreadCount' => $unreadCount]);
    }

    /**
     * Retrieves the total unread message count across all chat channels for the current user.
     *
     * This method aggregates the unread messages for all channels in which the current user is a participant.
     * It returns the total unread count as a JSON response.
     *
     * @param Request $request the incoming request instance
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUnreadCount(Request $request)
    {
        $unreadCount  = 0;
        $userUuid     = $request->user()->uuid;
        $chatChannels = ChatChannel::whereHas('participants', function ($query) use ($userUuid) {
            $query->where('user_uuid', $userUuid);
        })->get();

        foreach ($chatChannels as $chatChannel) {
            $unreadCount += $chatChannel->getUnreadMessageCountForUser($request->user());
        }

        return response()->json(['unreadCount' => $unreadCount]);
    }
}
