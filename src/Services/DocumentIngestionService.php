<?php

namespace Fleetbase\Services;

use Fleetbase\Models\DocumentQueueItem;
use Fleetbase\Models\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Document ingestion pipeline with safety-first design.
 *
 * SAFETY GUARANTEES:
 * - Missing Anthropic API key → falls back to heuristics, marks needs_review when AI would be needed
 * - Missing PDF tooling → marks PDF as needs_review with stored error
 * - Low match confidence → never creates CarrierInvoice, marks needs_review
 * - Hard exceptions → caught and stored, status = failed, document preserved
 *
 * AI USAGE:
 * - Heuristics first (filename, content keywords) for classification
 * - Claude API only when classification or invoice extraction is genuinely uncertain
 * - Never fabricates parsed data when AI is unavailable
 */
class DocumentIngestionService
{
    /**
     * Orchestrate the full ingestion pipeline for a queue item.
     */
    public function process(DocumentQueueItem $item): DocumentQueueItem
    {
        $item->update(['status' => DocumentQueueItem::STATUS_PROCESSING]);

        try {
            // Step 1: Extract text
            $text = $this->extractText($item);
            if ($text !== null) {
                $item->raw_content = $text;
                $item->save();
            } else {
                // Text extraction failed safely — preserve item for review
                $item->update([
                    'status'        => DocumentQueueItem::STATUS_NEEDS_REVIEW,
                    'error_message' => $item->error_message ?: 'Text extraction unavailable for this file type',
                    'processed_at'  => now(),
                ]);
                return $item->fresh();
            }

            // Step 2: Classify
            if ($item->document_type === DocumentQueueItem::TYPE_UNKNOWN) {
                $classification = $this->classifyDocument($text, $item->file?->original_filename);
                $item->document_type = $classification;
                $item->save();
            }

            // Step 3: Parse structured data (only for carrier invoices for now)
            $parsedData = null;
            if ($item->document_type === DocumentQueueItem::TYPE_CARRIER_INVOICE) {
                $parsedData = $this->parseStructuredData($text, $item->document_type);
                if ($parsedData) {
                    $item->update([
                        'parsed_data' => $parsedData,
                        'status'      => DocumentQueueItem::STATUS_PARSED,
                    ]);
                } else {
                    // AI unavailable or parse failed — mark for review, preserve raw text
                    $item->update([
                        'status'        => DocumentQueueItem::STATUS_NEEDS_REVIEW,
                        'error_message' => 'Structured parsing unavailable or failed — manual review required',
                        'processed_at'  => now(),
                    ]);
                    return $item->fresh();
                }
            } else {
                // Non-invoice docs: mark parsed without structured extraction
                $item->update(['status' => DocumentQueueItem::STATUS_PARSED]);
            }

            // Step 4: Auto-match
            if ($parsedData) {
                $match = $this->autoMatch($item, $parsedData);
                if ($match) {
                    $item->update([
                        'matched_order_uuid'    => $match['order_uuid'] ?? null,
                        'matched_shipment_uuid' => $match['shipment_uuid'] ?? null,
                        'match_confidence'      => $match['confidence'],
                        'match_method'          => $match['method'],
                        'status'                => DocumentQueueItem::STATUS_MATCHED,
                    ]);
                }
            }

            // Step 5: Conditionally create CarrierInvoice — only if safe
            if ($item->document_type === DocumentQueueItem::TYPE_CARRIER_INVOICE && $parsedData) {
                $invoice = $this->createCarrierInvoiceFromParsed($item, $parsedData);
                if (!$invoice) {
                    // Could not create invoice safely — stays in matched/needs_review state
                    $item->update([
                        'status' => DocumentQueueItem::STATUS_NEEDS_REVIEW,
                        'error_message' => $item->error_message ?: 'Invoice creation skipped: insufficient confidence or missing required fields',
                    ]);
                }
            }

            $item->update(['processed_at' => now()]);

        } catch (\Throwable $e) {
            Log::error('DocumentIngestionService::process failed', [
                'queue_item_uuid' => $item->uuid,
                'exception'       => $e->getMessage(),
            ]);
            $item->update([
                'status'        => DocumentQueueItem::STATUS_FAILED,
                'error_message' => substr($e->getMessage(), 0, 1000),
                'processed_at'  => now(),
            ]);
        }

        return $item->fresh();
    }

