<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Onboarding\ObserwujWybraneOsoby;
use App\Domain\Onboarding\PrzygotujEkranLudzi;
use App\Domain\Tags\Actions\UpdateTagFollows;
use App\Http\Requests\Onboarding\EkranLudziRequest;
use App\Http\Requests\Onboarding\ZapisObserwowanychRequest;
use App\Http\Requests\Onboarding\ZapisZainteresowanRequest;
use App\Models\Tag;
use App\Support\Komunikat;
use App\Support\PowrotDoRozmowy;
use App\Support\ZamiarObserwowania;
use App\Support\ZamiarUgotowania;
use App\Support\ZamiarZapisu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Onboarding: zainteresowania → opcjonalne obserwowanie → gotowe.
 *
 * Twarda zasada z docs/UX_50_PLUS.md: NIE WYMUSZAMY PUBLIKACJI.
 * Na końcu są dwa równorzędne wyjścia — "Dodaj pierwsze zdjęcie" oraz
 * "Na razie tylko pooglądam". Osoba, którą przymusi się do publikacji,
 * publikuje raz i nie wraca.
 *
 * Każdy krok da się pominąć i żaden nie blokuje korzystania z serwisu.
 */
class OnboardingController extends Controller
{
    /**
     * Krok „co lubisz gotować" (D-021 — czyta teraz `Tag`, nie `Topic`).
     *
     * Lista to `Tag::scopePromowane()` — tagi z listy gospodarza (D-021,
     * „tag promowany"), NIE „najpopularniejsze tagi". Przy zerowym ruchu
     * produkcyjnym popularność nie istnieje, więc lista po popularności
     * dałaby nowemu kontu pusty albo losowy ekran — dokładnie problem,
     * który ten krok ma rozwiązywać. Widok radzi sobie z pustą listą
     * (gospodarz jeszcze niczego nie promował) pokazując zachętę do
     * pominięcia kroku zamiast pustej siatki checkboxów.
     */
    public function interests(): View
    {
        return view('pages.onboarding.interests', [
            'tags' => Tag::promowane()->get(),
        ]);
    }

    public function saveInterests(ZapisZainteresowanRequest $request): RedirectResponse
    {
        app(UpdateTagFollows::class)->follow($request->user(), $request->tagi(), promotedOnly: true);

        return redirect()->route('onboarding.people');
    }

    /**
     * Krok „kogo obserwować" — i „znasz już kogoś tutaj?"
     * (`docs/research/MIGRACJA_Z_GARNKA.md` §3.1).
     *
     * Wejście czyta `EkranLudziRequest`, dane ekranu składa
     * `PrzygotujEkranLudzi` (tam też opis wyszukiwania i granic). `q` jest
     * parametrem GET, nie POST: to zwykłe wyszukiwanie w treści strony, więc
     * działa jako link do zapisania i wraca poprawnie po cofnięciu się
     * przeglądarką — zgodnie z AGENTS.md §5 (ważne rzeczy bez JavaScriptu).
     */
    public function people(EkranLudziRequest $request, PrzygotujEkranLudzi $ekran): View
    {
        [$context, $selectionValid] = $request->kontekstWyboru();

        return view('pages.onboarding.people', $ekran->handle(
            $request->user(),
            $request->fraza(),
            // Zaznaczenia z adresu liczą się tylko z tokenem bieżącego kontekstu.
            $selectionValid ? $request->zaznaczoneNazwy() : [],
            $request->oczekiwani(),
        ) + [
            'selectionContext' => $context['token'],
            'selectionExpired' => ! $selectionValid && $request->has('selection'),
        ]);
    }

