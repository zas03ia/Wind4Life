<?php

namespace App\Services\Export\Handlers;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Reading;

class ReadingExportHandler
{
    public function buildQuery(array $filters): Builder
    {
        $query = Reading::with(['anemometer', 'tags']);

        // Filter by IDs
        if (!empty($filters['ids'])) {
            $query->whereIn('readings.id', $filters['ids']);
        }

        // Filter by date range
        if (!empty($filters['date_range'])) {
            $startDate = $filters['date_range']['start'] ?? null;
            $endDate = $filters['date_range']['end'] ?? null;
            
            if ($startDate) {
                $query->where('readings.recorded_at', '>=', $startDate);
            }
            if ($endDate) {
                $query->where('readings.recorded_at', '<=', $endDate);
            }
        }

        // Filter by tags
        if (!empty($filters['tags'])) {
            if (!empty($filters['tags']['any'])) {
                $query->whereHas('tags', function ($q) use ($filters) {
                    $q->whereIn('tags.name', $filters['tags']['any']);
                });
            }
            
            if (!empty($filters['tags']['exact'])) {
                $query->whereHas('tags', function ($q) use ($filters) {
                    $q->whereIn('tags.name', $filters['tags']['exact']);
                }, '=', count($filters['tags']['exact']));
            }
        }

        // Filter by speed range
        if (!empty($filters['numeric_ranges']['speed'])) {
            $speedRange = $filters['numeric_ranges']['speed'];
            if (isset($speedRange['min'])) {
                $query->where('readings.speed', '>=', $speedRange['min']);
            }
            if (isset($speedRange['max'])) {
                $query->where('readings.speed', '<=', $speedRange['max']);
            }
        }

        // Filter by anemometer IDs (if provided in tags context)
        if (!empty($filters['anemometer_ids'])) {
            $query->whereIn('readings.anemometer_id', $filters['anemometer_ids']);
        }

        return $query;
    }

    public function getAvailableFields(): array
    {
        return [
            'id',
            'speed',
            'recorded_at',
            'created_at',
            'anemometer.id',
            'anemometer.name',
            'anemometer.latitude',
            'anemometer.longitude',
            'tags.name',
        ];
    }

    public function getDefaultFields(): array
    {
        return [
            'id',
            'speed',
            'recorded_at',
            'anemometer.name',
            'tags.name',
        ];
    }
}
