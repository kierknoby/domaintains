<?php
declare(strict_types=1);

namespace Domaintains\NumberSearch;

require_once __DIR__ . '/Evidence.php';

interface AvailabilityCarrier
{
    public function available(string $range, int $size): array;
}

final class AvailabilityFailure extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason, public readonly ?int $queueMs = null)
    {
        parent::__construct('Availability request failed.');
    }
}

final class PrivateFiles
{
    public static function directory(string $directory): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0 || !str_starts_with($directory, '/')
            || str_contains($directory, '/..') || is_link($directory)) { throw new \RuntimeException('Private unprivileged storage required.'); }
        $mask = umask(0077);
        try {
            if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new \RuntimeException('Private storage unavailable.'); }
        } finally { umask($mask); }
        if (realpath($directory) !== $directory || fileowner($directory) !== posix_geteuid() || !chmod($directory, 0700)) {
            throw new \RuntimeException('Unsafe private storage.');
        }
    }

    public static function open(string $file, string $mode)
    {
        clearstatcache(true, $file);
        if (is_link($file) || (file_exists($file) && (!is_file($file) || fileowner($file) !== posix_geteuid()))) {
            throw new \RuntimeException('Unsafe private file.');
        }
        $mask = umask(0077);
        try { $handle = fopen($file, $mode); } finally { umask($mask); }
        if ($handle === false) { throw new \RuntimeException('Private file unavailable.'); }
        $stat = fstat($handle);
        $pathStat = lstat($file);
        if ($stat === false || $pathStat === false || $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid()
            || $stat['ino'] !== $pathStat['ino'] || $stat['dev'] !== $pathStat['dev'] || !chmod($file, 0600)) {
            fclose($handle);
            throw new \RuntimeException('Unsafe private file ownership.');
        }
        return $handle;
    }
}

final class AvailabilityQueue
{
    public function __construct(private readonly string $file, private readonly \Closure $clock,
        private readonly \Closure $wait, private readonly \Closure $cancelled)
    {
        PrivateFiles::directory(dirname($file));
    }

    public function run(callable $operation): array
    {
        try { $handle = PrivateFiles::open($this->file, 'c+'); }
        catch (\Throwable $error) { throw new AvailabilityFailure(503, 'local_lock_unavailable'); }
        $start = ($this->clock)();
        $locked = false;
        $queueMs = null;
        try {
            while (($this->clock)() - $start < 15.0) {
                if (($this->cancelled)()) { throw new AvailabilityFailure(503, 'cancelled'); }
                if (flock($handle, LOCK_EX | LOCK_NB)) { $locked = true; break; }
                ($this->wait)(200000);
            }
            if (!$locked) { throw new AvailabilityFailure(503, 'local_queue_timeout'); }
            $contents = stream_get_contents($handle, 128);
            if ($contents === false || ($contents !== '' && !preg_match('/^\d+(?:\.\d+)?$/D', trim($contents)))) {
                throw new AvailabilityFailure(503, 'local_lock_corrupt');
            }
            $next = $contents === '' ? 0.0 : (float)trim($contents);
            while (($this->clock)() < $next) {
                if (($this->cancelled)()) { throw new AvailabilityFailure(503, 'cancelled'); }
                if (($this->clock)() - $start >= 15.0) { throw new AvailabilityFailure(503, 'local_queue_timeout'); }
                ($this->wait)((int)min(200000, ceil(($next - ($this->clock)()) * 1000000)));
            }
            if (($this->cancelled)()) { throw new AvailabilityFailure(503, 'cancelled'); }
            $stamp = sprintf('%.6F', ($this->clock)() + 5.0);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $stamp) !== strlen($stamp) || !fflush($handle)) {
                throw new AvailabilityFailure(503, 'local_lock_write_failure');
            }
            $queueMs = (int)round((($this->clock)() - $start) * 1000);
            return $operation($queueMs);
        } catch (AvailabilityFailure $error) {
            throw new AvailabilityFailure($error->status, $error->reason, $queueMs ?? (int)round((($this->clock)() - $start) * 1000));
        } finally {
            if ($locked) { flock($handle, LOCK_UN); }
            fclose($handle);
        }
    }
}

