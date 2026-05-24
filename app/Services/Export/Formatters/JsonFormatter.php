<?php

namespace App\Services\Export\Formatters;

use Illuminate\Support\Collection;
use Carbon\Carbon;

class JsonFormatter implements ExportFormatter
{
    public function format(Collection $data, array $options = []): string
    {
        $formattedData = $data->map(function ($item) use ($options) {
            return $this->formatItem($item, $options);
        });

        $result = [
            'metadata' => [
                'exported_at' => now()->toISOString(),
                'total_records' => $data->count(),
                'filters_applied' => $options['filters_applied'] ?? [],
            ],
            'data' => $formattedData->toArray(),
        ];

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function getMimeType(): string
    {
        return 'application/json';
    }

    public function getFileExtension(): string
    {
        return 'json';
    }

    public function getHeaders(array $fields): array
    {
        return [
            'Content-Type' => $this->getMimeType(),
            'Content-Disposition' => 'attachment; filename="export_' . date('Y-m-d_H-i-s') . '.json"',
        ];
    }

    private function formatItem($item, array $options): array
    {
        if (is_array($item)) {
            return $this->formatArrayItem($item, $options);
        }

        if ($item instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->formatModelItem($item, $options);
        }

        return $item;
    }

    private function formatModelItem($model, array $options): array
    {
        $formatted = [];
        $dateFormat = $options['date_format'] ?? 'Y-m-d H:i:s';
        $timezone = $options['timezone'] ?? 'UTC';
        $nullAs = $options['null_as'] ?? '';

        foreach ($model->getAttributes() as $key => $value) {
            $formatted[$key] = $this->formatValue($value, $dateFormat, $timezone, $nullAs);
        }

        // Handle relationships
        foreach ($model->getRelations() as $relation => $related) {
            if ($related instanceof \Illuminate\Database\Eloquent\Collection) {
                $formatted[$relation] = $related->map(function ($item) use ($dateFormat, $timezone, $nullAs) {
                    return $this->formatModelItem($item, ['date_format' => $dateFormat, 'timezone' => $timezone, 'null_as' => $nullAs]);
                })->toArray();
            } elseif ($related instanceof \Illuminate\Database\Eloquent\Model) {
                $formatted[$relation] = $this->formatModelItem($related, ['date_format' => $dateFormat, 'timezone' => $timezone, 'null_as' => $nullAs]);
            }
        }

        return $formatted;
    }

    private function formatArrayItem(array $item, array $options): array
    {
        $formatted = [];
        $dateFormat = $options['date_format'] ?? 'Y-m-d H:i:s';
        $timezone = $options['timezone'] ?? 'UTC';
        $nullAs = $options['null_as'] ?? '';

        foreach ($item as $key => $value) {
            $formatted[$key] = $this->formatValue($value, $dateFormat, $timezone, $nullAs);
        }

        return $formatted;
    }

    private function formatValue($value, string $dateFormat, string $timezone, string $nullAs)
    {
        if (is_null($value)) {
            return $nullAs;
        }

        if ($value instanceof \Carbon\Carbon) {
            return $value->setTimezone($timezone)->format($dateFormat);
        }

        if (is_numeric($value)) {
            $decimalPlaces = $this->getDecimalPlaces($value);
            return round($value, $decimalPlaces);
        }

        return $value;
    }

    private function getDecimalPlaces($value): int
    {
        if (is_int($value)) {
            return 0;
        }
        
        if (is_float($value)) {
            return 2; // Default to 2 decimal places for floats
        }
        
        return 0;
    }
}
