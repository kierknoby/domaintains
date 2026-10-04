<?php

function domaintains_destinations() {
	$module = \FreePBX::Domaintains();
	if (!$module->testDestinationRegistered()) {
		return [];
	}
	return [[
		'destination' => \FreePBX\modules\Domaintains::TEST_DESTINATION,
		'description' => _('DOMAINTAINS Test'),
		'category' => 'DOMAINTAINS',
	]];
}

function domaintains_getdestinfo($destination) {
	if ($destination !== \FreePBX\modules\Domaintains::TEST_DESTINATION || !\FreePBX::Domaintains()->testDestinationRegistered()) {
		return false;
	}
	return ['description' => _('DOMAINTAINS Test'), 'edit_url' => 'config.php?display=domaintains'];
}

function domaintains_get_config($engine) {
	global $ext;
	if ($engine === 'asterisk') {
		\FreePBX::Domaintains()->contributeTestDialplan($ext);
	}
}
