<?php

namespace App\Console\Commands;

use App\Domain\Assessment\Items\ItemGenerator;
use App\Models\CefrLevel;
use App\Models\Language;
use App\Models\Skill;
use Illuminate\Console\Command;

class GenerateItems extends Command
{
    protected $signature = 'lf:items:generate {language} {skill} {level} {--count=5}';

    protected $description = 'Draft new placement items with AI (stored as drafts pending human review)';

    public function handle(ItemGenerator $generator): int
    {
        $result = $generator->generate(
            Language::where('code', $this->argument('language'))->firstOrFail(),
            Skill::where('slug', $this->argument('skill'))->firstOrFail(),
            CefrLevel::where('code', strtoupper($this->argument('level')))->firstOrFail(),
            max(1, min(20, (int) $this->option('count'))),
        );

        foreach ($result['created'] as $item) {
            $this->line("draft  {$item->public_id}  {$item->prompt}");
        }
        foreach ($result['rejected'] as $rejected) {
            $this->warn('rejected: '.implode('; ', $rejected['errors']).' — '.($rejected['item']['prompt'] ?? ''));
        }
        $this->info(count($result['created']).' drafts created, '.count($result['rejected']).' rejected.');

        return self::SUCCESS;
    }
}
