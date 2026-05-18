<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('models endpoint returns service unavailable when api key is missing', function () {
    config()->set('services.openrouter.api_key', null);

    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson(route('api.models.index'));

    $response->assertStatus(503);
    $response->assertJsonPath('message', 'OPENROUTER_API_KEY is not configured.');
});

test('models endpoint proxies model data from openrouter', function () {
    config()->set('services.openrouter.api_key', 'test-key');
    config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
    config()->set('services.openrouter.referer', 'http://localhost:8000');
    config()->set('services.openrouter.title', 'Ai Chat App');

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                ['id' => 'openai/gpt-4o-mini', 'name' => 'GPT-4o mini'],
            ],
        ], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson(route('api.models.index'));

    $response->assertOk();
    $response->assertJsonPath('data.data.0.id', 'openai/gpt-4o-mini');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://openrouter.ai/api/v1/models'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->hasHeader('HTTP-Referer', 'http://localhost:8000')
            && $request->hasHeader('X-Title', 'Ai Chat App');
    });
});
