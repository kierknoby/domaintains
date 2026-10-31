<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Runtime.php';

function domaintainsOperatorIntegration(?array $config = null): \Domaintains\NumberSearch\OperatorIntegration
{
    $config = $config ?? \Domaintains\NumberSearch\Runtime::configuration();
    return new \Domaintains\NumberSearch\OperatorIntegration($config, \Domaintains\NumberSearch\Runtime::service($config),
        $config['clock'] ?? static fn(): float => microtime(true));
}