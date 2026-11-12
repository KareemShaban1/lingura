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
use DateTimeImmutable;

/**
 * Deterministic driver for automated tests and offline development.
 * Responses can be queued with FakeDriver::queueText(); it never produces learner-facing content in production
 * because AppServiceProvider refuses to boot it outside local/testing.
 */
class FakeDriver implements AiDriver
{
    /** @var list<array|string> */
    private static array $queuedText = [];

    /** @var list<object> */
    public static array $requests = [];

    public static function queueText(array|string $response): void
    {
        self::$queuedText[] = $response;
    }

    /** @var list<string> */
    private static array $queuedTranscripts = [];

    public static function queueTranscript(string $text): void
    {
        self::$queuedTranscripts[] = $text;
    }

    public static function reset(): void
    {
        self::$queuedText = [];
        self::$queuedTranscripts = [];
        self::$requests = [];
    }

    /**
     * Builds a minimal, schema-valid placeholder so flows work without an API key in local development.
     * Strings are visibly fake ("[fake] …") so nobody mistakes them for real feedback.
     */
    public static function fakeFromSchema(array $schema, string $name = 'value'): mixed
    {
        $type = is_array($schema['type'] ?? null) ? $schema['type'][0] : ($schema['type'] ?? 'string');

        return match (true) {
            isset($schema['enum']) => $schema['enum'][0],
            $type === 'object' => collect($schema['properties'] ?? [])->map(fn ($p, $k) => self::fakeFromSchema($p, $k))->all(),
            $type === 'array' => [],
            $type === 'integer' => (int) ($schema['minimum'] ?? 1),
            $type === 'number' => (float) ($schema['minimum'] ?? 1),
            $type === 'boolean' => false,
            $type === 'null' => null,
            default => "[fake] {$name}",
        };
    }

    public function slug(): string
    {
        return 'fake';
    }

    public function modelFor(string $operation): string
    {
        return 'fake-'.$operation;
    }

    public function generate(TextRequest $request): TextResult
    {
        self::$requests[] = $request;
        $next = array_shift(self::$queuedText)
            ?? ($request->jsonSchema ? self::fakeFromSchema($request->jsonSchema) : '[fake] reply');
        $text = is_array($next) ? json_encode($next, JSON_UNESCAPED_UNICODE) : $next;

        return new TextResult($text, is_array($next) ? $next : null, 'fake-text', mb_strlen($request->input), mb_strlen($text));
    }

    public function synthesize(SpeechRequest $request): AudioResult
    {
        self::$requests[] = $request;

        return new AudioResult('FAKEAUDIO:'.md5($request->text), 'audio/mpeg', 'mp3', 'fake-tts', mb_strlen($request->text));
    }

    public function transcribe(TranscriptionRequest $request): TranscriptionResult
    {
        self::$requests[] = $request;

        return new TranscriptionResult(array_shift(self::$queuedTranscripts) ?? '[fake] transcript', 'fake-stt', $request->durationSeconds ?? 0);
    }

    public function issue(RealtimeSessionRequest $request): EphemeralSession
    {
        self::$requests[] = $request;

        return new EphemeralSession('ek_fake_'.bin2hex(random_bytes(8)), new DateTimeImmutable('+60 seconds'), 'fake-realtime', 'fake');
    }
}
