<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Util;

/**
 * Helper constant for scheduling a background job to be picked up as soon
 * as possible, i.e. within the SAME cron.php invocation that's currently
 * running (rather than only on the next scheduled tick).
 *
 * Background: Nextcloud's own `\OC\BackgroundJob\JobList::add()` accepts an
 * optional third `$firstCheck` argument (not declared on the public
 * `OCP\BackgroundJob\IJobList` interface, but present on every real
 * implementation and safe to pass positionally) that becomes the new row's
 * `last_checked` column. `IJobList::getNext()` orders candidate jobs by
 * `last_checked ASC`, and always bumps whichever job it picks to
 * `last_checked = now()` - including periodic `TimedJob`s like our own
 * `CleanupLocksJob`, which get reselected (and re-touched) on essentially
 * every cron tick regardless of whether their own interval says they should
 * actually run.
 *
 * If we omit `$firstCheck`, `add()` defaults it to `time()` - i.e. "now",
 * the same instant `CleanupLocksJob`'s row was *just* touched to when
 * cron.php's loop selected it moments earlier in this same pass. That tie
 * lets `getNext()` hand cron.php the CleanupLocksJob row a second time; its
 * `$executedJobs[$job->getId()]` guard then sees an ID it already ran this
 * pass and does `break`, ending the ENTIRE cron.php invocation early - even
 * though 14 minutes of budget remained and our freshly-queued job was
 * sitting right there ready to go. Confirmed via real cron logs: a job
 * enqueued this way sat idle for a full 5-minute system-cron interval
 * before its first execution.
 *
 * Passing this constant as `$firstCheck` instead backdates the new row to
 * the epoch, guaranteeing it sorts before any job whose `last_checked` is a
 * real (much later) timestamp, so it always wins that ordering and gets
 * picked up in the same pass that queued it.
 */
final class JobScheduling {
	public const IMMEDIATE_FIRST_CHECK = 0;

	private function __construct() {
	}
}
