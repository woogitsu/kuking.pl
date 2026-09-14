# Przekazanie prac nad marką — 14 września 2026

## Aktualizacja po wznowieniu

**Późniejsze potwierdzenie:** Alfa 0.25 działa na produkcji jako
`b4d5d8e875006ad165660f7f2af8cc5b2b7ca77e`. Railway `6433957176` success
o 09:34:16 UTC, main CI `34826807883` success, Deploy `34828688035` success.
HTTP i zalogowany Chrome pokazały nową wersję; na istniejącym prywatnym
wpisie potwierdzono poprawiony komunikat. Szczegóły i ograniczenia:
[odbiór produkcji 0.25](ODBIOR_PRODUKCJI_ALFA_025.md).

PR #533 dla `32208ff2319fb5379c6d8d81cca9f00c33fbd50c` uzyskał dziesięć
sukcesów CI `34827406995` (PHP 3794/76279). Dalsze uzupełnienie raportu
wdrożenia wymaga kontroli nowego head; nie przenoś na niego tego wyniku.

PR #531 jest scalony jako `b4d5d8e875006ad165660f7f2af8cc5b2b7ca77e` po
dziesięciu sukcesach CI `34822658081`. PR #533 ma teraz bazę main i jest
gotowy do review; jego wymagane kontrole pozostają do potwierdzenia.
Pierwszy commit dokumentacji `7659a4514ba4a9703ddec0ee078e55d5a07b4364`
wysłano zwykłym pushem po pełnym hooku (274,06 s); zdalny SHA potwierdzono.

Produkcja **Alfa 0.24 / b0712cf** została potwierdzona rzeczywistym HTTP
oraz Railway `6433191800` success o 08:44:33 UTC. Deploy `34824265942`
i main CI `34822427307` zakończyły się sukcesem. Alfa 0.25 wymaga jeszcze
oddzielnego potwierdzenia wdrożenia. Dalsza treść opisuje wcześniejsze
przekazanie; w kwestiach stanu ta aktualizacja ma pierwszeństwo.

Użytkownik poprosił o zakończenie sesji, wysyłkę i przekazanie. Kontynuuj
istniejącą pracę, nie zaczynaj całego audytu od początku. Ten dokument
uzupełnia [przekazanie z 13 września](PRZEKAZANIE_MARKI_2026_09_13.md).
Stan odczytany około 08:44 UTC; późniejszy komunikat końcowy ma pierwszeństwo
w sprawie wyniku wysyłki i numeru nowego PR. Zawsze odczytaj aktualny GitHub.

## Najpierw

1. Przeczytaj AGENTS, aktualną konstytucję i dokumenty obszaru. Oryginalny ZIP
   jest zachowany w `docs/design/references/KuKing-styl-wizualizacja-konstytucja.zip`.
2. Sprawdź czystość canonical, aktywne procesy wysyłki i zdalne SHA. Nie
   uruchamiaj drugiego hooka/pusha równolegle. Nigdy nie omijaj hooków.
3. Sprawdź PR #531, CI i rzeczywistą produkcję. Nie uznawaj sukcesu CI za
   dowód przełączenia ruchu przez Railway.
4. Sprawdź nową gałąź dokumentacji #532 i jej PR. Dopiero po wymaganych
   kontrolach wykonuj zwykłe scalenie. Nie ustawiono automatycznego scalania.
5. Zaktualizuj macierz odbioru rzeczywistymi dowodami wdrożenia. Cała marka
   nadal **CZĘŚCIOWO**: nie każdy ekran, stan i klient poczty ma ogląd.

## Repozytorium i uprawnienia

Repo: https://github.com/woogitsu/kuking.pl, produkcja: https://kuking.pl.
Canonical: `C:\Users\matma\Documents\Codex\kuking.pl`.
Aktywna gałąź podczas przekazania: `docs/532-aktualne-zrodlo-stylu`,
utworzona z `24afa9e4046da31143430fd930883908ca9f87a7`.
Końcowy SHA dokumentacji ustal przez git; dokument jest częścią tego commita.

