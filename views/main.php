<?php
/**
 * DOMAINTAINS main view.
 *
 * @var string $moduleVersion
 * @var array $status
 * @var string $activationCsrfToken
 * @var array $activationMessage
 */
if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

$moduleVersion = isset($moduleVersion) ? (string)$moduleVersion : '';
$status = isset($status) && is_array($status) ? $status : [];
$state = isset($status['state']) ? (string)$status['state'] : 'unknown';
$stateLabel = $state === 'unprovisioned' ? _('Not activated') : ucfirst($state);
$activationCsrfToken = isset($activationCsrfToken) ? (string)$activationCsrfToken : '';
$activationMessage = isset($activationMessage) && is_array($activationMessage) ? $activationMessage : [];
?>
<div class="fpbx-container">
	<div class="row">
		<div class="col-md-12">
			<h2><?php echo _('DOMAINTAINS'); ?></h2>
			<p class="help-block">
				<?php echo _('Connect this FreePBX installation to your DOMAINTAINS service.'); ?>
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
								<th><?php echo _('Module version'); ?></th>
								<td><?php echo htmlspecialchars($moduleVersion, ENT_QUOTES, 'UTF-8'); ?></td>
							</tr>
							<?php if (isset($status['last_error'])): ?>
							<tr>
								<th><?php echo _('Last error'); ?></th>
								<td><?php echo htmlspecialchars($status['last_error'], ENT_QUOTES, 'UTF-8'); ?></td>
							</tr>
							<?php endif; ?>
							<?php if (!empty($status['provisioned'])): ?>
							<?php foreach (['inbound_numbers' => _('Inbound numbers'), 'inbound_trunks' => _('Inbound trunks'), 'outbound_trunks' => _('Outbound trunks')] as $field => $label): ?>
							<tr>
								<th><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></th>
								<td><?php echo (int)$status[$field]; ?></td>
							</tr>
							<?php endforeach; ?>
							<tr><th><?php echo _('Test destination'); ?></th><td><?php echo _('Ready'); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<?php if (!empty($activationMessage['message'])): ?>
		<div class="alert <?php echo !empty($activationMessage['success']) ? 'alert-success' : 'alert-danger'; ?>" role="alert">
			<?php echo htmlspecialchars((string)$activationMessage['message'], ENT_QUOTES, 'UTF-8'); ?>
		</div>
	<?php endif; ?>

	<?php if (empty($status['provisioned']) && $state !== 'error'): ?>
		<div class="row">
			<div class="col-md-8">
				<div class="panel panel-default">
					<div class="panel-heading"><strong><?php echo _('Activate DOMAINTAINS'); ?></strong></div>
					<div class="panel-body">
						<form method="post" class="form-horizontal" autocomplete="off">
							<input type="hidden" name="domaintains_action" value="activate">
							<input type="hidden" name="domaintains_csrf" value="<?php echo htmlspecialchars($activationCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
							<div class="form-group">
								<label for="activation-key" class="col-sm-3 control-label"><?php echo _('Activation key'); ?></label>
								<div class="col-sm-9">
									<input type="password" class="form-control" id="activation-key" name="activation_key" required autocomplete="new-password">
								</div>
							</div>
							<div class="form-group">
								<div class="col-sm-offset-3 col-sm-9">
									<button type="submit" class="btn btn-primary"><?php echo _('Activate'); ?></button>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<?php if (in_array($state, ['provisioned', 'pending'], true)): ?>
		<div class="row">
			<div class="col-md-8">
				<form method="post" autocomplete="off">
					<input type="hidden" name="domaintains_action" value="reactivate">
					<input type="hidden" name="domaintains_csrf" value="<?php echo htmlspecialchars($activationCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
					<div class="form-group">
						<label for="reactivation-key"><?php echo _('New activation key'); ?></label>
						<input type="password" id="reactivation-key" name="activation_key" class="form-control" required autocomplete="new-password">
					</div>
					<div class="checkbox"><label><input type="checkbox" name="domaintains_confirm" value="yes" required> <?php echo _('Confirm replacement of activation authorization using the existing signing identity.'); ?></label></div>
					<button type="submit" class="btn btn-primary"><?php echo _('Reactivate'); ?></button>
				</form>
				<hr>
				<form method="post">
					<input type="hidden" name="domaintains_action" value="deactivate">
					<input type="hidden" name="domaintains_csrf" value="<?php echo htmlspecialchars($activationCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
					<div class="checkbox"><label><input type="checkbox" name="domaintains_confirm" value="yes" required> <?php echo _('Confirm unlinking activation. Signing identity and PBX configuration will remain.'); ?></label></div>
					<button type="submit" class="btn btn-danger"><?php echo _('Deactivate'); ?></button>
				</form>
			</div>
		</div>
	<?php endif; ?>
</div>
