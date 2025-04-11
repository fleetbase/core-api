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
        $participants = $request->array('participants');

        // create the chat channel
        $chatChannel = ChatChannel::create([
            'company_uuid'    => session('company'),
            'created_by_uuid' => session('user'),
            'name'            => $name,
        ]);

        // If participants provided add them
        foreach ($participants as $userId) {
            $user = User::where('public_id', $userId)->first();
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
            $chatChannel = ChatChannel::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat channel resource not found.',
                ],
                404
            );
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

        return ChatChannelResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Chat Channel resources.
     *
     * @return \Fleetbase\Http\Resources\ChatChannelCollection
     */
    public function find($id)
    {
        // find for the chat channel
        try {
            $chatChannel = ChatChannel::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat channel resource not found.',
                ],
                404
            );
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
            $chatChannel = ChatChannel::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat channel resource not found.',
                ],
                404
            );
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
        $chatChannel   = $chatChannelId ? ChatChannel::where('public_id', $chatChannelId)->first() : null;
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
            $chatChannel = ChatChannel::findRecordOrFail($id);
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
            $chatChannel = ChatChannel::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Chat channel resource not found.',
                ],
                404
            );
        }

        // Find for the chat participant sender
        try {
            $sender = ChatParticipant::findRecordOrFail($senderId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Sender of chat message not found.',
                ],
                422
            );
        }

        // Get the message contents and attachment file id
        $content          = $request->input('content');
        $attachments      = $request->array('files');

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

        // Load models
        $chatMessage     = ChatMessage::where('public_id', $chatMessageId)->first();
        $chatParticipant = ChatParticipant::where('public_id', $chatParticipantId)->first();

        // Guard clauses for invalid references
        if (!$chatMessage || !$chatParticipant) {
            return response()->apiError('Invalid message or participant reference.', 404);
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
}
