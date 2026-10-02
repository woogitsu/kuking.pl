# Odpowiedzi na analizę prawną z 2 października 2026

Źródło: [`OPINIA_AI_2026-10-02.md`](OPINIA_AI_2026-10-02.md), czyli analiza
przekazana przez właściciela 2.10.2026. **Analizę przygotowała AI. Nie jest to
podpisana opinia adwokata ani radcy prawnego** (patrz jej nagłówek). W dokumentach
publicznych nie piszemy więc, że serwis „zweryfikował prawnik”. Pytania 1–18 to
lista z pakietu dla prawnika (Claude Doc „Kuking.pl — pakiet do przeglądu
prawnego”, 2.10.2026).

Ten plik zestawia każdą odpowiedź z tym, co jest dziś w kodzie i na produkcji
(stan sprawdzony 2.10.2026, gałąź integracji C `81f6ef73e`, zmienne usługi
`kuking.pl` w Railway), i mówi, co z tym robimy.

**Oznaczenia:**
- **ZGODNE**: stan już odpowiada zaleceniu.
- **DO ZROBIENIA**: praca dla agenta, bez decyzji właściciela.
- **DECYZJA**: wybór należy do właściciela.
- **TY**: czynność, którą może wykonać tylko właściciel (panel dostawcy, dane spółki, umowy).

## 0. Najważniejsze wnioski

1. **Zamknięta beta znanych, pełnoletnich osób jest dopuszczalna.** Warunek:
   to, co działa w becie, już teraz jest zgodne z prawem. Nie ma wyjątku
   „10–20 znajomych”; obowiązki powstają z faktycznego przetwarzania.
2. **Na produkcji działają dziś trzy integracje, które analiza każe wyłączyć do czasu
   wyjaśnienia:**
   - Cloudflare Web Analytics: zmienna `CLOUDFLARE_ANALYTICS_TOKEN` jest ustawiona (P0-04).
   - Moderacja OpenAI: zmienna `OPENAI_MODERATION_KEY` jest ustawiona. Brakuje
     potwierdzonej umowy i podstawy transferu (P0-01, P0-02).
   - Logowanie Google i Facebook: działa. Dziś jest opisane jako powierzenie; analiza
     zaleca opisać obu dostawców jako odrębnych administratorów (pytanie 5, §5.2).
     Tu wystarczy zmienić opis, wyłączać nie trzeba.

   **DECYZJA**: czy wyłączyć analitykę i moderację AI przed betą.
3. **Wersjonowanie polityki: analiza zaleca odwrotnie, niż ustalił właściciel.**
   - **Ustalenie z 2.10:** „drobna poprawka bez daty”; archiwum z 30.09 ma być
     identyczne z bieżącym plikiem.
   - **Zalecenie analizy:** nowa, rozpoznawalna rewizja „2026-10-02”. Archiwum ma
     odtwarzać tekst faktycznie opublikowany 30.09 i nie być poprawiane razem z
     bieżącym plikiem (pytanie 6, §6.4).

   **DECYZJA.** Do czasu decyzji nie wydajemy kolejnych zmian w archiwum z 30.09.
4. **Procedurę CSAM trzeba poprawić od razu.** Zgłoszenie z art. 18 DSA idzie
   **bezpośrednio do Policji lub prokuratury**. Dyżurnet.pl to dodatkowy kanał, a nie
   zamiennik. Poprawione w tym samym PR w
   [`CSAM_JEDNA_KARTKA.md`](../flota/CSAM_JEDNA_KARTKA.md) (krok 5).
5. **Umowy powierzenia (DPA):** brak osobnego PDF-u nie znaczy, że umowy nie ma, bo DPA
   bywa częścią warunków usługi. Trzeba jednak wykazać, która wersja wiąże spółkę.
   Termin naprawy to **teraz, a nie dzień publicznego otwarcia**. Regułę „obowiązek
   powstaje przy pierwszej osobie spoza bety” trzeba usunąć z dokumentów wewnętrznych. **TY**.

