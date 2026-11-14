<?php

namespace App\Http\Controllers;

class DevelopmentPlanController extends Controller
{
    public function export()
    {
        return response()->download(resource_path('data/full-system-checklist.txt'),'school-full-system-checklist-2026-11-05.txt',['Content-Type'=>'text/plain; charset=utf-8']);
    }
    public function __invoke()
    {
        $plan = json_decode(file_get_contents(resource_path('data/development-checklist.json')), true, 512, JSON_THROW_ON_ERROR);
        return view('development-plan', ['plan' => $plan]);
    }
}
