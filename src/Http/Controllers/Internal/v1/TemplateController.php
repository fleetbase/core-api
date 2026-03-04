<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\Internal\CreateTemplateRequest;
use Fleetbase\Http\Resources\Template as TemplateResource;
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

    /**
     * Create a template, then upsert any nested queries included in the payload.
     *
     * POST /templates
     */
    public function createRecord(Request $request): JsonResponse
    {
        // Let the standard behaviour create the template record
        $response = parent::createRecord($request);

        // Retrieve the newly-created template from the response resource
        $template = $this->_templateFromResponse($response);
        if ($template) {
            $this->_syncQueries($template, $request->input('queries', []));
            // Re-load queries so the response includes them
            $template->load('queries');
            TemplateResource::wrap('template');
            return new TemplateResource($template);
        }

        return $response;
    }

    /**
     * Update a template, then upsert any nested queries included in the payload.
     *
     * PUT /templates/{id}
     */
    public function updateRecord(Request $request, string $id): JsonResponse
    {
        $response = parent::updateRecord($request, $id);

        $template = $this->_templateFromResponse($response);
        if ($template) {
            $this->_syncQueries($template, $request->input('queries', []));
            $template->load('queries');
            TemplateResource::wrap('template');
            return new TemplateResource($template);
        }

        return $response;
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

        $companyUuid     = session('company');
        $createdByUuid   = session('user');
        $incomingUuids   = [];

        foreach ($queries as $queryData) {
            $uuid = data_get($queryData, 'uuid');

            // Skip client-side temporary IDs (prefixed with _unsaved_)
            if ($uuid && Str::startsWith($uuid, '_unsaved_')) {
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
                    'template_uuid'  => $template->uuid,
                    'company_uuid'   => $companyUuid,
                    'created_by_uuid'=> $createdByUuid,
                    'label'          => data_get($queryData, 'label'),
                    'variable_name'  => data_get($queryData, 'variable_name'),
                    'description'    => data_get($queryData, 'description'),
                    'model_type'     => data_get($queryData, 'model_type'),
                    'conditions'     => data_get($queryData, 'conditions', []),
                    'sort'           => data_get($queryData, 'sort', []),
                    'limit'          => data_get($queryData, 'limit'),
                    'with'           => data_get($queryData, 'with', []),
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

    /**
     * Extract the Template model from a JsonResponse that wraps a TemplateResource.
     */
    protected function _templateFromResponse($response): ?Template
    {
        if ($response instanceof TemplateResource) {
            return $response->resource;
        }

        // The parent returns a TemplateResource directly (not wrapped in JsonResponse)
        // when it's an internal request. Try to get the underlying model.
        if (method_exists($response, 'resource') || property_exists($response, 'resource')) {
            $model = $response->resource ?? null;
            if ($model instanceof Template) {
                return $model;
            }
        }

        return null;
    }
}
