<?php

namespace Database\Seeders;

use App\Domain\Assessment\Items\ItemImporter;
use Illuminate\Database\Seeder;

/**
 * Imports the authored, reviewed placement item bank from database/data/item-bank/*.json.
 * Idempotent: items are keyed by a content hash.
 */
class ItemBankSeeder extends Seeder
{
    public function run(ItemImporter $importer): void
    {
        foreach (glob(database_path('data/item-bank/*.json')) as $file) {
            $count = $importer->importFile($file);
            $this->command?->info(sprintf('Imported %d items from %s', $count, basename($file)));
        }
    }
}
