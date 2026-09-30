<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zgody\ArchiwumDokumentu;
use App\Support\ZaufanyMarkdown;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * „Wszystkie wersje regulaminu” i „Wszystkie wersje polityki” (#2220,
 * kryterium 4). Jeden kontroler dla obu dokumentów: trasa niesie
 * `->defaults('dokument', …)`, a różnice (nazwy, trasy, „starsze na prośbę”)
 * stoją w `ArchiwumDokumentu`.
 *
 * Publiczne jak sam dokument: gość też musi móc sprawdzić, na co się
 * zgadzał. Nie ma tu cudzego zasobu ani danych osobowych — tylko pliki
 * z repozytorium — więc bez Policy, tak jak `StaticPageController::terms()`.
 *
 * Wszystkie odpowiedzi są poza indeksem wyszukiwarek (`noindex, follow` na
 * stronach, `X-Robots-Tag` przy pobraniu): kto szuka regulaminu albo
 * polityki Kuking, ma trafić na obowiązujący dokument, a nie na brzmienie
 * sprzed miesięcy. Do archiwum prowadzi odnośnik z samego dokumentu; mapa
 * strony go nie wymienia.
 */
class ArchiwumDokumentuController extends Controller
{
    public function index(Request $request): View
    {
        $archiwum = $this->archiwum($request);

        return view('pages.static.dokument-wersje', [
            'archiwum' => $archiwum,
            'wersje' => $archiwum->wersje(),
            'biezaca' => $archiwum->biezaca(),
        ]);
    }

    public function show(Request $request, string $data): View|Response
    {
        $archiwum = $this->archiwum($request);
        $tresc = $archiwum->tresc($data);

        if ($tresc === null) {
            return $this->brakWersji($archiwum, $data);
        }

        return view('pages.static.dokument-wersja', [
            'archiwum' => $archiwum,
            'data' => $data,
            'biezaca' => $archiwum->biezaca(),
            'html' => ZaufanyMarkdown::doHtml($tresc),
        ]);
    }

    /**
     * Pobranie jako zwykły plik tekstowy — otworzy go każdy komputer i każdy
     * telefon, bez programu do Markdownu. Treść jest taka sama jak w pliku
     * archiwum, bajt w bajt.
     */
    public function pobierz(Request $request, string $data): Response
    {
        $archiwum = $this->archiwum($request);
        $tresc = $archiwum->tresc($data);

        if ($tresc === null) {
            return $this->brakWersji($archiwum, $data);
        }

        return response($tresc, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$archiwum->nazwaPobieranegoPliku($data).'"',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Wersji nie ma w archiwum. Zwykle to 404. Przy polityce data sprzed
     * początku archiwum (25 września 2026) jest prawdziwa — mógł ją zapisać
     * dziennik zgód — więc zamiast gołego „nie ma strony” mówimy, jak dostać
     * to brzmienie: na prośbę, kanałem z polityki. Status zostaje 404, bo
     * treści pod tym adresem nie ma.
     */
    private function brakWersji(ArchiwumDokumentu $archiwum, string $data): Response
    {
        abort_unless($archiwum->wydawanaNaProsbe($data), 404);

        return response()->view('pages.static.dokument-wersja-na-prosbe', [
            'archiwum' => $archiwum,
            'data' => $data,
        ], 404, ['X-Robots-Tag' => 'noindex']);
    }

    private function archiwum(Request $request): ArchiwumDokumentu
    {
        return ArchiwumDokumentu::dla((string) $request->route('dokument'));
    }
}
