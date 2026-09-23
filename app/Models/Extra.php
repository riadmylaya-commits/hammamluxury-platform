<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Models\Concerns\Translatable;

class Extra extends Model
{
    use Translatable;

    protected $guarded = [];

    protected $casts = ['per_person' => 'bool', 'price' => 'float'];

    public function spa(): BelongsTo { return $this->belongsTo(Spa::class); }
    public function treatment(): BelongsTo { return $this->belongsTo(Treatment::class); }
}
