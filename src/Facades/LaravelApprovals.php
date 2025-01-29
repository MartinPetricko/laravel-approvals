<?php

namespace MartinPetricko\LaravelApprovals\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MartinPetricko\LaravelApprovals\LaravelApprovals
 */
class LaravelApprovals extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MartinPetricko\LaravelApprovals\LaravelApprovals::class;
    }
}
