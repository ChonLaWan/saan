<?php
namespace App\Console\Commands;
use App\Domain\Cms\DeploymentHealth;
use Illuminate\Console\Command;

class SchoolDoctor extends Command
{
    protected $signature='school:doctor {--json : output JSON} {--package-bytes= : size of a package to be installed (makes the disk check blocking)}';
    protected $description='Preflight / doctor: check installed configuration without exposing secrets ([OK]/[WARN]/[ERR])';
    public function handle(DeploymentHealth $health):int
    {
        $bytes=$this->option('package-bytes');
        $report=$health->inspect($bytes!==null&&$bytes!==''?max(0,(int)$bytes):null);
        if($this->option('json'))$this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
        else $this->line(DeploymentHealth::renderText($report));
        // 0 = all OK, 1 = WARN only, 2 = at least one [ERR] (blocks updates)
        return $report['has_errors']?2:($report['technical_checks_passed']===$report['technical_checks_total']?self::SUCCESS:self::FAILURE);
    }
}
