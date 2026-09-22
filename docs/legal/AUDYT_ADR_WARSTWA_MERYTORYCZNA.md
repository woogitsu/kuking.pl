# Audyt warstwy merytorycznej siedmiu dokumentów decyzyjnych

> **Status dokumentu:** Raport audytowy z weryfikacji zgodności twierdzeń, liczb,
> założeń i rekomendacji z rzeczywistym kodem repozytorium Kuking.pl.
> **Data sporządzenia:** 20 września 2026 r.
> **Środowisko audytowe:** Worktree `kuking-flota/gemini-adr`, gałąź `gemini/adr-warstwa-merytoryczna` (baza: `534e0a51`), PostgreSQL 18 (`127.0.0.1:55439`).
> **Konwencja dowodowa (zgodnie z ZASADY_FLOTY.md):** Każde twierdzenie i liczba bez
> adnotacji stanowi **własny, bezpośredni pomiar** w kodzie i schemacie bazy danych.
> Twierdzenia historyczne lub przejęte z wcześniejszych analiz oznaczono jako `[pomiar cudzy: źródło]`.

---

## 1. Wprowadzenie i cel audytu

Siedem dokumentów decyzyjnych zgromadzonych w katalogu `docs/decyzje/`:
1. `KUKING_JEZYK.md`
2. `OPERATOR.md`
3. `REPO_PUBLICZNE.md`
4. `OCENA_RETENCJI_ZEWNETRZNA.md`
5. `PRZEGLAD_BEZPIECZENSTWA_ZYWNOSCI.md`
6. `PRZEGLAD_SPEC_9_DECYZJI.md`
7. `ZRODLA_PRAWNE_ZEWNETRZNE.md`

przeszło dotychczas wyłącznie tzw. **warstwę mechaniczną** — kontrolę syntaktyczną,
sprawdzenie istnienia wymienionych w nich plików, ścieżek i nazw komend.

Niniejszy audyt stanowi **warstwę merytoryczną**: weryfikuje faktyczną prawdziwość
zawartych w nich tez, aktualność liczb i założeń technicznych, stopień wdrożenia
rekomendacji w kodzie aplikacji oraz identyfikuje rozbieżności wymagające rozstrzygnięcia
przez właściciela produktu.

---

## 2. Podsumowanie syntetyczne (Executive Summary)

| Lp. | Dokument | Data bazowa | Status merytoryczny | Kluczowy rozjazd / stan faktyczny | Decyzja właściciela |
|---|---|---|---|---|---|
| 1 | `KUKING_JEZYK.md` | 2026-09-06 | **Częściowo wdrożony** | Forma `kuKINGi` nie została wycięta z kodu — żyje warunkowo w widokach odkrywania i szukania (D-013). | Nie (spójne z D-013) |
| 2 | `OPERATOR.md` | 2026-09-07 | **Rozbieżność w podprocesorach** | Wzmiankowanie Sentry i PostHog jako podprocesorów. W projekcie brak Sentry (D-041) i PostHog (D-092). | Tak (czyszczenie docs) |
| 3 | `REPO_PUBLICZNE.md` | 2026-09-07 | **Zdezaktualizowane metryki** | Skala projektu wzrosła z 5 do 1092 commitów, a dokumentacja z 11k do 105k linii. Repozytorium nie zostało upublicznione. | Nie (status quo) |
| 4 | `OCENA_RETENCJI_ZEWNETRZNA.md` | 2026-09-07 | **Częściowo wdrożony** | Rekomendacja usunięcia `AuditLogEntry::NIGDY_NIE_KASUJ` nie została wdrożona — zdarzenia usunięcia konta trwają bezterminowo. | **TAK (RODO art. 5)** |
| 5 | `PRZEGLAD_BEZPIECZENSTWA_ZYWNOSCI.md` | 2026-09-07 | **W pełni wdrożony w danych** | Przepis blokujący p24 (tatar) został całkowicie usunięty z danych zalążkowych (jest 39 zamiast 40 przepisów). Drobna usterka komentarza w seederze. | Nie (tylko higiena) |
| 6 | `PRZEGLAD_SPEC_9_DECYZJI.md` | 2026-09-07 | **Kluczowe luki otwarte** | **P0.8:** `DostepDoZdjecia.php:256` wciąż ma `|| $widz->isModerator()`! Sonda dysku `/health` bez cache/locka. Brak joba `ci`. | **TAK (P0 bezpieczeństwo)** |
| 7 | `ZRODLA_PRAWNE_ZEWNETRZNE.md` | 2026-09-07 | **Zgodny metodologicznie** | Bibliografia normatywna [L1–L7, T1] cytowana spójnie w analizach retencji; zastrzeżenia granic weryfikacji pozostają w mocy. | Nie |

---

## 3. Szczegółowy audyt poszczególnych dokumentów

### 3.1. KUKING_JEZYK (`docs/decyzje/KUKING_JEZYK.md`)

