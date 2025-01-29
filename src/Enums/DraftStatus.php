<?php

namespace MartinPetricko\LaravelApprovals\Enums;

enum DraftStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';
}
