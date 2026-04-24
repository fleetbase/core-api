<?php

namespace Fleetbase\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Models\DocumentQueueItem;
use Fleetbase\Models\File;
use Fleetbase\Services\DocumentIngestionService;
use Illuminate\Http\Request;

/**
 * Thin controller for document queue operations.
 * All ingestion/parsing/matching logic lives in DocumentIngestionService.
 */
class DocumentQueueController extends FleetbaseController
{
    public $resource = DocumentQueueItem::class;

    /**
     * POST /document-queue/upload
     * Manual upload — the primary first-class entry point.
     * Body: multipart/form-data with 'file' field, optional 'document_type'.
     */
    public function upload(Request $request)
    {
        $validated = $request->validate([
            'file'          => 'required|file',
            'document_type' => 'nullable|string',
        ]);

        $uploadedFile = $request->file('file');

        // Persist the file via the existing File model
        $path = "document-queue/" . session('company') . "/" . uniqid('doc_') . '_' . $uploadedFile->getClientOriginalName();
        $file = File::createFromUpload($uploadedFile, $path);

        if (!$file) {
            return response()->apiError('Failed to store uploaded file.');
        }

        $item = DocumentQueueItem::create([
            'company_uuid'  => session('company'),
            'file_uuid'     => $file->uuid,
            'source'        => DocumentQueueItem::SOURCE_MANUAL,
            'document_type' => $validated['document_type'] ?? DocumentQueueItem::TYPE_UNKNOWN,
            'status'        => DocumentQueueItem::STATUS_RECEIVED,
        ]);

        return response()->json(['data' => $item->load('file')]);
    }

    /**
     * POST /document-queue/{id}/process
     * Synchronously process an item through the full ingestion pipeline.
     */
    public function process(string $id)
    {
        $item = DocumentQueueItem::findRecordOrFail($id);
        $processed = app(DocumentIngestionService::class)->process($item);

        return response()->json(['data' => $processed->load('file')]);
    }

    /**
     * POST /document-queue/{id}/reprocess
     * Re-run the pipeline. Useful after fixing AI key, PDF tooling, or fixing match.
     */
    public function reprocess(string $id)
    {
        $item = DocumentQueueItem::findRecordOrFail($id);

        // Reset to received state (preserve raw_content if present)
        $item->update([
            'status'        => DocumentQueueItem::STATUS_RECEIVED,
            'error_message' => null,
        ]);

        $processed = app(DocumentIngestionService::class)->process($item);

        return response()->json(['data' => $processed->load('file')]);
    }

    /**
     * POST /document-queue/{id}/manual-match
     * Manually associate a queue item with an order or shipment.
     */
    public function manualMatch(string $id, Request $request)
    {
        $item = DocumentQueueItem::findRecordOrFail($id);

        $validated = $request->validate([
            'order_uuid'    => 'nullable|string',
            'shipment_uuid' => 'nullable|string',
        ]);

        $item->update([
            'matched_order_uuid'    => $validated['order_uuid'] ?? null,
            'matched_shipment_uuid' => $validated['shipment_uuid'] ?? null,
            'match_confidence'      => 1.00,
            'match_method'          => 'manual',
            'status'                => DocumentQueueItem::STATUS_MATCHED,
        ]);

        return response()->json(['data' => $item->fresh()]);
    }
}
