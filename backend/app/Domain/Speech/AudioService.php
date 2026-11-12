<?php

namespace App\Domain\Speech;

use App\Domain\Shared\DomainError;
use App\Models\AudioRecording;
use App\Models\LearnerProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Learner voice recordings: content-sniffed, size- and length-capped, stored privately and deleted
 * after the retention window (`lf:recordings:purge`). Never served publicly; playback uses signed URLs.
 */
class AudioService
{
    public function store(LearnerProfile $profile, UploadedFile $file, string $purpose, ?int $durationMs): AudioRecording
    {
        $config = config('learning.speech');
        if (! $file->isValid() || $file->getSize() > $config['max_upload_kb'] * 1024 || $file->getSize() < 100) {
            throw new DomainError('The recording is empty or too large.', 'invalid_recording', 422);
        }
        // Trust the bytes, not the client-supplied type or extension.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream';
        if (! in_array($mime, $config['allowed_mimes'], true)) {
            throw new DomainError('Unsupported audio format.', 'unsupported_audio', 422);
        }
        if ($durationMs !== null && $durationMs > $config['max_seconds'] * 1000) {
            throw new DomainError('Recordings can be at most '.$config['max_seconds'].' seconds long.', 'recording_too_long', 422);
        }

        $extension = match ($mime) {
            'audio/webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/x-m4a' => 'm4a', default => 'wav',
        };
        $disk = config('learning.media.disk');
        $path = "recordings/{$profile->public_id}/".Str::ulid().".{$extension}";
        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        return AudioRecording::create([
            'learner_profile_id' => $profile->id,
            'purpose' => $purpose,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'duration_ms' => $durationMs,
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'expires_at' => now()->addDays($config['retention_days']),
        ]);
    }

    /** A local file path for providers that need one (downloads from object storage when necessary). */
    public function localCopy(AudioRecording $recording): string
    {
        $storage = Storage::disk($recording->disk);
        if (config("filesystems.disks.{$recording->disk}.driver") === 'local') {
            return $storage->path($recording->path);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'lf-audio-').'.'.pathinfo($recording->path, PATHINFO_EXTENSION);
        file_put_contents($tmp, $storage->get($recording->path));

        return $tmp;
    }
}
