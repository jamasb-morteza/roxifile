<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laravel\Ai\Audio;
use Laravel\Ai\Prompts\AudioPrompt;
use RuntimeException;
use Tests\TestCase;

class TextToSpeechApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai_tts.api_token' => 'test-token',
            'services.ai_tts.model' => 'eleven_v3',
            'ai.providers.eleven.key' => 'fake-key',
        ]);
    }

    /** @return array<string, string> */
    private function headers(string $token = 'test-token'): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_missing_or_invalid_token_returns_401(): void
    {
        $this->postJson('/api/v1/ai/text-to-speech', ['text' => 'Hello'])->assertUnauthorized();
        $this->postJson('/api/v1/ai/text-to-speech', ['text' => 'Hello'], $this->headers('wrong'))->assertUnauthorized();

        config()->set('services.ai_tts.api_token', null);

        $this->postJson('/api/v1/ai/text-to-speech', ['text' => 'Hello'])->assertUnauthorized();
    }

    public function test_invalid_text_and_voice_return_422_without_generation(): void
    {
        Audio::fake()->preventStrayAudio();

        $this->postJson('/api/v1/ai/text-to-speech', ['text' => ''], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->postJson('/api/v1/ai/text-to-speech', ['text' => 'Hello', 'voice' => '../voice'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('voice');
        $this->postJson('/api/v1/ai/text-to-speech', ['text' => str_repeat('a', 3001)], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('text');

        Audio::assertNothingGenerated();
    }

    public function test_returns_binary_mp3_and_logs_metadata_without_text(): void
    {
        $bytes = "\xFF\xFB\x90\x64test-audio";
        Audio::fake([base64_encode($bytes)])->preventStrayAudio();

        $response = $this->postJson('/api/v1/ai/text-to-speech', [
            'text' => 'سلام دنیا',
            'voice' => 'Xb7hH8MSUJpSbSDYk0k2',
        ], $this->headers());

        $response->assertOk()->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Content-Disposition', 'inline; filename="speech.mp3"');
        $this->assertSame($bytes, $response->getContent());
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));

        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->provider->name() === 'eleven'
            && $prompt->voice === 'Xb7hH8MSUJpSbSDYk0k2'
            && $prompt->model === 'eleven_v3'
            && $prompt->text === 'سلام دنیا');
    }

    public function test_no_elevenlabs_key_returns_503(): void
    {
        config()->set('ai.providers.eleven.key', null);
        Audio::fake()->preventStrayAudio();

        $this->postJson('/api/v1/ai/text-to-speech', ['text' => 'Hello'], $this->headers())
            ->assertStatus(503);

        Audio::assertNothingGenerated();
    }

    public function test_upstream_failure_returns_502_without_exposing_details(): void
    {
        Audio::fake(function (): never {
            throw new RuntimeException('private error detail');
        })->preventStrayAudio();

        $this->postJson('/api/v1/ai/text-to-speech', ['text' => 'Hello'], $this->headers())
            ->assertStatus(502)->assertExactJson(['message' => 'Audio generation failed.']);
    }
}
