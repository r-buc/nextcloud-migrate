<template>
	<section id="ncm-run">
		<h3>Migration run</h3>

		<NcNoteCard v-if="errorText" type="error">
			{{ errorText }}
		</NcNoteCard>

		<div v-if="!instanceConfigured" class="settings-hint">
			Configure a target instance above before starting a migration.
		</div>

		<form v-else-if="!run" class="ncm-form" @submit.prevent="createRun">
			<div class="ncm-field">
				<label for="ncm-collision">Collision strategy</label>
				<select id="ncm-collision" v-model="form.collisionStrategy">
					<option value="rename">
						Rename on collision (default)
					</option>
					<option value="skip">
						Skip on collision
					</option>
					<option value="overwrite">
						Always overwrite on collision
					</option>
					<option value="overwrite_newer">
						Overwrite only if source is newer (else skip)
					</option>
				</select>
			</div>

			<table class="grid ncm-users-table">
				<thead>
					<tr>
						<th />
						<th>Local user</th>
						<th v-if="showAdvanced">
							Target username
						</th>
						<th v-if="showAdvanced">
							Target app password
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="user in localUsers" :key="user.id">
						<td>
							<input v-model="user.include" type="checkbox">
						</td>
						<td>{{ user.displayName ? `${user.displayName} (${user.id})` : user.id }}</td>
						<td v-if="showAdvanced">
							<input v-model="user.targetUserId" type="text">
						</td>
						<td v-if="showAdvanced">
							<input v-model="user.appPassword" type="password" placeholder="only used in expert mode">
						</td>
					</tr>
				</tbody>
			</table>

			<NcButton variant="tertiary" class="ncm-advanced-toggle" @click="showAdvanced = !showAdvanced">
				{{ showAdvanced ? 'Hide advanced options' : 'Advanced options' }}
			</NcButton>

			<div v-if="showAdvanced" class="ncm-advanced-options">
				<NcCheckboxRadioSwitch v-model="form.expertMode">
					Expert mode: supply each user's own target app password above
					instead of auto-creating/resetting the target account
				</NcCheckboxRadioSwitch>
				<p class="settings-hint">
					By default, each selected user's target account is created (or
					its password reset) automatically via the target instance's
					admin credentials - no need to know each user's own password.
				</p>
				<NcCheckboxRadioSwitch v-model="form.skipVerification">
					Skip post-transfer verification
				</NcCheckboxRadioSwitch>
				<p class="settings-hint">
					The target already validates each file's checksum at upload
					time and rejects the write on a mismatch, so this is safe to
					enable for faster migrations. Leave unchecked (default) to
					additionally re-download every file afterwards and compare
					checksums, which also catches rarer issues such as storage
					corruption on the target after a successful upload.
				</p>
			</div>

			<div class="ncm-actions">
				<NcButton type="submit" variant="primary" :disabled="creating">
					Start
				</NcButton>
			</div>
		</form>

		<div v-else class="ncm-progress">
			<p class="settings-hint">
				{{ stateLabel }}
			</p>
			<div class="ncm-progress-row">
				<NcProgressBar size="medium" :value="status ? status.progressPercent : 0" />
				<span class="ncm-percent">{{ status ? status.progressPercent : 0 }}%</span>
			</div>

			<table v-if="status" class="grid ncm-users-table">
				<thead>
					<tr>
						<th>Local user</th>
						<th>Target user</th>
						<th>Files</th>
						<th>Transferred</th>
						<th>Failed</th>
						<th>Volume</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="userMap in status.userMaps" :key="userMap.id">
						<td>{{ userMap.sourceUserId }}</td>
						<td>{{ userMap.targetUserId }}</td>
						<td>{{ userMap.totalFiles }}</td>
						<td>{{ userMap.transferredFiles }}</td>
						<td>{{ userMap.failedFiles }}</td>
						<td>
							<span>{{ formatBytes(userMap.transferredBytes) }} / {{ formatBytes(userMap.totalBytes) }}</span>
							<NcProgressBar size="small" :value="volumePercent(userMap)" />
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="status && status.run.failedFiles > 0" class="ncm-failures">
				<NcButton variant="tertiary" @click="toggleFailures">
					{{ showFailures ? 'Hide' : 'Show' }} failed files ({{ status.run.failedFiles }})
				</NcButton>
				<NcButton v-if="canRetryFailures" :disabled="retrying" @click="retryFailures">
					Retry failed files
				</NcButton>

				<table v-if="showFailures" class="grid ncm-failures-table">
					<thead>
						<tr>
							<th>User</th>
							<th>Path</th>
							<th>Stage</th>
							<th>Attempts</th>
							<th>Status</th>
							<th>Error</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="file in failures" :key="file.id">
							<td>{{ failureUserLabel(file) }}</td>
							<td>{{ file.targetPath || file.sourcePath }}</td>
							<td>{{ failureStageLabel(file.state) }}</td>
							<td>{{ failureAttempts(file) }}</td>
							<td>{{ file.nextRetryAt ? 'Will retry' : 'Permanently failed' }}</td>
							<td>{{ file.lastError }}</td>
						</tr>
						<tr v-if="!loadingFailures && failures.length === 0">
							<td colspan="6">
								No failed files loaded yet.
							</td>
						</tr>
					</tbody>
				</table>

				<div v-if="showFailures" class="ncm-failures-pagination">
					<span class="settings-hint">Page {{ failuresPage }} of {{ totalFailuresPages }} ({{ status.run.failedFiles }} failed file(s) total)</span>
					<NcButton variant="tertiary" :disabled="loadingFailures || failuresPage <= 1" @click="prevFailuresPage">
						Previous
					</NcButton>
					<NcButton variant="tertiary" :disabled="loadingFailures || failuresPage >= totalFailuresPages" @click="nextFailuresPage">
						Next
					</NcButton>
				</div>
			</div>

			<div class="ncm-actions">
				<NcButton v-if="isDone" variant="primary" :disabled="cancelling" @click="closeRun">
					Done
				</NcButton>
				<NcButton v-else :disabled="cancelling" @click="cancel">
					Cancel
				</NcButton>
				<NcButton variant="tertiary" @click="showAdvanced = !showAdvanced">
					{{ showAdvanced ? 'Hide advanced controls' : 'Advanced controls' }}
				</NcButton>
			</div>

			<div v-if="showAdvanced" class="ncm-advanced-options">
				<div class="ncm-actions">
					<NcButton :disabled="actionPending" @click="runAction('dry-run')">
						Retry dry run
					</NcButton>
					<NcButton :disabled="actionPending" @click="runAction('approve')">
						Approve &amp; start transfer
					</NcButton>
					<NcButton :disabled="actionPending" @click="runAction('pause')">
						Pause
					</NcButton>
					<NcButton :disabled="actionPending" @click="runAction('resume')">
						Resume
					</NcButton>
					<NcButton :disabled="actionPending" @click="refreshStatus">
						Refresh
					</NcButton>
				</div>
				<pre class="ncm-raw-status">{{ JSON.stringify(status, null, 2) }}</pre>
			</div>
		</div>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import api, { apiErrorMessage } from '../api.js'

