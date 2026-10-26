<?php
declare(strict_types=1);

namespace Domaintains\Byot;

final class StateStore
{
    public function __construct(private readonly string $directory)
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0 || !str_starts_with($directory, '/') || str_contains($directory, '/..')) {
            throw new \RuntimeException('Provider state requires an absolute private path and an unprivileged account.');
        }
        $previous = umask(0077);
        try {
            if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true))) {
                throw new \RuntimeException('Unsafe provider state directory.');
            }
        } finally {
            umask($previous);
        }
        if (realpath($directory) !== $directory) { throw new \RuntimeException('Private state path must be canonical without symbolic links.'); }
        $this->secure($directory, 0700);
    }

    public function transaction(string $key, callable $operation): mixed
    {
        if (!preg_match('/^[a-z0-9-]{1,100}$/D', $key)) {
            throw new \InvalidArgumentException('Invalid private state key.');
        }
        $path = $this->directory . '/' . $key . '.json';
        $lockPath = $this->directory . '/' . $key . '.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new \RuntimeException('Unsafe private state lock.');
        }
        $previous = umask(0077);
        try { $lock = fopen($lockPath, 'c+'); } finally { umask($previous); }
        if ($lock === false) { throw new \RuntimeException('Private state unavailable.'); }
        try {
            $this->secure($lockPath, 0600);
            if (!flock($lock, LOCK_EX)) { throw new \RuntimeException('Private state lock unavailable.'); }
            $state = [];
            if (file_exists($path) || is_link($path)) {
                $this->secure($path, 0600);
                if (filesize($path) > 16777216) { throw new \RuntimeException('Private state exceeds supported size.'); }
                $state = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($state) || $state === []) { throw new \RuntimeException('Invalid private state.'); }
            }
            $save = function (array $value) use ($path): void {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (strlen($encoded) > 16777216) { throw new \RuntimeException('Private state exceeds supported size.'); }
                $temporary = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
                $previous = umask(0077);
                try { $handle = fopen($temporary, 'x'); } finally { umask($previous); }
                if ($handle === false) { throw new \RuntimeException('Private state write failed.'); }
                try {
                    if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                        throw new \RuntimeException('Private state write failed.');
                    }
                    fclose($handle);
                    $handle = null;
                    if (is_link($path) || (file_exists($path) && !is_file($path)) || !rename($temporary, $path)) {
                        throw new \RuntimeException('Private state publication failed.');
                    }
                } finally {
                    if (is_resource($handle)) { fclose($handle); }
                    if (file_exists($temporary)) { unlink($temporary); }
                }
            };
            return $operation($state, $save);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function read(string $key): array
    {
        return $this->transaction($key, static fn(array $state): array => $state);
    }

    private function secure(string $path, int $mode): void
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if ($stat === false || is_link($path) || $stat['uid'] !== posix_geteuid()
            || ($mode === 0700 ? !is_dir($path) : (!is_file($path) || $stat['nlink'] !== 1)) || !chmod($path, $mode)) {
            throw new \RuntimeException('Unsafe private state ownership.');
        }
    }
}