<?php

namespace Database\Seeders;

use App\Domain\Content\ContentPackImporter;
use Illuminate\Database\Seeder;

/** Imports authored content packs (database/data/content/*.json). Idempotent. */
class ContentSeeder extends Seeder
{
    public function run(ContentPackImporter $importer): void
    {
        foreach (glob(database_path('data/content/*.json')) as $file) {
            $counts = $importer->importFile($file);
            $this->command?->info(basename($file).': '.json_encode($counts));
        }
    }
}
