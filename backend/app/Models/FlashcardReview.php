<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only review log (enables re-fitting FSRS parameters later). */
class FlashcardReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
