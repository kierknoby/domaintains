<?php
declare(strict_types=1);

// Execute locally on the provider host; do not paste the token into logs or tickets.
$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
fwrite(STDOUT, "Activation token (deliver securely to the service administrator):\n$token\n\n");
fwrite(STDOUT, "Store ONLY this SHA-256 in your private service configuration:\n" . hash('sha256', $token) . "\n");
