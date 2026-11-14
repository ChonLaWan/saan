<?php

namespace App\Support\Saan;

use App\Support\Modules\ModuleRegistry;

/** GET /work: shell for module-provided staff pages. */
class WorkController
{
    public function __invoke(ModuleRegistry $modules)
    {
        $role = (string) auth()->user()->role;
        $menu = $modules->workMenu($role);
        $assets = $modules->workAssets();
        // core page for admins: settings declared by modules in module.json settings_schema
        if ($role === 'admin' && $modules->settingsSchema()) {
            $menu[] = ['key' => 'module-settings', 'label' => 'ตั้งค่าโมดูล', 'path' => '/work#module-settings', 'icon' => 'grid', 'roles' => ['admin'], 'module' => 'core'];
            $assets[] = 'assets/saan-module-settings.js';
        }
        // "บริการบุคลากร" block of the work home: links to central services (pension, civil-servant salary) set by the admin; only while the payslip module is active
        $services = ($modules->all()['payslip']['active'] ?? false) && class_exists(\Saan\Modules\Payslip\Links::class) ? \Saan\Modules\Payslip\Links::all() : [];
        return view('work', ['menu' => $menu, 'assets' => $assets, 'role' => $role, 'services' => $services]);
    }
}