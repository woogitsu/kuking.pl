# Rejestr umów powierzenia (DPA) — lista do odhaczenia

Stan: gałąź `robota/bramka-startowa`, od `main` = `534e0a51`, 20 września 2026.
Przegląd roli dostawców wg analizy prawnej z 2.10.2026 (#2708, D-333): patrz
[`../prawo/OPINIA_AI_2026-10-02.md`](../prawo/OPINIA_AI_2026-10-02.md) §5 oraz
[`../prawo/ODPOWIEDZI_NA_OPINIE_2026-10-02.md`](../prawo/ODPOWIEDZI_NA_OPINIE_2026-10-02.md).
Analiza jest dziełem AI, nie podpisaną opinią adwokata ani radcy prawnego.

**Czym ten dokument jest.** Listą do odhaczenia. Po jednym wierszu na
realnego odbiorcę danych, a w wierszu: **co dokładnie trzeba u tego dostawcy
znaleźć i gdzie**. Tyle i nic więcej.

**Czym NIE jest.** Nie jest umową, nie jest oceną, czy istniejąca umowa
wystarcza, i nie jest potwierdzeniem, że cokolwiek podpisano. Ocena
wystarczalności należy do prawnika. **Nazwy dokumentów i miejsca w panelach
podane niżej to stan wiedzy modelu na wrzesień 2026 — dostawcy przenoszą
ustawienia i zmieniają nazwy dokumentów bez uprzedzenia. Jeśli czegoś nie ma
tam, gdzie napisano, to jest informacja o panelu, a nie dowód, że umowy nie
ma.**

**Dlaczego to w ogóle jest P0.** Polityka prywatności mówi dziś wprost:
*„Umów powierzenia przetwarzania danych z tymi dostawcami jeszcze nie mamy
podpisanych"* i obiecuje podpisanie ich **przed otwarciem rejestracji dla
wszystkich**. Analiza z 2.10.2026 (§5.4) koryguje ten termin: obowiązek
dotyczy **obecnego przetwarzania**, także adresów znajomych w becie, danych
administratorów i osób piszących przez formularz. Nie czekamy na „pierwszą
osobę spoza bety” i taka reguła nie obowiązuje. Zdania tego pilnuje
`DokumentyPrawneNieKlamiaTest::test_nie_twierdzimy_ze_mamy_umowy_powierzenia`
— dopóki nie ma umów, dokument nie może twierdzić, że są.

**Skąd lista odbiorców.** Z kodu (`COMPLIANCE.md` §7.2), potwierdzona wobec
zmiennych środowiskowych usługi produkcyjnej [pomiar cudzy: sesja prowadząca
floty, Railway, 20.09.2026 — skonfigurowane są `AWS_*`, `EMAILLABS_*`,
`FACEBOOK_CLIENT_*`, `GOOGLE_CLIENT_*`, `OPENAI_MODERATION_KEY`,
`CLOUDFLARE_ANALYTICS_TOKEN`, `TURNSTILE_*`; wartości zamaskowane].
**Żaden odbiorca nie jest martwy i żadnego nie brakuje** — więc lista ma
dokładnie tyle wierszy, ile paneli trzeba odwiedzić.

---

## 1. Dwie różne rzeczy na jednej liście — przeczytaj, zanim zaczniesz

**Siedem wierszy to podmioty przetwarzające** (Railway, trzy wiersze
Cloudflare o roli procesora, OpenAI, EmailLabs) albo, przy Turnstile, rola
mieszana. Przetwarzają dane **na nasze polecenie**, więc art. 28 ust. 3 RODO
wymaga umowy powierzenia i to my odpowiadamy za dobór takiego podmiotu.

**Dwa wiersze — Google (2.7) i Meta (2.8) — to odrębni administratorzy**
(analiza z 2.10.2026, §5.1 i §5.2). Przy zwykłym logowaniu każdy z nich
przetwarza dane użytkownika na własnych zasadach i własną odpowiedzialność,
nie na nasze zlecenie. **Umowa powierzenia jest tu niewłaściwym
instrumentem** — nie dlatego, że jej nie mamy, tylko dlatego, że nie ma
czego powierzać. Google nie jest u nas „Google Cloud”: logowanie przez
projekt w konsoli Google nie jest hostingiem w Google Cloud, więc **nie
szukamy DPA Google Cloud**. Podpisanie DPA nie zamknęłoby tych punktów;
zamyka je poprawny **opis ról** w polityce prywatności (z listą pól
odbieranych, ignorowanych i zapisywanych) oraz decyzja, czy ta droga
logowania zostaje (`DECYZJE_WLASCICIELA_R1_R6_DPA.md`, wariant B). Analiza
zastrzega, że kwalifikacja zależy od faktycznego wariantu (SDK, piksele,
analityka przed kliknięciem) i nie wyklucza z góry współadministrowania
etapu osadzenia (TSUE C-40/17 Fashion ID).

**Turnstile (2.3) ma rolę mieszaną** (§5.3 analizy): procesor dla usługi
świadczonej nam oraz odrębny administrator dla własnego celu Cloudflare
(ulepszanie wykrywania botów).

Kolumna „rola" niżej mówi, z którym przypadkiem masz do czynienia.
**To jest najważniejsza kolumna tej tabeli**, bo od niej zależy, czy w danym
panelu w ogóle szukasz umowy.

---

## 2. Lista do odhaczenia

### 2.1 Railway — hosting aplikacji i bazy danych

- **Rola:** podmiot przetwarzający.
- **Co do niego trafia:** wszystko — aplikacja, baza, dzienniki.
- **Czego szukać:** dokumentu o nazwie „Data Processing Agreement" albo
  „DPA" w ustawieniach konta lub przestrzeni roboczej, zwykle w sekcji
  prawnej/zgodności; oraz wykazu **podprocesorów** (Railway stoi na cudzej
  infrastrukturze, więc ten wykaz decyduje o tym, czy potrzebne są SCC).
- **Co jeszcze odczytać przy okazji:** **region usługi**. Deklarowana jest
  UE; z repozytorium tego nie widać i widać nie będzie. Ten sam wpis jest
  potrzebny do `REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §4.
- **Dzienniki aplikacji (stderr → zakładka Logs):** trafiają do Railway
  bez adresów e-mail, hashy haseł i komunikatów bazy z wartościami
  (`App\Logging\BezDanychOsobowychWLogu`, `docs/infra/MONITORING_BLEDOW.md`
  §2). **Okres przechowywania dzienników po stronie Railway: DO UZUPEŁNIENIA
  PRZEZ WŁAŚCICIELA** — widać go tylko w umowie albo w panelu (plan konta).
- **Dlaczego nie da się tego pominąć:** bez Railway nie ma serwisu. Ten
  odbiorca nie podlega wariantowi „ograniczyć liczbę odbiorców".
- **Data potwierdzenia:** 2.10.2026  **Kto:** właściciel (podpis), wpis: sesja koordynatora
- **ZAWARTA (2.10.2026).** *Data Processing Addendum* Railway Corporation do
  Terms of Service, podpisany przez obie strony przez DocuSign
  (koperta `04FC80D8-ABBE-843E-8176-BFDC686A49A0`, 11 stron). Strony: Customer
  Samsufi sp. z o.o. (podpisał prezes zarządu), Railway Corporation (Head of
  Operations). **Effective Date: 2026-10-02.** Podpisany PDF:
  [`umowy/railway-dpa-2026-10-02.pdf`](umowy/railway-dpa-2026-10-02.pdf). Właściciel
  2.10.2026 zdecydował, że plik jest w publicznym repozytorium, choć zawiera
  podpisy i nazwiska.
  - **Role (§2.1):** SAMSUFI jest administratorem, Railway procesorem.
  - **Transfery (§9):** EU SCC (decyzja 2021/914) są włączone do DPA
    i „uznane za podpisane”: moduł 2 (administrator → procesor), prawo i sąd
    Irlandii; dla UK aneks ICO. Eksporter danych: Samsufi sp. z o.o.,
    biuro@samsufi.pl.
  - **Podprocesorzy (§6):** ogólne upoważnienie; lista na trust.railway.com;
    zawiadomienie e-mailem co najmniej 10 dni przed nowym podprocesorem,
    sprzeciw w ciągu 10 dni.
  - **Naruszenie (§8):** Railway zawiadamia „without undue delay”.
  - **Audyt (§5):** raporty i certyfikaty, a w razie potrzeby audyt raz w roku,
    na koszt klienta.
  - **UWAGA — Exhibit A: „Sensitive Data or Special Categories of Data: None”.**
    Analiza z 2.10.2026 (§5.5) każe porównać to z faktycznym użyciem. Notatki
    w przepisach, planerze i spiżarni mogą zawierać informacje o zdrowiu
    (alergie, dieta po leczeniu). Do decyzji: ograniczyć funkcje, uzgodnić
    zakres z Railway albo przyjąć ryzyko. Zapis w #2708.
- **Wciąż do odczytania:** okres przechowywania dzienników Railway oraz
  retencja kopii i PITR (panel).

### 2.2 Cloudflare R2 — zdjęcia i paczki eksportu

- **Rola:** podmiot przetwarzający.
- **Co do niego trafia:** zdjęcia potraw i profilowe wraz z wariantami,
  paczki z danymi zamówione przez użytkowników (`config/filesystems.php`).
- **Czego szukać:** Cloudflare udostępnia **jeden globalny DPA dla całego
  konta** — przyjęcie go obejmuje także R2, Turnstile i Web Analytics,
  więc wiersze 2.2, 2.3 i 2.4 może zamknąć jeden dokument. Szukaj
  w ustawieniach konta, w sekcji prawnej/zgodności.
- **Co jeszcze odczytać przy okazji:** **lokalizację bucketu**.
  `AWS_DEFAULT_REGION` ma w kodzie domyślnie `auto`, co nie mówi nic
  o tym, gdzie leżą obiekty.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.3 Cloudflare Turnstile — ochrona siedmiu formularzy

- **Rola:** **mieszana.** Procesor w zakresie sygnałów przetwarzanych dla
  nas (ochrona formularzy) oraz **odrębny administrator** w zakresie własnego
  celu Cloudflare: ulepszanie technologii wykrywania botów (Turnstile
  Privacy Addendum z 18.06.2025, analiza §5.3). DPA obejmuje wyłącznie
  przetwarzanie na zlecenie; nie jest umową powierzenia dla operacji, w
  których Cloudflare samodzielnie ustala cel. Informacja dla użytkownika ma
  to odzwierciedlać, a niezbędność sygnałów dla bezpieczeństwa formularza
  oraz wyjątek PKE ocenia administrator, nie dostawca.
- **Co do niego trafia:** adres IP i techniczne cechy przeglądarki osoby
  wypełniającej formularz — także osoby **bez konta**, bo dwa z siedmiu
  formularzy są otwarte. Treść formularza i adres e-mail nie wychodzą.
- **Czego szukać:** tego samego globalnego DPA konta Cloudflare co wyżej.
- **Co jeszcze potwierdzić:** Cloudflare, Inc. jest spółką amerykańską —
  odnotuj **datę sprawdzenia wpisu na liście EU-US Data Privacy Framework**
  (`dataprivacyframework.gov`) i to, czy w DPA są standardowe klauzule
  umowne. Polityka prywatności już dziś twierdzi, że jedno i drugie ma
  miejsce; to twierdzenie potrzebuje daty.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.4 Cloudflare Web Analytics — statystyka odwiedzin

- **Rola:** podmiot przetwarzający; analiza z 2.10.2026 (§5.1) każe ocenić
  rolę według DPA i zakresu tej konkretnej usługi i nie zakładać, że wszystkie
  metadane służą jednemu celowi. Właściciel zdecydował 2.10.2026, że Web
  Analytics zostaje (D-333, ryzyko z PKE przyjęte).
- **Co do niego trafia:** adres otwieranej strony i adres odnośnika (oba bez
  części po znaku zapytania), rodzaj przeglądarki, czasy wczytania; kraj
  dolicza Cloudflare z połączenia. Bez ciasteczek i bez zapisu na urządzeniu
  (`app/Support/AnalitykaCloudflare.php`).
- **Czego szukać:** tego samego globalnego DPA konta Cloudflare.
- **Uwaga, która oszczędzi pytania:** to **ten sam dostawca**, nie kolejny.
  Cloudflare i tak widzi każde połączenie z serwisem, bo jest dostawcą sieci.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.5 OpenAI — wstępna ocena treści

- **Rola:** podmiot przetwarzający dla treści w zakresie objętym umową API;
  własne dane konta biznesowego odrębnie (analiza §5.1).
- **Strona umowy:** dla klientów z EOG publiczny dodatek DPA OpenAI (od
  1.01.2026) przewiduje co do zasady **OpenAI Ireland Ltd**, a nie
  „OpenAI, L.L.C.”. **Do potwierdzenia przez właściciela w panelu** (przyjęta
  umowa, dane organizacji, region). Irlandzka strona umowy nie dowodzi, że
  wszystkie operacje zostają w EOG.
- **Co do niego trafia:** treść wpisu albo komentarza i pomniejszone,
  przekodowane zdjęcie (bez EXIF-u i GPS-u), bez danych wskazujących osobę
  (`app/Moderacja/KlientOpenAI.php`).
- **Drugi cel tego samego odbiorcy — odczyt przepisu na żądanie (issue #2031,
  D-296, D-300 pkt 9):** zdjęcie kartki, tekst strony internetowej bez danych
  przepisu i obrazy stron skanu PDF, każde po osobnej zgodzie osoby
  (`REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.23). **DPA jest WARUNKIEM włączenia:**
  bez podpisanej umowy `OPENAI_IMPORT_KEY` (`kuking.import.model.klucz`)
  pozostaje pusty i żadne z tych trzech żądań nie wychodzi. Do odnotowania
  razem z pozostałymi: okres przechowywania danych wysłanych do API w tym
  celu (po jego uzupełnieniu podbić wersje informacji przy zgodzie —
  `InformacjaOdczytuAi::WERSJA` i `InformacjaTekstuZrodlaAi::WERSJA`).