Użytkownik upoważnił do autonomicznych poprawek, instalacji środowiska,
testów, PR-ów i zwykłego scalania po kontrolach oraz wdrożeń. Subagenci są
dozwoleni. Zachowaj dane, prywatność, autoryzację, sesje, CSRF i media.
Nie wysyłaj wiadomości do ludzi ani nie twórz fikcyjnej aktywności produkcji.
Nie ruszaj cudzego PR #456. Nie scalaj ponownie #493. Nie twórz SPA, Sites
ani nowych funkcji/minutnika bez potrzeby. Raportuj po polsku.

## PR #529 — scalony, Alfa 0.24

https://github.com/woogitsu/kuking.pl/pull/529

- Końcowy head: `a28703e3b86535fbffd35474b49287226a86d86d`.
- Merge/main: `b0712cf8df4a3508ec76e9fada31b4f839c99169`.
- CI PR `34820194350`: wszystkie 10 zadań success.
- PHP z logu joba `103899965517`: **3751 testów / 75592 asercje**.
- Main CI `34822427307`: completed/success w ostatnim odczycie.
- Railway deployment `6433191800`: nadal in_progress w ostatnim odczycie.
- Początkowy Deploy `34822433511` skipped; zdarzenie przed sukcesem deployu
  nie jest testem dymnym po wdrożeniu. Alfa 0.24 nie jest jeszcze potwierdzona.

Zakres #524: pełny przepis po rzeczywistych 419/429, odzyskanie 51 kroków,
budżet formularza i ponowienie POST/PUT z kontrolą CSRF, bez utraty tekstu.
Zakres #527: prawdziwe podsumowanie „Sprawdź formularz”, bez zgadywania
przyczyny błędu. Lokalne 93/671, 13 prawdziwych negatywów źródła; odbiór
przeglądarkowy opisany w raportach. Nie wykonuj tych prac ponownie.

Pierwsze CI #529 miało rzeczywisty błąd Larastan przy limicie 1 GB.
Odtworzono eksplozję typów kształtu tablicy w maksymalnym fixture testowym.
Naprawa to lokalna adnotacja `array<string, mixed>` w pętli testu
`BudzetOdzyskiwaniaTest`. Pełny PHPStan 1 GB przeszedł. Nie zwiększono limitu,
nie zmieniono runtime ani asercji. Późniejsze 10 zadań jest zielone.

## PR #531 — Alfa 0.25, jeszcze niescalony

https://github.com/woogitsu/kuking.pl/pull/531

- Gałąź `fix/526-528-zapis-przepisow`.
- Head: `24afa9e4046da31143430fd930883908ca9f87a7`.
- Ready/open; zwykły push przeszedł pełny obowiązkowy hook (268,79 s).
- CI `34822658081` nadal trwało. PHP job `103908931994` success:
  **3793 testy / 76259 asercji** z rzeczywistego logu.
- Pint, Vite, wyścigi i audyt zależności również success. Obraz, dostępność,
  Larastan i port miały w ostatnim odczycie stan trwający/oczekujący.
- Preview `34822658084` skipped. Nie scalać przed końcem wymaganych kontroli.

Zmiany:

- #526: słownik składników przyjmował 160 znaków, formularz 240.
  Migracja `2026_09_14_100000_dopasuj_slownik_skladnikow_do_formularza.php`
  rozszerza nazwę do varchar(240), normalizację do text (Æ może dać ae).
  Zachowuje indeksy. Rollback pod blokadą odmawia zwężenia długich danych,
  nie obcina ich. Testy obejmują 160/161/240, transliterację i deduplikację.
- #528: kreator waliduje surowe pola przed zapisem, nie dopiero po obcięciu.
  Niepoprawny tekst zostaje w UI, poprzedni dobry zapis w bazie. Korekta
  odblokowuje zapis; Wstecz/Dalej wraca do błędnego pola. Bez zmian mediów.
- #530: komunikaty publikacji i udostępniania rozróżniają private/followers/
  public. Prywatny zapis nie obiecuje, że inni go ugotują. Instrukcja wskazuje
  rzeczywisty przycisk „Dopisz szczegóły”. Gate i polityki pozostają.
