<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Note;
use Illuminate\Database\Seeder;

class NoteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Item::query()->each(function (Item $item): void {
            Note::factory()->for($item, 'notable')->create();
        });
    }
}
