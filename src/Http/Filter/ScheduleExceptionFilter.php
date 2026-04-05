<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Models\Schedule;
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
}
