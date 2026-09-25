<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Rules\ReservedUsername;
use App\Rules\UsernameNotTaken;
use App\Support\NazwaUzytkownika;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Profil: imię, nazwa użytkownika, kilka słów o sobie.
 *
 * ZDJĘCIA PROFILOWEGO TU JUŻ NIE MA — jest na `/ustawienia/zdjecie`
 * (`AvatarSettingsController`). Nie jest to druga droga obok tej: pole
 * zostało STĄD PRZENIESIONE. Powód jest w dwóch miejscach naraz:
 *
 *  - droga: zdjęcie stało jako szóste pole formularza, pod nazwą, nazwą
 *    użytkownika, opisem, regionem i specjalnością — czyli pod pięcioma
 *    polami, których człowiek chcący wstawić swoją twarz nie zamierzał ruszać;
 *  - walidacja: ten formularz wysyła WSZYSTKIE pola naraz, więc zmiana samego
 *    zdjęcia odbijała się od błędu przy nazwie użytkownika (zajęta,
 *    zastrzeżona, za krótka) — od czegoś, czego nikt tu nie dotykał.
 */
class ProfileSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('pages.settings.profile', [
            'profile' => $request->user()->profile,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $profile = $user->profile;

        /*
         * TA SAMA NORMALIZACJA CO PRZY REJESTRACJI, I Z TEGO SAMEGO POWODU
         * (patrz `NazwaUzytkownika`): człowiek, który wpisze „Małgorzata
         * Kowalska", ma dostać `malgorzata_kowalska`, a nie pouczenie
         * o dozwolonych znakach.
         *
         * Musi stać PRZED sprawdzeniem, czy nazwa się zmienia (niżej) —
         * inaczej wpisanie własnej nazwy w innym zapisie („Basia" przy
         * zapisanej `basia`) wyglądałoby jak zmiana i włączałoby kontrolę
         * nazw zastrzeżonych tam, gdzie nic się nie zmienia.
         */
        $request->merge([
            'username' => NazwaUzytkownika::znormalizuj((string) $request->input('username', '')),
        ]);

        // Druga — łatwiejsza do przeoczenia — droga do nazwy użytkownika.
        // Sprawdzanie tylko przy rejestracji byłoby zabezpieczeniem na pokaz:
        // wystarczyłoby założyć konto „basia" i zmienić je tutaj na „pomoc".
        $usernameRules = [
            'required', 'string', 'min:3', 'max:40', 'regex:/^[a-zA-Z0-9_]+$/',
        ];

        // Listę zastrzeżonych sprawdzamy TYLKO wtedy, gdy nazwa faktycznie się
        // zmienia. Konta obsługi mają takie nazwy legalnie (w DemoSeederze jest
        // @moderacja) — a formularz profilu wysyła wszystkie pola naraz, więc
        // reguła działająca też przy niezmienionej nazwie zabrałaby takiej
        // osobie możliwość zapisania czegokolwiek: bio, regionu, specjalności.
        // Odebranie komuś zastrzeżonej nazwy to sprawa moderacji, nie
        // walidatora formularza ustawień.
        if ($request->input('username') !== $profile->username) {
            $usernameRules[] = new ReservedUsername;
        }

        // Zajętość sprawdzamy bez rozróżniania wielkości liter (audyt A25) —
        // inaczej ta droga zostawiałaby otwartą furtkę, którą rejestracja
        // właśnie zamknęła: konto „basia2" zmieniające nazwę na „Basia".
        $usernameRules[] = new UsernameNotTaken($user->getKey());

        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:'.config('kuking.profil.dlugosc_nazwy')],
            'username' => $usernameRules,
            'bio' => ['nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:80'],
            'speciality' => ['nullable', 'string', 'max:120'],
        ], [
            'display_name.required' => 'Podaj imię, którym mamy Cię nazywać.',
            'display_name.min' => 'To imię jest za krótkie. Wpisz co najmniej dwie litery — na przykład „Basia”.',
            'display_name.max' => 'To imię jest za długie. Zmieść się w :max znakach.',
            /*
             * `username.required` DOPISANE PRZY PRZEGLĄDZIE KOMUNIKATÓW.
             *
             * Bez niego wypadał szablon ogólny: „Pole «nazwa użytkownika»
             * jest wymagane. Uzupełnij je, żeby wysłać formularz." Zdanie
             * było nie tylko puste, ale w połowie przypadków NIEPRAWDZIWE:
             * normalizacja wyżej zamienia „!!" albo same emoji w pusty ciąg,
             * więc człowiek, który COŚ wpisał, czytał, że pola nie wypełnił.
             * Nowe zdanie mówi, co wpisać, i pasuje do obu sytuacji.
             */
            'username.required' => 'Wpisz nazwę, która ma być w adresie Twojego profilu — na przykład imię i miejscowość: basia z podkarpacia.',
            // Do `regex` i `min` dochodzi się już tylko wtedy, gdy z wpisanego
            // tekstu nie da się nic ułożyć — patrz komentarz przy normalizacji.
            'username.regex' => 'Z tej nazwy nie da się ułożyć adresu. Wpisz imię albo imię i miejscowość.',
            'username.min' => 'Ta nazwa jest za krótka. Wpisz co najmniej trzy znaki — na przykład imię i miejscowość: basia z podkarpacia.',
            'username.max' => 'Ta nazwa jest za długa. Zmieść się w 40 znakach.',
            'username.unique' => 'Ta nazwa jest już zajęta.',
            'bio.max' => 'Ten opis jest za długi. Zmieść się w 500 znakach.',
            /*
             * DWA OSTATNIE — bo szablon ogólny nazywał te pola inaczej niż
             * ekran. Na ekranie stoi „Skąd jesteś" i „Na czym się znasz",
             * a komunikat mówił o polu „region" i „specjalność kulinarna".
             */
            'region.max' => 'To jest za długie. Napisz krócej, mieszcząc się w 80 znakach — wystarczy sama miejscowość albo region.',
            'speciality.max' => 'To jest za długie. Napisz krócej, mieszcząc się w 120 znakach — wystarczy kilka słów.',
        ]);

        /*
         * WYŚCIG O TĘ SAMĄ NAZWĘ (issue #887).
         *
         * `UsernameNotTaken` sprawdza stan z chwili odczytu, ale niczego nie
         * rezerwuje: dwie osoby wybierające tę samą wolną nazwę przechodzą
         * walidację obie, a drugi UPDATE odbija się od unikalnego indeksu.
         * Bez tego przechwycenia druga osoba dostawała błąd serwera i traciła
         * wszystko, co wpisała w formularz.
         *
         * `DB::transaction` daje savepoint, gdy żądanie już jest w transakcji
         * — inaczej złapane 23505 zostawia połączenie w stanie „transaction
         * aborted" (ten sam powód co w `User::defaultCollection()`).
         *
         * Łapiemy WYŁĄCZNIE naruszenie indeksów nazwy. Każda inna kolizja
         * unikalności leci dalej do zwykłej obsługi błędów — zamiana jej na
         * „nazwa zajęta" schowałaby prawdziwą awarię. Wyjątku nie logujemy:
         * jego komunikat zawiera wartości z zapytania.
         */
        try {
            DB::transaction(fn () => $profile->update($data));
        } catch (UniqueConstraintViolationException $e) {
            if (! self::naruszonoIndeksNazwy($e)) {
                throw $e;
            }

            // Model trzyma już nową, niezapisaną nazwę — nie może z nią
            // pójść dalej w tym żądaniu.
            $profile->refresh();

            // ValidationException wraca na formularz z `withInput()`, więc
            // imię, opis, region i specjalność zostają w polach.
            throw ValidationException::withMessages([
                'username' => 'Ta nazwa jest już zajęta — wybierz inną. Ktoś zajął ją przed chwilą. Pozostałe pola zostały bez zmian — popraw tylko nazwę i kliknij „Zapisz”.',
            ]);
        }

        return back()->with('status', 'Zapisane.');
    }

    /**
     * Stary `profiles_username_unique` (dokładny zapis) i funkcyjny
     * `profiles_username_lower_unique` pilnują tej samej nazwy — o tym,
     * który z nich zgłosi kolizję, decyduje PostgreSQL, więc uznajemy oba.
     */
    private static function naruszonoIndeksNazwy(UniqueConstraintViolationException $e): bool
    {
        for ($wyjatek = $e; $wyjatek !== null; $wyjatek = $wyjatek->getPrevious()) {
            if (preg_match('/"profiles_username(_lower)?_unique"/', $wyjatek->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
