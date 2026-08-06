import { createApp } from 'vue'
import App from './App.vue'

const mountEl = document.getElementById('nextcloud-migrate-admin-app')
if (mountEl) {
	createApp(App).mount(mountEl)
}
