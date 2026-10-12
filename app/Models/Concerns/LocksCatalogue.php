<?php

namespace App\Models\Concerns;

use App\Domain\Booking\CatalogueMutation;

trait LocksCatalogue
{
    public function save(array $options = [])
    {
        return app(CatalogueMutation::class)->run($this, fn () => parent::save($options));
    }

    public function delete()
    {
        if (! $this->exists) {
            return null;
        }

        return app(CatalogueMutation::class)->run($this, fn () => parent::delete());
    }
}
