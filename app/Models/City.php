<?php

namespace App\Models;

use App\Models\Concerns\ReferenceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use ReferenceItem;

    protected $guarded = [];

    protected $casts = ['is_active' => 'bool', 'is_popular' => 'bool'];

    public function spas(): HasMany
    {
        return $this->hasMany(Spa::class);
    }
}