    /**
     * Zapis kroku „kogo obserwować". Limit i kształt pól pilnuje
     * `ZapisObserwowanychRequest`, obserwowanie — `ObserwujWybraneOsoby`;
     * tu zostaje kolejność: zapis, oznaczenie końca pierwszych kroków, odpowiedź.
     */
    public function saveFollows(ZapisObserwowanychRequest $request, ObserwujWybraneOsoby $obserwuj): RedirectResponse
    {
        $wynik = $obserwuj->handle($request->user(), $request->zaznaczoneNazwy(), $request->oczekiwani());

        // Koniec pierwszych kroków zapisujemy tu, w POST — nie w GET
        // `/witaj/gotowe`, który przeglądarka może pobrać z wyprzedzeniem (#985).
        $this->oznaczZakonczony($request);

        $dalej = redirect()->route('onboarding.done');
        $komunikat = $wynik->komunikat();

        return $komunikat === null ? $dalej : $dalej->with(Komunikat::blad($komunikat));
    }

    public function done(Request $request, ZamiarObserwowania $zamiar, ZamiarUgotowania $gotowanie, PowrotDoRozmowy $rozmowa, ZamiarZapisu $zapis): View|RedirectResponse
    {
        // Bez zapisu stanu konta: GET może przyjść z prefetchu przeglądarki,
        // więc samo otwarcie tej strony nie wyłącza przypomnienia (#985).
        $request->session()->forget('onboarding.selection');

        // „Ugotowałem” PRZED obserwowaniem (#2058). Oba zamiary naraz
        // w sesji nie powinny się zdarzyć — nowszy link wypiera starszy
        // (`ZamiarUgotowania::zapamietaj`) — ale gdyby jednak, wygrywa
        // czynność przerwana w pół: formularz „Ugotowałem” jest ważniejszy
        // niż lajk (AGENTS.md §1), a przycisk „Obserwuj” autora i tak stoi
        // przy przepisie. Zamiar ugotowania zużywa się tu przy każdym
        // wejściu, także gdy przepis przestał być dostępny.
        if ($cel = $gotowanie->celPoOnboardingu($request)) {
            return redirect()->to($cel);
        }

        if ($cel = $zamiar->celPoOnboardingu($request)) {
            return redirect()->to($cel);
        }

        // Powrót do wątku komentarzy (#2027). Nowszy link wypiera starsze
        // zamiary (`PowrotDoRozmowy::zapamietaj`), więc kolejność jest tylko
        // zabezpieczeniem. Komentarz wysyła człowiek — tu tylko adres.
        if ($cel = $rozmowa->celPoOnboardingu($request)) {
            return redirect()->to($cel);
        }

        // „Zapisz do zeszytu” (#2028): wracamy na przepis z ROZWINIĘTYM
        // wyborem zeszytu. Nic nie zapisujemy — robi to dopiero klik człowieka.
        if ($cel = $zapis->celPoOnboardingu($request)) {
            return redirect()->to($cel);
        }

        return view('pages.onboarding.done', [
            'name' => $request->user()->displayName(),
            // Pytanie „Jak mamy do Ciebie pisać?” (D-332) — pole z profilu.
            'profile' => $request->user()->profile,
        ]);
    }

    /**
     * „Pomiń ten krok" na `/witaj/ludzie` — POST z CSRF, bo kończy
     * pierwsze kroki na stałe (#985).
     */
    public function skip(Request $request): RedirectResponse
    {
        $request->session()->forget('onboarding.selection');
        $this->oznaczZakonczony($request);

        return redirect()->route('onboarding.done');
    }

    /**
     * „Nie przypominaj" przy odnośniku na Starcie — trwała decyzja (#985).
     */
    public function dismiss(Request $request): RedirectResponse
    {
        $this->oznaczZakonczony($request);

        return redirect()->route('home')
            ->with(Komunikat::sukces('Dobrze, nie będziemy już przypominać o pierwszych krokach.'));
    }

    /** Tylko pierwszy raz: ponowne wejście pod `/witaj/...` niczego nie cofa ani nie przesuwa. */
    private function oznaczZakonczony(Request $request): void
    {
        $user = $request->user();

        if ($user->onboarding_zakonczony_at === null) {
            $user->forceFill(['onboarding_zakonczony_at' => now()])->save();
        }
    }
}
