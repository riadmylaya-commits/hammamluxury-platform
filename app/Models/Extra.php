<?php

namespace App\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Extra extends Model
{
    use Translatable;

    protected $guarded = [];

    protected $casts = ['per_person' => 'bool', 'commissionable' => 'bool', 'price' => 'float'];

    protected $attributes = ['commissionable' => true];

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }
}
