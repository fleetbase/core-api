<?php

namespace Fleetbase\Services;

use Fleetbase\Models\File;
use Fleetbase\Support\Utils;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FileResolverService.
 *
 * A centralized service for resolving files from multiple input types.
 * Supports: UploadedFile, Base64 strings, existing file IDs, and remote URLs.
 */
class FileResolverService
{
    /**
     * Resolve a file from various sources.
     *
     * This method intelligently detects the input type and delegates to the
     * appropriate handler method. It supports:
     * - UploadedFile instances (direct file uploads)
     * - Base64 encoded strings (data URIs)
     * - File public IDs (e.g., 'file_390jd2')
     * - Remote URLs (will download and store)
     *
     * @param mixed       $file The file input to resolve
     * @param string|null $path The storage path prefix (default: 'uploads')
     * @param string|null $disk The storage disk to use (default: config default)
     *
     * @return File|null The resolved File model or null on failure
     */
    public function resolve($file, ?string $path = 'uploads', ?string $disk = null): ?File
    {
        if ($file instanceof UploadedFile) {
            return $this->resolveFromUpload($file, $path, $disk);
        }

        if (is_string($file)) {
            // Check for public ID first (most specific)
            if (Utils::isPublicId($file)) {
                return $this->resolveFromId($file);
            }

            // Check for Base64 data URI
            if (Str::startsWith($file, 'data:image')) {
                return $this->resolveFromBase64($file, $path, $disk);
            }

            // Check for valid URL
            if (filter_var($file, FILTER_VALIDATE_URL)) {
                return $this->resolveFromUrl($file, $path, $disk);
            }
        }

        return null;
    }

    /**
     * Resolve multiple files at once.
     *
     * @param array       $files Array of file inputs
     * @param string|null $path  The storage path prefix
     * @param string|null $disk  The storage disk to use
     *
     * @return array Array of resolved File models
     */
    public function resolveMany(array $files, ?string $path = 'uploads', ?string $disk = null): array
    {
        $resolved = [];

        foreach ($files as $file) {
            $resolvedFile = $this->resolve($file, $path, $disk);
            if ($resolvedFile) {
                $resolved[] = $resolvedFile;
            }
        }

        return $resolved;
    }

    /**
     * Resolve a file from an UploadedFile instance.
     *
     * @param UploadedFile $upload The uploaded file
     * @param string       $path   The storage path prefix
     * @param string|null  $disk   The storage disk
     */
    protected function resolveFromUpload(UploadedFile $upload, string $path, ?string $disk = null): ?File
    {
        $disk = $disk ?? config('filesystems.default');

        // Generate a unique filename to prevent collisions
        $extension = $upload->getClientOriginalExtension();
        $fileName  = File::randomFileName($extension);

        // Ensure path doesn't have trailing slash
        $path     = rtrim($path, '/');
        $fullPath = $path . '/' . $fileName;

        // Store the file
        $upload->storeAs($path, $fileName, ['disk' => $disk]);

        // Create the File model
        return File::createFromUpload($upload, $fullPath, null, null, $disk);
    }

    /**
     * Resolve a file from a base64 encoded string.
     *
     * @param string      $base64 The base64 encoded string (with data URI prefix)
     * @param string      $path   The storage path prefix
     * @param string|null $disk   The storage disk
     */
    protected function resolveFromBase64(string $base64, string $path, ?string $disk = null): ?File
    {
        $disk = $disk ?? config('filesystems.default');

        // Ensure path is properly formatted
        $path = rtrim($path, '/');

        return File::createFromBase64($base64, null, $path, 'image', null, null, $disk);
    }

    /**
     * Resolve a file from an existing file public ID.
     *
     * @param string $id The file public ID (e.g., 'file_390jd2')
     */
    protected function resolveFromId(string $id): ?File
    {
        return File::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->first();
    }

    /**
     * Resolve a file from a remote URL by downloading it.
     *
     * @param string      $url  The remote URL
     * @param string      $path The storage path prefix
     * @param string|null $disk The storage disk
     */
    protected function resolveFromUrl(string $url, string $path, ?string $disk = null): ?File
    {
        $disk = $disk ?? config('filesystems.default');

        try {
            // Download the file
            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::warning('Failed to download file from URL', [
                    'url'    => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            // Extract filename from URL or generate one
            $filename = $this->extractFilenameFromUrl($url);
            $path     = rtrim($path, '/');
            $fullPath = $path . '/' . $filename;

            // Store the file content
            Storage::disk($disk)->put($fullPath, $response->body());

            // Determine content type
            $contentType = $response->header('Content-Type') ?? 'application/octet-stream';

            // Get file size
            $fileSize = $response->header('Content-Length') ?? strlen($response->body());

            // Create the file record
            return File::create([
                'company_uuid'      => session('company'),
                'uploader_uuid'     => auth()->id(),
                'disk'              => $disk,
                'path'              => $fullPath,
                'original_filename' => $filename,
                'content_type'      => $contentType,
                'file_size'         => $fileSize,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to download file from URL', [
                'url'   => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return null;
    }

    /**
     * Extract a filename from a URL.
     *
     * @param string $url The URL
     *
     * @return string The extracted or generated filename
     */
    protected function extractFilenameFromUrl(string $url): string
    {
        $path     = parse_url($url, PHP_URL_PATH);
        $filename = basename($path);

        // If no valid filename, generate one
        if (empty($filename) || !Str::contains($filename, '.')) {
            $extension = $this->guessExtensionFromUrl($url);
            $filename  = File::randomFileName($extension);
        }

        return $filename;
    }

    /**
     * Guess file extension from URL or content type.
     *
     * @param string $url The URL
     *
     * @return string The guessed extension (default: 'bin')
     */
    protected function guessExtensionFromUrl(string $url): string
    {
        // Try to get extension from URL path
        $path      = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (!empty($extension)) {
            return $extension;
        }

        // Default to binary file
        return 'bin';
    }

    /**
     * Resolve and attach a file to a model's file relation.
     *
     * This is a convenience method that resolves a file and immediately
     * sets it on the specified model attribute.
     *
     * @param mixed       $file      The file input to resolve
     * @param mixed       $model     The model to attach to
     * @param string      $attribute The attribute name (e.g., 'photo_uuid')
     * @param string|null $path      The storage path prefix
     * @param string|null $disk      The storage disk
     *
     * @return bool True if file was resolved and attached, false otherwise
     */
    public function resolveAndAttach($file, $model, string $attribute, ?string $path = 'uploads', ?string $disk = null): bool
    {
        $resolvedFile = $this->resolve($file, $path, $disk);

        if ($resolvedFile && $model) {
            $model->{$attribute} = $resolvedFile->uuid;

            return true;
        }

        return false;
    }
}
