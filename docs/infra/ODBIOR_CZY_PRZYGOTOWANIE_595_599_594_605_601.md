# Przygotowanie a odbiór — pięć pozycji infrastruktury

Data: 20.09.2026. Stanowisko `gpt-odbior-czy-przygotowanie`, gałąź
`gpt/odbior-czy-przygotowanie`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.

**Wynik: dla pięciu pozycji nie potwierdzono pomylenia przygotowania
z odbiorem produkcyjnym w sprawdzonych dokumentach. Nie ma tu pomyłki,
dokumentacja jest ostrożna.** Nie znaleziono zdania kwalifikującego się do
nakazanej korekty „przed → po”; nie zmieniono źródeł na siłę.

Jedno ważne uzupełnienie: określenie #605 jako wyłącznie przygotowania
pasuje do pakietu z 18 września w badanym HEAD, ale nie do wszystkich
późniejszych materiałów floty. Istnieje już **lokalny wynik rampy z 20 IX**.
Nie jest pomiarem produkcji ani wynikiem docelowego podziału usług.

**Żaden z pięciu odbiorów produkcyjnych nie został wykonany w tym zadaniu.**
Nie sprawdzano nawet publicznego `/health`. Brak dowodu w przeglądanym
materiale nie jest dowodem, że usługa obecnie nie działa.

## 1. Co sprawdziłem sam i granice przeglądu

- Przed zmianami: czyste drzewo, wskazany HEAD. Kanoniczne repozytorium
  i stanowisko miały ten sam HEAD oraz blob `docs/DECISIONS.md`:
  `e3ed8673f88cf639fa0a288e5e05105e34db7979`. W kanonicznym katalogu
  wykonano tylko odczyty gita. Nie odbudowywano zdrowego worktree.
- Własny odczyt sześciu zgłoszeń przez `gh issue view NUMER --repo
  woogitsu/kuking.pl`: **wszystkie OPEN**. Para #594/#193 jest jedną pozycją
  tej listy. Stan, daty aktualizacji i adresy komentarzy zapisano w
  [metryce zgłoszeń](evidence/odbior64/zgłoszenia.json).
- Przeszukano Markdown w `docs/infra/` stanowisk floty oraz `STAN_SESJI*.md`
  po numerach i nazwach tematów. Trafiono **2185 plików, 45 różnych wersji
  treści** po SHA-256. [Manifest zakresu](evidence/odbior64/zakres.json)
  podaje wzorzec, czas, reprezentanta i liczbę kopii każdej wersji.
  To skan konkretnych fraz i przegląd kontekstu trafień, nie dowód
  kompletności wszystkich możliwych parafraz ani plików spoza zakresu.
- Odczytano plan `gpt-ci-architektura/docs/infra/PLAN_TECHNICZNY_614.md`
  i `_wspolne/STAN_SESJI_CZESC4.md`; sprawdzono także odpowiednie fragmenty
  STAN_SESJI, części 5 i 7, starsze przekazania operacyjne oraz raporty
  stanowisk wymienione w tabeli niżej. Katalog bazowy ścieżek floty:
  `C:\Users\matma\Documents\kuking-flota`.
- W `DECISIONS.md` sprawdzono trafienia tematyczne, zwłaszcza D-043,
  D-049, D-143 i D-192. „Status: obowiązuje” oznacza obowiązującą decyzję,
  nie zakończoną operację. Datowanych zdań „dziś zero kopii” nie używam
  jako aktualnego odczytu panelu.
- Samodzielnie potwierdzono przez `git merge-base --is-ancestor`, że
  merge #625 `8b4528a049e9100ccfb9c45877c58f8843c2eedd` jest w badanym HEAD.
  Historia podaje 16.09.2026, 18:33:59 +02:00. Odczyt joba potwierdza
  `read($original)` przed pętlą, a w pętli ramkę nad natywną bitmapą.
- Powtórzono lokalny test istniejącej implementacji na PostgreSQL
  `127.0.0.1:55439`, baza `kuking_flota_gpt-odbior-czy-przygotowanie`,
  właściciel używany przez skrypt floty `kuking`. Wyniki w §5.

Numeracja wierszy niżej dotyczy źródeł odczytanych przed dodaniem raportu.
Pomiarów historycznych nie wykonałem ponownie: **[pomiar cudzy: wskazany
plik albo komentarz issue]**. Sam odczyt pliku i sprawdzenie jego liczb
nie zmienia autorstwa pomiaru.

