<?php
declare(strict_types=1);

namespace Domaintains\NumberSearch;

final class MagratheaAvailability implements AvailabilityCarrier
{
    public function __construct(private readonly string $username, private readonly string $password,
        private readonly string $userAgent = 'domaintains-number-search/1.0')
    {
        if ($username === '' || $password === '' || !extension_loaded('curl')) { throw new \RuntimeException('Private carrier configuration unavailable.'); }
    }

    public function available(string $range, int $size): array
    {
        if ($size !== 20 || !preg_match('/^0[12][0-9_]{9}$/D', $range)) { throw new \InvalidArgumentException('Invalid availability range.'); }
        $handle = curl_init('https://restapi.magrathea.net:8443/v1/number/alist/' . rawurlencode($range) . '/20');
        if ($handle === false) { throw new \RuntimeException('Carrier transport unavailable.'); }
        $body = '';
        try {
            curl_setopt_array($handle, [
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => $this->username . ':' . $this->password,
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}',
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function ($stream, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 262144) { return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $result = curl_exec($handle);
            return ['http_code' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE), 'curl_errno' => curl_errno($handle),
                'body' => $result === false ? '' : $body];
        } finally { curl_close($handle); }
    }
}