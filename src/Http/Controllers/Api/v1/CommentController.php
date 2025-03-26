<?php

namespace Fleetbase\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\CreateCommentRequest;
use Fleetbase\Http\Requests\UpdateCommentRequest;
use Fleetbase\Http\Resources\Comment as CommentResource;
use Fleetbase\Http\Resources\DeletedResource;
use Fleetbase\Models\Comment;
use Fleetbase\Support\Utils;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    /**
     * Creates a new Fleetbase Comment resource.
     *
     * @return CommentResource
     */
    public function create(CreateCommentRequest $request)
    {
        $content = $request->input('content');
        $subject = $request->input('subject', [
            'id'   => $request->input('subject_id'),
            'type' => $request->input('subject_type'),
        ]);
        $parent = $request->input('parent');

        // Prepare comment creation data
        $data = [
            'company_uuid'       => session('company'),
            'author_uuid'        => session('user'),
            'content'            => $content,
        ];

        // Resolve the parent
        $parentComment = null;
        if ($parent) {
            $parentComment = Comment::where(['public_id' => $parent, 'company_uuid' => session('company')])->first();
            if ($parentComment) {
                $data['parent_comment_uuid'] = $parentComment->uuid;
                $data['subject_uuid']        = $parentComment->subject_uuid;
                $data['subject_type']        = $parentComment->subject_type;
            }
        }

        // Resolve the subject
        if ($subject && !$parentComment) {
            $subjectClass = Utils::getMutationType(data_get($subject, 'type'));
            $subjectUuid  = null;
            if ($subjectClass) {
                $subjectUuid = Utils::getUuid(app($subjectClass)->getTable(), [
                    'public_id'          => data_get($subject, 'id'),
                    'company_uuid'       => session('company'),
                ]);
            }

            // If on subject found
            if ((!$subjectClass || !$subjectUuid) && !$parentComment) {
                return response()->apiError('Invalid subject provided for comment.');
            }

            $data['subject_uuid'] =  $subjectUuid;
            $data['subject_type'] =  $subjectClass;
        }

        // create the comment
        try {
            $comment = Comment::publish($data);
        } catch (\Throwable $e) {
            return response()->apiError('Uknown error attempting to create comment.');
        }

        // response the new comment
        return new CommentResource($comment);
    }

    /**
     * Updates a Fleetbase Comment resource.
     *
     * @param string $id
     *
     * @return CommentResource
     */
    public function update($id, UpdateCommentRequest $request)
    {
        // find for the comment
        try {
            $comment = Comment::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Comment resource not found.',
                ],
                404
            );
        }

        try {
            $content = $request->input('content');
            $comment->update(['content' => $content]);
        } catch (\Throwable $e) {
            return response()->apiError('Uknown error attempting to update comment.');
        }

        // response the comment resource
        return new CommentResource($comment);
    }

    /**
     * Query for Fleetbase Comment resources.
     *
     * @return \Fleetbase\Http\Resources\CommentResourceCollection
     */
    public function query(Request $request)
    {
        $results = Comment::queryWithRequest($request);

        return CommentResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Comment resources.
     *
     * @return \Fleetbase\Http\Resources\CommentCollection
     */
    public function find($id)
    {
        // find for the Comment
        try {
            $comment = Comment::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->apiError('Comment resource not found.', 404);
        } catch (\Throwable $e) {
            return response()->apiError('Uknown error occured trying to find the comment.', 404);
        }

        // response the comment resource
        return new CommentResource($comment);
    }

    /**
     * Deletes a Fleetbase Comment resources.
     *
     * @return DeletedResource
     */
    public function delete($id)
    {
        // find for the comment
        try {
            $comment = Comment::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->apiError('Comment resource not found.', 404);
        } catch (\Throwable $e) {
            return response()->apiError('Uknown error occured trying to find the comment.', 404);
        }

        // delete the comment
        $comment->delete();

        // response the comment resource
        return new DeletedResource($comment);
    }
}
