<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// doctor: cron heartbeat (storage/app/private/system/heartbeat.json); `school:doctor` warns when older than 10 minutes.
Schedule::call(fn () => \App\Domain\Cms\DeploymentHealth::writeHeartbeat())->name('saan-heartbeat')->everyMinute()->evenInMaintenanceMode();
// S1: the back-office jobs run only where staff work is served (combined, internal). The public edition keeps the heartbeat and the update check;
// expiry of published rows on the public side belongs to the S3 receiver, which must re-evaluate time-based projection filters (@today/@now) on a schedule.
$staffEdition = \App\Support\Saan\Edition::serves('staff');
if ($staffEdition) Schedule::command('cms:publish-due')->everyMinute()->withoutOverlapping();
// S3: internal edition only (combined has one database, nothing to push). Retry/backoff lives in pub_outbox; expiry of time-based rows is re-evaluated on every tick.
if (\App\Support\Saan\Edition::isInternal()) Schedule::command('saan:publish')->everyMinute()->withoutOverlapping(5);
// S5: the internal edition pulls the sealed inbox every minute; the public edition drops envelopes that nobody pulled within 7 days.
if (\App\Support\Saan\Edition::isInternal()) Schedule::command('saan:inbox-pull')->everyMinute()->withoutOverlapping(5);
if (\App\Support\Saan\Edition::isPublic()) Schedule::command('saan:inbox-purge')->hourly()->withoutOverlapping();
// web-cms F4: the public edition sends the per-day download totals of hub documents to the internal edition (aggregate only, sealed inbox envelope).
if (\App\Support\Saan\Edition::isPublic()) Schedule::command('saan:hubs-send-counts')->everyTenMinutes()->withoutOverlapping(5);
// web-cms F5a: the public edition sends the per-day view totals of news posts (aggregate only); every edition folds or prunes old per-day rows.
if (\App\Support\Saan\Edition::isPublic()) Schedule::command('saan:post-views-send')->everyTenMinutes()->withoutOverlapping(5);
// REL-02: an update whose starting request or process died is settled within a minute (site up on the new release when every step had passed,
// otherwise the previous files back), also on hosts without SSH. Silent no-op when nothing is unfinished or an update is still running.
Schedule::command('saan:update --recover --auto')->everyMinute()->evenInMaintenanceMode()->withoutOverlapping(10);
Schedule::command('saan:post-views-prune')->dailyAt('03:20')->withoutOverlapping();
// web-notices: remove names of expired public lists (privacy, G5). Only when the module command exists.
if ($staffEdition && is_file(base_path('modules/web-notices/module.json'))) Schedule::command('saan:notices-prune')->dailyAt('02:15')->withoutOverlapping();
// web-notices F9: the public edition masks full names itself when full_name_until passes (even if the internal side is down).
if (\App\Support\Saan\Edition::isPublic() && is_file(base_path('modules/web-notices/module.json'))) Schedule::command('saan:notices-public-prune')->everyMinute()->withoutOverlapping(5);
if ($staffEdition && is_file(base_path('modules/surveys/module.json'))) Schedule::command('saan:surveys-prune')->dailyAt('02:45')->withoutOverlapping();
// PDPA retention: request logs / expired SSO codes and tokens, and personnel files past their retention period.
if ($staffEdition && is_file(base_path('modules/api-gateway/module.json'))) Schedule::command('saan:api-prune')->dailyAt('03:30')->withoutOverlapping();
if ($staffEdition && is_file(base_path('modules/registry/module.json'))) Schedule::command('saan:registry-prune')->dailyAt('03:50')->withoutOverlapping();
// maintenance: repair photos can show pupils; honour maintenance.photo_retention_months.
if ($staffEdition && is_file(base_path('modules/maintenance/module.json'))) Schedule::command('saan:maintenance-prune')->dailyAt('04:10')->withoutOverlapping();
if ($staffEdition && is_file(base_path('modules/finance/module.json'))) Schedule::command('saan:finance-prune')->dailyAt('04:30')->withoutOverlapping(); // payment slip retention (finance is an internal-only module)
if ($staffEdition && is_file(base_path('modules/payslip/module.json'))) Schedule::command('saan:payslip-prune')->dailyAt('04:45')->withoutOverlapping(); // payroll retention (payslip is an internal-only module)
if ($staffEdition && is_file(base_path('modules/personnel/module.json'))) Schedule::command('saan:personnel-retention --apply')->monthlyOn(1, '03:45')->withoutOverlapping();

// Optional signed update feed: the command does nothing unless the admin switched updates.feed_enabled on.
// The minute is derived locally from APP_KEY (03:00-04:59), so installations do not all call the feed at the same time and nothing identifying is sent.
$feedMinute = crc32((string) config('app.key')) % 120;
Schedule::command('saan:update-check')->dailyAt(sprintf('%02d:%02d', 3 + intdiv($feedMinute, 60), $feedMinute % 60))->withoutOverlapping();

// Self-update (staff editions): does nothing unless the admin chose the mode "อัปเดตแพตช์อัตโนมัติ" (auto_patch), the night window is open, nothing is
// unfinished and a PATCH release is waiting (minor and major releases are never installed by itself). One attempt per release.
if ($staffEdition) Schedule::command('saan:self-update --auto')->everyTenMinutes()->withoutOverlapping(120);

// Optional scheduled backup (internal/combined only; the public edition can be rebuilt from the internal one). saan.backup.daily_at = HH:MM, empty = off.
// Writes storage/app/private/backups/auto-YYYYMMDD.zip through `school:archive backup` and keeps the newest saan.backup.keep of those; pre-update-*.zip are the updater's and untouched.
$backupAt = trim((string) config('saan.backup.daily_at', ''));
if ($staffEdition && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $backupAt)) {
    Schedule::call(function () {
        $r = \App\Console\Commands\SchoolArchive::runScheduled();
        \Illuminate\Support\Facades\Log::log($r['ok'] ? 'info' : 'error', '[saan-backup] '.$r['message'].($r['file'] ? ' '.basename($r['file']) : ''));
    })->name('saan-backup')->dailyAt($backupAt)->withoutOverlapping(180);
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