## 2. Lista z dowodem dla każdej pozycji

| Pozycja | Konkretne źródło i cytat rozstrzygający | Co jest potwierdzone i czego brakuje | Werdykt dokumentacyjny |
|---|---|---|---|
| [#595](https://github.com/woogitsu/kuking.pl/issues/595), podział usług | `gpt-ci-architektura/docs/infra/PLAN_TECHNICZNY_614.md`, wiersz 42: „Flaga konfiguracji nie dowodzi zastosowania”. `gpt-rozbicie-uslug/docs/infra/ROZDZIELENIE_ROL_595_600.md`, wiersze 3–6: „Nie wykonano planu przeciw Railway, apply, wdrożenia, zmiany zmiennych ani zwiększenia liczby procesów na produkcji.” | Własny odczyt `.railway/railway.ts:107–120`: flaga true ma jawne sprostowanie o stanie docelowym. Raport podziału zawiera procedurę i lokalne próby; nie ma w nim aktualnego planu ani odebranych trzech usług. | **Przygotowanie, nie odbiór. Nie ma tu pomyłki.** |
| [#599](https://github.com/woogitsu/kuking.pl/issues/599), monitoring/harmonogram | `MONITORING_BLEDOW.md:615–619`, tabela pięciu warstw: konfiguracja „brak”, wywołanie „działa”, odebranie wiadomości „niesprawdzone na produkcji”. `gpt-monitoring/docs/infra/MONITORING_ODBIOR_2026_09_20.md:5`: „To jest pomiar lokalny i instrukcja wdrożenia, nie odbiór produkcji.” | Własny odczyt `routes/console.php:231–250`: połączenia o :25, kolejka co 15 min. Log `DONE` i test transportu nie dowodzą wiadomości u człowieka. Aktualne issue wymaga ponadto dashboardu, uptime, zasobów, kosztu i metryk HTTP/DB/kolejek. | **Część kodowa i historyczne wywołania, bez odbioru kanału i całego #599. Nie ma tu pomyłki.** |
| [#594](https://github.com/woogitsu/kuking.pl/issues/594) / [#193](https://github.com/woogitsu/kuking.pl/issues/193), kopia i DR | `KOPIE_I_ODTWORZENIE.md:99–109`: „Warstwa offsite MA JUŻ KOD, ale NIE MA JESZCZE DZIAŁAJĄCEGO SERWISU”; §5, wiersze 563–577: pusta tabela rzeczywistego ćwiczenia; §5.1 osobno opisuje próby deweloperskie. D-192: „Zielony przebieg znaczy «mechanizm kopii i odtworzenia działa», nie «dane są bezpieczne».” | Kod `docker/kopia/` i skrypt próby istnieją. **[pomiar cudzy: `gpt-dr-baza/docs/infra/evidence/dr594/RAPORT.md:1–4,106–110`]** lokalne 5 504 654 wiersze i etapy odtworzenia; jawny brak produkcyjnego RPO/RTO i offsite. Nie sprawdzałem liczby kopii w panelu. | **Lokalnie sprawdzony mechanizm, brak udokumentowanego odbioru produkcyjnej kopii. Nie ma tu pomyłki.** |
| [#605](https://github.com/woogitsu/kuking.pl/issues/605), obciążenie | `evidence/obciazenie605/PRZYGOTOWANIE.md:3–5`: „Rampy do nasycenia NIE ZDJĘTO. To jest pakiet przyrządu, nie pakiet wyników.” Nowsze `gpt-obciazenie/docs/infra/evidence/obciazenie605/2026-09-20-gpt/RAPORT.md:1–14,107–119`: „pomiar lokalny”, topologia all, bez R2/Cloudflare. | W HEAD są odrzucone/niewykonane próby z 18 IX. **[pomiar cudzy: nowszy RAPORT.md i `serie/seria-r030-p1.json`, `werdykt-r030-p1.json`]** rampa lokalna: 15/s bez błędów, przy 30/s 2595/3600 nieudanych = 72,1%, werdykt otoczenia CZYSTY. Sam odczytałem JSON: cel `http://127.0.0.1:8605`. Pełnej rampy nie powtórzono po poprawce próbnika CPU; raport wyłącza nieważne średnie workera z wniosków. | **Starszy pakiet: przygotowanie. Nowszy: częściowy pomiar lokalny. Żaden nie twierdzi, że odebrał produkcję. Nie ma tu pomyłki; nie pomijaj późniejszego wyniku.** |
| [#601](https://github.com/woogitsu/kuking.pl/issues/601), jedno dekodowanie | `ODBIOR_JEDNEGO_DEKODOWANIA_601.md:7`: „Werdykt: NIE ZAMYKAĆ”; tabela §2: wykonanie nowej ścieżki na produkcji „NIESPEŁNIONE”, stany oraz czas/pamięć zadania „NIESPRAWDZONE”. `gpt-odbior-wdrozen/docs/infra/ODBIOR_WDROZEN_568_601_2026_09_20.md:3`: „lokalne scenariusze działają; odbiór produkcji pozostaje otwarty”. | Merge #625 potwierdzony w historii; istniejący test sprawdzony ponownie lokalnie. **[pomiar cudzy: komentarz #601 `5701162773`]** deployment 6485687626 success potwierdza wdrożenie tamtego SHA, nie nowy upload. Brak własnego pomiaru produkcyjnego joba/R2/RSS. | **Kod scalony, lokalna regresja zielona, produkcyjny odbiór otwarty. Nie ma tu pomyłki.** |

### Zdania pozornie sprzeczne — kontekst i historia

1. `INFRA_DECISION.md:920–925` zaczyna punkt od „Zrobione 10 września
   2026 (issue #33) bez Sentry”, ale w tym samym zdaniu stawia warunek
   skonfigurowania `LOG_BLAD_WEBHOOK_URL`, a ramka §11 mówi, że bez tej
   zmiennej kanał jest martwy. To deklaracja implementacji starego sygnału
   `failed_jobs`, **nie dowód odebrania #599**. `git log --follow -p`
   wskazuje commit `d6ed2797b045aa5118b7f1cdf24a8c6ce44e0961`, PR #255,
   11.09.2026 00:35:32 +02:00 (tekst mówi o 10 IX). Nie kwalifikuję tego
   jako niezastrzeżonego odbioru. Stare zdanie o brakującym progu kolejki
   jest historyczne wobec nowszej czujki w MONITORING_BLEDOW — nie należy
   z niego wyprowadzać nowego zadania implementacyjnego.
2. `INFRA_DECISION.md:741–744` mówi „Zbudowana w issue #193”, ale wskazuje
   `docker/kopia/` oraz **listę czynności właściciela** w §7. Ramka §10
   odsyła po bieżący stan do KOPIE_I_ODTWORZENIE. To zbudowany mechanizm,
   nie potwierdzenie działającej kopii. Pusta tabela §5 rozstrzyga odbiór.
3. Tytuł „Odbiór #601” i „Data odbioru” nie oznaczają pozytywnego wyniku:
   pierwsza ocena to NIE ZAMYKAĆ. W issue odnotowano też automatyczne
   zamknięcie przez `Closes` po #625 i natychmiastowe przywrócenie do
   odbioru (komentarz `5701005766`). Bieżący stan OPEN odczytałem sam.
4. STAN_SESJI_CZESC4, punkt 6 sekcji #614, mówi właśnie o **czterech
   przygotowaniach**. Nie stwierdza znalezienia czterech fałszywych
   odbiorów. Zastrzeżenia pozostają także w częściach 5 i 7 oraz w
   sekcji `gpt/odbior-wdrozen` głównego STAN_SESJI.

Nie ma korekty zdania źródłowego, więc nie ma fikcyjnego zestawienia
„przed → po” ani daty powstania nieudowodnionego błędu. Historii commitów
lokalnych raportów innych stanowisk nie odtwarzałem; ich treść i SHA-256
są źródłem, a wymienione w nich SHA pozostają twierdzeniami autorów,
jeśli nie zostały osobno potwierdzone powyżej.

## 3. Karty odbioru dla właściciela — procedury NIEWYKONANE

Wspólny zapis: data i strefa czasu, operator, środowisko, SHA aktywnego
wdrożenia, identyfikator usługi/przebiegu, oczekiwany i rzeczywisty wynik,
pozostałe braki. Nie zapisuj sekretów, payloadów, adresów podpisanych R2
ani danych innych osób. Brak dostępu do panelu/konsoli = **nieodebrane**.

Kilka minut wystarczy na **sprawdzenie istniejącego dowodu**. Nie oznacza
to, że da się w kilka minut stworzyć monitoring, wykonać duży restore lub
wiarygodną rampę. Poniżej osobno zapisano, co zrobić przy braku dowodu.
Nie wykonuj destrukcyjnych prób na działającej produkcji. Baza nie wymaga
publicznego portu; użyj istniejącej prywatnej konsoli lub zatwierdzonej
wewnętrznej drogi dostępu. Żaden krok tej listy nie został tu uruchomiony.

### #595 — czy rzeczywiście istnieją trzy działające role

1. W panelu Railway wybierz właściwy projekt i `production`. Zapisz
   identyfikatory aktywnych usług/replik, ich SHA i polecenia startowe.
   Oczekiwane: **1 web, 1 worker, 1 scheduler**, zgodne wydanie;
   start odpowiednio `kuking-entrypoint web`, `worker`, `scheduler`.
   Stary `all` ma być zatrzymany. Sama nazwa usługi lub zielony deployment
   nie rozstrzygają roli; argument startowy ma znaczenie.
2. W prywatnej konsoli każdej usługi obejrzyj listę procesów (`ps -eo
   pid,args`, bez publikowania pełnego zrzutu). Web: HTTP bez `queue:work`
   i pętli harmonogramu; worker: `queue:work`, bez HTTP/schedulera;
   scheduler: pętla `schedule:run`, bez HTTP/workera. Porównaj efektywną
   bazę, cache i dyski między usługami bez wypisywania wartości sekretów.
3. Otwórz `https://kuking.pl/health`: zapisz kod i pola JSON, nie tylko 200.
   W logach workera wskaż zakończone nowe zadanie, w logach schedulera
   rzeczywisty termin zadania. Zwykły własny upload ma dojść do `ready`
   według karty #601. Zapisz p95/błędy i zajętość puli przed/po zmianie,
   ze wskazaniem okna oraz zatwierdzonego budżetu.
4. Odbiór pełny wymaga również wcześniejszej próby izolacji awarii i
   wycofania na stagingu oraz obserwacji nocnego cyklu. Nie zatrzymuj
   produkcyjnego workera tylko po to, aby wypełnić tę kartę.

**Gdy brak:** plan/staging/kontrolowane zastosowanie i koszt wymagają
operatora. Procedura przygotowawcza: plik floty
`gpt-rozbicie-uslug/docs/infra/ROZDZIELENIE_ROL_595_600.md`, §4–5.
Nie podnosimy replik przy istniejącym `all`. Ta karta nie zastępuje planu
zmian ani nie zatwierdza automatycznie opcji z cudzej niescalonej gałęzi.

### #599 — harmonogram, metryki i wiadomość to trzy różne dowody

1. W aktywnym środowisku potwierdź obecność konfiguracji webhooka bez
   odczytu jego wartości. W prywatnej konsoli właściwej usługi właściciel
   uruchamia `php artisan kuking:sprawdz-alarm --bez-wysylki`.
   Sukces dowodzi **tylko konfiguracji**.
2. Po uprzedzeniu własnego odbiorcy właściciel uruchamia raz
   `php artisan kuking:sprawdz-alarm`. Oczekiwane: potwierdzenie 2xx
   i **jedna wiadomość próbna z tym samym znacznikiem czasu na właściwym
   kanale**, zobaczona przez wskazaną osobę. Zachowaj znacznik i potwierdzenie
   odbiorcy. Nie zaniżaj progów produkcji i nie wywołuj prawdziwej awarii.
3. W logach okna obejmującego :25 znajdź `kuking:budzet-polaczen` z liczbami
   `stan`, `zajete_serwer`, `dostepne`, `max_connections`. Dla kolejki
   znajdź `kuking:sprawdz-kolejke` z `oczekujace`, `zaleglosc_sekundy`,
   `nieudane_w_oknie`. W badanym HEAD terminy to :25 co godzinę oraz
   co 15 minut. Wymagaj szeregu kolejnych terminów, nie samego `DONE`.
4. W panelu zewnętrznego monitora sprawdź cele `https://kuking.pl/`
   i `https://kuking.pl/health`, ostatnią próbę, regułę oceny treści,
   kanał i odbiorcę. `HTTP 200` z `status=degraded` nie jest pełnym zdrowiem.
   Dowód alarmu i odwołania z kontrolowanej awarii **stagingu** powinien
   zawierać oba znaczniki czasu oraz potwierdzenie odbiorcy.
5. Osobno odbierz dashboard HTTP/DB/kolejek/Cloudflare i zasobów po rolach
   oraz alarm kosztu. Każdy musi mieć nazwę metryki, okno, próg, kontakt
   i wynik kontrolowanej próby. Jedna wiadomość próbna nie zamyka tych pól.

**Gdy brak:** bez konta monitora, kanału, odbiorcy i źródła szeregu nie da
się odebrać całego #599. Istniejąca procedura:
[MONITORING_BLEDOW.md §7](MONITORING_BLEDOW.md), a pełniejsza lista luk
w raporcie floty `gpt-monitoring/docs/infra/MONITORING_ODBIOR_2026_09_20.md`
§7–8. Nie przyjmuję propozycji progów ani cenników z niego za decyzję
właściciela lub aktualną ofertę dostawcy.

### #594/#193 — plik, automat i odtworzenie

1. Railway → produkcyjny serwis bazy → Backups: zapisz stan dostępnych
   warstw, ostatni sukces i okno PITR, jeśli dostępne. Nie przepisuj
   historycznego „tylko Pro” jako dzisiejszego faktu. Oddzielnie sprawdź
   serwis `kopia-bazy`: harmonogram, ostatni automatyczny przebieg,
   SHA/obraz oraz końcowy wpis `[kopia] GOTOWE: baza/kuking-…dump.cms`.
2. W prywatnym buckecie kopii znajdź ten sam zaszyfrowany obiekt i `.meta`.
   Rozmiar ma być dodatni i zgodny z `rozmiar_szyfrogramu_bajty`.
   Zapisz czas wykonania zrzutu, nie tylko czas dodania obiektu. Brak
   obiektu lub metadanych = brak tego dowodu; kod wyjścia 0 nie wystarcza.
3. Sprawdź protokół odtworzenia **tego obiektu pobranego z offsite**:
   osobny, izolowany cel; odszyfrowanie kluczem prywatnym odzyskanym
   niezależnie od Railway; SHA-256 zgodny z `sha256_jawnego`; odtworzenie
   z kodem 0 i spodziewanym schematem, ograniczeniami oraz migracjami.
   Liczniki i relacje porównaj ze spójną migawką źródłową, nie z żywą bazą
   zmieniającą się po zrzucie. Zweryfikuj konto, przepis i powiązane wpisy
   w aplikacji odtworzonej z wyłączoną pocztą i wykonywaniem kolejki.
4. Wypełnij tabelę §5 [KOPIE_I_ODTWORZENIE.md](KOPIE_I_ODTWORZENIE.md):
   identyfikator kopii, data, operator, rozmiar, wyniki kontroli, czasy
   pobrania/odszyfrowania/restore/weryfikacji. Oddziel czas restore bazy
   od pełnego odzyskania usługi. Wiek kopii to zaobserwowana ekspozycja
   na utratę danych, nie gwarantowany RPO ani decyzja o dopuszczalnym RPO.
5. #193 wymaga kolejnego udanego **automatycznego** przebiegu oraz
   odebranego alarmu błędu i braku świeżej kopii. Próby awarii wykonaj
   na odrębnej konfiguracji testowej; nie kasuj prawdziwych kopii i nie
   wyłączaj produkcyjnego harmonogramu. Sprawdź, czy dokumentowany test
   transportu dotyczy rzeczywistego kanału odbiorcy.

**Gdy brak:** potrzebne są autoryzowane środowisko odtworzenia, rzeczywista
kopia, niezależny klucz prywatny i operator. Nie pobieraj produkcyjnego
zrzutu na współdzielone stanowisko floty. Odzyskiwania całej usługi,
mediów i czasu przełączenia nie potwierdza lokalny `pg_restore`.
Kilkuminutowy odczyt protokołu jest możliwy; stworzenie brakującego protokołu
wymaga osobnego okna. Instrukcja wykonawcza to §4A/§7.4 dokumentu kopii,
z docelowym hostem/portem/nazwą bazy ustalonymi przed uruchomieniem.

### #605 — najpierw odbiór zakresu eksperymentu, nie atak na produkcję

1. Otwórz raport konkretnej serii i jej surowy JSON. Zapisz SHA aplikacji
   i przyrządu, topologię, limity zasobów, dane, korpus zdjęć i cel ruchu.
   `127.0.0.1:8605` w opisanej rampie potwierdza jej lokalny zakres.
   `CZYSTY` oznacza przejście bramki otoczenia, nie test produkcyjny.
2. W tabeli wyników wymagaj udanego stopnia, degradacji, błędów/timeoutów
   i próby powrotu; percentyli także z błędami, metryk HTTP/SQL/kolejek
   i CPU/RSS. Wskaż osobno pola nieważne po restarcie procesu. Nie
   zamieniaj wyniku 15/s na limit użytkowników ani na pojemność produkcji.
3. Jeśli odbiór ma objąć układ docelowy, porównaj zapis konfiguracji
   produkcji z autoryzowanym środowiskiem wydajnościowym: role, zasoby,
   wersje i ścieżka mediów. Brak równoważności oznacz jako ograniczenie.
   R2/podpisy/Cloudflare należy mierzyć na **oddzielnych testowych
   bucketach i domenie**, bez danych użytkowników, do nasycenia wyłącznie
   odizolowanego środowiska. Osobno potrzeba źródła rzeczywistego profilu
   ruchu do ekstrapolacji; w tym zadaniu go nie odczytano.

**Gdy brak:** nowa rampa wymaga środowiska, okna, SLO i budżetu;
nie uruchamiaj generatora przeciw `kuking.pl`. Aktualne #605 dopuszcza
pomiar przed podziałem z wyraźną etykietą `all`; nie wymaga niszczenia
produkcyjnej dostępności, żeby wynik był użyteczny. Lokalny raport jest
częściowym wynikiem eksperymentu, nie kolejnym pustym przygotowaniem.

### #601 — nowe zdjęcie, trzy warianty i stan gotowości

1. Zapisz SHA aktywnej usługi z workerem i potwierdź zawartość #625.
   Wybierz własne zdjęcie przetworzone po tym wdrożeniu; jeśli takiego
   nie ma, właściciel może użyć następnej zwykłej publikacji przez
   `https://kuking.pl/dodaj/zdjecie`. Stary gotowy obraz nie mierzy nowego joba.
2. Zanotuj UUID z adresu aplikacji. W tej samej uprawnionej przeglądarce
   sprawdź `https://kuking.pl/zdjecia/{uuid}/thumb`,
   `https://kuking.pl/zdjecia/{uuid}/feed` oraz
   `https://kuking.pl/zdjecia/{uuid}/large`. Wstaw własny UUID.
   Oczekiwane: WebP i prawidłowa orientacja. Dla poziomego zdjęcia 4:3
   o dłuższym boku co najmniej 1600 px: **320×240, 960×720, 1600×1200**.
   Dla innych proporcji/małych obrazów wymiary się różnią.
3. W istniejącej prywatnej konsoli bazy, wyłącznie dla tego UUID:

   ```sql
   SELECT id, status, metadata->>'processed_at' AS processed_at,
          metadata->'variants'->'thumb'->>'width' AS thumb_width,
          metadata->'variants'->'thumb'->>'height' AS thumb_height,
          metadata->'variants'->'feed'->>'width' AS feed_width,
          metadata->'variants'->'feed'->>'height' AS feed_height,
          metadata->'variants'->'large'->>'width' AS large_width,
          metadata->'variants'->'large'->>'height' AS large_height,
          metadata ? 'warianty_w_trakcie' AS variants_in_progress
   FROM media WHERE id = 'TU-UUID-WLASNEGO-ZDJECIA';
   ```

   Oczekiwane: **1 wiersz, ready**, czas przetworzenia po wdrożeniu
   i publikacji, sześć zgodnych wymiarów, `variants_in_progress=false`.
   Podgląd może być czwartym wariantem. Sam HTTP 200 nie wystarcza,
   bo aplikacja może zwrócić wariant zastępczy.
4. W logu workera dla tego okna sprawdź `App\Jobs\ProcessUploadedImage`
   RUNNING/DONE bez FAIL oraz brak komunikatów „Nie udało się przetworzyć
   zdjęcia”, „Przetwarzanie zdjęcia nie powiodło się do końca”,
   „Zdjęcie bez wygenerowanych wariantów” dotyczących medium.
   Standardowy wpis workera nie ma UUID: przy równoległych zadaniach
   sam czas/klasa nie pozwalają przypisać DONE do wybranego zdjęcia.
   Potwierdzenie `ready` i trzech rzeczywistych plików jest konieczne.
5. Zapisz oddzielnie, czy zmierzono czas i RSS **tego zadania**.
   Zbiorcza pamięć kontenera `all` nie jest RSS joba. Bez telemetrii
   przypisanej do procesu/zadania ten punkt pozostaje niezmierzony.
   Tak samo nie wyciągaj liczby dekodowań z logu DONE: produkcja nie
   ma tu licznika GD. Liczbę 3→1 potwierdza lokalny test tego samego kodu.

**Gdy brak:** nowe medium, dostęp właściciela, prywatny odczyt jego rekordu
oraz logi. Gdy odbiór wymaga też produkcyjnego CPU/RSS, potrzebne jest
osobne oprzyrządowanie/izolacja procesu; nie da się uzyskać tej liczby
z samego oglądania zdjęcia. Szczegóły wcześniejszej karty:
`gpt-odbior-wdrozen/docs/infra/ODBIOR_WDROZEN_568_601_2026_09_20.md:197–269`.

## 4. Decyzja właściciela, której raport nie podejmuje

| Wariant | Co można wtedy oznaczyć | Koszt i pozostająca niepewność |
|---|---|---|
| Zachować obecne kryteria | Część kodowa/lokalna osobno; odbiór operacyjny dopiero po dowodach z kart | Czas operatora, dostęp, okno wdrożenia/restore i koszty środowiska. Brakujące dowody pozostają jawne; #594/#193 i bramka restore w ROADMAP nadal obowiązują. |
| Jawnie dopuścić odbiór etapowy dla wybranych klas | Np. przyjąć lokalny eksperyment #605 i kod #601, zostawiając osobny otwarty odbiór integracji | Mniej oczekiwania na część kodową, ale trzeba nazwać właściciela i termin pozostałego odbioru. Nie wolno podpisać tego „produkcja sprawdzona”. Zmiana pełnych kryteriów wymaga decyzji i aktualizacji źródła. |
| Odłożyć odbiór bez zmiany kryteriów | „Przygotowane / lokalnie sprawdzone; odbiór odłożony” | Brak bieżącego kosztu operacji, za to dług niepewności co do kopii, alarmów, integracji i pojemności; brak podstaw do uznania bramki alfy za spełnioną. |

Nie dopisano asercji wybierającej którykolwiek wariant. Nie proponuję
zamknięcia #599 po jednej wiadomości ani #193 po samym istnieniu dumpa.

## 5. Kontrole i granice dostarczenia

Własny pomiar na nietkniętym kodzie: filtr
`JednoDekodowanieZdjeciaTest|PrzerwanePrzetwarzanieNieZostawiaSierotyTest|DokumentyMdNieMajaMartwychOdnosnikowTest`:
**6 PASS, 387 asercji, 5,09 s** — [surowy wynik](evidence/odbior64/test-przed.txt).
Regresja dekodowania ma kontrolę dodatnią: baseline musi wykonać trzy
prawdziwe dekodowania GD, badany job jedno; porównuje bajty wariantów
16 przypadków. To syntetyczne obrazy i dyski testowe, nie R2 ani produkcja.

Nie poprawiano kodu aplikacji ani testów. Nie ma nowego bugfixa, dla
którego należałoby wytwarzać czerwień. Historyczne kontrole ujemne z
raportów #601 pozostają **[pomiar cudzy: ODBIOR_JEDNEGO_DEKODOWANIA_601.md §3]**;
nie deklaruję ich powtórzenia.

`vendor/bin/pint`: **PASS, 1155 plików** w własnym runtime; brak zmian PHP
w stanowisku. Kontrola końcowa dokumentacji opisana w dołączonym
[wyniku końcowym](evidence/odbior64/kontrole-koncowe.txt).
Nie uruchamiano pełnej suity, builda ani benchmarku: zmiana obejmuje tylko
raport i dowody. `ProbaOdtworzeniaTest` nie był uruchamiany (dopuszczony
przez właściciela wyjątek ze współdzieloną bazą). Nie ma migracji.

Nie wykonano połączeń z produkcją, panelami Railway/Cloudflare, odczytów
produkcyjnej bazy, uploadów, wysyłek alarmów, konfiguracji portów ani
zmian innych stanowisk. Odczyt GitHub służył wyłącznie zgłoszeniom.
Brak push/PR/komentarzy/zamykania issues. SHA lokalnego commita podaje
meldunek końcowy. Wycofanie usuwa raport i jego dowody; nie zmienia danych
ani zachowania aplikacji.