final class AvailabilityAudit
{
    public const FIELDS = ['utc', 'request_id', 'action', 'prefix', 'context', 'client_id', 'service_id', 'product_id',
        'source_ip', 'session_hash', 'outcome', 'carrier_contacted', 'carrier_http', 'carrier_ms', 'queue_ms',
        'elapsed_ms', 'carrier_records', 'valid_offers', 'new_session_offers', 'search_length', 'search_position', 'curl_errno', 'reason'];

    public function __construct(private readonly string $directory) { PrivateFiles::directory($directory); }

    public function write(array $event): void
    {
        $event = array_intersect_key($event, array_flip(self::FIELDS));
        $base = $this->directory . '/availability-' . gmdate('Ymd');
        foreach (['jsonl', 'csv'] as $format) {
            try {
                $handle = PrivateFiles::open($base . '.' . $format, 'c+');
                try {
                    if (!flock($handle, LOCK_EX)) { throw new \RuntimeException('Audit lock unavailable.'); }
                    $stat = fstat($handle);
                    fseek($handle, 0, SEEK_END);
                    if ($format === 'jsonl') {
                        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                        if (fwrite($handle, $line) !== strlen($line)) { throw new \RuntimeException('Audit write failed.'); }
                    } else {
                        if ($stat['size'] === 0 && fputcsv($handle, self::FIELDS, ',', '"', '') === false) { throw new \RuntimeException('Audit header failed.'); }
                        $values = array_map(static function (string $field) use ($event): string {
                            $value = $event[$field] ?? '';
                            $cell = is_scalar($value) ? (string)$value : '';
                            return preg_match('/^[=+@\-\t\r\n]/', $cell) ? "'" . $cell : $cell;
                        }, self::FIELDS);
                        if (fputcsv($handle, $values, ',', '"', '') === false) { throw new \RuntimeException('Audit row failed.'); }
                    }
                    if (!fflush($handle)) { throw new \RuntimeException('Audit flush failed.'); }
                } finally { flock($handle, LOCK_UN); fclose($handle); }
            } catch (\Throwable $error) { error_log('DOMAINTAINS availability audit: private log unavailable'); }
        }
    }
}

final class AvailabilityService
{
    public function __construct(private readonly AvailabilityCarrier $carrier, private readonly AvailabilityQueue $queue,
        private readonly AvailabilityAudit $audit, private readonly array $regions, private readonly array $origins,
        private readonly \Closure $authorise, private readonly \Closure $clock,
        private readonly OfferEvidence $evidence = new SessionOfferEvidence(),
        private readonly ?\Closure $identity = null)
    {
    }

    public static function canonical(string $number): string
    {
        $number = preg_replace('/\D/', '', $number);
        if (preg_match('/^0[12]\d{9}$/D', $number)) { return '44' . substr($number, 1); }
        if (preg_match('/^44[12]\d{9}$/D', $number)) { return $number; }
        throw new AvailabilityFailure(400, 'invalid_number');
    }

    public static function score(string $number): int
    {
        $tail = substr($number, -6);
        $score = preg_match('/(\d)\1{3}$/', $tail) ? 14 : (preg_match('/(\d)\1{2}$/', $tail) ? 10 : (preg_match('/(\d)\1$/', $tail) ? 6 : 0));
        if (preg_match_all('/(\d)\1/', $tail) >= 2) { $score += 4; }
        if (preg_match('/(33|44|88)$/', $tail)) { $score += 4; }
        if (preg_match('/(\d)\d\1$/', $tail)) { $score += 4; }
        if (preg_match('/123|234|345|456|567|678|789/', $tail)) { $score += 5; }
        if (preg_match('/0$/', $tail)) { $score += 2; }
        if (preg_match('/00$/', $tail)) { $score += 4; }
        return $score;
    }

