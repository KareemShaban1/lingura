<?php

namespace App\Console\Commands;

use App\Models\AudioRecording;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Deletes learner voice recordings past their retention window (transcripts and scores are kept). */
class PurgeRecordings extends Command
{
    protected $signature = 'lf:recordings:purge';

    protected $description = 'Delete voice recordings whose retention period has ended';

    public function handle(): int
    {
        $count = 0;
        // Only the audio is deleted; the row (path '') keeps transcripts and scores linked for the learner's history.
        AudioRecording::where('expires_at', '<', now())->where('path', '!=', '')->chunkById(200, function ($recordings) use (&$count) {
            foreach ($recordings as $r) {
                Storage::disk($r->disk)->delete($r->path);
                $r->update(['path' => '', 'size_bytes' => 0]);
                $count++;
            }
        });
        $this->info("{$count} recordings purged.");

        return self::SUCCESS;
    }
}
