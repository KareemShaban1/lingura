<?php

namespace App\Jobs;

use App\Domain\Adaptive\AdaptiveEngine;
use App\Domain\Tutor\ReportGenerator;
use App\Models\AiConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateConversationReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [20];

    public function __construct(public readonly int $conversationId)
    {
        $this->onQueue('ai');
    }

    public function handle(ReportGenerator $generator, AdaptiveEngine $adaptive): void
    {
        $conversation = AiConversation::findOrFail($this->conversationId);
        if ($conversation->learner_turns === 0) {
            return; // nothing to assess
        }
        $generator->generate($conversation);
        $conversation->update(['status' => 'completed']);
        $adaptive->refresh($conversation->learnerProfile);
    }

    public function failed(Throwable $e): void
    {
        AiConversation::whereKey($this->conversationId)->update(['status' => 'report_failed']);
    }
}
