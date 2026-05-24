<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Anemometer;
use App\Models\Reading;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Anemometer $anemometer;
    protected Reading $reading;
    protected Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'testuser',
            'email' => 'test@example.com',
        ]);

        $this->anemometer = Anemometer::factory()->create([
            'name' => 'Test Anemometer',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        $this->reading = Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 15.5,
            'recorded_at' => now()->subHours(2),
        ]);

        $this->tag = Tag::factory()->create(['name' => 'windy']);
        $this->reading->tags()->attach($this->tag->id);

        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_can_export_readings_as_json()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertArrayHasKey('metadata', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals(1, $data['metadata']['total_records']);
    }

    /** @test */
    public function it_can_export_readings_as_csv()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'csv',
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv');

        $content = $response->getContent();
        $lines = explode("\n", trim($content));

        $this->assertGreaterThan(1, count($lines)); // Header + data
        $this->assertStringContainsString('id,speed,recorded_at', $lines[0]); // Header
    }

    /** @test */
    public function it_can_export_anemometers()
    {
        $payload = [
            'resource' => 'anemometers',
            'format' => 'json',
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
        $this->assertEquals('Test Anemometer', $data['data'][0]['name']);
    }

    /** @test */
    public function it_can_export_users()
    {
        $payload = [
            'resource' => 'users',
            'format' => 'json',
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
        $this->assertEquals('testuser', $data['data'][0]['username']);
    }

    /** @test */
    public function it_can_filter_readings_by_date_range()
    {
        // Create another reading outside the date range
        Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 20.0,
            'recorded_at' => now()->subDays(10),
        ]);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'filters' => [
                'date_range' => [
                    'start' => now()->subDay()->toDateTimeString(),
                    'end' => now()->toDateTimeString(),
                ],
            ],
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
    }

    /** @test */
    public function it_can_filter_readings_by_tags()
    {
        // Create another reading with different tags
        $anotherReading = Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 12.0,
        ]);
        $anotherTag = Tag::factory()->create(['name' => 'calm']);
        $anotherReading->tags()->attach($anotherTag->id);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'filters' => [
                'tags' => [
                    'any' => ['windy'],
                ],
            ],
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
    }

    /** @test */
    public function it_can_filter_anemometers_by_location()
    {
        // Create another anemometer outside the location range
        Anemometer::factory()->create([
            'name' => 'Far Anemometer',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $payload = [
            'resource' => 'anemometers',
            'format' => 'json',
            'filters' => [
                'numeric_ranges' => [
                    'latitude' => ['min' => 30, 'max' => 50],
                    'longitude' => ['min' => -80, 'max' => -70],
                ],
            ],
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
        $this->assertEquals('Test Anemometer', $data['data'][0]['name']);
    }

    /** @test */
    public function it_can_validate_export_request()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'validate_only' => true,
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'valid' => true,
                    'estimated_records' => 1,
                ],
            ]);
    }

    /** @test */
    public function it_rejects_invalid_resource()
    {
        $payload = [
            'resource' => 'invalid',
            'format' => 'json',
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['resource']);
    }

    /** @test */
    public function it_rejects_invalid_format()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'invalid',
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['format']);
    }

    /** @test */
    public function it_requires_authentication()
    {
        Sanctum::actingAs(null);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(401);
    }

    /** @test */
    public function it_can_sort_results()
    {
        // Create multiple readings with different speeds
        Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 25.0,
            'recorded_at' => now()->subHour(),
        ]);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'sort' => [
                ['field' => 'speed', 'direction' => 'desc'],
            ],
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(2, $data['metadata']['total_records']);
        $this->assertGreaterThan($data['data'][1]['speed'], $data['data'][0]['speed']);
    }

    /** @test */
    public function it_can_limit_results()
    {
        // Create multiple readings
        Reading::factory()->count(5)->create([
            'anemometer_id' => $this->anemometer->id,
        ]);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'pagination' => [
                'limit' => 3,
            ],
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(3, $data['metadata']['total_records']);
    }
}
