# Rejestr umów powierzenia (DPA) — lista do odhaczenia

Stan: gałąź `robota/bramka-startowa`, od `main` = `534e0a51`, 20 września 2026.

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
wszystkich**. Od dnia, w którym zarejestruje się pierwsza osoba spoza
zamkniętej bety, to zdanie zaczyna być zobowiązaniem z terminem, a nie
notatką. Zdania tego pilnuje
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

**Siedem wierszy to podmioty przetwarzające.** Przetwarzają dane **na nasze
polecenie**, więc art. 28 ust. 3 RODO wymaga umowy powierzenia i to my
odpowiadamy za dobór takiego podmiotu.

**Ósmy wiersz — Meta — to osobny administrator.** Meta przetwarza dane
użytkownika Facebooka na własnych zasadach i własną odpowiedzialność;
nie robi tego na nasze zlecenie. **Umowa powierzenia jest tu niewłaściwym
instrumentem** — nie dlatego, że jej nie mamy, tylko dlatego, że nie ma
czego powierzać. Podpisanie DPA z Meta nie zamknęłoby tego punktu; zamyka
go poprawny **opis ról** w polityce prywatności i decyzja, czy ta droga
logowania w ogóle zostaje (`DECYZJE_WLASCICIELA_R1_R6_DPA.md`, sekcja
o umowach powierzenia, wariant B).

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
- **Data potwierdzenia:** ______________  **Kto:** ______________

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

- **Rola:** podmiot przetwarzający.
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

- **Rola:** podmiot przetwarzający.
- **Co do niego trafia:** adres otwieranej strony i adres odnośnika (oba bez
  części po znaku zapytania), rodzaj przeglądarki, czasy wczytania; kraj
  dolicza Cloudflare z połączenia. Bez ciasteczek i bez zapisu na urządzeniu
  (`app/Support/AnalitykaCloudflare.php`).
- **Czego szukać:** tego samego globalnego DPA konta Cloudflare.
- **Uwaga, która oszczędzi pytania:** to **ten sam dostawca**, nie kolejny.
  Cloudflare i tak widzi każde połączenie z serwisem, bo jest dostawcą sieci.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.5 OpenAI — wstępna ocena treści

- **Rola:** podmiot przetwarzający.
- **Co do niego trafia:** treść wpisu albo komentarza i pomniejszone,
  przekodowane zdjęcie (bez EXIF-u i GPS-u), bez danych wskazujących osobę
  (`app/Moderacja/KlientOpenAI.php`).
- **Czego szukać:** w ustawieniach organizacji na platform.openai.com —
  dokumentu o nazwie „Data Processing Addendum" (OpenAI udostępnia go do
  zawarcia z poziomu panelu) oraz ustawienia dotyczącego **wykorzystywania
  danych do trenowania modeli**. Domyślnie dane z API nie służą do trenowania,
  ale to jest ustawienie i deklaracja dostawcy, nie prawo natury —
  odnotuj, co tam faktycznie stoi.
- **Co jeszcze potwierdzić:** datę sprawdzenia wpisu OpenAI, L.L.C. na liście
  EU-US Data Privacy Framework oraz **deklarowany okres przechowywania**
  treści przekazanych do API. Bez tej liczby
  `REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.7 nie ma terminu usunięcia.
- **Wariant „nie robimy tego wcale" istnieje i ma cenę:** wyłączenie klucza
  zatrzymuje cały kanał bez zmiany kodu, ale odbiera wykrywanie nienawiści,
  przemocy i treści seksualnych — czyli tej klasy treści, dla której istnieje
  zero-tolerancja z `MODERATION_PLAYBOOK.md`. Pełny bilans:
  `DECYZJE_WLASCICIELA_R1_R6_DPA.md`.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.6 EmailLabs (Vercom S.A.) — poczta transakcyjna

- **Rola:** podmiot przetwarzający.
- **Co do niego trafia:** adres e-mail odbiorcy i treść listu; dodatkowo
  dostawca **sam rejestruje otwarcia** — moment, adres IP i program pocztowy.
- **Czego szukać:** to jedyny dostawca **polski**, więc umowa powierzenia
  będzie polska i najpewniej trzeba o nią poprosić opiekuna konta albo
  znaleźć ją w regulaminie usługi — nie licz na przycisk w panelu.
- **Co jeszcze zrobić w tym samym panelu — i to jest osobne zadanie:**
  **wyłączyć liczenie otwarć listów.** Wyłącznika nie ma w kodzie i mieć
  go tam nie można; polityka prywatności mówi dziś wprost, że dopóki tego
  nie wyłączymy, obrazek jedzie w każdym liście. To jest wiersz P1 z listy
  gotowości (#204, #713 A3), a nie kwestia umowy — ale wchodzi się po to
  do tego samego panelu, więc szkoda dwóch wizyt.
- **Co jeszcze odczytać:** jak długo EmailLabs trzyma logi wysyłek i otwarć.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.7 Google — logowanie kontem Google

- **Rola:** podmiot przetwarzający **w zakresie tej jednej operacji** —
  potwierdzenia, że konto Google należy do osoby, która się loguje.
- **Co do niego trafia:** potwierdzenie tożsamości, adres e-mail wraz
  z informacją o jego potwierdzeniu, imię
  (`app/Http/Controllers/Auth/GoogleLoginController.php`).
- **Czego szukać:** w Google Cloud Console dla projektu OAuth — warunków
  usługi wraz z **dodatkiem o przetwarzaniu danych** („Data Processing
  Terms" / „Cloud Data Processing Addendum") oraz stanu **weryfikacji
  aplikacji OAuth** i zakresu żądanych uprawnień. Zakres w kodzie jest
  minimalny; sprawdź, czy w konsoli nie stoi szerszy.
- **Co jeszcze potwierdzić:** datę sprawdzenia wpisu Google LLC na liście
  EU-US Data Privacy Framework.
- **Data potwierdzenia:** ______________  **Kto:** ______________

### 2.8 Meta — logowanie kontem Facebooka: TU UMOWA POWIERZENIA NIE JEST WŁAŚCIWYM INSTRUMENTEM

- **Rola:** **osobny administrator.** Meta Platforms Ireland Limited
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
     opisuje: mówi wprost, że Meta jest osobnym administratorem i że my nie
     przekazujemy tych danych poza EOG, bo kontrahentem jest spółka
     irlandzka. Pilnuje tego `PolitykaPrywatnosciWymieniaKazdaUslugeTest`.
  2. **W panelu aplikacji na developers.facebook.com** sprawdzić zakres
     żądanych uprawnień (w kodzie są dwa: podstawowe dane profilu i adres
     e-mail), stan weryfikacji aplikacji oraz działanie odwołania
     autoryzacji (`app/Http/Controllers/Auth/FacebookDeauthorizeController.php`).
  3. **Podjąć decyzję produktową**, czy ta droga logowania zostaje. To
     jedyny odbiorca, którego odjęcie **upraszcza obraz prawny**, bo usuwa
     jedynego osobnego administratora; zostaje Google, hasło i link.
     Bilans: `DECYZJE_WLASCICIELA_R1_R6_DPA.md`.
- **Data potwierdzenia:** ______________  **Kto:** ______________

---

## 3. Czego ta lista nie obejmuje

- **Umów, które nie są powierzeniem** — hosting domeny, bank, księgowość.
  Z kodu ich nie widać i nie jest to przedmiotem tego dokumentu.
- **Oceny, czy podpisana umowa spełnia art. 28 ust. 3 RODO.** Osiem
  odhaczonych wierszy znaczy „osiem paneli sprawdzonych", a nie „zgodne
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
