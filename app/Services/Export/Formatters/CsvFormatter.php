<?php

namespace App\Services\Export\Formatters;

use Illuminate\Support\Collection;
use Carbon\Carbon;

class CsvFormatter implements ExportFormatter
{
    public function format(Collection $data, array $options = []): string
    {
        $includeHeaders = $options['include_headers'] ?? true;
        $dateFormat = $options['date_format'] ?? 'Y-m-d H:i:s';
        $timezone = $options['timezone'] ?? 'UTC';
        $decimalPlaces = $options['decimal_places'] ?? 2;
        $nullAs = $options['null_as'] ?? '';

        $csv = '';
        $headers = [];

        // Extract headers from first item
        if ($data->isNotEmpty()) {
            $headers = $this->extractHeaders($data->first());
        }

        // Add headers if requested
        if ($includeHeaders && !empty($headers)) {
            $csv .= $this->formatCsvRow($headers) . "\n";
        }

        // Add data rows
        foreach ($data as $item) {
            $row = $this->formatItem($item, $headers, $dateFormat, $timezone, $decimalPlaces, $nullAs);
            $csv .= $this->formatCsvRow($row) . "\n";
        }

        return $csv;
    }

    public function getMimeType(): string
    {
        return 'text/csv';
    }

    public function getFileExtension(): string
    {
        return 'csv';
    }

    public function getHeaders(array $fields): array
    {
        return [
            'Content-Type' => $this->getMimeType(),
            'Content-Disposition' => 'attachment; filename="export_' . date('Y-m-d_H-i-s') . '.csv"',
        ];
    }

    private function extractHeaders($item): array
    {
        $headers = [];

        if (is_array($item)) {
            $headers = $this->extractArrayHeaders($item);
        } elseif ($item instanceof \Illuminate\Database\Eloquent\Model) {
            $headers = $this->extractModelHeaders($item);
        }

        return $headers;
    }

    private function extractArrayHeaders(array $item): array
    {
        $headers = [];
        
        foreach ($item as $key => $value) {
            if (is_array($value)) {
                // Handle nested arrays (like tags)
                $headers = array_merge($headers, $this->extractArrayHeaders($value));
            } elseif (is_object($value)) {
                // Handle objects (like related models)
                $headers = array_merge($headers, $this->extractModelHeaders($value));
            } else {
                $headers[] = $key;
            }
        }

        return array_unique($headers);
    }

    private function extractModelHeaders($model): array
    {
        $headers = [];

        // Add model attributes
        foreach (array_keys($model->getAttributes()) as $attribute) {
            $headers[] = $attribute;
        }

        // Add relationship data
        foreach ($model->getRelations() as $relation => $related) {
            if ($related instanceof \Illuminate\Database\Eloquent\Collection) {
                foreach ($related as $item) {
                    $relationHeaders = $this->extractModelHeaders($item);
                    foreach ($relationHeaders as $header) {
                        $headers[] = $relation . '.' . $header;
                    }
                }
            } elseif ($related instanceof \Illuminate\Database\Eloquent\Model) {
                $relationHeaders = $this->extractModelHeaders($related);
                foreach ($relationHeaders as $header) {
                    $headers[] = $relation . '.' . $header;
                }
            }
        }

        return array_unique($headers);
    }

    private function formatItem($item, array $headers, string $dateFormat, string $timezone, int $decimalPlaces, string $nullAs): array
    {
        $row = [];

        foreach ($headers as $header) {
            $value = $this->extractValue($item, $header);
            $row[] = $this->formatValue($value, $dateFormat, $timezone, $decimalPlaces, $nullAs);
        }

        return $row;
    }

    private function extractValue($item, string $header)
    {
        if (is_array($item)) {
            return $this->extractFromArray($item, $header);
        }

        if ($item instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->extractFromModel($item, $header);
        }

        return null;
    }

    private function extractFromArray(array $item, string $header)
    {
        if (strpos($header, '.') === false) {
            return $item[$header] ?? null;
        }

        $parts = explode('.', $header);
        $value = $item;

        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return null;
            }
        }

        return $value;
    }

    private function extractFromModel($model, string $header)
    {
        if (strpos($header, '.') === false) {
            return $model->$header ?? null;
        }

        $parts = explode('.', $header);
        $value = $model;

        foreach ($parts as $part) {
            if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                $value = $value->$part ?? null;
            } elseif (is_array($value)) {
                $value = $value[$part] ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    private function formatValue($value, string $dateFormat, string $timezone, int $decimalPlaces, string $nullAs): string
    {
        if (is_null($value)) {
            return $nullAs;
        }

        if ($value instanceof \Carbon\Carbon) {
            return $value->setTimezone($timezone)->format($dateFormat);
        }

        if (is_numeric($value)) {
            return number_format($value, $decimalPlaces, '.', '');
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode(',', $value);
        }

        // Escape CSV special characters
        $value = (string) $value;
        
        if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
            $value = str_replace('"', '""', $value);
            return '"' . $value . '"';
        }

        return $value;
    }

    private function formatCsvRow(array $row): string
    {
        return implode(',', $row);
    }
}
