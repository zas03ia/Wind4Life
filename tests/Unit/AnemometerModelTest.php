<?php

/**
 * Mirrors the Django Anemometer.slug @property unit check.
 *
 * Django used django.utils.text.slugify; Laravel uses Str::slug which
 * produces the same lower-case hyphenated output for ASCII inputs.
 */

use App\Models\Anemometer;
use Illuminate\Support\Str;

it('exposes a slug accessor that slugifies the name', function (): void {
    $anemometer = Anemometer::factory()->make(['name' => 'Mistral Ridge Alpha']);

    expect($anemometer->slug)->toBe(Str::slug('Mistral Ridge Alpha'));
    expect($anemometer->slug)->toBe('mistral-ridge-alpha');
});

it('slug updates when name changes', function (): void {
    $anemometer = Anemometer::factory()->make(['name' => 'First']);
    expect($anemometer->slug)->toBe('first');

    $anemometer->name = 'Second Name';
    expect($anemometer->slug)->toBe('second-name');
});
