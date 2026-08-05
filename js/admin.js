/* global OC */
(function () {
	'use strict';

	const apiBase = OC.generateUrl('/apps/nextcloud_migrate/api/v1');
	let currentInstanceId = null;
	let selectedRunId = null;
	let pollTimer = null;
	// Guards each run+state pair so the automatic discovery/transfer
	// kickoff (see maybeAutoAdvance) only ever fires once per transition,
	// even if two poll ticks race before the state actually changes.
	const autoAdvanced = {};
	// Once a run reaches one of these states there is nothing left for the
	// simplified panel to wait on, so polling stops until a new run starts.
	const POLL_STOP_STATES = ['completed', 'completed_with_errors', 'failed', 'cancelled', 'validation_failed'];
	const STATE_LABELS = {
		created: 'Preparing…',
		validating: 'Validating target connection and credentials…',
		validation_failed: 'Validation failed',
		discovering: 'Discovering files…',
		dry_run_ready: 'Discovery complete, starting transfer…',
		approved: 'Starting transfer…',
		transferring: 'Transferring files…',
		verifying: 'Verifying transferred files…',
		finalizing: 'Finalizing…',
		completed: 'Completed',
		completed_with_errors: 'Completed, with errors',
		failed: 'Failed',
		paused: 'Paused',
		cancelled: 'Cancelled',
	};

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
		stopPolling();
		document.getElementById('ncm-create-run-form').hidden = false;
		document.getElementById('ncm-run-detail').hidden = true;
		document.getElementById('ncm-quick-form').hidden = false;
		document.getElementById('ncm-quick-progress').hidden = true;
	}

	function showRunDetail(runId) {
		selectedRunId = runId;
		document.getElementById('ncm-create-run-form').hidden = true;
		document.getElementById('ncm-run-detail').hidden = false;
		document.getElementById('ncm-quick-form').hidden = true;
		document.getElementById('ncm-quick-progress').hidden = false;
		startPolling();
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

	function startPolling() {
		if (pollTimer) {
			return;
		}
		pollTimer = setInterval(refreshRunDetail, 4000);
	}

	function stopPolling() {
		if (pollTimer) {
			clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	// Kicks off discovery as soon as a run is created, and transfer as soon
	// as discovery finishes, so no manual "Start dry run"/"Approve" clicks
	// are needed (the manual buttons in the advanced section still work too
	// - e.g. hitting an already-in-progress transition here just yields a
	// harmless conflict response).
	function maybeAutoAdvance(run) {
		const key = run.id + ':' + run.state;
		if (autoAdvanced[key]) {
			return;
		}

		if (run.state === 'created') {
			autoAdvanced[key] = true;
			apiFetch('/runs/' + run.id + '/dry-run', { method: 'POST' }).catch(function (e) {
				OC.Notification.showTemporary('Failed to start discovery: ' + e.message);
			});
		} else if (run.state === 'dry_run_ready') {
			autoAdvanced[key] = true;
			apiFetch('/runs/' + run.id + '/approve', { method: 'POST' }).catch(function (e) {
				OC.Notification.showTemporary('Failed to start transfer: ' + e.message);
			});
		}
	}

	function formatBytes(bytes) {
		if (!bytes || bytes <= 0) {
			return '0 B';
		}
		const units = ['B', 'KB', 'MB', 'GB', 'TB'];
		let unitIndex = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
		const value = bytes / Math.pow(1024, unitIndex);
		return (unitIndex === 0 ? value : value.toFixed(1)) + ' ' + units[unitIndex];
	}

	function renderQuickProgress(status) {
		const run = status.run;

		document.getElementById('ncm-quick-status').textContent = STATE_LABELS[run.state] || run.state;
		document.getElementById('ncm-quick-progressbar').value = status.progressPercent;
		document.getElementById('ncm-quick-percent').textContent = status.progressPercent + '%';

		const errorEl = document.getElementById('ncm-quick-error');
		if (run.errorMessage) {
			errorEl.textContent = run.errorMessage;
			errorEl.hidden = false;
		} else {
			errorEl.hidden = true;
		}

		const tbody = document.querySelector('#ncm-quick-user-table tbody');
		tbody.innerHTML = '';
		status.userMaps.forEach(function (userMap) {
			const row = document.createElement('tr');
			[
				userMap.sourceUserId,
				userMap.targetUserId,
				String(userMap.totalFiles),
				String(userMap.transferredFiles),
				formatBytes(userMap.transferredBytes) + ' / ' + formatBytes(userMap.totalBytes),
			].forEach(function (text) {
				const cell = document.createElement('td');
				cell.textContent = text;
				row.appendChild(cell);
			});
			tbody.appendChild(row);
		});
	}

	function refreshRunDetail() {
		if (!selectedRunId) {
			return;
		}
		apiFetch('/runs/' + selectedRunId + '/status').then(function (status) {
			document.getElementById('ncm-run-detail-content').textContent = JSON.stringify(status, null, 2);
			renderQuickProgress(status);
			maybeAutoAdvance(status.run);

			if (POLL_STOP_STATES.indexOf(status.run.state) !== -1) {
				stopPolling();
			}
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
			renderMappingTable(users);
			renderQuickUsersTable(users);
		});
	}

	function renderMappingTable(users) {
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
	}

	function renderQuickUsersTable(users) {
		const tbody = document.querySelector('#ncm-quick-users-table tbody');
		tbody.innerHTML = '';

		users.forEach(function (user) {
			const row = document.createElement('tr');

			const includeCell = document.createElement('td');
			const includeCheckbox = document.createElement('input');
			includeCheckbox.type = 'checkbox';
			includeCheckbox.className = 'ncm-quick-include';
			includeCheckbox.dataset.sourceUserId = user.id;
			includeCell.appendChild(includeCheckbox);
			row.appendChild(includeCell);

			const nameCell = document.createElement('td');
			nameCell.textContent = user.displayName ? user.displayName + ' (' + user.id + ')' : user.id;
			row.appendChild(nameCell);

			tbody.appendChild(row);
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
				skipVerification: document.getElementById('ncm-skip-verification').checked,
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

	// --- Quick migration (simplified panel: auto discovery + auto
	// transfer start, progress bar, per-user table) ---

	document.getElementById('ncm-quick-form').addEventListener('submit', function (event) {
		event.preventDefault();
		const form = event.target;

		const userMappings = [];
		document.querySelectorAll('#ncm-quick-users-table tbody tr').forEach(function (row) {
			const includeCheckbox = row.querySelector('.ncm-quick-include');
			if (!includeCheckbox.checked) {
				return;
			}
			userMappings.push({
				sourceUserId: includeCheckbox.dataset.sourceUserId,
				targetUserId: includeCheckbox.dataset.sourceUserId,
				mode: 'auto',
			});
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

	document.getElementById('ncm-quick-cancel').addEventListener('click', function () {
		const runId = selectedRunId;
		if (!runId) {
			showCreateForm();
			return;
		}
		apiFetch('/runs/' + runId + '/cancel', { method: 'POST' })
			.catch(function () {
				// Already in a terminal state (completed/failed/etc.) - fine,
				// reset the UI below either way.
			})
			.then(function () {
				showCreateForm();
				loadLocalUsers();
			});
	});

	loadInstance();
	loadLocalUsers();
	loadCurrentRun();
}());

