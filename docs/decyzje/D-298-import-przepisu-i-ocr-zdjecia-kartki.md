## D-298 — Import przepisu i OCR zdjęcia kartki: architektura (26 września 2026)

**Data:** 26 września 2026 · **Decyzja właściciela** (model, klucz, zakres) +
rozstrzygnięcia wykonawcze opisane niżej · Status: **obowiązuje** ·
Dotyczy: D-282 (V2 wolno budować), D-296 (zgoda „odczyt AI”), D-297 (budżet),
projekt `docs/research/V2_IMPORT_OCR_ODZYWCZE.md`, issue #28

### Decyzje właściciela z 26 września 2026

- Model OpenAI **`gpt-6-luna`** — domyślna wartość `KUKING_IMPORT_MODEL`,
  zmienialna w env. Osobny klucz **`OPENAI_IMPORT_KEY`**. **Brak klucza =
  funkcja wyłączona, przycisk się nie pokazuje.**
- **Intensywność myślenia (`reasoning.effort`) w konfiguracji, osobno per
  zadanie:** odczyt zdjęcia zeszytu = `medium` (`KUKING_IMPORT_EFFORT_OCR`),
  wyznaczanie fragmentów przepisu z URL/PDF = `low`
  (`KUKING_IMPORT_EFFORT_TEKST`). Dozwolone wartości są w kodzie
  (`KlientLuna::WYSILKI`: `minimal`, `low`, `medium`, `high`); wartość spoza
  listy wyłącza TO JEDNO zadanie (żadnego żądania) i mówi o tym
  `kuking:sprawdz-import`. Klient wysyła `reasoning.effort` dokładnie
  z konfiguracji (test). Import z URL/PDF (osobna gałąź) używa klucza
  `kuking.import.model.wysilek.tekst` i zadania `KlientLuna::ZADANIE_TEKST`.
- Szkic z odczytu jest **zawsze `draft` i `private`, nigdy auto-publikacja**.
- Przed publikacją szkicu z OCR: pole **„Sprawdziłem odczytany tekst”**,
  a **niepewne słowa `[?…?]` blokują publikację**. Na ekranie pole brzmi
  „Odczytany tekst jest sprawdzony ze zdjęciem” — forma „Sprawdziłem”
  przypisuje czytelnikowi płeć, czego zabrania `docs/brand/COPY_STYLE.md` §2
  (pilnuje `TekstyNiePrzypisujaPlciTest`); znaczenie i działanie bez zmian.
- Na start funkcja dla **wszystkich zalogowanych**.

### Architektura

- **Import = zadanie w kolejce, które wypełnia prywatny SZKIC w istniejącym
  kreatorze.** Szkic (`recipes`, `draft`, `private`) i zdjęcie kartki
  (`recipes.source_scan_media_id`, zwykły potok zdjęć — EXIF zdjęty przed
  czymkolwiek) powstają RAZEM ze zleceniem, zanim cokolwiek pójdzie do
  modelu. Dlatego „zdjęcie jest zapisane” jest prawdą przy każdym błędzie,
  limicie i wyłączonej funkcji, a zdjęcie ma od razu wszystkie ochrony skanu
  kartki (eksport, kasowanie z kontem, `DostepDoZdjecia`). Jedno zdjęcie na
  zlecenie w tym etapie (`source_scan_media_id` jest jedno).
