## D-024 · Dokumenty prawne idą na produkcję poprawione, a nieprawda z nich wypada od razu

**Data:** 7 września 2026 · Status: **obowiązuje** · weryfikacja W1–W5 · issue #8

Właściciel dostarczył trzy kompletne szkice (polityka prywatności,
regulamin, zasady) przygotowane do przeglądu przez prawnika. Pięć
przebiegów weryfikacyjnych sprawdziło każde twierdzenie o systemie
przeciwko kodowi.

### Rzeczy, które obecna, PUBLICZNIE SERWOWANA treść twierdzi nieprawdziwie

`GET https://kuking.pl/prywatnosc` → HTTP 200 (zmierzone). Czyli poniższe
zdania są dziś obowiązującą obietnicą, nie wersją roboczą:

1. **Sentry i PostHog w tabeli podprocesorów, z lokalizacjami.** Żadnego
   z nich nie ma w kodzie: brak `config/sentry.php`, brak pakietu
   w `composer.json`, brak integracji; PostHog to dwie puste zmienne
   w `.env.example`. Dokument wymienia podmioty, które nie przetwarzają
   niczego — to wprowadza w błąd co do tego, kto ma dane użytkownika.
2. **„Każdy z tych dostawców ma podpisaną z nami umowę powierzenia."**
   Właściciel potwierdził: **żadna nie jest podpisana.**
3. **„Nie zbieramy lokalizacji GPS"** — patrz D-023.
4. **„hasło przechowywane w postaci zaszyfrowanej"** — jest bcrypt o koszcie
   12 (zmierzone: `$2y$12$`), czyli nieodwracalny skrót, nie szyfrowanie.
5. **Notatki redakcyjne w treści widocznej dla użytkownika**: „[Wariant A —
   jeśli wdrożony baner:] … [Wariant B …]".
6. **Opublikowane placeholdery** w zdaniach o retencji: „[X dni — do
   ustalenia]".

### Co wybrano

**Poprawiona treść wchodzi teraz; usunięcie nieprawdy nie czeka na
prawnika.**

Rozróżnienie, na którym stoi ta decyzja: **wykreślenie zdania
nieprawdziwego nie jest decyzją prawną.** Nie wymaga niczyjej opinii — kod
mówi, że jest fałszywe. Czekanie z tym na przegląd oznaczałoby świadome
utrzymywanie fałszu przez czas, którego nie kontrolujemy.

Osobno i inaczej traktujemy zdania, które są PROPOZYCJĄ, nie stanem: okresy
retencji. Tu obowiązuje zasada autora szkicu, przyjęta bez zmian:
**proponowanego okresu nie wolno opublikować, dopóki automatyczne zadanie
go nie wykonuje.** Zmierzone: kod egzekwuje dokładnie dwa okresy —
`product_signals` 90 dni i paczki eksportu 7 dni. `audit_log`,
`notifications`, `reports`, `appeals` i `moderation_actions` nie mają
retencji żadnej, więc żadna liczba przy nich nie może się pojawić.

### Co zostaje jawną luką, bo należy do właściciela albo prawnika

- **Umowy powierzenia z Railway i Cloudflare — do zawarcia przed betą.**
  To warunek zgodności, nie formalność: bez DPA powierzenie danych
  procesorowi nie ma podstawy.
- **Dostawca poczty nie jest wybrany.** A maile weryfikacyjne i resetu hasła
  są dziś czymś wysyłane — więc jakiś podmiot przetwarza adresy e-mail
  wszystkich kont i nie wiemy który. `docs/decyzje/POCZTA.md` rekomenduje
  EmailLabs, ale decyzji nie ma w tym pliku.
- **Jurysdykcja bucketów R2.** Z kodu nieudowadnialna, a poszlaka jest
  NEGATYWNA: udokumentowany endpoint nie zawiera `.eu.`, a bucket
  z ograniczeniem jurysdykcyjnym UE jest osiągalny tylko pod
  `<ACCOUNT_ID>.eu.r2.cloudflarestorage.com`. Pogrubione zdanie „Dane
  przechowujemy na serwerach w Unii Europejskiej" wymaga potwierdzenia
  w panelu, zanim zostanie utrzymane.
- **Minimalny wiek: 16 lat** — to NIE jest luka, odpowiedź jest w kodzie
  (`config/kuking.php:228`) i w obu opublikowanych dokumentach. Otwarte
  zostaje węższe pytanie do prawnika: czy 16 lat wystarcza wobec
  ograniczonej zdolności do czynności prawnych osób 13–17.

### Czego nie wolno wpisać, bo kod nie zna celu

`media.checksum_sha256` jest zapisywany i **nigdy nieczytany** (indeks
`media_checksum_idx` nie obsługuje żadnego zapytania).
`media.perceptual_hash` **nie jest nawet zapisywany** przez kod produkcyjny
— zmierzone `count(perceptual_hash) = 0`. Kolumna zapisywana i nieczytana
nie ma celu przetwarzania, a wpisanie do polityki, że służy „moderacji"
albo „wykrywaniu duplikatów", byłoby wymyśleniem podstawy prawnej pod
funkcję, której nie ma.

📄 `resources/legal/*.md` · `docs/legal/BRAMKA_BETY.md` · issue #8