    public function knownPrefix(string $prefix): bool
    {
        foreach ($this->regions as $areas) {
            foreach (is_array($areas) ? $areas : [] as $codes) {
                if (is_array($codes) && array_key_exists($prefix, $codes)) { return true; }
            }
        }
        return false;
    }

    public function verifySelection(array &$session, array $request, array $server, string $selection): string
    {
        $canonical = self::canonical($selection);
        $prefix = $request['prefix'] ?? '';
        if (!is_string($prefix) || !$this->knownPrefix($prefix) || !str_starts_with('0' . substr($canonical, 2), $prefix)) {
            throw new AvailabilityFailure(400, 'invalid_number');
        }
        $context = ($this->authorise)($session, $request);
        $scope = $context['selection_scope'] ?? null;
        if (!is_string($scope) || $scope === '') { throw new AvailabilityFailure(403, 'forbidden'); }
        $seen = $this->evidence->seenAt($session, $scope, $prefix, $canonical);
        $verifyStart = (int)($this->clock)();
        $request['action'] = 'initial';
        unset($request['search'], $request['position']);
        [$status, $response] = $this->handle($session, $request, $server);
        if ($status !== 200) { throw new AvailabilityFailure($status, 'selection_unverified'); }
        foreach ($response['numbers'] as $offer) { if ($offer['number'] === $canonical) { return $canonical; } }
        $now = (int)($this->clock)();
        if (is_int($seen) && $seen <= $verifyStart && $seen >= $now - 1800) { return $canonical; }
        throw new AvailabilityFailure(400, 'selection_unverified');
    }

    public function verifyOrderSelection(array $line, string $prefix, string $canonical): string
    {
        $origin = $line['originating_item_id'] ?? null;
        if (!is_string($origin) || $origin === '') { throw new AvailabilityFailure(403, 'order_origin_unverified'); }
        $receipt = $line['selection_receipt'] ?? null;
        if ($receipt !== null && (!is_array($receipt) || ($receipt['resource_id'] ?? null) !== $origin)) {
            throw new AvailabilityFailure(403, 'order_receipt_origin_mismatch');
        }
        $now = (int)($this->clock)();
        $nonce = bin2hex(random_bytes(24));
        $session = ['domaintains_number_nonce' => $nonce, 'domaintains_number_nonce_time' => $now];
        $context = ['client_id' => $line['account_id'], 'product_id' => $line['product_id'], 'context' => 'order_verification',
            'selection_scope' => hash('sha256', $line['order_line_id'])];
        if (is_array($receipt) && ($receipt['number'] ?? null) === $canonical && ($receipt['account_id'] ?? null) === $line['account_id']
            && ($receipt['product_id'] ?? null) === $line['product_id'] && is_int($receipt['verified_at'] ?? null)
            && $receipt['verified_at'] <= $now && $receipt['verified_at'] >= $now - 1800
            && is_int($receipt['offered_at'] ?? null) && $receipt['offered_at'] <= $receipt['verified_at']) {
            $this->evidence->remember($session, $context['selection_scope'], $prefix, [['number' => $canonical]], $receipt['offered_at']);
        }
        $service = new self($this->carrier, $this->queue, $this->audit, $this->regions, $this->origins,
            static fn(array $session, array $request): array => $context, $this->clock, $this->evidence,
            static fn(array $session): CustomerIdentity => new CustomerIdentity($line['account_id']));
        return $service->verifySelection($session, ['nonce' => $nonce, 'prefix' => $prefix], ['REQUEST_METHOD' => 'POST'], $canonical);
    }

