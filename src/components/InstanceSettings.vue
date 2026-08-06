<template>
	<section id="ncm-instance">
		<h3>Target instance</h3>

		<NcNoteCard v-if="statusText" :type="statusType">
			{{ statusText }}
		</NcNoteCard>

		<form class="ncm-form" @submit.prevent="save">
			<NcTextField v-model="form.url"
				label="URL"
				placeholder="https://target.example.com"
				type="url"
				required />
			<NcTextField v-model="form.adminUserId"
				label="Admin username"
				required />
			<NcTextField v-model="form.adminAppPassword"
				label="Admin app password"
				type="password"
				:required="!instance" />
			<p class="settings-hint">
				This account must have <strong>admin</strong> privileges on the
				target instance. It is used only to create or reset target user
				accounts via the Provisioning API when starting a migration - never
				to write files, since Nextcloud's WebDAV has no admin-bypass for
				another user's files.
			</p>
			<NcCheckboxRadioSwitch v-model="form.allowSelfSigned">
				Allow self-signed certificate
			</NcCheckboxRadioSwitch>

			<div class="ncm-actions">
				<NcButton type="submit" variant="primary" :disabled="saving">
					Save target instance
				</NcButton>
				<NcButton v-if="instance" :disabled="testing" @click="test">
					Test connection
				</NcButton>
				<NcButton v-if="instance" variant="tertiary" :disabled="deleting" @click="remove">
					Remove
				</NcButton>
			</div>
		</form>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api, { apiErrorMessage } from '../api.js'

export default {
	name: 'InstanceSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcTextField },
	data() {
		return {
			instance: null,
			form: {
				url: '',
				adminUserId: '',
				adminAppPassword: '',
				allowSelfSigned: false,
			},
			statusText: '',
			statusType: 'info',
			saving: false,
			testing: false,
			deleting: false,
		}
	},
	mounted() {
		this.load()
	},
	methods: {
		load() {
			return api.get('/instances').then((instances) => {
				this.instance = instances[0] || null
				if (this.instance) {
					this.form.url = this.instance.url
					this.form.adminUserId = this.instance.adminUserId
					this.form.adminAppPassword = ''
					this.form.allowSelfSigned = !!this.instance.allowSelfSigned
					const tested = this.instance.lastTestedAt
						? new Date(this.instance.lastTestedAt * 1000).toLocaleString()
						: 'never'
					this.statusText = `Configured: ${this.instance.url} (admin: ${this.instance.adminUserId}). Last tested: ${tested}`
						+ (this.instance.lastTestError ? ` - ${this.instance.lastTestError}` : '')
					this.statusType = this.instance.lastTestError ? 'warning' : 'info'
				} else {
					this.form = { url: '', adminUserId: '', adminAppPassword: '', allowSelfSigned: false }
					this.statusText = 'No target instance configured yet.'
					this.statusType = 'info'
				}
				this.$emit('changed', this.instance)
			})
		},
		save() {
			this.saving = true
			api.post('/instances', this.form)
				.then(() => this.load())
				.catch((e) => {
					this.statusText = `Failed to save target instance: ${apiErrorMessage(e)}`
					this.statusType = 'error'
				})
				.finally(() => {
					this.saving = false
				})
		},
		test() {
			if (!this.instance) {
				return
			}
			this.testing = true
			api.post(`/instances/${this.instance.id}/test`)
				.then(() => this.load())
				.catch((e) => {
					this.statusText = `Connection test failed: ${apiErrorMessage(e)}`
					this.statusType = 'error'
				})
				.finally(() => {
					this.testing = false
				})
		},
		remove() {
			if (!this.instance) {
				return
			}
			this.deleting = true
			api.delete(`/instances/${this.instance.id}`)
				.then(() => this.load())
				.catch((e) => {
					this.statusText = `Failed to remove target instance: ${apiErrorMessage(e)}`
					this.statusType = 'error'
				})
				.finally(() => {
					this.deleting = false
				})
		},
	},
}
</script>

<style scoped>
.ncm-form {
	max-width: 500px;
}

.ncm-actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}
</style>
