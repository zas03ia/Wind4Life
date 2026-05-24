<?php

namespace App\Services\Export;

use App\Http\Requests\ExportRequest;
use App\Services\Export\Handlers\ReadingExportHandler;
use App\Services\Export\Handlers\AnemometerExportHandler;
use App\Services\Export\Handlers\UserExportHandler;
use App\Services\Export\Formatters\JsonFormatter;
use App\Services\Export\Formatters\CsvFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportService
{
    protected array $handlers = [
        'readings' => ReadingExportHandler::class,
        'anemometers' => AnemometerExportHandler::class,
        'users' => UserExportHandler::class,
    ];

    protected array $formatters = [
        'json' => JsonFormatter::class,
        'csv' => CsvFormatter::class,
    ];

    public function export(ExportRequest $request): array
    {
        $resource = $request->input('resource');
        $format = $request->input('format');
        $filters = $request->getFilters();
        $fields = $request->getFields();
        $sort = $request->getSort();
        $pagination = $request->getPagination();
        $options = $request->getOptions();

        // Get handler for the resource
        $handler = $this->getHandler($resource);
        
        // Build query
        $query = $handler->buildQuery($filters);

        // Apply field selection
        if (!empty($fields)) {
            $query = $this->applyFieldSelection($query, $fields, $resource);
        }

        // Apply sorting
        if (!empty($sort)) {
            $query = $this->applySorting($query, $sort, $resource);
        }

        // Apply pagination
        $query = $query->offset($pagination['offset'])->limit($pagination['limit']);

        // Get data
        $data = $query->get();

        // Get formatter
        $formatter = $this->getFormatter($format);

        // Add filters to options for metadata
        $options['filters_applied'] = $filters;

        // Format data
        $content = $formatter->format($data, $options);

        // Generate filename
        $filename = $this->generateFilename($resource, $format);

        // For large exports, store the file
        if ($data->count() > 1000 || $request->isAsync()) {
            $filePath = $this->storeExportFile($filename, $content);
            return [
                'content' => null,
                'filename' => $filename,
                'file_path' => $filePath,
                'mime_type' => $formatter->getMimeType(),
                'file_extension' => $formatter->getFileExtension(),
                'records_count' => $data->count(),
                'headers' => $formatter->getHeaders($fields),
                'stored' => true,
            ];
        }

        // For small exports, return content directly
        return [
            'content' => $content,
            'filename' => $filename,
            'file_path' => null,
            'mime_type' => $formatter->getMimeType(),
            'file_extension' => $formatter->getFileExtension(),
            'records_count' => $data->count(),
            'headers' => $formatter->getHeaders($fields),
            'stored' => false,
        ];
    }

    public function validateExport(ExportRequest $request): array
    {
        $resource = $request->input('resource');
        $filters = $request->getFilters();
        $pagination = $request->getPagination();

        $handler = $this->getHandler($resource);
        $query = $handler->buildQuery($filters);

        $totalRecords = $query->count();
        $estimatedSize = $this->estimateFileSize($totalRecords, $request->input('format'));

        return [
            'valid' => true,
            'estimated_records' => $totalRecords,
            'estimated_size' => $estimatedSize,
            'will_be_async' => $totalRecords > 25000,
        ];
    }

    public function getStoredExport(string $filename): ?array
    {
        $filePath = 'exports/' . $filename;
        
        if (!Storage::disk('local')->exists($filePath)) {
            return null;
        }

        $content = Storage::disk('local')->get($filePath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        $formatter = $this->getFormatter($extension);

        return [
            'content' => $content,
            'mime_type' => $formatter->getMimeType(),
            'headers' => $formatter->getHeaders([]),
        ];
    }

    protected function storeExportFile(string $filename, string $content): string
    {
        $filePath = 'exports/' . $filename;
        
        // Ensure the exports directory exists
        Storage::disk('local')->makeDirectory('exports');
        
        // Store the file
        Storage::disk('local')->put($filePath, $content);
        
        return $filePath;
    }

    protected function getHandler(string $resource)
    {
        if (!isset($this->handlers[$resource])) {
            throw new \InvalidArgumentException("Unsupported resource: {$resource}");
        }

        $handlerClass = $this->handlers[$resource];
        return new $handlerClass();
    }

    protected function getFormatter(string $format)
    {
        if (!isset($this->formatters[$format])) {
            throw new \InvalidArgumentException("Unsupported format: {$format}");
        }

        $formatterClass = $this->formatters[$format];
        return new $formatterClass();
    }

    protected function applyFieldSelection(Builder $query, array $fields, string $resource): Builder
    {
        $handler = $this->getHandler($resource);
        $availableFields = $handler->getAvailableFields();
        
        // Filter requested fields to only available ones
        $validFields = array_intersect($fields, $availableFields);
        
        if (empty($validFields)) {
            // Use default fields if no valid fields specified
            $validFields = $handler->getDefaultFields();
        }

        // For anemometers, don't apply select if readings_count is requested
        // since it's an aggregated field, not a real column
        if ($resource === 'anemometers' && in_array('readings_count', $validFields)) {
            return $query; // Return query without select restriction
        }

        return $query->select($this->mapFieldsToSelect($validFields, $resource));
    }

    protected function applySorting(Builder $query, array $sort, string $resource): Builder
    {
        foreach ($sort as $sortItem) {
            $field = $sortItem['field'];
            $direction = $sortItem['direction'];
            
            // Map field names to database columns
            $column = $this->mapFieldToColumn($field, $resource);
            
            $query->orderBy($column, $direction);
        }

        return $query;
    }

    protected function mapFieldsToSelect(array $fields, string $resource): array
    {
        $mapped = [];
        
        foreach ($fields as $field) {
            if (strpos($field, '.') !== false) {
                // Handle relationship fields
                $parts = explode('.', $field);
                if ($resource === 'readings' && $parts[0] === 'anemometer') {
                    $mapped[] = 'readings.anemometer_id';
                }
            } else {
                $mapped[] = $resource . '.' . $field;
            }
        }

        // Remove duplicates and ensure we have primary keys
        $mapped = array_unique($mapped);
        
        if ($resource === 'readings' && !in_array('readings.id', $mapped)) {
            $mapped[] = 'readings.id';
        } elseif ($resource === 'anemometers' && !in_array('anemometers.id', $mapped)) {
            $mapped[] = 'anemometers.id';
        } elseif ($resource === 'users' && !in_array('users.id', $mapped)) {
            $mapped[] = 'users.id';
        }

        return $mapped;
    }

    protected function mapFieldToColumn(string $field, string $resource): string
    {
        if (strpos($field, '.') !== false) {
            return $field;
        }

        // Map table prefixes
        $prefix = match ($resource) {
            'readings' => 'readings.',
            'anemometers' => 'anemometers.',
            'users' => 'users.',
            default => '',
        };

        return $prefix . $field;
    }

    protected function generateFilename(string $resource, string $format): string
    {
        return "export_{$resource}_" . date('Y-m-d_H-i-s') . ".{$format}";
    }

    protected function estimateFileSize(int $recordCount, string $format): string
    {
        // Rough estimation: 1KB per record for JSON, 0.5KB for CSV
        $bytesPerRecord = $format === 'json' ? 1024 : 512;
        $totalBytes = $recordCount * $bytesPerRecord;

        if ($totalBytes < 1024) {
            return "{$totalBytes}B";
        } elseif ($totalBytes < 1024 * 1024) {
            return round($totalBytes / 1024, 1) . "KB";
        } else {
            return round($totalBytes / (1024 * 1024), 1) . "MB";
        }
    }
}
