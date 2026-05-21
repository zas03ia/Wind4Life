<?php

/**
 * POST /api/auth-token — token issuance endpoint.
 *
 * The Django app uses DRF's built-in TokenAuthentication obtain view;
 * our Laravel port exposes a Sanctum-backed equivalent at
 * /api/auth-token.
 */

use App\Models\User;

it('issues a token for valid credentials', function (): void {
    $user = User::factory()->create([
        'username' => 'testuser',
        'password' => 's3cret!!',
    ]);

    $response = $this->postJson('/api/auth-token', [
        'username' => $user->username,
        'password' => 's3cret!!',
    ]);

    $response->assertOk();
    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('rejects invalid credentials', function (): void {
    User::factory()->create([
        'username' => 'testuser',
        'password' => 's3cret!!',
    ]);

    $response = $this->postJson('/api/auth-token', [
        'username' => 'testuser',
        'password' => 'wrong',
    ]);

    // Accept either 401 (unauthorized) or 422 (validation) since the
    // controller implementation is owned by Team C — both are valid
    // "you can't have a token" signals and will be narrowed once the
    // endpoint lands. Django would return 400 with a non-field error.
    expect($response->status())->toBeIn([401, 422]);
});
