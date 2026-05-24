<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ExportRequest;
use App\Services\Export\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class ExportController extends Controller
{
    protected ExportService $exportService;

    public function __construct(ExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    public function export(ExportRequest $request): JsonResponse|Response
    {
        // Handle validation-only requests
        if ($request->isValidateOnly()) {
            $validation = $this->exportService->validateExport($request);
            return response()->json([
                'success' => true,
                'data' => $validation,
            ]);
        }

        // Generate export
        $result = $this->exportService->export($request);

        // For small exports or when content is available, return file directly
        if (!$result['stored'] || $result['content'] !== null) {
            return response($result['content'], 200, $result['headers']);
        }

        // For larger exports, return download URL
        return response()->json([
            'success' => true,
            'data' => [
                'download_url' => route('export.download', ['filename' => $result['filename']]),
                'records_count' => $result['records_count'],
                'file_size' => $this->getFileSize($result['file_path']),
                'expires_at' => now()->addHours(24)->toISOString(),
            ],
        ]);
    }

    public function download(string $filename): JsonResponse|Response
    {
        $exportData = $this->exportService->getStoredExport($filename);
        
        if (!$exportData) {
            return response()->json([
                'error' => 'File not found or expired',
                'message' => 'The export file may have expired or does not exist',
            ], 404);
        }

        return response($exportData['content'], 200, $exportData['headers']);
    }

    protected function getFileSize(?string $filePath): string
    {
        if (!$filePath) {
            return '0B';
        }

        $bytes = \Illuminate\Support\Facades\Storage::disk('local')->size($filePath);

        if ($bytes < 1024) {
            return "{$bytes}B";
        } elseif ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . "KB";
        } else {
            return round($bytes / (1024 * 1024), 1) . "MB";
        }
    }
}
