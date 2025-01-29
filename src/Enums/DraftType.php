<?php

namespace MartinPetricko\LaravelApprovals\Enums;

enum DraftType: string
{
    case Create = 'create';

    case Update = 'update';

    case Delete = 'delete';
}
