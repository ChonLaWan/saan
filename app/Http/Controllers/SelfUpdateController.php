<?php

namespace App\Http\Controllers;

use App\Domain\SchoolAccounts;
use App\Domain\SelfUpdate;
use App\Domain\SelfUpdateException;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;

/**
 * Back office "อัปเดตระบบ" (/admin/updates): status, check, download one package, mode. Installing goes through the existing
 * POST /api/site/updates/apply (UpdateController::apply), which asks for the administrator's password in the same way.
 */
class SelfUpdateController extends Controller
{
    public function page()
    {
        app(SchoolAccounts::class)->authorize();
        return view('updates-admin');
    }

    public function status()
    {
        app(SchoolAccounts::class)->authorize();
        return response()->json(app(SelfUpdate::class)->status())->header('Cache-Control', 'no-store');
    }

    public function check(Request $request)
    {
        app(SchoolAccounts::class)->authorize();
        $d = $request->validate(['retry_auto' => 'nullable|boolean']);
        $su = app(SelfUpdate::class);
        try { $su->check((bool) ($d['retry_auto'] ?? false)); }
        catch (SelfUpdateException $e) { return response()->json(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage()], $e->http); }
        Mutation::audit('updates.feed_checked', 'updates', null);
        return response()->json($su->status());
    }

    /** One package per call; a big file arrives over several calls ("partial" then "ready"). */
    public function download(Request $request)
    {
        app(SchoolAccounts::class)->authorize();
        $d = $request->validate(['package' => ['required', 'string', 'regex:/^(core|[a-z][a-z0-9-]{1,39})$/'], 'current_password' => 'required|string|current_password']);
        @set_time_limit(120); ignore_user_abort(true);
        try { $r = app(SelfUpdate::class)->download($d['package']); }
        catch (SelfUpdateException $e) { return response()->json(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage()], $e->http); }
        return response()->json(['ok' => true] + $r);
    }

    public function mode(Request $request)
    {
        app(SchoolAccounts::class)->authorize();
        $d = $request->validate(['mode' => 'required|in:off,notify,auto_patch', 'window_start' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'window_end' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'current_password' => 'required|string|current_password']);
        try { SelfUpdate::setMode($d['mode'], $d['window_start'] ?? null, $d['window_end'] ?? null); }
        catch (SelfUpdateException $e) { return response()->json(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage()], 422); }
        Mutation::audit('updates.mode_changed', 'updates', null, ['mode' => $d['mode'], 'window' => SelfUpdate::window()]);
        return response()->json(app(SelfUpdate::class)->status());
    }
}
