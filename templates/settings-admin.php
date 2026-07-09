<?php
/**
 * SPDX-FileCopyrightText: 2026 Kiga
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */

$values = $_['values'];
$actionUrl = \OC::$server->getURLGenerator()->linkTo('', 'apps/ironclaw_talk_bridge/settings/admin/save');
?>

<div class="section" id="ironclaw-talk-bridge-admin-settings">
	<h2>Ironclaw Talk Bridge</h2>
	<p>Serverseitiger Trigger fuer Nextcloud Talk ohne pro-Raum-Botaktivierung.</p>

	<form method="post" action="<?php p($actionUrl); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

		<p>
			<input
				type="checkbox"
				id="ictb_enabled"
				name="enabled"
				value="1"
				<?php if ($values['enabled']) { p('checked'); } ?>>
			<label for="ictb_enabled"><strong>Bridge aktivieren</strong></label>
		</p>

		<p>
			<input
				type="checkbox"
				id="ictb_strict_membership"
				name="strict_membership_resolver"
				value="1"
				<?php if ($values['strict_membership_resolver']) { p('checked'); } ?>>
			<label for="ictb_strict_membership"><strong>Strikter Membership-Resolver (fail-closed)</strong></label>
		</p>

		<p>
			<label for="ictb_ironclaw_url"><strong>Ironclaw URL</strong></label><br>
			<input type="url" id="ictb_ironclaw_url" name="ironclaw_inbound_url" required style="width: 100%; max-width: 720px;" value="<?php p($values['ironclaw_inbound_url']); ?>" placeholder="https://ironclaw.example.tld/api/channels/nextcloud/inbound">
		</p>

		<p>
			<label for="ictb_fake_user_name"><strong>Fake User Name (Mention-Anzeigename)</strong></label><br>
			<input type="text" id="ictb_fake_user_name" name="fake_user_name" required style="width: 100%; max-width: 480px;" value="<?php p($values['fake_user_name']); ?>" placeholder="Ironclaw">
		</p>

		<p>
			<label for="ictb_fake_user_id"><strong>Fake User ID (fuer Loop-Schutz, optional aber empfohlen)</strong></label><br>
			<input type="text" id="ictb_fake_user_id" name="fake_user_id" style="width: 100%; max-width: 480px;" value="<?php p($values['fake_user_id']); ?>" placeholder="ironclaw-bot-userid">
		</p>

		<p>
			<label for="ictb_secret"><strong>Authentifizierung gegenueber Ironclaw (Shared Secret)</strong></label><br>
			<input type="password" id="ictb_secret" name="ironclaw_shared_secret" autocomplete="new-password" style="width: 100%; max-width: 480px;" placeholder="Neues Secret setzen oder leer lassen">
			<br>
			<em><?php if ($_['secretConfigured']) { p('Secret ist gesetzt (leer lassen, um es unveraendert zu lassen).'); } else { p('Noch kein Secret gesetzt.'); } ?></em>
		</p>

		<p>
			<label for="ictb_allowlist"><strong>Raum-Allowlist Tokens (optional, CSV)</strong></label><br>
			<input type="text" id="ictb_allowlist" name="room_allowlist_tokens" style="width: 100%; max-width: 720px;" value="<?php p($values['room_allowlist_tokens']); ?>" placeholder="token1,token2,token3">
		</p>

		<p>
			<label for="ictb_batch"><strong>Dispatch Batch Size</strong></label><br>
			<input type="number" id="ictb_batch" name="dispatch_batch_size" min="1" max="500" style="width: 140px;" value="<?php p($values['dispatch_batch_size']); ?>">
		</p>

		<p>
			<label for="ictb_sig_tol"><strong>Signature Tolerance (Sekunden)</strong></label><br>
			<input type="number" id="ictb_sig_tol" name="signature_tolerance_seconds" min="60" max="3600" style="width: 140px;" value="<?php p($values['signature_tolerance_seconds']); ?>">
		</p>

		<p>
			<button class="primary" type="submit">Einstellungen speichern</button>
		</p>
	</form>
</div>
