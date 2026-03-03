<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\Internal\CreateTemplateRequest;
use Fleetbase\Models\Template;
use Fleetbase\Services\TemplateRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
}
