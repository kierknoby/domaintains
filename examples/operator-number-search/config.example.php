<?php
declare(strict_types=1);

return [
    'enabled' => false,
    'operator_bootstrap' => null,
    'regions_file' => '/etc/domaintains-number-search/uk_area_codes.php',
    'private_state_dir' => '/var/lib/domaintains-number-search',
    'allowed_origins' => ['https://portal.example'],
    'product_id' => 'replace-with-operator-product-id',
    'selection_field_name' => 'selected_number',
    'asset_url' => '/domaintains',
    'availability_url' => '/domaintains/availability.php',
    'customer_access' => null,
    'catalog' => null,
    'ordering' => null,
    'ordering_lifecycle' => null,
    'evidence' => new \Domaintains\NumberSearch\SessionOfferEvidence(),
    'provisioning' => null,
    'billing' => null,
    'carrier' => null,
];