# #28 — projekt OCR zeszytów i importu przepisu z adresu strony

Status: **propozycja do decyzji właściciela, bez implementacji**. Badanie: 20 września 2026.
Gałąź `gpt/ocr-import`, stan wejściowy `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, początkowo czyste drzewo.
Zlecenie: [issue #28](https://github.com/woogitsu/kuking.pl/issues/28).

## 1. Rekomendacja i granica zadania

Zachować obie funkcje w V2. Najpierw pilotaż OCR jednej kartki, następnie osobno import URL z kontrolą pochodzenia. OCR proponuje tekst do sprawdzenia; importer przygotowuje prywatny szkic. Żaden nie publikuje automatycznie. Nie finansować startu społeczności cudzymi przepisami.

Rekomenduję Cloud Vision `DOCUMENT_TEXT_DETECTION` do prób OCR, bez generatywnego poprawiania treści, i parser statycznego HTML w PHP: JSON-LD Recipe oraz ograniczone mikrodane Recipe. Lokalny Tesseract nie jest rekomendowanym silnikiem pisma odręcznego. Publiczne udostępnienie importu wymaga osobnej kontroli praw i trwałego wskazania źródła. Sam link i zmiana kilku słów nie legalizują kopii.

Dokument opisuje przyszłe tabele, reguły i koszty. Nie ustanawia decyzji w `DECISIONS.md`, nie zmienia regulaminu ani nie rozszerza MVP. „V1-minimum” oznacza pierwszą wersję funkcji V2, nie przesunięcie do V1 całego produktu.

## 2. Stan zastany — własny odczyt i pomiar przed zmianami

`docs/ROADMAP.md` opisuje fundament, MVP i bramkę V1, ale **nie ma osobnej listy V2 ani pozycji OCR/import**. Klasyfikację V2 potwierdzają `docs/FEATURES.md`, AGENTS §9/12 i otwarte #28 (P2). Nie przypisuję roadmapie zdania, którego w niej nie ma.

| Co sprawdziłem | Wynik i znaczenie |
|---|---|
| `app/Domain/Recipes`, `app/Http/Controllers`, `app/Jobs`, `routes/web.php`, `composer.json` | Odczyt plików i wyszukiwanie `ocr`, `tesseract`, `vision`, `import`, `source_url`: brak ścieżki OCR i importera URL. Wzmianki o przyszłym imporcie są komentarzami. Brak biblioteki sam w sobie nie byłby dowodem; sprawdzono też akcje i wejścia HTTP. |
| `RecipeController`, `PublishRecipe`, `Recipe` | Istnieją `source_type`, `source_url`, `source_person`, `source_note`, `source_scan_media_id`, `published_at`; nie dodawać ich drugi raz. |
| `RecipeWizardTest::test_edycja_przepisu_nie_kasuje_zdjecia_kartki_z_zeszytu` | Jest test zachowania zdjęcia kartki przy edycji; to punkt wyjścia, nie OCR. Wynik uruchomienia opisuje sekcja kontroli. |
| `SnapshotRecipeVersion::handle()` | Snapshot zawiera typ i opis pochodzenia, ale **nie zawiera `source_url` ani `source_scan_media_id`**. `docs/DATABASE.md` obiecuje zapis wszystkich czterech pól pochodzenia w wersji. To rozbieżność odczytu kodu z dokumentacją, nie wykonany test regresji. Przed importem trzeba ją rozwiązać; historia nie wystarczy jako dowód adresu w chwili publikacji. |
| `DostepDoZdjecia` | Skan jest obecnie jednym ze zdjęć przepisu; nie ma osobnej gwarancji „prywatny skan przy publicznym przepisie”. Nowego prywatnego wejścia OCR nie wolno po prostu przypiąć do publicznego przepisu. |
| `ZglosNielegalnaTresc`, `NotifyReporterReceipt`, `NotifyReporterDecision`, `NotifyReporterAppealOutcome` | Istnieją przyjęcie zgłoszenia prawnego bez konta, potwierdzenia i odpowiedzi zgłaszającemu. Nie projektować drugiego systemu spraw ani nowego identycznego powiadomienia. |
| `RecipeStatusTransitions::BY_AUTHOR` | Autor nie może edytować przepisu `hidden`. Nie obiecywać „ukryj, a potem popraw ten przepis” bez nowej drogi bezpiecznej korekty. |

Komentarz w #28 z 9 września również wskazuje brak obu funkcji **[pomiar cudzy: komentarz matmaxalez/Claude Code w #28]**. Nie jest podstawą powyższej oceny — kod sprawdziłem ponownie na wskazanym SHA. Nie oglądałem zalogowanej produkcji.

### Próba dostępności danych na stronach

Własny pojedynczy GET statycznego HTML przez PowerShell `Invoke-WebRequest -UseBasicParsing -TimeoutSec 20`, bez wykonywania JS, logowania i zapisywania cudzego przepisu. Skrypty `application/ld+json` sprawdzono przez `ConvertFrom-Json` (typ główny i `@graph`), a `itemtype` Recipe przez wyszukiwanie znacznika. To **rozpoznanie formatu**, nie test kompletnego parsera ani zgoda na import.

| Próbka z 20.09.2026 | HTTP / bajty / czas jednego GET | JSON-LD / mikrodane |
|---|---|---|
| [AniaGotuje — naleśniki](https://aniagotuje.pl/przepis/jak-zrobic-ciasto-na-nalesniki) | 200 / 231 609 / 960 ms | Dwa bloki JSON-LD z wykrytymi typami WebSite i WebPage; jeden znacznik `schema.org/Recipe` w mikrodanych. |
| [Kwestia Smaku — naleśniki](https://www.kwestiasmaku.com/kuchnia_polska/nalesniki/nalesniki.html) | 200 / 96 902 / 380 ms | Zero bloków JSON-LD; jeden znacznik Recipe w mikrodanych. |

Sprawdzono oba `robots.txt`: AniaGotuje `Allow: /`, Kwestia Smaku ma zakazy dla innych ścieżek i `Crawl-delay: 10`. Pierwsza strona ma meta robots `index, follow`; w drugiej nie znaleziono standardowego znacznika `name=robots`. Nie zmierzono wszystkich nagłówków ani regulaminów wydawców. Brak zakazu indeksowania **nie oznacza licencji**. Żadna domena nie zostaje przez to automatycznie dopuszczona do pilotażu.

Wniosek: wyłącznie JSON-LD wykluczyłoby co najmniej drugą próbkę. Dwa adresy nie pozwalają szacować pokrycia polskiego internetu ani p95 pobrania. [Schema.org Recipe](https://schema.org/Recipe) opisuje m.in. tekstowe składniki i kroki tekstowe lub HowToStep/HowToSection — wejście wymaga kilku jawnych wariantów.

## 3. Prawa autorskie — rekomendowane rozstrzygnięcie

### 3.1. Prawo a polityka produktu

Polska ustawa chroni twórczy sposób wyrażenia, nie samą ideę, procedurę czy metodę. Ochrona konkretnego tekstu, zdjęcia lub opracowania wymaga oceny; nie przyjmujemy, że każdy opis gotowania jest utworem, ani że żaden nim nie jest. Własność zeszytu nie oznacza praw do wszystkich zapisanych treści. Podanie autora nie zastępuje uprawnienia do publikacji. Prywatny użytek użytkownika nie uprawnia automatycznie operatora do każdej kopii. Podstawa: art. 1, 2, 17 i 23 [ustawy o prawie autorskim — tekst ISAP](https://isap.sejm.gov.pl/isap.nsf/download.xsp/WDU19940240083/U/D19940083Lj.pdf).

To projekt reguły produktu, nie opinia rozstrzygająca konkretny spór. **Warunek uruchomienia:** prawnik zatwierdza podstawę przetwarzania kopii, publikacji, regulamin, retencję dowodów i umowę z OCR. `LICENCJA_UGC_PROJEKT.md` nie jest obowiązującą licencją ani zgodą blogera.

### 3.2. OCR własnego zeszytu

Dopuścić odczyt własnych lub legalnie posiadanych notatek do prywatnego szkicu; przed wysłaniem do dostawcy wyjaśnić odbiorcę i cel. Z odręcznego pisma **nie da się wiarygodnie ustalić**, czy tekst pochodzi od babci, z książki czy z bloga. W V1 nie ma skanowania internetu, „procentu plagiatu” ani decyzji prawnej modelu.

Przed udostępnieniem komukolwiek poza właścicielem (także obserwującym) człowiek wybiera pochodzenie i składa niezaznaczone domyślnie oświadczenie o prawie do tekstu oraz osobno zdjęcia kartki, jeśli chce je pokazać. Przy „nie wiem” pozostaje prywatny szkic, z możliwością samodzielnego opisania przygotowania na podstawie doświadczenia lub uzyskania zgody. Rodzinne pochodzenie nie daje automatycznej licencji. Deklaracja użytkownika jest śladem decyzji, nie zwolnieniem operatora z reagowania.

Proponowany tekst przed publikacją:

> Zdjęcie własnego zeszytu może zawierać tekst przepisany z książki lub strony. Udostępnij tylko tekst i zdjęcia, do których masz prawa. Samo podanie źródła nie wystarczy. Jeśli nie masz pewności, zachowaj szkic tylko dla siebie.

Zdjęcie kartki z nazwiskami, telefonem lub cudzym tekstem pozostaje domyślnie **prywatne także po publikacji poprawionego przepisu**. Publiczne pokazanie skanu jest osobnym wyborem, po sprawdzeniu praw i danych. Zachować obraz niezależnie od powodzenia OCR przez czas przechowywania przepisu, z prawem usunięcia i wyjątkami dla zgodnego z prawem zabezpieczenia dowodów. Hasło z #28 „zostaje na zawsze” zastąpić tą konkretną obietnicą; nie oznacza ono zachowania surowego EXIF ani uniemożliwienia usunięcia konta.

### 3.3. Import URL

Pierwszy pilotaż: **lista dopuszczonych domen z udokumentowaną zgodą na pobranie i prywatne przechowanie**, obejmująca licencjonowane źródła lub strony użytkowników z potwierdzonymi prawami. Nie zaczynać od dowolnego bloga. Pozyskanie zgód jest kosztem uruchomienia. Do czasu listy importer pozostaje wyłączony.

Import zapisuje nieusuwalne przez autora pochodzenie i powstaje z `source_type=external`, `visibility=private`, jako szkic. Brak importu zdjęć, opisów autora, komentarzy, ocen, reklam i tekstu wprowadzającego. Nie pobieramy całego serwisu ani list adresów. Kontrola w akcji domenowej obejmuje kreator, prosty formularz, późniejszą edycję i bezpośrednie wywołanie akcji — ukryte pole formularza nie chroni pochodzenia.

**Próba opublikowania importu jako swojego:** system odmawia zmiany źródła na `own` i publikacji niezweryfikowanego tekstu. Pokazuje zachowany szkic i instrukcję. Checkbox albo różnica znaków nie dowodzą autorstwa. Publiczny import w pilotażu wymaga sprawdzenia przez moderatora podstawy prawnej: praw własnych, konkretnej zgody/licencji zgodnej z zasadami Kuking albo samodzielnego opisu przygotowania bez przejęcia chronionej formy. Zamiana słów synonimami nie jest obejściem. Wątpliwości trafiają do prawnika; do wyjaśnienia treść pozostaje prywatna.

Po dopuszczeniu: widoczna adnotacja **„Przepis przygotowany na podstawie: {nazwa źródła}”**, link zwrotny do strony i oznaczenie importu w sekcji pochodzenia. `source_url` i `isBasedOn` pozostają zgodne. Konto opisujemy jako osobę publikującą, bez przypisywania jej autorstwa oryginału. Wymogi licencji (autor, nazwa licencji, link, informacja o zmianach) pokazujemy dodatkowo; niewspierana licencja nie daje dopuszczenia. Źródło jest widoczne także prywatnie i w eksporcie. Korekta błędnego URL przez moderatora dopisuje historię, nie wymazuje śladu.

Reguła blokowania pobierania:

- Lista dopuszczona **i** brak blokady domeny/URL po zgłoszeniu. Żądanie uprawnionego wydawcy o zakazie kolejnych importów prowadzi do szybkiej blokady, z weryfikacją i możliwością odwołania.
- Respektować `robots.txt` dla nazwanego bota, `Disallow`, limity częstotliwości i 429. Błąd sieci/5xx przy robots oznacza odroczenie; 404 oznacza brak pliku, nie zgodę na wykorzystanie treści.
- `noindex`, `none` lub `noarchive` w meta robots / `X-Robots-Tag` oznaczają w naszej ostrożnej polityce odmowę importu. Robots i noindex dotyczą robotów/indeksowania, **nie są systemem licencji**. [RFC 9309](https://www.rfc-editor.org/rfc/rfc9309.html) i [meta robots](https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag).
- Nie omijać logowania, paywalla, CAPTCHA, zakazu regulaminowego ani zabezpieczeń. DMCA nie jest znacznikiem HTML ani europejskim automatycznym trybem blokady. Pismo nazwane DMCA traktujemy jako zgłoszenie praw autorskich i kierujemy do tej samej procedury; nakazy prawne mają osobną ocenę i pierwszeństwo.

### 3.4. Zgłoszenie naruszenia i odpowiedzialność

Właściciel wyznacza **moderatora dyżurnego i zastępcę**; administrator rozpatruje odwołanie, prawnik rozstrzyga sporne prawa. `/zglos-nielegalna-tresc` pozostaje dostępne bez konta. Numer sprawy, wskazanie oryginału i kopii, uzasadnienie, kontakt i oświadczenie dobrej wiary pozwalają ocenić zgłoszenie. Nie żądać konta ani obowiązkowego skanu dowodu osobistego.

Proponowany wewnętrzny cel: potwierdzenie automatyczne bez zbędnej zwłoki, przegląd przez człowieka **do 24 godzin kalendarzowych**, prosta decyzja do **72 godzin**, informacja o dalszym postępowaniu do 72 godzin w sprawie spornej. Gdy wiarygodna informacja uzasadnia nielegalność — szybko ograniczyć dostęp, nie czekać do końca SLA. To cele organizacyjne, nie ustawowy termin ani obietnica końca każdego sporu w trzy dni. Zaostrza to obecny standard P2 playbooka (72 h); bez obsady również w weekendy nie uruchamiać publicznych importów.

Potwierdzona kopia: ukryć sporny przepis i publiczne kopie skanu, zabezpieczyć minimalne dowody, powiadomić autora o przyczynie i środkach odwołania oraz zgłaszającego o wyniku. Nie ujawniać automatycznie jego danych autorowi. Odmowa zgłoszenia też wymaga uzasadnienia i pouczenia. Bez automatycznych banów ani usuwania konta po jednym zarzucie. Wykorzystać istniejące sprawy, odwołania, wiadomości w serwisie i e-mail dla osób bez konta; sprawdzić faktyczne doręczenie, nie tylko znacznik kolejkowania.

Art. 16 DSA przewiduje mechanizm zgłoszeń, potwierdzenie i poinformowanie o decyzji oraz terminowe, staranne rozpatrywanie; art. 17 dotyczy uzasadnienia ograniczeń. Nie podają ogólnego terminu „24 h na każde naruszenie praw autorskich”. Zachować zakres obowiązków małych przedsiębiorstw i D-042, bez automatycznego rozszerzania art. 20 na wszystkie zgłoszenia. [DSA, tekst urzędowy](https://eur-lex.europa.eu/eli/reg/2022/2065).

Ukrytego przepisu autor dziś nie edytuje. W V1 pozostaje ukryty; autor przekazuje wyjaśnienia/dowód w istniejącym odwołaniu. Nowy poprawiony szkic z importu musi przejść ponowną kontrolę i być związany ze sprawą. **Nie przywracać publicznej kopii „na czas poprawiania”.** Edytor korekt pod ukrytym przepisem poza minimum.

## 4. OCR — V1-minimum

1. „Dodaj przepis” → „Ze zdjęcia kartki”. Jedno zdjęcie JPEG/PNG/WebP, jedna strona i jeden przepis na zadanie. Limity bajtów i megapikseli z obecnego pipeline, bez podnoszenia ich na oko.
2. Prywatne przyjęcie, sprawdzenie obrazu, re-enkodowanie i usunięcie EXIF. Do OCR trafia przetworzony obraz o rozdzielczości dobranej w pilotażu, nie surowy plik ani wyłącznie miniatura 1600 px bez pomiaru czytelności.
3. Jawne „Odczytaj tekst”, informacja o dostawcy, job w database queue. Proponowany limit 5 nowych odczytów/osobę/dobę, jeden aktywny; jedna automatyczna ponowna próba przy błędzie przejściowym. Limity w `config/kuking.php`, idempotencja zapisu i jawne liczenie płatnych prób.
4. „Sprawdź odczytany tekst”: obraz i edytowalny tekst obok siebie na komputerze, pionowo na telefonie, z przyciskiem powiększenia. Człowiek przenosi/poprawia nazwę, składniki i kroki w istniejącym kreatorze; zachowujemy wiersze i polskie znaki. Nie zgadujemy, czy „1” oznacza „7”.
5. Szkic zapisuje się przed dalszymi krokami; wynik joba nie nadpisuje nowszych poprawek. Publikacja ma osobny podgląd ilości, temperatur, czasu, źródła i widoczności skanu oraz reguły prawne z §3.

Poza minimum: PDF, wiele stron, dzielenie wielu przepisów, automatyczne jednostki i przeliczenia, tłumaczenie, dopisywanie ilości, generatywne „naprawianie”, wartości odżywcze. Błędna ilość lub temperatura jest gorsza niż brak podpowiedzi, a nowe formaty zwiększają zakres bezpieczeństwa i odbioru.

**Cel czasu, nie wynik pomiaru API:** przyjęcie do 1 s po uploadzie, typowy wynik 5–10 s, p95 do 20 s, timeout próby 30 s; do 60 s po retry albo błąd z zachowanym obrazem i tekstem. Formularz nie czeka na długie żądanie HTTP. Sprawdzanie statusu wyłącznie na tym ekranie, np. co 3 s przez minutę (do 20 żądań), bez globalnego `wire:poll` i WebSocketów. Po powrocie „Sprawdź wynik”.

Błąd: „Nie udało się odczytać kartki. Spróbuj ponownie albo przepisz tekst ze zdjęcia.” Przy działaniu i w podsumowaniu; brak JS daje drogę do zwykłego formularza. Nie obiecywać zapisania zdjęcia, dopóki zapis się nie uda.

## 5. Import URL — V1-minimum

1. „Dodaj przepis” → „Z adresu strony”, pole z widoczną etykietą; informacja o dozwolonych źródłach i prywatnym szkicu.
2. Serwer sprawdza prawa/listę źródeł, robots i bezpieczeństwo adresu. Pobiera tylko statyczny HTML, bez JS i zasobów zewnętrznych. Limit 5 importów/osobę/dobę, jeden aktywny i wspólny limit na domenę.
3. Parser PHP odczytuje JSON-LD (obiekt, tablica, `@graph`, typ także jako tablica) albo mikrodane `itemscope/itemprop` Recipe. Bez pobierania zdalnych `@context`. Kilka receptur → wybór nazw, nigdy losowy pierwszy obiekt. Brak wspieranego opisu → zachować adres i zaoferować ręczne wpisanie.
4. Tytuł, surowe wiersze składników, kolejność kroków i opcjonalnie porcje/czas trafiają do podglądu. HowToSection zachowuje kolejność; nieobsługiwany układ daje komunikat zamiast częściowego sukcesu. HTML nie jest zaufaną treścią. Nie kopiować zdjęć, ocen, komentarzy, historii blogera i linków śledzących.
5. Człowiek poprawia dane i zapisuje prywatny szkic. Osobne „Poproś o sprawdzenie przed publikacją” kieruje do kontroli praw; akceptacja dotyczy konkretnej wersji. Zmiana treści/źródła unieważnia akceptację. „Opublikuj” dopiero po akceptacji, również na alternatywnych wejściach.

SSRF: tylko HTTP/HTTPS (preferowane HTTPS), porty 80/443, bez danych logowania w URL. Sprawdzać wszystkie A/AAAA, zakazać adresów prywatnych, loopback, link-local, multicast, metadanych i IPv4 mapowanego w IPv6. Łączyć ze zweryfikowanym IP przy zachowanej walidacji TLS/SNI — ponowne rozwiązanie DNS bez kontroli otwiera rebinding. Każde z maksymalnie 3 przekierowań ponownie sprawdza domenę, IP i zasady. Nie pobierać celu canonical automatycznie. Limit odpowiedzi **po dekompresji 2 MiB**, całego pobrania 10 s, rozmiaru i głębokości JSON; bez encji XML i sieci parsera. Osobny proces workera z ograniczonym wyjściem sieciowym według `SECURITY_BASELINE.md` §5.6; ta sama aplikacja, nie nowy mikroserwis. Bez zweryfikowanej izolacji importer wyłączony.

Odpadają: heurystyczny scraping, selektory dla dziesiątek blogów, strony wymagające JS, PDF, logowanie/paywall, CAPTCHA, masowy import, tłumaczenie, zdjęcia i generowanie brakujących pól. Nie dokładamy Pythona ani AGPL-owego kodu Mealie/Tandoor. Pierwsza implementacja używa możliwości PHP; pakiet dopiero po pomiarze konkretnej luki.

## 6. Dane, migracje i ekrany — plan, nie zmiana schematu

Wykorzystać `recipes`, składniki/kroki, `recipe_versions`, `media`, database queue, `reports`, `moderation_actions`, odwołania, `notifications` i `audit_log`. `recipes.published_at` pozostaje datą pierwszej publikacji według dotychczasowych przejść — nie dowodem pierwszego publicznego ujawnienia przy zmianie prywatny → publiczny.

### Trzy proponowane migracje

| Migracja | Zakres i reguły |
|---|---|
| M1: `recipe_intakes` | UUID; FK właściciela i docelowego przepisu; metoda `ocr/url`; stany queued/processing/ready/failed/applied; klucz idempotencji unikalny w obrębie właściciela; prywatne FK media wejściowego; oryginalny i końcowy URL, host, data pobrania, SHA-256 treści, wersja parsera/usługi, czasy i liczba prób; wynik JSONB jako półstrukturalna propozycja. CHECK zależności pól od metody. Bez archiwum całego bloga. |
| M2: `recipe_publication_provenance` | UUID; FK przepisu, wersji, intake i składającego deklarację; źródło i metoda zamrożone dla wersji; chwila udostępnienia poza właściciela `shared_at`; widoczność; podstawa praw, wersja oświadczenia, `declared_at`, opcjonalny prywatny dowód zgody; kontrola pending/approved/rejected, moderator/czas/uzasadnienie i hash sprawdzanej wersji. Publikacja i dowód atomowo. Nowa publikowana wersja dopisuje ślad. |
| M3: `recipe_import_sources` | Rejestr dopuszczonych/zablokowanych domen/ścieżek: UUID, znormalizowany host, zakres, status, podstawa/licencja, uprawnienia do pobrania/przechowania/publikacji, daty weryfikacji/wygaśnięcia, FK sprawy i moderatora. Blokada przed zgodą, dokładne granice hosta bez dopasowania `dobry.pl.zly.pl`. |

Wszystkie czasy `timestamptz`, rzeczywiste FK, CHECK-i statusów i wymaganych pól, indeks kolejki i FK intake→recipe. Reguł praw i właściciela nie przyjmować przez masowe przypisanie z requestu. Unikalność identyfikuje wysłanie/wersję, nie globalny URL — dwie osoby mogą legalnie pracować na tym samym źródle. Pochodzenie sprawdzane także przy ręcznej edycji importu.

Prywatny skan OCR pozostaje przy intake. `source_scan_media_id` używamy wyłącznie dla osobnej, świadomie udostępnionej kopii po kontroli danych i praw. Nie dziedziczyć publicznego dostępu przez drugi rodzic tego samego media. Dostosować Policy i eksport, bez flagi `media.visibility` sprzecznej z architekturą.

Uzupełnienie snapshotu o URL/odnośnik pochodzenia wymaga zmiany wersjonowania, nie kolejnej kolumny JSONB. Nie rekonstruować starych źródeł z obecnego URL; brak dowodu pozostaje jawny. `audit_log` dostaje identyfikatory i zdarzenia, bez tekstów, e-maili, tokenów i odpowiedzi OCR. Hash HTML pozwala porównać później przedstawioną kopię, sam **nie odtwarza strony ani nie dowodzi praw**.

Retencja proponowana: nieprzyjęty wynik OCR/importu 7 dni; po zastosowaniu usunąć wynik z intake, bo istnieje w szkicu; zachowany obraz kartki do usunięcia przez właściciela/przepisu. Minimalne pochodzenie/oświadczenie przez czas udostępniania, po usunięciu maksymalnie 12 miesięcy według zatwierdzonego testu uzasadnionego interesu; trwająca sprawa podlega obecnej retencji spraw (konfiguracja bazowa 36 miesięcy) i udokumentowanemu zabezpieczeniu dowodów. To nie bezwzględne nakazy prawa. Usuwanie, eksport i anonimizacja obejmują nowe tabele i obiekty R2.

Rollback: wyłączyć nowe przyjęcia i publikacje obu ścieżek, dokończyć/bezpiecznie zatrzymać joby. Zachować czytanie przepisów i pochodzenia. `down()` usuwa puste tabele; odmawia utraty oświadczeń, źródeł publikacji, blokad lub prywatnych skanów. Eksport/ręczne przeniesienie danych warunkiem destrukcyjnego cofnięcia. Każda migracja potrzebuje testu odmowy **i** udanego cofnięcia pustej bazy oraz aktualizacji `docs/DATABASE.md`. Bez cofania prywatności do publicznej wartości domyślnej.

### Ekrany i dokumenty do przyszłej zmiany

- „Dodaj przepis”: dwa nazwane wejścia, bez zmiany pięciu pozycji nawigacji.
- Kreator i prosty formularz: przyjęcie danych, status zadania, obraz/tekst, zachowanie poprawek, źródło, oświadczenie, osobna widoczność skanu, podgląd publikacji.
- „Moje”/szkice: odczyt w toku, błąd, gotowy wynik i wniosek do sprawdzenia; wznowienie zamiast kolejnej płatnej próby.
- Szczegół przepisu i eksport: adnotacja/link/licencja, `isBasedOn`, osoba publikująca oddzielona od źródła.
- Panel moderacji: kontrola praw do publikacji wersji, filtr metody pozyskania, rejestr źródeł, dowód i powiązana sprawa; dowody zgody nie są publiczne.
- Zgłoszenie prawne: instrukcja wskazania oryginału i kopii, istniejące potwierdzenie/odpowiedź. Nowy komunikat o wyniku **kontroli przed publikacją**, nie drugi typ odpowiedzi na naruszenie praw.
- Regulamin, prywatność, zasady, playbook, DATABASE, FEATURES i roadmapa — wraz z zatwierdzeniem i wdrożeniem. Obecne „prywatne treści nie wychodzą” w opisie moderacji wymaga wyjaśnienia nowego, dobrowolnie uruchamianego OCR.

Każdy ekran: tekst/pola ≥18 px, przyciski ≥48 px, etykiety, polski błąd przy polu i na górze, klawiatura, 320 px/200%, bez hover i utraty danych. Usunięcie skanu ma osobne potwierdzenie.

## 7. Koszt techniczny i czas wykonania

### OCR — cena usługi i ograniczenia wyceny

Cennik sprawdzony 20.09.2026: Google Cloud Vision Document Text Detection **1,50 USD/1000 obrazów** po pierwszym bezpłatnym 1000 jednostek miesięcznie, do 5 mln. Jedno zdjęcie i jedna funkcja to jednostka. Stawka krańcowa **0,0015 USD/zdjęcie**; inne funkcje/infrastruktura osobno. [Cennik Google](https://cloud.google.com/vision/pricing).

| Nowe zdjęcia/miesiąc | Założenie 10% płatnych ponownych prób | API z dostępnym darmowym 1000 | Bez darmowej puli |
|---:|---:|---:|---:|
| 1 000 | 1 100 | 0,15 USD | 1,65 USD |
| 10 000 | 11 000 | 15 USD | 16,50 USD |
| 100 000 | 110 000 | 163,50 USD | 165 USD |

Wzór: `max(0, zdjęcia × 1,10 − 1000) × 0,0015`. Jedna strona na przepis: 0,00165 USD/przepis bez darmowej puli; dwie strony podwoją składnik. 10% retry to założenie, nie zmierzona awaryjność. Bez VAT, kursu PLN i innych usług; to nie cena całej funkcji.

Google opisuje rękopis w tej metodzie, nie gwarantuje jakości babcinego pisma: [handwriting](https://docs.cloud.google.com/vision/docs/handwriting). Nie uruchomiłem płatnego API ani benchmarku — nie dostarczono korpusu z prawami i konfiguracji usługi. Przed wyborem sprawdzić przetwarzanie regionalne, DPA, podwykonawców, retencję i transfery. [Data Usage FAQ](https://docs.cloud.google.com/vision/docs/data-usage) nie zastępuje umowy ani informacji dla użytkownika.

Alternatywa lokalna: Tesseract bez opłaty za żądanie, ale z kosztem CPU, RAM i utrzymania; projekt wskazuje słabszą przydatność do rękopisu, bo projektowano go do druku ([FAQ](https://tesseract-ocr.github.io/tessdoc/FAQ.html)). Koszt: `N × sekundy_CPU/zdjęcie ÷ 3600 × stawka_CPU/h` plus RAM i administracja. Bez benchmarku cena per zdjęcie nieznana. Trening własnego modelu odręcznego poza minimum.

Próba odbiorowa: 50 legalnie udostępnionych kartek od ≥10 osób, różne pismo, polskie znaki, ułamki, plamy i przekreślenia. Dwie ręczne transkrypcje referencyjne. Mierzyć CER, błędy liczb/jednostek osobno, p50/p95 z kolejką i czas poprawiania względem ręcznego wpisania. Bramka proponowana: ≥80% kartek daje oszczędność czasu, mediana poprawiania ≤ połowy czasu przepisywania, żadna niezatwierdzona ilość nie trafia do publikacji. To plan, nie uzyskana skuteczność.

### Import, przechowywanie i utrzymanie

Parser bez płatnego API. Koszt = CPU + transfer HTML + worker z ograniczeniem sieci + zmiany formatów. Budżet pilotażu obu funkcji: **20–50 USD/miesiąc** na dodatkowy worker/zapas zasobów (założenie planistyczne, nie oferta Railway ani pomiar). Utrzymanie parsera/dostawcy: **4–8 h/miesiąc** przy małej liście źródeł, 800–1600 PLN przy założeniu 200 PLN/h.

Przy 10 000 zdjęć/miesiąc i 2 MB na przetworzony obraz dochodzi ok. 20 GB/miesiąc, ok. 240 GB po roku bez usunięć. R2 Standard: 0,015 USD/GB-miesiąc, czyli ok. 0,30 i 3,60 USD/miesiąc za ten zasób przed darmową pulą, wariantami i kopiami; operacje A/B osobno. [Cennik R2](https://developers.cloudflare.com/r2/pricing/). Rozmiar trzeba zmierzyć w korpusie; „na zawsze” to koszt narastający.

### Jednorazowy nakład — oszacowanie, nie pomiar wykonania

Dzień = 8 h. Stawki scenariusza: **200 PLN/h** technicznie, **500 PLN/h** prawnik, **60 PLN/h** operacyjnie; nie są otrzymanymi ofertami.

| Obszar | Nakład | Koszt scenariusza |
|---|---:|---:|
| Wspólne pochodzenie, prywatność, dowody, reguły publikacji i moderacja | 6–9 dni | 9 600–14 400 PLN |
| OCR: próba jakości 2–3 dni + integracja/formularz/awarie/odbiór 4–6 | 6–9 dni | 9 600–14 400 PLN |
| Import: parser 3–5 dni + izolacja/SSRF 3–4 + UI/odbiór 2–3 | 8–12 dni | 12 800–19 200 PLN |
| Prawo, umowy, retencja i teksty | 6–10 h | 3 000–5 000 PLN |
| Udokumentowanie 2–3 dozwolonych źródeł | 4–12 h | 240–720 PLN |

Obie ścieżki: **35 240–53 720 PLN** przed rezerwą, **44 050–67 150 PLN** z rezerwą 25%, bez VAT, opłat licencyjnych wydawców i oczekiwania na zgody. Sam OCR ze wspólnym przygotowaniem i prawem: 22 200–33 800 PLN przed rezerwą. Późniejszy importer to głównie własne 8–12 dni i źródła. Pilotaż może wykazać, że OCR nie oszczędza pracy i integracja nie powinna powstać.

## 8. Koszt moderacji i „co to zabiera”

Brak danych produkcyjnych o sporach po imporcie. To **jawny model obciążenia**, do zastąpienia pomiarem. Jednostka: 1000 prób publikacji OCR i osobno 1000 URL/miesiąc, nie 1000 pobrań.

Godziny = `(N × udział_kontroli × min_kontroli + N × udział_zgłoszeń × min_sprawy) / 60`.

| Scenariusz na 1000 publikacji daną drogą | Wstępny przegląd | Późniejsze sprawy | Godziny / koszt po 60 PLN/h |
|---|---|---|---:|
| Ręczne — założony punkt odniesienia | 0% | 1% × 15 min | 2,5 h / 150 PLN |
| OCR — bazowy | 10% × 5 min (niepewne prawa) | 2% × 20 min | 15 h / 900 PLN |
| URL — bazowy, wszystkie publikacje sprawdzane | 100% × 5 min | 5% × 20 min | 100 h / 6 000 PLN |
| OCR — przeciążenie | 25% × 8 min | 5% × 30 min | 58,33 h / 3 500 PLN |
| URL — przeciążenie | 100% × 8 min | 10% × 30 min | 183,33 h / 11 000 PLN |

1000 OCR + 1000 URL: bazowo **115 h / 6900 PLN** zamiast 5 h / 300 PLN dla 2000 ręcznych, przyrost **110 h / 6600 PLN**. Przeciążenie: **241,67 h / 14 500 PLN**. Późniejsze skargi mogą dotyczyć już sprawdzonych przepisów — celowo: kontrola nie wyklucza reklamacji. Przy OCR niepewność deklaruje człowiek lub wynika z konkretnej przesłanki, nie z „wykrycia plagiatu” przez automat.

Pilotaż: maksymalnie **50 próśb o publikację URL/miesiąc** (bazowo 5 h) i 200 OCR (3 h), plus **20% rezerwy na korespondencję/odwołania: 1,6 h**. Razem 9,6 h / 576 PLN, poza dyżurem i resztą moderacji. Rezerwa prawna na spory 2–4 h/miesiąc = 1000–2000 PLN; niewykorzystana nie jest wydatkiem. Gdy najstarsza sprawa przekracza cel 24 h lub wyczerpano czas pracy, wstrzymać nowe prośby o publikację URL, zachowując szkice. Nie blokować przyjmowania zgłoszeń prawnych.

Funkcja zabiera:

- **Czas gospodarza:** przy 1000 URL sam przegląd to ok. 83 h przed skargami, odebrane rozmowom i nowym osobom.
- **Prostotę:** dwa wejścia, oczekiwanie, poprawki i prawa. Opcjonalnie; ręczne dodawanie pozostaje najkrótszą drogą.
- **Prywatność/zaufanie:** zeszyt trafia do dostawcy, skan może ujawnić nazwisko. Potrzebne minimalizacja, jawny wybór i usuwanie.
- **Bezpieczeństwo receptury:** OCR myli dawkę, temperaturę, czas przetworów. Kontrola człowieka konieczna, ale nie gwarantuje braku błędu.
- **Reputację:** bloger może uznać Kuking za narzędzie kradzieży ruchu i tekstów mimo linku. Prawa, współpraca i szybka odpowiedź ograniczają ryzyko, nie usuwają go.
- **Budżet/utrzymanie:** umowy, zmiany HTML, antyspam, izolacja, kolejka, archiwum i prawnik kosztują więcej niż rozpoznanie znaków.
- **Uwagę:** nowe powiadomienie o sprawdzeniu wersji. Wynik OCR wystarczy na ekranie/szkicach; bez e-maila po każdym skanie i bez mieszania z „Ugotowałem”.

## 9. Decyzje właściciela z rekomendacją

| Decyzja | Rekomendacja | Koszt alternatywy |
|---|---|---|
| Kolejność/etap | V2, najpierw pomiar OCR | Równoległy start angażuje 20–30 dni technicznych i podwaja odbiór. |
| Dowolna domena czy lista | 2–3 uzgodnione źródła z prawami do kopii | Otwarty importer zwiększa prawo/SSRF/utrzymanie; brak uczciwej kwoty bez pilotażu. |
| Publikacja URL | Trwałe źródło, sprawdzenie każdej wersji | Checkbox tańszy, ale nie chroni przed kopią; wyłącznie prywatny import usuwa kolejkę publikacji kosztem wartości społecznej. |
| Zachowanie kartki | Do usunięcia, prywatnie, niezależnie od OCR | Dosłowne „na zawsze” koliduje z usuwaniem/retencją i zwiększa storage. |
| Dostawca | Cloud Vision po pomiarze i umowie | Tesseract ogranicza transfer danych, użyteczność dla rękopisu nieudowodniona; własny model poza wyceną. |
| Obsada/skala | Dyżurny + zastępca, 24 h przegląd / 72 h prosta decyzja, 50 URL/miesiąc | Bez obsady tylko prywatny pilotaż; 1000 URL to ok. 100 h moderacji/miesiąc. |
| Retencja/licencje | Zatwierdzenie prawnika przed budową publikacji | Nie utrwalać nieuzgodnionych okresów testami ani nie używać roboczej licencji UGC. |

Nie potrzeba decyzji, czy sam link daje prawa — nie daje. Potrzeba akceptacji polityki, obsady, budżetu i przetwarzania. Właściciel może odrzucić rekomendację produktową; jego akceptacja nie zastąpi podstawy prawnej.

## 10. Przyszły odbiór i ograniczenia badania

Przed implementacją: zaakceptowane zasady, korpus z prawami, umowa OCR, źródła, limit kosztu i obsada. Potem testy wszystkich zapisów: obejście źródła, edycja po akceptacji, retry/podwójne kliknięcie, nadpisanie nowej edycji wynikiem joba, cudzy intake/media, wyciek skanu, publikacja dla obserwujących, eksport/usunięcie/rollback. Parser: obiekt/tablica/@graph/mikrodane, zagnieżdżone kroki, brak/wiele Recipe, uszkodzony JSON, XSS, robots/noindex, przekierowania, IPv6/rebinding, bomby kompresji i timeout. Każdy strażnik z kontrolą dodatnią i ujemną.

Po miesiącu: koszt udanego szkicu, udział użytych wyników, czas poprawek, p95 z kolejką, rzeczywiste gotowanie, zgłoszenia/100 publikacji, minuty moderatora. Kontynuować tylko przy oszczędności czasu użytkownika i obsługiwalnym koszcie społeczności.

Nie wykonano: płatnego OCR, benchmarku rękopisu, kompletnego parsera, pomiaru ruchu/moderacji produkcji, negocjacji z wydawcami, porady prawnej ani odbioru nieistniejących ekranów. Nie zmieniono kodu produkcyjnego, migracji, tras, modeli, widoków, regulaminu i decyzji. Bez wiadomości, push i PR. Wynikiem jest projekt i lokalny commit, nie gotowość funkcji.

## 11. Wykonane kontrole i przekazanie

Własny pomiar na niezmienionym kodzie bazowym, runtime `/home/mateusz/flota/gpt-ocr-import-run`, PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_gpt-ocr-import`, rola `kuking`:

