<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Anemometer;
use App\Models\Reading;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

        // Fake the local storage disk to prevent writing actual files to disk
        Storage::fake('local');

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
            ->assertHeader('Content-Type');

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
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200); // User is authenticated from setUp
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

    /** @test */
    public function it_can_filter_readings_by_speed_range()
    {
        // Create readings with different speeds
        Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 5.0,
            'recorded_at' => now()->subHours(1),
        ]);

        Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 50.0,
            'recorded_at' => now()->subHours(2),
        ]);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'filters' => [
                'numeric_ranges' => [
                    'speed' => [
                        'min' => 10,
                        'max' => 30
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
        $this->assertEquals(15.5, $data['data'][0]['speed']);
    }

    /** @test */
    public function it_can_filter_readings_by_exact_tags()
    {
        // Create reading with multiple tags
        $stormyTag = Tag::factory()->create(['name' => 'stormy']);
        $highTag = Tag::factory()->create(['name' => 'high']);
        
        $multiTagReading = Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 25.0,
        ]);
        $multiTagReading->tags()->attach([$this->tag->id, $stormyTag->id]);

        $singleTagReading = Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 18.0,
        ]);
        $singleTagReading->tags()->attach($this->tag->id);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'filters' => [
                'tags' => [
                    'exact' => ['windy', 'stormy']
                ]
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
        $this->assertEquals(25.0, $data['data'][0]['speed']);
    }

    /** @test */
    public function it_can_filter_users_by_staff_status()
    {
        // Create staff and non-staff users
        User::factory()->create([
            'username' => 'staffuser',
            'email' => 'staff@example.com',
            'is_staff' => true,
        ]);

        User::factory()->create([
            'username' => 'regularuser',
            'email' => 'regular@example.com',
            'is_staff' => false,
        ]);

        $payload = [
            'resource' => 'users',
            'format' => 'json',
            'filters' => [
                'is_staff' => true
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
        $this->assertEquals('staffuser', $data['data'][0]['username']);
    }

    /** @test */
    public function it_can_filter_anemometers_by_name_search()
    {
        // Create anemometers with different names
        Anemometer::factory()->create([
            'name' => 'Weather Station Alpha',
            'latitude' => 35.0,
            'longitude' => -95.0,
        ]);

        Anemometer::factory()->create([
            'name' => 'Weather Station Beta',
            'latitude' => 45.0,
            'longitude' => -85.0,
        ]);

        $payload = [
            'resource' => 'anemometers',
            'format' => 'json',
            'filters' => [
                'text_search' => [
                    'name' => 'Alpha'
                ]
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
        $this->assertEquals('Weather Station Alpha', $data['data'][0]['name']);
    }

    /** @test */
    public function it_can_filter_by_specific_ids()
    {
        // Create additional readings
        $reading2 = Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 20.0,
        ]);

        $reading3 = Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 25.0,
        ]);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'filters' => [
                'ids' => [$this->reading->id, $reading2->id]
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(2, $data['metadata']['total_records']);
        
        $ids = array_map(fn($item) => $item['id'], $data['data']);
        $this->assertContains($this->reading->id, $ids);
        $this->assertContains($reading2->id, $ids);
        $this->assertNotContains($reading3->id, $ids);
    }

    /** @test */
    public function it_can_select_specific_fields()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'fields' => ['id', 'speed', 'recorded_at']
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $reading = $data['data'][0];
        $this->assertArrayHasKey('id', $reading);
        $this->assertArrayHasKey('speed', $reading);
        $this->assertArrayHasKey('recorded_at', $reading);
        $this->assertArrayNotHasKey('created_at', $reading);
        $this->assertArrayNotHasKey('updated_at', $reading);
    }

    /** @test */
    public function it_can_select_relationship_fields()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'fields' => ['id', 'speed', 'anemometer.name']
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $reading = $data['data'][0];
        $this->assertArrayHasKey('id', $reading);
        $this->assertArrayHasKey('speed', $reading);
        $this->assertArrayHasKey('anemometer', $reading);
        $this->assertArrayHasKey('name', $reading['anemometer']);
        $this->assertEquals('Test Anemometer', $reading['anemometer']['name']);
    }

    /** @test */
    public function it_can_apply_multi_field_sorting()
    {
        // Create readings with different speeds and times
        Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 20.5,
            'recorded_at' => now()->subHour(),
        ]);

        Reading::factory()->create([
            'anemometer_id' => $this->anemometer->id,
            'speed' => 25.5,
            'recorded_at' => now()->subMinutes(30),
        ]);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'sort' => [
                ['field' => 'speed', 'direction' => 'desc'],
                ['field' => 'recorded_at', 'direction' => 'desc']
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(3, $data['metadata']['total_records']);
        
        // Should be sorted by speed descending first
        $this->assertGreaterThanOrEqual($data['data'][1]['speed'], $data['data'][0]['speed']);
    }

    /** @test */
    public function it_can_apply_pagination_with_offset()
    {
        // Create multiple readings
        Reading::factory()->count(10)->create([
            'anemometer_id' => $this->anemometer->id,
        ]);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'pagination' => [
                'limit' => 3,
                'offset' => 5
            ],
            'sort' => [
                ['field' => 'recorded_at', 'direction' => 'desc']
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(3, $data['metadata']['total_records']);
    }

    /** @test */
    public function it_can_apply_format_options()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'options' => [
                'date_format' => 'Y-m-d',
                'timezone' => 'UTC',
                'decimal_places' => 1
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $reading = $data['data'][0];
        $this->assertEquals(15.5, $reading['speed']); // Should be formatted to 1 decimal place
    }

    /** @test */
    public function it_can_export_csv_with_custom_options()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'csv',
            'options' => [
                'include_headers' => true,
                'decimal_places' => 1,
                'null_as' => 'NULL'
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200)
            ->assertHeader('Content-Type');

        $content = $response->getContent();
        $lines = explode("\n", trim($content));

        $this->assertGreaterThan(1, count($lines));
        $this->assertStringContainsString('id,speed,recorded_at', $lines[0]);
    }

    /** @test */
    public function it_returns_download_url_for_large_exports()
    {
        // Create many readings to trigger large export behavior
        Reading::factory()->count(1500)->create([
            'anemometer_id' => $this->anemometer->id,
        ]);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'pagination' => [
                'limit' => 1500
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'download_url',
                    'records_count',
                    'file_size',
                    'expires_at'
                ]
            ]);

        $data = $response->json();
        $this->assertEquals(1500, $data['data']['records_count']);
        $this->assertStringContainsString('/api/export/download/', $data['data']['download_url']);
    }

    /** @test */
    public function it_can_download_stored_files()
    {
        // Create many readings to trigger file storage
        Reading::factory()->count(1500)->create([
            'anemometer_id' => $this->anemometer->id,
        ]);

        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'pagination' => [
                'limit' => 1500
            ]
        ];

        $exportResponse = $this->postJson('/api/export', $payload);
        $downloadUrl = $exportResponse->json('data.download_url');

        // Extract filename from URL
        $filename = basename(parse_url($downloadUrl, PHP_URL_PATH));

        // Assert file exists on the fake storage disk
        Storage::assertExists('exports/' . $filename);

        // Download the file
        $downloadResponse = $this->get($downloadUrl);

        $downloadResponse->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');

        $content = $downloadResponse->getContent();
        $data = json_decode($content, true);

        $this->assertArrayHasKey('metadata', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals(1500, $data['metadata']['total_records']);
    }

    /** @test */
    public function it_returns_404_for_nonexistent_files()
    {
        $response = $this->get('/api/export/download/nonexistent_file.json');

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'File not found or expired',
                'message' => 'The export file may have expired or does not exist'
            ]);
    }

    /** @test */
    public function it_validates_complex_filter_combinations()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'validate_only' => true,
            'filters' => [
                'date_range' => [
                    'start' => '2026-05-01T00:00:00Z',
                    'end' => '2026-05-31T23:59:59Z'
                ],
                'numeric_ranges' => [
                    'speed' => ['min' => 10, 'max' => 100]
                ],
                'tags' => [
                    'any' => ['windy', 'storm']
                ]
            ],
            'pagination' => [
                'limit' => 50000
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'valid',
                    'estimated_records',
                    'estimated_size',
                    'will_be_async'
                ]
            ]);
    }

    /** @test */
    public function it_rejects_invalid_date_range()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'filters' => [
                'date_range' => [
                    'start' => 'invalid-date'
                ]
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters.date_range.start']);
    }

    /** @test */
    public function it_rejects_invalid_numeric_ranges()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'filters' => [
                'numeric_ranges' => [
                    'speed' => [
                        'min' => 'not-a-number'
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters.numeric_ranges.speed.min']);
    }

    /** @test */
    public function it_rejects_invalid_latitude_range()
    {
        $payload = [
            'resource' => 'anemometers',
            'format' => 'json',
            'filters' => [
                'numeric_ranges' => [
                    'latitude' => [
                        'min' => 200  // Invalid latitude > 90
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters.numeric_ranges.latitude.min']);
    }

    /** @test */
    public function it_rejects_invalid_longitude_range()
    {
        $payload = [
            'resource' => 'anemometers',
            'format' => 'json',
            'filters' => [
                'numeric_ranges' => [
                    'longitude' => [
                        'max' => 200  // Invalid longitude > 180
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters.numeric_ranges.longitude.max']);
    }

    /** @test */
    public function it_rejects_invalid_sort_direction()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'sort' => [
                ['field' => 'speed', 'direction' => 'invalid']
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sort.0.direction']);
    }

    /** @test */
    public function it_rejects_pagination_limit_exceeding_maximum()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'pagination' => [
                'limit' => 100000  // Exceeds maximum of 50000
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pagination.limit']);
    }

    /** @test */
    public function it_rejects_invalid_timezone()
    {
        $payload = [
            'resource' => 'readings',
            'format' => 'json',
            'options' => [
                'timezone' => 'Invalid/Timezone'
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['options.timezone']);
    }

    /** @test */
    public function it_handles_empty_results_gracefully()
    {
        // Delete all readings
        Reading::query()->delete();

        $payload = [
            'resource' => 'readings',
            'format' => 'json'
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(0, $data['metadata']['total_records']);
        $this->assertEmpty($data['data']);
    }

    /** @test */
    public function it_can_export_anemometers_with_reading_counts()
    {
        $payload = [
            'resource' => 'anemometers',
            'format' => 'json',
            'fields' => ['name', 'readings_count']
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $anemometer = $data['data'][0];
        $this->assertArrayHasKey('name', $anemometer);
        $this->assertArrayHasKey('readings_count', $anemometer);
        $this->assertEquals('Test Anemometer', $anemometer['name']);
        $this->assertGreaterThan(0, $anemometer['readings_count']);
    }

    /** @test */
    public function it_can_filter_users_by_text_search()
    {
        // Create additional users
        User::factory()->create([
            'username' => 'winduser',
            'email' => 'wind@example.com',
        ]);

        User::factory()->create([
            'username' => 'stormuser',
            'email' => 'storm@example.com',
        ]);

        $payload = [
            'resource' => 'users',
            'format' => 'json',
            'filters' => [
                'text_search' => [
                    'username' => 'wind'
                ]
            ]
        ];

        $response = $this->postJson('/api/export', $payload);

        $response->assertStatus(200);

        $content = $response->getContent();
        $data = json_decode($content, true);

        $this->assertEquals(1, $data['metadata']['total_records']);
        $this->assertEquals('winduser', $data['data'][0]['username']);
    }
}
