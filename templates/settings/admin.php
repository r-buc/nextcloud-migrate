<div id="nextcloud-migrate-admin" class="section">
	<h2>Migrate to another instance</h2>
	<p class="settings-hint">
		Push a user's files 1:1 to a remote Nextcloud instance. v1 migrates file
		content, folder structure, and modification time only - shares, tags,
		comments, favorites, versions, and encrypted files are not migrated.
	</p>

	<section id="ncm-instance">
		<h3>Target instance</h3>
		<p id="ncm-instance-status" class="settings-hint"></p>

		<form id="ncm-instance-form">
			<p>
				<label for="ncm-instance-label">Label</label>
				<input type="text" id="ncm-instance-label" name="label" placeholder="e.g. Backup instance">
			</p>
			<p>
				<label for="ncm-instance-url">Target URL</label>
				<input type="url" id="ncm-instance-url" name="url" placeholder="https://target.example.com" required>
			</p>
			<p>
				<label for="ncm-instance-user">Target username</label>
				<input type="text" id="ncm-instance-user" name="targetUserId" required>
			</p>
			<p>
				<label for="ncm-instance-password">Target app password</label>
				<input type="password" id="ncm-instance-password" name="appPassword" required>
			</p>
			<p>
				<label for="ncm-instance-selfsigned">Allow self-signed certificate</label>
				<input type="checkbox" id="ncm-instance-selfsigned" name="allowSelfSigned">
			</p>
			<p>
				<button type="submit">Save target instance</button>
				<button type="button" id="ncm-instance-test">Test connection</button>
				<button type="button" id="ncm-instance-delete">Remove</button>
			</p>
		</form>
	</section>

	<section id="ncm-run">
		<h3>Migration run</h3>

		<form id="ncm-create-run-form">
			<p>
				<label for="ncm-run-collision">Collision strategy</label>
				<select id="ncm-run-collision" name="collisionStrategy" required>
					<option value="rename">Rename on collision (default)</option>
					<option value="skip">Skip on collision</option>
					<option value="overwrite">Overwrite on collision</option>
				</select>
			</p>
			<p>
				<label for="ncm-run-mappings">User mappings</label>
				<textarea id="ncm-run-mappings" name="userMappings" placeholder="sourceUser1:targetUser1&#10;sourceUser2:targetUser2" rows="4" required></textarea>
			</p>
			<p class="settings-hint">One mapping per line, format <code>sourceUser:targetUser</code>.</p>
			<p>
				<button type="submit">Start migration</button>
			</p>
		</form>

		<div id="ncm-run-detail" hidden>
			<pre id="ncm-run-detail-content"></pre>
			<p>
				<button id="ncm-run-dry-run">Start dry run</button>
				<button id="ncm-run-approve">Approve &amp; start transfer</button>
				<button id="ncm-run-pause">Pause</button>
				<button id="ncm-run-resume">Resume</button>
				<button id="ncm-run-cancel">Cancel</button>
				<button id="ncm-run-refresh">Refresh</button>
				<button id="ncm-run-new">Start a new run</button>
			</p>
		</div>
	</section>
</div>
