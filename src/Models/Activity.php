<?php

namespace Fleetbase\Models;

use Fleetbase\Support\Utils;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    use HasUuid;
    use HasApiModelBehavior;
    use Filterable;
    use Searchable;
    protected $with              = ['subject', 'causer'];
    protected $appends           = ['humanized_subject_type', 'humanized_causer_type'];
    protected $searchableColumns = ['subject_type', 'description', 'log_name'];

    public function getHumanizedSubjectTypeAttribute(): ?string
    {
        $segments = explode('\\', $this->attributes['subject_type']);
        if (!$segments) {
            return null;
        }

        $name = end($segments);
        $name = Str::snake($name);

        return Utils::humanize($name);
    }

    public function getHumanizedCauserTypeAttribute(): ?string
    {
        $segments = explode('\\', $this->attributes['causer_type']);
        if (!$segments) {
            return null;
        }

        $name = end($segments);
        $name = Str::snake($name);

        return Utils::humanize($name);
    }
}
