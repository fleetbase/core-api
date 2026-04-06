<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Models\Schedule;
use Fleetbase\Support\Utils;
use Illuminate\Support\Str;

class ScheduleItemFilter extends Filter
{
    public function queryForInternal()
    {
        // Scope to the authenticated company.
        // Prefer the direct company_uuid column (populated since 2026_04_06 migration);
        // fall back to the schedule join for older rows that pre-date the column.
        $companyUuid = $this->session->get('company');
        if ($companyUuid) {
            $this->builder->where(function ($q) use ($companyUuid) {
                $q->where('company_uuid', $companyUuid)
                  ->orWhereHas('schedule', function ($sq) use ($companyUuid) {
                      $sq->where('company_uuid', $companyUuid);
                  });
            });
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
     * Filter by assignee_type — resolves short aliases like 'fleet-ops:driver'
     * to the full PHP class name stored in the database.
     */
    public function assigneeType(?string $type)
    {
        if (empty($type)) {
            return;
        }

        if (Str::contains($type, '\\')) {
            $this->builder->where('assignee_type', $type);
            return;
        }

        try {
            $resolved = Utils::getMutationType($type);
            $this->builder->where('assignee_type', $resolved);
        } catch (\Throwable $e) {
            $this->builder->where('assignee_type', $type);
        }
    }

    /**
     * Filter by assignee_uuid.
     */
    public function assigneeUuid(?string $uuid)
    {
        if (empty($uuid)) {
            return;
        }
        $this->builder->where('assignee_uuid', $uuid);
    }

    /**
     * Range filter: start_at_gte / start_at_lte
     * Called automatically by the base Filter range engine as startAtBetween($gte, $lte).
     */
    public function startAtBetween(?string $gte, ?string $lte)
    {
        if ($gte) {
            $this->builder->where('start_at', '>=', $gte);
        }
        if ($lte) {
            $this->builder->where('start_at', '<=', $lte);
        }
    }

    /**
     * Range filter: end_at_gte / end_at_lte
     * Called automatically by the base Filter range engine as endAtBetween($gte, $lte).
     */
    public function endAtBetween(?string $gte, ?string $lte)
    {
        if ($gte) {
            $this->builder->where('end_at', '>=', $gte);
        }
        if ($lte) {
            $this->builder->where('end_at', '<=', $lte);
        }
    }
}