- Publikacja idzie **zwykłym `PublishRecipe`** i zwykłą moderacją.
  `app/Domain/Import/**` nie odwołuje się do `PublishRecipe` z `publish: true`
  (test architektoniczny). Bramkę „Sprawdziłem / `[?`” trzyma
  `BramkaPublikacjiOdczytu` wołana z `PublishRecipe`, więc obejmuje kreator
  i formularz bez JavaScriptu. **`PublishRecipe` (moduł `Recipes`) nie
  importuje `Import` wprost** — woła kontrakt `App\Domain\Recipes\
  BramkaPublikacjiSzkicu`, którego implementację (`BramkaPublikacjiOdczytu`)
  wiąże `AppServiceProvider` (wzorem `ObserwowanieGospodarza`, issue #971).
  Bezpośredni import zamykał cykl `Import → Recipes → Import`, bo `Import`
  i tak zależy od `Recipes` przez `PublishRecipe` (`ZlecImportPrzepisu`,
  `OdczytajPrzepis`) — pilnuje tego `GrafModulowDomenyBezCykliTest`.
- **Klient `App\Domain\Import\KlientLuna`** — Responses API
  (`POST https://api.openai.com/v1/responses`), host i ścieżka w kodzie
  (`#^/v1/responses$#`, D-250), `store: false`, bez narzędzi, wyjście
  w schemacie JSON (`strict`). Nie wysyła e-maila, nazwy, IP, identyfikatorów
  ani pola `user`/`safety_identifier`. Trzy wyniki jak w moderacji (#1662).
- **Obraz do modelu:** wariant `large` (≤ 1600 px) przekodowany przez GD do
  JPEG, dłuższy bok ≤ 2000 px zmierzony z bajtów przed i po — bez EXIF/XMP/GPS.
- **Kolejka `low`, nie osobna `import`.** Produkcja chodzi w roli `all`
  (jeden proces na wszystkie kolejki), więc osobna kolejka trafiłaby do tego
  samego procesu, a kosztowałaby zmianę `docker/entrypoint.sh`, IaC i
  `UmowaKolejkiTest` oraz dodatkową pamięć po wydzieleniu workera. Po
  wydzieleniu odczyt (do 90 s) stoi na `low` za moderacją, a nie przed
  zdjęciami (`media`) i listami (`high`/`default`). Gdy pomiar pokaże, że
  odczyty opóźniają moderację — wtedy osobna kolejka, nową decyzją.
- **Tabela `importy_przepisow`** (ślad zlecenia, bez treści przepisu):
  surowa odpowiedź modelu 30 dni, wiersz 90 dni (`kuking:sprzataj-importy`,
  06:00). Wyjątek: wiersz szkicu, który nadal jest szkicem, zostaje jako
  bramka publikacji. Eksport RODO: sekcja `odczyty_przepisow`; anonimizacja
  konta kasuje wiersze.
- **Brak powiadomienia w serwisie** w tym etapie: ekran postępu
  `/import/{import}` (słowa, `aria-live`, działa bez JS) i lista „Moje szkice”.
- Komenda **`kuking:sprawdz-import`** mówi, czego brakuje (klucz, model,
  adres, intensywność, cennik, budżet) — bez wysyłania żądań.

### Maszyna stanów płatnego wywołania (#1973, #1974, #1977, #1980)

Audyt gałęzi pokazał cztery okna, w których awaria między dwoma zapisami
psuła budżet albo zlecenie (zmierzone testami przed poprawką:
`MaszynaStanowOdczytuTest`): rezerwacja zatwierdzona bez śladu w zleceniu
(76 000 mikro-USD zablokowane na zawsze), rozliczenie policzone dwa razy
(82 000 zamiast 76 000), zlecenie `oczekuje` bez zadania w kolejce (także po
ponowieniu tym samym kluczem) i ponowienie zadania wysyłające DRUGIE płatne
żądanie, choć odpowiedź pierwszego była zapisana. Jedna maszyna stanów
zamiast czterech łatek:

- **Księga `ai_rezerwacje`**, klucz `UNIQUE (import_id, proba)`, stany
  `zarezerwowana → wyslana → rozliczona` albo `→ zwolniona`. Każde przejście
  to warunkowy `UPDATE … WHERE stan IN (otwarte)`, więc powtórzone
  rozliczenie niczego nie zmienia (#1974). Wiersz księgi powstaje w tej
  samej transakcji co zwiększenie `zarezerwowano_mikrousd`, a zadanie
  owija rezerwację i zwiększenie `importy_przepisow.proby` w jedną
  transakcję (#1973). Kolumny `rezerwacja_mikrousd`/`rezerwacja_dzien`
  zniknęły ze zlecenia (migracja gałęzi poprawiona w miejscu, przed
  scaleniem).
- **`wyslana` zapisywane PRZED żądaniem.** Dzięki temu rezerwacja porzucona
  ma jednoznaczny los: niewysłana wraca do budżetu, wysłana idzie w wydatki
  całą kwotą (D-297: lepiej zawyżyć). Domykają ją `failed()`, początek
  następnej próby i **`kuking:odzyskaj-importy`** (co kwadrans; rezerwacja
  otwarta ponad 30 minut — zadanie żyje najwyżej 120 s).
- **Rozliczenie budżetu i zapis kosztu, tokenów i odpowiedzi w zleceniu —
  jedna transakcja** (`RozliczenieOdczytu`). Koszt dopisywany w SQL
  (`COALESCE(koszt_mikrousd, 0) + ?`), bo porzuconą rezerwację mógł domknąć
  kto inny.
- **Zapisana odpowiedź = etap „odczytano” zamknięty** (#1980). Ponowienie
  zadania z `odpowiedz_modelu` odtwarza wynik (`OdpowiedzModelu::zZapisanej`)
  i dokańcza BEZ rezerwacji i bez żądania. Zgody nie sprawdza drugi raz:
  nic już nie wychodzi. Granica: `output_text` jest w zapisie ucięty do
  20 000 znaków — dłuższy (niespotykany dla kartki) po wznowieniu kończy się
  `odpowiedz_bledna`, nigdy drugim wywołaniem.
- **Sufit płatnych żądań na zlecenie: `PROBY_MODELU` (3)** — liczony
  z `proby`, więc obejmuje także ponowienia po zwykłym wyjątku, które
  kolejka robi do `$tries` (8, bo obejmuje też czekanie na zdjęcie).
- **Szkic i `gotowy` w jednej transakcji** — inaczej ponowienie po awarii
  tuż za szkicem brało tekst modelu za pracę człowieka (`szkic_zmieniony`).
- **Zlecenie i zadanie razem albo wcale** (#1977): `OdczytajPrzepis::dispatch()`
  wewnątrz transakcji zapisu zlecenia — kolejka bazodanowa na tym samym
  połączeniu, bez `after_commit`, ten sam outbox co `ZamowEksportDanych`
  (A02) i `StoreUploadedImage` (#1456). `afterCommit()` odrzucone: przenosi
  zapis zadania za commit, czyli zostawia to samo okno (kontrola ujemna
  w `scripts/kontrole-negatywne-alfa08.py` robi dokładnie tę zmianę).
  Zadanie zgubione inną drogą: zlecenie `oczekuje`/`w_toku` bez zmiany od
  120 minut dostaje `nieudany`/`blad_wewnetrzny` i „Spróbuj jeszcze raz”.
  **Nie wysyłamy zadania ponownie automatycznie** — gdyby „zgubione” zadanie
  jednak żyło, dwa zadania to dwa płatne żądania; ponowienie należy do
  człowieka i liczy się do jego limitu.

### Czego ta decyzja NIE robi

Nie włącza funkcji na produkcji: wymaga klucza, cennika, umowy powierzenia
(DPA) z OpenAI i nowej wersji polityki prywatności (projekt tekstu:
`docs/legal/projekty/POLITYKA_ODCZYT_AI.md`). Nie buduje importu z URL/PDF
ani wartości odżywczych (osobne etapy i gałęzie).

### Wycofanie

Najszybciej: usunąć `OPENAI_IMPORT_KEY` — przyciski znikają, zlecenia
w kolejce kończą się `wylaczony`, szkice ze zdjęciami zostają. Cofnięcie kodu:
odwrócić commity; migracje `importy_przepisow` cofa się bez odmowy,
`ai_budzet_dzienny` (razem z księgą `ai_rezerwacje`) odmawia przy wydatkach
w bieżącym miesiącu (D-088).
