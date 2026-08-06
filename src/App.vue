<template>
	<div class="ncm-app">
		<InstanceSettings @changed="onInstanceChanged" />
		<MigrationPanel :instance-configured="instanceConfigured" />
	</div>
</template>

<script>
import InstanceSettings from './components/InstanceSettings.vue'
import MigrationPanel from './components/MigrationPanel.vue'

export default {
	name: 'App',
	components: { InstanceSettings, MigrationPanel },
	data() {
		return {
			instanceConfigured: false,
		}
	},
	methods: {
		onInstanceChanged(instance) {
			this.instanceConfigured = instance !== null
		},
	},
}
</script>
