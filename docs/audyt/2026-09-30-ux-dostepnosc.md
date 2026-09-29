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

## Sprawdzone i w porządku

- **axe-core** (wcag2a/aa, 21a/aa, 22aa) na 390 i 1280 px: zero naruszeń na 44 ekranach (gość i zalogowany), w tym przepis, „Ugotowałem”, tryb gotowania, historia wersji, planer, zeszyt, powiadomienia, wszystkie podstrony ustawień, onboarding `/witaj/*`.
- **Przewijanie w bok:** brak na żadnym z tych ekranów przy 320, 390, 640 (200%), 768 i 1280 px.
- **Rejestracja (błąd serwera):** podsumowanie na górze + błąd przy polu, fokus na podsumowaniu, `aria-invalid`, nazwa/e-mail/zaznaczenie wieku zostają po błędzie (hasło celowo czyszczone).
- **Pierwszy wpis:** błąd złego pliku po polsku z instrukcją; opis, widoczność i wybrane zdjęcie zostają po „Sprawdź tag” (komunikat „Twoje zdjęcia są zachowane”).
- **„Ugotowałem” z przepisu:** przycisk główny 332×51 px; błąd czasu po polsku („Wpisz najwyżej 10080 minut”); notatka i dobre zdjęcie zostają po błędzie.
- **Kreator przepisu:** brak nazwy → podsumowanie + pole; składniki, przygotowanie i zdjęcie główne zostają.

