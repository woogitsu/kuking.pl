<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reakcje\Smakowicie;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * „Smakowicie wygląda" na karcie wpisu (issue #1813, D-280). Zwykłe
 * formularze POST/DELETE, bez JavaScriptu; cofnięcie jednym dotknięciem.
 */
class SmakowicieController extends Controller
{
    public function __construct(private readonly Smakowicie $smakowicie) {}

    public function dodaj(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('view', $post);

        try {
            $this->smakowicie->dodaj($request->user(), $post);
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['smakowicie' => $e->getMessage()]);
        }

        return back()->with('status', 'Zapisane: „Smakowicie wygląda”. Twoja nazwa jest teraz pod tym wpisem — widzą ją wszyscy. Autor dostanie powiadomienie raz dziennie, razem z innymi.');
    }

    public function cofnij(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('view', $post);
        $this->smakowicie->cofnij($request->user(), $post);

        return back()->with('status', 'Cofnięte.');
    }
}
