<?php
declare(strict_types=1);

require_once __DIR__ . '/operator.php';

function domaintainsOperatorSearchPicker(string $serviceId): string
{
    $config = \Domaintains\NumberSearch\Runtime::configuration();
    return \Domaintains\NumberSearch\Runtime::subscriptionPicker($config, $_SESSION, $serviceId);
}

function domaintainsOperatorSearchAdd(string $serviceId, string $prefix, string $selection): mixed
{
    return domaintainsOperatorIntegration()->addToSubscription($_SESSION, $serviceId, $prefix, $selection, $_SERVER);
}