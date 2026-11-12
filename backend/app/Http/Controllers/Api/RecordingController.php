<?php

namespace App\Http\Controllers\Api;

use App\Domain\Media\MediaUrlService;
use App\Domain\Speech\AudioService;
use App\Http\Controllers\Controller;
use App\Models\AudioRecording;
use App\Models\LearnerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecordingController extends Controller
{
    public function __construct(private readonly AudioService $audio) {}

    /** Upload a voice recording (multipart). Content is sniffed server-side; stored privately with a retention window. */
    public function store(Request $request, LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:'.config('learning.speech.max_upload_kb')],
            'purpose' => ['required', Rule::in(['conversation', 'pronunciation', 'speaking_assessment'])],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);
        $recording = $this->audio->store($learnerProfile, $request->file('audio'), $data['purpose'], $data['duration_ms'] ?? null);

        return response()->json(['data' => [
            'id' => $recording->public_id,
            'duration_ms' => $recording->duration_ms,
            'expires_at' => $recording->expires_at->toIso8601String(),
        ]], 201);
    }

    public function url(Request $request, AudioRecording $recording, MediaUrlService $media): JsonResponse
    {
        abort_unless($recording->learnerProfile?->user_id === $request->user()->id && $recording->path !== '', 404);

        return response()->json(['url' => $media->temporaryUrl($recording->path)]);
    }
}
