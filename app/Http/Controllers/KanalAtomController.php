<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Kanaly\Kanal;
use App\Domain\Kanaly\TresciKanalu;
use App\Domain\Kanaly\ZapisAtom;
use App\Models\Collection;
use App\Models\Profile;
use App\Models\Tag;
use App\Support\PublicznyHtmlGoscia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Kanały Atom publicznego profilu, tagu i zeszytu (#2227, D-333).
 *
 * ZAWSZE OCZAMI GOŚCIA
 * Trasy stoją w `bootstrap/app.php` (`then:`), poza grupą `web`, jak
 * `/wydanie`: czytnik pyta co kilkanaście minut, a w grupie `web` każde
 * pytanie zakładałoby sesję w bazie i odsyłało `Set-Cookie`. Bez sesji nie
 * ma zalogowanego, więc Policy pytamy jawnie o widza `null`
 * (`Gate::forUser(null)`). Adres kanału wysłany komuś dalej nie ujawni więc
 * niczego, czego ta osoba nie zobaczyłaby na stronie bez konta.
 *
 * ODMOWA TO 404, NIE 403. Prywatny zeszyt, profil konta zbanowanego albo
 * kasowanego i tag ukryty wyglądają jak adres, którego nie ma — kanał nie
 * potwierdza istnienia rzeczy, których gość nie może zobaczyć.
 *
 * CACHE (#610). `ETag` z treści i `Last-Modified` z najpóźniejszej daty;
 * zgodne `If-None-Match`/`If-Modified-Since` dostaje 304 bez treści.
 * `Cache-Control` idzie za polityką HTML gościa: przy
 * `KUKING_HTML_EDGE_CACHE_SECONDS` > 0 `public, max-age=0, s-maxage=N`
 * (ta sama górna granica 300 s okna nieświeżości), a domyślnie
 * `private, no-cache` — bez wspólnego cache, ale czytnik może pytać
 * warunkowo. `PreventSharedSessionCache` i tak zamienia to na
 * `private, no-store`, gdy żądanie niesie ciasteczko albo `Authorization`.
 */
final class KanalAtomController
{
    public function __construct(
        private readonly TresciKanalu $tresci = new TresciKanalu,
        private readonly ZapisAtom $zapis = new ZapisAtom,
    ) {}

    public function profil(Request $request, string $username): Response
    {
        // Ta sama droga co `ProfileController::show()`: nazwa bez
        // rozróżniania wielkości liter, indeks `lower(username)`.
        $profil = Profile::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->with('user')
            ->first();

        $wlasciciel = $profil?->user;

        if ($wlasciciel === null || Gate::forUser(null)->denies('viewProfile', $wlasciciel)) {
            abort(404);
        }

        $wlasciciel->setRelation('profile', $profil);

        return $this->odpowiedz($request, $this->tresci->profil($wlasciciel));
    }

    public function tag(Request $request, string $tag): Response|RedirectResponse
    {
        $model = Tag::query()->where('slug', $tag)->first();

        // Jak `TagController::show()`: ukryty nie ma strony, scalony
        // prowadzi trwale do kanału tagu kanonicznego.
        if ($model === null || $model->status === Tag::STATUS_HIDDEN) {
            abort(404);
        }

        if ($model->isMerged()) {
            return redirect()->route('kanaly.tag', $model->tagKanoniczny()->slug, status: 301);
        }

        return $this->odpowiedz($request, $this->tresci->tag($model));
    }

    public function zeszyt(Request $request, string $collection): Response
    {
        $zeszyt = Collection::query()->with('owner.profile')->find($collection);

        // `CollectionPolicy::view(null, …)`: tylko zeszyt publiczny osoby,
        // której konto jest dostępne. Prywatny i rodzinny „tylko dla nas”
        // odpadają tu tak samo jak na stronie.
        if ($zeszyt === null || Gate::forUser(null)->denies('view', $zeszyt)) {
            abort(404);
        }

        return $this->odpowiedz($request, $this->tresci->zeszyt($zeszyt));
    }

    private function odpowiedz(Request $request, Kanal $kanal): Response
    {
        $xml = $this->zapis->xml($kanal);
        $sekundy = PublicznyHtmlGoscia::sekundy();

        $odpowiedz = response($xml, 200, [
            'Content-Type' => ZapisAtom::TYP,
            // Kanał nie jest stroną do wyników wyszukiwania; strony, które
            // opisuje, są w mapie strony i mają własne `canonical`.
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => $sekundy > 0
                ? 'public, max-age=0, s-maxage='.$sekundy
                : 'private, no-cache',
        ]);

        $odpowiedz->setEtag(hash('sha256', $xml), weak: true);
        $odpowiedz->setLastModified($kanal->zmieniono->toDateTime());
        $odpowiedz->isNotModified($request);

        return $odpowiedz;
    }
}
