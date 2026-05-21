<?php

/**
 * Port of the nested-reading viewset tests
 * (api:anemometers-readings-list / -detail in Django).
 */

use App\Models\Anemometer;

it('lists nested readings for an anemometer', function (): void {
    actingAsUser();
    $number = 5;
    $anemometer = Anemometer::factory()->withReadings($number)->create();

    $response = $this->getJson("/api/anemometers/{$anemometer->id}/readings");

    $response->assertOk();
    expect($response->json('results'))->toHaveCount($number);
});

it('returns a nested reading detail with id', function (): void {
    actingAsUser();
    $anemometer = Anemometer::factory()->withReadings(5)->create();
    $reading = $anemometer->readings()->first();

    $response = $this->getJson("/api/anemometers/{$anemometer->id}/readings/{$reading->id}");

    $response->assertOk();
    expect($response->json('id'))->toBe($reading->id);
});

it('returns 404 for nonexistent nested reading', function (): void {
    actingAsUser();
    $anemometer = Anemometer::factory()->withReadings(5)->create();

    $response = $this->getJson(
        "/api/anemometers/{$anemometer->id}/readings/".uuidInvalid()
    );

    $response->assertNotFound();
});
