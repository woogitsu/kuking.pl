<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Kanaly\CacheKanalu;
use App\Domain\Kanaly\Kanal;
use App\Domain\Kanaly\KluczeKanalu;
use App\Domain\Kanaly\TresciKanalu;
use App\Domain\Kanaly\ZapisAtom;
use App\Http\Support\PrzekierowanieDawnejNazwy;
use App\Models\Collection;
use App\Models\Profile;
use App\Models\Tag;
use App\Support\AdresKanoniczny;
use App\Support\PublicznyHtmlGoscia;
use Closure;
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
 * CACHE (#610). Tylko `ETag` z treści; zgodne `If-None-Match` dostaje 304
 * bez treści. BEZ `Last-Modified`: data z najpóźniejszej pozycji nie zmienia
 * się, gdy pozycja znika (usunięta, ukryta, zdjęta z urzędu), więc czytnik
 * pytający samym `If-Modified-Since` dostawał 304 i dalej pokazywał
 * wycofaną treść. Hash treści zmienia się przy każdej zmianie zestawu.
 * `Cache-Control` idzie za polityką HTML gościa: przy
 * `KUKING_HTML_EDGE_CACHE_SECONDS` > 0 `public, max-age=0, s-maxage=N`
 * (ta sama górna granica 300 s okna nieświeżości), a domyślnie
 * `private, no-cache` — bez wspólnego cache, ale czytnik może pytać
 * warunkowo. `PreventSharedSessionCache` i tak zamienia to na
 * `private, no-store`, gdy żądanie niesie ciasteczko albo `Authorization`.
 *
 * CACHE APLIKACYJNY (D-333, decyzja z 30.09.2026): gotowa treść kanału żyje
 * `kuking.kanal_cache_sekund` (domyślnie 300 s) — patrz `xml()`. Dostęp
 * (Policy, 404) jest sprawdzany przy każdym żądaniu, przed cache.
 */
final class KanalAtomController
{
    public function __construct(
        private readonly TresciKanalu $tresci = new TresciKanalu,
        private readonly ZapisAtom $zapis = new ZapisAtom,
    ) {}

    public function profil(Request $request, string $username): Response|RedirectResponse
    {
        // Ta sama droga co `ProfileController::show()`: nazwa bez
        // rozróżniania wielkości liter, indeks `lower(username)`.
        $profil = Profile::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->with('user')
            ->first();

        // Dawna nazwa → 301 na kanał pod aktualną nazwą (czytniki zapisują
        // adres kanału). Widz to zawsze gość — kanał jest poza sesją.
        if ($profil === null) {
            $przekierowanie = PrzekierowanieDawnejNazwy::dla($request, null, $username, 'kanaly.profil');

            abort_if($przekierowanie === null, 404);

            return $przekierowanie;
        }

        $wlasciciel = $profil->user;

        if ($wlasciciel === null || Gate::forUser(null)->denies('viewProfile', $wlasciciel)) {
            abort(404);
        }

        $wlasciciel->setRelation('profile', $profil);

        return $this->odpowiedz($request, KluczeKanalu::profil((string) $wlasciciel->getKey(), (string) $profil->username), fn () => $this->tresci->profil($wlasciciel));
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

        return $this->odpowiedz($request, KluczeKanalu::tag((string) $model->getKey()), fn () => $this->tresci->tag($model));
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

        return $this->odpowiedz($request, KluczeKanalu::zeszyt((string) $zeszyt->getKey()), fn () => $this->tresci->zeszyt($zeszyt));
    }

    /**
     * @param  string  $klucz  klucz cache z `KluczeKanalu`
     * @param  Closure(): Kanal  $zbuduj  wołane tylko przy braku świeżej kopii
     */
    private function odpowiedz(Request $request, string $klucz, Closure $zbuduj): Response
    {
        $xml = $this->xml($klucz, $zbuduj);
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
        $odpowiedz->isNotModified($request);

        return $odpowiedz;
    }

    /**
     * Krótki cache APLIKACYJNY gotowej treści kanału (decyzja właściciela
     * z 30.09.2026, D-333). Kanał jest zawsze widokiem gościa, więc kopia
     * jest wspólna (klucz z `KluczeKanalu`: typ + identyfikator, dla profilu też nazwa). Cache stoi ZA bramką
     * dostępu: Policy i 404 dla prywatnego zeszytu, konta zbanowanego albo
     * tagu ukrytego liczą się przy KAŻDYM żądaniu. Cena: pozycja ukryta,
     * usunięta albo zdjęta z urzędu może zostać w kanale najwyżej
     * `kuking.kanal_cache_sekund` (domyślnie 300 s) — chyba że zmiana przeszła
     * przez model `Post`/`Recipe` (`UniewaznijKanaly` czyści kopie od razu). Bez
     * unieważniania przy zmianie treści. 0 = bez cache.
     *
     * @param  Closure(): Kanal  $zbuduj
     */
    private function xml(string $klucz, Closure $zbuduj): string
    {
        $sekundy = max(0, (int) config('kuking.kanal_cache_sekund'));

        // Treść budowana z `APP_URL`, nie z żądania: kopia jest wspólna dla
        // wszystkich, a `route()` i `Media::url()` biorą schemat, host i port
        // z żądania (zaufane nagłówki `X-Forwarded-*`). Bez tego jedno
        // żądanie z obcym `X-Forwarded-Proto`/`-Port` przy zimnym cache
        // zatruwałoby kanał dla wszystkich na cały TTL.
        $buduj = fn (): string => AdresKanoniczny::zbuduj(fn (): string => $this->zapis->xml($zbuduj()));

        if ($sekundy === 0) {
            return $buduj();
        }

        return CacheKanalu::zapamietaj($klucz, $sekundy, $buduj);
    }
}
