<?php

namespace App\Domain\Speech;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\TranscriptionRequest;
use App\Models\AudioRecording;
use App\Models\SpeechTranscription;

class TranscriptionService
{
    public function __construct(private readonly AiGateway $ai, private readonly AudioService $audio) {}

    public function transcribe(AudioRecording $recording, string $languageCode, string $operation): SpeechTranscription
    {
        if ($existing = $recording->transcription) {
            return $existing;
        }
        $path = $this->audio->localCopy($recording);
        $result = $this->ai->transcribe(new TranscriptionRequest(
            operation: $operation,
            audioPath: $path,
            languageCode: $languageCode,
            durationSeconds: $recording->duration_ms ? (int) ceil($recording->duration_ms / 1000) : null,
            userId: $recording->learnerProfile->user_id,
        ));

        return SpeechTranscription::create([
            'audio_recording_id' => $recording->id,
            'language' => $languageCode,
            'provider_slug' => config('ai.default_provider'),
            'model_slug' => $result->model,
            'text' => trim($result->text),
        ]);
    }
}
