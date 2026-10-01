## D-332 · Forma zwracania się zamiast „nie pytamy o płeć” (#1751, 25 września 2026, potwierdzona 29 września 2026)

**Data:** 25 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

> **Potwierdzenie i zakres wdrożenia — decyzja właściciela z 29 września 2026
> (#1751).** Wdrażamy ustawienie „Jak mamy do Ciebie pisać?” (żeńska / męska /
> neutralna, **neutralna domyślna**) **bez czekania na prawnika**. Prawnik (#8)
> ocenia już tylko jedno zdanie w polityce prywatności (czy przy formie
> widocznej dla innych wystarcza wykonanie umowy, czy potrzebna jest zgoda).
> To odblokowuje #1752 (ustawienie w profilu i w onboardingu) oraz #1753
> (helper tekstów według formy z obowiązkowym wariantem neutralnym). Pierwszy
> etap wdrożenia obejmuje kilka kluczowych tekstów wskazanych w issue, nie cały
> serwis.

> **Zmiana polityki jest ISTOTNA — decyzja właściciela z 29 września 2026
> (D-327).** Nowa wersja polityki (publikacja 2026-09-30, poprzednia
> 2026-09-29) obowiązuje od 14 października 2026, a zalogowani do tego dnia
> widzą pasek „Zmieniliśmy politykę prywatności” (`ZmianaPolityki`). Do dnia
> wejścia w życie **wybór formy jest ukryty** (`Forma::wyborDostepny()`, liczone
> z `WersjaDokumentu::polityka()`): stara polityka o tej danej nie mówi, więc
> jej nie zbieramy; wszyscy dostają wariant neutralny, a pytanie pojawia się
> samo po tej dacie, bez kolejnego wdrożenia.
>
> **Dlaczego nie 2026-09-29 → 13.10 (recenzja 29.09.2026).** Wersja
> 2026-09-29 (#1816, #1324, #619) wyszła już w Alfa 0.76 jako DROBNA
> i CHANGELOG ogłosił ją jako obowiązującą; dziennik zgód na produkcji od
> tego dnia zapisuje `2026-09-29`. Przeklasyfikowanie jej na istotną
> z poprzednią 2026-09-25 cofnęłoby już obowiązujący tekst i kazałoby
> dziennikowi zgód zapisywać starszą wersję niż wczoraj. Dlatego forma
> dostaje NOWĄ wersję (2026-09-30), a poprzednią jest 2026-09-29. Data
> wersji to dzień publikacji na produkcji — jeśli wdrożenie wypadnie
> później, trzeba ją podbić, żeby 14 dni liczyło się od prawdziwej
> publikacji.
>
> **Zmiana polityki jest jednak DROBNA — decyzja właściciela z 29 września
> 2026 (wieczór), zastępuje akapit wyżej.** Serwis nie ma jeszcze prawdziwych
> użytkowników ani kont, więc nikt nie zna poprzedniej wersji i nie ma kogo
> uprzedzać. Wersja 2026-09-30 (poprzednia 2026-09-29 zostaje nietknięta)
> obowiązuje od dnia publikacji: bez okresu przejściowego 14 dni, bez paska
> dla kont, a ustawienie „Jak mamy do Ciebie pisać?” jest widoczne od razu po
> wdrożeniu (`Forma::wyborDostepny()` przy zmianie drobnej zwraca `true`).
> Konfiguracja: `kuking.zgody.zmiana_polityki.istotna` = `false`.
> `ZmianaPolityki::pokazac()` pokazuje pasek polityki tylko przy zmianie
> istotnej (polityka §9 obiecuje powiadomienie właśnie przy niej). Mechanizm
> paska (`ZmianaPolityki`, `ZmianaPolitykiController`, trasa
> `privacy.notice.dismiss`, kolumna `users.policy_notice_dismissed_version`,
> migracja `2026_09_29_180000`) zostaje w kodzie na przyszłe zmiany istotne;
> `ZmianaPolitykiTest` i `FormaZwracaniaSieTest` sprawdzają oba przypadki.
> Akapit o zmianie istotnej wyżej zostaje jako historia decyzji.

Research: `docs/research/PROFIL_FORMA_I_URODZINY.md` (gałąź
`claude/research-profil-forma-urodziny`), pytania P1, P2, P3 i P7.

### Decyzja

1. **Pytamy „Jak mamy do Ciebie pisać?”** — trzy równorzędne odpowiedzi:
   forma żeńska, forma męska, forma neutralna. **Neutralna jest domyślna**
   i jest pełnoprawną odpowiedzią, nie brakiem odpowiedzi. To **preferencja
   językowa, nie płeć**: nie pytamy o płeć, nie nazywamy pola „płeć” i nie
   wyciągamy z niego wniosków o człowieku.
2. **Nie zgadujemy.** Formy nie wywodzimy z imienia, z nazwy konta, z adresu
   e-mail ani z danych Google/Facebooka (zakres Facebooka zostaje
   `public_profile,email`, bez `user_gender`). Formę ustawia wyłącznie
   sam człowiek. Kto nic nie wybrał, dostaje dokładnie dzisiejsze teksty
   bez rodzaju.
3. **Forma działa w obu kierunkach:** w tekstach **do** osoby („Co dziś
   ugotowałaś?”) **i** w tym, jak **inni czytają o niej** („Ania ugotowała
   Twój rosół”, karty wpisów, powiadomienia). Dlatego jest **daną widoczną
   dla innych** — tak samo jak nazwa wyświetlana — i ekran wyboru mówi to
   wprost, zanim ktoś wybierze.
4. **Przycisk przy formie żeńskiej brzmi „Ugotowałam”.** Nazwą funkcji
   w dokumentacji, w marce, w pomocy i na liczniku cudzego profilu zostaje
   **„Ugotowałem”** (reguła „jedna nazwa funkcji”, `BRAND_EXTENDED.md` §3).
   Zmienia się wyłącznie napis na przycisku i w formularzu wykonania
   u osoby, która wybrała formę żeńską.
5. **Pytamy w dwóch miejscach:** w ustawieniach profilu i w onboardingu —
   tam jako krok **pomijalny**, z domyślnie zaznaczoną formą neutralną, bez
   przypominania i bez „Uzupełnij profil!”.

### Co z tego wynika dla tekstów

- Granica z `COPY_STYLE.md` §2 dostaje trzecią część: **do czytelnika bez
  wybranej formy — bez rodzaju** (jak dotąd); **do osoby i o osobie, która
  wybrała formę — w jej formie, wyłącznie przez jeden helper**
  (`App\Support\Forma`) z **obowiązkowym** wariantem neutralnym. Goły tekst
  z rodzajem w widoku nadal jest usterką.
- Zakaz ukośników („ugotowałaś/eś”) i wypisywania obu form obok siebie
  zostaje bez zmian.
- Przed zalogowaniem (landing, rejestracja, `<title>`, maile przed założeniem
  konta) czytelnika nie znamy — tam zostaje hasło „ugotowałeś” jak dotąd.
- „Nie tworzymy formy żeńskiej” z D-009/D-145 i `GLOS_MARKI.md` dotyczy
  **rzeczownika `kuKING`** (kuKINGini, kuKINGówka) i obowiązuje dalej.
  Formy czasownika to inna sprawa i tej decyzji rzeczownik nie dotyczy.

### Dane i prywatność

- Kolumna z zamkniętą listą wartości (CHECK), `NULL` = forma neutralna.
  To zwykła preferencja, nie pole sterujące — nie podlega D-006.
- Podstawa: **wykonanie umowy** (art. 6 ust. 1 lit. b RODO) — funkcja,
  z której człowiek świadomie korzysta, tak jak nazwa wyświetlana i opis
  w publicznym profilu. Pole jest dobrowolne, a domyślna odpowiedź nie
  ujawnia niczego. **Do potwierdzenia u prawnika (#8):** czy przy formie
  widocznej dla innych wystarcza umowa, czy potrzebna jest zgoda
  (art. 6 ust. 1 lit. a). Do tej odpowiedzi obowiązuje lit. b (decyzja
  właściciela z 25.09.2026).
- Nie używamy formy w statystykach, w analityce ani do segmentacji
  (podsumowanie tygodnia, tablica, wyszukiwarka).
- Eksport RODO zawiera wybraną formę; anonimizacja konta (art. 17) ją zeruje.
- Polityka prywatności dostaje wiersz o tej danej (widoczna dla innych,
  podstawa, eksport, usunięcie) **razem z kodem, który ją zapisuje**
  (#1752) — dokument opisuje stan serwisu, nie plan.

### Co ta decyzja zmienia w innych dokumentach

- `docs/brand/COPY_STYLE.md` §2, §6 (Ugotowałem), §7, §8;
- `docs/brand/BRAND_EXTENDED.md` §2.4;
- `docs/brand/GLOS_MARKI.md` (zakres „formy żeńskiej nie tworzymy”);
- `docs/SECURITY_PRIVACY_LEGAL.md` „Data minimization”;
- D-207 — dopisek: nie **zgadujemy** płci; forma wybrana przez człowieka
  jest dozwolona.

`tests/Feature/TekstyNiePrzypisujaPlciTest.php` zmienia się razem
z helperem (#1753): rodzaj w widoku przechodzi wyłącznie wewnątrz wywołania
helpera z trzema wariantami, a przy formie neutralnej wyrenderowane ekrany
nadal przechodzą dzisiejszy skan.

### Czego ta decyzja NIE obejmuje

- Urodzin, imienin i rocznicy dołączenia — to osobne pytania P4–P6, P8
  z researchu.
- Formy w materiałach marketingowych i w mailach wysyłanych przed
  założeniem konta.
- Wariantów spoza trzech wymienionych.

**Zmiana wymaga:** sygnału z testów z ludźmi (#15), że pytanie odstrasza
albo myli (np. konta współdzielone przez małżeństwo), albo opinii prawnika,
że widoczność formy dla innych wymaga zgody zamiast wykonania umowy.

### Wycofanie

Helper zwraca wariant neutralny dla każdego (jedna zmiana), ekran wyboru
znika z ustawień i onboardingu, kolumna zostaje do decyzji o danych —
jej usunięcie przechodzi przez migrację, której rollback odmawia przy
zapisanych wyborach (D-088).

📄 `docs/brand/COPY_STYLE.md` · `docs/brand/BRAND_EXTENDED.md` · `docs/brand/GLOS_MARKI.md` ·
`docs/SECURITY_PRIVACY_LEGAL.md` · D-207 · D-088 ·
`database/migrations/2026_09_25_140000_add_form_of_address_to_profiles.php` ·
`app/Http/Controllers/Settings/FormOfAddressController.php` · `FormaZwracaniaSieTest` ·
`app/Support/Forma.php` · `FormaTekstyTest` · `TekstyNiePrzypisujaPlciTest`
