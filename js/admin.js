/* global OC */
(function () {
	'use strict';

	const apiBase = OC.generateUrl('/apps/nextcloud_migrate/api/v1');
	let currentInstanceId = null;
	let selectedRunId = null;

	function apiFetch(path, options) {
		options = options || {};
		options.headers = Object.assign({
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		}, options.headers || {});
		return fetch(apiBase + path, options).then(function (response) {
			if (!response.ok) {
				return response.json().catch(function () {
					return {};
				}).then(function (body) {
					throw new Error(body.error || ('Request failed: ' + response.status));
				});
			}
			if (response.status === 204) {
				return null;
			}
			return response.json();
		});
	}

	// --- Target instance (v1 supports exactly one per admin) ---

	function loadInstance() {
		return apiFetch('/instances').then(function (instances) {
			const instance = instances[0] || null;
			const form = document.getElementById('ncm-instance-form');
			const status = document.getElementById('ncm-instance-status');
			const deleteBtn = document.getElementById('ncm-instance-delete');
			const testBtn = document.getElementById('ncm-instance-test');

			if (instance) {
				currentInstanceId = instance.id;
				form.label.value = instance.label || '';
				form.url.value = instance.url;
				form.targetUserId.value = instance.targetUserId;
				form.appPassword.value = '';
				form.allowSelfSigned.checked = !!instance.allowSelfSigned;

				const tested = instance.lastTestedAt
					? new Date(instance.lastTestedAt * 1000).toLocaleString()
					: 'never';
				status.textContent = 'Configured: ' + instance.url + ' (' + instance.targetUserId + '). Last tested: ' + tested
					+ (instance.lastTestError ? ' - ' + instance.lastTestError : '');
				deleteBtn.hidden = false;
				testBtn.hidden = false;
			} else {
				currentInstanceId = null;
				form.reset();
				status.textContent = 'No target instance configured yet.';
				deleteBtn.hidden = true;
				testBtn.hidden = true;
			}
		});
	}

	document.getElementById('ncm-instance-form').addEventListener('submit', function (event) {
		event.preventDefault();
		const form = event.target;
		apiFetch('/instances', {
			method: 'POST',
			body: JSON.stringify({
				label: form.label.value,
				url: form.url.value,
				targetUserId: form.targetUserId.value,
				appPassword: form.appPassword.value,
				allowSelfSigned: form.allowSelfSigned.checked,
			}),
		}).then(loadInstance).catch(function (e) {
			OC.Notification.showTemporary('Failed to save target instance: ' + e.message);
		});
	});

	document.getElementById('ncm-instance-test').addEventListener('click', function () {
		if (!currentInstanceId) {
			return;
		}
		apiFetch('/instances/' + currentInstanceId + '/test', { method: 'POST' })
			.then(loadInstance)
			.catch(function (e) {
				OC.Notification.showTemporary('Connection test failed: ' + e.message);
			});
	});

	document.getElementById('ncm-instance-delete').addEventListener('click', function () {
		if (!currentInstanceId) {
			return;
		}
		apiFetch('/instances/' + currentInstanceId, { method: 'DELETE' })
			.then(loadInstance)
			.catch(function (e) {
				OC.Notification.showTemporary('Failed to remove target instance: ' + e.message);
			});
	});

	// --- Migration run (v1 shows only the current/latest run) ---

	function showCreateForm() {
		selectedRunId = null;
		document.getElementById('ncm-create-run-form').hidden = false;
		document.getElementById('ncm-run-detail').hidden = true;
	}

	function showRunDetail(runId) {
		selectedRunId = runId;
		document.getElementById('ncm-create-run-form').hidden = true;
		document.getElementById('ncm-run-detail').hidden = false;
		refreshRunDetail();
	}

	function loadCurrentRun() {
		return apiFetch('/runs').then(function (runs) {
			if (runs.length > 0) {
				showRunDetail(runs[0].id);
			} else {
				showCreateForm();
			}
		});
	}

	function refreshRunDetail() {
		if (!selectedRunId) {
			return;
		}
		apiFetch('/runs/' + selectedRunId + '/status').then(function (status) {
			document.getElementById('ncm-run-detail-content').textContent = JSON.stringify(status, null, 2);
		});
	}

	document.getElementById('ncm-create-run-form').addEventListener('submit', function (event) {
		event.preventDefault();
		const form = event.target;
		const userMappings = {};
		form.userMappings.value.split('\n').forEach(function (line) {
			const parts = line.split(':').map(function (s) {
				return s.trim();
			});
			if (parts.length === 2 && parts[0] && parts[1]) {
				userMappings[parts[0]] = parts[1];
			}
		});

		apiFetch('/runs', {
			method: 'POST',
			body: JSON.stringify({
				collisionStrategy: form.collisionStrategy.value,
				userMappings: userMappings,
			}),
		}).then(function (run) {
			form.reset();
			showRunDetail(run.id);
		}).catch(function (e) {
			OC.Notification.showTemporary('Failed to create run: ' + e.message);
		});
	});

	['dry-run', 'approve', 'pause', 'resume', 'cancel'].forEach(function (action) {
		document.getElementById('ncm-run-' + action).addEventListener('click', function () {
			if (!selectedRunId) {
				return;
			}
			apiFetch('/runs/' + selectedRunId + '/' + action, { method: 'POST' })
				.then(refreshRunDetail)
				.catch(function (e) {
					OC.Notification.showTemporary('Action failed: ' + e.message);
				});
		});
	});

	document.getElementById('ncm-run-refresh').addEventListener('click', refreshRunDetail);
	document.getElementById('ncm-run-new').addEventListener('click', showCreateForm);

	loadInstance();
	loadCurrentRun();
}());

