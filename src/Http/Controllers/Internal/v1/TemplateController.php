<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\Internal\CreateTemplateRequest;
use Fleetbase\Models\Template;
use Fleetbase\Models\TemplateQuery;
use Fleetbase\Services\TemplateRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class TemplateController extends FleetbaseController
{
    /**
     * The resource to query.
     */
    public $resource = 'template';

    /**
     * The validation request to use for create/update.
     */
    public $request = CreateTemplateRequest::class;

    /**
     * The TemplateRenderService instance.
     */
    protected TemplateRenderService $renderService;

    public function __construct(TemplateRenderService $renderService)
    {
        parent::__construct();
        $this->renderService = $renderService;
    }

    // -------------------------------------------------------------------------
    // Lifecycle hooks — called automatically by HasApiControllerBehavior
    // -------------------------------------------------------------------------

    /**
     * Called by HasApiControllerBehavior::createRecord() after the template
     * record has been persisted. Syncs the nested queries array included in
     * the request payload.
     *
     * Signature expected by getControllerCallback(): ($request, $record, $input)
     *
     * @param Request  $request
     * @param Template $record
     * @param array    $input
     */
    public function onAfterCreate(Request $request, Template $record, array $input): void
    {
        $this->_syncQueries($record, $request->input('queries', []));
        $record->load('queries');
    }

    /**
     * Called by HasApiControllerBehavior::updateRecord() after the template
     * record has been updated. Syncs the nested queries array included in
     * the request payload.
     *
     * Signature expected by getControllerCallback(): ($request, $record, $input)
     *
     * @param Request  $request
     * @param Template $record
     * @param array    $input
     */
    public function onAfterUpdate(Request $request, Template $record, array $input): void
    {
        $this->_syncQueries($record, $request->input('queries', []));
        $record->load('queries');
    }

    // -------------------------------------------------------------------------
    // Custom endpoints
    // -------------------------------------------------------------------------

    /**
     * Render an unsaved template payload to HTML for preview.
     *
     * POST /templates/preview  (no {id} — template has not been persisted yet)
     *
     * Body:
     *   template (object) — the full template payload from the builder
     *     .name, .content, .context_type, .width, .height, .unit,
     *     .orientation, .margins, .styles, .queries (array, optional)
     *   subject_type (string, optional) — fully-qualified model class
     *   subject_id   (string, optional) — UUID or public_id of the subject record
     */
    public function previewUnsaved(Request $request): JsonResponse
    {
        $payload = $request->input('template', []);

        // Hydrate a transient (non-persisted) Template model from the payload.
        // fill() respects $fillable so unknown keys are silently ignored.
        $template = new Template();
        $template->fill([
            'name'         => data_get($payload, 'name', 'Preview'),
            'content'      => data_get($payload, 'content', []),
            'context_type' => data_get($payload, 'context_type', 'generic'),
            'width'        => data_get($payload, 'width'),
            'height'       => data_get($payload, 'height'),
            'unit'         => data_get($payload, 'unit', 'mm'),
            'orientation'  => data_get($payload, 'orientation', 'portrait'),
            'margins'      => data_get($payload, 'margins', []),
            'styles'       => data_get($payload, 'styles', []),
        ]);

        // Hydrate transient TemplateQuery objects so the render pipeline can
        // execute them without any DB records existing yet.
        $rawQueries = data_get($payload, 'queries', []);
        $queryModels = collect($rawQueries)->map(function ($q) {
            $tq = new TemplateQuery();
            $tq->fill([
                'label'         => data_get($q, 'label'),
                'variable_name' => data_get($q, 'variable_name'),
                'model_type'    => data_get($q, 'model_type'),
                'conditions'    => data_get($q, 'conditions', []),
                'sort'          => data_get($q, 'sort', []),
                'limit'         => data_get($q, 'limit'),
                'with'          => data_get($q, 'with', []),
            ]);

            return $tq;
        });

        // Set the queries relation directly so buildContext() can iterate them
        // without calling loadMissing() against the database.
        $template->setRelation('queries', $queryModels);

        $subjectType = $request->input('subject_type');
        $subjectId   = $request->input('subject_id');
        $subject     = null;

        if ($subjectType && $subjectId && class_exists($subjectType)) {
            $subject = $subjectType::where('uuid', $subjectId)
                ->orWhere('public_id', $subjectId)
                ->first();
        }

        $html = $this->renderService->renderToHtml($template, $subject);

        return response()->json(['html' => $html]);
    }

    /**
     * Render a template to HTML for preview.
     *
     * POST /templates/{id}/preview
     *
     * Body:
     *   subject_type (string, optional) — fully-qualified model class
     *   subject_id   (string, optional) — UUID or public_id of the subject record
     */
    public function preview(string $id, Request $request): JsonResponse
    {
        $template = Template::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $subjectType = $request->input('subject_type');
        $subjectId   = $request->input('subject_id');
        $subject     = null;

        if ($subjectType && $subjectId && class_exists($subjectType)) {
            $subject = $subjectType::where('uuid', $subjectId)
                ->orWhere('public_id', $subjectId)
                ->first();
        }

        $html = $this->renderService->renderToHtml($template, $subject);

        return response()->json(['html' => $html]);
    }

    /**
     * Render a template to a PDF and stream it as a download.
     *
     * POST /templates/{id}/render
     *
     * Body:
     *   subject_type (string, optional)
     *   subject_id   (string, optional)
     *   filename     (string, optional) — defaults to template name
     */
    public function render(string $id, Request $request): Response
    {
        $template = Template::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $subjectType = $request->input('subject_type');
        $subjectId   = $request->input('subject_id');
        $filename    = $request->input('filename', $template->name);
        $subject     = null;

        if ($subjectType && $subjectId && class_exists($subjectType)) {
            $subject = $subjectType::where('uuid', $subjectId)
                ->orWhere('public_id', $subjectId)
                ->first();
        }

        $pdf = $this->renderService->renderToPdf($template, $subject);

        return $pdf->download($filename . '.pdf');
    }

    /**
     * Return the available context types and their variable schemas.
     * Used by the frontend variable picker.
     *
     * GET /templates/context-schemas
     */
    public function contextSchemas(): JsonResponse
    {
        $schemas = $this->renderService->getContextSchemas();

        return response()->json(['schemas' => $schemas]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Upsert the nested queries array onto the given template.
     *
     * Each item in $queries may have:
     *   - uuid (string|null)  — present for existing queries, absent for new ones
     *   - All other TemplateQuery fillable fields
     *
     * Strategy:
     *   1. Collect the UUIDs present in the incoming payload.
     *   2. Soft-delete any existing queries NOT in that set (removed by the user).
     *   3. For each incoming query: update if UUID exists, create if not.
     *
     * @param Template $template
     * @param array    $queries
     */
    protected function _syncQueries(Template $template, array $queries): void
    {
        if (empty($queries) && !is_array($queries)) {
            return;
        }

        $companyUuid   = session('company');
        $createdByUuid = session('user');
        $incomingUuids = [];

        foreach ($queries as $queryData) {
            $uuid = data_get($queryData, 'uuid');

            // Skip client-side temporary IDs (prefixed with _new_ or _unsaved_)
            if ($uuid && (Str::startsWith($uuid, '_new_') || Str::startsWith($uuid, '_unsaved_'))) {
                $uuid = null;
            }

            if ($uuid) {
                // Update existing query
                $existing = TemplateQuery::where('uuid', $uuid)
                    ->where('template_uuid', $template->uuid)
                    ->first();

                if ($existing) {
                    $existing->fill([
                        'label'         => data_get($queryData, 'label'),
                        'variable_name' => data_get($queryData, 'variable_name'),
                        'description'   => data_get($queryData, 'description'),
                        'model_type'    => data_get($queryData, 'model_type'),
                        'conditions'    => data_get($queryData, 'conditions', []),
                        'sort'          => data_get($queryData, 'sort', []),
                        'limit'         => data_get($queryData, 'limit'),
                        'with'          => data_get($queryData, 'with', []),
                    ])->save();

                    $incomingUuids[] = $uuid;
                }
            } else {
                // Create new query
                $newQuery = TemplateQuery::create([
                    'template_uuid'   => $template->uuid,
                    'company_uuid'    => $companyUuid,
                    'created_by_uuid' => $createdByUuid,
                    'label'           => data_get($queryData, 'label'),
                    'variable_name'   => data_get($queryData, 'variable_name'),
                    'description'     => data_get($queryData, 'description'),
                    'model_type'      => data_get($queryData, 'model_type'),
                    'conditions'      => data_get($queryData, 'conditions', []),
                    'sort'            => data_get($queryData, 'sort', []),
                    'limit'           => data_get($queryData, 'limit'),
                    'with'            => data_get($queryData, 'with', []),
                ]);

                $incomingUuids[] = $newQuery->uuid;
            }
        }

        // Remove queries that were deleted in the builder
        // (only when the caller explicitly sent a queries array — even if empty)
        $template->queries()
            ->whereNotIn('uuid', $incomingUuids)
            ->delete();
    }
}
