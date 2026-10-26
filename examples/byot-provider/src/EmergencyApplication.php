<?php
declare(strict_types=1);

namespace Domaintains\Byot;

final class TrustedSessionCustomer implements CustomerAuthenticator
{
    public function __construct(private readonly string $sessionKey = 'domaintains_customer') {}

    public function current(): ?CustomerIdentity
    {
        $record = $_SESSION[$this->sessionKey] ?? null;
        if (!is_array($record) || !is_string($record['account'] ?? null) || !is_string($record['subject'] ?? null)
            || !is_int($record['expires_at'] ?? null) || $record['expires_at'] <= time()) { return null; }
        return new CustomerIdentity($record['account'], $record['subject'], $record['expires_at']);
    }
}

final class EmergencyApplication
{
    public function __construct(public readonly CustomerAuthenticator $authentication, public readonly EmergencyController $controller) {}

    public static function load(): self
    {
        $configuration = Runtime::loadConfiguration();
        $factory = $configuration['emergency_application'] ?? null;
        if (!is_callable($factory)) { throw new \RuntimeException('Emergency customer integration is not configured.'); }
        $application = $factory($configuration);
        if (!$application instanceof self) { throw new \RuntimeException('Emergency customer integration unavailable.'); }
        return $application;
    }

    public static function startSession(): void
    {
        if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTPS'] ?? '') !== '1') { throw new \RuntimeException('HTTPS required.'); }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1, 'cookie_secure' => true,
                'cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
        }
    }

    public static function headers(): void
    {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    }
}