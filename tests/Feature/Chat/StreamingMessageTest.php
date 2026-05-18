<?php

use App\Models\Conversation;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('message endpoint rejects empty content', function () {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Test',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->actingAs($user);

    $response = $this->postJson(route('api.conversations.messages.store', $conversation), [
        'content' => '',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['content']);
});

test('message endpoint rejects temperature above 2', function () {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Test',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->actingAs($user);

    $response = $this->postJson(route('api.conversations.messages.store', $conversation), [
        'content' => 'Hello',
        'model' => 'openai/gpt-4o-mini',
        'temperature' => 2.5,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['temperature']);
});

test('message endpoint rejects zero or negative max_tokens', function () {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Test',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->actingAs($user);

    $response = $this->postJson(route('api.conversations.messages.store', $conversation), [
        'content' => 'Hello',
        'model' => 'openai/gpt-4o-mini',
        'max_tokens' => 0,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['max_tokens']);
});

// ---------------------------------------------------------------------------
// Pre-flight checks
// ---------------------------------------------------------------------------

test('message endpoint returns 503 when api key is not configured', function () {
    config()->set('services.openrouter.api_key', null);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Test',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->actingAs($user);

    $response = $this->postJson(route('api.conversations.messages.store', $conversation), [
        'content' => 'Hello',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $response->assertStatus(503);
    $response->assertJsonPath('message', 'OPENROUTER_API_KEY is not configured.');
});

test('guests cannot send messages', function () {
    $owner = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $owner->id,
        'title' => 'Test',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $response = $this->postJson(route('api.conversations.messages.store', $conversation), [
        'content' => 'Hello',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $response->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Streaming happy path
// ---------------------------------------------------------------------------

test('streaming endpoint stores user message saves assistant message and returns sse headers', function () {
    config()->set('services.openrouter.api_key', 'test-key');

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Test',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->mock(OpenRouterService::class, function (MockInterface $mock) {
        $mock->shouldReceive('ensureApiKey')->once();
        $mock->shouldReceive('buildChatPayload')
            ->once()
            ->andReturn(['model' => 'openai/gpt-4o-mini', 'messages' => [['role' => 'user', 'content' => 'Tell me a joke']], 'temperature' => 0.7, 'max_tokens' => 512]);
        $mock->shouldReceive('streamChatCompletion')
            ->once()
            ->andReturnUsing(function (array $payload, callable $onChunk): string {
                $onChunk('Why did the ');
                $onChunk('chicken cross the road?');
                return 'Why did the chicken cross the road?';
            });
    });

    $this->actingAs($user);

    $response = $this->post(
        route('api.conversations.messages.store', $conversation),
        [
            'content' => 'Tell me a joke',
            'model' => 'openai/gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 512,
        ]
    );

    $response->assertOk();
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);
    // Content-Type must start with text/event-stream (framework may append ; charset=utf-8).
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    // Force stream execution to trigger DB writes.
    ob_start();
    $response->baseResponse->sendContent();
    ob_end_clean();

    // User message persisted before stream.
    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Tell me a joke',
        'model' => 'openai/gpt-4o-mini',
        'status' => 'queued',
    ]);

    // Assistant message persisted after stream completes.
    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Why did the chicken cross the road?',
        'model' => 'openai/gpt-4o-mini',
        'status' => 'complete',
    ]);
});

// ---------------------------------------------------------------------------
// Auto-title on first message
// ---------------------------------------------------------------------------

test('first message auto-generates conversation title', function () {
    config()->set('services.openrouter.api_key', 'test-key');

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'New conversation',
        'model' => null,
    ]);

    $this->mock(OpenRouterService::class, function (MockInterface $mock) {
        $mock->shouldReceive('ensureApiKey');
        $mock->shouldReceive('buildChatPayload')->andReturn(['model' => 'openai/gpt-4o-mini', 'messages' => [], 'temperature' => 0.7, 'max_tokens' => 1024]);
        $mock->shouldReceive('streamChatCompletion')
            ->andReturnUsing(function (array $payload, callable $onChunk): string {
                $onChunk('Paris.');
                return 'Paris.';
            });
    });

    $this->actingAs($user);

    $response = $this->post(
        route('api.conversations.messages.store', $conversation),
        [
            'content' => 'What is the capital of France?',
            'model' => 'openai/gpt-4o-mini',
        ]
    );

    ob_start();
    $response->baseResponse->sendContent();
    ob_end_clean();

    $conversation->refresh();

    expect($conversation->title)->toBe('What is the capital of France?');
});

