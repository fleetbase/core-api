<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Support\Utils;
use Illuminate\Support\Str;

class ScheduleFilter extends Filter
{
    public function queryForInternal()
    {
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
     * Filter by subject_type — resolves short aliases like 'fleet-ops:driver'
     * to the full PHP class name stored in the database.
     *
     * The frontend sends 'fleet-ops:driver' but the DB stores
     * 'Fleetbase\FleetOps\Models\Driver' (via PolymorphicType cast on write).
     */
    public function subjectType(?string $type)
    {
        if (empty($type)) {
            return;
        }

        // If it already looks like a fully-qualified class name, use as-is
        if (Str::contains($type, '\\')) {
            $this->builder->where('subject_type', $type);

            return;
        }

        // Resolve alias (e.g. 'fleet-ops:driver') to FQCN
        try {
            $resolved = Utils::getMutationType($type);
            $this->builder->where('subject_type', $resolved);
        } catch (\Throwable $e) {
            // Fallback: filter with the raw value so we don't silently skip
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

    /**
     * Filter by status.
     */
    public function status(?string $status)
    {
        if (empty($status)) {
            return;
        }
        $this->builder->where('status', $status);
    }
}
