# Kuking.pl — raport końcowy audytu wielodyscyplinarnego

**Repozytorium:** `woogitsu/kuking.pl`  
**Pełny snapshot audytu:** `main` @ `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Delta sprawdzona przed zamknięciem:** `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`  
**Data:** 10.09.2026  
**Język raportu:** polski

---

## 1. Werdykt w jednym zdaniu

**Kuking.pl jest technicznie znacznie dojrzalszy niż typowy projekt przed startem, ale na podstawie dostępnych dowodów nie zatwierdziłbym dziś szerokiego publicznego startu ani kampanii migracyjnej z Garnek.pl.** Powodem nie jest brak funkcji ani słaba jakość kodu, lecz kilka niedomkniętych bramek: trwałość danych i mediów, restore, realny monitoring po deployu, zgodność poczty z polityką prywatności, gotowość prawno-moderacyjna oraz brak wystarczającej liczby testów z docelową grupą 60–75 i krytycznej masy społeczności.

### Decyzja startowa

| Scenariusz | Decyzja | Warunek |
|---|---|---|
| Development / staging | **GO** | obecny stan jest wystarczający do dalszego rozwoju |
| Mała, kontrolowana alpha z zaproszonymi osobami | **CONDITIONAL GO** | tylko po potwierdzeniu trwałego storage, backupu i podstaw prawno-prywatnościowych |
| Publiczna beta | **NO-GO na podstawie dostępnych dowodów** | zamknąć bramki P0 opisane niżej |
| Szeroka kampania „tęsknisz za Garnek.pl?” | **NO-GO** | oprócz P0 potrzebna krytyczna masa realnej społeczności i sprawdzona retencja |

`NO-GO` nie oznacza, że produkcja jest „zepsuta”. Oznacza, że repo i dostępne źródła **nie dają jeszcze wystarczającego dowodu**, aby bezpiecznie skierować na serwis szeroki ruch osób, których zdjęcia, konta i relacje mają być trwałe.

---

## 2. Jak czytać liczbę znalezisk

W 13 audytach obszarowych zapisano **77 oznaczonych znalezisk/obserwacji**:

- 13 pozycji zawiera P0 lub P0/P1,
- 31 pozycji P1,
- 30 pozycji P2,
- 3 pozycje P3.

To **nie jest 77 niezależnych bugów**. Część problemów celowo pojawia się w kilku dyscyplinach, ponieważ jeden fakt ma kilka konsekwencji. Przykład: niedomknięty R2 jest równocześnie problemem bezpieczeństwa, infrastruktury, trwałości danych i obietnicy produktowej.

W planie napraw należy deduplikować problemy według bramek, nie według liczby wpisów w raportach.

---

## 3. Ogólny obraz projektu

| Obszar | Stan | Najważniejszy komentarz |
|---|---|---|
| Architektura | **dobry** | modularny monolit jest właściwy; nie rozbijać na mikroserwisy |
| Bezpieczeństwo aplikacji | **dobry z bramkami** | CSP/CSRF/model uprawnień mocne; direct origin i Host nadal wymagają domknięcia |
| Baza i integralność | **dobry z jednym ryzykiem zgód** | schemat dojrzały; ślad zgody na digest jest za słaby |
| Media/wydajność | **dobry dla MVP** | 50 Mpx nie przekracza udokumentowanego 1 GB sufitu; główny koszt to upload przez PHP |
| Testy i CI | **bardzo dobry** | ok. 2400 testów, PG18, axe/Lighthouse; PHPStan level 1 jest zbyt niski |
| UX 50–75 | **dobry, lecz wymaga dalszych realnych sesji** | kod nie zastąpi testów z użytkownikami |
| Produkt/cold start | **dobry kierunek, niedomknięty start** | funkcji jest wystarczająco; brakuje krytycznej masy ludzi i treści |
| SEO/PWA | **dobry** | kilka technicznych P2; usunąć wymuszanie orientacji pionowej |
| Moderacja / T&S | **proces dojrzały, tooling niedomknięty** | priorytet P0–P3 istnieje w playbooku, ale nie w kolejce |
| RODO/DSA/prawo | **blokujący** | tracking EmailLabs i rozjazd regulamin–produkt muszą zostać zamknięte |
| Infrastruktura/DR | **blokujący** | brak udowodnionego restore/offsite/storage/monitoringu |
| Supply chain | **dobry, lecz nie zahartowany** | świeże zależności; pin SHA i polityka CVE wymagają poprawy |
| Copy/dokumentacja | **dobry język, rosnący drift** | historia i stan bieżący są zbyt często wymieszane |

---

## 4. Twarde bramki przed publiczną betą

Poniższa lista jest ważniejsza niż wszystkie P2 razem.

### BRAMKA A — P0: trwałe media na R2, nie na ulotnym `local`

**Źródła:** audyty 02 i 11, issue #120, `docs/infra/BRAMKA_R2.md`.

Przed przyjęciem prawdziwych zdjęć trzeba udowodnić:

- wariant publiczny działa,
- oryginał nie jest publiczny,
- aplikacja może pobrać oryginał przez API,
- `r2.dev` jest wyłączone dla prywatnych oryginałów,
- `incoming/` nie trafia do publicznego bucketu,
- delete usuwa/purge'uje pliki,
- EXIF/GPS nie trafia do publicznego wariantu,
- realna produkcja nie zapisuje unikalnych zdjęć wyłącznie do filesystemu kontenera.

**Dowód zamknięcia:** wypełniona bramka z datą i testem na realnym R2, nie tylko zielone testy lokalne.

---

### BRAMKA B — P0: automatyczny backup + pełny restore drill

**Źródła:** audyt 11, #9, #193, `docs/infra/KOPIE_I_ODTWORZENIE.md`.

Dziś dokumentacja jest lepsza niż wykonanie. Trzeba mieć:

1. automatyczny zaszyfrowany `pg_dump` poza pojedynczą awarią Railway,
2. retencję,
3. alarm, gdy backup nie powstanie,
4. co najmniej jedno pełne odtworzenie na izolowanym środowisku,
5. zmierzone RPO/RTO,
6. kontrolę integralności po restore.

**Dowód zamknięcia:** data, źródło kopii, rozmiar, RPO, RTO, wynik i lista problemów po odtworzeniu.

Nie akceptować „backup jest skonfigurowany” bez testu restore.

---

### BRAMKA C — P0: EmailLabs nie może robić czegoś, czemu publiczna polityka zaprzecza

**Źródła:** audyt 10, issue #204, `resources/legal/polityka-prywatnosci.md`.

W rzeczywiście dostarczonej wiadomości stwierdzono dwa URL-e śledzące otwarcie `click.kuking.pl/track/o/...`, podczas gdy polityka prywatności mówi, że Kuking **nie używa pikseli śledzących otwarcia**.

To trzeba zamknąć przed szerszym mailingiem.

**Preferowana naprawa:** wyłączyć open tracking po stronie EmailLabs i zweryfikować surowy MIME realnie dostarczonego e-maila do kilku skrzynek.

Jeżeli dostawca nie pozwala tego wyłączyć, trzeba zmienić dostawcę albo uczciwie przebudować podstawę prawną i treść polityki. Nie wystarczy zmienić sam tekst, jeśli założeniem produktu jest brak takiego trackingu.

---

### BRAMKA D — P0: dokumenty prawne i rzeczywisty workflow zgłoszeń muszą mówić to samo

**Źródła:** audyty 09–10, #8, `resources/legal/regulamin.md`, `docs/legal/MODERATION_PLAYBOOK.md`.

Regulamin obiecuje zgłaszającemu informację o wyniku, a zwykłe `Zgłoś` jej obecnie nie dostarcza. Jednocześnie playbook ma pełniejszy model decyzji/odwołania niż publiczne zasady.

**Rekomendowany kierunek:** wdrożyć jeden lifecycle zgłoszenia:

`received → triage → reviewing → decided → reporter informed`

z osobnym profilem dla zwykłego zgłoszenia i formalnego notice, jeśli prawo wymaga innego zakresu informacji.

**Dowód zamknięcia:** test end-to-end zgłoszenia + teksty publiczne zgodne z faktycznym zachowaniem.

---

### BRAMKA E — P0/P1: moderacja krytycznych treści musi mieć realny priorytet

**Źródło:** audyt 09.

Playbook definiuje P0–P3, ale kolejka nie ma pola priorytetu i sortuje sprawy według świeżości. Starsze zgłoszenie krytyczne może więc zejść pod nowszy spam.

**Minimum przed publicznym UGC:**

- priorytet zapisany w danych,
- sortowanie najpierw po priorytecie/SLA,
- wyraźny stan `triage/reviewing`,
- deadline/review_due dla treści ukrytych „do wyjaśnienia”,
- historia wcześniejszych sankcji przy decyzji,
- jednoznaczna, zweryfikowana ścieżka eskalacji materiałów P0.

---

### BRAMKA F — P0/P1: direct Railway origin i host trust

**Źródło:** audyt 02 i 11.

`NormalizeForwardedFor` sam dokumentuje ryzyko wejścia bezpośrednio przez `*.up.railway.app`. Jeżeli origin jest osiągalny z internetu z pominięciem Cloudflare, klient może wpływać na zaufane nagłówki proxy. Brakuje też jawnej allowlisty hostów przy zaufaniu do `X-Forwarded-Host`.

**Dowód zamknięcia:** test z zewnętrznego internetu, że bezpośredni origin nie obsługuje zwykłego requestu użytkownika albo wymaga niepodrabialnego sygnału edge; do tego allowlista hostów aplikacji.

Nie uznawać tego za zamknięte na podstawie samej konfiguracji middleware.

---

### BRAMKA G — P0/P1: monitoring i post-deploy smoke muszą być udowodnione, nie tylko zapisane

**Źródła:** audyt 11, #33, `.github/workflows/deploy.yml`.

Aktualny workflow sam dokumentuje pomiar z 9 września: **249 przebiegów `Deploy`, wszystkie `skipped`**, przez co smoke test po wdrożeniu nie uruchomił się ani razu. Warunek został już zmieniony, ale skuteczność poprawki nie została jeszcze udowodniona realnym deployem.

Dodatkowo zewnętrzny monitor `/health` nie ma potwierdzonego działania.

**Dowód zamknięcia:** 

- realny deployment,
- powiązany run `Deploy`,
- job `Test dymny po deployu` = SUCCESS, nie skipped,
- testy na prawdziwym URL produkcji,
- niezależny uptime monitor,
- kontrolowany test awarii i zmierzony czas alarmu.

---

### BRAMKA H — P0: pełniejszy przegląd prawny i rejestr zgodności

**Źródło:** audyt 10, #8.

Przed szerszym ruchem trzeba zamknąć przynajmniej:

- przegląd regulaminu i polityki prywatności przez osobę kompetentną prawnie,
- inwentaryzację faktycznych DPA / warunków art. 28 u dostawców,
- ROPA/rejestr czynności przetwarzania,
- rzeczywiste okresy retencji backupów,
- procedurę DSA dostosowaną do faktycznie obowiązującego w Polsce stanu prawnego w dniu startu,
- zweryfikowaną ścieżkę dla najwyższego ryzyka moderacyjnego.

**Stan legislacyjny sprawdzony na 10.09.2026:** oficjalny komunikat UKE z 04.09.2026 wskazuje, że Sejm uchwalił nową ustawę wdrażającą DSA i przekazano ją Prezydentowi; nie znalazłem oficjalnego źródła potwierdzającego do chwili audytu jej podpisanie i ogłoszenie. Przed publicznym startem trzeba sprawdzić stan ponownie, a nie kopiować status z `COMPLIANCE.md`.

---

### BRAMKA I — P0: realne testy osób 50–75, szczególnie 60–75

**Źródło:** audyt 06, #15.

Jedna realna sesja z 63-letnią użytkowniczką wykryła problemy, których nie złapały automaty. Commit `e3cf6ab5` już poprawił część z nich: normalizację username, ekran login-link, rozróżnienie stanu poprawnego od błędu i przewijanie do błędów.

To jest argument **za kontynuowaniem sesji**, a nie za uznaniem UX za zamknięty.

Minimum przed szeroką betą: wykonać planowane sesje w trzech przedziałach wieku i przejść pełne zadania:

- założenie konta,
- pierwszy wpis ze zdjęciem,
- znalezienie osoby/treści,
- komentarz,
- zapis przepisu,
- powrót następnego dnia.

Mierzyć wykonanie bez pomocy moderatora testu, nie tylko deklarację „podoba mi się”.

---

### BRAMKA J — P0 dla kampanii: społeczność nie może być pusta

**Źródło:** audyt 07, #29.

Nie kierowałbym szerokiej kampanii nostalgicznej do pustego lub półpustego produktu. Użytkownik z Garnek.pl nie migruje po „funkcję dodawania zdjęć”; migruje po **ludzi, rytuał publikowania i reakcje**.

Przed kampanią:

- minimum 20–30 aktywnych, realnych osób,
- ok. 100–150 autentycznych wpisów,
- 7–10 dni historii aktywności,
- komentarze/reakcje pod treścią,
- kilka żywych tematów,
- szybka odpowiedź nowym osobom w pierwszych dniach.

Bez fałszywych kont i sztucznego engagementu.

---

## 5. Najważniejsze P1 po zamknięciu bramek P0

Poniższe rzeczy mają realną wartość, ale **nie powinny wypierać backupu, R2, prawa i testów użytkowników**.

### 5.1. PHPStan level 1

Repo ma bardzo silny test suite, ale analiza statyczna na level 1 jest niska. Sam `phpstan.neon` dokumentuje setki błędów na wyższych poziomach.

**Plan:** level 2 → 3 → 5 → 8, bez ogromnego baseline'u. Najpierw `app/Domain`, potem HTTP i modele.

### 5.2. Upload przez PHP

Do 6 × 15 MB może przejść przez request PHP. Przy słabym LTE i grupie 60+ jest to koszt dokładnie na głównej akcji produktu.

**Plan:** benchmark realnych telefonów; potem direct-to-R2/presigned upload albo kontrolowana redukcja danych po stronie klienta. Nie osłabiać prywatności oryginałów.

### 5.3. `failed_jobs` poczty bez operator alertu

Po wyczerpaniu prób wiadomość może trafić do `failed_jobs`, podczas gdy `/health` nadal jest zielony, a użytkownik zakłada sukces.

**Plan:** degraded health + alert przy finalnym failure + licznik dziennego limitu EmailLabs.

### 5.4. Supply chain hardening

- pin pełnego SHA dla GitHub Actions,
- pin konkretnej wersji `@railway/cli`,
- osobna polityka blokowania high/critical CVE dla normalnego release,
- lekki cykliczny audit zależności.

Zależności same w sobie są świeże; nie robić aktualizacji „dla numerka”.

### 5.5. Onboarding i pierwszy sukces

Po rejestracji użytkownik powinien możliwie szybko znaleźć się przy najważniejszej akcji: **dodaj pierwsze zdjęcie / zobacz ludzi**. Trzy kroki personalizacji powinny być jawnie opcjonalne.

### 5.6. Odkrywanie ludzi i tematów

Kuking ma już elementy przypominające fotofora/tags, ale discovery powinno być bardzo czytelne dla osób znających Garnek. Nie budować algorytmicznego rankingu. Wystarczy prosta, publiczna strona tematów i żywe kategorie.

---

## 6. Rzeczy, których teraz NIE robić

Audyt nie uzasadnia następujących inwestycji:

1. **Nie dzielić modularnego monolitu na mikroserwisy.** Obecnym ryzykiem jest produkt/operacje, nie skala architektury.
2. **Nie dodawać Redis tylko dlatego, że to social.** Najpierw zmierzone wąskie gardło.
3. **Nie budować rozbudowanego algorytmu feedu.** Chronologia i prostota są atutem dla tej grupy.
4. **Nie dodawać i18n przed znalezieniem product-market fit w polskiej społeczności.**
5. **Nie robić dużego upgrade'u Laravel/Vite bez konkretnej potrzeby.** Stack jest świeży.
6. **Nie wdrażać Sentry/PostHog jako substytutu uptime/restore.** Monitoring produktu nie naprawia braku kopii.
7. **Nie obniżać arbitralnie limitu 50 Mpx do 32–36 Mpx.** Po weryfikacji D-064 wcześniejszy alarm o 384 MB był błędny; 50 Mpx zmierzono na ok. 452 MB RSS przy limicie kontenera 1024 MB. Najpierw benchmark współbieżności.
8. **Nie uruchamiać szerokiej kampanii Garnek przed zasianiem realnej społeczności.** Acquisition nie naprawia pustego feedu.
9. **Nie dokładać funkcji tylko dlatego, że Garnek je miał.** Przenosić rytuały i prostotę, nie cały historyczny interfejs.

---

## 7. Kolejność napraw — plan minimalizujący zależności

### Faza 0 — zamrożenie źródła prawdy

Zanim operator zacznie klikać panele:

- przepisać `docs/OTWARCIE.md` jako bieżący snapshot,
- oznaczyć `WYKONANE / ZAIMPLEMENTOWANE-NIEZWERYFIKOWANE / PLAN / BLOKADA`,
- usunąć sprzeczny stary opis runnerów z `deploy.yml`,
- dodać pole dowodu: data + środowisko + metoda + wynik.

To zmniejsza ryzyko wykonania nieaktualnej instrukcji.

### Faza 1 — bezpieczeństwo danych

1. Uruchomić i zweryfikować R2 (#120).
2. Uruchomić offsite encrypted backup (#193).
3. Wykonać pełny restore drill (#9).
4. Ustalić retencję backupów i restore-after-deletion.
5. Zamknąć direct-origin bypass / Host allowlist.

### Faza 2 — poczta i obserwowalność

1. Wyłączyć i zweryfikować EmailLabs open tracking (#204).
2. Przetestować SPF/DKIM/DMARC na realnych polskich skrzynkach.
3. Dodać alert `failed_jobs` / limit dzienny (#234).
4. Udowodnić post-deploy smoke test.
5. Uruchomić niezależny `/health` monitor i wykonać kontrolowaną awarię.

### Faza 3 — prawo i moderacja

1. Ujednolicić regulamin, zasady i faktyczny reporter lifecycle.
2. Dodać priorytety/SLA do kolejki moderacji.
3. Dodać historię sankcji i prawdziwy `triage/reviewing`.
4. Domknąć DPA/ROPA/retencję i review prawnika (#8).
5. Ponownie sprawdzić polski stan wdrożenia DSA w dniu decyzji o starcie.

### Faza 4 — testy użytkowników

1. Kontynuować sesje 50–59 / 60–69 / 70+ (#15).
2. Po każdej sesji naprawiać tylko problemy obserwowane powtarzalnie lub blokujące zadanie.
3. Mierzyć completion rate i czas do pierwszego wpisu.
4. Nie zastępować tego kolejnym automatycznym audytem WCAG.

### Faza 5 — cold start

1. Zebrać pierwsze 20–30 aktywnych osób (#29).
2. Zbudować autentyczną historię feedu.
3. Dopiero potem uruchomić kampanię odwołującą się do pamięci Garnek.pl.
4. Pierwsze 72 h kampanii obsługiwać ręcznie jak launch community, nie kampanię display.

### Faza 6 — dług techniczny P1/P2

- PHPStan,
- direct upload,
- SEO/sitemap,
- PWA orientation,
- supply-chain SHA pins,
- paginacja listy blokowanych,
- refaktoryzacja grubych kontrolerów przy okazji zmian.

---

## 8. Kryteria „możemy otworzyć publiczną betę”

Nie używałbym subiektywnego „wygląda gotowe”. Otworzyłbym betę dopiero, gdy ta tabela jest cała zielona:

| Warunek | Dowód wymagany |
|---|---|
| R2 | test realnego bucketu + prywatność oryginału + delete/purge |
| Backup | automatyczny dump + alarm |
| Restore | pełny drill, RPO/RTO zapisane |
| E-mail | SPF/DKIM/DMARC + brak nieujawnionego tracking pixel + reset hasła działa |
| Monitoring | zewnętrzny health monitor + przećwiczony alarm |
| Deploy | co najmniej jeden realny post-deploy smoke = success |
| Direct origin | negatywny test obejścia Cloudflare |
| Prawo | review dokumentów + DPA/ROPA + aktualny status DSA |
| Moderacja | P0 trafia na górę + SLA + odwołanie + historia kar |
| Reporter lifecycle | regulamin i produkt zachowują się tak samo |
| 50–75 usability | zakończony plan #15 z raportem problemów |
| Community seed | min. 20–30 aktywnych osób przed szeroką kampanią |

Jeżeli którykolwiek z pierwszych 10 punktów jest `NIE WIEMY`, traktowałbym go jako **nieprzejście bramki**, nie jako sukces.

---

## 9. Co w projekcie jest wyjątkowo dobrze zrobione

Warto zachować obecny kierunek zamiast przebudowywać projekt po audycie:

- modularny monolit odpowiada faktycznemu ryzyku projektu,
- duży nacisk na regresję i testy negatywne,
- PostgreSQL jest testowany jako PostgreSQL, nie emulowany SQLite,
- security headers i CSP są potraktowane serio,
- model mediów rozdziela oryginał od publicznego wariantu,
- re-encoding usuwa EXIF/GPS przed publikacją wariantu,
- produkt nie używa „seniorowego” języka wobec grupy 50+,
- cele dotykowe/typografia są projektowane pod realną użyteczność,
- chronologiczny feed i brak zbędnej gamifikacji są spójne z grupą docelową,
- dokumentacja zapisuje decyzje i potrafi jawnie prostować błędne założenia,
- jedna sesja z realną 63-letnią osobą została od razu przekuta w konkretne poprawki i testy,
- nie ma technologicznego „resume-driven development”: repo wielokrotnie odrzuca niepotrzebne Redis/mikroserwisy/usługi.

Największą wartością repo nie jest liczba funkcji, lecz kultura: **„sprawdź, zmierz, zrób test ujemny, zapisz dlaczego”**. Trzeba tę samą kulturę przenieść z kodu do paneli produkcyjnych i procedur operacyjnych.

---

## 10. Delta `main` w trakcie audytu

Pełny audyt został zamrożony na `cee15a56`, aby wynik nie zmieniał się pod nogami podczas kilkudziesięciu sprawdzeń.

Przed zamknięciem sprawdziłem `main` ponownie. Doszedł dokładnie jeden commit:

`e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`

Zmienia on głównie:

- rejestrację,
- normalizację `username`,
- komunikaty login-link,
- kolory stanów formularza,
- focus/scroll do błędów,
- słownictwo „list” → „e-mail/wiadomość”,
- testy tych ścieżek.

### Wpływ na audyt

- **UX2** obniżono z P1 do P2: dwa pola nazwy nadal istnieją, ale techniczne tarcie zostało mocno zredukowane.
- **UX1** („Cztery pola i gotowe” → potem onboarding 1/3) nadal istnieje na aktualnym `main`.
- pozostałe bramki P0/P1 nie zostały dotknięte przez ten commit.

Dodatkowo podczas finalnej kontroli skorygowałem własny wcześniejszy błąd w audycie wydajności: repo już zawierało D-064 prostujące limit pamięci workera/kontenera. Finalny raport **nie powiela** fałszywego założenia 384 MB.

---

## 11. Korekty audytora wykonane przed finalnym ZIP

Dwa wnioski zostały świadomie poprawione po dodatkowej weryfikacji:

### Korekta 1 — pamięć obrazów

Pierwsza wersja audytu 04 oparła się na starym komentarzu `--memory=384` w `ProcessUploadedImage`. D-064 jednoznacznie mówi, że realny limit kontenera to 1024 MB, `PHP_WORKER_MEMORY_LIMIT` = 512M, a GD może alokować poza licznikiem PHP. Zmierzony RSS 50 Mpx to ok. 452 MB.

**Finalny wniosek:** 50 Mpx nie jest automatycznie za wysokim limitem; potrzebny benchmark współbieżności, nie arbitralne cięcie.

### Korekta 2 — brak GitHub Actions runu

Pierwsza wersja CI3 nadinterpretowała pusty wynik konektora `fetch_commit_workflow_runs`. Ten odczyt filtruje runy do zdarzeń `pull_request`, więc nie pozwala stwierdzić, że push-CI nie działało.

**Finalny wniosek:** tego zarzutu nie stawiam. Niezależny i dobrze udokumentowany problem pozostaje w `deploy.yml`: historycznie 249 przebiegów post-deploy smoke było `skipped` i aktualna poprawka wymaga realnego potwierdzenia.

Ta sekcja jest celowa: raport audytowy ma korygować własne błędy tak samo, jak repo koryguje swoje.

---

## 12. Ostateczna rekomendacja

**Nie dodawać teraz kolejnych dużych funkcji.** Produkt ma już wystarczająco dużo, żeby sprawdzić hipotezę rynku. Następny etap powinien być operacyjny i społecznościowy:

1. **R2 → backup → restore.**
2. **EmailLabs tracking + niezawodność poczty.**
3. **monitoring + naprawdę działający post-deploy smoke.**
4. **prawo + moderacja + reporter lifecycle.**
5. **pełny cykl testów 50–75.**
6. **20–30 realnych aktywnych osób.**
7. **dopiero wtedy kampania Garnek.pl.**

Jeżeli te siedem punktów zostanie udowodnionych, nie tylko „zaimplementowanych”, Kuking.pl będzie miał solidną podstawę do publicznego startu. Reszta obecnego długu może być naprawiana iteracyjnie po danych z realnego ruchu.
