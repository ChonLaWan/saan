<?php

namespace App\Http\Controllers;

use App\Domain\SchoolAccounts;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\UpdateFeed;
use App\Support\Modules\WhatsNew;
use App\Support\Modules\ModuleActionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/** Admin page "โมดูล" (enable/disable, export data) and the "มีอะไรใหม่" page. */
class ModuleManagerController extends Controller
{
    public function __construct(private ModuleManager $manager, private ModuleRegistry $registry) {}

    public function page()
    {
        app(SchoolAccounts::class)->authorize();
        return view('modules-admin');
    }

    public function index()
    {
        app(SchoolAccounts::class)->authorize();
        return response()->json(['version' => config('saan.version'), 'modules' => $this->manager->listing(), 'feed' => UpdateFeed::status()]);
    }

    public function state(Request $request, string $id)
    {
        app(SchoolAccounts::class)->authorize();
        $d = $request->validate(['enabled' => 'required|boolean', 'confirm' => 'nullable|string|max:60', 'current_password' => 'required|string|current_password']);
        try {
            return response()->json(['ok' => true, 'modules' => $this->manager->setActive($id, (bool) $d['enabled'], (string) ($d['confirm'] ?? ''))]);
        } catch (ModuleActionException $e) {
            return response()->json(['message' => $e->getMessage()], in_array($e->getCode(), [404, 409, 422], true) ? $e->getCode() : 422);
        } catch (QueryException $e) {
            if (ModuleManager::isUniqueViolation($e)) return response()->json(['message' => 'ข้อมูลซ้ำกับรายการที่มีอยู่ โปรดลองอีกครั้ง'], 409);
            throw $e;
        }
    }

    public function export(Request $request, string $id)
    {
        app(SchoolAccounts::class)->authorize();
        $request->validate(['current_password' => 'required|string|current_password']);
        if (!$this->registry->get($id)) abort(404);
        @set_time_limit(300);
        try {
            $path = $this->manager->export($id);
        } catch (ModuleActionException $e) {
            return response()->json(['message' => $e->getMessage()], in_array($e->getCode(), [404, 409, 422], true) ? $e->getCode() : 422);
        } catch (QueryException $e) {
            if (ModuleManager::isUniqueViolation($e)) return response()->json(['message' => 'ข้อมูลซ้ำกับรายการที่มีอยู่ โปรดลองอีกครั้ง'], 409);
            throw $e;
        }
        return response()->download($path, 'saan-module-'.$id.'-'.now()->format('Ymd-His').'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store'])->deleteFileAfterSend(true);
    }

    /** Any signed-in staff may read the release notes. */
    public function whatsNew()
    {
        $version = (string) config('saan.version');
        $core = WhatsNew::section(base_path('CHANGELOG.md'), $version);
        $mods = [];
        foreach ($this->registry->all() as $m) {
            if (!$m['active']) continue;
            $s = WhatsNew::section($m['path'].'/CHANGELOG.md', $m['version']);
            if ($s) $mods[] = ['id' => $m['id'], 'name' => $m['name'], 'version' => $m['version'], 'section' => $s];
        }
        $feed = auth()->user()->role === 'admin' ? UpdateFeed::status() : null;
        return view('whats-new', ['version' => $version, 'core' => $core, 'modules' => $mods, 'feed' => $feed]);
    }
}
