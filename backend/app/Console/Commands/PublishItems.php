<?php

namespace App\Console\Commands;

use App\Domain\Shared\AuditLogger;
use App\Jobs\SynthesizeItemAudio;
use App\Models\AssessmentItem;
use App\Models\User;
use Illuminate\Console\Command;

/** Interim review workflow until the admin review UI ships (Phase 4). */
class PublishItems extends Command
{
    protected $signature = 'lf:items:publish {ids* : public ids of draft items} {--reviewer= : reviewer email (must hold content.review)}';

    protected $description = 'Publish reviewed draft items';

    public function handle(AuditLogger $audit): int
    {
        $reviewer = User::where('email', $this->option('reviewer'))->first();
        if (! $reviewer || ! $reviewer->hasPermission('content.review')) {
            $this->error('A reviewer with the content.review permission is required (--reviewer=email).');

            return self::FAILURE;
        }

        foreach (AssessmentItem::whereIn('public_id', $this->argument('ids'))->get() as $item) {
            $item->update(['review_status' => AssessmentItem::STATUS_PUBLISHED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
            $audit->log('assessment_item.published', $item, actorId: $reviewer->id);
            if ($item->requiresAudio() && ! $item->audio_path) {
                SynthesizeItemAudio::dispatch($item->id);
            }
            $this->line("published {$item->public_id}");
        }

        return self::SUCCESS;
    }
}
