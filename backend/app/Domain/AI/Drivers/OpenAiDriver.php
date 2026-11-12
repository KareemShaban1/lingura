<?php

namespace App\Domain\AI\Drivers;

use App\Domain\AI\Contracts\AiDriver;
use App\Domain\AI\Data\AudioResult;
use App\Domain\AI\Data\EphemeralSession;
use App\Domain\AI\Data\RealtimeSessionRequest;
use App\Domain\AI\Data\SpeechRequest;
use App\Domain\AI\Data\TextRequest;
use App\Domain\AI\Data\TextResult;
use App\Domain\AI\Data\TranscriptionRequest;
use App\Domain\AI\Data\TranscriptionResult;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\AI\Exceptions\ProviderNotConfigured;
use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * OpenAI driver: Responses API (text + structured output), audio/speech, audio/transcriptions,
 * and realtime client secrets for browser WebRTC sessions. The API key never leaves this class.
 */
class OpenAiDriver implements AiDriver
{
    public function __construct(private readonly array $config) {}

    public function slug(): string
    {
        return 'openai';
    }

    public function modelFor(string $operation): string
    {
        return $this->config['models'][$operation] ?? throw new AiException("No OpenAI model configured for {$operation}.");
    }

    public function generate(TextRequest $request): TextResult
    {
        $model = $this->modelFor('text');
        $payload = [
            'model' => $model,
            'instructions' => $request->instructions,
            'input' => [...($request->history ?? []), ['role' => 'user', 'content' => $request->input]],
            'max_output_tokens' => $request->maxOutputTokens,
            'store' => false,
        ];
        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->jsonSchema !== null) {
            $payload['text'] = ['format' => [
                'type' => 'json_schema',
                'name' => $request->schemaName,
                'schema' => $request->jsonSchema,
                'strict' => true,
            ]];
        }

        $data = $this->send(fn (PendingRequest $http) => $http->post('/responses', $payload))->json();

        $text = '';
        foreach ($data['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text') {
                    $text .= $content['text'];
                }
            }
        }

        $json = null;
        if ($request->jsonSchema !== null) {
            try {
                $json = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new AiException('Provider returned invalid JSON for a structured request.', 'invalid_structured_output', $e);
            }
        }

        return new TextResult(
            text: $text,
            json: $json,
            model: $data['model'] ?? $model,
            inputTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['output_tokens'] ?? 0),
        );
    }

    public function synthesize(SpeechRequest $request): AudioResult
    {
        $model = $this->modelFor('tts');
        $payload = array_filter([
            'model' => $model,
            'voice' => $request->voice ?? $this->config['tts_voice'],
            'input' => $request->text,
            'instructions' => $request->instructions,
            'response_format' => $request->format,
        ]);
        $response = $this->send(fn (PendingRequest $http) => $http->post('/audio/speech', $payload));

        return new AudioResult(
            bytes: $response->body(),
            mimeType: $request->format === 'mp3' ? 'audio/mpeg' : 'audio/'.$request->format,
            extension: $request->format,
            model: $model,
            characters: mb_strlen($request->text),
        );
    }

    public function transcribe(TranscriptionRequest $request): TranscriptionResult
    {
        $model = $this->modelFor('stt');
        $response = $this->send(fn (PendingRequest $http) => $http
            ->attach('file', fopen($request->audioPath, 'r'), basename($request->audioPath))
            ->post('/audio/transcriptions', ['model' => $model, 'language' => $request->languageCode]));

        return new TranscriptionResult(
            text: (string) $response->json('text', ''),
            model: $model,
            audioSeconds: (int) ($request->durationSeconds ?? 0),
        );
    }

    public function issue(RealtimeSessionRequest $request): EphemeralSession
    {
        $model = $this->modelFor('realtime');
        $data = $this->send(fn (PendingRequest $http) => $http->post('/realtime/client_secrets', [
            'expires_after' => ['anchor' => 'created_at', 'seconds' => 60],
            'session' => [
                'type' => 'realtime',
                'model' => $model,
                'instructions' => $request->instructions,
                'audio' => ['output' => ['voice' => $request->voice ?? $this->config['tts_voice']]],
            ],
        ]))->json();

        return new EphemeralSession(
            clientSecret: (string) ($data['value'] ?? throw new AiException('Realtime secret missing from response.')),
            expiresAt: (new DateTimeImmutable)->setTimestamp((int) ($data['expires_at'] ?? time() + 60)),
            model: $model,
            provider: $this->slug(),
        );
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call): Response
    {
        if (empty($this->config['api_key'])) {
            throw ProviderNotConfigured::make('openai');
        }

        $http = Http::baseUrl($this->config['base_url'])
            ->withToken($this->config['api_key'])
            ->acceptJson()
            ->timeout($this->config['timeout'] ?? 60)
            ->retry(
                config('ai.retry.times', 2),
                config('ai.retry.sleep_ms', 400),
                fn ($e) => $e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true),
                throw: false,
            );

        $response = $call($http);
        if ($response->failed()) {
            $code = $response->json('error.code') ?? 'http_'.$response->status();
            throw new AiException('OpenAI request failed: '.($response->json('error.message') ?? $response->status()), (string) $code);
        }

        return $response;
    }
}
