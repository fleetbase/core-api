<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Models\Schedule;
use Fleetbase\Support\Utils;
use Illuminate\Support\Str;

class ScheduleExceptionFilter extends Filter
{
    public function queryForInternal()
    {
        // Scope to the authenticated company — schedule_exceptions has company_uuid directly
        $companyUuid = $this->session->get('company');
        if ($companyUuid) {
            $this->builder->where('company_uuid', $companyUuid);
        }
    }

    public function queryForPublic()
    {
        $this->queryForInternal();
    }

    /**
     * Filter by schedule_uuid — accepts either a raw UUID or a public_id.
     *
     * The frontend sends `this.schedule.id` which is the public_id
     * (e.g. 'schedule_fpQgvKtGVx'). We resolve it to the internal UUID here.
     */
    public function scheduleUuid(?string $id)
    {
        if (empty($id)) {
            return;
        }

        if (Str::isUuid($id)) {
            $this->builder->where('schedule_uuid', $id);
        } else {
            // Resolve public_id to uuid via a subquery
            $uuid = Schedule::where('public_id', $id)->value('uuid');
            if ($uuid) {
                $this->builder->where('schedule_uuid', $uuid);
            } else {
                // No matching schedule — return empty result set
                $this->builder->whereRaw('1 = 0');
            }
        }
    }

    /**
     * Filter by subject_type — resolves short aliases like 'fleet-ops:driver'
     * to the full PHP class name stored in the database.
     */
    public function subjectType(?string $type)
    {
        if (empty($type)) {
            return;
        }

        if (Str::contains($type, '\\')) {
            $this->builder->where('subject_type', $type);

            return;
        }

        try {
            $resolved = Utils::getMutationType($type);
            $this->builder->where('subject_type', $resolved);
        } catch (\Throwable $e) {
            $this->builder->where('subject_type', $type);
        }
    }

    /**
     * Filter by subject_uuid.
     */
    public function subjectUuid(?string $uuid)
    {
        if (empty($uuid)) {
            return;
        }
        $this->builder->where('subject_uuid', $uuid);
    }
}
