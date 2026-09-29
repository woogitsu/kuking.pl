# Audyt UX 50+ i dostępności — 30 września 2026

**Stan:** w toku (zapis przyrostowy).
**Baza:** `origin/claude/paczka-i-kandydat` @ `5548c7e16`.
**Metoda:** aplikacja lokalnie (`php artisan serve`, `npm run build` na własnym
`node_modules`, `DemoSeeder`), Chromium 141 (`/opt/pw-browsers/chromium`)
przez Playwright 1.63 i `@axe-core/playwright` (tagi `wcag2a`, `wcag2aa`,
`wcag21a`, `wcag21aa`, `wcag22aa`). Szerokości 320 / 390 / 640 (= 1280 px przy
powiększeniu 200%) / 768 / 1280 px.

## Podsumowanie

(uzupełniane)

## Znaleziska

| ID | Waga | Tytuł | Dowód (plik:linia na BAZIE) | Odtworzenie | Wpływ | Poprawka i test regresyjny | Rozmiar | Duplikat? |
|---|---|---|---|---|---|---|---|---|
| UX-01 | P2 | Puste pole wymagane zatrzymuje dymek przeglądarki, a nie podsumowanie błędów Kuking | `resources/views/components/field.blade.php:219` (`required` na każdym polu wymaganym); `resources/views/auth/register.blade.php:20` (formularz bez `novalidate`) | Chromium 390 px, `/register`: klik „Załóż konto” przy pustym formularzu. Brak żądania do serwera; `#f-display_name.validationMessage` = komunikat przeglądarki; brak `.error-summary`, brak `.field-error` | Dymek pokazuje się tylko przy pierwszym pustym polu i znika po kilku sekundach. Jego język i treść zależą od przeglądarki („Wypełnij to pole”), a nie mówią, co zrobić. Łamie AGENTS.md §5 („błąd przy polu ORAZ w podsumowaniu”). Bez `required` (atrybut usunięty w teście) serwer zwraca poprawne polskie podsumowanie i błędy przy polach | `novalidate` na formularzach z `x-field` (zostawić `required` dla czytnika albo zastąpić `aria-required`). Test widoku: formularz rejestracji ma `novalidate`; test przeglądarkowy: puste „Załóż konto” pokazuje `.error-summary` | S | nie znaleziono (wyszukiwanie issues „required natywna walidacja”) |
| UX-02 | P3 | Samodzielne zdania instrukcji i pustych stanów w 16 px (`.meta`) | `resources/views/pages/planer/show.blade.php:37` i `:53`; `resources/views/pages/search.blade.php:130`; `resources/views/pages/recipes/historia.blade.php:7`; `resources/views/pages/settings/privacy.blade.php:132`; `resources/views/pages/settings/ukryte.blade.php:74`; `resources/views/components/comment-thread.blade.php:313`; styl `resources/css/app.css:2868` (`.meta` → `--text-help`, 16 px) | Pomiar `getComputedStyle` na 320–1280 px: np. `/planer` „Przy każdym dniu wyszukasz przepis…” 16 px, „Nic jeszcze nie zaplanowane.” 16 px | `docs/design/DESIGN_SYSTEM.md:174` dopuszcza 16 px „tylko tam, gdzie tekst główny obok jest ≥ 18 px”. Tu zdanie stoi samo i jest jedyną instrukcją ekranu (planer, pusta wyszukiwarka, historia wersji) | Te akapity na `--text-body` (osobna klasa albo `p.meta` bez sąsiedztwa → 18 px). Test: pomiar w `scripts/audyt-ux50plus.mjs` dla `/planer`, `/szukaj`, `/przepisy/{slug}/historia` | S | nie (B1 #2 dotyczył `.field-error`) |
| UX-03 | P2 | Po złym haśle przy usuwaniu konta formularz jest zwinięty: pola z błędem nie widać | `resources/views/pages/settings/data.blade.php:200` (`<details>` bez `open` przy błędach), `:198` (podsumowanie nad zwiniętym blokiem); haczyk `confirm` bez `old()` `:240-241` | Chromium 390 px: nowe konto → `/ustawienia/twoje-dane` → „Chcę usunąć swoje konto” → oba haczyki + złe hasło → „Usuń moje konto”. Po przeładowaniu `details.open === false`, pole hasła niewidoczne; podsumowanie „Wpisz poprawne hasło…” wskazuje `#f-password`. Chromium rozwija `details` po kliknięciu odnośnika, ale haczyk „Rozumiem…” jest już odznaczony | Osoba widzi błąd, ale nie widzi pola, którego on dotyczy. Musi się domyślić, że trzeba jeszcze raz kliknąć „Chcę usunąć swoje konto” i ponownie zaznaczyć „Rozumiem…”. Podejrzenie (niezweryfikowane): Safari na iOS nie rozwija `details` po przejściu do kotwicy, więc tam odnośnik z podsumowania nic nie zrobi | `<details @if($errors->hasAny(['password','confirm','usun_tresci'])) open @endif>`; ewentualnie `@checked(old('confirm'))` (do decyzji: ponowne potwierdzenie może być celowe). Test widoku: po `withErrors(['password'=>…])` blok ma `open` | S | nie; #812 (zamknięte) dotyczyło haczyka zakresu |
| UX-04 | P2 | Droga powrotu po usunięciu konta to adres wpisany w komunikat zwykłym tekstem | `app/Http/Controllers/Settings/DataSettingsController.php:218-219` (adres `route('account.delete.cancel')` sklejony w treść), `resources/views/components/layout.blade.php:1000` (`{{ session('status') }}`, czyli tekst bez odnośnika) | Po zleceniu usunięcia strona `/` pokazuje: „…zrobisz to na stronie „Cofnij usunięcie konta” (http://127.0.0.1:8291/cofnij-usuniecie-konta)…”. Adres nie jest odnośnikiem. `curl /login` i `curl /`: 0 wystąpień `cofnij-usuniecie-konta` | Osoba 50+, która zmieni zdanie po chwili (jest wylogowana), musi przepisać adres ręcznie albo szukać go w poczcie. Na ekranie logowania nie ma odnośnika „Cofnij usunięcie konta” | Flash z odnośnikiem (osobny komponent albo przycisk-odnośnik pod komunikatem) albo odnośnik „Zmieniłem zdanie — cofnij usunięcie” na `/login`. Test: odpowiedź po `POST /ustawienia/twoje-dane/usun-konto` zawiera `<a href=".../cofnij-usuniecie-konta">` | S | nie znaleziono |
| UX-05 | P3 | „Dodane do planu na środa” — dzień tygodnia w mianowniku | `app/Http/Controllers/PlanerController.php:103-108` (`"Dodane do planu na {$kiedy}."`, `$kiedy = PlanerTygodnia::nazwaDnia()` w mianowniku) | `/planer` → środa → „rosol” → „Szukaj przepisu” → „Dodaj do planu”: komunikat „Dodane do planu na środa, 30 września.” (także „Ten przepis już jest w planie na …”, „Dopisane na …”) | Błąd językowy w potwierdzeniu, które osoba czyta po każdym dodaniu; obniża zaufanie („na środę”, „na sobotę”, „na niedzielę”) | Biernik w `nazwaDnia()` albo osobna metoda `naDzien()`. Test jednostkowy dla 7 dni | S | nie znaleziono |

## Sprawdzone i w porządku

- **axe-core** (wcag2a/aa, 21a/aa, 22aa) na 390 i 1280 px: zero naruszeń na 44 ekranach (gość i zalogowany), w tym przepis, „Ugotowałem”, tryb gotowania, historia wersji, planer, zeszyt, powiadomienia, wszystkie podstrony ustawień, onboarding `/witaj/*`.
- **Przewijanie w bok:** brak na żadnym z tych ekranów przy 320, 390, 640 (200%), 768 i 1280 px.
- **Rejestracja (błąd serwera):** podsumowanie na górze + błąd przy polu, fokus na podsumowaniu, `aria-invalid`, nazwa/e-mail/zaznaczenie wieku zostają po błędzie (hasło celowo czyszczone).
- **Pierwszy wpis:** błąd złego pliku po polsku z instrukcją; opis, widoczność i wybrane zdjęcie zostają po „Sprawdź tag” (komunikat „Twoje zdjęcia są zachowane”).
- **„Ugotowałem” z przepisu:** przycisk główny 332×51 px; błąd czasu po polsku („Wpisz najwyżej 10080 minut”); notatka i dobre zdjęcie zostają po błędzie.
- **Zeszyt:** zapis przepisu przez „Wybierz zeszyt”; pusty tytuł nowego zeszytu → polski błąd, opis zostaje.
- **Planer:** szukanie i dodanie przepisu do dnia; fokus wraca na grupę dnia (`tabindex=-1`); pusty własny wpis → polski błąd przy polu i w podsumowaniu; axe czysto.
- **Powiadomienia:** autor przepisu (basia) widzi „Halina Testowa — ugotowane z Twojego przepisu… Jest zdjęcie.” i „ma Twój przepis w swoim zeszycie”; wszystkie przyciski ≥ 48 px; licznik w nawigacji ma nazwę „Powiadomienia 4 nieprzeczytanych”.
- **Usunięcie konta:** potwierdzenie hasłem + haczyk, akcja odsunięta za `details`, komunikat po polsku z 30 dniami i zakresem.
- **Bez JavaScriptu (27 ekranów):** brak widocznych przycisków `type=button` i przycisków bez formularza — brak martwych przycisków.
- **Kreator przepisu:** brak nazwy → podsumowanie + pole; składniki, przygotowanie i zdjęcie główne zostają.