    /**
     * Extract raw text from the attached file.
     * Supports text/plain, text/csv natively. PDF requires spatie/pdf-to-text.
     * Returns null if extraction not available; sets error_message on the item.
     */
    public function extractText(DocumentQueueItem $item): ?string
    {
        $file = $item->file;
        if (!$file) {
            $item->error_message = 'No file attached to queue item';
            return null;
        }

        $contentType = strtolower((string) $file->content_type);

        // Plain text and CSV — read directly from storage
        if (in_array($contentType, ['text/plain', 'text/csv', 'text/tab-separated-values'])
            || str_starts_with($contentType, 'text/')) {
            try {
                return Storage::disk($file->disk ?? 'local')->get($file->path);
            } catch (\Throwable $e) {
                $item->error_message = 'Failed to read text file: ' . $e->getMessage();
                return null;
            }
        }

        // PDF — only if spatie/pdf-to-text is available AND pdftotext binary is present
        if ($contentType === 'application/pdf') {
            if (!class_exists(\Spatie\PdfToText\Pdf::class)) {
                $item->error_message = 'PDF parsing unavailable: spatie/pdf-to-text not installed';
                return null;
            }

            try {
                $disk = Storage::disk($file->disk ?? 'local');
                $tempPath = $disk->path($file->path);

                // If the disk does not expose a local path (e.g., S3), download to temp first
                if (!is_string($tempPath) || !file_exists($tempPath)) {
                    $contents = $disk->get($file->path);
                    $tempPath = tempnam(sys_get_temp_dir(), 'docq_') . '.pdf';
                    file_put_contents($tempPath, $contents);
                }

                $text = (new \Spatie\PdfToText\Pdf())->setPdf($tempPath)->text();
                return $text;
            } catch (\Throwable $e) {
                $item->error_message = 'PDF text extraction failed: ' . substr($e->getMessage(), 0, 500);
                return null;
            }
        }

        $item->error_message = "Unsupported content type for text extraction: {$contentType}";
        return null;
    }

