# Audyt 6 — UX/UI, dostępność i użytkownicy 50–75

**Bazowy commit:** `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 2026-09-10

## Wniosek

Kuking jest projektowany pod starszą grupę użytkowników znacznie lepiej niż typowy serwis społecznościowy: duży tekst, cele dotykowe, jawne etykiety, polskie błędy, odzyskanie formularza po 419, brak gestów jako jedynej drogi, e-mail login link, proste publikowanie. Największy problem nie jest wizualny — to spójność modelu mentalnego rejestracji i onboardingu.

**Ocena: 8,5/10.**

## Najważniejsze ustalenia

### UX1 — P1 — „Cztery pola i gotowe” jest nieprawdziwą obietnicą końca

`resources/views/auth/register.blade.php` mówi: **„Cztery pola i gotowe.”** Po utworzeniu konta onboarding zaczyna się ekranem **„Krok 1 z 3”** (`resources/views/pages/onboarding/interests.blade.php`). Kroki są opcjonalne, ale copy tego nie komunikuje na granicy między rejestracją a onboardingiem.

**Skutek:** osoba, która spodziewała się końca procesu, widzi kolejny trzyetapowy kreator i może uznać, że założenie konta nadal się nie udało/zakończyło.

**Naprawa:** po sukcesie nazwać stan wprost: „Konto gotowe. Teraz możesz opcjonalnie ustawić swoją stronę główną — 3 krótkie kroki, każdy można pominąć.” Alternatywnie usunąć z rejestracji „i gotowe”.

### UX2 — P2 po delcie `e3cf6ab5` — dwa podobne identyfikatory nadal są na pierwszym ekranie

Rejestracja wymaga równocześnie:

- „Jak mamy Cię nazywać?” (`display_name`),
- „Twoja nazwa użytkownika” (`username`).

Dla użytkownika przychodzącego z Garnek.pl różnica między „nazwą widoczną” a „nazwą w adresie profilu” jest techniczna i mało wartościowa w pierwszej minucie.

**Delta po rozpoczęciu audytu:** commit `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104` znacząco zmniejszył tarcie: `username` jest normalizowany z naturalnego zapisu („Małgorzata Kowalska” → `malgorzata_kowalska`), pole przyjmuje polskie litery i spacje, a zajęta nazwa ma dostać gotową propozycję. To usuwa najgorszy błąd walidacyjny wykryty przez 63-letnią testerkę. Dlatego na aktualnym `main` obniżam wagę z P1 do **P2**.

**Naprawa rekomendowana:** docelowo rozważyć wymaganie tylko nazwy widocznej, a username generować automatycznie i pozwolić zmienić później. Nie jest to już bramka startowa.

### UX3 — P1 — publiczny landing jest zbyt gęsty przed prostym wyjaśnieniem produktu

Ostatni audyt dostępności na commicie bazowym wskazuje, że anonimowy landing pokazuje rozbudowaną/interaktywną zawartość przed prostym „Jak działa”. Dla migracji z Garnek najważniejsza powinna być odpowiedź na trzy pytania: „co to jest”, „czy znajdę tu ludzi/zdjęcia jak dawniej”, „jak zacząć”.

**Naprawa:** nad foldem: jedno zdanie pozycjonujące + 3 kroki + jeden CTA. Feed/demonstracja niżej.

### UX4 — P2 — instrukcja TOTP mówi „przepisz” mimo wsparcia paste/autofill

Technicznie pole wspiera `autocomplete=one-time-code` i wklejenie, ale język prowadzi użytkownika do trudniejszej czynności. Przy 60–75 należy promować najłatwiejszą ścieżkę, nie manualne przepisywanie.

**Naprawa:** „Wklej lub wpisz 6-cyfrowy kod z aplikacji.”

### UX5 — P2 — awaryjna konfiguracja 2FA używa żargonu w najgorszym momencie

„sekret (klucz TOTP)” pojawia się wtedy, gdy QR nie zadziałał. To właśnie ścieżka dla użytkownika, który już ma problem.

**Naprawa:** „Jeśli nie możesz zeskanować kodu QR, wybierz w aplikacji opcję ręcznego dodania konta i wklej ten klucz.” Termin techniczny można schować w nawiasie lub pomocy.

### UX6 — P1 — focus obscuration przez mobilną belkę nadal wymaga dowodu runtime

Mobilna dolna nawigacja jest `fixed` i może się zawinąć przy powiększonym tekście. Rezerwa treści nie jest powiązana z rzeczywistą wysokością belki. Automatyczne testy reflow nie dowodzą kryterium WCAG 2.2 2.4.11 „Focus Not Obscured”.

**Naprawa:** test Playwright iterujący po fokusowalnych elementach przy 320 px oraz powiększeniu/skalowaniu tekstu i sprawdzający przecięcie bounding boxa fokusu z dolną belką. Dodać `scroll-padding-bottom`/dynamiczny offset oparty na zmierzonej wysokości nawigacji.

### UX7 — P0 jako bramka publicznej bety — za mało realnych testów docelowej grupy

Issue #15 nadal zakłada przed publiczną betą 13 sesji (50–59, 60–69, 70+). Jedna sesja z 63-letnią użytkowniczką już ujawniła problemy, których automaty nie wychwyciły. To dowód, że ten etap nie może być zastąpiony kolejnym audytem kodu.

**Naprawa:** zamknąć #15 przed szeroką kampanią. Scenariusze: rejestracja → pierwszy wpis ze zdjęciem → znalezienie starego kontaktu/nowej osoby → komentarz → zapis do Zeszytu → powrót następnego dnia.

## Mocne strony

- cele dotykowe projektowane na 48 px;
- body/form 18 px;
- opcja zwiększenia tekstu;
- brak hover/swipe/long-press jako jedynej drogi;
- globalne komunikaty `aria-live`;
- 419 nie kasuje wpisanych danych;
- formularze mają error summary i zachowanie danych;
- 2FA ma paste/autocomplete i kody zapasowe;
- logowanie linkiem e-mail jest wartościową alternatywą dla haseł;
- axe-core sprawdza 27 ekranów × 4 warianty w CI;
- produkt nie nazywa użytkowników „seniorami”.

## Rekomendowana kolejność

1. UX1 przed kolejną falą testów z osobami 60+; UX2 traktować już jako optymalizację, nie blocker.
2. UX6 jako automatyczny test regresji.
3. UX3 przed kampanią „tęsknisz za Garnek.pl?”.
4. UX7 — realne testy jako twarda bramka bety.


## Delta aktualnego `main`

Pełny audyt wykonano na `cee15a56`. W trakcie pracy `main` przesunął się o jeden commit do `e3cf6ab5`; przejrzano jego diff. Zmiana dotyczyła rejestracji/login-link/copy i testów. Poza obniżeniem UX2 z P1 do P2 nie unieważnia pozostałych ustaleń tego raportu.
