<?php

namespace Fleetbase\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\CreateChatChannelRequest;
use Fleetbase\Http\Requests\UpdateChatChannelRequest;
use Fleetbase\Http\Resources\ChatChannel as ChatChannelResource;
use Fleetbase\Http\Resources\ChatMessage as ChatMessageResource;
use Fleetbase\Http\Resources\ChatParticipant as ChatParticipantResource;
use Fleetbase\Http\Resources\ChatReceipt as ChatReceiptResource;
use Fleetbase\Http\Resources\DeletedResource;
use Fleetbase\Http\Resources\User as UserResource;
use Fleetbase\Models\ChatAttachment;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\ChatReceipt;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ChatChannelController extends Controller
{
    /**
     * Creates a new Fleetbase Chat Channel resource.
     *
     * @return ChatChannelResource
     */
    public function create(CreateChatChannelRequest $request)
    {
        // get request input
        $name         = $request->input('name');
        $participants = $request->inputArray('participants');

        // create the chat channel
        $chatChannel = ChatChannel::create([
            'company_uuid'    => session('company'),
            'created_by_uuid' => session('user'),
            'name'            => $name,
        ]);

        // If participants provided add them
        foreach ($participants as $userId) {
            // Only people of this organisation: a public id from another one must not pull
            // its user into the channel, where they would be sent every message.
            $user = User::where('public_id', $userId)
                ->where(function ($query) {
                    $query->where('company_uuid', session('company'))->orWhereHas('companyUsers', function ($query) {
                        $query->where('company_uuid', session('company'));
                    });
                })
                ->first();
            if ($user) {
                ChatParticipant::create([
                    'company_uuid'      => session('company'),
                    'user_uuid'         => $user->uuid,
                    'chat_channel_uuid' => $chatChannel->uuid,
                ]);
            }
        }

        // response the driver resource
        return new ChatChannelResource($chatChannel);
    }

    /**
     * Updates a Fleetbase Chat Channel resource.
     *
     * @param string $id
     *
     * @return ChatChannelResource
     */
    public function update($id, UpdateChatChannelRequest $request)
    {
        // find for the chat channel
        try {
            $chatChannel = $this->findChannelForCaller($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat channel resource not found.',
                ],
                404
            );
        }

        // Renaming is for whoever created the channel
        if (!$this->callerCreatedChannel($chatChannel)) {
            return response()->apiError('Only the creator of this chat channel can change it.', 403);
        }

        // get request input
        $input = $request->only(['name']);

        // update the chat channel
        $chatChannel->update($input);

        // response the chat channel resource
        return new ChatChannelResource($chatChannel);
    }

    /**
     * Query for Fleetbase Chat Channel resources.
     *
     * @return \Fleetbase\Http\Resources\ChatChannelCollection
     */
    public function query(Request $request)
    {
        $results = ChatChannel::queryWithRequest($request);

        // Each channel carries only its latest entries; the rest is fetched per channel with
        // `GET chat-channels/{id}`, which says so through `feed_truncated`.
        foreach ($results as $chatChannel) {
            $chatChannel->limitFeed(ChatChannel::LIST_FEED_LIMIT);
        }

        return ChatChannelResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Chat Channel resources.
     *
     * The whole feed comes back unless the request pages it: `limit` keeps the most recent
     * entries (up to ChatChannel::MAX_FEED_LIMIT), and `before` (a chat message id or a time)
     * keeps only what is older, for loading earlier history a page at a time.
     *
     * @return \Fleetbase\Http\Resources\ChatChannelCollection
     */
    public function find($id, Request $request)
    {
        // find for the chat channel
        try {
            $chatChannel = $this->findChannelForCaller($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat channel resource not found.',
                ],
                404
            );
        }

        // Page the feed when asked to
        if ($request->filled('limit') || $request->filled('before')) {
            $before = null;
            if ($request->filled('before')) {
                $before = $this->feedCursor($chatChannel, (string) $request->input('before'));
                if (!$before) {
                    return response()->apiError('The `before` value must be a message id from this chat channel or a valid time.', 422);
                }
            }

            $chatChannel->limitFeed((int) $request->input('limit', ChatChannel::LIST_FEED_LIMIT), $before);
        }

        // response the chat channel resource
        return new ChatChannelResource($chatChannel);
    }

    /**
     * Deletes a Fleetbase Chat Channel resources.
     *
     * @return \Fleetbase\Http\Resources\ChatChannelCollection
     */
    public function delete($id)
    {
        // find for the chat channel
        try {
            $chatChannel = $this->findChannelForCaller($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat channel resource not found.',
                ],
                404
            );
        }

        // Deleting is for whoever created the channel
        if (!$this->callerCreatedChannel($chatChannel)) {
            return response()->apiError('Only the creator of this chat channel can delete it.', 403);
        }

        // delete the chat channel
        $chatChannel->delete();

        // response the chat channel resource
        return new DeletedResource($chatChannel);
    }

    /**
     * Query for available chat participants.
     *
     * @return \Fleetbase\Http\Resources\ChatChannelCollection
     */
    public function getAvailablePartificants(Request $request)
    {
        $chatChannelId = $request->input('channel');
        $chatChannel   = null;
        if ($chatChannelId) {
            // A channel the caller cannot see filters nothing, the same as no channel
            try {
                $chatChannel = $this->findChannelForCaller($chatChannelId);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                $chatChannel = null;
            }
        }
        $users         = User::where('company_uuid', session('company'))->get();

        if ($chatChannel) {
            $users = $users->filter(function ($user) use ($chatChannel) {
                $isParticipant = $chatChannel->participants->firstWhere('user_uuid', $user->uuid);

                return !$isParticipant;
            });
        }

        return UserResource::collection($users);
    }

    /**
     * Adds a new participant to a chat channel.
     *
     * @return ChatParticipantResource
     */
    public function addParticipant($id, Request $request)
    {
        $participantUserId = $request->input('user');

        // find for the chat channel
        try {
            $chatChannel = $this->findChannelForCaller($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat channel resource not found.',
                ],
                404
            );
        }

        // Find the user from the public id
        try {
            $participantUser = User::findRecordOrFail($participantUserId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'User to add as participant not found.',
                ],
                422
            );
        }

        // Create the new chat participant
        $chatParticipant = ChatParticipant::create([
            'company_uuid'      => session('company'),
            'user_uuid'         => $participantUser->uuid,
            'chat_channel_uuid' => $chatChannel->uuid,
        ]);

        return new ChatParticipantResource($chatParticipant);
    }

    /**
     * Removes a participant from a chat channel.
     *
     * @return DeletedResource
     */
    public function removeParticipant($participantId)
    {
        // Find the chat participant from the public id
        try {
            $chatParticipant = ChatParticipant::findRecordOrFail($participantId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat participant resource not found.',
                ],
                422
            );
        }

        $callerUserUuid = $this->callerUserUuid();
        if ($callerUserUuid !== null) {
            // A participant of a channel the caller is not in is answered as not found
            $chatChannel = $this->callerParticipatesIn($chatParticipant->chat_channel_uuid)
                ? ChatChannel::where('uuid', $chatParticipant->chat_channel_uuid)->first()
                : null;
            if (!$chatChannel) {
                return response()->json(
                    [
                        'error' => 'Chat participant resource not found.',
                    ],
                    422
                );
            }

            // Anyone may leave; removing someone else is for whoever created the channel
            if ($chatParticipant->user_uuid !== $callerUserUuid && $chatChannel->created_by_uuid !== $callerUserUuid) {
                return response()->apiError('Only the creator of this chat channel can remove other participants.', 403);
            }
        }

        // Delete the chat participant
        $chatParticipant->delete();

        return new DeletedResource($chatParticipant);
    }

    /**
     * Sends a message in a chat channel.
     *
     * @return ChatMessageResource
     */
    public function sendMessage($id, Request $request)
    {
        $senderId = $request->input('sender');

        // Find for the chat channel
        try {
            $chatChannel = $this->findChannelForCaller($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat channel resource not found.',
                ],
                404
            );
        }

        // An order's chat ends with the order: once closed it can be read but not written to
        if (data_get($chatChannel, 'meta.closed_at')) {
            return response()->apiError('This conversation has ended.', 422);
        }

        // Find for the chat participant sender, who has to be in this channel
        try {
            $sender = ChatParticipant::findRecordOrFail($senderId, [], ['*'], function ($query) use ($chatChannel) {
                $query->where('chat_channel_uuid', $chatChannel->uuid);
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Sender of chat message not found.',
                ],
                422
            );
        }

        // A person can only speak as themselves
        $callerUserUuid = $this->callerUserUuid();
        if ($callerUserUuid !== null && $sender->user_uuid !== $callerUserUuid) {
            return response()->apiError('You can only send messages as yourself.', 403);
        }

        // Get the message contents and attachment file id
        $content          = $request->input('content');
        $attachments      = $request->inputArray('files');

        // Create chat message
        $chatMessage = ChatMessage::create([
            'company_uuid'      => session('company'),
            'chat_channel_uuid' => $chatChannel->uuid,
            'sender_uuid'       => $sender->uuid,
            'content'           => $content,
        ]);

        // If has attachment create chat attachment
        if ($attachments) {
            foreach ($attachments as $attachmentFileId) {
                // Find for the file sent as attachment
                try {
                    $file = File::findRecordOrFail($attachmentFileId);
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                    // Delete message as it failed to send
                    $chatMessage->delete();

                    return response()->apiError('Attachment file not found.');
                }

                $chatAttachment = ChatAttachment::create([
                    'company_uuid'      => session('company'),
                    'chat_channel_uuid' => $chatChannel->uuid,
                    'chat_message_uuid' => $chatMessage->uuid,
                    'sender_uuid'       => $sender->uuid,
                    'file_uuid'         => $file->uuid,
                ]);
                $chatMessage->attachments->push($chatAttachment);
            }
        }

        // Notify participants
        $chatMessage->notifyParticipants();

        return new ChatMessageResource($chatMessage);
    }

    /**
     * Deletes a message from a chat channel.
     *
     * @param string $chatMessageId The chat message being read
     *
     * @return DeletedResource
     */
    public function deleteMessage($chatMessageId)
    {
        // Find the chat message from the public id
        try {
            $chatMessage = ChatMessage::findRecordOrFail($chatMessageId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->apiError('Chat message resource not found.', 404);
        }

        $callerUserUuid = $this->callerUserUuid();
        if ($callerUserUuid !== null) {
            // A message in a channel the caller is not in is answered as not found
            if (!$this->callerParticipatesIn($chatMessage->chat_channel_uuid)) {
                return response()->apiError('Chat message resource not found.', 404);
            }

            // Only the person who wrote a message can delete it
            if (data_get($chatMessage, 'sender.user_uuid') !== $callerUserUuid) {
                return response()->apiError('Only the author of this message can delete it.', 403);
            }
        }

        // Delete the chat message
        $chatMessage->delete();

        return new DeletedResource($chatMessage);
    }

    /**
     * Create a read receipt for a given chat message and participant.
     *
     * This method checks if a read receipt already exists for the specified message and participant
     * within the current company context. If it does not exist, it creates a new read receipt.
     *
     * @param string  $chatMessageId The chat message being read
     * @param Request $request       the HTTP request instance containing `participant` public ID
     *
     * @return \Illuminate\Http\Response|\App\Http\Resources\ChatReceiptResource
     */
    public function createReadReceipt($chatMessageId, Request $request)
    {
        $chatParticipantId = $request->input('participant');
        $companyUuid       = session('company');

        // Load models, from this organisation only
        $chatMessage     = ChatMessage::where('public_id', $chatMessageId)->where('company_uuid', $companyUuid)->first();
        $chatParticipant = ChatParticipant::where('public_id', $chatParticipantId)->where('company_uuid', $companyUuid)->first();

        // Guard clauses for invalid references
        if (!$chatMessage || !$chatParticipant) {
            return response()->apiError('Invalid message or participant reference.', 404);
        }

        // A message in a channel the caller is not in is answered as not found, and so is a
        // reader who is not in the message's channel
        $callerUserUuid = $this->callerUserUuid();
        if (($callerUserUuid !== null && !$this->callerParticipatesIn($chatMessage->chat_channel_uuid)) || $chatParticipant->chat_channel_uuid !== $chatMessage->chat_channel_uuid) {
            return response()->apiError('Invalid message or participant reference.', 404);
        }

        // A person can only mark messages read for themselves
        if ($callerUserUuid !== null && $chatParticipant->user_uuid !== $callerUserUuid) {
            return response()->apiError('You can only mark messages as read for yourself.', 403);
        }

        // Check for existing receipt
        $existingChatReceipt = ChatReceipt::where('company_uuid', $companyUuid)
            ->where('chat_message_uuid', $chatMessage->uuid)
            ->where('participant_uuid', $chatParticipant->uuid)
            ->first();

        if ($existingChatReceipt) {
            return new ChatReceiptResource($existingChatReceipt);
        }

        // Create new receipt
        try {
            $chatReceipt = ChatReceipt::create([
                'company_uuid'       => $companyUuid,
                'chat_message_uuid'  => $chatMessage->uuid,
                'participant_uuid'   => $chatParticipant->uuid,
            ]);

            return new ChatReceiptResource($chatReceipt);
        } catch (\Exception $e) {
            $message = app()->hasDebugModeEnabled() ? $e->getMessage() : 'Unable to create read receipt.';

            return response()->apiError($message);
        }
    }

    /**
     * The user making the request with their own token, or null when it was made with the
     * organisation's API key.
     *
     * Both kinds of request have a session user (a key runs as the user who created it), so
     * the token is what tells them apart: an organisation key is the one credential whose key
     * or secret is the bearer token itself. A personal token, such as the one a driver or
     * customer signs in with, never is, and so is held to that person's own channels. A
     * request that cannot be shown to come from an organisation key is treated as a person's.
     */
    protected function callerUserUuid(): ?string
    {
        $token = request()->bearerToken();
        if (is_string($token) && $token !== '') {
            foreach ([session('api_key'), session('api_secret')] as $credential) {
                if (is_string($credential) && $credential !== '' && hash_equals($credential, $token)) {
                    return null;
                }
            }
        }

        return (string) session('user');
    }

    /**
     * Whether the calling user is a participant of the channel. Always true for a request
     * made with the organisation's API key.
     */
    protected function callerParticipatesIn(?string $chatChannelUuid): bool
    {
        $callerUserUuid = $this->callerUserUuid();
        if ($callerUserUuid === null) {
            return true;
        }

        return ChatParticipant::where(['chat_channel_uuid' => $chatChannelUuid, 'user_uuid' => $callerUserUuid])->exists();
    }

    /**
     * Whether the caller may rename or delete the channel: the user who created it, or the
     * organisation's API key.
     */
    protected function callerCreatedChannel(ChatChannel $chatChannel): bool
    {
        $callerUserUuid = $this->callerUserUuid();

        return $callerUserUuid === null || $chatChannel->created_by_uuid === $callerUserUuid;
    }

    /**
     * Finds a chat channel of this organisation by its public id, as the caller sees it: a
     * user only finds the channels they participate in, so every other channel is not found.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    protected function findChannelForCaller($id): ChatChannel
    {
        $callerUserUuid = $this->callerUserUuid();

        return ChatChannel::findRecordOrFail($id, [], ['*'], function ($query) use ($callerUserUuid) {
            if ($callerUserUuid !== null) {
                $query->whereHas('participants', function ($query) use ($callerUserUuid) {
                    $query->where('user_uuid', $callerUserUuid);
                });
            }
        });
    }

    /**
     * The time a `before` value points at: when the message with that id in this channel was
     * sent, or the time itself. Null when it is neither.
     */
    protected function feedCursor(ChatChannel $chatChannel, string $before): ?Carbon
    {
        $chatMessage = ChatMessage::where(['public_id' => $before, 'chat_channel_uuid' => $chatChannel->uuid])->first();
        if ($chatMessage) {
            return $chatMessage->created_at;
        }

        try {
            // Stored timestamps are in the application's timezone
            return Carbon::parse($before)->setTimezone(date_default_timezone_get());
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