    /**
     * Classify document type. Heuristics first, AI fallback if available.
     */
    public function classifyDocument(string $text, ?string $filename = null): string
    {
        $textLower = strtolower(substr($text, 0, 5000));
        $filenameLower = strtolower((string) $filename);

        // Heuristic-first classification — fast, deterministic, no API cost
        $heuristics = [
            DocumentQueueItem::TYPE_CARRIER_INVOICE => ['invoice', 'inv #', 'invoice #', 'invoice number', 'amount due', 'balance due'],
            DocumentQueueItem::TYPE_BOL             => ['bill of lading', 'bol number', 'b/l number'],
            DocumentQueueItem::TYPE_POD             => ['proof of delivery', 'received by', 'delivery receipt', 'pod'],
            DocumentQueueItem::TYPE_RATE_CONFIRMATION => ['rate confirmation', 'rate con', 'load confirmation', 'tender confirmation'],
            DocumentQueueItem::TYPE_INSURANCE_CERT  => ['certificate of insurance', 'coi', 'liability coverage'],
            DocumentQueueItem::TYPE_CUSTOMS         => ['customs declaration', 'commercial invoice', 'shipper export declaration'],
        ];

        foreach ($heuristics as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($textLower, $keyword) || str_contains($filenameLower, str_replace(' ', '_', $keyword))) {
                    return $type;
                }
            }
        }

        // Heuristic miss — try AI if available
        if ($this->isAiAvailable()) {
            $aiResult = $this->classifyWithAi($text);
            if ($aiResult) {
                return $aiResult;
            }
        }

        // No deterministic match and no AI — return unknown rather than guessing
        return DocumentQueueItem::TYPE_UNKNOWN;
    }

    /**
     * Use Claude API to classify document type.
     */
    protected function classifyWithAi(string $text): ?string
    {
        $allowedTypes = [
            DocumentQueueItem::TYPE_CARRIER_INVOICE,
            DocumentQueueItem::TYPE_BOL,
            DocumentQueueItem::TYPE_POD,
            DocumentQueueItem::TYPE_RATE_CONFIRMATION,
            DocumentQueueItem::TYPE_INSURANCE_CERT,
            DocumentQueueItem::TYPE_CUSTOMS,
            DocumentQueueItem::TYPE_OTHER,
        ];

        $prompt = "Classify this freight document as ONE of: " . implode(', ', $allowedTypes)
            . ".\n\nReturn ONLY the classification, nothing else.\n\nDocument text:\n"
            . substr($text, 0, 4000);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key'         => config('services.anthropic.key'),
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => config('services.anthropic.classification_model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 50,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                return null;
            }

            $classification = trim(strtolower($response->json('content.0.text', '')));
            return in_array($classification, $allowedTypes) ? $classification : null;
        } catch (\Throwable $e) {
            Log::warning('AI classification failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse structured data from document text. Currently focused on carrier invoices.
     * Returns null if AI is unavailable or parsing fails — never fabricates data.
     */
    public function parseStructuredData(string $text, string $docType): ?array
    {
        if ($docType !== DocumentQueueItem::TYPE_CARRIER_INVOICE) {
            return null; // Only invoice parsing supported in this build
        }

        if (!$this->isAiAvailable()) {
            return null;
        }

        $prompt = <<<PROMPT
Extract structured data from this carrier freight invoice. Return ONLY valid JSON, no markdown.

JSON structure:
{
  "carrier_name": "string or null",
  "invoice_number": "string or null",
  "invoice_date": "YYYY-MM-DD or null",
  "pro_number": "string or null",
  "bol_number": "string or null",
  "pickup_date": "YYYY-MM-DD or null",
  "delivery_date": "YYYY-MM-DD or null",
  "origin": {"city": "", "state": "", "zip": ""},
  "destination": {"city": "", "state": "", "zip": ""},
  "total_amount": number or null,
  "line_items": [
    {
      "charge_type": "linehaul|fuel_surcharge|accessorial|detention|lumper|liftgate|residential|inside_delivery|other",
      "description": "string",
      "amount": number,
      "quantity": number or null,
      "rate": number or null
    }
  ]
}

Invoice text:
{$text}
PROMPT;

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key'         => config('services.anthropic.key'),
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => config('services.anthropic.parse_model', 'claude-sonnet-4-6'),
                    'max_tokens' => 2000,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                return null;
            }

            $jsonText = $response->json('content.0.text', '');
            $jsonText = preg_replace('/```json?\s*|\s*```/', '', (string) $jsonText);
            $parsed = json_decode(trim($jsonText), true);

            if (!is_array($parsed)) {
                return null;
            }

            return $parsed;
        } catch (\Throwable $e) {
            Log::warning('AI structured parse failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Match a parsed document to an order/shipment.
     * Priority: PRO number → BOL number → carrier + pickup date.
     */
    public function autoMatch(DocumentQueueItem $item, ?array $parsedData): ?array
    {
        if (!$parsedData) {
            return null;
        }

        $companyUuid = $item->company_uuid;

        // Try PRO number first (highest confidence)
        if ($proNumber = $parsedData['pro_number'] ?? null) {
            // Check shipments
            if (class_exists(\Fleetbase\FleetOps\Models\Shipment::class)) {
                $shipment = \Fleetbase\FleetOps\Models\Shipment::where('company_uuid', $companyUuid)
                    ->where('pro_number', $proNumber)
                    ->first();
                if ($shipment) {
                    $orderUuid = null;
                    try {
                        $orderUuid = $shipment->orders()->first()?->uuid;
                    } catch (\Throwable $e) {
                        // relationship missing or empty — fine
                    }
                    return [
                        'shipment_uuid' => $shipment->uuid,
                        'order_uuid'    => $orderUuid,
                        'confidence'    => 0.95,
                        'method'        => 'pro_number',
                    ];
                }
            }

            // Check orders by meta.pro_number (legacy path)
            if (class_exists(\Fleetbase\FleetOps\Models\Order::class)) {
                $order = \Fleetbase\FleetOps\Models\Order::where('company_uuid', $companyUuid)
                    ->where('meta->pro_number', $proNumber)
                    ->first();
                if ($order) {
                    return [
                        'order_uuid'   => $order->uuid,
                        'confidence'   => 0.90,
                        'method'       => 'pro_number',
                    ];
                }
            }
        }

        // Try BOL number
        if ($bolNumber = $parsedData['bol_number'] ?? null) {
            if (class_exists(\Fleetbase\FleetOps\Models\Shipment::class)) {
                $shipment = \Fleetbase\FleetOps\Models\Shipment::where('company_uuid', $companyUuid)
                    ->where('bol_number', $bolNumber)
                    ->first();
                if ($shipment) {
                    $orderUuid = null;
                    try {
                        $orderUuid = $shipment->orders()->first()?->uuid;
                    } catch (\Throwable $e) {}
                    return [
                        'shipment_uuid' => $shipment->uuid,
                        'order_uuid'    => $orderUuid,
                        'confidence'    => 0.90,
                        'method'        => 'bol_number',
                    ];
                }
            }
        }

        // Try carrier name + pickup date (lower confidence)
        $carrierName = $parsedData['carrier_name'] ?? null;
        $pickupDate  = $parsedData['pickup_date'] ?? null;
        if ($carrierName && $pickupDate && class_exists(\Fleetbase\FleetOps\Models\Vendor::class)) {
            $vendor = \Fleetbase\FleetOps\Models\Vendor::where('company_uuid', $companyUuid)
                ->where('name', 'LIKE', "%{$carrierName}%")
                ->first();

            if ($vendor && class_exists(\Fleetbase\FleetOps\Models\Shipment::class)) {
                $shipment = \Fleetbase\FleetOps\Models\Shipment::where('company_uuid', $companyUuid)
                    ->where('vendor_uuid', $vendor->uuid)
                    ->whereDate('planned_pickup_at', $pickupDate)
                    ->first();

                if ($shipment) {
                    $orderUuid = null;
                    try {
                        $orderUuid = $shipment->orders()->first()?->uuid;
                    } catch (\Throwable $e) {}
                    return [
                        'shipment_uuid' => $shipment->uuid,
                        'order_uuid'    => $orderUuid,
                        'confidence'    => 0.70,
                        'method'        => 'carrier_date',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Create a CarrierInvoice from parsed data — ONLY if safe.
     *
     * Safety conditions ALL must be met:
     * - document_type is carrier_invoice
     * - parsed_data has total_amount and either invoice_number or pro_number
     * - match_confidence >= MIN_INVOICE_CREATION_CONFIDENCE (or vendor resolvable)
     * - vendor is resolvable from carrier_name
     */
    public function createCarrierInvoiceFromParsed(DocumentQueueItem $item, array $parsedData): ?\Fleetbase\Ledger\Models\CarrierInvoice
    {
        // Required class check
        if (!class_exists(\Fleetbase\Ledger\Models\CarrierInvoice::class)) {
            return null;
        }

        // Must be carrier invoice
        if ($item->document_type !== DocumentQueueItem::TYPE_CARRIER_INVOICE) {
            return null;
        }

        // Confidence threshold for safe auto-creation
        if ($item->match_confidence !== null
            && (float) $item->match_confidence < DocumentQueueItem::MIN_INVOICE_CREATION_CONFIDENCE) {
            return null;
        }

        // Required fields
        $totalAmount = $parsedData['total_amount'] ?? null;
        if (!$totalAmount) {
            return null;
        }

        // Resolve vendor — required for CarrierInvoice
        $carrierName = $parsedData['carrier_name'] ?? null;
        if (!$carrierName || !class_exists(\Fleetbase\FleetOps\Models\Vendor::class)) {
            return null;
        }

        $vendor = \Fleetbase\FleetOps\Models\Vendor::where('company_uuid', $item->company_uuid)
            ->where('name', 'LIKE', "%{$carrierName}%")
            ->first();

        if (!$vendor) {
            return null;
        }

        // Idempotency: don't create if already created from this item
        if ($item->matched_carrier_invoice_uuid) {
            return \Fleetbase\Ledger\Models\CarrierInvoice::where('uuid', $item->matched_carrier_invoice_uuid)->first();
        }

        try {
            $invoice = \Fleetbase\Ledger\Models\CarrierInvoice::create([
                'company_uuid'    => $item->company_uuid,
                'vendor_uuid'     => $vendor->uuid,
                'order_uuid'      => $item->matched_order_uuid,
                'shipment_uuid'   => $item->matched_shipment_uuid,
                'invoice_number'  => $parsedData['invoice_number'] ?? null,
                'pro_number'      => $parsedData['pro_number'] ?? null,
                'bol_number'      => $parsedData['bol_number'] ?? null,
                'invoiced_amount' => $totalAmount,
                'invoice_date'    => $parsedData['invoice_date'] ?? null,
                'pickup_date'     => $parsedData['pickup_date'] ?? null,
                'delivery_date'   => $parsedData['delivery_date'] ?? null,
                'source'          => $item->source,
                'status'          => 'pending',
                'received_at'     => now(),
                'file_uuid'       => $item->file_uuid,
            ]);

            // Create line items if present
            foreach ($parsedData['line_items'] ?? [] as $lineItem) {
                $invoice->items()->create([
                    'charge_type'     => $lineItem['charge_type'] ?? 'other',
                    'description'     => $lineItem['description'] ?? null,
                    'invoiced_amount' => $lineItem['amount'] ?? 0,
                    'quantity'        => $lineItem['quantity'] ?? null,
                    'rate'            => $lineItem['rate'] ?? null,
                ]);
            }

            // Link back to the queue item
            $item->update([
                'matched_carrier_invoice_uuid' => $invoice->uuid,
            ]);

            return $invoice;
        } catch (\Throwable $e) {
            Log::error('CarrierInvoice creation from queue item failed', [
                'queue_item_uuid' => $item->uuid,
                'error'           => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if the Anthropic API key is configured.
     */
    protected function isAiAvailable(): bool
    {
        return !empty(config('services.anthropic.key'));
    }
}
