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
				form.url.value = instance.url;
				form.adminUserId.value = instance.adminUserId;
				form.adminAppPassword.value = '';
				form.allowSelfSigned.checked = !!instance.allowSelfSigned;

				const tested = instance.lastTestedAt
					? new Date(instance.lastTestedAt * 1000).toLocaleString()
					: 'never';
				status.textContent = 'Configured: ' + instance.url + ' (admin: ' + instance.adminUserId + '). Last tested: ' + tested
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
				url: form.url.value,
				adminUserId: form.adminUserId.value,
				adminAppPassword: form.adminAppPassword.value,
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

	// --- User mapping table (local users, with an expert-mode manual
	// target app password column) ---

	function setExpertMode(enabled) {
		document.querySelectorAll('.ncm-expert-col').forEach(function (el) {
			el.hidden = !enabled;
		});
	}

	function loadLocalUsers() {
		return apiFetch('/local-users').then(function (users) {
			const tbody = document.querySelector('#ncm-user-mappings-table tbody');
			tbody.innerHTML = '';

			users.forEach(function (user) {
				const row = document.createElement('tr');

				const includeCell = document.createElement('td');
				const includeCheckbox = document.createElement('input');
				includeCheckbox.type = 'checkbox';
				includeCheckbox.className = 'ncm-map-include';
				includeCheckbox.dataset.sourceUserId = user.id;
				includeCell.appendChild(includeCheckbox);
				row.appendChild(includeCell);

				const nameCell = document.createElement('td');
				nameCell.textContent = user.displayName ? user.displayName + ' (' + user.id + ')' : user.id;
				row.appendChild(nameCell);

				const targetCell = document.createElement('td');
				const targetInput = document.createElement('input');
				targetInput.type = 'text';
				targetInput.className = 'ncm-map-target';
				targetInput.value = user.id;
				targetCell.appendChild(targetInput);
				row.appendChild(targetCell);

				const passwordCell = document.createElement('td');
				passwordCell.className = 'ncm-expert-col';
				passwordCell.hidden = !document.getElementById('ncm-expert-mode').checked;
				const passwordInput = document.createElement('input');
				passwordInput.type = 'password';
				passwordInput.className = 'ncm-map-password';
				passwordCell.appendChild(passwordInput);
				row.appendChild(passwordCell);

				tbody.appendChild(row);
			});
		});
	}

	document.getElementById('ncm-expert-mode').addEventListener('change', function (event) {
		setExpertMode(event.target.checked);
	});

	document.getElementById('ncm-create-run-form').addEventListener('submit', function (event) {
		event.preventDefault();
		const form = event.target;
		const expertMode = document.getElementById('ncm-expert-mode').checked;

		const userMappings = [];
		document.querySelectorAll('#ncm-user-mappings-table tbody tr').forEach(function (row) {
			const includeCheckbox = row.querySelector('.ncm-map-include');
			if (!includeCheckbox.checked) {
				return;
			}
			const targetUserId = row.querySelector('.ncm-map-target').value.trim();
			if (!targetUserId) {
				return;
			}
			const mapping = {
				sourceUserId: includeCheckbox.dataset.sourceUserId,
				targetUserId: targetUserId,
				mode: expertMode ? 'manual' : 'auto',
			};
			if (expertMode) {
				mapping.appPassword = row.querySelector('.ncm-map-password').value;
			}
			userMappings.push(mapping);
		});

		if (userMappings.length === 0) {
			OC.Notification.showTemporary('Select at least one user to migrate.');
			return;
		}

		apiFetch('/runs', {
			method: 'POST',
			body: JSON.stringify({
				collisionStrategy: form.collisionStrategy.value,
				userMappings: userMappings,
			}),
		}).then(function (run) {
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
	document.getElementById('ncm-run-new').addEventListener('click', function () {
		showCreateForm();
		loadLocalUsers();
	});

	loadInstance();
	loadLocalUsers();
	loadCurrentRun();
}());

