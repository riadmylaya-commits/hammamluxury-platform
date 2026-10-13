<?php

namespace App\Events;

use App\Models\CancellationRequest;
use Illuminate\Foundation\Events\Dispatchable;

/** Rendez-vous passé avec une demande d'annulation partenaire encore ouverte : HammamLuxury doit trancher. */
class CancellationArbitrationRequired
{
    use Dispatchable;

    public function __construct(public CancellationRequest $request) {}
}
