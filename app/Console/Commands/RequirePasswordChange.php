<?php
namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Mark accounts whose password was generated for them: at the next sign-in they must pick their own password first. */
class RequirePasswordChange extends Command
{
    protected $signature = 'school:require-password-change {email?* : accounts to mark} {--all : every active account of this school (not demo accounts)} {--clear : remove the mark instead}';
    protected $description = 'Make accounts change their password at the next sign-in';

    public function handle(): int
    {
        if (!Schema::hasColumn('users', 'must_change_password')) { $this->error('Run the migrations first (php artisan migrate --force)'); return self::FAILURE; }
        $emails = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) $this->argument('email'))));
        if (!$this->option('all') && !$emails) { $this->error('Give one or more e-mail addresses, or --all'); return self::FAILURE; }
        $q = User::where('school_id', config('school.school_id'))->where('is_active', true)->where('is_demo', false);
        if (!$this->option('all')) $q->whereIn('email', $emails);
        $users = $q->get(['id', 'email']);
        if (!$this->option('all') && ($missing = array_diff($emails, $users->pluck('email')->all()))) { $this->error('No active account: '.implode(', ', $missing)); return self::FAILURE; }
        $flag = !$this->option('clear');
        DB::transaction(function () use ($users, $flag) {
            foreach ($users as $u) {
                DB::table('users')->where('id', $u->id)->update(['must_change_password' => $flag, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                DB::table('audit_events')->insert(['school_id' => (int) config('school.school_id'), 'actor_id' => $u->id, 'action' => $flag ? 'account.password_change_required' : 'account.password_change_cleared',
                    'entity' => 'users', 'entity_id' => $u->id, 'detail' => json_encode(['channel' => 'cli']), 'created_at' => now()]);
            }
        });
        $this->info(($flag ? '[OK] must change password at next sign-in: ' : '[OK] mark removed: ').$users->count().' account(s)');
        return self::SUCCESS;
    }
}
