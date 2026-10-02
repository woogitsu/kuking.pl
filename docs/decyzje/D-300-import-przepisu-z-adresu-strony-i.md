## D-300 — Import przepisu z adresu strony i z PDF: granice prawne i techniczne (V2, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela ·
Dotyczy projektu `docs/research/V2_IMPORT_OCR_ODZYWCZE.md` (PR #1854) §2.4, §4,
etapów 4–5, pytań P-3, P-7, P-10, P-12 i issue #28

**Problem.** Import z adresu strony to najprostsza droga do zamiany Kuking
w agregator cudzych treści (AGENTS.md §9, §12: „masowy import cudzych
przepisów" jest anty-wzorcem na zawsze) i zarazem furtka SSRF — serwer
pobiera adres, który wkleił człowiek. Projekt proponował trzymać import URL
za wyłącznikiem do opinii prawnika (P-3).

**Decyzja właściciela (26.09.2026).** Import z adresu **od razu**, bez czekania
na prawnika, dla wszystkich zalogowanych (P-7), w tych granicach:

1. **Wynik to wyłącznie prywatny szkic** (`status = draft`, `visibility =
   private`). Import nie ma drogi do publikacji — publikuje człowiek zwykłym
   „Opublikuj".
2. **Źródło obowiązkowe i zapisane:** `source_type = external`, `source_url`
   = adres po przekierowaniach bez parametrów śledzących. W kreatorze
   i formularzu źródła szkicu z adresu nie da się zmienić (`StrazImportu`
   nadpisuje je przy każdym zapisie).
3. **Zdjęć z cudzych stron nie pobieramy** — ani do szkicu, ani jako podgląd;
   parser JSON-LD nie przenosi nawet adresu zdjęcia.
4. **`robots.txt` szanowany** (RFC 9309: grupa `KukingImport` przed `*`;
   4xx = wolno, 5xx i brak odpowiedzi = nie wolno), uczciwy `User-Agent`
   `KukingImport/1.0 (+<adres serwisu>/o-kuking)`, bez obchodzenia zabezpieczeń.
5. **Pełna ochrona SSRF:** tylko `http`/`https`, porty 80/443, bez
   `user:hasło@`; blokada adresów prywatnych, pętli, link-local (w tym
   metadanych chmury), CGNAT, zakresów zarezerwowanych, IPv6 lokalnych,
   IPv4 zapisanych jako IPv6, NAT64/6to4, nazw jednoczłonowych i stref
   `.internal`/`.local`/`.localhost`; KAŻDY adres IP nazwy musi być publiczny;
   połączenie przypięte do sprawdzonego IP (`CURLOPT_RESOLVE`, bez proxy
   ze zmiennych środowiskowych); host sprowadzany do jednej postaci
   (małe litery, punycode, bez końcowej kropki, IPv4 z zapisów
   `inet_aton`, IPv6 skrócony) i adres dla cURL-a składany z niej na nowo,
   żeby przypięcie i żądanie miały ten sam klucz, a po połączeniu
   `CURLOPT_PREREQFUNCTION` przerywa żądanie do adresu innego niż
   sprawdzony (#1978); każde przekierowanie (najwyżej 3) i każdy
   `robots.txt` przez strażnika od nowa; limit 2 MB liczony w trakcie
   pobierania, bez rozpakowywania, 10 s na żądanie, 25 s na całość,
   tylko `text/html`.
6. **Bez masowego importu:** jeden adres albo jeden plik na wysłanie, brak
   pola na listę adresów, wspólny dla OCR, adresu i PDF limit na osobę **5 dziennie / 30 miesięcznie**,
   wspólny dla wszystkich źródeł importu (jak przy OCR), plus throttle trasy.
7. **Ostrzeżenie (nie blokada, P-10)** przy publikacji, gdy opis
   przygotowania jest podobny do strony źródłowej w ≥ 60% (`similarity()`
   z `pg_trgm` wobec tekstu zapamiętanego przy imporcie).
8. **„Sprawdziłem odczytany tekst" (P-12)** — pole wymagane przed pierwszą
   publikacją szkicu z importu (adres, PDF, zdjęcie). Pilnuje go
   `PublishRecipe` przez kontrakt `StrazPochodzeniaPrzepisu`, więc obowiązuje
   w kreatorze, w formularzu jednostronicowym i w każdym przyszłym wejściu.
9. **Model „GPT-6 Luna” (`gpt-6-luna`) tylko tam, gdzie bez niego się nie
   da:** strona BEZ danych JSON-LD `Recipe` (tryb fragmentów — model zwraca
   same granice wierszy z etykietami, PHP składa tekst z oryginału i odrzuca
   odpowiedź bez pełnego pokrycia) i PDF BEZ warstwy tekstu (ścieżka OCR).
   Strona z JSON-LD i PDF z tekstem idą lokalnie, bez kosztu. Wywołania
   modelu wyłącznie przez klienta, budżet i zgodę z fundamentu importu;
   wysiłek rozumowania dla wyznaczania fragmentów — `low`
   (`KUKING_IMPORT_EFFORT_TEKST`, decyzja właściciela z 26.09.2026).
   Każde takie wywołanie wymaga zgody zaznaczonej w danym formularzu;
   wcześniejsza zgoda na odczyt zdjęcia kartki nie obejmuje tekstu strony
   ani stron skanowanego PDF. Rezerwacja w istniejącym budżecie AI następuje
   przed wysłaniem, a po wyczerpaniu budżetu model nie dostaje danych.
   **Uzupełnienie #2031 (29.09.2026):** zgoda z formularza jest wersjonowana —
   informacja stoi w jednym komponencie `x-zgoda-zrodlo-ai`, formularz niesie
   `InformacjaTekstuZrodlaAi::WERSJA`, a zaznaczone pole z inną albo brakującą
   wersją nie wysyła niczego do modelu. Opis źródeł: rejestr czynności §3.23
   i `docs/legal/projekty/POLITYKA_ODCZYT_AI.md`.

**W kodzie.** `app/Domain/Import/` (`Url/StraznikAdresow`, `Url/PobieraczStron`,
`Url/RobotsTxt`, `Url/ParserJsonLdPrzepisu`, `TrybFragmentow`,
`Pdf/TekstZPdf` przez `poppler-utils`, `ParserTekstuPrzepisu`,
`Actions/ZapiszSzkicZImportu`, `StrazImportu`, `PodobienstwoDoZrodla`,
`LimitImportu`), tabela `przepisy_z_importu` (`docs/DATABASE.md`), trasy
`/dodaj/przepis/z-adresu` i `/dodaj/przepis/z-pdf`, konfiguracja
`kuking.import`. Testy: `ImportStraznikAdresowTest`, `ImportPobieraczStronTest`,
`ImportParseryTest`, `ImportTekstZPdfTest`, `ImportPrzepisuZAdresuIPdfTest`,
`CofniecieMigracjiImportuTest`, `ObrazMaNarzedziaPdfTest` — sieć wyłącznie
przez `Http::fake`, DNS przez podstawioną mapę nazw.

**Uzupełnienie #2293 (30.09.2026, audyt infra IN-01).** Poppler chodzi przez
Symfony Process, a `docker/php.ini` wyłącza `proc_open`, więc na obrazie
produkcyjnym odczyt PDF padał zawsze. `php.ini` zostaje bez zmian (WWW dalej
bez `proc_open`); procesy `queue:work` dostają w `docker/entrypoint.sh` flagę
`-d disable_functions=` z listą równą tej z `php.ini` minus `proc_open`
(`FUNKCJE_ZABRONIONE_KOLEJKI`). `exec`, `system`, `popen` i reszta zostają
wyłączone także w kolejce. Proces bez `proc_open` dostaje nazwane odrzucenie
„odczyt PDF chwilowo nie działa” i błąd w logu (`TekstZPdf::wymagajUruchamianiaProcesow`).
Testy: `KolejkaCzytaPdfZProdukcyjnymPhpIniTest` (podproces PHP z produkcyjnym
`php.ini`) i krok CI „Odczyt PDF w procesie kolejki obrazu (#2293)” na
zbudowanym obrazie.

**Czego ta decyzja nie zmienia.** Nie otwiera masowego importu ani importu
z serwisów wymagających logowania; nie pozwala AI „przepisać własnymi słowami"
cudzego tekstu przed publikacją (to byłoby pranie cudzej treści). Nie zastępuje
opinii prawnika — jeśli prawnik wskaże inaczej, import z adresu wyłącza się
bez wdrożenia: `KUKING_IMPORT_URL=false` (przycisku wtedy nie ma, D-053).

### Uzupełnienie #28 (29 września 2026): import z adresu chodzi w kolejce

Punkt 5 powyżej (limity czasu) opisuje pracę zadania, nie żądania WWW. Wysłanie
formularza z adresem **tylko zleca** import: szybka kontrola składni bez DNS-u
(`StraznikAdresow::sprawdzBezSieci`), jedna transakcja ze zleceniem
(`importy_przepisow`, `zrodlo = 'url'`), miejscem w wspólnym limicie
(`proby_importu`) i zadaniem `ImportujPrzepisZAdresu` (kolejka `low`, `tries = 1`,
`timeout = 150 s`), przekierowanie na ekran postępu `/import/{id}`. Pobranie
strony (robots.txt, DNS, przekierowania), parser JSON-LD i ewentualne żądanie do
modelu robi worker — żadna z gałęzi nie zajmuje procesu WWW, a zerwane połączenie
po wysłaniu niczego nie przerywa. Wszystkie bramki z D-300 zostają w warstwie
domenowej (`OdczytajPrzepisZAdresu`, `PlatnyOdczytImportu`), nie w kontrolerze.
Zgoda „odczyt AI” na wysłanie tekstu strony jedzie w zadaniu jako flaga z tego
jednego formularza; adres jest w wierszu zlecenia i znika z niego w stanie
końcowym. Jedna próba (`tries = 1`) jest świadoma: rezerwacja budżetu ma klucz
`(próba, 1)`, więc automatyczne ponowienie płatnego kroku zostałoby odrzucone;
zadanie zabite w środku kończy w `failed()`, które domyka księgę budżetu.
Ponowienie należy do człowieka („Wklej adres jeszcze raz”) i liczy się do jego limitu.
Migracja `2026_09_29_120000_extend_importy_przepisow_kod_bledu_o_adres` dopisuje do
`kod_bledu` siedem powodów odmowy strony (`docs/DATABASE.md`). 
**Etap 2 (#28, #2051): import z PDF w kolejce.** Ten sam wzorzec
(`ZlecImportZPdf` → `ImportujPrzepisZPdf`, kolejka `low`, `tries = 1`, `timeout = 200 s`),
z jedną różnicą: wejściem jest plik. Żądanie WWW robi tylko tanie kontrole (rozmiar,
sygnatura `%PDF-`), zapisuje plik na prywatny dysk współdzielony przez web i worker
(`kuking.import.pdf.dysk`, na produkcji ten sam co surowe uploady Livewire, czyli R2;
katalog `import-pdf-tmp/`, odrębny od `livewire-tmp/` i `incoming/`), potem zlecenie
(`importy_przepisow.plik_tymczasowy`) i zadanie w jednej transakcji. Worker pobiera
plik do kopii roboczej, uruchamia Popplera (i tylko dla skanu, tylko za zgodą z tego
formularza, model), a plik znika w każdym stanie końcowym, przy usunięciu konta
i po retencji (`kuking:odzyskaj-importy`, także pliki bez wiersza). Zgoda „odczyt AI”
dla skanu PDF jedzie w zadaniu jako flaga z formularza, nie z ustawień konta; PDF
z warstwą tekstu nie wychodzi z serwisu. Migracja
`2026_09_29_150000_add_plik_tymczasowy_and_kody_pdf_to_importy_przepisow` (kolumna
+ siedem kodów odmowy PDF; `docs/DATABASE.md`).
Testy: `ImportZAdresuWKolejceTest`, `ImportZPdfWKolejceTest`,
`CofniecieMigracjiKodowAdresuImportuTest`, `CofniecieMigracjiPlikuTymczasowegoImportuTest`,
`ImportPrzepisuZAdresuIPdfTest`, `UmowaKolejkiTest`.

### Doprecyzowanie parserów lokalnych (#2536, #2538, #2539)
Parser lokalny wybiera pierwszy **niepusty** przepis w kolejności węzłów JSON-LD;
pusty `Recipe` w tym samym grafie nie zasłania późniejszego przepisu. Mikrodane
odczytują składnik i krok także z atrybutu `content` elementu `meta`, bez
mieszania pól odrębnych przepisów. Jednoznaczna tekstowa liczba porcji przyjmuje
setne z kropką albo przecinkiem w granicach formularza 0,5–999 porcji;
zakresy, inne jednostki, wartości poza granicą i większa precyzja
pozostają puste. Te naprawy #2536/#2538/#2539 nie zmieniają schematu ani
budżetu modelu. Wycofanie kodu przywraca błędy odczytu, ale nie wymaga migracji.

### Wycofanie
`KUKING_IMPORT_URL=false` i/lub `KUKING_IMPORT_PDF=false` zdejmują przyciski
i trasy (404). Istniejące szkice zostają prywatne i zachowują bramkę
„Sprawdziłem". Zdjęcie tabeli `przepisy_z_importu` — tylko według rollbacku
w `docs/DATABASE.md` (`down()` odmawia przy niesprawdzonych szkicach).