- COPY_STYLE zawiera prawdziwe warianty i granice autozapisu. Konstytucja
  pozostaje 1.9, ostatnia decyzja D-213; nie dopisano nowego kierunku marki.

Weryfikacja lokalna po integracji: **104 testy / 1194 asercji**, pełny
PHPStan 1 GB, Pint, dokumentacja 3/41, poradnik 27/1301. **16 rzeczywistych
negatywów** migracji/Blade/kontrolera/udostępniania, fizyczne kopie poza
repo, MD5 i mtime przywrócone. Raporty `SLOWNIK_SKLADNIKOW_526.md`,
`AUTOZAPIS_KREATORA_528.md`, `KOMUNIKATY_WIDOCZNOSCI_530.md` zawierają zakresy.

Przeglądarka lokalna: rzeczywiste utworzenie/edycja 240-znakowego składnika,
błąd tytułu 181, zachowanie poprzedniego zapisu i korekta. 12 konfiguracji
320–1440, oba motywy, tekst 140%; cztery prawdziwe zoomy 200%. Prywatny
komunikat oglądany 320/1440 w obu motywach, inne widoczności przez HTTP/
Livewire i macierz dostępu. Nie wysyłano testowych danych na produkcję.

## #532 — sprostowanie indeksów i dodatkowe raporty

https://github.com/woogitsu/kuking.pl/issues/532

Nowa luka, której #516 nie obejmował: `docs/design/README.md` nadal nazywał
kit-v2 obowiązującym wyglądem, a `system-v3.1/CZYTAJ-NAJPIERW.md` nadawał
historycznym uploads nadrzędność. Oba indeksy teraz najpierw wskazują
aktualne zasady, konstytucję, decyzje, źródłowy ZIP i macierz. Stare materiały
są opisane historycznie. Oryginalnych kitów, uploads i ZIP nie zmieniono.
Nie ma zmiany interfejsu ani podbicia wersji aplikacji w tym pakiecie.

