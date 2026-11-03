<?php

return [
    'suggestions_ttl_minutes' => 1440,

    'suggestions' => [
        // Discovery margins do not change the rules for confirming a reconciliation.
        'movement_date_window_days' => 7,
        'installment_date_window_days' => 60,
        'minimum_amount_window_cents' => 500,
        'maximum_amount_window_cents' => 5000,
        'relative_amount_window_basis_points' => 200,
        'minimum_score' => 25,
        'high_confidence_score' => 85,
        'medium_confidence_score' => 45,
        'ambiguity_score_gap' => 10,
    ],
];