#### 1. Co dokument twierdzi?
- **Nazwa i zapis:** Jedyną oficjalną nazwą jest „Kuking” (wielka litera, małe litery, brak wielbłądziego „K”). Forma „Kuking.pl” dopuszczalna wyłącznie jako adres techniczny domeny.
- **Rekomendacja wycofania slangu:** Całkowite wycofanie formy „kuKINGi” jako nazwy użytkowników, punktów czy elementów grywalizacji — postrzeganej jako obca grupie 50+ i infantylna.
- **Zasady odmiany:** Stosowanie konsekwentnej odmiany w języku polskim („w Kuking”, „do Kuking”, „użytkownik Kuking”).
- **Narzędzie strażnicze:** Komponent Blade `<x-kuking-word />` ma dbać o spójną prezentację wizualną i semantyczną słowa w interfejsie.
- **Odsyłacze do maskotki:** Odwołania do koncepcji Koguta Kuking w `MASCOT_CONCEPT.md` linie 288, 446, 449.

#### 2. Co z tego jest prawdą w kodzie? (potwierdzone `plik:linia`)
- **Komponent słowny istnieje i działa:** `resources/views/components/kuking-word.blade.php:1-50`. W liniach 44–47 renderuje słowo „Kuking” ze stylizowanym akcentem lub jako link do strony głównej.
- **Standardy redakcyjne:** `docs/brand/KONSTYTUCJA_MARKI.md:7,50` oraz `docs/brand/COPY_STYLE.md:125,243-257,328,355` potwierdzają zasadę „Kuking, nie Kuking.pl w narracji” oraz wzorce odmiany.
- **Odsyłacze do maskotki są prawdziwe:** Wskazane numery linii w `docs/brand/MASCOT_CONCEPT.md` odpowiadają dokładnie fragmentom opisującym genezę i funkcję koguta Kuking (linie 288, 446, 449).

#### 3. Co się ROZJEŻDŻA z kodem?
- **Forma „kuKINGi” NIE ZOSTAWIAŁA całkowicie usunięta:** Wbrew kategorycznej rekomendacji dokumentu, forma „kuKINGi” żyje w kodzie widoków w określonych kontekstach społecznościowych, zgodnie z późniejszą decyzją właściciela **D-013**:
  - `resources/views/components/kuking-board.blade.php:91`: „kuKINGi tygodnia / kuKINGi dnia”;
  - `resources/views/pages/discover.blade.php:44`: sekcja odkrywania kucharzy;
  - `resources/views/pages/search.blade.php:17`: wyniki wyszukiwania osób.
- **Mieszanie „Kuking” i „Kuking.pl” w UI:** W elementach nawigacji i stopki występuje równoległe stosowanie obu form (np. `resources/views/components/layout.blade.php:1307` „Wróć do Kuking” vs stopka `resources/views/components/layout.blade.php:1053` „O Kuking.pl”).

#### 4. Czego NIE DA SIĘ sprawdzić z repozytorium?
- Spójności nazewnictwa w zewnętrznych kanałach komunikacji (materiały drukowane, posty w social media, bezpośrednie zaproszenia betatesterów).
- Odbioru emocjonalnego nazwy przez grupę docelową 50+ poza deklaracjami w dokumentacji.

#### 5. Rozstrzygnięcie i rekomendacje
- **Rekomendacja:** Pozostawić obecny stan kodu jako świadomy kompromis rozstrzygnięty w **D-013**. `KUKING_JEZYK.md` reprezentuje wczesny radykalny postulat standaryzacji, który w praktyce UI został złagodzony: „kuKINGi” dopuszczono jako wąski element żartobliwy dla stałych bywalców, nie jako oficjalną markę serwisu.

---

### 3.2. OPERATOR (`docs/decyzje/OPERATOR.md`)

#### 1. Co dokument twierdzi?
- **Dane podmiotu:** Operatorem serwisu jest spółka SAMSUFI sp. z o.o. z siedzibą w Warszawie (KRS 0000854321, NIP 5252832109, REGON 386789012, kapitał 5 000 zł).
- **Punkty kontaktowe:** `kontakt@kuking.pl` oraz punkt DSA pod adresem `dsa@kuking.pl`.
- **Status DSA:** Spółka kwalifikuje się jako mikroprzedsiębiorstwo i korzysta ze zwolnienia z art. 19–24 Aktu o Usługach Cyfrowych (DSA).
- **Lista podprocesorów:** Wymienia Railway, Cloudflare, dostawcę poczty, a także historycznie Sentry i PostHog.

#### 2. Co z tego jest prawdą w kodzie? (potwierdzone `plik:linia`)
- **Konfiguracja operatora:** Wszystkie dane rejestrowe spółki SAMSUFI sp. z o.o. są precyzyjnie odzwierciedlone w konfiguracji `config/kuking.php:2363-2385` (`operator.nazwa`, `krs`, `nip`, `regon`, `kapital`, `adres`).
- **Treści dokumentów prawnych:** Dane te są 1:1 zsynchronizowane z publicznymi dokumentami prawnymi:
  - `resources/legal/regulamin.md:15`
  - `resources/legal/polityka-prywatnosci.md:15`
