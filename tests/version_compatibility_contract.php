<?php

declare(strict_types=1);

function compatibility_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function xml_first(string $xml, string $tag): string {
	$pattern = '/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/s';
	if (!preg_match($pattern, $xml, $matches)) {
		return '';
	}
	return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

$root = dirname(__DIR__);
$xml = file_get_contents($root . '/module.xml');
compatibility_assert($xml !== false && $xml !== '', 'module.xml should be readable');
compatibility_assert(xml_first($xml, 'rawname') === 'domaintains', 'raw module name should be domaintains');
compatibility_assert(xml_first($xml, 'name') === 'DOMAINTAINS', 'display name should be DOMAINTAINS');
compatibility_assert(xml_first($xml, 'version') === '0.1.0', 'module version should be 0.1.0');
compatibility_assert(xml_first($xml, 'publisher') === 'FreePBX UK', 'publisher should be FreePBX UK');
compatibility_assert(xml_first($xml, 'license') === 'GPLv3+', 'module licence metadata should be GPLv3+');

compatibility_assert((bool)preg_match('/<depends>\s*<version>16\.0<\/version>\s*<\/depends>/s', $xml), 'minimum FreePBX version should be 16.0');
compatibility_assert((bool)preg_match('/<supported>.*<version>16\.0<\/version>.*<version>17\.0<\/version>.*<\/supported>/s', $xml), 'supported metadata should include FreePBX 16.0 and 17.0');

$productionFiles = [
	'install.php',
	'uninstall.php',
	'Domaintains.class.php',
	'page.domaintains.php',
	'Console/Domaintains.class.php',
	'views/main.php',
];

$phpEightConstructs = [
	'/\?->/' => 'nullsafe operator',
	'/#\[/' => 'attributes',
	'/\bmatch\s*\(/' => 'match expression',
	'/\b(?:enum|readonly)\s+[A-Za-z_]/' => 'enum or readonly declaration',
	'/\bstr_(?:contains|starts_with|ends_with)\s*\(/' => 'PHP 8 string helper',
];

foreach ($productionFiles as $file) {
	$source = file_get_contents($root . '/' . $file);
	compatibility_assert($source !== false, $file . ' should be readable');
	foreach ($phpEightConstructs as $pattern => $description) {
		compatibility_assert(!preg_match($pattern, $source), $file . ' should not use PHP 8-only ' . $description);
	}
}

$readme = file_get_contents($root . '/README.md');
$moduleClass = file_get_contents($root . '/Domaintains.class.php');
$consoleClass = file_get_contents($root . '/Console/Domaintains.class.php');

compatibility_assert(strpos($readme, 'FreePBX/PBXact 16 or 17') !== false, 'README compatibility should cover FreePBX/PBXact 16 and 17');
compatibility_assert(strpos($moduleClass, 'FreePBX 16 and 17') !== false, 'module documentation should identify FreePBX 16 and 17 support');
compatibility_assert(strpos($consoleClass, "->setName('domaintains')") !== false, 'fwconsole command should be named domaintains');
compatibility_assert(strpos($consoleClass, "'status'") !== false, 'initial CLI should implement status');
compatibility_assert(!file_exists($root . '/module.sig'), 'development scaffold must not include a copied module signature');

echo "Version compatibility contract passed.\n";
