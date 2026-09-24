<?php
/**
 * DOMAINTAINS for FreePBX 16 and 17.
 *
 * PBX-side integration boundary for DOMAINTAINS services orchestrated through
 * FreePBX UK's my-connect remote bridge.
 *
 * @copyright 2026 20 Telecom Ltd (trading as 20tele.com)
 * @license   GPLv3+
 */

namespace FreePBX\modules;

class Domaintains implements \BMO {

	/** Fallback only. Authoritative version lives in module.xml. */
	const VERSION = '0.1.0';

	/** @var \FreePBX */
	private $FreePBX;

	public function __construct($freepbx = null) {
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
	}

	public function install(): void {}
	public function uninstall(): void {}
	public function backup(): array { return []; }
	public function restore($backup): void {}
	public function doConfigPageInit($page): void {}

	public function getVersion(): string {
		try {
			$info = \FreePBX::Modules()->getInfo('domaintains');
			if (isset($info['domaintains']['version'])) {
				return (string)$info['domaintains']['version'];
			}
		} catch (\Exception $e) {
			// Module metadata may be unavailable during early install.
		}

		return self::VERSION;
	}

	/**
	 * Return the current DOMAINTAINS module state.
	 *
	 * Provisioning deliberately does not exist in the initial scaffold. The
	 * stable status shape is introduced first so GUI, CLI and later my-connect
	 * automation can consume the same module-owned state.
	 */
	public function getStatus(): array {
		return [
			'module' => 'DOMAINTAINS',
			'rawname' => 'domaintains',
			'version' => $this->getVersion(),
			'state' => 'unprovisioned',
			'provisioned' => false,
			'bridge' => 'my-connect',
			'freepbx_support' => ['16', '17'],
		];
	}

	public function showPage(): string {
		return load_view(__DIR__ . '/views/main.php', [
			'moduleVersion' => $this->getVersion(),
			'status' => $this->getStatus(),
		]);
	}
}