## Decyzje właściciela z 2.10.2026 (zapis w D-333)

1. **Cloudflare Web Analytics: zostaje.** To decyzja wbrew zaleceniu analizy, z
   przyjęciem ryzyka z PKE. Polityka i tak nie może twierdzić, że brak ciasteczek
   oznacza brak potrzeby zgody (pytanie 15).
2. **Moderacja OpenAI: zostaje.** Właściciel sprawdza 2.10.2026 w panelu OpenAI
   organizację, DPA i region (pytanie 9).
3. **Wersjonowanie polityki: bez zmian.** Drobne poprawki idą bez nowej daty, a
   archiwum z 30.09 jest identyczne z bieżącym plikiem. To decyzja wbrew zaleceniu
   analizy (pytanie 6, §6.4).
4. **Beta: tylko osoby pełnoletnie, znane właścicielowi** (pytanie 12). Regulamin
   zostaje przy 16+.

5. **EmailLabs: historyczne dane o otwarciach usunięte** (potwierdzenie właściciela, pytanie 16).
6. **Dane spółki:** sąd rejestrowy w Białymstoku, kapitał zakładowy 5 000,00 zł.
   Dopisane do regulaminu, punkt 1 (pytanie 14).
7. **SAMSUFI jest mikroprzedsiębiorstwem** (oświadczenie właściciela, pytanie 10).
   Przed publicznym startem 16+ zalecana jest jeszcze pisemna notatka z danymi
   (zatrudnienie, obrót lub suma bilansowa, powiązania).

Punkty oznaczone wyżej jako DECYZJA przy pytaniach 6, 9, 12 i 15 są tym rozstrzygnięte.
Otwarta zostaje data wejścia regulaminu dla bety (pytanie 4).

## 1. Odpowiedzi na pytania 1–18

### 1. Licencja na treści i kolaż

- **Analiza:** wystarcza wąska licencja funkcjonalna, a obecna klauzula regulaminu
  (§5) jest bliska tej potrzebie. **Nie przyjmować** szerokiego projektu
  `LICENCJA_UGC_PROJEKT.md` (druk, reklamy, zewnętrzne media) jako warunku konta.
  Zamiennik klauzuli jest w analizie, w pytaniu 1.
- **Kolaż na stronie głównej:**
  - mieści się w licencji, jeśli opiszemy go w regulaminie;
  - każde zdjęcie ma widoczny podpis autora i link do wpisu, nie tylko w `alt`;
  - reklama zewnętrzna wymaga osobnego, dobrowolnego upoważnienia do konkretnego materiału.
- **U nas:** regulamin ma licencję funkcjonalną. Kolaż istnieje. Nie sprawdzono,
  czy przy każdym zdjęciu widać autora i link.
- **Co robimy:**
  - **DO ZROBIENIA:** podmienić §5 na zamiennik z analizy, dopisać zestawienia i
    kolaż na stronie głównej, rozdzielić licencję od zostawiania tekstów po usunięciu konta;
  - **DO ZROBIENIA:** test kolażu (widoczny podpis i link przy każdym zdjęciu, na telefonie);
  - **ZGODNE:** nie promujemy cudzych treści poza serwisem, i tak zostaje.

### 2. Ograniczenie odpowiedzialności i słownik

- **Analiza:** §10 jest zbyt ogólny („nie odpowiadamy, chyba że wiedzieliśmy”).
  Zamiennik jest w analizie, w pytaniu 2. Ostrzeżenie „to tylko objaśnienie słowa”
  nie naprawi błędnej instrukcji. Przy hasłach wysokiego ryzyka (ogień, przetwory,
  grzyby, surowe produkty) potrzebne jest ostrzeżenie przy samym haśle. Ryzykowne
  hasła powinien przejrzeć specjalista od bezpieczeństwa żywności.