- `testuj.sh gpt-ocr-import --filter '^(?!.*ProbaOdtworzeniaTest)' --compact`: **4393 passed, 83 692 assertions, 478,23 s**, kod wyjścia 0. Zawiera RecipeTest, RecipeWizardTest i testy źródła/zgłoszeń. Nie dowodzi działania przyszłego OCR ani importera.
- `ProbaOdtworzeniaTest` pominięty dokładnie po nazwie zgodnie z dopuszczeniem zlecenia — jego skrypt korzysta ze wspólnej bazy próby. Pierwszy niepełny przebieg zatrzymano przed tym testem, gdy okazało się, że wykluczenie grupy nie odpowiada nazwie klasy; wynik powyżej pochodzi z kolejnego kompletnego przebiegu z poprawnym filtrem.
- Po odświeżeniu runtime `vendor/bin/pint`: **PASS, 1155 files**, kod wyjścia 0. Wywołanie przez PHP 8.4 z `/opt/kuking-php-8.4-avif/bin/php`.
- Nie dodano testów cementujących decyzje produktowe ani testu regresji do nieimplementowanej poprawki. Kontrola ujemna będzie obowiązkowa przy przyszłych zmianach kodu.

W trakcie pracy zniknęła rejestracja worktree i gałąź; `git worktree repair` nie znalazł repozytorium wskazanego przez `.git`. Zachowano cały katalog jako `C:\Users\matma\Documents\kuking-flota\gpt-ocr-import-recovery-20260920`, odtworzono wyłącznie własne stanowisko i gałąź od tego samego SHA, przeniesiono dokument. Nie wykonywano prune, reset ani operacji na cudzych stanowiskach. Kopia odzyskania pozostaje do dyspozycji właściciela.
