<?php
declare(strict_types=1);

namespace Domaintains\Byot;

require_once __DIR__ . '/StateStore.php';

/**
 * Durable, atomic first-use key binding and nonce replay prevention.
 * Files must live outside the public web directory.
 */
final class ReplayStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function throttle(int $now): void
    {
        (new StateStore($this->directory))->transaction('request-rate', static function (array $state, callable $save) use ($now): void {
            if (($state['window'] ?? 0) <= $now - 60 || ($state['window'] ?? 0) > $now) { $state = ['window' => $now, 'count' => 0]; }
            if (($state['count'] ?? 0) >= 600) { throw new ProviderException(429, 'Provider request limit exceeded.'); }
            $state['count'] = ($state['count'] ?? 0) + 1;
            $save($state);
        });
    }

    /** @throws ProviderException */
    public function consume(string $service, string $nonce, string $publicKey, int $now, int $ttl, string $configuredKey): void
    {
        $this->prepareDirectory();
        $lockPath = $this->directory . '/activation.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && (!is_file($lockPath) || fileowner($lockPath) !== posix_geteuid() || lstat($lockPath)['nlink'] !== 1))) {
            throw new ProviderException(500, 'Unsafe replay storage.');
        }
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new ProviderException(500, 'Replay storage unavailable.');
        }
        try {
            @chmod($lockPath, 0600);
            if (!flock($lock, LOCK_EX)) {
                throw new ProviderException(500, 'Replay storage unavailable.');
            }
            $path = $this->directory . '/activation-state.json';
            if (is_link($path) || (file_exists($path) && (!is_file($path) || fileowner($path) !== posix_geteuid() || lstat($path)['nlink'] !== 1 || filesize($path) > 16777216))) {
                throw new ProviderException(500, 'Unsafe replay storage.');
            }
            $state = ['version' => 1, 'seen' => [], 'keys' => []];
            if (file_exists($path)) {
                $bytes = @file_get_contents($path);
                $value = is_string($bytes) ? json_decode($bytes, true) : null;
                if (!is_array($value) || ($value['version'] ?? null) !== 1
                    || !isset($value['seen'], $value['keys']) || !is_array($value['seen']) || !is_array($value['keys'])) {
                    throw new ProviderException(500, 'Replay storage is invalid.');
                }
                $state = $value;
            }
            $nowExpiryCutoff = $now - $ttl - 10;
            foreach ($state['seen'] as $key => $seenAt) {
                if (!is_int($seenAt) || $seenAt < $nowExpiryCutoff) {
                    unset($state['seen'][$key]);
                }
            }
            if (count($state['seen']) >= 10000) {
                throw new ProviderException(503, 'Replay storage is full.');
            }
            $key = hash('sha256', $service . "\0" . $nonce);
            if (isset($state['seen'][$key])) {
                throw new ProviderException(409, 'Activation request already used.');
            }
            // Optional provider-set pin takes precedence; first use binds otherwise.
            if ($configuredKey !== '' && !hash_equals($configuredKey, $publicKey)) {
                throw new ProviderException(403, 'Activation public key rejected.');
            }
            if (isset($state['keys'][$service]) && (!is_string($state['keys'][$service])
                || !hash_equals($state['keys'][$service], $publicKey))) {
                throw new ProviderException(403, 'Activation public key rejected.');
            }
            foreach ($state['keys'] as $boundService => $boundKey) {
                if ($boundService !== $service && is_string($boundKey) && hash_equals($boundKey, $publicKey)) {
                    throw new ProviderException(403, 'Activation public key already belongs to another service.');
                }
            }
            $state['keys'][$service] = $publicKey;
            $state['seen'][$key] = $now;
            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $written = false;
            try {
                $previous = umask(0077);
                try {
                    $handle = fopen($temporary, 'x');
                    if ($handle !== false) {
                        try { $written = fwrite($handle, $encoded . "\n") === strlen($encoded) + 1 && fflush($handle) && (!function_exists('fsync') || fsync($handle)); }
                        finally { fclose($handle); }
                    }
                } finally {
                    umask($previous);
                }
                if (!$written || !@chmod($temporary, 0600) || !@rename($temporary, $path)) {
                    throw new ProviderException(500, 'Unable to persist replay protection.');
                }
            } finally {
                if (file_exists($temporary)) {
                    @unlink($temporary);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function prepareDirectory(): void
    {
        new StateStore($this->directory);
        if (is_link($this->directory)) {
            throw new ProviderException(500, 'Unsafe replay directory.');
        }
        if (!is_dir($this->directory)) {
            $previous = umask(0077);
            try {
                if (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                    throw new ProviderException(500, 'Replay directory unavailable.');
                }
            } finally {
                umask($previous);
            }
        }
        if (!is_dir($this->directory) || !@chmod($this->directory, 0700)) {
            throw new ProviderException(500, 'Replay directory unavailable.');
        }
    }
}