// Once a run reaches one of these states there is nothing left to wait on,
// so polling stops until a new run starts.
const POLL_STOP_STATES = ['completed', 'completed_with_errors', 'failed', 'cancelled', 'validation_failed']

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
}

// Friendly labels for a failed file's `state` column (which stage it failed
// in) - mapping_failed means the pre-transfer collision check against the
// target failed (e.g. target unreachable), not an unresolvable naming
// conflict (those are auto-resolved per the run's collision strategy and
// never fail).
const FAILURE_STAGE_LABELS = {
	mapping_failed: 'Collision check',
	transfer_failed: 'Transfer',
	verification_failed: 'Verification',
}

// Failed-files list is paginated (a run can have thousands of failures),
// shown 20 at a time with page navigation rather than an ever-growing
// "load more" list. The server allows up to 500 per request (see
// StatusController::runFailures()) - well above what's needed here.
const FAILURES_PAGE_SIZE = 20

export default {
	name: 'MigrationPanel',
	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcProgressBar },
	props: {
		instanceConfigured: {
			type: Boolean,
			required: true,
		},
	},
	data() {
		return {
			localUsers: [],
			run: null,
			status: null,
			form: {
				collisionStrategy: 'rename',
				expertMode: false,
				skipVerification: false,
			},
			showAdvanced: false,
			creating: false,
			cancelling: false,
			actionPending: false,
			errorText: '',
			pollTimer: null,
			autoAdvanced: {},
			showFailures: false,
			failures: [],
			failuresPage: 1,
			loadingFailures: false,
			retrying: false,
		}
	},
	computed: {
		stateLabel() {
			return (this.run && STATE_LABELS[this.run.state]) || (this.run && this.run.state) || ''
		},
		// Total pages for the failed-files list, derived from the live
		// failedFiles count (rather than a snapshot taken when the list was
		// opened) so it stays correct even if more files fail while the list
		// is open - though the currently-viewed page's contents only refresh
		// when explicitly navigated (see refreshStatus()).
		totalFailuresPages() {
			if (!this.status || this.status.run.failedFiles <= 0) {
				return 1
			}
			return Math.max(1, Math.ceil(this.status.run.failedFiles / FAILURES_PAGE_SIZE))
		},
		// Nothing will ever happen to the run on its own past these states
		// (mirrors POLL_STOP_STATES) - once here, "Cancel" no longer makes
		// sense, so the button becomes "Done" and removes the run instead.
		isDone() {
			return !!this.run && POLL_STOP_STATES.includes(this.run.state)
		},
		// Mirrors the backend's RunOrchestrator::retryFailures() allowed
		// states - only a run that finished WITH failures can be retried.
		canRetryFailures() {
			return !!this.run && ['completed_with_errors', 'failed'].includes(this.run.state)
		},
	},
	mounted() {
		this.loadLocalUsers()
		this.loadCurrentRun()
	},
	beforeDestroy() {
		this.stopPolling()
	},
	methods: {
		loadLocalUsers() {
			return api.get('/local-users').then((users) => {
				this.localUsers = users.map((user) => ({
					id: user.id,
					displayName: user.displayName,
					include: false,
					targetUserId: user.id,
					appPassword: '',
				}))
			})
		},
		loadCurrentRun() {
			return api.get('/runs').then((runs) => {
				if (runs.length > 0) {
					this.run = runs[0]
					this.startPolling()
					this.refreshStatus()
				} else {
					this.run = null
					this.status = null
				}
			})
		},
		createRun() {
			const userMappings = this.localUsers
				.filter((user) => user.include)
				.map((user) => {
					const mapping = {
						sourceUserId: user.id,
						targetUserId: user.targetUserId || user.id,
						mode: this.form.expertMode ? 'manual' : 'auto',
					}
					if (this.form.expertMode) {
						mapping.appPassword = user.appPassword
					}
					return mapping
				})

			if (userMappings.length === 0) {
				this.errorText = 'Select at least one user to migrate.'
				return
			}

			this.creating = true
			this.errorText = ''
			api.post('/runs', {
				collisionStrategy: this.form.collisionStrategy,
				userMappings,
				skipVerification: this.form.skipVerification,
			}).then((run) => {
				this.run = run
				this.showAdvanced = false
				this.startPolling()
				this.refreshStatus()
			}).catch((e) => {
				this.errorText = `Failed to create run: ${apiErrorMessage(e)}`
			}).finally(() => {
				this.creating = false
			})
		},
		startPolling() {
			if (this.pollTimer) {
				return
			}
			this.pollTimer = setInterval(this.refreshStatus, 4000)
		},
		stopPolling() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},
		refreshStatus() {
			if (!this.run) {
				return
			}
			return api.get(`/runs/${this.run.id}/status`).then((status) => {
				this.status = status
				this.run = status.run
				this.maybeAutoAdvance(status.run)
				if (POLL_STOP_STATES.includes(status.run.state)) {
					this.stopPolling()
				}
				// Not auto-refreshing an already-open failed-files list
				// here (unlike the rest of the status view): with
				// potentially thousands of entries the list is paginated
				// (see fetchFailuresPage()), and reloading on every poll
				// tick would jump the admin back to a stale view of
				// whichever page they're currently looking at. Toggling
				// the list closed and open again, or navigating pages,
				// fetches current data.
			})
		},
		// Kicks off discovery as soon as a run is created, and transfer as
		// soon as discovery finishes, so no manual clicks are needed. The
		// advanced controls below still work too - hitting an
		// already-in-progress transition just yields a harmless conflict
		// response.
		maybeAutoAdvance(run) {
			const key = `${run.id}:${run.state}`
			if (this.autoAdvanced[key]) {
				return
			}
			if (run.state === 'created') {
				this.autoAdvanced[key] = true
				api.post(`/runs/${run.id}/dry-run`).catch((e) => {
					this.errorText = `Failed to start discovery: ${apiErrorMessage(e)}`
				})
			} else if (run.state === 'dry_run_ready') {
				this.autoAdvanced[key] = true
				api.post(`/runs/${run.id}/approve`).catch((e) => {
					this.errorText = `Failed to start transfer: ${apiErrorMessage(e)}`
				})
			}
		},
		runAction(action) {
			if (!this.run) {
				return
			}
			this.actionPending = true
			api.post(`/runs/${this.run.id}/${action}`)
				.then(() => this.refreshStatus())
				.catch((e) => {
					this.errorText = `Action failed: ${apiErrorMessage(e)}`
				})
				.finally(() => {
					this.actionPending = false
				})
		},
		cancel() {
			if (!this.run) {
				this.resetToCreateForm()
				return
			}
			this.cancelling = true
			api.post(`/runs/${this.run.id}/cancel`)
				.catch(() => {
					// Already in a terminal state (completed/failed/etc.) -
					// fine, reset the UI below either way.
				})
				.finally(() => {
					this.cancelling = false
					this.resetToCreateForm()
				})
		},
		retryFailures() {
			if (!this.run) {
				return
			}
			this.retrying = true
			this.errorText = ''
			api.post(`/runs/${this.run.id}/retry-failures`)
				.then((run) => {
					this.run = run
					this.showFailures = false
					this.failures = []
					this.failuresPage = 1
					this.startPolling()
					this.refreshStatus()
				})
				.catch((e) => {
					this.errorText = `Failed to retry failed files: ${apiErrorMessage(e)}`
				})
				.finally(() => {
					this.retrying = false
				})
		},
		closeRun() {
			if (!this.run) {
				this.resetToCreateForm()
				return
			}
			this.cancelling = true
			api.delete(`/runs/${this.run.id}`)
				.then(() => {
					this.resetToCreateForm()
				})
				.catch((e) => {
					this.errorText = `Failed to remove run: ${apiErrorMessage(e)}`
				})
				.finally(() => {
					this.cancelling = false
				})
		},
		resetToCreateForm() {
			this.stopPolling()
			this.run = null
			this.status = null
			this.showAdvanced = false
			this.showFailures = false
			this.failures = []
			this.failuresPage = 1
			this.errorText = ''
			this.loadLocalUsers()
		},
		volumePercent(userMap) {
			return userMap.totalBytes > 0 ? Math.min(100, Math.round((userMap.transferredBytes / userMap.totalBytes) * 100)) : 0
		},
		formatBytes(bytes) {
			if (!bytes || bytes <= 0) {
				return '0 B'
			}
			const units = ['B', 'KB', 'MB', 'GB', 'TB']
			const unitIndex = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
			const value = bytes / (1024 ** unitIndex)
			return `${unitIndex === 0 ? value : value.toFixed(1)} ${units[unitIndex]}`
		},
		toggleFailures() {
			this.showFailures = !this.showFailures
			if (this.showFailures) {
				this.loadFailures()
			}
		},
		loadFailures() {
			if (!this.run) {
				return
			}
			this.failures = []
			this.failuresPage = 1
			return this.fetchFailuresPage()
		},
		prevFailuresPage() {
			if (this.failuresPage <= 1) {
				return
			}
			this.failuresPage -= 1
			return this.fetchFailuresPage()
		},
		nextFailuresPage() {
			if (this.failuresPage >= this.totalFailuresPages) {
				return
			}
			this.failuresPage += 1
			return this.fetchFailuresPage()
		},
		fetchFailuresPage() {
			if (!this.run) {
				return
			}
			this.loadingFailures = true
			const offset = (this.failuresPage - 1) * FAILURES_PAGE_SIZE
			return api.get(`/runs/${this.run.id}/failures?limit=${FAILURES_PAGE_SIZE}&offset=${offset}`)
				.then((files) => {
					this.failures = files
				})
				.catch((e) => {
					this.errorText = `Failed to load failed files: ${apiErrorMessage(e)}`
				})
				.finally(() => {
					this.loadingFailures = false
				})
		},
		failureStageLabel(state) {
			return FAILURE_STAGE_LABELS[state] || state
		},
		failureAttempts(file) {
			return file.state === 'verification_failed' ? file.verifyAttempts : file.transferAttempts
		},
		// Failed files only carry a userMapId, not a username - resolve it
		// against the per-user table already loaded in `status.userMaps` so
		// the admin can see which mapped user each failure belongs to.
		failureUserLabel(file) {
			const userMap = this.status && this.status.userMaps.find((u) => u.id === file.userMapId)
			if (!userMap) {
				return `#${file.userMapId}`
			}
			return userMap.sourceUserId === userMap.targetUserId
				? userMap.sourceUserId
				: `${userMap.sourceUserId} \u2192 ${userMap.targetUserId}`
		},
	},
}
</script>

<style scoped>
.ncm-field {
	margin-bottom: 12px;
}

.ncm-field label {
	display: inline-block;
	min-width: 180px;
}

.ncm-users-table {
	width: 100%;
	max-width: 700px;
	margin-bottom: 12px;
}

.ncm-users-table th:first-child,
.ncm-users-table td:first-child {
	width: 2em;
	padding-right: 0;
}

.ncm-advanced-toggle {
	margin-bottom: 12px;
}

.ncm-advanced-options {
	margin-bottom: 12px;
	padding: 12px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.ncm-actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
	flex-wrap: wrap;
}

.ncm-progress-row {
	display: flex;
	align-items: center;
	gap: 8px;
	max-width: 500px;
}

.ncm-percent {
	min-width: 3.5em;
	text-align: right;
}

.ncm-failures {
	margin-top: 12px;
	margin-bottom: 12px;
}

.ncm-failures-table {
	width: 100%;
	max-width: 900px;
	margin-top: 8px;
}

.ncm-failures-pagination {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
}

.ncm-raw-status {
	background: var(--color-background-dark);
	padding: 8px;
	max-height: 300px;
	overflow: auto;
	border-radius: var(--border-radius);
}
</style>
