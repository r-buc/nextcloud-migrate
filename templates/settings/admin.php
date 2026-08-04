<div id="nextcloud-migrate-admin" class="section">
	<h2>Migrate to another instance</h2>
	<p class="settings-hint">
		Push a user's files 1:1 to a remote Nextcloud instance. v1 migrates file
		content, folder structure, and modification time only - shares, tags,
		comments, favorites, versions, and encrypted files are not migrated.
	</p>

	<section id="ncm-instances">
		<h3>Target instances</h3>
		<table class="ncm-table" id="ncm-instances-table">
			<thead>
				<tr><th>Label</th><th>URL</th><th>Target user</th><th>Last tested</th><th></th></tr>
			</thead>
			<tbody></tbody>
		</table>

		<form id="ncm-create-instance-form" class="ncm-form">
			<h4>Add target instance</h4>
			<input type="text" name="label" placeholder="Label (e.g. Backup instance)" required>
			<input type="url" name="url" placeholder="https://target.example.com" required>
			<input type="text" name="targetUserId" placeholder="Target username" required>
			<input type="password" name="appPassword" placeholder="Target app password" required>
			<label><input type="checkbox" name="allowSelfSigned"> Allow self-signed certificate</label>
			<button type="submit">Add instance</button>
		</form>
	</section>

	<section id="ncm-runs">
		<h3>Migration runs</h3>
		<table class="ncm-table" id="ncm-runs-table">
			<thead>
				<tr><th>ID</th><th>Instance</th><th>State</th><th>Progress</th><th>Created</th><th></th></tr>
			</thead>
			<tbody></tbody>
		</table>

		<form id="ncm-create-run-form" class="ncm-form">
			<h4>Create migration run</h4>
			<select name="instanceId" id="ncm-run-instance-select" required></select>
			<select name="collisionStrategy" required>
				<option value="rename">Rename on collision (default)</option>
				<option value="skip">Skip on collision</option>
				<option value="overwrite">Overwrite on collision</option>
			</select>
			<textarea name="userMappings" placeholder="sourceUser1:targetUser1&#10;sourceUser2:targetUser2" rows="4" required></textarea>
			<p class="settings-hint">One mapping per line, format <code>sourceUser:targetUser</code>.</p>
			<button type="submit">Create run</button>
		</form>
	</section>

	<section id="ncm-run-detail" hidden>
		<h3>Run detail</h3>
		<pre id="ncm-run-detail-content"></pre>
		<button id="ncm-run-dry-run">Start dry run</button>
		<button id="ncm-run-approve">Approve &amp; start transfer</button>
		<button id="ncm-run-pause">Pause</button>
		<button id="ncm-run-resume">Resume</button>
		<button id="ncm-run-cancel">Cancel</button>
		<button id="ncm-run-refresh">Refresh</button>
	</section>
</div>
