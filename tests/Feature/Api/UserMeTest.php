<?php

/**
 * Port of wind_for_life/apps/users/tests/api/test_views.py
 * (the "user-me" test) and test_urls.py (the URL resolution assertions
 * — we verify route registration by hitting the URL directly since
 * Laravel has no direct `reverse()/resolve()` analog that ships with
 * Pest out of the box).
 *
 * The original Django test inspects AbstractUser-specific fields
 * (username + URL hyperlink). Our Laravel user keeps `username` but
 * does not expose a hyperlinked URL — we just assert the current
 * user's identifying fields are returned. This is the simplification
 * the instructions call out.
 */

use App\Models\User;

it('returns the authenticated user for /api/users/me', function (): void {
    $user = User::factory()->create([
        'username' => 'testuser',
        'name' => 'Test User',
    ]);
    actingAsUser($user);

    $response = $this->getJson('/api/users/me');

    $response->assertOk();
    expect($response->json('username'))->toBe('testuser');
    expect($response->json('name'))->toBe('Test User');
});

it('rejects unauthenticated access to /api/users/me', function (): void {
    $response = $this->getJson('/api/users/me');

    $response->assertStatus(401);
});

it('registers the user list route', function (): void {
    actingAsUser();

    $response = $this->getJson('/api/users');

    $response->assertOk();
});