    public function handle(array &$session, array $request, array $server): array
    {
        $start = ($this->clock)();
        $now = (int)$start;
        $event = ['utc' => gmdate('Y-m-d\TH:i:s\Z'), 'request_id' => bin2hex(random_bytes(6)), 'carrier_contacted' => 0,
            'client_id' => null,
            'source_ip' => filter_var($server['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '', 'context' => 'unverified'];
        if (in_array($request['action'] ?? null, ['initial', 'refresh', 'search'], true)) { $event['action'] = $request['action']; }
        $requestedContext = $request['context'] ?? null;
        if (is_string($requestedContext) && preg_match('/^(cart|update)-[a-zA-Z0-9_-]{1,200}$/D', $requestedContext, $match)) {
            $event['context'] = $match[1] === 'cart' ? 'unverified_cart' : 'unverified_subscription';
        }
        $requestedPrefix = $request['prefix'] ?? null;
        if (is_string($requestedPrefix) && preg_match('/^0[12]\d{0,6}$/D', $requestedPrefix) && $this->knownPrefix($requestedPrefix)) { $event['prefix'] = $requestedPrefix; }
        $auditable = false;
        try {
            if (($server['REQUEST_METHOD'] ?? '') !== 'POST') { throw new AvailabilityFailure(405, 'post_only'); }
            $origin = $server['HTTP_ORIGIN'] ?? '';
            $referer = $server['HTTP_REFERER'] ?? '';
            if (($origin !== '' && !in_array($origin, $this->origins, true)) || ($origin === '' && $referer !== ''
                && !array_filter($this->origins, static fn(string $allowed): bool => str_starts_with($referer, $allowed . '/')))) {
                throw new AvailabilityFailure(403, 'forbidden');
            }
            $nonce = $request['nonce'] ?? null;
            $sessionNonce = $session['domaintains_number_nonce'] ?? null;
            $nonceTime = $session['domaintains_number_nonce_time'] ?? 0;
            if (!is_string($nonce) || !is_string($sessionNonce) || strlen($sessionNonce) < 32 || !hash_equals($sessionNonce, $nonce)
                || !is_int($nonceTime) || $nonceTime <= 0 || $nonceTime > $now || $now - $nonceTime > 86400) {
                throw new AvailabilityFailure(403, 'session_expired');
            }
            $auditable = true;
            $event['session_hash'] = substr(hash_hmac('sha256', session_id(), $sessionNonce), 0, 12);
            if ($this->identity !== null) { $event['client_id'] = ($this->identity)($session)?->accountId; }
            try { $context = ($this->authorise)($session, $request); }
            catch (AvailabilityFailure $error) { throw $error; }
            catch (\Throwable $error) { throw new AvailabilityFailure(503, 'attribution_unavailable'); }
            if (!is_array($context) || !is_string($context['selection_scope'] ?? null) || $context['selection_scope'] === '') { throw new AvailabilityFailure(403, 'forbidden'); }
            $event = array_replace($event, array_intersect_key($context, array_flip(['client_id', 'service_id', 'product_id', 'context'])));
            $prefix = $request['prefix'] ?? null;
            $action = $request['action'] ?? null;
            $search = $request['search'] ?? '';
            if (!is_string($prefix) || !preg_match('/^0[12]\d{0,6}$/D', $prefix) || !$this->knownPrefix($prefix)) { throw new AvailabilityFailure(400, 'invalid_prefix'); }
            if (!in_array($action, ['initial', 'refresh', 'search'], true)) { throw new AvailabilityFailure(400, 'invalid_action'); }
            $event['prefix'] = $prefix;
            $event['action'] = $action;
            $needed = 11 - strlen($prefix);
            $range = $prefix . str_repeat('_', $needed);
            if ($action === 'search') {
                if (!is_string($search) || !preg_match('/^\d{2,7}$/D', $search)) { throw new AvailabilityFailure(400, 'invalid_pattern'); }
                $remaining = $needed - strlen($search);
                $position = filter_var($request['position'] ?? null, FILTER_VALIDATE_INT);
                if ($remaining < 0 || $position === false || $position < 0 || $position > min(5, $remaining)) { throw new AvailabilityFailure(400, 'invalid_position'); }
                $range = $prefix . str_repeat('_', $position) . $search . str_repeat('_', $remaining - $position);
                $event['search_length'] = strlen($search);
                $event['search_position'] = $position;
            } elseif ($search !== '' || isset($request['position'])) { throw new AvailabilityFailure(400, 'invalid_action'); }
            $recent = array_values(array_filter((array)($session['domaintains_availability_times'] ?? []), static fn($time): bool => is_int($time) && $time > $now - 60));
            if (count($recent) >= 30) { throw new AvailabilityFailure(429, 'session_limit'); }
            $event['queue_ms'] = 0;
            $response = $this->queue->run(function (int $queueMs) use (&$session, &$event, $recent, $range): array {
                $event['queue_ms'] = $queueMs;
                $session['domaintains_availability_times'] = array_merge($recent, [(int)($this->clock)()]);
                $event['carrier_contacted'] = 1;
                $carrierStart = ($this->clock)();
                try { $response = $this->carrier->available($range, 20); }
                catch (\Throwable $error) { throw new AvailabilityFailure(502, 'carrier_network_failure'); }
                finally { $event['carrier_ms'] = (int)round((($this->clock)() - $carrierStart) * 1000); }
                $event['carrier_http'] = $response['http_code'] ?? 0;
                $event['curl_errno'] = $response['curl_errno'] ?? 0;
                if (!empty($response['curl_errno'])) { throw new AvailabilityFailure(502, 'carrier_network_failure'); }
                if (($response['http_code'] ?? 0) < 200 || ($response['http_code'] ?? 0) >= 300) { throw new AvailabilityFailure(502, 'carrier_http_failure'); }
                try { $data = json_decode($response['body'] ?? '', true, 16, JSON_THROW_ON_ERROR); }
                catch (\JsonException $error) { throw new AvailabilityFailure(502, 'carrier_invalid_json'); }
                if (!is_array($data) || !array_is_list($data) || count($data) > 10000) { throw new AvailabilityFailure(502, 'carrier_invalid_json'); }
                return $data;
            });
            $scored = [];
            foreach ($response as $number) {
                if (!is_string($number)) { continue; }
                try { $canonical = self::canonical($number); } catch (AvailabilityFailure $error) { continue; }
                if (!str_starts_with('0' . substr($canonical, 2), $prefix)) { continue; }
                $scored[$canonical] = ['number' => $canonical, 'score' => self::score($canonical)];
            }
            usort($scored, static fn(array $first, array $second): int => ($second['score'] <=> $first['score']) ?: strcmp($first['number'], $second['number']));
            $numbers = [];
            foreach (array_slice($scored, 0, 20) as $index => $row) {
                $numbers[] = ['number' => $row['number'], 'recommended' => $index < 3];
            }
            $scope = $context['selection_scope'];
            $newOffers = $this->evidence->remember($session, $scope, $prefix, $numbers, (int)($this->clock)());
            $event += ['carrier_records' => count($response), 'valid_offers' => count($numbers), 'new_session_offers' => $newOffers];
            $event['outcome'] = 'success';
            return [200, ['ok' => true, 'prefix' => $prefix, 'numbers' => $numbers]];
        } catch (AvailabilityFailure $error) {
            $event['outcome'] = $error->reason;
            if ($error->queueMs !== null) { $event['queue_ms'] = $error->queueMs; }
            $messages = ['session_expired' => 'Selection session expired', 'forbidden' => 'Forbidden', 'post_only' => 'POST only',
                'session_limit' => 'Too many availability searches. Please try again shortly.'];
            return [$error->status, ['ok' => false, 'error' => $messages[$error->reason] ?? 'Availability temporarily unavailable']];
        } catch (\Throwable $error) {
            $event['outcome'] = 'internal_failure';
            return [503, ['ok' => false, 'error' => 'Availability temporarily unavailable']];
        } finally {
            if ($auditable) {
                $event['reason'] = ($event['outcome'] ?? '') === 'success' ? '' : ($event['outcome'] ?? 'failure');
                $event['elapsed_ms'] = (int)round((($this->clock)() - $start) * 1000);
                $this->audit->write($event);
            }
        }
    }
}