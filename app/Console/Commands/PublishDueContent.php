<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Domain\Cms\WebsiteContent;
class PublishDueContent extends Command {
    protected $signature='cms:publish-due';
    protected $description='Publish content explicitly scheduled by an active authorized account';
    public function handle(WebsiteContent $content): int {
        $count=$content->publishDue();$this->info('[OK] Published '.$count.' scheduled entries');
        // RT-05: the publish result and the monitoring heartbeat are reported separately; a failed heartbeat is a failure.
        if(!\Illuminate\Support\Facades\Storage::disk('local')->put('system/cms-publish-heartbeat.json',json_encode(['at'=>now()->utc()->toIso8601String(),'published'=>$count]))){$this->error('[ERR] Could not write scheduler heartbeat (storage/app/private/system); content above was published but monitoring will show the scheduler as stale');return self::FAILURE;}
        return self::SUCCESS;
    }
}
