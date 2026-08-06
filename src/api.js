import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const apiBase = generateUrl('/apps/nextcloud_migrate/api/v1')

/**
 * Thin wrapper around @nextcloud/axios scoped to this app's REST API.
 * axios already attaches the OCS/CSRF requesttoken header via
 * @nextcloud/auth integration, and rejects on non-2xx status, so callers
 * just need to catch and read `error.response.data.error`.
 */
export default {
	get(path) {
		return axios.get(apiBase + path).then((response) => response.data)
	},
	post(path, body) {
		return axios.post(apiBase + path, body ?? {}).then((response) => response.data)
	},
	delete(path) {
		return axios.delete(apiBase + path).then((response) => response.data)
	},
}

export function apiErrorMessage(error) {
	return error?.response?.data?.error || error?.message || String(error)
}