- **Czego szukać:** w ustawieniach organizacji na platform.openai.com —
  dokumentu o nazwie „Data Processing Addendum" (OpenAI udostępnia go do
  zawarcia z poziomu panelu) oraz ustawienia dotyczącego **wykorzystywania
  danych do trenowania modeli**. Domyślnie dane z API nie służą do trenowania,
  ale to jest ustawienie i deklaracja dostawcy, nie prawo natury —
  odnotuj, co tam faktycznie stoi.
- **Co jeszcze potwierdzić:** datę sprawdzenia wpisu **właściwego podmiotu
  kontraktowego** (nie z pamięci: ustalić, czy chodzi o OpenAI Ireland Ltd,
  czy o OpenAI, L.L.C.) na liście EU-US Data Privacy Framework i które SCC
  oraz moduł wiążą strony, a także **okres przechowywania dla rzeczywistego
  endpointu `/v1/moderations`**. **Nie wpisujemy „30 dni”:** to ogólna
  informacja właściwa innym konfiguracjom API. Dokumentacja OpenAI dla
  `/v1/moderations` opisuje inne zasady, więc w rejestrze zapisujemy dopiero
  to, co potwierdzone dla tego endpointu, modelu i trybu organizacji
  (analiza §4, pyt. 9). Brak retencji w jednej kolumnie nie oznacza braku
  danych rozliczeniowych ani bezpieczeństwa. Odpowiednie pole w
  `REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.7 zostaje puste do czasu
  potwierdzenia.
- **Wariant „nie robimy tego wcale" istnieje i ma cenę:** wyłączenie klucza
  zatrzymuje cały kanał bez zmiany kodu, ale odbiera wykrywanie nienawiści,
  przemocy i treści seksualnych — czyli tej klasy treści, dla której istnieje
  zero-tolerancja z `MODERATION_PLAYBOOK.md`. Pełny bilans:
  `DECYZJE_WLASCICIELA_R1_R6_DPA.md`.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.6 EmailLabs (Vercom S.A.) — poczta transakcyjna

- **Rola:** podmiot przetwarzający.
- **Co do niego trafia:** adres e-mail odbiorcy i treść listu. Dostawca
  rejestrował też otwarcia (moment, adres IP, program pocztowy); **od
  2.10.2026 liczenie otwarć jest wyłączone, a historyczne dane o otwarciach
  usunął EmailLabs** (potwierdzenie właściciela, D-333). Brakuje jeszcze
  dowodu z dostarczonego MIME (P0-03).
- **Czego szukać:** to jedyny dostawca **polski**, więc umowa powierzenia
  będzie polska i najpewniej trzeba o nią poprosić opiekuna konta albo
  znaleźć ją w regulaminie usługi — nie licz na przycisk w panelu.
- **Liczenie otwarć listów (dawniej osobne zadanie P1, #204, #713 A3):
  ZAMKNIĘTE 2.10.2026.** Open Tracking wyłączony przez właściciela, dane
  historyczne usunięte przez EmailLabs (D-333). Historia wpisu zostaje.
- **Co jeszcze odczytać:** jak długo EmailLabs trzyma logi wysyłek i otwarć.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.7 Google — logowanie kontem Google: ODRĘBNY ADMINISTRATOR, nie procesor

- **Rola:** **odrębny administrator** dla własnych etapów i celów (potwierdzenie
  tożsamości, bezpieczeństwo konta Google). SAMSUFI odrębnie odpowiada za użycie
  otrzymanych danych do utworzenia lub połączenia konta Kuking (analiza §5.1,
  §5.2). **To nie jest powierzenie i nie szukamy DPA Google Cloud:** logowanie
  przez projekt OAuth w konsoli Google nie jest tożsame z hostingiem w Google
  Cloud.
- **Co do nas trafia** (`app/Http/Controllers/Auth/GoogleLoginController.php`):
  potwierdzenie tożsamości, adres e-mail wraz z informacją o jego
  potwierdzeniu, imię. Do uzupełnienia w polityce: lista pól **odbieranych,
  ignorowanych i zapisywanych**. Nie wolno twierdzić, że profil „nie jest
  przekazywany”, jeśli token zawiera np. adres zdjęcia, choć go nie
  używamy.
- **Czego szukać zamiast umowy:** w Google Cloud Console dla projektu OAuth —
  stanu **weryfikacji aplikacji OAuth** i zakresu żądanych uprawnień. Zakres w
  kodzie jest minimalny; sprawdź, czy w konsoli nie stoi szerszy. Sprawdź też
  warunki dokładnie tego produktu logowania i rzeczywisty przepływ.
- **Co jeszcze ocenić:** transfer do USA po stronie Google jest sprawą Google
  jako administratora; nie opisujemy go jako naszego przekazania. Datę wpisu
  Google LLC na liście EU-US Data Privacy Framework odnotuj informacyjnie.
- **Granica:** nie udostępniamy Google prywatnych zeszytów, planera ani listy
  zakupów; brak SDK, pikseli i analityki Google po stronie Kuking trzeba
  potwierdzić (zdanie z analizy §5.2 wymaga tego sprawdzenia).
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.8 Meta — logowanie kontem Facebooka: TU UMOWA POWIERZENIA NIE JEST WŁAŚCIWYM INSTRUMENTEM

- **Rola:** **odrębny administrator** (analiza §5.1, §5.2). Meta Platforms Ireland Limited
  przetwarza dane swojego użytkownika na własnych zasadach i własną
  odpowiedzialność, nie na nasze polecenie.
- **Co to znaczy praktycznie:** **nie szukaj w panelu Meta umowy
  powierzenia i nie odhaczaj tego wiersza jej brakiem.** Nie ma czego
  powierzać. Nie jest to też współadministrowanie z art. 26 RODO — role
  są rozdzielone, a nie wspólne; **tę kwalifikację musi potwierdzić
  prawnik**, bo to jedyny wiersz tej listy, w którym właściwy instrument
  prawny zależy od oceny, a nie od dokumentu w panelu.
- **Co zamiast tego trzeba zrobić:**
  1. **Sprawdzić, czy polityka prywatności opisuje role poprawnie.** Dziś
     opisuje: mówi wprost, że Meta jest osobnym administratorem. Analiza
     z 2.10.2026 (§5.2) każe **usunąć z polityki zdanie „nie przekazujemy poza
     EOG, bo kontrahentem jest spółka irlandzka”**: tożsamość kontrahenta nie
     rozstrzyga całego przepływu ani dostępu z innego państwa. Zmiana
     tekstu publicznego jest poza zakresem tego dokumentu (osobne zadanie).
     Pilnuje tego `PolitykaPrywatnosciWymieniaKazdaUslugeTest`.
  2. **W panelu aplikacji na developers.facebook.com** sprawdzić zakres
     żądanych uprawnień (w kodzie są dwa: podstawowe dane profilu i adres
     e-mail), stan weryfikacji aplikacji oraz działanie odwołania
     autoryzacji (`app/Http/Controllers/Auth/FacebookDeauthorizeController.php`).
  3. **Podjąć decyzję produktową**, czy ta droga logowania zostaje. To
     jedyny odbiorca, którego odjęcie **upraszcza obraz prawny**, bo usuwa
     jedynego osobnego administratora; zostaje Google, hasło i link.
     Bilans: `DECYZJE_WLASCICIELA_R1_R6_DPA.md`.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.9 Cloudflare — sieć, CDN i ochrona przed atakami (pośrednik całego ruchu)

- **Rola:** podmiot przetwarzający. Dopisane 30.09.2026 (#2282): wiersze
  2.2–2.4 wspominały o tej roli tylko uwagą „Cloudflare i tak widzi każde
  połączenie”, bez własnej pozycji.
- **Co do niego trafia:** **całe żądanie i cała odpowiedź** każdego wejścia —
  adres IP, nagłówki, ciasteczka, treść formularzy (także hasła przy
  logowaniu), strony po zalogowaniu. Cloudflare kończy TLS, więc widzi to
  w postaci jawnej (`REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.28).
