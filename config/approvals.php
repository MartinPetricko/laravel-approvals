<?php

// config for MartinPetricko/LaravelApprovals
return [
    'models' => [
        'draft' => \MartinPetricko\LaravelApprovals\Models\Draft::class,
    ],
    'column_names' => [
        'approved_at' => 'approved_at',
    ],
    'diff' => [
        'renderer' => 'SideBySide',
        'renderer_options' => [
            'detailLevel' => 'word',
            'lineNumbers' => false,
            'showHeader' => false,
            'spacesToNbsp' => false,
        ],
    ],
];
