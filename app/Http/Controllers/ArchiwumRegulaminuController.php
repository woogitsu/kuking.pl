<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zgody\ArchiwumRegulaminu;
use App\Support\ZaufanyMarkdown;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * „Wszystkie wersje regulaminu” (#2220, kryterium 4). Publiczne jak sam
 * regulamin: gość też musi móc sprawdzić, na co się zgadzał. Nie ma tu
 * cudzego zasobu ani danych osobowych — tylko pliki z repozytorium —
 * więc bez Policy, tak jak `StaticPageController::terms()`.
 *
 * Wszystkie trzy odpowiedzi są poza indeksem wyszukiwarek (`noindex,
 * follow` na stronach, `X-Robots-Tag` przy pobraniu): kto szuka regulaminu
 * Kuking, ma trafić na obowiązujący `/regulamin`, a nie na brzmienie sprzed
 * miesięcy. Do archiwum prowadzi odnośnik z samego regulaminu; mapa strony
 * go nie wymienia.
 */
class ArchiwumRegulaminuController extends Controller
{
    public function index(): View
    {
        return view('pages.static.regulamin-wersje', [
            'wersje' => ArchiwumRegulaminu::wersje(),
            'biezaca' => ArchiwumRegulaminu::biezaca(),
        ]);
    }

    public function show(string $data): View
    {
        $tresc = ArchiwumRegulaminu::tresc($data);
        abort_if($tresc === null, 404);

        return view('pages.static.regulamin-wersja', [
            'data' => $data,
            'biezaca' => ArchiwumRegulaminu::biezaca(),
            'html' => ZaufanyMarkdown::doHtml($tresc),
        ]);
    }

    /**
     * Pobranie jako zwykły plik tekstowy — otworzy go każdy komputer i każdy
     * telefon, bez programu do Markdownu. Treść jest taka sama jak w pliku
     * archiwum, bajt w bajt.
     */
    public function pobierz(string $data): Response
    {
        $tresc = ArchiwumRegulaminu::tresc($data);
        abort_if($tresc === null, 404);

        return response($tresc, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"regulamin-kuking-{$data}.txt\"",
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
