<?php

namespace App\Services\Export\Handlers;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Anemometer;

class AnemometerExportHandler
{
    public function buildQuery(array $filters): Builder
    {
        $query = Anemometer::withCount('readings');

        // Filter by IDs
        if (!empty($filters['ids'])) {
            $query->whereIn('anemometers.id', $filters['ids']);
        }

        // Filter by latitude range
        if (!empty($filters['numeric_ranges']['latitude'])) {
            $latRange = $filters['numeric_ranges']['latitude'];
            if (isset($latRange['min'])) {
                $query->where('anemometers.latitude', '>=', $latRange['min']);
            }
            if (isset($latRange['max'])) {
                $query->where('anemometers.latitude', '<=', $latRange['max']);
            }
        }

        // Filter by longitude range
        if (!empty($filters['numeric_ranges']['longitude'])) {
            $lonRange = $filters['numeric_ranges']['longitude'];
            if (isset($lonRange['min'])) {
                $query->where('anemometers.longitude', '>=', $lonRange['min']);
            }
            if (isset($lonRange['max'])) {
                $query->where('anemometers.longitude', '<=', $lonRange['max']);
            }
        }

        // Filter by name search
        if (!empty($filters['text_search']['name'])) {
            $query->where('anemometers.name', 'LIKE', '%' . $filters['text_search']['name'] . '%');
        }

        // Filter by whether anemometer has readings
        if (isset($filters['has_readings'])) {
            if ($filters['has_readings']) {
                $query->has('readings');
            } else {
                $query->doesntHave('readings');
            }
        }

        return $query;
    }

    public function getAvailableFields(): array
    {
        return [
            'id',
            'name',
            'latitude',
            'longitude',
            'created_at',
            'readings_count',
        ];
    }

    public function getDefaultFields(): array
    {
        return [
            'id',
            'name',
            'latitude',
            'longitude',
            'readings_count',
        ];
    }
}
