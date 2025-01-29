<?php

namespace MartinPetricko\LaravelApprovals;

use Illuminate\Support\Facades\App;
use MartinPetricko\LaravelApprovals\Models\Draft;

class LaravelApprovals
{
    public function getRequestId(): string
    {
        return App::make('approvableRequestId');
    }

    public function getDraftModel()
    {
        return config('approvals.models.draft', Draft::class);
    }
}
