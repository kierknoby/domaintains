<?php
declare(strict_types=1);

use Domaintains\Byot\{CurlEmergencyTransport, EmergencyApplication, EmergencyBillingAdapter, EmergencyController, LocalEmergencyAuthority, MappedEmergencyAdapter, StateStore, TrustedSessionCustomer};

return static function (array $configuration): EmergencyApplication {
    $settings = $configuration['emergency'] ?? [];
    $secret = base64_decode($settings['intent_key_base64'] ?? '', true);
    if ($secret === false || strlen($secret) !== 32) { throw new RuntimeException('Private emergency key required.'); }
    $store = new StateStore($configuration['private_state_dir']);
    $billing = $settings['billing_adapter'] ?? null;
    if (!$billing instanceof EmergencyBillingAdapter) { throw new RuntimeException('An authoritative emergency assignment adapter is required.'); }
    $authority = new LocalEmergencyAuthority($store, $billing, hash_hmac('sha256', 'number-identifiers', $secret, true));
    $adapter = new MappedEmergencyAdapter($settings['provider'], new CurlEmergencyTransport());
    return new EmergencyApplication(new TrustedSessionCustomer(),
        new EmergencyController($authority, $adapter, $store, hash_hmac('sha256', 'form-intents', $secret, true)));
};