- **Czego szukać:** tego samego globalnego DPA konta Cloudflare co w 2.2–2.4;
  przy okazji odczytaj, **jak długo Cloudflare trzyma dzienniki żądań** dla
  planu konta — rejestr czynności ma na to puste pole.
- **Co jeszcze potwierdzić:** wpis Cloudflare, Inc. na liście EU-US Data
  Privacy Framework i SCC w DPA — jak w 2.3.
- **Data potwierdzenia:** ______________  **Kto:** ______________

---

## 3. Czego ta lista nie obejmuje

- **Umów, które nie są powierzeniem** — hosting domeny, bank, księgowość.
  Z kodu ich nie widać i nie jest to przedmiotem tego dokumentu.
- **Oceny, czy podpisana umowa spełnia art. 28 ust. 3 RODO.** Dziewięć
  odhaczonych wierszy znaczy „dziewięć paneli sprawdzonych", a nie „zgodne
  z prawem".
- **Podprocesorów naszych podprocesorów.** Widać ich wyłącznie w wykazach
  dostawców; przy Railway to jest punkt, od którego zależy, czy potrzebne
  są SCC.
- **Rejestru podprocesorów publikowanego dla użytkowników** — to osobna
  decyzja właściciela, wiersz P2 na liście gotowości.

## 4. Gdy wiersze będą odhaczone

Wtedy — i dopiero wtedy — dwie rzeczy zmieniają się w innych dokumentach:

1. Z `resources/legal/polityka-prywatnosci.md` znika zdanie *„Umów
   powierzenia przetwarzania danych z tymi dostawcami jeszcze nie mamy
   podpisanych"*. **Wcześniej nie wolno go usunąć** — pilnuje tego test
   wymieniony na początku tego dokumentu, i słusznie.
2. Wiersz P0 o DPA w `COMPLIANCE.md` §7 dostaje datę przeglądu zamiast
   wskazania, gdzie szukać.
