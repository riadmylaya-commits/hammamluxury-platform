<?php

namespace App\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    use Translatable;

    protected $guarded = [];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'value' => 'float'];

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }
}
