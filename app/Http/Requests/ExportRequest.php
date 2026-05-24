<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resource' => ['required', 'in:readings,anemometers,users'],
            'format' => ['required', 'in:json,csv'],
            'filters' => ['array'],
            'filters.ids' => ['array'],
            'filters.ids.*' => ['uuid'],
            'filters.date_range' => ['array'],
            'filters.date_range.start' => ['date'],
            'filters.date_range.end' => ['date'],
            'filters.tags' => ['array'],
            'filters.tags.any' => ['array'],
            'filters.tags.any.*' => ['string'],
            'filters.tags.exact' => ['array'],
            'filters.tags.exact.*' => ['string'],
            'filters.numeric_ranges' => ['array'],
            'filters.numeric_ranges.speed' => ['array'],
            'filters.numeric_ranges.speed.min' => ['numeric'],
            'filters.numeric_ranges.speed.max' => ['numeric'],
            'filters.numeric_ranges.latitude' => ['array'],
            'filters.numeric_ranges.latitude.min' => ['numeric', 'between:-90,90'],
            'filters.numeric_ranges.latitude.max' => ['numeric', 'between:-90,90'],
            'filters.numeric_ranges.longitude' => ['array'],
            'filters.numeric_ranges.longitude.min' => ['numeric', 'between:-180,180'],
            'filters.numeric_ranges.longitude.max' => ['numeric', 'between:-180,180'],
            'filters.text_search' => ['array'],
            'filters.text_search.name' => ['string'],
            'filters.text_search.username' => ['string'],
            'filters.text_search.email' => ['string'],
            'fields' => ['array'],
            'fields.*' => ['string'],
            'sort' => ['array'],
            'sort.*.field' => ['string'],
            'sort.*.direction' => ['in:asc,desc'],
            'pagination' => ['array'],
            'pagination.limit' => ['integer', 'min:1', 'max:50000'],
            'pagination.offset' => ['integer', 'min:0'],
            'options' => ['array'],
            'options.include_headers' => ['boolean'],
            'options.date_format' => ['string'],
            'options.timezone' => ['string', 'timezone'],
            'options.decimal_places' => ['integer', 'min:0', 'max:10'],
            'options.null_as' => ['string'],
            'async' => ['boolean'],
            'validate_only' => ['boolean'],
        ];
    }

    public function getFilters(): array
    {
        return $this->input('filters', []);
    }

    public function getFields(): array
    {
        return $this->input('fields', []);
    }

    public function getSort(): array
    {
        return $this->input('sort', []);
    }

    public function getPagination(): array
    {
        return array_merge([
            'limit' => 10000,
            'offset' => 0,
        ], $this->input('pagination', []));
    }

    public function getOptions(): array
    {
        return array_merge([
            'include_headers' => true,
            'date_format' => 'Y-m-d H:i:s',
            'timezone' => 'UTC',
            'decimal_places' => 2,
            'null_as' => '',
        ], $this->input('options', []));
    }

    public function isAsync(): bool
    {
        return $this->boolean('async', false);
    }

    public function isValidateOnly(): bool
    {
        return $this->boolean('validate_only', false);
    }
}