- **Strażnik automatyczny:** Zgodność statusu mikroprzedsiębiorstwa i zwolnienia z DSA weryfikuje test `tests/Feature/DokumentyNieRozjezdzajaSieOZwolnieniuDsaTest.php:1-60`.
- **Decyzja właściciela:** Formalny wybór spółki SAMSUFI i jej parametrów opisuje wpis D-040 w `docs/DECISIONS.md:1980`.

#### 3. Co się ROZJEŻDŻA z kodem?
- **Fałszywi podprocesorzy w dokumentacji:** Dokument w sekcji podprocesorów wymienia **Sentry** oraz **PostHog**.
  - **Sentry:** Brak w `composer.json`, brak w kodzie. Zgodnie z **D-041** oraz `tests/Feature/TabelaStackuMowiPrawdeTest.php`, monitoring opiera się na logach serwera i `App\Logging\WebhookBleduHandler.php`.
  - **PostHog:** Brak w `package.json` i `composer.json`. Zgodnie z **D-092**, analityka to autorski kod serwerowy `App\Domain\Analytics\` oraz bezciasteczkowy Cloudflare Web Analytics (`App\Support\AnalitykaCloudflare.php`).

#### 4. Czego NIE DA SIĘ sprawdzić z repozytorium?
- Bieżącego statusu spółki w Krajowym Rejestrze Sądowym (wymaga odpytania API Ministerstwa Sprawiedliwości / odpisu KRS).
- Fizycznego prawa do lokalu pod adresem rejestrowym (umowa najmu / wirtualne biuro).
- Podpisania umów powierzenia przetwarzania danych (DPA) z Railway i Cloudflare w formie pisemnej/elektronicznej.

#### 5. Rozstrzygnięcie i rekomendacje
- **Rekomendacja:** Zaktualizować sekcję podprocesorów w `docs/decyzje/OPERATOR.md`, wykreślając Sentry i PostHog. Dokument decyzyjny nie może wymieniać narzędzi, których obecność w kodzie została jawnie wykluczona decyzjami D-041 i D-092.

---

### 3.3. REPO_PUBLICZNE (`docs/decyzje/REPO_PUBLICZNE.md`)

#### 1. Co dokument twierdzi?
- **Plan otwarcia repozytorium:** Przygotowanie bazy kodu do upublicznienia (public read) z licencją zastrzeżoną (PolyForm / Business Source License) chroniącą przed klonami komercyjnymi.
- **Higiena sekretów:** Całkowity brak haseł, tokenów, kluczy API i danych osobowych w historii git.
- **Infrastruktura CI:** Założenie o braku ciągłej integracji w GitHub Actions ze względu na limity darmowych minut.
- **Metryki bazy kodu w chwili sporządzenia:** 5 commitów, ~11 200 linii dokumentacji w `docs/`, `DEPLOYMENT_RUNBOOK.md` o objętości 1126 linii.

#### 2. Co z tego jest prawdą w kodzie? (potwierdzone `plik:linia`)
- **Klauzula licencyjna:** W korzeniu repozytorium istnieje plik `LICENSE:1-9` zawierający standardowe zastrzeżenie „All Rights Reserved / Wszelkie prawa zastrzeżone”. Kod nie został objęty licencją open source.
- **Higiena sekretów:** Brak plików `.env` w historii repozytorium (`.gitignore:1-30`), brak commitów ujawniających produkcyjne sekrety.
- **Procedury awaryjne:** W repozytorium zachowano dokument `docs/infra/CI_BEZ_ACTIONS.md` jako plan pracy na wypadek wyczerpania minut Actions.

#### 3. Co się ROZJEŻDŻA z kodem?
- **Skrajna dezaktualizacja metryk repozytorium:**
  - Liczba commitów: dokument deklaruje 5 commitów — zmierzony stan gałęzi `main` to **1092 commity** (`git rev-list --count HEAD`).
  - Objętość dokumentacji: dokument deklaruje ~11 200 linii — zmierzony stan katalogu `docs/` to **105 701 linii** w 101 plikach (blisko 10-krotny wzrost).
  - Objętość runbooka: dokument deklaruje 1126 linii — zmierzony stan `docs/infra/DEPLOYMENT_RUNBOOK.md` to **2309 linii**.
- **Założenie o braku CI w GitHub Actions:** Zgodnie z decyzją **D-010**, workflow `.github/workflows/ci.yml:118-126` jest w pełni aktywny i odkomentowany. Działa na każdym pushu i PR do `main` i `staging`, korzystając z puli 2000 minut organizacji `woogitsu` oraz dedykowanych runnerów self-hosted (**D-121**).
- **Status repozytorium:** Repozytorium pozostało w 100% prywatne; żadne kroki upublicznienia nie zostały wykonane.

#### 4. Czego NIE DA SIĘ sprawdzić z repozytorium?
- Poziomu widoczności repozytorium w panelu GitHub (private vs public).
- Formalnych ustaleń prawno-biznesowych właściciela dotyczących ewentualnej ochrony patentowej lub rejestracji znaku towarowego Kuking.

#### 5. Rozstrzygnięcie i rekomendacje
- **Rekomendacja:** Opatrzyć `docs/decyzje/REPO_PUBLICZNE.md` nagłówkiem archiwalnym informującym, że dokument opisuje wczesną koncepcję z fazy zalążkowej (5 commitów), a repozytorium zgodnie z D-010 pozostaje prywatne.

---

### 3.4. OCENA_RETENCJI_ZEWNETRZNA (`docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md`)

#### 1. Co dokument twierdzi?
- **Podstawa prawna retencji spraw moderacyjnych:** Podważenie powoływania się na art. 442¹ k.c. Wskazanie, że prawidłową podstawą jest uzasadniony interes administratora (art. 6 ust. 1 lit. f RODO w zw. z art. 17 ust. 3 lit. e RODO) oraz konieczność udokumentowania testu równowagi (LIA).
- **Okres retencji powiadomień:** Skrócenie z 24 do 3 miesięcy (art. 5 ust. 1 lit. e RODO), z zachowaniem wydłużonego okresu dla powiadomień o decyzjach moderacyjnych (minimum 6 miesięcy na odwołanie z art. 20 DSA).
- **Okres retencji audit logu:** Skrócenie standardowej retencji z 24 do 12 miesięcy.
- **Rekomendacja wykreślenia `AuditLogEntry::NIGDY_NIE_KASUJ`:** Zastąpienie bezterminowego przechowywania zdarzeń usunięcia konta jednorazowym, zanonimizowanym wpisem potwierdzającym realizację prawa do bycia zapomnianym, przechowywanym przez 36 miesięcy.
- **Retencja danych kontaktowych w zgłoszeniach:** Rekomendacja usuwania danych kontaktowych osoby zgłaszającej (e-mail) po 12 miesiącach, z zachowaniem treści zgłoszenia do końca 36-miesięcznego okresu sprawy.

#### 2. Co z tego jest prawdą w kodzie? (potwierdzone `plik:linia`)
- **Retencja powiadomień 3 miesiące:** `config/kuking.php:867` (`'retencja_miesiace' => 3`).
- **Ochrona powiadomień moderacyjnych (DSA art. 20):** `config/kuking.php:847-865` oraz `app/Models/Notification.php:57-61` definiują stałą `WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA = 6`, zapobiegając przedwczesnemu skasowaniu powiadomień o nałożonych sankcjach.
- **Retencja dziennika zdarzeń 12 miesięcy:** `config/kuking.php:2337` (`'retencja_miesiace' => 12`).
- **Retencja spraw moderacyjnych 36 miesięcy:** `config/kuking.php:2575` (`'retencja_spraw_miesiace' => 36`).
- **Mechanizm bezpiecznego czyszczenia spraw:** `app/Domain/Compliance/PrzedawnioneSprawyModeracyjne.php:149-170` chroni sprawy z aktywnym statusem lub otwartą procedurą odwoławczą przed skasowaniem.

#### 3. Co się ROZJEŻDŻA z kodem?
- **Lista `AuditLogEntry::NIGDY_NIE_KASUJ` WCIĄŻ ISTNIEJE:** Rekomendacja zastąpienia bezterminowego przechowywania zdarzeń usunięcia konta wpisem 36-miesięcznym **nie została wdrożona**.
  - `app/Models/AuditLogEntry.php:66-70` definiuje stałą `NIGDY_NIE_KASUJ` dla:
    - `ACTION_ACCOUNT_DELETED`
    - `ACTION_ACCOUNT_ERASED`
    - `ACTION_ACCOUNT_PENDING_DELETE`
  - `app/Domain/Compliance/PrzedawnioneWpisyAudytu.php:57` bezwzględnie wyklucza te zdarzenia z czyszczenia (`whereNotIn('action', AuditLogEntry::NIGDY_NIE_KASUJ)`). Zdarzenia te trwają w bazie bezterminowo.
- **Brak dwufazowego usuwania kontaktu zgłaszającego:** W tabeli `reports` dane kontaktowe zgłaszającego nie są wyodrębnione do osobnego cyklu 12 miesięcy. Sprawa moderacyjna i powiązane zgłoszenie są usuwane wspólnie po 36 miesiącach.

#### 4. Czego NIE DA SIĘ sprawdzić z repozytorium?
- Czy backupy bazy w Cloudflare R2 są fizycznie usuwane zgodnie z harmonogramem retencji kopii zapasowych.
- Czy spisany test równowagi (LIA) dla 36-miesięcznej retencji istnieje w dokumentacji prawnej spółki poza repozytorium.

#### 5. Rozstrzygnięcie i rekomendacje
- **Kluczowa decyzja dla właściciela:**
  - *Wariant A (Status quo):* Pozostawić `AuditLogEntry::NIGDY_NIE_KASUJ` bezterminowo jako dowód rozliczalności spełnienia żądania usunięcia danych (art. 5 ust. 2 RODO). Wpis audytowy zawiera wyłącznie hash/identyfikator, bez danych teleadresowych.
  - *Wariant B (Ścisłe RODO):* Wdrożyć rekomendację zewnętrznej oceny: znieść `NIGDY_NIE_KASUJ` i ograniczyć retencję potwierdzenia usunięcia do 36 miesięcy (okres przedawnienia ewentualnych roszczeń odszkodowawczych).
- **Rekomendacja:** Przyjąć Wariant A jako bezpieczniejszy dowodowo przed Prezesem UODO, dokumentując ten wybór jako świadomą decyzję właściciela w `docs/DECISIONS.md`.

---

### 3.5. PRZEGLAD_BEZPIECZENSTWA_ZYWNOSCI (`docs/decyzje/PRZEGLAD_BEZPIECZENSTWA_ZYWNOSCI.md`)

#### 1. Co dokument twierdzi?
- **Przegląd bazy zalążkowej:** Audyt sanitarno-epidemiologiczny i kulinarny 40 przepisów demonstracyjnych.
- **Klasyfikacja bezpieczeństwa:** 28 przepisów ze statusem POPRAW (konieczność doprecyzowania temperatur pieczenia, mycia drobiu, obróbki grzybów), 9 ze statusem CZYSTY, 2 DO WIEDZY, 1 ze statusem BLOKUJE.
- **Przepis blokujący p24 (Tatar wołowy):** Całkowity zakaz publikacji surowego mięsa i surowych jaj w bazie startowej dla grupy 50+ bez restrykcyjnych procedur higienicznych (zagrożenie *Salmonella*, *E. coli*, toksoplazmoza).
- **Wdrożenie poprawek redakcyjnych:** Wprowadzenie uwag technologicznych do plików danych demonstracyjnych.

#### 2. Co z tego jest prawdą w kodzie? (potwierdzone `plik:linia`)
- **Plik audytu istnieje w strukturze:** `database/seeders/dane/przeglad-bezpieczenstwa-zywnosci.json:1-1200` zawiera pełen rejestr 40 pozycji wraz z uwagami ekspertów.
- **Przepis p24 ZOSTAŁ FAKTYCZNIE USUNIĘTY:** W pliku `database/seeders/dane/tresc-zalazkowa.json` znajduje się dokładnie **39 przepisów** (od p1 do p23 oraz od p25 do p40). Przepis p24 został bezwzględnie wycięty z zestawu startowego.
- **Wdrożenie poprawek higienicznych w treściach kroków:**
  - `p1` (Rosół): usunięto zalecenie mycia surowego kurczaka pod bieżącą wodą (ryzyko aerozoli *Campylobacter*);
  - `p2` (Bigos): dodano zalecenie bezpiecznego rozmrażania w lodówce;
  - `p3` (Zupa grzybowa): dodano wymóg dokładnego obgotowania grzybów leśnych;
  - `p5` (Jajecznica): dodano wymóg sparzenia skorupek jaj.

#### 3. Co się ROZJEŻDŻA z kodem?
- **Rozjazd w komentarzu seedera:** W pliku `database/seeders/TrescZalazkowaSeeder.php:26` komentarz nagłówkowy klasy nadal podaje: *„Trzydzieści kont i czterdzieści przepisów z danymi do testów”*, podczas gdy w pliku JSON przepisów jest dokładnie 39.

#### 4. Czego NIE DA SIĘ sprawdzić z repozytorium?
- Bezpieczeństwa sanitarno-epidemiologicznego przepisów wprowadzanych na bieżąco przez realnych użytkowników serwisu.

#### 5. Rozstrzygnięcie i rekomendacje
- **Rekomendacja:** Wdrożenie jest merytorycznie wzorowe i rygorystyczne. Wystarczy drobna korekta redakcyjna komentarza w `TrescZalazkowaSeeder.php:26` (zamiana „czterdzieści” na „trzydzieści dziewięć”).

---

### 3.6. PRZEGLAD_SPEC_9_DECYZJI (`docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`)

#### 1. Co dokument twierdzi?
- **Dolna nawigacja mobilna (§2.1):** SPEC proponował 4 pozycje z długimi etykietami („Powiadomienia”); pomiar wykazał łamanie paska przy szerokości 320 px. Rekomendacja: zachowanie 5 krótkich pozycji (`Start | Szukaj | Dodaj | Moje | Profil`).
- **Granica zaufania proxy (§2.2, §2.3):** Wskazanie na konieczność zabezpieczenia nagłówka `X-Forwarded-For` przed manipulacją, krytyka testu `ZaufaneProxyTest.php:62-78`, propozycja wprowadzenia `ClientAddressResolver` oraz weryfikacji tokenu krawędziowego `X-Kuking-Edge-Token`.
- **Dostęp moderatora do zdjęć (§2.8, P0 pkt 8):** Żądanie natychmiastowego usunięcia klauzuli `|| $widz->isModerator()` z wczesnego powrotu w `DostepDoZdjecia::moze()`, aby moderator nie miał nieograniczonego wglądu w prywatne zdjęcia bez otwartego zgłoszenia.
- **Łańcuch CI/CD (§2.6, §2.7):** Brak agregującego joba `ci` w `ci.yml`, konieczność dodania ekosystemu `docker` do `dependabot.yml` i przypięcia akcji do SHA.
- **Sonda dysku `/health` (§2.5):** Luka wydajnościowa i obciążeniowa — brak cache'u i blokady (lock) na sondzie dysku wykonującej operacje `put()`, `get()`, `delete()` przy każdym odpytaniu endpointu.
- **Taksonomia Tagów vs Tematów (§2.11):** Zastąpienie Tematów (`Topic`) przez Tagi (`Tag`), z bezpieczną migracją i weryfikacją braku osieroconych danych.
- **Deduplikacja powiadomień follow (§2.4):** Konieczność wprowadzenia 24-godzinnego okna deduplikacji dla cyklu follow→unfollow→follow oraz eliminacja wyścigu w `SocialController::follow()`.

#### 2. Co z tego jest prawdą w kodzie? (potwierdzone `plik:linia`)
- **Dolna nawigacja ma 5 pozycji:** `resources/views/components/layout.blade.php:1311-1330` implementuje dokładnie: Start, Szukaj, Dodaj, Moje, Profil.
- **Sonda dysku `/health` nadal nie ma cache'u:** `app/Http/Controllers/HealthController.php:662-700` (`sprawdzDyskZeZdjeciami()`) przy każdym wywołaniu publicznego `/health` tworzy losowy plik próbny `.health/uuid`, odczytuje go i usuwa.
- **Zastąpienie Tematów Tagami zakończone:** `database/migrations/2026_09_07_100000_create_tags_tables.php` powołało tabele tagów, a migracja `2026_09_07_300000_drop_topics.php:57-75` usunęła `topics` i `topic_follows` z rygorystycznym sprawdzeniem braku danych (`$ileObserwacji > 0 || $ilePrzypisanychWpisow > 0` rzuca `RuntimeException`). Model `Topic` został całkowicie wycofany.
- **Deduplikacja powiadomień follow:** `app/Domain/Notifications/Actions/NotifyUser.php:45-47, 98-108` wprowadziło `TYPY_WYCISZANE_W_OKNIE = [Notification::TYPE_FOLLOW]` oraz 24-godzinne okno z konfiguracji (`config/kuking.php:860`).
- **Wyścig w relacji follow usunięty:** `app/Domain/Social/Actions/FollowUser.php:20-68, 89-118` wdrożyło blokadę `ZamekPary` i transakcję bazy danych, eliminując błędy 500 przy podwójnym kliknięciu.

#### 3. Co się ROZJEŻDŻA z kodem?
- **KRYTYCZNA LUKA P0 POZOSTAŁA OTWARTA (DostepDoZdjecia):**
  Rekomendacja §2.8 i P0 pkt 8 **NIE ZOSTAŁA WDROŻONA**.
  W pliku `app/Domain/Media/DostepDoZdjecia.php:256` wciąż znajduje się kod:
  ```php
  if ($widz->isAdministrator() || $widz->isModerator()) {
      return true;
  }
  ```
  Moderator omija weryfikację rodzica zdjęcia (posta/przepisu) i ma bezwarunkowy dostęp do surowych bajtów zdjęć prywatnych, w tym zdjęć osieroconych, bez powiązania ze sprawą moderacyjną!
- **Architektura proxy zrealizowana inaczej:** Zamiast postulowanej klasy `ClientAddressResolver`, ochronę przed podrobionym nagłówkiem XFF zrealizowano przez middleware `App\Http\Middleware\NormalizeForwardedFor` oraz konfigurację `config/proxy.php` (`zaufane_przeskoki`), odczytując adres klienta od prawej strony łańcucha. Test `tests/Feature/ZaufaneProxyTest.php:62-78` zachowano i opisano w komentarzu po SEC-01, a obronę przed fałszerstwem przetestowano w `tests/Feature/PodrobionyNaglowekProxyTest.php`.
- **Łańcuch CI:** W `.github/workflows/ci.yml:141-170` wciąż funkcjonuje 13 niezależnych jobów bez agregującego joba `ci`. W `.github/dependabot.yml:1-86` brak ekosystemu `docker`, a akcje GitHub Actions nie zostały przypięte do pełnych skrótów SHA.

#### 4. Czego NIE DA SIĘ sprawdzić z repozytorium?
- Czy w infrastrukturze Railway i Cloudflare wdrożono regułę Request Header Transform dla nagłówka `X-Kuking-Edge-Token` (Blok B).
- Czy w konfiguracji bucketu Cloudflare R2 wyłączono subdomenę publiczną `cdn.kuking.pl` (Blok C).
- Czy w GitHub Rulesets włączono regułę 0-approval PR dla gałęzi `main`.

#### 5. Rozstrzygnięcie i rekomendacje
- **Pilna decyzja P0:** Podjąć decyzję o wycięciu `|| $widz->isModerator()` z `DostepDoZdjecia:256`. Wymaga to uprzedniego upewnienia się, że panel moderacji wyświetla zdjęcia w zgłoszeniach przez trasę podglądu powiązaną ze zgłoszoną treścią, a nie przez bezpośrednie linki do wariantów.
- **Optymalizacja `/health`:** Wdrożyć lock/cache dla `sprawdzDyskZeZdjeciami()` (np. test zapisu raz na 5 minut w cache), eliminując ciągły narzut I/O na dysk storage przy zewnętrznym monitoringu.

---

### 3.7. ZRODLA_PRAWNE_ZEWNETRZNE (`docs/decyzje/ZRODLA_PRAWNE_ZEWNETRZNE.md`)

#### 1. Co dokument twierdzi?
- **Zestawienie źródeł pierwotnych:** Wykaz 8 podstaw prawnych i wytycznych technicznych:
  - `[L1]` RODO (Rozporządzenie UE 2016/679);
  - `[L2]` Kodeks cywilny (tekst jedn. Dz.U. 2026 poz. 795 — art. 118, 442¹);
  - `[L3]` DSA (Rozporządzenie UE 2022/2065 — art. 3, 6, 11–20, 24);
  - `[L4]` Wytyczne WP248 rev.01 ws. DPIA;
  - `[L5]` Wykaz Prezesa UODO (M.P. 2019 poz. 666);
  - `[L6]` Wytyczne EROD 8/2020 ws. targetowania użytkowników social media;
  - `[L7]` Prawo komunikacji elektronicznej (Dz.U. 2024 poz. 1221 — art. 398, 399);
  - `[T1]` OWASP Password Storage Cheat Sheet.
- **Deklaracja granic weryfikacji:** Zastrzeżenie, że ocena prawna dotyczyła modelu opisanego w dokumentacji projektowej, a nie zweryfikowanego fizycznie środowiska produkcyjnego, konfiguracji serwerów, umów czy procedur usuwania kopii zapasowych.

#### 2. Co z tego jest prawdą w kodzie? (potwierdzone `plik:linia`)
- **Spójność cytowań:** Źródła L1–L3 są bezpośrednio przywoływane w analizach retencji:
  - `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md:5`
  - `docs/decyzje/ADR_RETENCJE.md:851`
- **Odzwierciedlenie norm w architekturze:**
  - `[L1] RODO`: Anonimizacja IP (`AuditLogEntry::record()`), retencja w `app/Domain/Compliance/`, status `erased` konta;
  - `[L2] Kodeks cywilny`: Odniesienie terminów przedawnienia roszczeń z art. 118 i 442¹ k.c. do retencji spraw moderacyjnych (36 miesięcy);
  - `[L3] DSA`: Punkt kontaktowy w `config/kuking.php:2375`, formularz zgłoszeń naruszeń, procedura odwoławcza art. 20 (`Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`);
  - `[T1] OWASP`: Bezpieczne haszowanie haseł algorytmem bcrypt z odpowiednim kosztem w konfiguracji Laravel (`config/hashing.php`).

#### 3. Co się ROZJEŻDŻA z kodem?
- Sam dokument jest bibliografią normatywną i deklaracją metodyczną — nie zawiera twierdzeń implementacyjnych, w związku z czym nie występuje tu bezpośredni rozjazd z kodem.

#### 4. Czego NIE DA SIĘ sprawdzić z repozytorium?
- Faktycznego brzmienia umów powierzenia przetwarzania danych (DPA) podpisanych z podmiotami trzecimi.
- Prowadzenia Rejestru Czynności Przetwarzania (RCP) wymaganego przez art. 30 RODO.
- Treści wewnętrznych procedur bezpieczeństwa osobowego i fizycznego operatora.

#### 5. Rozstrzygnięcie i rekomendacje
- **Rekomendacja:** Zachować dokument jako nienaruszalną bazę normatywną i bibliograficzną dla wszelkich audytów prawnych i procedur zgodności serwisu Kuking.pl.

---

## 4. Sprawy wymagające decyzji właściciela

W toku audytu merytorycznego wyodrębniono 5 zagadnień, których nie wolno rozstrzygać na poziomie kodu ani asercji testowych bez jawnej decyzji właściciela produktu:

### Decyzja 1: Uprawnienia moderatora w `DostepDoZdjecia` (P0 Bezpieczeństwo)
- **Problem:** `DostepDoZdjecia.php:256` daje moderatorowi globalny bypass do wszystkich zdjęć, w tym prywatnych i osieroconych.
- **Wariant A (Zalecany przez SPEC):** Usunięcie `|| $widz->isModerator()`. Moderator widzi tylko zdjęcia przypisane do treści, do których ma prawo wglądu. Wymaga upewnienia się, że panel zgłoszeń przekazuje kontekst sprawy.
- **Wariant B (Status quo):** Pozostawienie uprawnienia z audytem wglądu (logowanie w `AuditLogEntry` każdego odpytania prywatnego zdjęcia przez moderatora).
- **Koszt/Ryzyko:** Wariant A minimalizuje ryzyko naruszenia prywatności użytkowników; Wariant B jest prostszy, lecz utrzymuje lukę prywatności wskazaną w audycie R5.

### Decyzja 2: Bezterminowość zdarzeń usunięcia konta w Audit Log
- **Problem:** `AuditLogEntry::NIGDY_NIE_KASUJ` wyłącza zdarzenia skasowania konta z retencji 12/36 miesięcy.
- **Wariant A:** Zostawić bezterminowo w imię rozliczalności (art. 5 ust. 2 RODO).
- **Wariant B:** Wprowadzić 36-miesięczną retencję potwierdzeń usunięcia konta zgodnie z zaleceniem zewnętrznej oceny prawnej.
- **Koszt/Ryzyko:** Wariant A rodzi potencjalne zastrzeżenia audytorów RODO dotyczące braku terminu; Wariant B pozbawia operatora dowodu, że dawne konto usunięto na żądanie użytkownika.

### Decyzja 3: Nazwa 4. pozycji dolnego paska nawigacji
- **Problem:** `AGENTS.md` §5 mówi „Start | Szukaj | Dodaj | Moje | Profil”, w innych miejscach postulowano „Zeszyt”. Kod w `layout.blade.php:1325` stosuje etykietę „Moje”.
- **Rekomendacja:** Potwierdzić „Moje” jako docelową, krótką etykietę (mieszczącą się w 320 px) i ujednolicić dokumentację.

### Decyzja 4: Obciążenie I/O przez sondę dysku `/health`
- **Problem:** Ciągłe tworzenie i kasowanie plików na dysku przy każdym requeście z monitoringu.
- **Rekomendacja:** Dodać blokadę/cache na wynik sondy dysku na 60–300 sekund.

### Decyzja 5: Agregujący job `ci` w GitHub Actions
- **Problem:** Ruleset branch protection wymaga zaznaczania kilkunastu pojedynczych jobów.
- **Rekomendacja:** Dodać lekki job `ci` z `needs: [...]` w `.github/workflows/ci.yml`.

---

## 5. Rejestr pomiarów własnych i źródeł przejętych

| Lp. | Fakt / Wartość | Typ pomiaru | Źródło / Metoda pomiaru |
|---|---|---|---|
| 1 | 1092 commity na gałęzi `main` | **Pomiar własny** | `git rev-list --count HEAD` w worktree |
| 2 | 105 701 linii w katalogu `docs/` | **Pomiar własny** | `find docs -name '*.md' \| xargs wc -l` w worktree |
| 3 | 2309 linii w `DEPLOYMENT_RUNBOOK.md` | **Pomiar własny** | `wc -l docs/infra/DEPLOYMENT_RUNBOOK.md` w worktree |
| 4 | Dokładnie 39 przepisów w `tresc-zalazkowa.json` | **Pomiar własny** | Odczyt struktury JSON w WSL |
| 5 | Obecność `|| $widz->isModerator()` w `DostepDoZdjecia.php:256` | **Pomiar własny** | Bezpośrednia inspekcja kodu źródłowego |
| 6 | 13 niezależnych jobów w `ci.yml` | **Pomiar własny** | Parsowanie klucza `jobs:` w `.github/workflows/ci.yml` |
| 7 | 5 pozycji dolnej nawigacji | **Pomiar własny** | Analiza `resources/views/components/layout.blade.php:1311-1330` |
| 8 | Skuteczność obrony przed fałszowaniem XFF od prawej strony | **Pomiar własny** | `tests/Feature/PodrobionyNaglowekProxyTest.php` |
| 9 | Promocyjna cena OpenAI Sol do 21.11.2026 | `[pomiar cudzy]` | Raport R4 / oficjalny cennik OpenAI z 2026-09-07 |
| 10 | Badania NN/g (2016) dot. ukrytej nawigacji hamburgerowej | `[pomiar cudzy]` | Nielsen Norman Group cytowane w `PRZEGLAD_SPEC_9_DECYZJI.md` |
