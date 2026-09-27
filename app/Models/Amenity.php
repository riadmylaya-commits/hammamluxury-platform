<?php

namespace App\Models;

use App\Models\Concerns\ReferenceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Amenity extends Model
{
    use ReferenceItem;

    protected $guarded = [];

    protected $casts = ['is_active' => 'bool'];

    public function spas(): BelongsToMany
    {
        return $this->belongsToMany(Spa::class, 'spa_amenities');
    }
}
