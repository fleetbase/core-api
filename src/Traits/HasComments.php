<?php

namespace Fleetbase\Traits;

use Fleetbase\Models\Comment;

trait HasComments
{
    /**
     * Get all comments for this record (top-level only, no replies).
     */
    public function comments()
    {
        return $this->hasMany(Comment::class, 'subject_uuid')
                    ->whereNull('parent_comment_uuid')
                    ->latest();
    }

    /**
     * Get top-level comments with author and nested replies eager-loaded.
     */
    public function topLevelComments()
    {
        return $this->comments()->with('replies.author', 'author');
    }
}
