<?php

namespace App\Models;

use App\Models\Concerns\ReferenceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use ReferenceItem;

    protected $guarded = [];

    protected $casts = ['is_active' => 'bool', 'is_popular' => 'bool'];

    public function spas(): BelongsToMany
    {
        return $this->belongsToMany(Spa::class, 'spa_categories');
    }
}
