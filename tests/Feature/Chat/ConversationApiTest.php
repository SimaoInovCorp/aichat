<?php

use App\Models\Conversation;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('guests cannot access conversation list endpoint', function () {
    $response = $this->getJson(route('api.conversations.index'));

    $response->assertUnauthorized();
});

test('authenticated users can create and list only their own conversations', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    Conversation::query()->create([
        'user_id' => $otherUser->id,
        'title' => 'Other user conversation',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->actingAs($owner);

    $createResponse = $this->postJson(route('api.conversations.store'), [
        'title' => 'Owner conversation',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $createResponse->assertCreated();
    $createResponse->assertJsonPath('data.title', 'Owner conversation');

    $listResponse = $this->getJson(route('api.conversations.index'));

    $listResponse->assertOk();
    $listResponse->assertJsonCount(1, 'data');
    $listResponse->assertJsonPath('data.0.title', 'Owner conversation');
});

test('users cannot view conversations they do not own', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();

    $conversation = Conversation::query()->create([
        'user_id' => $owner->id,
        'title' => 'Private conversation',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->actingAs($attacker);

    $response = $this->getJson(route('api.conversations.show', $conversation));

    $response->assertForbidden();
});

test('authenticated users can send a message and it is persisted', function () {
    config()->set('services.openrouter.api_key', 'test-key');

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Testing messages',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->mock(OpenRouterService::class, function (MockInterface $mock) {
        $mock->shouldReceive('ensureApiKey');
        $mock->shouldReceive('buildChatPayload')->andReturn(['model' => 'openai/gpt-4o-mini', 'messages' => [], 'temperature' => 0.7, 'max_tokens' => 200]);
        $mock->shouldReceive('streamChatCompletion')
            ->andReturnUsing(function (array $payload, callable $onChunk): string {
                $onChunk('Response text.');
                return 'Response text.';
            });
    });

    $this->actingAs($user);

    $response = $this->post(route('api.conversations.messages.store', $conversation), [
        'content' => 'Hello assistant',
        'model' => 'openai/gpt-4o-mini',
        'temperature' => 0.7,
        'max_tokens' => 200,
    ]);

    $response->assertOk();

    ob_start();
    $response->baseResponse->sendContent();
    ob_end_clean();

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hello assistant',
        'model' => 'openai/gpt-4o-mini',
    ]);

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Response text.',
    ]);

    $this->assertDatabaseHas('conversations', [
        'id' => $conversation->id,
        'model' => 'openai/gpt-4o-mini',
    ]);
});