- **U nas:** paczka V (słownik #2343) już ma poprawki bezpieczeństwa uzgodnione
  z właścicielem:
  - usunięte „Pasteryzować”;
  - Marynować „w lodówce”;
  - surowe jajka przy sztywnej pianie;
  - dłuższe ostrzeżenie przy flambirowaniu.
- **Co robimy:**
  - **DO ZROBIENIA:** §10 na zamiennik z analizy;
  - **DO ZROBIENIA:** przelicznik porcji ma nie sugerować, że czasy i temperatury
    skalują się razem z porcjami; data z opakowania w spiżarni nie jest oceną
    przydatności (sprawdzić teksty);
  - **TY:** przegląd haseł słownika przez specjalistę od bezpieczeństwa żywności (opcjonalny, zalecany).

### 3. Reklamacje, odwołania, prawa konsumenta

- **Analiza:**
  - trzy ścieżki: reklamacja, zgłoszenie z DSA i odwołanie;
  - reklamacji nie wolno odrzucić dlatego, że dotyczy moderacji;
  - §14: 14 dni kalendarzowych, odpowiedź na trwałym nośniku, brak odpowiedzi w
    terminie oznacza uznanie reklamacji, prośba o uzupełnienie nie przesuwa terminu.
    Gotowe uzupełnienie jest w analizie, w pytaniu 3;
  - nie linkować platformy ODR (zamknięta rozporządzeniem 2024/3228);
  - dodać informację o zgodności usługi z umową i o odstąpieniu od umowy;
  - termin 7 dni roboczych na odwołanie to nasze własne zobowiązanie i trzeba go dotrzymywać.
- **U nas:**
  - **ZGODNE:** brak linku ODR w `resources/legal` (sprawdzone);
  - **ZGODNE:** odwołania, uzasadnienia i zgłoszenia bez logowania już działają.
- **Co robimy:**
  - **DO ZROBIENIA:** §14 i blok konsumencki w regulaminie;
  - **DO ZROBIENIA:** dokument wewnętrzny z „14 dni na odwołanie” poprawić na 7 dni
    roboczych zgodnie z regulaminem.

### 4. Od kiedy obowiązują dokumenty i jak ogłaszać zmiany

- **Analiza:**
  - dzień publicznego otwarcia nie może być pierwszą datą obowiązywania wobec osób,
    które już mają konta;
  - trzeba rozdzielić datę publikacji, datę stosowania do nowych umów i datę wejścia
    zmian wobec dotychczasowych użytkowników;
  - §11 wymaga konkretnych powodów zmian;
  - zmianę ogłaszamy e-mailem z pełnym tekstem co najmniej 14 dni przed wejściem,
    z prawem wypowiedzenia umowy;
  - samo „dalsze korzystanie oznacza akceptację” nie wystarcza;
  - gotowy rdzeń klauzuli jest w analizie, w pytaniu 4.
- **Co robimy:**
  - **DO ZROBIENIA:** §11 według analizy, a w nagłówku regulaminu trzy daty;
  - **DECYZJA:** data wejścia regulaminu dla bety. Na przykład dla nowych kont od
    dnia publikacji, a dla istniejących kont 14 dni po wysłaniu e-maila.

### 5. Podstawy prawne w polityce i rejestrze

- **Analiza:** gotowa mapa celów i podstaw jest w analizie, w pytaniu 5. Najważniejsze:
  - nie wszystko z regulaminu mieści się w lit. b;
  - obsługa zgłoszeń z DSA to lit. c;
  - moderacja zasad, logi i AI to lit. f z testem równowagi;
  - urodziny i tygodniowy list to lit. a;
  - dane o zdrowiu wymagają art. 9, sama lit. b nie wystarcza;
  - Google i Meta przy zwykłym logowaniu to odrębni administratorzy, nie nasi
    procesorzy, a rejestr umów odsyłający do DPA Google Cloud jest błędny.
- **Co robimy:**
  - **DO ZROBIENIA:** tabela celów w polityce i w rejestrze czynności według tej mapy;
  - **DO ZROBIENIA:** Google i Facebook opisane jako odrębni administratorzy w
    polityce i w `REJESTR_UMOW_POWIERZENIA.md`, z precyzyjną listą pól, które
    odbieramy, ignorujemy i zapisujemy.

### 6. Trzy nowe prywatne dane dopisane 2.10 bez nowej daty

- **Analiza:**
  - zapamiętane porcje, dzień gotowania i dopisek **nie wymagają nowej zgody**, wystarcza lit. b;
  - nie wymagają też 14 dni oczekiwania;
  - **nie należy ich jednak dopisywać pod starą datą 30.09.** Analiza zaleca rewizję
    „Aktualizacja: 2 października 2026 r., wersja 2026-10-02” i krótki komunikat
    przy funkcjach;
  - archiwum ma odtwarzać faktycznie opublikowany tekst, a nie być poprawiane razem z bieżącym.
- **Dodatkowo (ważne):** „po 24 godzinach” przy sprzątaniu raz na dobę znaczy w
  praktyce do około 48 godzin. Tak samo jest z dwugodzinnym plikiem importu.
  Trzeba albo częściej sprzątać, albo w polityce oddzielić „wygasa” od
  „najpóźniej usuwamy”.
- **U nas:**
  - polityka i jej archiwum z 30.09 są identyczne, zgodnie z decyzją właściciela z 2.10;
  - po decyzji doszły tam także: dopiski paczki V, poprawka śledzenia otwarć (#2703)
    i poprawka wiersza tygodniowego podsumowania (paczka V, `b0ecc0c42`).
- **Co robimy:**
  - **DECYZJA:** przejść na datowaną rewizję i przywrócić archiwum do tekstu z
    wdrożenia 30.09, czy zostać przy obecnym trybie;
  - **DO ZROBIENIA (po decyzji):** odtworzyć archiwum z commita wdrożonego 30.09,
    dodać notę „Wersja archiwalna” z analizy (§6.4) i stronę historii zmian;
  - **DO ZROBIENIA:** sprawdzić najgorszy przypadek przy „24 h” i „2 h”, czyli
    harmonogram sprzątania albo tekst polityki.

### 7. Potwierdzenia usunięcia konta (36 miesięcy)

- **Analiza:**
  - 36 miesięcy jest dopuszczalne dla **minimalnego** zapisu obsługi żądania:
    identyfikator, daty, zakres, sposób potwierdzenia tożsamości;
  - nie jest to termin z RODO;
  - automatyczne kasowanie jest zalecane;
  - zatrzymanie dowodów tylko dla konkretnej sprawy, z rejestrem: kto zatwierdził,
    co obejmuje, kiedy przegląd;
  - zdanie, że zdarzenia usunięcia zostają „na stałe”, trzeba usunąć;
  - bezterminowy dziennik akceptacji regulaminu wymaga tej samej oceny;
  - HMAC e-maila to nadal dana osobowa.
- **Co robimy:**
  - **DO ZROBIENIA:** retencja zapisu usunięcia 36 miesięcy z automatycznym
    kasowaniem i klauzulą z analizy;
  - **DO ZROBIENIA:** termin dla dziennika akceptacji regulaminu i zgód;
  - **DO ZROBIENIA:** rejestr zatrzymań (legal hold).

### 8. Logi, kopie i skutki usunięcia konta

- **Analiza:**
  - okresy kopii 6/27/89 dni dają najdłuższy horyzont **89 dni**, a nie sumę 122;
  - przy 30 dniach karencji przed usunięciem wychodzi do około 119 dni od żądania;
  - **nie obiecywać „wszystko znika w 30 dni”**;
  - 30-dniowa karencja jest dopuszczalna jako dobrowolna opcja, ale nie może
    utrudniać natychmiastowego żądania;
  - 12 miesięcy logów logowania trzeba uzasadnić;
  - HMAC IP to pseudonimizacja, a nie anonimizacja;
  - po odtworzeniu kopii najpierw stosujemy rejestr usunięć, a dopiero potem
    uruchamiamy serwis;
  - test usuwania obejmuje warianty zdjęć, CDN, eksporty, `failed_jobs`, sesje i tokeny.
- **U nas:** kopie Railway i PITR są włączone (D-333, 2.10). Rejestr usunięć
  stosowany przy odtworzeniu istnieje.
- **Co robimy:**
  - **TY:** odczytać z panelu Railway faktyczny harmonogram i retencję kopii oraz
    retencję PITR (zrzut ekranu wystarczy);
  - **DO ZROBIENIA:** polityka z trzema komunikatami: co znika z serwisu, co i kiedy
    znika z aktywnych systemów, co zostaje czasowo w kopii;
  - **DO ZROBIENIA:** uzasadnienie albo skrócenie 12 miesięcy logów;
  - **DO ZROBIENIA:** test „odtworzenie kopii, potem rejestr usunięć”;
  - **DO ZROBIENIA:** sprawdzić, czy `failed_jobs` nie trzyma treści resetu hasła po wygaśnięciu tokenu.

### 9. Moderacja przez OpenAI i transfery

- **Analiza:**
  - zdanie, że nie wysyłamy „niczego, co pozwoliłoby Cię wskazać”, jest za mocne;
    zamiennik jest w analizie, w pytaniu 9;
  - stroną umowy dla klientów z EOG jest **OpenAI Ireland Ltd**, a nie OpenAI, L.L.C.;
  - dla `/v1/moderations` **nie wpisywać „30 dni” retencji**;
  - „DPF + SCC” wymaga dowodu;
  - art. 22 RODO nie zachodzi, bo decyduje człowiek;
  - granicę publiczności sprawdzamy w chwili wysyłki, a nie przy dodaniu zadania do kolejki;
  - do czasu wyjaśnienia można moderować ręcznie.
- **U nas:** `OPENAI_MODERATION_KEY` jest ustawiony na produkcji, więc integracja działa.
- **Co robimy:**
  - **DECYZJA:** wyłączyć moderację AI do potwierdzenia umowy i podstawy transferu
    (analiza dopuszcza tryb ręczny), czy zostawić;
  - **TY:** w panelu OpenAI sprawdzić stronę umowy (organizacja, DPA) i region;
  - **DO ZROBIENIA:** poprawić zdanie w polityce i nazwę kontrahenta;
  - **DO ZROBIENIA:** test sprawdzania widoczności w chwili wysyłki do AI.

### 10. Zwolnienie mikro- i małego przedsiębiorstwa z części DSA

- **Analiza:**
  - status ustala zarząd na podstawie danych finansowych, zatrudnienia i powiązań
    (zalecenie 2003/361);
  - certyfikat UKE nie jest potrzebny;
  - liczba kont nie ma tu znaczenia;
  - nie pisać „zwolnienie z art. 19–28” bez objaśnienia;
  - art. 11–18 obowiązują zawsze; art. 15 ma osobne zwolnienie.
- **Co robimy:**
  - **TY:** krótka notatka kwalifikacyjna SAMSUFI, najlepiej z księgową: zatrudnienie,
    obrót lub suma bilansowa, powiązania, okresy;
  - **DO ZROBIENIA:** poprawić sformułowania o zwolnieniu w `docs/research/DSA-LUKI.md`
    i w dokumentach publicznych.

### 11. Potwierdzenie zgłoszenia i wiadomość o decyzji

- **Analiza:**
  - potwierdzenie e-mailem z numerem sprawy, gdy zgłaszający podał adres; treść
    jest w analizie, w pytaniu 11;
  - zgłaszający dostaje wynik i informację, jak go zakwestionować;
  - autor dostaje uzasadnienie z art. 17;
  - „zawsze, gdy to możliwe” w §8 trzeba usunąć;
  - tożsamości zgłaszającego nie ujawniamy rutynowo;
  - wiadomość nie powtarza zgłoszonego materiału.
- **Co robimy:**
  - **DO ZROBIENIA:** sprawdzić, czy wysyłamy potwierdzenie e-mailem z
    identyfikatorem; jeśli nie, dodać je z treścią z analizy;
  - **DO ZROBIENIA:** §8 regulaminu;
  - **DO ZROBIENIA:** przegląd szablonów decyzji (uwzględnienie i brak działania)
    pod kątem przykładów z analizy.

### 12. Deklaracja wieku 16+

- **Analiza:**
  - sam checkbox „mam 16 lat” nie jest pełną ochroną małoletnich;
  - nie trzeba jednak sprawdzać dowodów i **nie należy zbierać skanów**;
  - zalecenie na teraz: **zamknięta beta pełnoletnich, znanych osób**;
  - przed publicznym startem 16+ potrzebne są: notatka o statusie mikro/małego,
    ocena ryzyka i procedura reakcji na konto dziecka (ograniczyć widoczność profilu
    na czas wyjaśnienia);
  - projekt EU KIDS Act (17.09.2026) to na razie tylko propozycja.
- **Co robimy:**
  - **DECYZJA:** potwierdzić, że do bety zapraszamy wyłącznie osoby pełnoletnie (zalecane);
  - **DO ZROBIENIA przed publicznym 16+:** procedura „wiarygodny sygnał o wieku”,
    z ukryciem profilu i treści na czas wyjaśnienia (a nie samą blokadą pisania).

### 13. Polska ustawa o DSA i UKE

- **Analiza:**
  - ustawa z 4.09.2026 została podpisana 25.09.2026 i wchodzi w życie 30 dni od
    ogłoszenia; dokładnej daty nie ustalono;
  - DSA obowiązuje bezpośrednio od 17.02.2024;
  - licencja ani zgoda UKE nie jest potrzebna;
  - potrzebne są punkty kontaktowe z art. 11 i 12 z podanymi językami;
  - zawiadomienie o CSAM nie idzie wyłącznie do UKE.
- **Co robimy:**
  - **DO ZROBIENIA:** sprawdzić w regulaminie punkty kontaktowe z art. 11 i 12 oraz języki;
  - **DO ZROBIENIA:** dopisać możliwość skargi do Prezesa UKE bez nadmiernych obietnic;
  - **DO ZROBIENIA:** po ogłoszeniu ustawy wpisać pozycję Dziennika Ustaw i datę do
    dziennika decyzji.

### 14. UŚUDE, dane spółki i e-maile

- **Analiza:**
  - regulamin ma większość treści wymaganej przez UŚUDE;
  - brakuje danych z **art. 206 KSH**: sądu rejestrowego i kapitału zakładowego;
  - akceptacja regulaminu musi być osobna także przy wejściu przez Google lub Facebook;
  - dla informacji handlowej podstawą jest art. 398 PKE;
  - tygodniowy list to opt-in; gotowe brzmienie jest w analizie, w pytaniu 14;
  - wiadomości usługowe nie wymagają zgody, dopóki nie dokładamy do nich marketingu.
- **U nas:**
  - regulamin ma KRS, NIP i REGON, ale **nie ma sądu rejestrowego ani kapitału zakładowego**;
  - tygodniowy list i urodziny mają osobny wybór (ZGODNE).
- **Co robimy:**
  - **TY:** podać sąd rejestrowy i wysokość kapitału zakładowego SAMSUFI (bez tego
    nie wpisujemy niczego domyślnego);
  - **DO ZROBIENIA:** sprawdzić akceptację regulaminu na ścieżkach Google i Facebook.

### 15. Brak banera przy Cloudflare Web Analytics

- **Analiza:**
  - **brak ciasteczek to za mało**, bo art. 399 PKE obejmuje też odczyt informacji z urządzenia;
  - skrypt wysyła adres strony, referrer i cechy przeglądarki;
  - nie ma stanowiska UODO zatwierdzającego taki wariant;
  - zalecenie: **wyłączyć do czasu ustalenia podstawy** albo uruchamiać po zgodzie,
    ewentualnie przejść na minimalną analitykę po stronie serwera;
  - przycisk „Nie licz mnie” to sprzeciw, a nie zgoda;
  - ciasteczko „zapamiętaj mnie” na 400 dni przy każdym logowaniu wymaga osobnej oceny.
- **U nas:** `CLOUDFLARE_ANALYTICS_TOKEN` jest ustawiony na produkcji, więc skrypt
  jest wstawiany (D-092).
- **Co robimy:**
  - **DECYZJA:** wyłączyć Web Analytics przed betą (zalecane), wprowadzić baner
    zgody, albo przejść na liczenie po stronie serwera;
  - **DO ZROBIENIA:** usunąć z polityki argument „brak cookies = brak zgody” i zdanie
    „Cloudflare nie wie, że to Ty”;
  - **DO ZROBIENIA:** sprawdzić, ile trwa ciasteczko zapamiętania logowania i czy
    użytkownik świadomie je wybiera.

### 16. Piksel EmailLabs

- **Analiza:**
  - wyłączyć pomiar otwarć, bo sama informacja w polityce nie wystarcza;
  - odbiór: wysłać listy testowe każdą ścieżką i sprawdzić **dostarczony MIME**
    (brak piksela i przekierowań liczących kliknięcia), we wszystkich kontach i subkontach;
  - dane zebrane wcześniej ocenić i poprosić dostawcę o ich usunięcie;
  - to nie jest naruszenie z art. 33 RODO;
  - historii nie przepisywać tak, jakby piksela nigdy nie było.
- **U nas:**
  - liczenie otwarć jest wyłączone przez właściciela (D-333, 2.10);
  - polityka już to mówi (#2703 oraz poprawka wiersza tygodniowego podsumowania w paczce V);
  - śledzenie odnośników wyłącza nagłówek `X-TRACKING-OFF`.
- **Co robimy:**
  - **TY:** wysłać do siebie reset hasła, potwierdzenie adresu i tygodniowy list,
    a potem zapisać źródło (`.eml`) każdego; przeanalizuję je, by potwierdzić brak
    piksela (P0-03);
  - **TY:** poprosić EmailLabs o usunięcie historycznych danych o otwarciach;
  - **DO ZROBIENIA:** w `DO_WERYFIKACJI_PRAWNEJ.md` (R-16) i w `COMPLIANCE.md`
    oznaczyć sprawę jako zamkniętą z datą, bez kasowania historii.

### 17. CSAM i poważne przestępstwa

- **Analiza:**
  - **nie zatwierdza** ścieżki „Dyżurnet, a Policja tylko przy trwającym
    zagrożeniu”; art. 18 DSA wymaga zawiadomienia **Policji lub prokuratury** bez
    zbędnej zwłoki;
  - Dyżurnet.pl to kanał dodatkowy;
  - zagrożenie życia w tej chwili: 112;
  - plików nie przesyłamy e-mailem, Discordem ani do AI i nie robimy zrzutów;
  - sam `deleted_at` nie jest kwarantanną dowodową;
  - **nie przechowujemy materiału 36 miesięcy**;
  - przed startem próba na nieszkodliwym pliku: wszystkie adresy, miniatury, CDN,
    kopia zapasowa;
  - panel musi być wdrożony i sprawdzony, a nie tylko zapowiedziany.
- **U nas:** ścieżka CSAM jest w paczce V, której jeszcze nie ma na produkcji.
  Karta [`CSAM_JEDNA_KARTKA.md`](../flota/CSAM_JEDNA_KARTKA.md) w kroku 5 mówiła
  „Dyżurnet.pl, oraz/lub Policja”.
- **Co robimy:**
  - **ZROBIONE w tym PR:** krok 5 karty: najpierw Policja lub prokuratura (art. 18
    DSA), dodatkowo Dyżurnet.pl, 112 przy bezpośrednim zagrożeniu, i czego nie robić z plikami;
  - **DO ZROBIENIA:** przegląd `MODERATION_PLAYBOOK.md` i panelu z paczki V pod
    kątem sześciu etapów z analizy oraz retencji materiału (bez 36 miesięcy);
  - **TY + DO ZROBIENIA:** próba na nieszkodliwym pliku po wdrożeniu paczki V;
  - **TY:** osoba zastępcza i polski prawnik karny do trybu dowodowego (zalecane przed publicznym startem).

### 18. Udostępnianie prywatnego przepisu jednej osobie

- **Analiza:**
  - nie wymaga osobnej zgody RODO;
  - musi być opisane przed uruchomieniem;
  - zakres jest pokazany przed udostępnieniem;
  - bez prywatnych dopisków, porcji, planera i notatek;
  - cofnięcie działa przez autoryzację przy każdym wejściu, a losowy URL nie jest uprawnieniem;
  - brak pozostałości w CDN po cofnięciu;
  - udostępnienie nie zmienia treści w publiczną, więc treść nie idzie do OpenAI;
  - rejestr zaproszeń ma termin.
- **U nas:** funkcja jest w budowie (#2650).
- **Co robimy:**
  - **ZROBIONE:** wymagania 1–7 z analizy przekazane agentowi budującemu #2650
    (2.10.2026); każde ma mieć test.

## 2. Bramki P0 z analizy (§7.2) a nasz stan

| ID | Warunek | Stan 2.10.2026 | Kto |
|---|---|---|---|
| P0-01 | DPA i role aktywnych dostawców | Nie wykazano przyjętych wersji DPA (B8 w `DROGA_DO_BETY`) | TY |
| P0-02 | Podstawy transferów | Brak mapy transferów z dowodami | TY + agent (mapa) |
| P0-03 | Brak trackingu poczty | Wyłączone 2.10. Brak dowodu z dostarczonego MIME | TY (`.eml`) |
| P0-04 | Analityka zgodna z PKE | Web Analytics **działa**, bez zgody | DECYZJA |
| P0-05 | Wąska licencja i podpisy przy kolażu | Licencja do podmiany. Podpisy w kolażu niesprawdzone | agent |
| P0-06 | Rzetelne dokumenty i historia wersji | Archiwum 30.09 identyczne z bieżącym | DECYZJA, potem agent |
| P0-07 | Usuwanie, retencje i kopie zgodne z opisem | Kopie i PITR włączone. Opis w polityce do przepisania | TY (odczyt) + agent |
| P0-08 | Art. 16, 17, odwołania | Mechanizmy są. Szablony i §8 do przeglądu | agent |
| P0-09 | Art. 18 i niedostępność plików | Karta poprawiona tutaj. Panel w paczce V. Próba po wdrożeniu | agent + TY |
| P0-10 | Małoletni i status mikro/małego | Brak notatki. Beta dla pełnoletnich | TY + DECYZJA |
| P0-11 | Relacja konsumencka | §10, §11, §14 i blok konsumencki do zrobienia | agent |
| P0-12 | Dostęp i bezpieczeństwo | Jedna osoba z dostępem. Brak spisanych upoważnień | TY |

Zasada z analizy: „wyłączono” znaczy, że faktycznie nic się nie dzieje (brak
skryptu, zadania, piksela). Samo ukrycie przycisku tego nie spełnia.
