import Vue from 'vue'
import App from './App.vue'

const mountEl = document.getElementById('nextcloud-migrate-admin-app')
if (mountEl) {
	new Vue({
		render: (h) => h(App),
	}).$mount(mountEl)
}
