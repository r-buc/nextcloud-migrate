<div id="nextcloud-migrate-admin" class="section">
	<h2>Migrate to another instance</h2>
	<p class="settings-hint">
		Push a user's files 1:1 to a remote Nextcloud instance. v1 migrates file
		content, folder structure, and modification time only - shares, tags,
		comments, favorites, versions, and encrypted files are not migrated.
	</p>

	<?php if (!empty($_['jsMissing'])) : ?>
	<div role="alert" style="background: var(--color-error); color: #fff; border-radius: var(--border-radius); padding: 12px; margin-bottom: 12px;">
		<strong>This app's frontend assets are missing.</strong>
		The rest of this page will stay blank until
		<code>js/nextcloud_migrate-main.js</code> exists in the app's install
		directory. This usually means it was installed from GitHub's
		auto-generated "Source code" archive for a tag/release instead of the
		<code>nextcloud_migrate.tar.gz</code> asset our release workflow
		builds and uploads (that archive never contains built files, the
		same way it never contains a vendor/ directory). Re-download and
		install that release asset instead, or build the frontend yourself
		with <code>npm install &amp;&amp; npm run build</code> before
		enabling the app.
	</div>
	<?php endif; ?>

	<div id="nextcloud-migrate-admin-app"></div>
</div>
