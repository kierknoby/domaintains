<?php
/**
 * DOMAINTAINS main view.
 *
 * @var string $moduleVersion
 * @var array $status
 */
if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

$moduleVersion = isset($moduleVersion) ? (string)$moduleVersion : '';
$status = isset($status) && is_array($status) ? $status : [];
$state = isset($status['state']) ? (string)$status['state'] : 'unknown';
$stateLabel = $state === 'unprovisioned' ? _('Not provisioned') : ucfirst($state);
?>
<div class="fpbx-container">
	<div class="row">
		<div class="col-md-12">
			<h2><?php echo _('DOMAINTAINS'); ?></h2>
			<p class="help-block">
				<?php echo _('PBX-side SIP trunking and numbering integration for DOMAINTAINS services, orchestrated through FreePBX UK\'s my-connect.'); ?>
			</p>
		</div>
	</div>

	<div class="row">
		<div class="col-md-8">
			<div class="panel panel-default">
				<div class="panel-heading"><strong><?php echo _('Connectivity status'); ?></strong></div>
				<div class="panel-body">
					<table class="table table-striped table-condensed" style="margin-bottom:0;">
						<tbody>
							<tr>
								<th style="width:220px;"><?php echo _('Status'); ?></th>
								<td><?php echo htmlspecialchars($stateLabel, ENT_QUOTES, 'UTF-8'); ?></td>
							</tr>
							<tr>
								<th><?php echo _('Remote bridge'); ?></th>
								<td>my-connect</td>
							</tr>
							<tr>
								<th><?php echo _('Module version'); ?></th>
								<td><?php echo htmlspecialchars($moduleVersion, ENT_QUOTES, 'UTF-8'); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<div class="alert alert-info" role="alert">
		<strong><?php echo _('Development scaffold'); ?></strong><br>
		<?php echo _('This release reports module status only. SIP trunk, routing, numbering, verification and removal actions are not implemented yet.'); ?>
	</div>
</div>
