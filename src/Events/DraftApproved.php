<?php

namespace MartinPetricko\LaravelApprovals\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MartinPetricko\LaravelApprovals\Models\Draft;

class DraftApproved
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Draft $draft)
    {
        //
    }
}
