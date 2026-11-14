<?php

namespace App\Http\Controllers;

use App\Support\Saan\Edition;
use App\Support\Saan\Publish\Pairing;
use Illuminate\Http\Request;

/**
 * POST /saan-pair/redeem (S6): a public install exchanges the one-time pairing code for the pairing bundle (public keys + unlock key).
 * No session or CSRF: the code is the credential. Internal edition only; the combined and public editions answer 404.
 */
class SaanPairController extends Controller
{
    public function redeem(Request $request)
    {
        abort_unless(Edition::isInternal(), 404);
        if ($request->getContentTypeFormat() !== 'json' && !$request->isJson()) return $this->reply(415, ['error' => 'json_required']);
        [$status, $body] = Pairing::redeem((string) $request->input('code', ''), (string) $request->input('push_url', ''), $request->ip());
        return $this->reply($status, $body);
    }

    private function reply(int $status, array $body)
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
