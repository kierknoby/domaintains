<?php
declare(strict_types=1);

return [
    'intent_key_base64' => 'GENERATE_A_PRIVATE_32_BYTE_KEY',
    'billing_adapter' => null,
    'provider' => [
        'base_url' => 'https://emergency-api.example',
        'headers' => ['Authorization: Bearer REPLACE_WITH_PRIVATE_PROVIDER_CREDENTIAL'],
        'minimum_interval' => 60,
        'rate_limits' => [
            'provider' => 'replace-with-stable-provider-credential-pool-id',
            'rules' => [
                ['scope' => 'provider', 'operations' => ['retrieve', 'submit', 'status'], 'limit' => 100, 'window' => 60],
                ['scope' => 'account', 'operations' => ['retrieve', 'submit', 'status'], 'limit' => 20, 'window' => 60],
                ['scope' => 'number', 'operations' => ['retrieve'], 'limit' => 1, 'window' => 5],
                ['scope' => 'number', 'operations' => ['submit'], 'limit' => 1, 'window' => 30],
                ['scope' => 'number', 'operations' => ['status'], 'limit' => 1, 'window' => 10],
            ],
        ],
        'supports_type_change' => true,
        'fields' => [
            'title' => 'title', 'forename' => 'first_name', 'honours' => 'honours', 'name' => 'surname_or_business',
            'business_suffix' => 'business_suffix', 'address_line_1' => 'street', 'address_line_2' => 'address_extra',
            'locality' => 'town', 'region' => 'region', 'postcode' => 'postal_code', 'country' => 'country_code',
        ],
        'status_field' => 'state', 'revision_field' => 'version', 'registration_field' => 'address',
        'statuses' => [
            'received' => 'received', 'validating' => 'awaiting_validation', 'submitted' => 'submitted',
            'pending' => 'awaiting_acceptance', 'registered' => 'registered', 'rejected' => 'rejected',
        ],
        'missing_http_statuses' => [],
        'response_rules' => [
            ['http_status' => 400, 'operations' => ['retrieve'], 'when' => [['path' => ['error', 'code'], 'equals' => 'NO_REGISTRATION']], 'outcome' => 'unregistered'],
            ['http_status' => 400, 'operations' => ['retrieve', 'submit', 'status'], 'when' => [['path' => ['error', 'code'], 'equals' => 'INVALID_NUMBER']], 'outcome' => 'invalid_number'],
            ['http_status' => 400, 'operations' => ['retrieve', 'submit', 'status'], 'when' => [['path' => ['error', 'code'], 'equals' => 'ACCESS_DENIED']], 'outcome' => 'unauthorised'],
        ],
        'acknowledgements' => [202 => 'submitted'],
        'operations' => [
            'retrieve' => ['method' => 'GET', 'path' => '/registrations/{number}'],
            'submit' => ['method' => 'PUT', 'path' => '/registrations/{number}'],
            'status' => ['method' => 'GET', 'path' => '/registrations/{number}/status'],
        ],
    ],
];