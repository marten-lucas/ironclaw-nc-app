<?php
/**
 * SPDX-FileCopyrightText: 2026 Kiga
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */

$values = $_['values'];
$users = $_['users'] ?? [];
$uiStatus = $_['uiStatus'] ?? '';
$uiMessage = $_['uiMessage'] ?? '';
$actionUrl = \OC::$server->getURLGenerator()->linkToRoute('ironclaw_talk_bridge.Settings.save');
$testUrl = \OC::$server->getURLGenerator()->linkToRoute('ironclaw_talk_bridge.Settings.testConnection');
?>

<div class="section" id="ironclaw-talk-bridge-admin-settings">
	<h2>Ironclaw Talk Bridge</h2>
	<p>Serverseitiger Trigger fuer Nextcloud Talk ohne pro-Raum-Botaktivierung.</p>

	<?php if ($uiMessage !== ''): ?>
		<p style="padding: 10px 12px; border-radius: 6px; background: <?php p($uiStatus === 'success' ? '#e9f7ef' : '#fdecec'); ?>; color: <?php p($uiStatus === 'success' ? '#176b2c' : '#8a1f1f'); ?>; max-width: 720px;">
			<?php p((string)$uiMessage); ?>
		</p>
	<?php endif; ?>

	<form method="post" action="<?php p($actionUrl); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<input type="hidden" name="enabled_present" value="1">

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
			<label for="ictb_ironclaw_url"><strong>Ironclaw URL</strong></label><br>
			<input type="url" id="ictb_ironclaw_url" name="ironclaw_inbound_url" required style="width: 100%; max-width: 720px;" value="<?php p($values['ironclaw_inbound_url']); ?>" placeholder="https://ironclaw.example.tld/api/channels/nextcloud/inbound">
		</p>

		<p>
			<label for="ictb_fake_user_id"><strong>Fake User (Name + ID)</strong></label><br>
			<input type="text" id="ictb_fake_user_name" name="fake_user_name" readonly style="width: 100%; max-width: 480px; margin-bottom: 8px;" value="<?php p($values['fake_user_name']); ?>" placeholder="Display Name wird aus ID aufgeloest">
			<select id="ictb_fake_user_id" name="fake_user_id" required style="width: 100%; max-width: 480px;">
				<option value="">Bitte Benutzer waehlen</option>
				<?php foreach ($users as $user): ?>
					<option
						value="<?php p((string)$user['uid']); ?>"
						data-display-name="<?php p((string)$user['displayName']); ?>"
						<?php if ((string)$values['fake_user_id'] === (string)$user['uid']) { p('selected'); } ?>>
						<?php p((string)$user['displayName'] . ' (' . (string)$user['uid'] . ')'); ?>
					</option>
				<?php endforeach; ?>
			</select>
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
			<label for="ictb_sig_tol"><strong>Signature Tolerance (Sekunden)</strong></label><br>
			<input type="number" id="ictb_sig_tol" name="signature_tolerance_seconds" min="60" max="3600" style="width: 140px;" value="<?php p($values['signature_tolerance_seconds']); ?>">
		</p>

		<p>
			<button type="button" id="ictb_test_connection">Verbindung testen</button>
			<span id="ictb_test_result" style="margin-left: 10px;"></span>
		</p>

		<p>
			<button class="primary" type="submit">Einstellungen speichern</button>
			<span id="ictb_save_result" style="margin-left: 10px;"></span>
		</p>
	</form>
</div>

<script>
(function () {
	const formEl = document.querySelector('#ironclaw-talk-bridge-admin-settings form');
	const button = document.getElementById('ictb_test_connection');
	const result = document.getElementById('ictb_test_result');
	const saveResult = document.getElementById('ictb_save_result');
	const urlInput = document.getElementById('ictb_ironclaw_url');
	const fakeUserSelect = document.getElementById('ictb_fake_user_id');
	const fakeUserNameInput = document.getElementById('ictb_fake_user_name');
	if (!formEl || !button || !result || !saveResult || !urlInput || !fakeUserSelect || !fakeUserNameInput) {
		return;
	}

	const syncFakeUserName = function () {
		const selected = fakeUserSelect.options[fakeUserSelect.selectedIndex];
		const displayName = selected ? String(selected.getAttribute('data-display-name') || '') : '';
		fakeUserNameInput.value = displayName;
	};

	fakeUserSelect.addEventListener('change', syncFakeUserName);
	syncFakeUserName();

	const requestToken = <?php echo json_encode((string)$_['requesttoken']); ?>;

	formEl.addEventListener('submit', async function (event) {
		event.preventDefault();
		saveResult.textContent = 'Speichere...';
		saveResult.style.color = '';

		const formData = new URLSearchParams(new FormData(formEl));

		try {
			const response = await fetch(formEl.action, {
				method: 'POST',
				headers: {
					'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
					'requesttoken': requestToken,
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: formData.toString(),
				credentials: 'same-origin',
			});

			let data = null;
			try {
				data = await response.json();
			} catch (e) {
				data = null;
			}

			const ok = response.ok && data && data.ok;
			saveResult.textContent = data && data.message ? data.message : (ok ? 'Einstellungen gespeichert.' : 'Speichern fehlgeschlagen.');
			saveResult.style.color = ok ? '#008a00' : '#b30000';
		} catch (error) {
			saveResult.textContent = 'Speichern fehlgeschlagen.';
			saveResult.style.color = '#b30000';
		}
	});

	button.addEventListener('click', async function () {
		result.textContent = 'Teste...';
		result.style.color = '';

		const form = new URLSearchParams();
		form.set('requesttoken', requestToken);
		form.set('ironclaw_inbound_url', String(urlInput.value || '').trim());

		try {
			const response = await fetch(<?php echo json_encode($testUrl); ?>, {
				method: 'POST',
				headers: {
					'requesttoken': requestToken,
					'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: form.toString(),
				credentials: 'same-origin',
			});

			const raw = await response.text();
			let data = null;
			try {
				data = JSON.parse(raw);
			} catch (e) {
				data = null;
			}

			if (!data) {
				if (response.status >= 500) {
					result.textContent = 'Verbindung vorhanden, aber Test-Endpunkt liefert Gateway/Serverfehler (HTTP ' + response.status + ').';
					result.style.color = '#b37a00';
					return;
				}

				result.textContent = 'Unbekannte Antwort';
				result.style.color = '#b30000';
				return;
			}

			result.textContent = data && data.message ? data.message : 'Unbekannte Antwort';
			const level = data && data.level ? String(data.level) : (data && data.ok ? 'green' : 'red');
			if (level === 'green') {
				result.style.color = '#008a00';
			} else if (level === 'yellow') {
				result.style.color = '#b37a00';
			} else {
				result.style.color = '#b30000';
			}
		} catch (error) {
			result.textContent = 'Verbindungstest fehlgeschlagen.';
			result.style.color = '#b30000';
		}
	});
})();
</script>
