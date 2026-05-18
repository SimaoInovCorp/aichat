<?php

use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('includes referer and title headers when listing models', function () {
    config()->set('services.openrouter.api_key', 'test-key');
    config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
    config()->set('services.openrouter.referer', 'http://localhost:8000');
    config()->set('services.openrouter.title', 'Ai Chat App');

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                ['id' => 'openai/gpt-4o-mini'],
            ],
        ], 200),
    ]);

    $service = app(OpenRouterService::class);
    $result = $service->listModels();

    expect($result['data'][0]['id'])->toBe('openai/gpt-4o-mini');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://openrouter.ai/api/v1/models'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->hasHeader('HTTP-Referer', 'http://localhost:8000')
            && $request->hasHeader('X-Title', 'Ai Chat App');
    });
});

it('adds stream flag and attribution headers for chat completion streaming', function () {
    config()->set('services.openrouter.api_key', 'test-key');
    config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
    config()->set('services.openrouter.referer', 'http://localhost:8000');
    config()->set('services.openrouter.title', 'Ai Chat App');

    $sseBody = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n";
    $sseBody .= "data: [DONE]\n\n";

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response($sseBody, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $service = app(OpenRouterService::class);
    $payload = [
        'model' => 'openai/gpt-4o-mini',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
        'temperature' => 0.7,
        'max_tokens' => 128,
    ];

    $chunks = [];
    $assembled = $service->streamChatCompletion($payload, function (string $chunk) use (&$chunks): void {
        $chunks[] = $chunk;
    });

    expect($assembled)->toBe('Hello');
    expect($chunks)->toBe(['Hello']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->hasHeader('HTTP-Referer', 'http://localhost:8000')
            && $request->hasHeader('X-Title', 'Ai Chat App')
            && ($request['stream'] ?? false) === true;
    });
});

it('omits referer and title headers when they are empty', function () {
    config()->set('services.openrouter.api_key', 'test-key');
    config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
    config()->set('services.openrouter.referer', '   ');
    config()->set('services.openrouter.title', null);

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(['data' => []], 200),
    ]);

    $service = app(OpenRouterService::class);
    $service->listModels();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://openrouter.ai/api/v1/models'
            && ! $request->hasHeader('HTTP-Referer')
            && ! $request->hasHeader('X-Title');
    });
});
