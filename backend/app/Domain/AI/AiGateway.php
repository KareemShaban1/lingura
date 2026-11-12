<?php

namespace App\Domain\AI;

use App\Domain\AI\Data\AudioResult;
use App\Domain\AI\Data\EphemeralSession;
use App\Domain\AI\Data\RealtimeSessionRequest;
use App\Domain\AI\Data\SpeechRequest;
use App\Domain\AI\Data\TextRequest;
use App\Domain\AI\Data\TextResult;
use App\Domain\AI\Data\TranscriptionRequest;
use App\Domain\AI\Data\TranscriptionResult;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\AI\Exceptions\CapabilityUnavailable;
use App\Domain\Languages\CapabilityMatrix;
use Throwable;

/**
 * Single entry point for all AI operations. Domain services depend on this, never on a vendor SDK.
 * Responsibilities: capability check per language, provider routing, usage metering, error normalisation.
 * (Quota enforcement hooks in here in Phase 4 via entitlements.)
 */
class AiGateway
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly CapabilityMatrix $capabilities,
        private readonly UsageMeter $meter,
    ) {}

    public function text(TextRequest $request, ?string $provider = null): TextResult
    {
        $this->guard('content_generation', $request->languageCode);
        $driver = $this->manager->driver($provider);

        return $this->metered($driver->slug(), $driver->modelFor('text'), $request->operation, $request->userId, $request->learnerProfileId,
            fn () => $driver->generate($request),
            fn (TextResult $r) => ['input_tokens' => $r->inputTokens, 'output_tokens' => $r->outputTokens, 'model_slug' => $r->model]);
    }

    public function speech(SpeechRequest $request, ?string $provider = null): AudioResult
    {
        $this->guard('tts', $request->languageCode);
        $driver = $this->manager->driver($provider);

        return $this->metered($driver->slug(), $driver->modelFor('tts'), $request->operation, $request->userId, null,
            fn () => $driver->synthesize($request),
            fn (AudioResult $r) => ['characters' => $r->characters]);
    }

    public function transcribe(TranscriptionRequest $request, ?string $provider = null): TranscriptionResult
    {
        $this->guard('stt', $request->languageCode);
        $driver = $this->manager->driver($provider);

        return $this->metered($driver->slug(), $driver->modelFor('stt'), $request->operation, $request->userId, null,
            fn () => $driver->transcribe($request),
            fn (TranscriptionResult $r) => ['audio_seconds' => $r->audioSeconds]);
    }

    public function realtimeSession(RealtimeSessionRequest $request, ?string $provider = null): EphemeralSession
    {
        $this->guard('realtime_voice', $request->languageCode);
        $driver = $this->manager->driver($provider);

        return $this->metered($driver->slug(), $driver->modelFor('realtime'), 'realtime.session_issue', $request->userId, null,
            fn () => $driver->issue($request), fn () => []);
    }

    private function guard(string $feature, ?string $languageCode): void
    {
        if ($languageCode !== null && ! $this->capabilities->isUsable($languageCode, $feature)) {
            throw CapabilityUnavailable::for($feature, $languageCode);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @param  callable(T): array  $usage
     * @return T
     */
    private function metered(string $provider, string $model, string $operation, ?int $userId, ?int $profileId, callable $call, callable $usage): mixed
    {
        $start = hrtime(true);
        try {
            $result = $call();
        } catch (Throwable $e) {
            $this->meter->record($provider, $model, $operation, $userId, $profileId, [
                'status' => 'error',
                'error_code' => $e instanceof AiException ? $e->errorCode : class_basename($e),
                'latency_ms' => (int) ((hrtime(true) - $start) / 1e6),
            ]);
            throw $e instanceof AiException ? $e : new AiException($e->getMessage(), 'provider_error', $e);
        }

        $this->meter->record($provider, $model, $operation, $userId, $profileId, [
            'status' => 'success',
            'latency_ms' => (int) ((hrtime(true) - $start) / 1e6),
            ...$usage($result),
        ]);

        return $result;
    }
}