test('title is truncated at 60 chars with ellipsis when message is too long', function () {
    config()->set('services.openrouter.api_key', 'test-key');

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'New conversation',
        'model' => null,
    ]);

    $this->mock(OpenRouterService::class, function (MockInterface $mock) {
        $mock->shouldReceive('ensureApiKey');
        $mock->shouldReceive('buildChatPayload')->andReturn(['model' => 'openai/gpt-4o-mini', 'messages' => [], 'temperature' => 0.7, 'max_tokens' => 1024]);
        $mock->shouldReceive('streamChatCompletion')
            ->andReturnUsing(function (array $payload, callable $onChunk): string {
                $onChunk('Done.');
                return 'Done.';
            });
    });

    $this->actingAs($user);
    $longMessage = str_repeat('A', 80);

    $response = $this->post(
        route('api.conversations.messages.store', $conversation),
        [
            'content' => $longMessage,
            'model' => 'openai/gpt-4o-mini',
        ]
    );

    ob_start();
    $response->baseResponse->sendContent();
    ob_end_clean();

    $conversation->refresh();

    expect($conversation->title)->toHaveLength(60);
    expect($conversation->title)->toEndWith('...');
});

test('subsequent messages do not overwrite the conversation title', function () {
    config()->set('services.openrouter.api_key', 'test-key');

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Already set title',
        'model' => 'openai/gpt-4o-mini',
    ]);

    // Add a prior message so this is NOT the first one.
    $conversation->messages()->create([
        'role' => 'user',
        'content' => 'First message',
        'model' => 'openai/gpt-4o-mini',
        'status' => 'queued',
    ]);

    $this->mock(OpenRouterService::class, function (MockInterface $mock) {
        $mock->shouldReceive('ensureApiKey');
        $mock->shouldReceive('buildChatPayload')->andReturn(['model' => 'openai/gpt-4o-mini', 'messages' => [], 'temperature' => 0.7, 'max_tokens' => 1024]);
        $mock->shouldReceive('streamChatCompletion')
            ->andReturnUsing(function (array $payload, callable $onChunk): string {
                $onChunk('Reply.');
                return 'Reply.';
            });
    });

    $this->actingAs($user);

    $response = $this->post(
        route('api.conversations.messages.store', $conversation),
        [
            'content' => 'Follow-up question that should not replace title',
            'model' => 'openai/gpt-4o-mini',
        ]
    );

    ob_start();
    $response->baseResponse->sendContent();
    ob_end_clean();

    $conversation->refresh();

    expect($conversation->title)->toBe('Already set title');
});

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------

test('stream emits error event and marks user message as error when openrouter fails', function () {
    config()->set('services.openrouter.api_key', 'test-key');

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Test',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->mock(OpenRouterService::class, function (MockInterface $mock) {
        $mock->shouldReceive('ensureApiKey');
        $mock->shouldReceive('buildChatPayload')->andReturn(['model' => 'openai/gpt-4o-mini', 'messages' => [], 'temperature' => 0.7, 'max_tokens' => 1024]);
        $mock->shouldReceive('streamChatCompletion')
            ->andThrow(new RuntimeException('OpenRouter API key is invalid.'));
    });

    $this->actingAs($user);

    $response = $this->post(
        route('api.conversations.messages.store', $conversation),
        [
            'content' => 'Hello',
            'model' => 'openai/gpt-4o-mini',
        ]
    );

    // Capture stream output to inspect error event.
    ob_start();
    $response->baseResponse->sendContent();
    $output = ob_get_clean();

    // SSE output must contain an error event.
    expect($output)->toContain('"type":"error"');
    expect($output)->toContain('OpenRouter API key is invalid.');

    // User message should be marked as errored.
    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'status' => 'error',
    ]);

    // No assistant message should be created.
    $this->assertDatabaseMissing('messages', [
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
    ]);
});
