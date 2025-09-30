<?php

namespace Fleetbase\Support\Reporting\Contracts;

use Fleetbase\Support\Reporting\ReportSchemaRegistry;

interface ReportSchema
{
    /**
     * Register tables and columns for report generation.
     */
    public function registerReportSchema(ReportSchemaRegistry $registry): void;
}
