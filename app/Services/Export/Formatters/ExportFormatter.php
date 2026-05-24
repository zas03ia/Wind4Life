<?php

namespace App\Services\Export\Formatters;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;

interface ExportFormatter
{
    public function format(Collection $data, array $options = []): string;
    public function getMimeType(): string;
    public function getFileExtension(): string;
    public function getHeaders(array $fields): array;
}
