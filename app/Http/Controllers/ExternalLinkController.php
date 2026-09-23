<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\LinkiWTekscie;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;

final class ExternalLinkController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = $request->query('cel');
        abort_unless(is_string($token) && strlen($token) <= 6000, 404);
        try {
            $adres = LinkiWTekscie::bezpiecznyAdres(Crypt::decryptString($token));
        } catch (DecryptException) {
            abort(404);
        }
        abort_if($adres === null, 404);
        abort_if(LinkiWTekscie::wewnetrzny($adres), 404);

        return response()->view('pages.external-link', [
            'adres' => $adres,
            'domena' => parse_url($adres, PHP_URL_HOST),
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
