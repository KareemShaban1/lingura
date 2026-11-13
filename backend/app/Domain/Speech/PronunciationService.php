<?php

namespace App\Domain\Speech;

use App\Domain\Practice\TextNormalizer;
use App\Models\AudioRecording;
use App\Models\PronunciationResult;
use Illuminate\Support\Facades\DB;

class PronunciationService
{
    public function __construct(
        private readonly TranscriptionService $transcriber,
        private readonly PronunciationScorer $scorer,
        private readonly PronunciationLexicon $lexicon,
    ) {}

    public function assess(AudioRecording $recording, string $reference, string $languageCode): PronunciationResult
    {
        $transcript = $this->transcriber->transcribe($recording, $languageCode, 'stt.pronunciation');
        $readings = $this->lexicon->readings($languageCode);
        $score = $this->scorer->score($reference, $transcript->text, $readings);
        // Speaking rate in tokens per minute: words, or characters for unspaced scripts such as Chinese.
        $heardWords = count(TextNormalizer::words($transcript->text));
        $wpm = $recording->duration_ms ? (int) round($heardWords / ($recording->duration_ms / 60000)) : null;

        return DB::transaction(function () use ($recording, $reference, $score, $wpm, $readings) {
            $result = PronunciationResult::create([
                'audio_recording_id' => $recording->id,
                'reference_text' => $reference,
                'method' => $readings ? 'transcript_alignment_tone_v1' : 'transcript_alignment_v1',
                'accuracy' => $score['accuracy'],
                'completeness' => $score['completeness'],
                'words_per_minute' => $wpm !== null && $wpm <= 400 ? $wpm : null,
            ]);
            foreach ($score['words'] as $i => $w) {
                $result->words()->create(['position' => $i, ...$w]);
            }

            return $result->load('words');
        });
    }
}
