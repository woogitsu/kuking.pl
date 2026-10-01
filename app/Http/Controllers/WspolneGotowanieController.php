<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\DziennikWgladu;
use App\Domain\Recipes\Gotowanie\Wspolne\PostepWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookingSession;
use App\Models\CookingSessionInvitation;
use App\Models\Recipe;
use App\Support\Komunikat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Wspólne gotowanie (#2385). Projekt, autoryzacja i retencja:
 * `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`.
 *
 * Kontroler jest cienki: reguły żyją w `App\Domain\Recipes\Gotowanie\Wspolne`
 * i w `CookingSessionPolicy`. Pięć pytań z AGENTS.md §7: `auth` (grupa tras),
 * Policy przy KAŻDYM wejściu, walidacja z polskim zdaniem, limity `zaproszenia`
 * i `cooking_krok`, ślad w samych wierszach (kto i kiedy odhaczył).
 *
 * DWIE BRAMKI NA KAŻDE WEJŚCIE NA EKRAN SESJI
 *  1. `CookingSessionPolicy::view` — członkostwo (UUID w adresie nie jest
 *     autoryzacją; nie-członek, także zalogowany, dostaje 404);
 *  2. `RecipePolicy::view` dla oglądającego — członkostwo nie otwiera
 *     przepisu, do którego konto straciło dostęp (autor zmienił widoczność
 *     na prywatną, zablokował pomocnika). Wtedy osoba widzi zdanie bez
 *     jakiejkolwiek treści przepisu.
 *
 * Adresy z tokenem (`/gotowanie-razem/dolacz/{token}`) niczego nie zużywają
 * przy GET i dają jedną odpowiedź na wszystko, co nie pozwala dołączyć.
 */
class WspolneGotowanieController extends Controller
{
    public function __construct(
        private readonly SesjaWspolnegoGotowania $sesje,
        private readonly ZaproszenieDoGotowania $zaproszenia,
        private readonly PostepWspolnegoGotowania $postep,
    ) {}

    /** „Gotuj z kimś”: zakłada sesję dla przepisu (albo wraca do trwającej). */
    public function zaloz(Request $request, string $recipe): RedirectResponse
    {
        $przepis = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $przepis);

        try {
            $sesja = $this->sesje->zaloz($request->user(), $przepis);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('cooking.show', $przepis->slug)->with(Komunikat::blad($e->getMessage()));
        }

        return redirect()->route('wspolne-gotowanie.show', $sesja)
            ->with(Komunikat::sukces('Sesja gotowa. Utwórz link i wyślij go osobom, które zapraszasz — zobaczą ten sam przepis i ten sam postęp. Jeden link wpuści do trzech osób.'));
    }

    public function show(Request $request, CookingSession $cookingSession): View|Response
    {
        $this->authorize('view', $cookingSession);

        $osoba = $request->user();
        $przepis = $cookingSession->recipe;

        // Brama przepisu: członek, który nie widzi przepisu, nie dostaje z niego niczego.
        if ($przepis === null || $przepis->trashed() || ! Gate::forUser($osoba)->allows('view', $przepis)) {
            return response()->view('pages.wspolne-gotowanie.przepis-niedostepny', [
                'jestGospodarzem' => $cookingSession->maGospodarza($osoba),
            ], 403);
        }

        // Sesja pokazuje cały przepis — wgląd z urzędu jak w trybie gotowania.
        app(DziennikWgladu::class)->przepis($przepis, $osoba, $request->ip());

        $przepis->load(['steps.media', 'ingredients.unit']);
        $jestGospodarzem = $cookingSession->maGospodarza($osoba);
        $pomocnicy = $cookingSession->pomocnicy()->with('profile')->orderBy('cooking_session_participants.joined_at')->orderBy('users.id')->get();
        $zrobione = $this->postep->zrobione($cookingSession);
        $oczekujace = $jestGospodarzem
            ? $cookingSession->invitations()->where('status', CookingSessionInvitation::STATUS_PENDING)->where('expires_at', '>', now())->first()
            : null;

        $maxPomocnikow = max(1, (int) config('kuking.wspolne_gotowanie.max_pomocnikow', 3));
        $kroki = $przepis->steps;
        $zrobioneLiczba = $kroki->filter(fn ($k): bool => isset($zrobione[(string) $k->getKey()]))->count();

        return response()->view('pages.wspolne-gotowanie.show', [
            'sesja' => $cookingSession,
            'recipe' => $przepis,
            'steps' => $kroki,
            'zrobione' => $zrobione,
            'zrobioneLiczba' => $zrobioneLiczba,
            'jestGospodarzem' => $jestGospodarzem,
            'gospodarz' => $cookingSession->host,
            'pomocnicy' => $pomocnicy,
            'mozeZapisywac' => $osoba->isActive(),
            'oczekujace' => $oczekujace,
            'maxPomocnikow' => $maxPomocnikow,
            'jestMiejsce' => $pomocnicy->count() < $maxPomocnikow,
            'ostrzezenieOWidocznosci' => $jestGospodarzem ? $this->ostrzezenieOWidocznosci($przepis) : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Sam numer rewizji — dla skryptu, który podpowiada, że ktoś z sesji coś zmienił. */
    public function stan(Request $request, CookingSession $cookingSession): JsonResponse
    {
        $this->authorize('view', $cookingSession);

        $przepis = $cookingSession->recipe;
        abort_if($przepis === null || $przepis->trashed() || ! Gate::forUser($request->user())->allows('view', $przepis), 403);

        return response()
            ->json(['aktywna' => true, 'rewizja' => $cookingSession->revision])
            ->header('Cache-Control', 'no-store, private');
    }

    public function krok(Request $request, CookingSession $cookingSession): RedirectResponse
    {
        $this->authorize('update', $cookingSession);
        $this->wymagajPrzepisu($request, $cookingSession);

        $dane = $request->validate([
            'krok_id' => ['required', 'string', 'uuid'],
            'zrobiono' => ['required', 'boolean'],
            'rewizja' => ['nullable', 'integer', 'min:1'],
        ], [
            'krok_id.required' => 'Nie wiadomo, o który krok chodzi. Odśwież stronę i spróbuj jeszcze raz.',
            'krok_id.uuid' => 'Nie wiadomo, o który krok chodzi. Odśwież stronę i spróbuj jeszcze raz.',
        ]);

        $widziana = isset($dane['rewizja']) ? (int) $dane['rewizja'] : null;
        $zrobiono = in_array($dane['zrobiono'], [true, 1, '1'], true);
        $stanPrzed = $cookingSession->revision;
        $juzWZadanymStanie = isset($this->postep->zrobione($cookingSession)[$dane['krok_id']]) === $zrobiono;

        try {
            $this->postep->ustaw($request->user(), $cookingSession, $dane['krok_id'], $zrobiono);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('wspolne-gotowanie.show', $cookingSession)->with(Komunikat::blad($e->getMessage()));
        }

        $odpowiedz = redirect()->to(route('wspolne-gotowanie.show', $cookingSession).'#krok-'.$dane['krok_id']);

        // PODWÓJNE KLIKNIĘCIE TO NIE DRUGA OSOBA: drugie wysłanie tego samego
        // formularza niesie rewizję sprzed pierwszego, które podbiło ją o jeden,
        // a krok jest już w żądanym stanie — nie ma o czym ostrzegać.
        $powtorzenie = $widziana !== null && $widziana + 1 === $stanPrzed && $juzWZadanymStanie;

        // Ktoś z sesji zmienił postęp, odkąd ta strona się wyświetliła — jedno
        // zdanie, nie komunikat techniczny. Własne kliknięcie jest zapisane.
        if ($widziana !== null && $widziana !== $stanPrzed && ! $powtorzenie) {
            return $odpowiedz->with(Komunikat::informacja('Ktoś z sesji zmienił postęp. Widzisz teraz jego aktualny stan, a Twoje kliknięcie zostało zapisane.'));
        }

        return $odpowiedz;
    }

    public function odPoczatku(Request $request, CookingSession $cookingSession): RedirectResponse
    {
        $this->authorize('manage', $cookingSession);

        try {
            $this->postep->wyczysc($request->user(), $cookingSession);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('wspolne-gotowanie.show', $cookingSession)->with(Komunikat::blad($e->getMessage()));
        }

        return redirect()->route('wspolne-gotowanie.show', $cookingSession)
            ->with(Komunikat::sukces('Odhaczenia usunięte dla wszystkich. Możecie zacząć od pierwszego kroku.'));
    }

    public function utworzLink(Request $request, CookingSession $cookingSession): RedirectResponse
    {
        $this->authorize('manage', $cookingSession);

        try {
            [, $token] = $this->zaproszenia->utworz($request->user(), $cookingSession);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('wspolne-gotowanie.show', $cookingSession)->withErrors(['link' => $e->getMessage()]);
        }

        // Jawny token istnieje tylko w tej jednej odpowiedzi — w bazie jest
        // jego skrót. Sesja trzyma go do następnego wyświetlenia strony.
        return redirect()->route('wspolne-gotowanie.show', $cookingSession)
            ->with('link_zaproszenia', route('wspolne-gotowanie.link.show', $token))
            ->with(Komunikat::sukces('Link jest gotowy. Skopiuj go i wyślij osobom, które zapraszasz — wpuści do '.max(1, (int) config('kuking.wspolne_gotowanie.max_pomocnikow', 3)).' osób.'));
    }

    public function odwolajLink(Request $request, CookingSession $cookingSession): RedirectResponse
    {
        $this->authorize('manage', $cookingSession);

        $this->zaproszenia->odwolaj($request->user(), $cookingSession);

        return redirect()->route('wspolne-gotowanie.show', $cookingSession)
            ->with(Komunikat::sukces('Link odwołany. Nikt już z niego nie dołączy; osoby, które już są w sesji, zostają.'));
    }

    public function usunPomocnika(Request $request, CookingSession $cookingSession, string $user): RedirectResponse
    {
        $this->authorize('manage', $cookingSession);

        abort_unless(Str::isUuid($user), 404);
        $pomocnik = $cookingSession->pomocnicy()->whereKey($user)->first();
        abort_if($pomocnik === null, 404);

        $this->sesje->usunPomocnika($request->user(), $cookingSession, $user);

        return redirect()->route('wspolne-gotowanie.show', $cookingSession)
            ->with(Komunikat::sukces("{$pomocnik->displayName()} nie ma już dostępu do tej sesji. Możesz utworzyć nowy link."));
    }

    public function wyjdz(Request $request, CookingSession $cookingSession): RedirectResponse
    {
        $this->authorize('leave', $cookingSession);

        $this->sesje->wyjdz($request->user(), $cookingSession);

        return redirect()->route('home')
            ->with(Komunikat::sukces('Nie jesteś już w tej sesji. Przepis i Twoje konto zostają bez zmian.'));
    }

    public function zakoncz(Request $request, CookingSession $cookingSession): RedirectResponse
    {
        $this->authorize('end', $cookingSession);

        $slug = $cookingSession->recipe?->slug;
        $this->sesje->zakoncz($request->user(), $cookingSession);

        $komunikat = Komunikat::sukces('Sesja zakończona. Wspólny postęp i link zostały usunięte, a pomocnicy nie mają już do nich dostępu.');

        return $slug !== null
            ? redirect()->route('cooking.show', $slug)->with($komunikat)
            : redirect()->route('home')->with($komunikat);
    }

    /** Strona linku: niczego nie zużywa, a treść przepisu pokazuje dopiero po `RecipePolicy::view`. */
    public function pokazLink(Request $request, string $token): Response|RedirectResponse
    {
        $zaproszenie = $this->zaproszenia->poTokenie($token);
        $sesja = $zaproszenie?->session;
        $osoba = $request->user();

        $przepis = $sesja?->trwa() ? $sesja->recipe : null;
        $moze = $sesja !== null && $przepis !== null && ! $przepis->trashed()
            && $osoba->isActive()
            && $sesja->host_id !== $osoba->getKey()
            && $sesja->host?->mozeCzytac()
            && ! $osoba->hasBlockRelationWith($sesja->host)
            && Gate::forUser($osoba)->allows('view', $przepis);

        // Gospodarz otwierający własny link: wskazujemy mu jego sesję.
        if ($sesja !== null && $sesja->trwa() && $sesja->host_id === $osoba->getKey()) {
            return response()->view('pages.wspolne-gotowanie.link-wlasny', ['sesja' => $sesja], 200)
                ->header('Referrer-Policy', 'no-referrer');
        }

        // Już uczestnik tej sesji — wystarczy do niej wejść.
        if ($sesja !== null && $sesja->trwa() && $sesja->maPomocnika($osoba)) {
            return redirect()->route('wspolne-gotowanie.show', $sesja);
        }

        // Komplet pomocników: link nie ma już dokąd wpuścić, więc nie kusimy przyciskiem.
        $maxPomocnikow = max(1, (int) config('kuking.wspolne_gotowanie.max_pomocnikow', 3));

        if (! $moze || $sesja->pomocnicy()->count() >= $maxPomocnikow) {
            return response()->view('pages.wspolne-gotowanie.link-nieaktualny', [], 410)
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->view('pages.wspolne-gotowanie.link', [
            'sesja' => $sesja,
            'recipe' => $przepis,
            'gospodarz' => $sesja->host,
            'token' => $token,
            'wazneDo' => $zaproszenie->expires_at,
            'maxPomocnikow' => $maxPomocnikow,
        ])->header('Referrer-Policy', 'no-referrer');
    }

    public function przyjmijLink(Request $request, string $token): RedirectResponse|Response
    {
        try {
            $sesja = $this->zaproszenia->dolacz($request->user(), $token);
        } catch (BladDlaCzlowieka) {
            return response()->view('pages.wspolne-gotowanie.link-nieaktualny', [], 410)
                ->header('Referrer-Policy', 'no-referrer');
        }

        return redirect()->route('wspolne-gotowanie.show', $sesja)
            ->with(Komunikat::sukces('Jesteś w sesji. Widzisz ten sam przepis i ten sam postęp co gospodarz.'));
    }

    /** Odhaczanie bez dostępu do przepisu nie ma sensu — i nie ujawnia, że sesja istnieje. */
    private function wymagajPrzepisu(Request $request, CookingSession $sesja): void
    {
        $przepis = $sesja->recipe;

        abort_if(
            $przepis === null || $przepis->trashed() || ! Gate::forUser($request->user())->allows('view', $przepis),
            403,
            'Nie masz już dostępu do tego przepisu, więc nie możesz zmieniać postępu.',
        );
    }

    private function ostrzezenieOWidocznosci(Recipe $przepis): ?string
    {
        if (! $przepis->isPublished() || $przepis->visibility === 'private') {
            return 'Ten przepis jest prywatny. Pomocnik go nie zobaczy, więc nie będzie mógł dołączyć — żeby gotować razem, przepis musi być dla niego widoczny.';
        }

        if ($przepis->visibility === 'followers') {
            return 'Ten przepis widzą tylko osoby, które obserwują jego autora. Pomocnik dołączy, jeśli go obserwuje.';
        }

        return null;
    }
}
