<?php

namespace App\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaPhoto extends Model
{
    use Translatable;

    protected $guarded = [];

    protected $casts = ['is_cover' => 'bool'];

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function url(): string
    {
        return str_starts_with($this->path, 'http') ? $this->path : asset('storage/'.$this->path);
    }
}
