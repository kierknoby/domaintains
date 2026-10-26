<?php
declare(strict_types=1);

use Domaintains\Byot\ActivationProvider;
use Domaintains\Byot\ReplayStore;

require dirname(__DIR__) . '/src/ActivationProvider.php';
require dirname(__DIR__) . '/src/ReplayStore.php';

function check(bool $pass, string $label): void
{
    if (!$pass) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'PASS: ' . $label . "\n";
}
function signedRequest(string $token, string $service, string $secret, int $now, ?string $nonce = null): array
{
    $claim = [
        'token' => $token,
        'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)),
        'timestamp' => $now,
        'nonce' => base64_encode($nonce ?? random_bytes(24)),
        'service' => $service,
    ];
    $canonical = json_encode($claim, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return [
        'action' => 'activate',
        'claim' => $claim,
        'signature' => base64_encode(sodium_crypto_sign_detached($canonical, $secret)),
    ];
}
function status(ActivationProvider $provider, array $request, int $now): int
{
    return $provider->process($request, $now)[0];
}

$dir = sys_get_temp_dir() . '/domaintains-byot-test-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Cannot create test storage.');
}
try {
    $now = 1800000000;
    $token = 'synthetic-long-random-token-for-a-single-service-only';
    $service = 'example-service-01';
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $otherSecret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $record = [
        'enabled' => true,
        'token_sha256' => hash('sha256', $token),
        'public_key' => '',
        'profile' => 'Synthetic fixture',
        'domaintains_hostname' => 'pbx.example',
        'max_channels' => 10,
        'numbers' => ['442079460001', '442079460002'],
        'trunks' => [
            ['role' => 'inbound', 'sip_host' => 'inbound.example', 'sip_port' => 5060],
            ['role' => 'outbound', 'sip_host' => 'outbound.example', 'sip_port' => 5060],
        ],
    ];
    $config = [
        'private_state_dir' => $dir,
        'max_request_age' => 300,
        'services' => [$service => $record],
    ];
    $provider = new ActivationProvider($config, new ReplayStore($dir));
    $request = signedRequest($token, $service, $secret, $now);
    [$code, $body] = $provider->process($request, $now);
    check($code === 200 && ($body['ok'] ?? false) === true, 'Signed and authorised activation accepted');
    check(($body['service'] ?? null) === $service && $body['max_channels'] === 10 && count($body['trunks']) === 2,
        'Flat response includes module-required fields');
    check(status($provider, $request, $now) === 409, 'Replayed nonce rejected');
    check(status($provider, signedRequest($token, $service, $secret, $now), $now) === 200, 'New nonce with same pinned key accepted');
    check(status($provider, signedRequest($token, $service, $otherSecret, $now), $now) === 403,
        'Other public key rejected after first authorised activation');
    check(status($provider, signedRequest('wrong-token', $service, $secret, $now), $now) === 403,
        'Wrong activation token rejected');
    check(status($provider, signedRequest($token, 'example-service-02', $secret, $now), $now) === 403,
        'Unregistered service rejected');
    check(status($provider, signedRequest($token, $service, $secret, $now - 301), $now) === 400,
        'Expired request rejected');
    check(status($provider, signedRequest($token, $service, $secret, $now + 6), $now) === 400,
        'Future request rejected');
    check(status($provider, signedRequest($token, 'invalid/service', $secret, $now), $now) === 400,
        'Invalid service identity characters rejected');
    $tampered = signedRequest($token, $service, $secret, $now);
    $tampered['claim']['nonce'] = base64_encode(random_bytes(24));
    check(status($provider, $tampered, $now) === 403, 'Tampered signed claim rejected');
    $disabled = $config;
    $disabled['services'][$service]['enabled'] = false;
    check(status(new ActivationProvider($disabled, new ReplayStore($dir)), signedRequest($token, $service, $secret, $now), $now) === 403,
        'Disabled service rejected');
    $invalid = $config;
    $invalid['services'][$service]['trunks'][0]['sip_port'] = '5060';
    check(status(new ActivationProvider($invalid, new ReplayStore($dir)), signedRequest($token, $service, $secret, $now), $now) === 500,
        'Invalid provider entitlement fails closed');
    $pinned = $config;
    $pinned['services'][$service]['public_key'] = base64_encode(sodium_crypto_sign_publickey_from_secretkey($otherSecret));
    check(status(new ActivationProvider($pinned, new ReplayStore($dir)), signedRequest($token, $service, $secret, $now), $now) === 403,
        'Configured public key pin enforced');
    $restarted = new ActivationProvider($config, new ReplayStore($dir));
    check(status($restarted, signedRequest($token, $service, $otherSecret, $now), $now) === 403,
        'First-use key binding survives handler restart');
    // 0.4.1-dev security hardening: reject provider configuration that can
    // inject PJSIP directives or exhaust resource allocation.
    foreach (["sip.example\n;INJECTION", "sip.example\r\n", "sip.example\0X",
              'not a hostname', 'sip.example;INJECTION', 'sip.example..', '999.999.999.999'] as $unsafe) {
        $bad = $config;
        $bad['services'][$service]['trunks'][0]['sip_host'] = $unsafe;
        check(status(new ActivationProvider($bad, new ReplayStore($dir)),
            signedRequest($token, $service, $secret, $now), $now) === 500,
            'Unsafe provider SIP host rejected');
    }
    foreach (['2001:db8::1', '[2001:db8::1]', '192.0.2.10', 'sip.example.'] as $allowed) {
        $valid = $config;
        $valid['services'][$service]['trunks'][0]['sip_host'] = $allowed;
        check(status(new ActivationProvider($valid, new ReplayStore($dir)),
            signedRequest($token, $service, $secret, $now), $now) === 200,
            'Valid SIP host accepted');
    }
    $hugeNumbers = $config;
    $hugeNumbers['services'][$service]['numbers'] = array_map('strval', range(1, 10001));
    check(status(new ActivationProvider($hugeNumbers, new ReplayStore($dir)),
        signedRequest($token, $service, $secret, $now), $now) === 500,
        'Excessive number list rejected');
    $hugeTrunks = $config;
    $hugeTrunks['services'][$service]['trunks'] = array_fill(0, 65, $record['trunks'][0]);
    check(status(new ActivationProvider($hugeTrunks, new ReplayStore($dir)),
        signedRequest($token, $service, $secret, $now), $now) === 500,
        'Excessive trunk list rejected');
    $longNumber = $config;
    $longNumber['services'][$service]['numbers'] = [str_repeat('9', 33)];
    check(status(new ActivationProvider($longNumber, new ReplayStore($dir)),
        signedRequest($token, $service, $secret, $now), $now) === 500,
        'Long telephone number rejected');
    $longProfile = $config;
    $longProfile['services'][$service]['profile'] = str_repeat('x', 1025);
    check(status(new ActivationProvider($longProfile, new ReplayStore($dir)),
        signedRequest($token, $service, $secret, $now), $now) === 500,
        'Long descriptive field rejected');
    $expired = $config;
    $expired['services'][$service]['expires_at'] = $now - 1;
    check(status(new ActivationProvider($expired, new ReplayStore($dir)),
        signedRequest($token, $service, $secret, $now), $now) === 403,
        'Expired credential denied');
    echo "BYOT provider reference contract passed.\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}
