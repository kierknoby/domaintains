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
</div>
