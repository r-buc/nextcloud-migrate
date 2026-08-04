/* global OC */
(function () {
	'use strict';

	const apiBase = OC.generateUrl('/apps/nextcloud_migrate/api/v1');
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

	function el(tag, attrs, children) {
		const node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'text') {
				node.textContent = attrs[key];
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});
		(children || []).forEach(function (child) {
			node.appendChild(child);
		});
		return node;
	}

	function loadInstances() {
		return apiFetch('/instances').then(function (instances) {
			const tbody = document.querySelector('#ncm-instances-table tbody');
			tbody.innerHTML = '';
			const select = document.getElementById('ncm-run-instance-select');
			select.innerHTML = '';

			instances.forEach(function (instance) {
				const tested = instance.lastTestedAt
					? new Date(instance.lastTestedAt * 1000).toLocaleString()
					: 'never';
				const testBtn = el('button', { text: 'Test' });
				testBtn.addEventListener('click', function () {
					apiFetch('/instances/' + instance.id + '/test', { method: 'POST' })
						.then(function () {
							return loadInstances();
						})
						.catch(function (e) {
							OC.Notification.showTemporary('Connection test failed: ' + e.message);
						});
				});
				const deleteBtn = el('button', { text: 'Delete' });
				deleteBtn.addEventListener('click', function () {
					apiFetch('/instances/' + instance.id, { method: 'DELETE' })
						.then(loadInstances)
						.catch(function (e) {
							OC.Notification.showTemporary('Delete failed: ' + e.message);
						});
				});

				tbody.appendChild(el('tr', {}, [
					el('td', { text: instance.label || '(no label)' }),
					el('td', { text: instance.url }),
					el('td', { text: instance.targetUserId }),
					el('td', { text: tested + (instance.lastTestError ? ' - ' + instance.lastTestError : '') }),
					el('td', {}, [testBtn, deleteBtn]),
				]));

				select.appendChild(el('option', { value: instance.id, text: instance.label || instance.url }));
			});
		});
	}

	function loadRuns() {
		return apiFetch('/runs').then(function (runs) {
			const tbody = document.querySelector('#ncm-runs-table tbody');
			tbody.innerHTML = '';

			runs.forEach(function (run) {
				const progress = run.totalFiles > 0
					? Math.round((run.verifiedFiles / run.totalFiles) * 100) + '%'
					: '-';
				const viewBtn = el('button', { text: 'View' });
				viewBtn.addEventListener('click', function () {
					showRunDetail(run.id);
				});

				tbody.appendChild(el('tr', {}, [
					el('td', { text: String(run.id) }),
					el('td', { text: String(run.instanceId) }),
					el('td', { text: run.state }),
					el('td', { text: progress }),
					el('td', { text: new Date(run.createdAt * 1000).toLocaleString() }),
					el('td', {}, [viewBtn]),
				]));
			});
		});
	}

	function showRunDetail(runId) {
		selectedRunId = runId;
		const section = document.getElementById('ncm-run-detail');
		section.hidden = false;
		refreshRunDetail();
	}

	function refreshRunDetail() {
		if (!selectedRunId) {
			return;
		}
		apiFetch('/runs/' + selectedRunId + '/status').then(function (status) {
			document.getElementById('ncm-run-detail-content').textContent = JSON.stringify(status, null, 2);
		});
	}

	document.getElementById('ncm-create-instance-form').addEventListener('submit', function (event) {
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
		}).then(function () {
			form.reset();
			return loadInstances();
		}).catch(function (e) {
			OC.Notification.showTemporary('Failed to add instance: ' + e.message);
		});
	});

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
				instanceId: parseInt(form.instanceId.value, 10),
				collisionStrategy: form.collisionStrategy.value,
				userMappings: userMappings,
			}),
		}).then(function (run) {
			form.reset();
			return loadRuns().then(function () {
				showRunDetail(run.id);
			});
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
				.then(function () {
					return Promise.all([refreshRunDetail(), loadRuns()]);
				})
				.catch(function (e) {
					OC.Notification.showTemporary('Action failed: ' + e.message);
				});
		});
	});

	document.getElementById('ncm-run-refresh').addEventListener('click', refreshRunDetail);

	loadInstances();
	loadRuns();
}());