Regresja w istniejącym `InstrukcjeChroniaSrodowiskoTest`. Niezależny review
wykrył, że pierwotny test nie sprawdzał kolejności sekcji; root dodał jawne
pozycje obu nagłówków. Końcowy wynik: **5 testów / 55 asercji PASS**,
Pint PASS, wszystkie pięć prawdziwych negatywów wykrytych. Źródła przywrócono
z potwierdzeniem MD5 i dokładnego mtime. Szczegóły opisuje
[raport #532](ZRODLO_STYLU_532.md). Wynik pełnego hooka wysyłki sprawdź osobno.

Dodano też [odbiór gotowania](ODBIOR_TRYBU_GOTOWANIA_2026_09_14.md):
48 konfiguracji, pełne 4000 znaków, realne Next/Previous, Tab/Enter,
odhaczenie przeżywające reload i powrót. Bez nowego potwierdzonego błędu.
Prawdziwy zoom API, nie font; raport jawnie rozróżnia CSS i fizyczną
szerokość. Bez fizycznego telefonu, Wake Lock, minutnika czy screenreadera.

[Pomiar fokusu szczegółów](ODBIOR_FOKUSU_SZCZEGOLY_530.md): cztery przebiegi
po 25 elementów, true zoom 200%, oba motywy, tekst 100/140. Brak całkowicie
zasłoniętego fokusu. Przy 140 pozostaje 101,5 px między nawigacjami;
etykieta zdjęcia 315,648 px wymaga przewinięcia do napisu. Nie usuwaj
globalnego fokusu ani stałej nawigacji jako pozornej naprawy. Walidacji
w tym odczycie nie wywoływano. #518: użytkownik już nie widzi ramki od 0.19.

## Ostatnia rzeczywiście potwierdzona produkcja

**Alfa 0.23**, SHA `6db1b8978f8d7e2229a77e8dd4701ffd510a1b6a`.
Railway `6432286380` success o 07:43:37 UTC; main CI `34817233565` success.
Rzeczywisty HTTP i zalogowany Chrome pokazały stopkę 0.23 / `6db1b89`.
CSS `app-BZTD7N58.css`, JS `app-DXNAnudp.js`, oba lokalne Inter HTTP 200.
Oglądano zalogowane `/home`, `/szukaj`, `/powiadomienia`, bez klikania
zapisów/obserwowania/czytania powiadomień. Nie publikuj prywatnych danych
ze zrzutów do repo. To reprezentatywny desktop, nie cała macierz produkcji.

Stare czerwone wyścigi #928 są już zdiagnozowane w `WYSCIGI_CI_928.md`:
adnotacja potwierdza, że job nie wystartował po pięciu nieudanych próbach
przydzielenia. Sam 404 nie był diagnozą. Głębsza przyczyna przydziału
nieustalona, powtórzenie i późniejsze pomiary przeszły; D-105 bez zmian.

## Środowisko, procesy i helpery

- Ubuntu WSL, kopia wykonawcza `/tmp/kuking-final-20260913`.
- PHP `/opt/kuking-php-8.4-avif/bin/php` 8.4.24; Node 20.20.2.
- Chromium `/home/mateusz/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome`.
- PostgreSQL 18, izolowany port **55439**, user kuking. **Nie używaj 5432**.
- Pełne PHP: baza `kuking_final_20260913`, APP_URL `http://localhost`, UTC.
- Przeglądarka: `kuking_audit429`, serwer 8029. Nie uruchamiaj pełnego PHP
  równolegle z odbiorem wspólnych mediów. Konteksty agentów zamknięte.
- Prywatny stan sesji `/tmp/kuking524-local-browser-state.json` — nie drukuj.
  Lokalne fixture pozostawiono tylko do odbioru; żadnych produkcyjnych zapisów.
- Nie restartuj WSL, runnerów, Dockera ani współdzielonych usług. Inne projekty
  działają. Nie kopiuj nigdy `.git` z native z powrotem do canonical.
- Windows worktree mają absolutne wskaźniki `.git`; nie przepisuj ich dla WSL.
  Worktree ingredient526, autosave528 i visibility530 są zachowane z dawną
  pracą agentów. Zintegrowane rozwiązania są już na gałęzi PR #531.
- Stary stash po integracji #526 został zastosowany, ale pozostał jako kopia.
  **Nie stosuj go ponownie** — nie jest bieżącą pracą do odzyskania.

Lokalne helpery w ignorowanym `output/`:

- `pr515-status.py NUMER SHA`: stan PR i jego CI.
- `merge515.py NUMER SHA RUN`: zwykłe scalenie z kontrolą head, clean,
  wszystkich 10 success i braku changes-requested; odczyt po błędzie.
- `ready515.py NUMER SHA`: ready z odczytem stanu.
- `deploy529-status.py`, `current-ci-detail.py`, `production018-http.py`:
  aktualny deploy, kroki CI, rzeczywista stopka i assety. Nazwa 018 historyczna.
- `php531-ci.log`: pełny log 3793/76259. Wczesne celowe porażki w tym logu
  należą do negatywów CI, nie do końcowego zestawu.
- `prepare532.py`, `push532.py`: metadane canonical → native i normalny
  push gałęzi dokumentacji z pełnym hookiem; log `/tmp/kuking-push532.log`.
- `sync-final.py` używa listy `output/source-files.txt`: odśwież ją przez
  `git ls-files --cached --others --exclude-standard` przed synchronizacją.
  Kopia nie usuwa automatycznie plików znikających ze źródła; kontroluj to.
- `github_api.py` bierze uprawnienie z GCM bez drukowania sekretu.
- `output/playwright/cooking-audit/` i `output/focus530-*.json`: surowe
  lokalne dowody. Nie są wersjonowane; raporty w docs są trwałym zapisem.

Po błędzie API zawsze odczytaj stan przed ponowieniem. Nie duplikuj PR-ów.
Odróżniaj zapisany kod, wykonany test, scalenie i działającą produkcję.
