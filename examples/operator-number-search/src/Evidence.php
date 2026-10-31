<?php
declare(strict_types=1);

namespace Domaintains\NumberSearch;

require_once __DIR__ . '/Contracts.php';

final class SessionOfferEvidence implements OfferEvidence
{
    public function seenAt(array $state, string $scope, string $prefix, string $canonical): ?int
    {
        $time = ($state['domaintains_search_offers'] ?? [])[$scope][$prefix][$canonical] ?? null;
        return is_int($time) ? $time : null;
    }

    public function remember(array &$state, string $scope, string $prefix, array $numbers, int $now): int
    {
        $offers = (array)(($state['domaintains_search_offers'] ?? [])[$scope][$prefix] ?? []);
        $offers = array_filter($offers, static fn($time): bool => is_int($time) && $time >= $now - 1800 && $time <= $now);
        $newOffers = 0;
        foreach ($numbers as $row) { if (!isset($offers[$row['number']])) { $newOffers++; } $offers[$row['number']] = $now; }
        if (count($offers) > 5000) { asort($offers, SORT_NUMERIC); $offers = array_slice($offers, -5000, null, true); }
        unset($state['domaintains_search_offers'][$scope]);
        $state['domaintains_search_offers'][$scope] = [$prefix => $offers];
        $state['domaintains_search_offers'] = array_slice($state['domaintains_search_offers'], -16, null, true);
        return $newOffers;
    }

    public function clear(array &$state): void { unset($state['domaintains_search_offers']); }
}