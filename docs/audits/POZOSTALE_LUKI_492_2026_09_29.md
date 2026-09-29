# #492 — pozostałe luki marki, stan na 29.09.2026

Źródła: treść #492 i wszystkie jego komentarze (24, do 21.09.2026), bieżąca baza
gałęzi (paczka H), [`WERYFIKACJA_713_2026_09_29.md`](WERYFIKACJA_713_2026_09_29.md),
[`../design/MACIERZ_KOMPLETNOSCI_517.md`](../design/MACIERZ_KOMPLETNOSCI_517.md).
Ten dokument zastępuje **jako bieżący widok** starsze listy z komentarzy (kronika
wdrożeń, nie kolejka zadań) i
[`../design/POZOSTALE_LUKI_492.md`](../design/POZOSTALE_LUKI_492.md) (historyczny).
Nie implementuje niczego ponownie i nie zastępuje odbiorów; to tylko indeks.

## 1. Luki wskazane w komentarzach, które są już w kodzie

| Wskazanie w komentarzu #492 | Stan na bazie |
|---|---|
| #946 — `aria-current` na „Mój zeszyt” i „Profil” przy cudzej treści | scalone, commit `7648b7570` |
| #948 — „Pokaż hasło” we wspólnym `x-field` | scalone, commit `2e7c63a0b` (PR #1611) |
| #949 — `autocomplete` w edycji profilu | scalone, commit `fe3285986` (PR #1558) |
| #648 — listy relacji z błędnym `users.id` | zamknięte (#650) |
| #638 — etykiety dolnej nawigacji | zamknięte po odbiorze |
| #561, #548, #549, #567, #574, #579, #646, #652, #569 | zamknięte; nie powtarzać |
| Zasłanianie treści przez nagłówek i podpowiedź „Wygląd” (#684) | zmierzone lokalnie i w CI (`scripts/pasek-uklady.mjs`), obserwacja z 17.09 nie potwierdziła błędu |
| Dług weryfikacyjny #713 D1, D3–D7 | zrobione w repo (patrz `WERYFIKACJA_713_2026_09_29.md`) |

Przegląd `resources/views` nie znalazł pozostałej klasy `lead` ani innych śladów
starego UI poza panelem moderacji.

## 2. Co naprawdę zostało

Podział: **A** — praca w kodzie innej sesji, **B** — wymaga właściciela, produkcji lub
urządzenia, **C** — decyzja właściciela.

| Grupa | Pozycja | Kto / czego brak |
|---|---|---|
| A | Panel moderacji #581: oprawa, produkcyjne pełne zgłoszenia, wyzwanie 2FA | osobna sesja (#581); nie ruszać w #492 |
| B | Produkcyjny odbiór po wdrożeniu: wstęp `text-lead` (D4), pasek w gotowaniu i panelu (D6), blok „UWAGA” w prawdziwej paczce (D5) | ogląd na produkcji |
| B | Tagi #370/#681: kolaż z ≥ 3 autorów, wariant fotograficzny katalogu | realne dane |
| B | Liczby #666 (2/5/22 wykonań), puste stany zeszytu #667 na koncie produkcyjnym | realny ruch, konto |
| B | „Wyszło / Podziękuj”: zdjęcia wykonania, rzeczywisty zoom 200%, wysyłka mobilna | ogląd lokalny lub produkcyjny |
| B | Logowanie: pełne hasło z prawdziwym Turnstile (C4), zewnętrzny OAuth | konto, dostawca |
| B | Formularze: nowe uploady, dane maksymalne, pełny fokus, pozostałe kombinacje błędów | odbiór lokalny |
| B | Urządzenia: fizyczny telefon Android i iOS/Safari, natywna instalacja PWA (#278), druk i rozpakowanie paczki (C3) | urządzenia |
| B | Poczta: Gmail, Outlook, Apple Mail, ciemny tryb klienta; wyłączenie otwarć u dostawcy (A3) | panele i skrzynki |
| B | Czytnik ekranu: odsłuch NVDA/VoiceOver minutnika (C1) | człowiek |
| B | Badania z użytkownikami #15, ocena prawna #8 | ludzie, prawnik |
| C | Nazwa „Pozostały czas” dla minutnika (`cooking.blade.php`, `role="timer"` bez nazwy) | właściciel po odsłuchu C1; D-333 tego nie rozstrzyga |
| C | Wpięcie skryptów przeglądarkowych (`pasek-uklady`, `lead-wstep`, `eksport-bloki-692`, `turnstile-csp`) do joba „Port marki” | właściciel (czas CI) i pełny przebieg joba |
| C | „Poradźcie” (`KUKING_QUESTIONS_ENABLED`) — włączenie na produkcji | właściciel (D-221) |

## 3. Kryterium zamknięcia #492

Definicja gotowości z opisu issue jest spełniona w części dokumentacyjnej:
nie ma nieprzypisanej listy historycznych pakietów, a każda pozycja z tabeli
powyżej ma właściciela. **Nie zamykać #492**, dopóki #581 jest otwarte, a pozycje
grupy B nie mają odbioru albo jawnego przeniesienia do `not planned` przez właściciela.
Dla każdej pozycji grupy B, której jeszcze nie ma w osobnym issue, założyć jedno wąskie
issue z kryterium odbioru (zgodnie z opisem #492), zamiast dopisywać ją tutaj.
