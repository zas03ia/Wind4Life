<?php

namespace App\Services\Export\Handlers;

use Illuminate\Database\Eloquent\Builder;
use App\Models\User;

class UserExportHandler
{
    public function buildQuery(array $filters): Builder
    {
        $query = User::query();

        // Filter by IDs
        if (!empty($filters['ids'])) {
            $query->whereIn('users.id', $filters['ids']);
        }

        // Filter by username search
        if (!empty($filters['text_search']['username'])) {
            $query->where('users.username', 'LIKE', '%' . $filters['text_search']['username'] . '%');
        }

        // Filter by email search
        if (!empty($filters['text_search']['email'])) {
            $query->where('users.email', 'LIKE', '%' . $filters['text_search']['email'] . '%');
        }

        // Filter by staff status
        if (isset($filters['is_staff'])) {
            $query->where('users.is_staff', $filters['is_staff']);
        }

        // Filter by superuser status
        if (isset($filters['is_superuser'])) {
            $query->where('users.is_superuser', $filters['is_superuser']);
        }

        return $query;
    }

    public function getAvailableFields(): array
    {
        return [
            'id',
            'username',
            'email',
            'name',
            'created_at',
            'is_staff',
            'is_superuser',
            'email_verified_at',
        ];
    }

    public function getDefaultFields(): array
    {
        return [
            'id',
            'username',
            'email',
            'name',
            'is_staff',
            'is_superuser',
        ];
    }
}
