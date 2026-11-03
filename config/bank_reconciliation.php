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
        'composition_candidate_limit' => 30,
        'maximum_composition_size' => 3,
        'maximum_advanced_suggestions' => 5,
    ],

    'business_day_scoring' => true,
    'holidays' => [],
    // Optional overrides keyed by OFX BANKID (e.g. '237', '077', '756', '748').
    // Supported keys: movement_date_window_days, installment_date_window_days, holidays.
    'bank_profiles' => [],

    'history' => [
        'enabled' => true,
        'minimum_confirmations' => 3,
        'lookback_days' => 180,
        'bonus_points' => 10,
    ],
];
