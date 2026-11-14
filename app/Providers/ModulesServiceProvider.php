<?php

namespace App\Providers;

use App\Support\Modules\ModuleRegistry;
use App\Support\Saan\Edition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Loads code that lives in modules/<id>/ so each module can be shipped and patched on its own.
 * - PHP classes: namespace Saan\Modules\<StudlyId>\ -> modules/<id>/src (no composer dump needed on school hosts)
 * - migrations: always loaded, so data tables exist even while a module is switched off
 * - views: namespace "<id>::"
 * - routes: only for active modules, by edition (config('saan.edition')): modules/<id>/routes/public.php for the public site surface,
 *   modules/<id>/routes/web.php for the staff/guardian/api surfaces
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'Saan\\Modules\\';
            if (!str_starts_with($class, $prefix)) return;
            [$studly, $rest] = array_pad(explode('\\', substr($class, strlen($prefix)), 2), 2, '');
            if ($rest === '') return;
            $id = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
            $file = base_path('modules/'.$id.'/src/'.str_replace('\\', '/', $rest).'.php');
            if (is_file($file)) require_once $file;
        });
    }

    public function boot(): void
    {
        foreach (glob(base_path('modules/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (is_dir($dir.'/database/migrations')) $this->loadMigrationsFrom($dir.'/database/migrations');
            if (is_dir($dir.'/resources/views')) $this->loadViewsFrom($dir.'/resources/views', $id);
        }
        // Module console commands (modules/<id>/src/Console/*Command.php), registered even while a module is switched off
        // so retention/prune jobs keep working on kept data. Scheduling stays an explicit routes/console.php decision.
        if ($this->app->runningInConsole()) {
            $commands = [];
            foreach (glob(base_path('modules/*/src/Console/*Command.php')) ?: [] as $file) {
                $id = basename(dirname($file, 3));
                if (!preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $id)) continue;
                $class = 'Saan\\Modules\\'.\Illuminate\Support\Str::studly($id).'\\Console\\'.basename($file, '.php');
                if (class_exists($class) && is_subclass_of($class, \Illuminate\Console\Command::class)) $commands[] = $class;
            }
            if ($commands) $this->commands($commands);
        }
        $this->app->booted(function () {
            if ($this->app->routesAreCached()) return;
            $registry = $this->app->make(ModuleRegistry::class);
            foreach ($registry->all() as $id => $module) {
                if (!$module['active']) continue;
                $middleware = ['web', \App\Http\Middleware\SchoolBoundary::class, 'module:'.$id];
                // S1: routes/public.php = guest-visible pages and public form endpoints ('site' surface, public edition and combined);
                // routes/web.php = staff screens and APIs plus the guardian/api surfaces ('staff', internal edition and combined).
                // The module's own "edition" must allow the file as well.
                $public = $module['path'].'/routes/public.php';
                if (Edition::serves('site') && in_array($module['edition'], ['public', 'both'], true) && is_file($public)) Route::middleware($middleware)->group($public);
                $routes = $module['path'].'/routes/web.php';
                if (Edition::serves('staff') && in_array($module['edition'], ['internal', 'both'], true) && is_file($routes)) Route::middleware($middleware)->group($routes);
            }
        });
    }
}
