<?php
declare(strict_types=1);

return [
    'private_state_dir' => '/var/lib/domaintains-byot',
    'max_request_age' => 300,
    'simulate_dns' => true,
    'emergency_application' => null,
    'billing' => [
        'number_assignments' => [
            '12025550101' => ['account' => 'account-01', 'service' => 'replace-with-local-pbx-identity', 'pbx' => 'pbx-01', 'status' => 'active', 'revision' => 1],
            '12025550102' => ['account' => 'account-01', 'service' => 'replace-with-local-pbx-identity', 'pbx' => 'pbx-01', 'status' => 'active', 'revision' => 1],
        ],
        'pbx' => ['pbx-01' => ['account' => 'account-01', 'status' => 'active', 'service' => 'replace-with-local-pbx-identity']],
        'voice' => [
            'voice-01' => ['account' => 'account-01', 'status' => 'active', 'max_channels' => 2, 'numbers' => ['12025550101']],
            'voice-02' => ['account' => 'account-01', 'status' => 'active', 'max_channels' => 3, 'numbers' => ['12025550102']],
        ],
    ],
    'plans' => [
        'replace-with-local-pbx-identity' => [
            'profile' => 'local-simulation', 'domaintains_hostname' => 'pbx.example',
            'trunks' => [
                ['role' => 'inbound', 'sip_host' => 'inbound.example', 'sip_port' => 5060],
                ['role' => 'outbound', 'sip_host' => 'outbound.example', 'sip_port' => 5060],
            ],
        ],
    ],
    'destinations' => [
        'replace-with-local-pbx-identity' => ['account' => 'account-01', 'pbx' => 'pbx-01', 'sip_host' => 'pbx.example', 'sip_port' => 5060, 'transport' => 'udp'],
    ],
    'policies' => [
        'replace-with-local-pbx-identity' => [
            'inbound' => ['auth' => 'ip', 'registration' => 'none', 'context' => 'from-pbx',
                'delivery' => ['sip_host' => 'inbound.example', 'sip_port' => 5060, 'transport' => 'udp']],
            'outbound' => ['auth' => 'ip', 'registration' => 'none', 'context' => 'from-pbx',
                'carrier' => ['id' => 'carrier-01', 'sip_host' => 'carrier.example', 'sip_port' => 5060, 'transport' => 'udp'],
                'patterns' => ['12025550XXX']],
        ],
    ],
];
