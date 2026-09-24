# Stan sesji, część 7 — raporty dotąd niezapisane

## Tabela kontrolna

| Stanowisko | Plik raportu | Czy był już zapisany gdzie indziej | Czy zapisuję go tutaj |
|---|---|---|---|
| `gpt-analityka-piksel` | `docs/infra/ANALITYKA_PIKSEL_204_692.md` | Nie znaleziono w STAN_SESJI(.md)/CZESC2–6 | Tak |
| `gpt-cloudflare-cache` | `docs/infra/CLOUDFLARE_CACHE_597_610.md` | Nie znaleziono | Tak |
| `gpt-cloudflare-cache` | `docs/infra/SONDA_WDROZENIA_805_808.md` | **Tak** — ten sam wynik (#805–#808) opisany już w STAN_SESJI.md, sekcja `` gpt/sonda-wdrozenia `` (linia ok. 702), z tymi samymi liczbami (22 oblane/8 zaliczonych, 4429/83836/392,84 s) | Nie — pomijam jako duplikat |
| `gpt-dlug-weryfikacyjny` | `docs/audits/WERYFIKACJA_713_492_2026_09_20.md` | **Tak** — STAN_SESJI_CZESC5.md, sekcja 1 „Dług weryfikacyjny (#713, #492)” | Nie |
| `gpt-dr-baza` | `docs/infra/DR594_RUNBOOK_LOKALNY.md` + `evidence/dr594/RAPORT.md` | **Tak** — STAN_SESJI_CZESC5.md, sekcja 2 „Odtwarzanie bazy (#594 / #193)” | Nie |
| `gpt-dr-zdjecia` | `docs/infra/DR_ZDJEC_617_602.md` + `evidence/dr617/RAPORT.md` | Nie znaleziono | Tak |
| `gpt-monitoring` | `docs/infra/MONITORING_ODBIOR_2026_09_20.md` | **Tak** — STAN_SESJI_CZESC5.md, sekcja 3 „Monitoring i budżet połączeń (#598 / #599)” | Nie |
| `gpt-obciazenie` | `docs/obciazenie/KORPUS_605.md` | Nie znaleziono | Tak |
| `gpt-odbior-wdrozen` | `docs/infra/ODBIOR_WDROZEN_568_601_2026_09_20.md` | Tak — STAN_SESJI.md, `` gpt/odbior-wdrozen `` | Nie |
| `gpt-openai-granice` | `docs/legal/GRANICE_OPENAI_912_909_911.md` | Nie znaleziono | Tak |
| `gpt-panel-moderacji-marka` | `docs/design/PANEL_ODBIOR_FLOTY_581_2026_09_20.md` | Tak — STAN_SESJI_CZESC6.md, „Raport 1: Panel moderacji #581” | Nie |
| `gpt-przepis-liczniki` | `docs/design/WERYFIKACJA_LICZNIKOW_I_LINKOW_666_667_2026_09_20.md` | Tak — STAN_SESJI_CZESC6.md, „Raport 2: Liczniki i cele odnośników” | Nie |
| `gpt-pwa-push` | `docs/design/POMIAR_PWA_PUSH_2026_09_20.md`, `docs/product/WEB_PUSH_35.md` | Tak — STAN_SESJI.md, `` gpt/pwa-push `` | Nie |
| `gpt-pytania-poradzcie` | `docs/qa/pytania-poradzcie/RAPORT.md` | Tak — STAN_SESJI.md, `` gpt/pytania-poradzcie `` | Nie |
| `gpt-r2-jurysdykcja` | `docs/infra/R2_ODBIOR_2026_09_20.md` | Tak — STAN_SESJI.md, `` gpt/r2-jurysdykcja `` | Nie |
| `gpt-rozbicie-uslug` | `docs/infra/ROZDZIELENIE_ROL_595_600.md` (+ `_POMIARY.md`) | Tak — STAN_SESJI.md, `` gpt/rozbicie-uslug `` | Nie |
| `gpt-ugotowalem-dostep` | `docs/research/dostep-902-903-910/RAPORT.md` | Tak — STAN_SESJI_CZESC2.md, `` gpt/ugotowalem-dostep — #902, #903, #910 `` | Nie |
| `gpt-ustawienia-profilu` | `docs/design/USTAWIENIA_PROFILU_801_804.md` | Tak — STAN_SESJI_CZESC2.md, `` gpt/ustawienia-profilu — #801–#804 `` | Nie |
| `gpt-wspomnienia-prywatnosc` | `docs/product/WSPOMNIENIA_PRYWATNOSC_879_882.md` | Tak — STAN_SESJI_CZESC2.md, `` gpt/wspomnienia-prywatnosc — #879–#882 `` | Nie |
| `gpt-wyszukiwanie-granice` | `docs/research/GRANICE_WYSZUKIWANIA_885_886.md` | Nie znaleziono | Tak |
| `gpt-zdjecia-limity` | `docs/research/ZDJECIA_LIMITY_2026-09-20.md` | Tak — STAN_SESJI_CZESC2.md, `` gpt/zdjecia-limity — #883, #884, #891 `` | Nie |
| `gpt-zeszyt-droga` | `docs/design/ZESZYT_DROGA_904_905_908.md`, `DECYZJA_POWIADOMIEN_ZESZYT_906.md`, `docs/infra/ZAPIS_ZESZYTU_907.md`, `output/ZESZYT-DROGA-RAPORT.md` | Tak — STAN_SESJI_CZESC2.md, `` gpt/zeszyt-droga — #904–#908 `` | Nie |
| `gpt-haslo-konto-zoom`, `gpt-obciazenie-evidence`, `gpt-tablica-evidence`, `gpt-zeszyt-zapisy-evidence` | (brak repozytorium git / brak pliku `.md` raportu) | — | Nie — to katalogi z surowymi dowodami (skrypty, JSON, logi), bez narracyjnego raportu `.md`; nie są „raportem stanowiska” w rozumieniu zlecenia |
| `gpt-pytania-eksport` | — | — | Nie — `git diff origin/main..HEAD` jest pusty, brak nowych plików |

Metodę potwierdzenia „już zapisane” oparłem o `grep` po nazwach plików raportów, numerach zgłoszeń i nagłówkach `##`/`###` we wszystkich sześciu plikach `STAN_SESJI*.md` obecnych w chwili pisania (część plików, np. CZĘŚĆ2, CZĘŚĆ5, dopisywały się w trakcie tej pracy — sprawdzałem stan po raz ostatni tuż przed zapisem tej tabeli).

Poniżej opisuję **sześć raportów, które nie miały jeszcze zapisu**: `gpt-analityka-piksel`, `gpt-cloudflare-cache` (plik CLOUDFLARE_CACHE, nie SONDA_WDROZENIA), `gpt-dr-zdjecia`, `gpt-obciazenie`, `gpt-openai-granice`, `gpt-wyszukiwanie-granice`.

---

## `gpt/analityka-piksel` — #204, #692

Gałąź `gpt/analityka-piksel`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, drzewo czyste przed pomiarem. Runtime `/home/mateusz/flota/gpt-analityka-piksel-run`, PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_gpt-analityka-piksel`.

**Wynik: #692 jest już naprawione w badanym drzewie** (nie zmieniano eksportu). **#204 pozostaje otwarte** po stronie konfiguracji dostawcy EmailLabs i pomiaru doręczonych listów — nie ma własnego świeżego dowodu piksela w skrzynce odbiorcy.

### #692 — własny pomiar istniejącej naprawy
Historia wskazuje commit `1afd5f697545000b50d1ef36d0aca13e52dd90e5` z testem `EksportMowiOZdjeciachKtoreNieWejdaNigdyTest`. Na nietkniętym drzewie: **51 PASS, 327 asercji** (razem z testami zdjęć w drodze, wykrywacza piksela i transportu). Test buduje ZIP przez `GenerateUserExport`, czyta `index.html`, `CZYTAJ-TO-NAJPIERW.txt`, `dane.json`; sprawdza m.in., że przy koncie z gotowym i odrzuconymi zdjęciami w paczce jest tylko gotowe zdjęcie, oraz osobne zdania o liczbie odrzuconych/skasowanych zdjęć. Dwie kontrole ujemne (`rejectedCount()`→0, `deletedCount()`→0): obie oblały na brakującym zdaniu, po przywróceniu zielone, MD5/mtime porównane.

Wniosek autora: #692 można przekazać do zamknięcia na podstawie pomiaru tego commita — **ale to nie jest ogląd produkcyjnej paczki i nie mówi, jaki SHA jest obecnie wdrożony**.

### #204 — co dokładnie wysyła aplikacja
`ListyKontaPrzedEmailLabsTest` przepuszcza prawdziwe `UstawienieNowegoHasla` i `PotwierdzenieAdresu` przez `MailChannel`/`TransportEmailLabs` (atrapa HTTP zatrzymuje ruch dopiero przed siecią, bez `Mail::fake()`). **Własny pomiar: oba żądania mają zero obrazków, zero śladów wykrywacza i nagłówek `X-TRACKING-OFF = "1"`.** Test nie mierzy kolejki, dostawcy ani zawartości skrzynki. Dwie kontrole ujemne (wstawka `img`, zmiana nagłówka na `0`) — obie oblały z właściwej przyczyny, potem zielone z potwierdzeniem MD5/mtime.

**[pomiar cudzy: treść issue #204, historyczny list z 9.09.2026 na o2.pl]** — potwierdzenie adresu miało wtedy `img` i tło CSS kierujące na `click.kuking.pl/track/o/…`; to dowód dotyczący jednego rodzaju listu i wcześniejszego ustawienia konta, **nie pomiar resetu hasła ani stanu z 20 września**.

Tabela inwentaryzacji kodu wskazuje, które klasy używają domyślnego mailera (a więc podlegają Open Tracking EmailLabs, jeśli jest włączony): konto/bezpieczeństwo (`PotwierdzenieAdresu`, `UstawienieNowegoHasla`, `LinkDoLogowania` i inne), zgłoszenia/odwołania, listy do obsługi serwisu, paczka/kontakt, oraz osobno `PodsumowanieTygodnia` (decyzji o mierzeniu digestu #204 nie rozstrzyga). **Autor jawnie zastrzega: nie wolno mówić „potwierdzono piksel w resecie hasła” na podstawie samego wspólnego transportu** — jedyny przejęty pomiar rzeczywiście doręczonego HTML dotyczy `PotwierdzenieAdresu`.

`kuking:sprawdz-poczte` używa `Mail::raw()` (tekst) — brak piksela w takim liście **nie potwierdza** wyłączenia wstawek HTML; runbook to teraz koryguje.

### Konfiguracja dostawcy — przegląd publicznego API, bez wejścia na konto
20.09.2026 pobrano publiczne OpenAPI EmailLabs: `POST /v2.1/email` przypisuje `X-TRACKING-OFF` do śledzenia **kliknięć**; `EmailObject` nie zawiera ustawienia otwarć. Nie znaleziono w tej dokumentacji nagłówka/pola wysyłki wyłączającego otwarcia — **to ograniczony wynik przeglądu publicznego API, nie dowód nieistnienia opcji dostępnej wyłącznie przez wsparcie**. Wskazana ścieżka ręczna dla właściciela: Email API → Settings → SMTP Accounts → subkonto (historycznie `1.mkapica.smtp`) → Additional Settings → Open Tracking → wyłączyć, potem odebrać nowe HTML (reset hasła, potwierdzenie adresu, informacja o paczce) i uruchomić `kuking:sprawdz-piksel`.

### Polityka prywatności
Własny odczyt `resources/legal/polityka-prywatnosci.md`: §2 (wiersze 35–36) i §3 (wiersze 52, 77–79) już wymieniają dostawcę i mechanizm piksela, zapowiadając wyłączenie po jego stronie — **zarzut całkowitego milczenia polityki nie opisuje tego drzewa**. Autor zastrzega: samo poinformowanie nie rozstrzyga podstawy przetwarzania; sformułowanie „w każdym liście” jest szersze niż udokumentowany mechanizm HTML i po decyzji/wyłączeniu wymaga korekty.

### Kontrole końcowe i granice
Końcowe regresje: **52 PASS, 343 asercje**. Cztery kontrole ujemne PASS→FAIL→PASS. Pełny przebieg: **4392 PASS, 2 FAIL, 83 708 asercji, 530,99 s** — obie porażki (`DokumentyMdNieMajaMartwychOdnosnikowTest`) były spowodowane własną pracą (raport dopisany przed skopiowaniem runtime, literalna ścieżka API dostawcy wyglądała jak trasa aplikacji); po poprawce klasa strażnika + nowy test poczty: **4 PASS, 65 asercji, 3,56 s** — **autor zastrzega, że nie przedstawia tego jako pełnego zielonego przebiegu**. Pint: PASS, bez plików do poprawy; PHPStan: PASS.

**Czego nie zrobiono i dlaczego:** nie wysłano poczty do ludzi, nie zmieniono produkcji ani konfiguracji EmailLabs, nie policzono zdjęć/listów na produkcji, nie badano wyglądu w przeglądarce (brak zmian UI). Wycofanie: odwrócić commit gałęzi; nie ma migracji; wycofanie raportu/testu nie zmienia konfiguracji EmailLabs.

**Czego potrzeba od właściciela:** rekomendowane wyłączenie Open Tracking i zebranie dowodów z doręczonych HTML (koszt: ustawienie konta + próby na własnych skrzynkach). Warianty zapasowe: wyjaśnienie ze wsparciem EmailLabs, zmiana dostawcy (koszt integracji/DNS/dokumentów), albo świadome pozostawienie pomiaru otwarć (wymaga osobnej decyzji i oceny prawnej — obecny akapit polityki sam jej nie rozstrzyga).

---

## `gpt/cloudflare-cache` — #597, #610

Gałąź `gpt/cloudflare-cache`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. **To przygotowanie aplikacji, reguł i kontroli — nie wdrożenie Cloudflare.** Plik `cloudflare-cache-rules-597-610.json` (trzy wyłączone reguły) nie zastępuje całego rulesetu i nie był wysłany do API Cloudflare.

**#597** — anonimowe zdjęcie bez Cookie/Authorization może być cache'owane. **#610 — autor jawnie pisze: NIE WŁĄCZAĆ.** HTML gościa dziś uruchamia sesję, wystawia ciasteczka i CSRF; reguła HTML jest projektem do późniejszego odbioru, nie gotową optymalizacją.

### Własny pomiar przed zmianą
Baza `4c811cc7`, drzewo czyste. `ZdjeciaChronioneNieWyciekajaTest`: **31 zaliczonych, 183 asercje**. Nowa kontrola na niezmienionej aplikacji: **4 oblane, 1 zaliczony, 13 asercji** — anonimowy publiczny obraz miał `public` razem z `XSRF-TOKEN` i ciasteczkiem sesji; zalogowany na publicznym zdjęciu też dostawał `public`; HTML z sesją nie miał `no-store`; błędny publiczny nagłówek z nagłówkiem CDN nie był odcinany przez sesję. **To pomiar aplikacji w izolacji, nie dowód wycieku na produkcji ani odczyt konfiguracji brzegu.**

### Co chroni aplikacja
`PreventSharedSessionCache` ustawia `private, no-store` dla sesji/Cookie/Authorization/zalogowanego/odpowiedzi z cookie/błędów i usuwa nadrzędne nagłówki CDN/Surrogate. Tylko `GET/HEAD media.show` bez żadnych ciasteczek i Authorization może ominąć trwałą sesję (dostaje pusty magazyn w pamięci, nie czyta/zapisuje sesji w PostgreSQL). Policy rodzica sprawdzana przed podpisem — zalogowany widz nawet na publicznym zdjęciu dostaje `private, no-store`.

### DECYZJA WŁAŚCICIELA
20.09.2026: **„Zaakceptuj godzinę dla wcześniej publicznego zdjęcia.”** Rozróżnienie wynika z aktualnej decyzji `dlaAnonima`, nie z tego, czy zalogowany jest właścicielem.

| Rodzaj w chwili wydania podpisu | Podpis R2 | Cache anonimowego 302 i bajtów |
|---|---:|---:|
| publiczny | 60 minut | 30 minut |
| dostępny wyłącznie prywatnie | 5 minut | `private, no-store` |

Nowa opcja `KUKING_MEDIA_PUBLIC_SIGNED_URL_MINUTES` (istniejąca `KUKING_MEDIA_SIGNED_URL_MINUTES` dotyczy zdjęć chronionych). Zmiana wpisu na prywatny zamyka nowe żądanie originu, ale nie unieważnia już wydanego podpisu — kopia bajtów może być świeża jeszcze przez 30 minut, więc **granica wynikająca z podpisu i cache wynosi do 90 minut od wydania podpisu**. To nie jest obietnica skasowania kopii już pobranej przez kogoś. Najszerszy publiczny rodzic nadal wygrywa (D-020).

### Reguły do ręcznego przygotowania (przekazanie dla właściciela/operatora)
1. Zachować istniejące reguły statycznych assetów; usunąć sprzeczne „Cache Everything” dla domeny bucketu (D-020 nadal obowiązuje).
2. Reguła zdjęć: Eligible for cache, Edge TTL = „Use cache-control header if present, bypass cache if not” (`bypass_by_default`), Browser TTL = Respect origin. Bez liczbowego Edge TTL/Status Code TTL; zachować Origin Cache Control, nie usuwać Set-Cookie, nie ignorować query string w kluczu.
3. Reguła ochronna BYPASS **ostatnia** po wszystkich regułach eligible — pomija każde cookie (także remember-me i wygląd), Authorization, query string, metody inne niż GET/HEAD i trasy konta.
4. HTML pozostawić wyłączony.
5. Przejrzeć też Workers/Page Rules/reguły odpowiedzi — zewnętrzny override może unieważnić nagłówki aplikacji (kod PHP tego nie wykryje).
6. Na stagingu włączyć najpierw ochronny BYPASS, potem regułę zdjęć; dopiero dodatni odbiór pozwala powtórzyć na produkcji. **Porażka sondy = wyłączenie eligible i purge, nie podnoszenie TTL.**

### Zależność i pułapka przy scalaniu
Sonda odbioru (`sprawdz-wdrozenie.sh --cache-gate`) jest zależnością z commita `cb560b3d46b70d1d66cbcd75934d3d6d1349934f` gałęzi `gpt/sonda-wdrozenia` — używa jej parsera nagłówków (#806) bez powielania przyrządu. **Pułapka przy scalaniu:** ten sam commit sondy występuje też jako plik `SONDA_WDROZENIA_805_808.md` wewnątrz repozytorium `gpt-cloudflare-cache` (patrz tabela kontrolna — pominięty tu jako duplikat już zapisanego wyniku `gpt/sonda-wdrozenia`); integrator musi rozstrzygnąć, z której gałęzi bierze finalną wersję sondy, żeby nie zdublować lub nie cofnąć zmian.

### Weryfikacja lokalna
PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_gpt-cloudflare-cache`. Po zmianach: pełny końcowy przebieg **4467 zaliczonych, 1 porażka, 83 994 asercje, 439,32 s** — jedyna porażka to martwy odnośnik w dokumentacji (skrót ścieżki zdjęć), naprawiona; kontrola dokumentacji po poprawce: 3 testy, 49 asercji (całości nie powtórzono). Cztery kontrole ujemne: PASS→FAIL→PASS z odtworzeniem bajtów i czasu plików. `vendor/bin/pint`: 1161 plików PASS; PHPStan bez błędów.

**Czego nie zrobiono:** nie zmieniono Cloudflare, DNS, Railway ani R2; brak push/PR; nie potwierdzono produkcyjnego HIT, obsługi parametrów przez R2, działania purge ani kompletności obecnych reguł — **odbiór infrastruktury zostaje dla właściciela**. #610 pozostaje zablokowane technicznie przez sesyjny HTML.

Wycofanie: najpierw wyłączyć reguły eligible, zachować końcowy BYPASS, wyczyścić istniejące wpisy cache; dopiero potem `git revert` aplikacji. Brak migracji. Skrócenie konfiguracji wpływa tylko na nowe podpisy, nie na już wydane. Purge Cloudflare nie czyści cache przeglądarek ani zapisanych kopii.

---

## `gpt/dr-zdjecia` — #617, #602

Gałąź `gpt/dr-zdjecia`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. **To propozycja do decyzji właściciela, nie wdrożona ochrona.** Nie czytano produkcyjnego R2, nie pozyskiwano dostępu; narzędzie pracuje wyłącznie na lokalnym MinIO.

### Stan przed zmianami
Odczyt kodu: `StoreUploadedImage` woła `UsunGps::zBajtow()` przed `Storage::put()`; oryginał zachowuje pozostały EXIF (D-023). Dyski zdjęć dzielą poświadczenie `AWS_ACCESS_KEY_ID`; `r2_kopie` służy tylko kopiom BAZY — **nie znaleziono w konfiguracji/skryptach wykonywalnego procesu drugiej kopii zdjęć**. `--filter OryginalTraci` na nietkniętym drzewie: **18 testów, 63 asercje, zielone**.

### #617 — wybór do zatwierdzenia (DECYZJA WŁAŚCICIELA: brak — wymaga wyboru)
| Wariant | Korzyść | Koszt |
|---|---|---|
| Blokada aktywnych oryginałów | Odrzuca DELETE/overwrite w okresie ochrony | Koliduje z normalnym kasowaniem — **nie rekomendujemy** |
| Osobne konto R2, prywatna kopia z Bucket Lock | Błąd aplikacji nie dosięga kopii | Administrator konta kopii może zdjąć regułę |
| Osobne konto AWS S3, Versioning + Object Lock COMPLIANCE | Chroni wersje nawet przed administratorem | Inny dostawca, koszt transferu, trudniejsze usuwanie |
| Akceptacja ryzyka | Brak nowej usługi/opłat | Logicznie usunięte zdjęcia mogą być nieodzyskiwalne |

**Rekomendacja autora:** osobne konto R2 z ograniczonymi tokenami i czasową blokadą kopii; jeśli wymagana odporność także na administratora konta kopii — rozważyć S3 COMPLIANCE. Autor jawnie nie ogłasza decyzji za właściciela.

Szczegóły do wdrożenia: osobne konto Cloudflare z odrębną administracją i MFA; wyłączony `r2.dev`; Bucket Lock na `snapshots/` na 30 dni + lifecycle kasujący po 31 dniach (**to propozycja okresów, nie obietnica terminu — lifecycle jest asynchroniczne**). **Autor zastrzega: nie wydawać `put-bucket-versioning` na R2 — R2 nie obsługuje wersjonowania/Object Lock przez API S3; Bucket Locks to osobna funkcja.** Tabela ról/poświadczeń rozdziela: aplikację (R/W na aktywnych bucketach, zero dostępu do kopii), proces czytający źródło, proces zapisujący kopię, operatora odtwarzania i administrację kopii (wyłącznie właściciel konta kopii, nigdy aplikacja/harmonogram).

**Koszt (model, nie odczyt rachunku):** 30 dni ochrony + kasowanie po 31 dniach ≈ **32 pełne zestawy** przy codziennym snapshotcie (nie jeden zestaw). Dla stałych 111 GB: ok. 3552 GB kopii ≈ **53,28 USD/mies.** za sam storage Standard przed darmowym progiem (pojedyncza kopia 111 GB = 1,665 USD, ale się starzeje). Dla 150 tys. obiektów i 30 dni: **4,5 mln PUT, ≥9 mln GET**, stawki 4,50 USD/mln klasy A, 0,36 USD/mln klasy B, 0,015 USD/GB-mies., transfer wychodzący R2 bez opłaty (ceny sprawdzone 20.09.2026).

### #602 — nie rekomendujemy direct upload do kwarantanny
Dziś GPS usuwa `UsunGps` synchronicznie na serwerze przed zapisem do R2. W proponowanej drodze przeglądarka→R2→worker GPS usuwałby **po utrwaleniu surowego pliku** — przy braku potwierdzenia/awarii/zatrzymaniu kolejki GPS zostaje do sprzątnięcia. **Autor: „nie potrafimy wykazać, że lokalizacja nigdy nie przeleży w kwarantannie; przeciwnie, lokalny PUT jest bezpośrednim kontrprzykładem.”** Rekomendacja: pozostać przy obecnym serwerze przed R2; powrót do tematu wymaga wyniku #605 (obciążenie) i osobnej decyzji o dopuszczalności surowej kwarantanny.

### Pomiar własny (evidence/dr617/RAPORT.md)
MinIO `RELEASE.2025-09-07T16-13-09Z`. **Przed ustawieniem retencji — czerwień z właściwego powodu:** bucket `--with-lock` bez domyślnego okresu retencji, test oblał 27 asercji: `BRAK_ODMOWY: pisarz kasuje chronioną wersję`. Rzeczywisty MinIO zwrócił **400 InvalidRequest: Object is WORM protected**, nie 403 — test teraz rozróżnia ten dokładny kod.

Po ustawieniu retencji COMPLIANCE 1 dzień (do lokalnego pomiaru, nie wybór produkcyjny), pierwszy zielony przebieg: 3 zdjęcia, **15 obiektów, 33 223 B**, **15/15 faktycznie usuniętych źródeł i odzyskanych** (rozmiary + SHA-256), 15/15 obrazów odczytanych po odzyskaniu, 3/3 stron HTTP 200, 12/12 tras wariantów→podpisany URL→GET MinIO poprawne. Czasy: przygotowanie+kopia **0,589 s**, wiek kopii przy utracie źródeł **0,053 s**, odtworzenie+weryfikacja **0,520 s** — **autor zastrzega: RTO produkcji niezmierzone (0,520 s to 33 kB lokalnie, bez wykrycia awarii/decyzji operatora/odzyskania poświadczeń), RPO produkcji nieustalone**, nie wolno przeliczać liniowo na 111 GB.

Końcowa weryfikacja pakietu: MinIO **2 PASS, 195 asercji**; **15/15 obiektów**, przygotowanie+kopia 0,466 s, odtworzenie+kontrola HTTP **0,324 s**; **9 odmów** (7×403 AccessDenied, 2×400 InvalidRequest/WORM). Zestaw projektu: **4393 PASS, 83 691 asercji, 472,78 s** (pominięto `ProbaOdtworzeniaTest` zgodnie z instrukcją floty). Trzy kontrole ujemne (brak retencji, uszkodzony bajt kopii, pominięcie `UsunGps`) — wszystkie **POTWIERDZONA**, PASS→FAIL→PASS.

**Zastrzeżenie granicy pomiaru:** to **nie dowodzi R2 Bucket Locks ani izolacji kont Cloudflare** — MinIO sprawdza własny silnik S3 i własne polityki; COMPLIANCE jest silniejsze niż reguła R2 (administrator R2 może ją zdjąć). Nie zmierzono wygaśnięcia jednodniowej retencji ani wykonania lifecycle.

**Czego nie zrobiono:** push/PR, zmiany produkcji, wdrożenie harmonogramu kopii, zmiana R2/polityki prywatności/schematu. Wycofanie: usunąć przyrząd i dokumentację, bez migracji.

---

## `gpt/obciazenie` — #605 (korpus prawdziwych zdjęć)

Ten raport (`docs/obciazenie/KORPUS_605.md`) jest krótkim opisem **nowej opcji przyrządu**, nie pełnym raportem z wynikami serii obciążeniowej. Generator i rampa to istniejące narzędzia `scripts/*obciazenia-605*`; nowa opcja `--korpus /bezwzgledna/sciezka/korpus.json` rozszerza scenariusz `upload` o realne pliki z manifestu (ścieżka, SHA-256, MIME, oczekiwany wynik `accepted`/`rejected`) — nie zastępuje mieszanki testem samych zdjęć.

Przed napływem generator sprawdza wszystkie sumy; korpus pusty, brak pliku, nieznany MIME lub zmienione bajty **zatrzymują bieg**. Zdjęcia i manifest sesji pozostają poza repozytorium; zakaz publikowania ciasteczek/tokenów/haseł/pełnego zrzutu EXIF-GPS.

**Zastrzeżenie autora wprost w raporcie:** dla korpusu HTTP 302 nie wystarcza jako dowód — przyjęcie wymaga przekierowania do `/wpisy/{uuid}`, oczekiwane odrzucenie do `/dodaj/zdjecie`; **sam redirect nie dowodzi utworzenia wpisu ani przetworzenia zdjęcia w tle** — raport musi osobno sprawdzić wpisy/media/warianty/kolejkę.

**Ważne sprostowanie nazewnictwa:** bez `--korpus` pozostaje historyczny plik `kuking-b605-12mpx.jpg`; **pliki tworzone przez `scripts/zdjecia-obciazenia-605.php` są syntetyczne, mimo określenia „prawdziwe zdjęcia” w dawnej metodzie** — nie spełniają wymogu fotografii z aparatów/telefonów. Autor jawnie zastrzega: **historycznych czasów obciążenia nie przeliczać na nowy korpus**.

Sprawdzenie samego przyrządu: `node scripts/przyrzad-605.test.mjs` (uruchamia też `korpus-605.test.mjs`) — mały serwer kontrolny sprawdza przesłane nazwy plików, publikację, odrzucenie i odmowę zmienionej sumy; **jego bajty są atrapą transmisji, nie zdjęciami do benchmarku**.

**Czego ten raport nie zawiera:** brak nowego pomiaru pojemności/przepustowości produkcji — to tylko przygotowanie przyrządu do przyszłej serii z realnym korpusem zdjęć. (Uwaga integracyjna: dodatkowy katalog `docs/infra/evidence/obciazenie605/2026-09-20-gpt/` w tym repozytorium jest niezacommitowany — `git status` pokazuje go jako `??` — więc nie wchodzi do historii gałęzi, dopóki ktoś go nie doda.)

---

## `gpt/openai-granice` — #912, #909, #911

Gałąź `gpt/openai-granice`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.

### DECYZJE WŁAŚCICIELA
20.09.2026 właściciel zatwierdził **wspólną ochronę zdjęć wpisów oraz awatarów** (#912) i potwierdził zasadę: **„otwarte uzupełniaj, zamknięte zostaw”** — sprawy odrzucone/zamknięte po edycji nie są wznawiane, bez wersjonowania zgłoszeń.

### Pomiar przed poprawkami
Czysty kod: 27 istniejących testów moderacji, 79 asercji, zielone.

- **#912:** syntetyczne historyczne metadane z samym `large` (brak klucza `thumb`), oraz `thumb` deklarujący 320×240 przy innych bajtach, wysyłały JPEG **2048×1536** do atrapy HTTP dostawcy — obiema drogami (wpis oraz zadanie `PrzeanalizujAwatar`). Przeszły też warianty 321×240 i 240×321. **Dziesięć czerwonych przypadków.** Kontrola dodatnia: rzeczywisty WebP 320×240 dał dwa żądania JPEG 320×240.
- **#909:** po zakończonej analizie neutralnego komentarza (0 spraw) i edycji przez endpoint — nowego zadania nie było (test czerwony na braku dispatch).
- **#911:** wyjątek w `Notification::creating` po potwierdzonej zmianie komentarza w PostgreSQL zostawiał `deleted_at`/`body_removed_at` bez powiadomienia (dwie czerwienie); drugie usunięcie z odpowiedzią dawało dwa powiadomienia zamiast jednego (trzecia czerwień).

### Implementacja
`OcenaModelem` wybiera **tylko dokładny wariant `thumb`**, sprawdza wymiary z bajtów przed dekodowaniem i ponownie na zakodowanym JPEG przed utworzeniem URI — **każdy bok najwyżej 320 px**, niezależnie od konfiguracji generatora wariantów i deklarowanych metadanych; większa miniatura jest pomijana (bez fallbacku na `large`/oryginał).

Zmiana tekstu komentarza zleca `PrzeanalizujTresc` po zatwierdzeniu (tekst identyczny po przycięciu nie zleca zadania). `OznaczDoPrzegladu.php` przejęto z `gpt/moderacja-ai` (SHA `906f19a96d98643305157e4f9ace4f33c0fce8f4`) — **pułapka przy scalaniu, zapisana wprost przez autora: „Przy scalaniu obu gałęzi zachować również rozdzielenie zadań i bramkę dostępu z gpt/moderacja-ai — nie przywracać całego starego `PrzeanalizujTresc` z tej gałęzi.”**

`DeleteComment` zamyka zmianę komentarza i powiadomienie w **jednej transakcji** z blokadą wiersza i ponownym sprawdzeniem Policy/znacznika; wyjątek cofa całość.

### Ograniczenia i przekazanie
Wszystkie pomiary lokalne (WSL, `kuking_flota_gpt-openai-granice`, `127.0.0.1:55439`); obrazy/konta syntetyczne, HTTP i poczta — atrapy. **Nie wykonano żądań do prawdziwego OpenAI**, korespondencji, push, PR, CI, wdrożenia ani operacji na danych produkcyjnych. **Nie policzono zdjęć bez miniatury na produkcji — autor jawnie: „nie stwierdzamy incydentu produkcyjnego ani jego skali”**; audyt agregatów produkcyjnych pozostaje osobną pracą.

### Kontrole końcowe
Pełny przebieg po głównych poprawkach: **4417 testów, 83 766 asercji, 380,11 s**, zielone (pominięto tylko `ProbaOdtworzeniaTest`). Rozszerzenie brzegów (wadliwy typ `thumb`, mały zamiennik, ślad odmowy, komentarz z śladem usunięcia): cztery czerwienie z oczekiwanych przyczyn, po poprawce własne testy **28/96 asercji**; po uzupełnieniach: **100 testów regresyjnych, 326 asercji**, zielone (pełnego przebiegu nie powtórzono). PHPStan i Pint (1158 plików) bez uwag. Sześć fizycznych kontroli mutacyjnych: każda PASS→FAIL z oczekiwaną przyczyną→PASS, z niezależnym potwierdzeniem MD5/mtime.

Lokalne commity: `32d5e9d93a615805ec0e83d4c90ca995cdb3dada` (#912 + ochrona awatarów), `7b40b509459bb93f24b69130dc9646774c13c469` (#909, #911).

Wycofanie: odwrócić lokalne commity; przed cofnięciem ochrony zdjęć wyłączyć ocenę obrazów lub wyczyścić klucz modelu, **żeby nie przywrócić znanej drogi wysyłki dużego obrazu**. Nie kasować powiadomień/zgłoszeń.

---

## `gpt/wyszukiwanie-granice` — #885, #886

Gałąź `gpt/wyszukiwanie-granice`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, drzewo czyste.

### Pomiar własny przed zmianą kodu
Istniejący `SearchTest`: **12 testów, 34 asercje, zielony**. Sonda `GraniceWyszukiwaniaPomiarTest.php` (testowy kernel HTTP, przechwycone SQL przez `DB::listen` — nie pomiar przeglądarki):

| Wpisane znaki | W polu HTML | Fraza w SQL: przepisy | Fraza w SQL: ludzie |
|---|---|---|---|
| 119 | 119 | 119 | 119 |
| 120 | 120 | 120 | 120 |
| 121 | 121 | 120 | 120 |
| 150 | 150 | 120 | 120 |

Sześć kolumn `*_search` w `information_schema.columns` mają typ `text`, `character_maximum_length = NULL` — limit **120** jest ochroną aplikacyjną (`SearchQuery::normalize()` → `mb_substr($phrase, 0, 120)`), nie ograniczeniem typu kolumny ani indeksu. `git log -S` wskazuje początkowy commit `9d7717c4`; **autor: „nie znalazłem w przeczytanej dokumentacji ani decyzjach pomiaru uzasadniającego dokładnie 120.”**

**[pomiar cudzy: treść #885 i #886]** — zgłoszenia zawierały sondy prywatnej normalizacji przez Reflection, bez PostgreSQL i HTTP; wnioski powyżej potwierdzono samodzielnie na bazie i HTTP, nie przejęto ich jako własnych.

### DECYZJA WŁAŚCICIELA — #885
Trzy warianty przedstawiono właścicielowi (odrzuć >120 i zachowaj tekst / szukaj pierwszych 120 i pokaż fragment / podnieś limit). **Wybrano pierwszy: „Tak, zachowaj tekst i poproś o skrócenie.”** Nie wymaga migracji ani nowej biblioteki.

### DECYZJA WŁAŚCICIELA — #886
Dwa warianty (pojedyncze `@` jako dotychczasowa fraza / `@` jako dokładny login). **Wybrano: „Takie same wyniki jak dla «basia» bez @ — najmniejsza zmiana.”** Dotychczasowe dopasowanie trzech pól i kolejność zachowane; pomijany tylko pojedynczy początkowy `@` (`@@` i `@` wewnątrz frazy pozostają dosłowne).

### Wdrożenie i weryfikacja
`SearchQuery::MAX_PHRASE_LENGTH` = 120 znaków przed transliteracją, po przycięciu spacji (prefiks `@` liczy się do limitu). Formularze GET renderują błąd w odpowiedzi 200 (bez przekierowania), cały tekst zostaje w polu; odrzucone wyszukiwanie nie zapisuje `search_performed`. Nowe regresje: `DlugoscFrazyWyszukiwaniaTest`, `PrefiksNazwyWWyszukiwaniuTest`. Trzy kontrole ujemne (usunięcie usuwania prefiksu, limit 120→1000, usunięcie walidacji domenowych) — wszystkie PASS→FAIL z oczekiwanej przyczyny→PASS, MD5/mtime potwierdzone.

Przebieg przed poprawkami: **4393 testy, 83 692 asercje, zielony (506,55 s)**. Końcowy pełny przebieg: **4423 testy, 84 005 asercji, zielony (424,16 s)** (pominięto tylko `ProbaOdtworzeniaTest`). Pint: 1158 plików. `npm run build`: zielony.

Chromium własny: 121 znaków `ż` zachowane w polu, fokus na błędzie przenosi się do `#f-q`; przy 320 px brak przewijania, tekst 20 px, komunikat 18 px, przycisk 50,5 px; przy CSS zoom 200% (viewport 640 px) brak przewijania poziomego. **Autor zastrzega: to emulacja CSS, nie pomiar natywnego powiększenia przeglądarki**; onboarding i trafienia osób potwierdzają tylko testy HTTP, nie oglądano na produkcji.

### Pułapka przy scalaniu
`SearchQuery.php` i `SearchController.php` **mogą wymagać ręcznego połączenia z gałęzią `wyszukiwarka`** — tamta gałąź ma zmiany `%`, `_` i typu `q` (#753/#738), które trzeba zachować. Po połączeniu uruchomić ponownie testy wyszukiwania na izolowanej bazie. Nie zmieniano obsługi `%`, `_` ani typu `q` w tej gałęzi. Obie decyzje produktowe (#885, #886) zostały podjęte w tej sesji — nie pozostał wybór limitu ani semantyki prefiksu do rozstrzygnięcia.

---

## Raporty, których NIE ruszono (do dokończenia przez kogoś innego)

Żadnych innych nowych raportów stanowisk `gpt-*` nie znaleziono poza wymienionymi w tabeli kontrolnej — wszystkie sprawdzone stanowiska są tu ujęte (opisane w tej części albo już zapisane gdzie indziej, albo bez nowego pliku raportu). Nie sprawdzano stanowisk spoza wzorca `gpt-*` (np. `gemini-*`, `hero-ekran`, `kaskada`, `relacje`, `stopka` itd.) — zlecenie ograniczało zakres do katalogów `gpt-*`.

Pełne ścieżki plików opisanych w tej części, dla kogoś kto chce zweryfikować źródła:
- `C:\Users\matma\Documents\kuking-flota\gpt-analityka-piksel\docs\infra\ANALITYKA_PIKSEL_204_692.md`
- `C:\Users\matma\Documents\kuking-flota\gpt-cloudflare-cache\docs\infra\CLOUDFLARE_CACHE_597_610.md`
- `C:\Users\matma\Documents\kuking-flota\gpt-dr-zdjecia\docs\infra\DR_ZDJEC_617_602.md`
- `C:\Users\matma\Documents\kuking-flota\gpt-dr-zdjecia\docs\infra\evidence\dr617\RAPORT.md`
- `C:\Users\matma\Documents\kuking-flota\gpt-obciazenie\docs\obciazenie\KORPUS_605.md`
- `C:\Users\matma\Documents\kuking-flota\gpt-openai-granice\docs\legal\GRANICE_OPENAI_912_909_911.md`
- `C:\Users\matma\Documents\kuking-flota\gpt-wyszukiwanie-granice\docs\research\GRANICE_WYSZUKIWANIA_885_886.md`
