<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Media\Actions\PrzypnijAwatar;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\KasujZdjecie;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\Komunikat;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Zdjęcie profilowe — jeden ekran, jedna czynność.
 *
 * PO CO OSOBNY EKRAN, SKORO POLE JUŻ BYŁO
 * Bo droga do niego była dłuższa niż sama czynność. Zdjęcie profilowe stało
 * jako szóste pole w formularzu `/ustawienia/profil`, a ten formularz jest
 * osiągalny dopiero przez menu → Ustawienia (które prowadzi na „Czytelność")
 * → Profil → przewinięcie pod pola nazwy, nazwy użytkownika, opisu, regionu
 * i specjalności. Człowiek, który chce tylko wstawić swoje zdjęcie, po drodze
 * mija pięć pól, których nie zamierzał ruszać.
 *
 * Kotwica (`#f-avatar`) w tamtym formularzu byłaby półśrodkiem: na telefonie
 * ląduje się w środku ekranu pełnego innych pól, bez kontekstu, co się właśnie
 * otworzyło.
 *
 * DRUGI, WAŻNIEJSZY POWÓD: JEDNA CZYNNOŚĆ = JEDEN FORMULARZ
 * Formularz profilu wysyła WSZYSTKIE pola naraz i waliduje je razem. Zmiana
 * samego zdjęcia odbijała się więc od błędu przy nazwie użytkownika (zajęta,
 * zastrzeżona, za krótka) — czyli od czegoś, czego człowiek w ogóle nie
 * dotykał. Pole zdjęcia zostało stamtąd PRZENIESIONE, a nie skopiowane:
 * druga droga wgrywania tego samego to druga okazja do rozjazdu.
 *
 * AUTORYZACJA BEZ IDENTYFIKATORA W ADRESIE
 * Trasa nie przyjmuje żadnego identyfikatora — działa zawsze na profilu osoby
 * zalogowanej. To jest mocniejsze niż `/@basia/zdjecie` z Policy, bo nie ma
 * czego podmienić w adresie. Mimo to wołamy `ProfilePolicy::update` przez
 * `authorize()`: reguła „swoje zdjęcie zmienia właściciel konta" ma mieszkać
 * w Policy, a nie w tym, że akurat nikt nie dopisał parametru do trasy
 * (AGENTS.md §7: „UUID w adresie NIE JEST autoryzacją").
 */
class AvatarSettingsController extends Controller
{
    public function __construct(
        private readonly StoreUploadedImage $storeImage,
        private readonly KasujZdjecie $kasujZdjecie,
        private readonly PrzypnijAwatar $przypnijAwatar,
    ) {}

    public function edit(Request $request): View
    {
        $profile = $request->user()->profile;

        $this->authorize('update', $profile);

        return view('pages.settings.avatar', [
            'profile' => $profile,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $profile = $user->profile;

        $this->authorize('update', $profile);

        $request->validate([
            // `required`, bo na tym ekranie brak pliku nie jest „zostaw jak
            // było" — to jest jedyne pole formularza. Bez tego kliknięcie
            // „Zapisz zdjęcie" bez wybrania pliku kończyło się komunikatem
            // „Zapisane." i niezmienionym zdjęciem, czyli kłamstwem.
            'avatar' => ['required', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
        ], [
            'avatar.required' => 'Najpierw wybierz zdjęcie z telefonu albo z komputera.',
            'avatar.file' => 'Nie udało się odczytać pliku. Spróbuj wybrać zdjęcie jeszcze raz.',
            'avatar.max' => LimityZdjec::komunikatZaDuzyPlik(),
        ]);

        try {
            $zdjecie = $this->storeImage->handle($user, $request->file('avatar'));
        } catch (BladDlaCzlowieka $e) {
            // Bez `withInput()`: pliku i tak nie da się odtworzyć w polu
            // (przeglądarki na to nie pozwalają), a reszta profilu nie jest
            // częścią tego formularza, więc nie ma czego gubić.
            return back()->withErrors(['avatar' => $e->getMessage()]);
        }

        // Poprzednie zdjęcie zostaje na dysku do przebiegu
        // `kuking:sprzataj-osierocone-zdjecia` (doba karencji) — tak samo jak
        // przed tą zmianą. Świadomie NIE kasujemy go tutaj, w odróżnieniu od
        // `destroy()` niżej: podmiana zdjęcia nie jest obietnicą, że stare
        // zniknęło z internetu w tej sekundzie, a kasowanie plików to ruch po
        // sieci do R2 doklejony do żądania, które właśnie przyjęło i zapisało
        // kilkumegabajtowy plik. `destroy()` taką obietnicę składa wprost
        // i tam ta cena jest do zapłacenia.
        //
        // SAMO PRZYPIĘCIE IDZIE POD BLOKADĄ WIERSZA `media` (D-103,
        // dokończenie D-083). Przedtem stała tu goła aktualizacja profilu,
        // bez transakcji i bez blokady: między wgraniem pliku a zapisem
        // kolumny sprzątacz osieroconych zdjęć mógł przejąć ten wiersz
        // i skasować pliki. `profiles.avatar_media_id` ma
        // `ON DELETE SET NULL` (migracja `2026_09_05_000200_create_profiles_table`),
        // więc kolumna wracała po cichu do `null` — bez wyjątku i bez wpisu
        // w logu. Człowiek widział „Zdjęcie zapisane." i puste miejsce po
        // awatarze.
        try {
            $this->przypnijAwatar->handle($user, $zdjecie);
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['avatar' => $e->getMessage()]);
        }

        // ZDJĘCIE PROFILOWE NIE IDZIE DO OCENY MODELEM (D-240). Do #237
        // w tym miejscu zlecaliśmy `PrzeanalizujAwatar`, które wysyłało
        // miniaturę do OpenAI. Decyzja właściciela: awatar bez potwierdzonej
        // zgody nie wychodzi, a mechanizmu takiej zgody w serwisie nie ma.
        // Awatar dalej podlega zgłoszeniom od ludzi, jak każda treść.

        return redirect()
            ->route('settings.avatar')
            ->with('status', 'Zdjęcie zapisane. Za chwilę pojawi się przy Twoich wpisach.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $profile = $request->user()->profile;

        $this->authorize('update', $profile);

        $zdjecie = $profile->avatar;

        if ($zdjecie === null) {
            return redirect()->route('settings.avatar');
        }

        // Identyfikator jest warunkiem zgodności formularza, nie uprawnieniem
        // do dowolnego zdjęcia. Brak pola w starej karcie również oznacza odmowę.
        if ($request->input('avatar_media_id') !== (string) $zdjecie->getKey()) {
            return redirect()->route('settings.avatar')
                ->with(Komunikat::blad('Zdjęcie profilowe zmieniło się lub formularz jest nieaktualny. '
                    .'Sprawdź aktualne zdjęcie i ponownie wybierz „Usuń zdjęcie”, jeśli chcesz je usunąć. Nic nie usunęliśmy.'));
        }

        // Odpięcie PRZED kasowaniem — inaczej `KasujZdjecie::jestUzywane()`
        // zobaczy własny wiersz `profiles.avatar_media_id` i słusznie odmówi.
        //
        // ODPIĘCIE JEST WARUNKOWE (D-103). Przedtem kolumna zerowała się
        // bezwarunkowo, na podstawie `$profile->avatar` odczytanego wyżej —
        // czyli akcja ufała modelowi podanemu z zewnątrz (D-079 §3). Kto
        // wgrał NOWE zdjęcie między odczytem w tym żądaniu a odpięciem,
        // tracił je przez kliknięcie „Usuń zdjęcie”: zerowała
        // się kolumna wskazująca już na nowe zdjęcie, więc po dobie karencji
        // zabierał je sprzątacz osieroconych zdjęć razem z plikami.
        if (! $this->przypnijAwatar->odepnij($request->user(), $zdjecie)) {
            return redirect()
                ->route('settings.avatar')
                ->with(Komunikat::blad('Zdjęcie profilowe zmieniło się w międzyczasie — nic nie usunęliśmy. '
                    .'Sprawdź, które zdjęcie masz teraz, i kliknij „Usuń zdjęcie” jeszcze raz, jeśli nadal chcesz je usunąć.'));
        }

        // PLIKI LECĄ OD RAZU, A NIE PRZEZ SPRZĄTANIE OSIEROCONYCH.
        //
        // Tutaj człowiek prosi wprost o usunięcie swojej twarzy, a serwis
        // odpowiada „Zdjęcie usunięte". Zostawienie pliku pod działającym
        // adresem na dobę robi z tego zdania nieprawdę — ta sama klasa błędu
        // co issue #93 (awatar zostawał na dysku po wymazaniu konta).
        //
        // Porażka nie jest tu awarią: `KasujZdjecie` łapie błędy dysku,
        // loguje je i zostawia wiersz `media` na miejscu, więc nieprzypięte
        // zdjęcie trafi w kolejny przebieg
        // `kuking:sprzataj-osierocone-zdjecia`. Człowiek i tak ma już to,
        // o co prosił — zdjęcie zniknęło z jego profilu.
        $this->kasujZdjecie->jesliNieuzywane($zdjecie);

        return redirect()
            ->route('settings.avatar')
            ->with('status', 'Zdjęcie usunięte. Zamiast niego pokazujemy pierwszą literę Twojego imienia.');
    }
}
