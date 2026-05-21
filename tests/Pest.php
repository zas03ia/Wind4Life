<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case Bindings
|--------------------------------------------------------------------------
|
| Feature tests get the full Laravel TestCase and a fresh sqlite:memory
| schema per test via RefreshDatabase. Unit tests stay pure PHP unless
| they opt in individually (e.g. the Eloquent-backed model tests use
| ->in('Unit') below to also hit the DB).
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| actingAsUser()   — creates (or reuses) a user and authenticates it via
|                    Sanctum, mirroring Django's APIClient.force_authenticate.
|
| uuidInvalid()    — shared fixture value used across the 404/invalid-UUID
|                    tests (mirrors the Django literal of the same string).
*/

/**
 * Authenticate the test client as a (new or provided) user via Sanctum.
 */
function actingAsUser(?User $user = null): User
{
    $user ??= User::factory()->create();
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Canonical "looks valid but definitely not in the DB" UUID used by the
 * anemometer/reading 404 tests.
 */
function uuidInvalid(): string
{
    return 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
}
