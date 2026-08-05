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
				<label for="ncm-instance-url">URL</label>
				<input type="url" id="ncm-instance-url" name="url" placeholder="https://target.example.com" required>
			</p>
			<p>
				<label for="ncm-instance-user">Admin username</label>
				<input type="text" id="ncm-instance-user" name="adminUserId" required>
			</p>
			<p>
				<label for="ncm-instance-password">Admin app password</label>
				<input type="password" id="ncm-instance-password" name="adminAppPassword" required>
			</p>
			<p class="settings-hint">
				This account must have <strong>admin</strong> privileges on the
				target instance. It is used only to create or reset target user
				accounts via the Provisioning API when starting a migration - never
				to write files, since Nextcloud's WebDAV has no admin-bypass for
				another user's files.
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

	<section id="ncm-quick">
		<h3>Quick migration</h3>

		<form id="ncm-quick-form">
			<p>
				<label for="ncm-quick-collision">Collision strategy</label>
				<select id="ncm-quick-collision" name="collisionStrategy" required>
					<option value="rename">Rename on collision (default)</option>
					<option value="skip">Skip on collision</option>
					<option value="overwrite">Overwrite on collision</option>
				</select>
			</p>

			<table id="ncm-quick-users-table">
				<thead>
					<tr>
						<th></th>
						<th>Local user</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>

			<p>
				<button type="submit">Start</button>
			</p>
		</form>

		<div id="ncm-quick-progress" hidden>
			<p id="ncm-quick-status" class="settings-hint"></p>
			<p>
				<progress id="ncm-quick-progressbar" max="100" value="0" style="width: 100%;"></progress>
				<span id="ncm-quick-percent">0%</span>
			</p>
			<p id="ncm-quick-error" class="settings-hint" hidden></p>

			<table id="ncm-quick-user-table">
				<thead>
					<tr>
						<th>Local user</th>
						<th>Target user</th>
						<th>Files</th>
						<th>Transferred</th>
						<th>Volume</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>

			<p>
				<button type="button" id="ncm-quick-cancel">Cancel</button>
			</p>
		</div>
	</section>

	<section id="ncm-run">
		<h3>Migration run (manual/advanced)</h3>
		<p class="settings-hint">
			Kept for testing while the simplified panel above is validated: lets
			you drive each stage by hand (expert mode, optional verification
			skip, raw status JSON) instead of the automatic flow.
		</p>

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
				<label for="ncm-expert-mode">Expert mode</label>
				<input type="checkbox" id="ncm-expert-mode">
			</p>
			<p class="settings-hint">
				By default, each selected user's target account is created (or its
				password reset) automatically via the admin credentials above - no
				need to know each user's own password. Enable expert mode to supply
				a target app password yourself instead, without touching the
				target account at all.
			</p>

			<p>
				<label for="ncm-skip-verification">Skip post-transfer verification</label>
				<input type="checkbox" id="ncm-skip-verification" name="skipVerification">
			</p>
			<p class="settings-hint">
				The target already validates each file's checksum at upload time
				(via the OC-Checksum header) and rejects the write on a mismatch,
				so this is safe to enable for faster migrations. Leave unchecked
				(default) to additionally re-download every file afterwards and
				compare checksums, which also catches rarer issues such as
				storage corruption on the target after a successful upload.
			</p>

			<table id="ncm-user-mappings-table">
				<thead>
					<tr>
						<th></th>
						<th>Local user</th>
						<th>Target username</th>
						<th class="ncm-expert-col" hidden>Target app password</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>

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
