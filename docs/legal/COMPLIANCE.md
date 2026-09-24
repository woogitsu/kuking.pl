# Kuking.pl — analiza compliance (DSA, RODO, prawo autorskie, ePrivacy, EAA)

> **To nie jest porada prawna.** To robocza analiza przygotowana przez AI na potrzeby planowania produktu, oparta na czytaniu tekstów aktów prawnych i wtórnych źródeł branżowych dostępnych we wrześniu 2026. Przed publicznym startem serwisu **każdy punkt oznaczony jako wymagający decyzji prawnej musi zostać zweryfikowany przez radcę prawnego/adwokata specjalizującego się w prawie nowych technologii**. Numery artykułów i progi kwotowe sprawdzano w wielu źródłach, ale prawo się zmienia — traktuj to jako punkt startowy do rozmowy z prawnikiem, nie jako gotowe zabezpieczenie.

Założenia przyjęte w analizie: operator to mikro- lub mały przedsiębiorca (podmiot polski, self-hosted na Railway w UE), serwis nie jest wyznaczony jako VLOP (Very Large Online Platform — próg to 45 mln aktywnych odbiorców miesięcznie w UE), nie ma na starcie płatności/marketplace'u, nie kieruje reklam behawioralnych do dzieci, feed jest chronologiczny (brak systemu rekomendacji w rozumieniu DSA na MVP).

---

## 1. DSA (Rozporządzenie (UE) 2022/2065) — co dotyczy Kuking na starcie

Kuking.pl to **hosting service** świadczący usługę **online platform** (umożliwia przechowywanie i publiczne rozpowszechnianie treści na żądanie odbiorców — zdjęcia, przepisy, komentarze). To kwalifikuje serwis do Rozdziału III RODO... nie, DSA — Sekcja 1 (wszyscy dostawcy usług pośrednich), Sekcja 2 (dodatkowo dla hostingu, w tym platform), Sekcja 3 (dodatkowo dla platform online, chyba że mikro/małe przedsiębiorstwo).

### 1.1 Obowiązki, które dotyczą Kuking **zawsze** (Sekcja 1 i 2 — brak zwolnienia dla małych podmiotów)

| Art. | Obowiązek | Co to znaczy w praktyce dla Kuking |
|---|---|---|
| Art. 11 | Punkt kontaktowy dla organów (państw członkowskich, Komisji, Rady ds. Usług Cyfrowych) | Adres e-mail + wskazany język komunikacji (polski, ewentualnie angielski) publikowany łatwo dostępnie |
| Art. 12 | Punkt kontaktowy dla użytkowników (odbiorców usługi) | Ten sam lub osobny e-mail; **nie może to być wyłącznie bot/formularz bez możliwości bezpośredniego kontaktu** — musi umożliwiać szybką, bezpośrednią komunikację |
| Art. 13 | Przedstawiciel prawny w UE | Nie dotyczy — operator jest w Polsce, czyli w UE. Istotne tylko, gdyby operator przeniósł się poza UE |
| Art. 14 | Regulamin (terms and conditions) — jasny, zrozumiały, w prostym języku, informacje o polityce moderacji, wykorzystaniu narzędzi automatycznych, prawach użytkownika | Regulamin musi jasno opisać zasady moderacji treści (patrz `resources/legal/regulamin.md`); dla serwisu z użytkownikami 50+ obowiązek "plain language" jest tu wyjątkowo ważny merytorycznie, nie tylko formalnie |
| Art. 15 | Sprawozdawczość przejrzystości dla dostawców usług pośrednich (co najmniej raz w roku) | **Mikro/małe przedsiębiorstwa są zwolnione** (Art. 19) — patrz niżej |
| Art. 16 | Mechanizm zgłaszania i działania (notice and action) dla nielegalnych treści | **Obowiązkowy niezależnie od wielkości.** Musi być: elektroniczny, łatwo dostępny, przyjazny użytkownikowi, umożliwiać wskazanie dokładnej lokalizacji treści i wyjaśnienie dlaczego treść jest nielegalna. Formularz "Zgłoś" w Kuking musi to spełniać |
| Art. 17 | Uzasadnienie decyzji (statement of reasons) | **Obowiązkowy niezależnie od wielkości**, dla usług hostingu. Przy każdym usunięciu/ukryciu/ograniczeniu treści lub zawieszeniu konta z powodu nielegalności lub naruszenia regulaminu → użytkownik musi dostać jasne uzasadnienie (podstawa, fakty, czy decyzja była zautomatyzowana, informacja o możliwości odwołania). Musi też trafiać do unijnej bazy DSA Transparency Database, jeśli dotyczy platformy online (nie tylko czystego hostingu) — [do weryfikacji, czy przy zwolnieniu z Art. 19 ten obowiązek zgłaszania do bazy nadal obowiązuje w pełnym zakresie; praktyka wskazuje, że tak, bo Art. 17 jest w Sekcji 2, nie 3] |
| Art. 18 | Zgłaszanie podejrzeń przestępstw zagrażających życiu/bezpieczeństwu do organów ścigania | **Obowiązkowy niezależnie od wielkości.** Dotyczy np. CSAM, gróźb, treści wskazujących na zagrożenie życia — patrz `MODERATION_PLAYBOOK.md` sekcja o zero-tolerancji |

### 1.2 Obowiązki Sekcji 3 (Art. 19–28) — od których Kuking **jest zwolniony** jako mikroprzedsiębiorstwo

Art. 19 DSA wyłącza dostawców platform online będących mikro- lub małym przedsiębiorstwem (w rozumieniu załącznika do zalecenia 2003/361/WE: mikro = <10 zatrudnionych i obrót/suma bilansowa ≤2 mln EUR; małe = <50 zatrudnionych i obrót/suma bilansowa ≤10 mln EUR) z obowiązków Sekcji 3, **z wyjątkiem obowiązku publikowania średniej liczby aktywnych odbiorców usługi miesięcznie (Art. 24 ust. 3)** — ten wyjątek istnieje, żeby regulator mógł monitorować, czy platforma zbliża się do progu VLOP.

**Na czym to zwolnienie stoi u nas — i kiedy przestanie działać.** Serwis prowadzi **SAMSUFI sp. z o.o.** (D-040), więc pytanie „czy operator w ogóle jest przedsiębiorstwem" u nas nie istnieje: spółka handlowa nim jest, bez wykładni. Przy dzisiejszej skali próg mikroprzedsiębiorstwa jest spełniony z dużym zapasem. Dwa zastrzeżenia, zanim ktoś potraktuje ten rozdział jak stan wieczysty:

1. **Progi liczy się dla całego przedsiębiorstwa, nie dla serwisu.** Jeśli SAMSUFI ma przedsiębiorstwa partnerskie lub powiązane, ich zatrudnienie i obrót dolicza się do progu (Zalecenie 2003/361/WE art. 6). Tego nie da się sprawdzić z repozytorium — to wie właściciel.
2. **Statusu nie traci się natychmiast.** Przekroczenie progu w jednym roku obrotowym niczego nie zmienia; status znika dopiero po dwóch kolejnych latach powyżej progu (art. 4 ust. 2 Zalecenia). Jest więc czas na wdrożenie Sekcji 3, ale skończony.

**Czego zwolnienie nie obejmuje w żadnym wariancie:** Art. 16 (zgłaszanie treści) i Art. 17 (uzasadnienie decyzji) leżą w **Sekcji 2** i obowiązują niezależnie od wielkości. Zwolnienie z Sekcji 3 nigdy nie jest podstawą, żeby wyłączyć formularz zgłoszeń albo przestać uzasadniać decyzje moderacyjne.

Historia tego ustalenia — dlaczego przez chwilę wyglądało na wątpliwe i co je rozstrzygnęło — jest w `docs/decyzje/OPERATOR.md` §3.

Zwolnione (dopóki Kuking spełnia progi mikro/małego przedsiębiorstwa i nie zostanie wyznaczony jako VLOP):

- **Art. 20** — wewnętrzny system rozpatrywania skarg (internal complaint-handling system) — formalnie niewymagany, ale **już wdrożony i obiecany**: regulamin §8 daje sześć miesięcy na odwołanie, a `ModerationAction::appealDeadline()` to egzekwuje (D-038). Od chwili wpisania do regulaminu nie jest to dobra wola, tylko zobowiązanie wobec użytkownika — skrócenie wymaga zmiany regulaminu i powiadomienia ludzi, nie zmiany konfiguracji.
- **Art. 21** — pozasądowe rozstrzyganie sporów (ODS) — niewymagane, nie trzeba przystępować do certyfikowanego podmiotu ODS.
- **Art. 22** — status "zaufanych podmiotów sygnalizujących" (trusted flaggers) z priorytetowym traktowaniem zgłoszeń — niewymagany.
- **Art. 23** — środki przeciw nadużyciom (zawieszanie kont notorycznie nadużywających zgłoszeń) — niewymagany, choć warto mieć z powodów praktycznych (spam w zgłoszeniach).
- **Art. 24** (poza ust. 3) — pełne sprawozdanie przejrzystości platformy (statystyki moderacji, liczba sporów itd.) — niewymagane.
- **Art. 25** — zakaz "dark patterns" w projektowaniu interfejsu — [do weryfikacji: literalnie w Sekcji 3, więc formalnie zwolniony, ale wiele kancelarii traktuje to jako "dobrą praktykę bezwzględną" i część krajowych organów konsumenckich może to egzekwować z innej podstawy (nieuczciwe praktyki rynkowe, UOKiK); rekomendacja: **stosować się tak, jakby obowiązywało** — to tani do wdrożenia standard, a ryzyko reputacyjne/konsumenckie jest niezależne od DSA].
- **Art. 26** — transparentność reklam na platformie — niewymagany na starcie (Kuking nie sprzedaje reklam ukierunkowanych); istotne, gdy pojawi się model reklamowy.
- **Art. 27** — transparentność systemu rekomendacji — niewymagany; i tak nieaktualny przy chronologicznym feedzie z MVP.
- **Art. 28** — ochrona małoletnich online (wysoki poziom prywatności, bezpieczeństwa; zakaz reklam targetowanych do dzieci) — formalnie w Sekcji 3, więc też objęty zwolnieniem dla mikro/małych **w części** — ale UWAGA: Komisja Europejska wydała w 2025 r. wytyczne do Art. 28 ust. 1 sugerujące, że oczekiwania co do ochrony małoletnich stają się de facto standardem branżowym niezależnie od wielkości podmiotu, i część prawników ostrzega, że "small business" nie oznacza zerowej odpowiedzialności za bezpieczeństwo dzieci na platformie ([do weryfikacji z prawnikiem — to obszar świeży i politycznie wrażliwy]). **Rekomendacja produktowa: traktować ochronę małoletnich jako obowiązującą niezależnie od formalnego zwolnienia** — patrz sekcja 4 niżej.

### 1.3 Co to oznacza praktycznie — minimalny zestaw DSA dla Kuking na start

1. Formularz zgłaszania treści spełniający Art. 16 (nie tylko ikonka flagi — patrz `MODERATION.md`, już to zakłada) **wraz z odpowiedzią dla zgłaszającego: potwierdzeniem przyjęcia (ust. 4) i informacją o decyzji z pouczeniem o dostępnych środkach (ust. 5)**. To są osobne obowiązki od samego formularza i obowiązują obie drogi zgłoszenia — z kontem i bez (issue #10, `docs/MODERATION.md`, „Co dostaje ZGŁASZAJĄCY”).
2. Szablon uzasadnienia decyzji (Art. 17) — patrz `MODERATION_PLAYBOOK.md`, sekcja szablonów.
3. Procedura eskalacji do organów ścigania przy podejrzeniu przestępstwa zagrażającego życiu (Art. 18) — patrz zero-tolerancja CSAM.
4. Punkty kontaktowe w regulaminie (Art. 11, 12) i regulamin w prostym języku (Art. 14).
5. Monitorowanie liczby aktywnych użytkowników miesięcznie i gotowość do jej publikacji (Art. 24 ust. 3) — nawet będąc zwolnionym z reszty raportowania.
6. Zapisywanie decyzji moderacyjnych (baza danych — kolumna `status`, `reason` w tabeli zgłoszeń) na wypadek utraty statusu małego przedsiębiorstwa lub kontroli koordynatora.

### 1.4 Nadzór w Polsce

Prezes UKE (Urząd Komunikacji Elektronicznej) został wyznaczony na **Koordynatora ds. Usług Cyfrowych** dla Polski. Ustawa wdrażająca DSA do polskiego porządku prawnego (nowelizacja ustawy o świadczeniu usług drogą elektroniczną) była w toku legislacyjnym jeszcze w 2025 r. — Sejm procedował ją pod koniec 2025 r. [do weryfikacji: aktualny status wejścia w życie na wrzesień 2026 — sprawdzić na stronie Sejmu/UKE przed startem, bo od tego zależą krajowe sankcje i tryb skarg]. Niezależnie od statusu ustawy krajowej, DSA jako rozporządzenie UE **obowiązuje bezpośrednio** od 17 lutego 2024 r.

---

## 2. RODO (GDPR)

### 2.1 Rola administratora

Kuking.pl (operator) jest **administratorem danych** (data controller) dla danych kont, profili, treści, logów. Nie jest to relacja współadministrowania z użytkownikami — to oni są podmiotami danych, nie współadministratorami, nawet jeśli publikują dane innych osób (np. na zdjęciu) — tu działa **wyłączenie działalności czysto osobistej lub domowej** (Art. 2 ust. 2 lit. c RODO) po stronie użytkownika, ale **nie zdejmuje to z operatora odpowiedzialności za samą platformę** jako administratora infrastruktury i kont.

### 2.2 Tabela: cel przetwarzania | dane | podstawa prawna | retencja

| Cel | Dane | Podstawa prawna (Art. 6 RODO) | Sugerowana retencja |
|---|---|---|---|
| Założenie i obsługa konta | e-mail, hasło (hash), status konta, ustawienia (locale, text_scale) | Art. 6(1)(b) — wykonanie umowy (regulamin = umowa o świadczenie usługi drogą elektroniczną) | Przez czas trwania konta + [do ustalenia z prawnikiem, zwykle 30–90 dni] okres "soft delete" na wypadek pomyłki, potem trwałe usunięcie |
| Profil publiczny (username, display name, bio, avatar) | dane podane dobrowolnie przez użytkownika | Art. 6(1)(b) — realizacja funkcji usługi, do której użytkownik się zapisał | Do usunięcia konta lub zmiany przez użytkownika |
| Zdjęcia (oryginały i warianty) | piksele, EXIF w oryginale (data, model aparatu, GPS) | Art. 6(1)(b) — realizacja usługi publikowania treści | Do usunięcia zdjęcia przez użytkownika **lub do usunięcia konta — wtedy kasowane są WSZYSTKIE**, razem z cache CDN-u (D-018) |
| Treść tekstowa (posty, przepisy, komentarze) | tekst, historia wersji przepisu | Art. 6(1)(b) — realizacja usługi publikowania treści | Do usunięcia treści przez użytkownika. Przy usunięciu konta **decyduje sam użytkownik** (D-022, `users.delete_scope`): domyślnie **tekst zostaje, zanonimizowany** — podpisany „Użytkownik usunięty" (D-018); po zaznaczeniu haczyka na ekranie usuwania konta tekst jest **kasowany na stałe** razem z wpisami, przepisami, komentarzami, wykonaniami i zeszytami; wersje historyczne przepisu — do ustalenia limitu (np. ostatnie N wersji) |
| Relacje społecznościowe (follow, block) | ID obserwującego/obserwowanego | Art. 6(1)(b) | Do usunięcia relacji lub konta |
| Zgłoszenia treści i moderacja | zgłaszający, zgłoszony, powód, decyzja, uzasadnienie | Art. 6(1)(c) — obowiązek prawny (DSA Art. 16–18) oraz Art. 6(1)(f) — uzasadniony interes (bezpieczeństwo platformy) | Dłuższa niż dane samej treści — rekomendacja [do ustalenia z prawnikiem]: 12–24 miesiące od zamknięcia sprawy, dla obrony przed roszczeniami i nadzoru DSA |
| Logi bezpieczeństwa (audit log, próby logowania, IP) | IP, user agent, timestamp, typ zdarzenia | Art. 6(1)(f) — uzasadniony interes (bezpieczeństwo, wykrywanie nadużyć) | Krótka — rekomendacja 90 dni dla logów ogólnych, dłużej tylko dla zdarzeń związanych z aktywnym incydentem bezpieczeństwa |
| Powiadomienia in-app | treść powiadomienia, status przeczytania | Art. 6(1)(b) | Do usunięcia/przeczytania + rozsądny bufor |
| Analityka produktowa (PostHog) | zdarzenia UI, w miarę możliwości bez identyfikatorów bezpośrednich | Art. 6(1)(f) — uzasadniony interes, **o ile** spełnione warunki testu równoważenia i **niezależnie** od wymogu zgody na poziomie ePrivacy dla cookies/localStorage (patrz sekcja 5) | Krótka, rekomendacja 6–14 miesięcy, zagregowane dane bez limitu |
| Analityka odwiedzin (Cloudflare Web Analytics, **wdrożone** 10.09.2026 — D-092) | adres odsłoniętej strony i adres źródła wejścia (oba **bez query stringu** — skrypt czyści `search`, `hash`, `username` i `password`), rodzaj i wersja przeglądarki, czasy wczytania (Web Vitals), kraj doliczany przez Cloudflare z samego połączenia; **bez** ciasteczek, **bez** zapisu na urządzeniu, identyfikator odsłony losowany w pamięci na jedno wczytanie strony | Art. 6(1)(f) — uzasadniony interes (wiedza, czy serwis komukolwiek się przydaje); ePrivacy/PKE nie wchodzi w grę, bo nie ma zapisu ani odczytu na urządzeniu (sekcja 5.5) | Po stronie Cloudflare, agregaty bez limitu; my nie trzymamy kopii |
| Błędy aplikacji (Sentry) | stack trace, czasem fragmenty requestu — **ryzyko wycieku PII w treści błędu** | Art. 6(1)(f) — uzasadniony interes (utrzymanie usługi) | Rekomendacja 30–90 dni; **skonfigurować scrubbing PII w Sentry (data scrubbing rules) przed startem** |
| Newsletter/e-mail transakcyjny (reset hasła, powiadomienia) | e-mail, treść wiadomości | Art. 6(1)(b) dla e-maili transakcyjnych; Art. 6(1)(a) zgoda dla e-maili marketingowych, jeśli takie się pojawią | Jak konto / do wycofania zgody |

**Dlaczego zdjęcia i tekst są tu rozdzielone** (D-018, audyt W4-01): tekst
przepisu po podmianie podpisu przestaje być danymi osobowymi. Zdjęcie nie —
dane są w pikselach (twarz, wnętrze mieszkania, dokument na stole), a
w oryginale dodatkowo w EXIF-ie. Anonimizacja podpisu nie zmienia tam niczego,
więc zdjęcia przy usunięciu konta kasujemy w całości.

Tekst zostaje, bo to jest już także cudza historia: ktoś odpowiedział
w komentarzu, ktoś ugotował z tego przepisu i ma go w swoim zeszycie.

**Od D-022 to jest jednak DOMYŚLNA opcja, a nie jedyna.** Anonimizacja jest
naszą oceną, że tak jest lepiej dla społeczności — a tej oceny nie wolno robić
za kogoś przy jego własnych danych: część ludzi usuwa konto właśnie po to, żeby
ich słowa zniknęły. Ekran usuwania konta ma więc odhaczony haczyk „Usuń także
moje przepisy, wpisy, komentarze, wykonania i zeszyty", a wybór zapisuje się
w `users.delete_scope` przy zgłoszeniu (`minimum` / `everything`). Ekran
wymienia w TRZECH listach: co znika zawsze, co zostaje przy haczyku
nietkniętym i co znika dodatkowo po jego zaznaczeniu — razem z ceną, czyli
cudzymi komentarzami i wykonaniami stojącymi pod kasowaną treścią.

**Stan końcowy konta ma od D-022 własny status** (`users.status = 'erased'`).
Do tej pory konto zostawało po anonimizacji na `pending_delete`, na którym stoi
granica widoczności treści — więc zanonimizowany tekst zostawał w bazie i
znikał ze serwisu (403). Obietnica z tego akapitu nie była spełniona przez
kilkanaście commitów; szczegóły i pomiar: `docs/DATABASE.md`, sekcja
„`status = 'erased'` i `delete_scope`".

### 2.3 Powierzenie przetwarzania (processors) — co zrobić

Każdy z poniższych podmiotów jest **procesorem** i wymaga **umowy powierzenia przetwarzania danych (DPA — data processing agreement, Art. 28 RODO)**:

- **Railway** (hosting, PostgreSQL) — sprawdzić lokalizację centrów danych (deklarowane UE) i czy Railway oferuje standardową umowę DPA; jeśli infrastruktura Railway w praktyce korzysta z podwykonawców spoza UE (np. AWS/GCP regiony), potrzebne są **standardowe klauzule umowne (SCC)** — [do weryfikacji bezpośrednio w dokumentacji Railway, to się zmienia].
- **Cloudflare R2 / S3** — podobnie: DPA + sprawdzić region bucketa (wymusić EU region), Cloudflare ma globalny DPA dostępny z poziomu panelu.
- **Sentry** — DPA dostępny standardowo (Sentry/Functional Software Inc. — spółka US, ale oferuje hosting UE — **wybrać explicit region UE przy konfiguracji projektu** i podpiąć SCC).
- **PostHog** — dostępny w wariancie **PostHog Cloud EU** (Frankfurt) — **wybrać ten wariant**, nie US-cloud, żeby uniknąć transferu poza EOG.
- **Dostawca poczty transakcyjnej** (np. Postmark/SES/Resend) — sprawdzić region wysyłki i DPA; e-maile zawierają dane osobowe (adres, czasem treść powiadomienia) więc też wymagają DPA.

**Rekomendacja:** prowadzić prostą tabelę "Rejestr podprocesorów" (nazwa, cel, kraj/region, czy jest DPA podpisane, czy dane opuszczają EOG) — to samo w sobie ułatwia odpowiedź na pytania klientów/regulatora i jest dobrą praktyką nawet bez formalnego obowiązku publikacji takiej listy.

### 2.4 Transfery poza EOG

Domyślnie: **unikać**. Wybierać regiony UE we wszystkich usługach (Railway, R2, Sentry, PostHog — wszystkie mają opcję UE). Jeśli jakikolwiek podprocesor jest spółką z siedzibą w USA (np. Sentry, część dostawców e-mail), nawet przy hostowaniu danych w UE **transfer może zachodzić** przez dostęp zdalny/wsparcie techniczne — od 2023 r. wielu dostawców USA jest certyfikowanych w ramach **EU-US Data Privacy Framework (DPF)**, co jest uznaną podstawą transferu — **sprawdzić certyfikację DPF konkretnego dostawcy przed podpisaniem umowy** [do weryfikacji per dostawca, lista certyfikowanych firm jest publiczna na stronie dataprivacyframework.gov].

### 2.5 DPIA (ocena skutków dla ochrony danych)

Prawdopodobnie **niewymagana obowiązkowo** dla podstawowego zakresu Kuking MVP — nie ma tu przetwarzania danych szczególnych kategorii na dużą skalę, systematycznego monitorowania na dużą skalę ani profilowania z istotnymi skutkami prawnymi. Jednak warto rozważyć **dobrowolną, uproszczoną DPIA** (pół strony) dla:
- modułu moderacji (przetwarzanie zgłoszeń, potencjalnie danych o naruszeniach — to zbliża się do "danych o naruszeniach prawa" w rozumieniu Art. 10 RODO, jeśli dotyczy przestępstw),
- ewentualnego rozpoznawania obrazów/moderacji automatycznej zdjęć w przyszłości (hash porównawczy z bazami CSAM — to wrażliwy obszar operacyjnie, nie tylko dla ochrony danych).

[do weryfikacji z prawnikiem: czy prowadzenie skanowania zdjęć pod kątem CSAM (np. PhotoDNA/hash-matching) rodzi własne obowiązki DPIA i podstawy prawnej — rekomendacja: tak, zapytać prawnika zanim się to wdroży, bo dotyka Art. 9/10 RODO i przepisów karnych jednocześnie]. Rejestr czynności przetwarzania (Art. 30 RODO) — obowiązkowy nawet dla małych podmiotów, jeśli przetwarzanie nie jest "okazjonalne" — a przetwarzanie danych kont użytkowników na platformie społecznościowej **nie jest okazjonalne**, więc **rejestr czynności przetwarzania jest obowiązkowy** niezależnie od wielkości firmy (wyjątek dla małych firm w Art. 30 ust. 5 RODO dotyczy tylko przetwarzania okazjonalnego, bez ryzyka dla praw i wolności — to nie pasuje do serwisu społecznościowego).

### 2.6 Prawa podmiotów danych — realne terminy

| Prawo | Termin realizacji | Uwaga praktyczna |
|---|---|---|
| Dostęp (Art. 15) | bez zbędnej zwłoki, max 1 miesiąc (przedłużalne o 2 miesiące przy skomplikowanych wnioskach, z informacją do wnioskodawcy) | Paczka z ustawień (`CollectUserExportData`) plus droga na żądanie. Mapa „tabela/kolumna → eksport / na żądanie / nie dotyczy” to `app/Domain/Users/Exports/InwentarzDanychKonta.php`; test `EksportObejmujeKazdaTabeleKontaTest` oblewa, gdy w schemacie pojawi się kolumna wskazująca na konto bez rozstrzygnięcia (#953). Kategorie „na żądanie” paczka wypisuje w `kategorie_poza_paczka` z powodami — przy ręcznym żądaniu z art. 15 trzeba je wydać osobno (poświadczeń nie wydajemy nigdy) |
| Sprostowanie (Art. 16) | jw. | Realizowane przez edycję profilu/treści przez użytkownika samodzielnie w większości przypadków |
| Usunięcie / "prawo do bycia zapomnianym" (Art. 17) | jw. | Usunięcie konta w MVP — zwrócić uwagę na **backupy** (patrz niżej) i na **treści, które zostały skomentowane/cytowane przez innych** — trzeba mieć politykę, czy komentarze usuniętego użytkownika zostają (zwykle: tak, zanonimizowane jako "użytkownik usunięty") |
| Przenoszenie danych (Art. 20) | jw. | Eksport w formacie strukturalnym (JSON/CSV) — MVP i tak to zakłada |
| Sprzeciw (Art. 21) | jw. | Dotyczy głównie przetwarzania na podstawie uzasadnionego interesu (np. analityka) |
| Ograniczenie przetwarzania (Art. 18) | jw. | Rzadko używane w praktyce małych serwisów, ale trzeba mieć procedurę (np. oznaczenie konta jako "zamrożone" do wyjaśnienia sporu) |

### 2.7 Zgłaszanie naruszeń (Art. 33–34 RODO)

- Zgłoszenie do UODO **w ciągu 72 godzin** od stwierdzenia naruszenia (jeśli naruszenie stwarza ryzyko dla praw/wolności osób) — liczy się od momentu, gdy administrator **dowiedział się** o naruszeniu, nie od momentu jego wystąpienia.
- Powiadomienie osób, których dane dotyczą — **bez zbędnej zwłoki**, jeśli naruszenie stwarza **wysokie ryzyko** (np. wyciek haseł w postaci jawnej, wyciek e-maili + treści prywatnych).
- **Rekomendacja operacyjna:** mieć gotowy szablon zgłoszenia do UODO i szablon e-maila do użytkowników **przed** incydentem, nie w trakcie paniki — patrz `SECURITY_BASELINE.md`, sekcja runbook.

### 2.8 Retencja i usuwanie konta

- Zaprojektować **hard delete** po okresie karencji (rekomendacja: 30 dni "pending_delete" — kolumna `status` w schemacie już to przewiduje) — w tym czasie użytkownik może cofnąć decyzję.
- Po 30 dniach: usunięcie/anonimizacja danych osobowych, natomiast **treści z realną wartością społeczną (np. przepis, do którego inni się odwoływali) mogą zostać zachowane w formie zanonimizowanej** ("autor: konto usunięte") — to standardowa praktyka portali społecznościowych, ale **musi być jasno opisana w regulaminie i polityce prywatności**, żeby nie zaskoczyć użytkownika.
- **Backupy bazy danych** zawierają dane osobowe do czasu rotacji backupu — polityka prywatności musi podać maksymalny czas życia backupu (np. "usunięte dane mogą pozostawać w kopiach zapasowych do X dni") — to częsty błąd pomijany w politykach prywatności małych serwisów.

---

## 3. Prawo autorskie i treści użytkowników

### 3.1 Przepisy kulinarne — co jest, a co nie jest chronione

Zgodnie z ugruntowanym stanowiskiem doktryny i orzecznictwa (w tym powoływany wyrok Sądu Najwyższego z lat 30. XX w. dot. książki kucharskiej) oraz aktualnymi analizami prawniczymi:

- **Sama receptura/metoda/lista składników i kroków nie jest chroniona prawem autorskim** — to "sposób działania" (idea/procedura), a te są wprost wyłączone spod ochrony (Art. 1 ust. 2¹ ustawy o prawie autorskim i prawach pokrewnych). Każdy może opublikować identyczny przepis "sernik babci" własnymi słowami.
- **Konkretna forma wyrażenia przepisu** (specyficzny, twórczy opis, narracja, oryginalny układ) **może** być utworem, jeśli ma cechy indywidualnej twórczości — im bardziej "sucha instrukcja" (składniki + kroki), tym mniejsza szansa na ochronę; im bardziej osobista narracja/styl, tym większa.
- **Zdjęcie potrawy** — jak każda fotografia, może być utworem fotograficznym, jeśli ma twórczy charakter (kompozycja, światło, ujęcie) — w praktyce niemal każde zdjęcie na Instagramie/blogu spełnia ten niski próg.
- **Cała książka kucharska jako zbiór** może być chroniona jako "utwór zbiorowy" ze względu na dobór i układ, nawet jeśli pojedyncze przepisy nie są chronione.

**Konsekwencja dla polityki Kuking:**
- Nie zabraniać publikowania "tego samego przepisu" co u innych — to wprost legalne (idea nie jest chroniona).
- **Zabronić/moderować kopiowanie 1:1 cudzego opisu/tekstu** (skopiuj-wklej z bloga/książki) — to już może naruszać prawa autorskie do konkretnego wyrażenia, niezależnie od tego, że sam przepis jest "wolny".
- **Zabronić używania cudzych zdjęć** bez zgody/wskazania źródła — to najbardziej realne ryzyko roszczeń (łatwe do wykrycia przez reverse image search, częsta podstawa realnych sporów w Polsce dot. blogów kulinarnych).
- Pole `source_type` w schemacie (`own | family | adaptation | external`) jest dobrym rozwiązaniem UX — rekomendacja: gdy `external`, wymagać `source_url` i wyświetlać wyraźne odesłanie/atrybucję, oraz **nie pozwalać na wklejenie pełnej treści opisu ze źródła zewnętrznego** (tylko link + własnymi słowami).

### 3.2 „Przepis po mamie” / rodzinny

Prawnie identyczne z każdym innym przepisem — nie ma specjalnej kategorii prawnej "przepis rodzinny". Jeśli forma opisu jest oryginalna, prawa autorskie należą do tego, kto **ją zapisał/utrwalił** (czyli w praktyce do użytkownika Kuking, który go spisał, a nie do babci ustnie przekazującej recepturę — utwór musi być ustalony, tj. utrwalony w jakiejkolwiek formie). To dobra wiadomość dla Kuking: użytkownicy spisujący rodzinne przepisy własnymi słowami **są** twórcami tej formy wyrażenia i mogą swobodnie ją publikować.

### 3.3 Licencja użytkownika dla serwisu

Regulamin musi jasno określić, na jakiej podstawie Kuking może:
1. przechowywać i wyświetlać treść (to niezbędne minimum — **niewyłączna licencja** na hosting, publiczne udostępnianie w ramach funkcji serwisu, tworzenie miniatur/kopii technicznych potrzebnych do działania usługi),
2. **nie potrzeba** licencji "na wszelkich polach eksploatacji, bez ograniczeń terytorialnych i czasowych, z prawem do sublicencji, w tym do celów komercyjnych osób trzecich" — to nadmiarowe i budzi nieufność (a przy grupie 50+ zaufanie jest kluczowe).

**Rekomendacja minimalnej licencji:**
> "Publikując treść, udzielasz Kuking niewyłącznej, nieodpłatnej licencji na jej przechowywanie, przetwarzanie techniczne (np. zmniejszanie zdjęć, generowanie miniatur) oraz publiczne udostępnianie w ramach funkcji serwisu i tak długo, jak długo treść jest opublikowana lub jak wymaga tego prawo (np. kopie zapasowe). Licencja wygasa z chwilą usunięcia treści, z zastrzeżeniem kopii technicznych/zapasowych usuwanych zgodnie z Polityką Prywatności. Zachowujesz pełnię praw autorskich do swoich treści."

Ten fragment trafi do `resources/legal/regulamin.md` — ale **finalne brzmienie licencji musi zatwierdzić prawnik**, to jeden z najczęściej kwestionowanych zapisów regulaminów UGC.

### 3.4 Wizerunek (Art. 81 ustawy o prawie autorskim i prawach pokrewnych)

- Rozpowszechnianie wizerunku osoby wymaga jej zgody, **chyba że**: (a) osoba otrzymała umówioną zapłatę za pozowanie, (b) wizerunek osoby powszechnie znanej wykonany w związku z pełnieniem funkcji publicznych, (c) **wizerunek osoby stanowi jedynie szczegół całości** (np. zdjęcie imprezy, pikniku, gdzie inne osoby są w tle) — to najczęstszy praktyczny wyjątek dotyczący zdjęć kulinarnych/rodzinnych ze spotkań.
- **Ryzyko dla Kuking:** zdjęcia z rodzinnych obiadów/imprez, gdzie w tle są rozpoznawalne osoby trzecie, które nie wyraziły zgody. W praktyce ryzyko sporu jest niskie (rodzina zwykle nie pozywa), ale **warto to zaadresować w Regulaminie** jako obowiązek użytkownika: publikujesz zdjęcie = odpowiadasz za to, że masz prawo je opublikować (w tym zgody osób rozpoznawalnych, o ile nie są "jedynie szczegółem całości").
- Praktyczna rekomendacja produktowa: **umożliwić łatwe zgłoszenie i szybkie usunięcie** zdjęcia na żądanie osoby, która rozpoznaje siebie i nie wyraziła zgody — to tańsze i szybsze niż spór, i dobrze wygląda operacyjnie wobec DSA (Art. 16 notice and action pokrywa też ten scenariusz, jeśli zgłaszający wskaże naruszenie dóbr osobistych/wizerunku jako podstawę).

### 3.5 Osoby zmarłe

Prawo do wizerunku i cześć zmarłego chronione są pośrednio przez **dobra osobiste** (Art. 23–24 Kodeksu cywilnego) — po śmierci ochrony wizerunku (w rozumieniu Art. 81 pr. aut.) mogą dochodzić bliscy przez 20 lat od śmierci (zgoda następców, jeśli za życia zmarły nie wyraził woli inaczej — Art. 83 pr. aut. odpowiednio). W praktyce dla Kuking: **wspomnieniowe posty o zmarłych bliskich (np. "przepis babci, która odeszła")** są emocjonalnie ważnym, wartościowym contentem dla grupy 50+ i **nie powinny być domyślnie ograniczane** — ale polityka moderacji powinna mieć jasną ścieżkę na wypadek zgłoszenia przez rodzinę, że coś narusza pamięć zmarłego (rzadkie, ale realne w kulturze memoriam).

---

## 4. Małoletni

- **Minimalny wiek konta:** rekomendacja **16 lat** — to zgodne z domyślnym progiem RODO Art. 8 w Polsce (Polska nie obniżyła wieku zgody na przetwarzanie danych dziecka w kontekście usług społeczeństwa informacyjnego do 13 lat, mimo że projekt to przewidywał — ostatecznie utrzymano próg 16 lat wynikający wprost z RODO). Ustawienie wieku minimalnego na 16 lat **eliminuje potrzebę uzyskiwania zgody rodzica** (Art. 8 RODO dotyczy dzieci **poniżej** progu krajowego), co jest ogromnym uproszczeniem operacyjnym dla dwuosobowego zespołu.
- **Jak wyegzekwować bez weryfikacji tożsamości** (realistyczne minimum, zgodne z zasadą proporcjonalności):
  1. Checkbox/oświadczenie przy rejestracji: "Mam ukończone 16 lat" (self-declaration) — to **nie jest** weryfikacja wieku, ale jest standardem rynkowym dla serwisów tej skali i **wymagane minimum prawne** (bez tego nie ma nawet formalnej podstawy do twierdzenia, że wiek był sprawdzany).
  2. Data urodzenia zamiast samego checkboxa **nie jest rekomendowana** dla Kuking — `PRODUCT.md`/`schema_mvp.sql` już świadomie **nie zbiera** pełnej daty urodzenia (data minimization) — to dobra decyzja prywatnościowa, ale słabsza pod kątem możliwości późniejszej weryfikacji. Kompromis: zbierać tylko potwierdzenie "16+" bez daty, zgodnie z already przyjętą zasadą minimalizacji.
  3. **Nie ma realnego obowiązku weryfikacji dokumentem tożsamości** dla platformy tej skali i charakteru (to praktyka regulowana wprost tylko dla usług wysokiego ryzyka, np. pornografia, hazard) — próba wdrożenia weryfikacji ID dla portalu kulinarnego byłaby nieproporcjonalna i sama w sobie rodziłaby ryzyko RODO (przetwarzanie dodatkowych danych identyfikacyjnych).
  4. Reagować **reaktywnie**: jeśli moderacja poweźmie wiarygodne podejrzenie, że konto należy do osoby poniżej 16 lat (np. z treści posta wynika, że to dziecko) → zawiesić konto do wyjaśnienia, zgodnie z katalogiem w `MODERATION_PLAYBOOK.md`.
- Nie kierować reklam behawioralnych do kont oznaczonych/podejrzewanych jako małoletnie (nieaktualne przy braku reklam w MVP, ale zapisać to jako zasadę na przyszłość).
- Nie ma DM w MVP — to samo w sobie znacząco redukuje ryzyko groomingu, zgodnie z już przyjętą decyzją produktową w `FEATURES.md`.

---

## 5. Cookies / ePrivacy

### 5.1 Podstawa prawna w Polsce

Do 9 listopada 2024 r. obowiązywał Art. 173 ustawy Prawo telekomunikacyjne. Od 10 listopada 2024 r. materię tę reguluje **ustawa Prawo komunikacji elektronicznej (PKE)** — [do weryfikacji dokładny numer artykułu odpowiadający dawnemu Art. 173 — źródła wskazują na artykuły w okolicach Art. 398–400 PKE, ale numeracja bywa myląco cytowana w różnych serwisach branżowych; **potwierdzić dokładny numer artykułu u prawnika lub bezpośrednio w tekście ustawy przed publikacją polityki prywatności**]. Zasada merytoryczna pozostaje ta sama co pod starym prawem i pod Dyrektywą ePrivacy: **przechowywanie informacji lub uzyskiwanie dostępu do informacji już przechowywanej w telekomunikacyjnym urządzeniu końcowym użytkownika (czyli cookies, localStorage, fingerprinting itp.) wymaga zgody użytkownika**, chyba że jest to **ściśle niezbędne** do świadczenia usługi wyraźnie zażądanej przez użytkownika (np. cookie sesji logowania, cookie CSRF).

### 5.2 Co wymaga zgody, a co nie

| Rodzaj | Przykład w Kuking | Wymaga zgody? |
|---|---|---|
| Cookie sesji (auth, CSRF) | Laravel session cookie | **Nie** — ściśle niezbędne do działania usługi, na którą użytkownik świadomie się zalogował |
| Cookie preferencji technicznych bez śledzenia (np. zapamiętany rozmiar czcionki, jeśli w cookie a nie w koncie) | `text_scale` — ale to już jest w kolumnie `users.text_scale`, czyli **serwerowo, nie w cookie** — dobre rozwiązanie, unika problemu | **Nie**, jeśli trzymane po stronie konta, nie w cookie/localStorage |
| Analityka produktowa (PostHog) | zdarzenia UI, identyfikator sesji/użytkownika | **Tak, w standardowym podejściu** — Polska (UODO) nie wydała własnych wytycznych zwalniających analitykę z obowiązku zgody (w przeciwieństwie do np. Francji/CNIL, Włoch/Garante, Hiszpanii/AEPD, które mają wąskie wyjątki dla zagregowanej, ściśle statystycznej analityki pierwszej strony) |
| Analityka bez identyfikatorów i bez zapisu na urządzeniu (np. agregacja server-side, brak cookie/localStorage, brak fingerprinting) | Konfiguracja PostHog w trybie **bez person profiles**, bez cookie, z wyłączonym autocapture identyfikującym urządzenie | **Można argumentować, że nie** — bo obowiązek dotyczy "przechowywania/dostępu do informacji na urządzeniu końcowym", a nie samego faktu zbierania zdarzeń serwerowo. To jednak wymaga **rygorystycznej konfiguracji technicznej** i nadal może podlegać RODO (jeśli dane są w jakikolwiek sposób powiązane z osobą, np. przez adres IP niehashowany) |
| **Analityka faktycznie wdrożona w Kuking: Cloudflare Web Analytics** (D-092) | skrypt `static.cloudflareinsights.com/beacon.min.js` — sprawdzone przez pobranie i odczytanie pliku (sha256 `08c4fd72…`, wersja JS `2026.9.1`): **zero** wystąpień `cookie` (także w wariancie z wielkiej litery), **zero** `localStorage`, **zero** `sessionStorage`, **zero** `indexedDB`, **zero** `setItem`/`getItem` | **Nie** — nic nie jest zapisywane na urządzeniu ani z niego odczytywane w rozumieniu ePrivacy/PKE. Patrz 5.5 |
| Sentry (błędy) | zwykle nie zapisuje cookie na urządzeniu użytkownika, dane wysyłane są z serwera/przeglądarki do Sentry przy wystąpieniu błędu | Zasadniczo nie wymaga zgody cookies (nie jest to "storage" na urządzeniu w typowej konfiguracji), ale wymaga podstawy RODO (uzasadniony interes) i minimalizacji PII w payloadzie |

### 5.3 Jak zrobić PostHog bez banera zgody — realistyczna ocena

Jest to **możliwe technicznie, ale ryzykowne prawnie bez pewności**, bo Polska nie ma oficjalnych wytycznych UODO analogicznych do francuskiego CNIL. Warunki, które trzeba by spełnić łącznie, żeby argumentować brak potrzeby zgody:
1. Wyłączyć trwałe identyfikatory po stronie klienta (brak cookies PostHog, brak localStorage z ID) — używać wyłącznie **server-side capture** z agregacją, bez łączenia zdarzeń w sesje per-użytkownik na urządzeniu.
2. Anonimizować/skracać adresy IP przed zapisem.
3. Ograniczyć zbierane zdarzenia do czysto statystycznych (np. "odwiedzono stronę X"), bez profilowania zachowania per-osoba.
4. Udokumentować to jako **uzasadniony interes** w rejestrze czynności i ocenić test równoważenia interesów (LIA — legitimate interest assessment).

**Rekomendacja praktyczna dla dwuosobowego zespołu:** Prostszy i bezpieczniejszy wariant to **lekki, uczciwy baner zgody** (patrz 5.4) obejmujący tylko analitykę — koszt wdrożenia jest niski, a ryzyko sporu/kary praktycznie zerowe. Próba obejścia banera przez "cookieless analytics" ma sens dopiero, gdy zespół ma czas i budżet na konsultację prawną potwierdzającą konkretną konfigurację — na start MVP **nie warto ryzykować** przez oszczędność jednego komponentu UI.

### 5.4 Minimalny, uczciwy baner (jeśli wdrażany)

- Domyślnie **wszystko wyłączone** (opt-in, nie opt-out) — zgodnie z zasadą "consent must be freely given, specific, informed".
- Dwa równorzędne przyciski: **"Akceptuję"** i **"Odrzucam"** — nie chować odmowy w linku "ustawienia" mniejszą czcionką (to klasyczny dark pattern, i tak zakazany zasadą uczciwości niezależnie od formalnego zwolnienia z Art. 25 DSA — patrz 1.2).
- Brak "ściany zgody" (cookie wall) blokującej dostęp do treści publicznych — RODO/ePrivacy w orzecznictwie UODO i EDPB kwestionuje wymuszanie zgody pod groźbą braku dostępu do podstawowej treści.
- Osobna, łatwo dostępna możliwość **zmiany decyzji później** (link w stopce "Ustawienia cookies").
- Duża czcionka, prosty język — spójnie z resztą UX dla grupy 50+.

### 5.5 Co ostatecznie wdrożono — Cloudflare Web Analytics, bez banera (D-092, 10.09.2026)

Sekcje 5.2–5.4 powstały, gdy kandydatem był **PostHog**, a pytanie brzmiało „czy da się go skonfigurować tak, żeby nie wymagał zgody". Odpowiedź w 5.3 była: da się, ale to ryzykowne. **Ta ocena zostaje w mocy dla PostHoga i nie została podważona** — wdrożono jednak co innego, więc wypada napisać wprost, dlaczego 5.3 tutaj nie zabrania.

Różnica jest jedna i jest istotna. Rekomendacja z 5.3 broniła przed **cichym rozjechaniem się dokumentu z rzeczywistością**: „PostHog bez identyfikatorów" to KONFIGURACJA, a konfigurację da się cofnąć jednym przełącznikiem w cudzym panelu — i wtedy polityka prywatności przestaje być prawdziwa, a nikt się o tym nie dowiaduje. W beaconie Cloudflare nie ma czego przestawiać, i to jest **zmierzone, nie wzięte ze strony dostawcy**: w pobranym `beacon.min.js` nie występuje ani jedno odwołanie do `document.cookie`, `localStorage`, `sessionStorage`, `indexedDB`, `setItem` ani `getItem` — słowo „cookie" nie pada w tym pliku w żadnej postaci. Kodu, który nie ma czym zapisać na urządzeniu, nie da się do tego namówić przełącznikiem w cudzym panelu. **Brak ciasteczek jest tu właściwością narzędzia, nie jego ustawieniem** — dokładnie tak samo jak przy odrzuconym Plausible, i z mocniejszym pomiarem (Plausible przynajmniej ODCZYTYWAŁ `localStorage`, żeby sprawdzić flagę `plausible_ignore`; ten beacon nie zagląda tam wcale).

Warunek 1 z listy w 5.3 (brak trwałych identyfikatorów po stronie klienta) jest więc spełniony z definicji: identyfikator odsłony powstaje z `crypto.randomUUID()` w pamięci karty i ginie razem z nią, bo nie ma go gdzie odłożyć. Warunek 3 (tylko dane statystyczne, bez profilu osoby) — z braku technicznej możliwości zrobienia inaczej; skrypt dodatkowo **czyści adresy przed wysłaniem**: usuwa query string, fragment oraz login i hasło z URL-a (funkcja `cleanLocation`), więc identyfikator z linku typu `?utm_id=…` do Cloudflare nie dojedzie. Warunek 2 (brak zapisanego IP) leży po stronie dostawcy i my go nie zmierzymy — ale ma tu inny ciężar niż przy jakimkolwiek innym kandydacie: **Cloudflare widzi IP każdego żądania do kuking.pl od pierwszego dnia**, bo jest naszym CDN-em i WAF-em przed Railwayem (`docs/infra/INFRA_DECISION.md`; `bootstrap/app.php` ma zaufane proxy właśnie z tego powodu). Włączenie statystyk nie stworzyło nowego przepływu danych do nowego podmiotu — i dlatego w polityce prywatności **nie doszedł ani nowy dostawca, ani trzeci akapit o przekazywaniu poza EOG**: Cloudflare, Inc. stoi tam od Turnstile'a (D-050), na podstawie EU-US Data Privacy Framework i standardowych klauzul umownych. Dopisany został **nowy cel** przetwarzania przy tym samym dostawcy, zgodnie z obietnicą, którą polityka sama sobie składa.

Zachowanie skryptu zostało sprawdzone przez pobranie i odczytanie pliku, a nie przyjęte ze strony marketingowej dostawcy — bo to samo zdanie stoi w dokumencie publikowanym pod `/prywatnosc`. Wynik, wersja pliku i porównanie z odrzuconymi kandydatami (Plausible, Umami): `docs/DECISIONS.md`, D-092.

Pozycja z listy zadań (sekcja 9, P1) „baner cookies LUB potwierdzona konfiguracja bez-zgodowa" jest tym samym **zamknięta wariantem drugim**. Warunek jego utrzymania jest jeden i trzeba go pilnować: **gdyby doszło narzędzie, które cokolwiek na urządzeniu zapisuje albo odczytuje, wraca obowiązek zgody i wraca temat banera z 5.4** — polityka prywatności obiecuje wprost, że zapytamy, zanim to się stanie.

Czego to **nie** przesądza — trzy rzeczy, wypisane, żeby nie wyglądały na przesądzone:

1. **Podstawy RODO.** Zbieranie danych o odsłonach nadal opiera się na uzasadnionym interesie (Art. 6(1)(f)) i podlega prawu sprzeciwu z Art. 21 — to jest osobna warstwa od ePrivacy i sekcja 5 jej nie zastępuje.
2. **Ciasteczek stawianych przez samo proxy Cloudflare** (np. bot management). To warstwa sieciowa, która stoi przed serwisem niezależnie od tej decyzji i **nie została tu zmierzona** — beacon nie dokłada do niej nic, ale zdanie „na urządzeniu nie ma żadnego ciasteczka Cloudflare" nie jest zdaniem, które ten pomiar uprawnia napisać. Do sprawdzenia osobno, przy przeglądzie konfiguracji Cloudflare.
3. **Automatycznego wstrzykiwania beacona.** Cloudflare umie wstrzyknąć ten sam skrypt w locie, na ruchu przechodzącym przez proxy. Ta opcja **musi zostać wyłączona**: my stawiamy znacznik w layoucie, więc wstrzyknięcie dałoby dwa beacony na stronę (podwójne liczenie) i wersję, której nie widzą ani recenzja, ani testy CSP. Czynność właściciela, zapisana w D-092 i w `.env.example`.

---

## 6. Dostępność (European Accessibility Act / ustawa o dostępności produktów i usług)

- EAA (Dyrektywa 2019/882) wdrożona w Polsce ustawą z 26 kwietnia 2024 r. o zapewnianiu spełniania wymagań dostępności niektórych produktów i usług przez podmioty gospodarcze — obowiązki dla nowych produktów/usług wprowadzanych na rynek **po 28 czerwca 2025 r.**
- **Zakres przedmiotowy** ustawy/dyrektywy jest **zamknięty i dość wąski**: obejmuje m.in. usługi bankowe, handel elektroniczny (e-commerce), e-booki, usługi transportu pasażerskiego, usługi łączności elektronicznej, dostęp do audiowizualnych usług medialnych. **Ogólny serwis społecznościowy/UGC bez komponentu e-commerce nie jest wprost wymieniony** w katalogu usług objętych ustawą [do weryfikacji z prawnikiem — katalog bywa interpretowany szeroko, a granica "handlu elektronicznego" może się rozmyć, jeśli Kuking w przyszłości doda jakikolwiek płatny element, np. płatne konto premium, sklep z akcesoriami kuchennymi].
- **Niezależnie od powyższego, ustawa wprost wyłącza mikroprzedsiębiorstwa** (poniżej 10 zatrudnionych i obrót/suma bilansowa ≤2 mln EUR) świadczące usługi objęte ustawą — więc nawet gdyby ogólny serwis społecznościowy miał być objęty zakresem, **Kuking jako mikroprzedsiębiorstwo jest z tego zwolniony formalnie**.
- **Rekomendacja produktowa niezależna od obowiązku prawnego:** grupa docelowa Kuking to 50+ — dostępność (czytelna typografia, kontrast, skalowalna czcionka — `text_scale` już jest w schemacie, alt-text do zdjęć — kolumna `alt_text` w `media` już istnieje) jest **uzasadnieniem biznesowym silniejszym niż jakikolwiek przepis**. Potraktować WCAG 2.1 AA jako cel produktowy, nie jako formalny obowiązek compliance na starcie.

---

## 7. Checklista przed publicznym startem

Priorytety: **P0 = blokujące start**, **P1 = zrobić w pierwszych tygodniach**, **P2 = ważne, ale nie blokujące**.

**Jak czytać kolumnę „Dowód" — to jest reguła tej listy, nie ozdoba.**
Każdy wiersz ma dokładnie jedną z trzech rzeczy:

- **nazwę testu albo `plik:linia`** — twierdzenie jest pilnowane przez kod
  i odhaczasz je, patrząc na zielony przebieg, nie na własną pamięć;
- **`DO SPRAWDZENIA PRZEZ CZŁOWIEKA:`** — czego kod nie potrafi sprawdzić
  i co trzeba zobaczyć samemu, z podaniem GDZIE;
- **`BRAK:`** — rzecz nie istnieje. Wiersz zostaje, bo brak jest informacją.

Punkt bez dowodu jest gorszy niż brak punktu, bo daje złudzenie sprawdzenia.
Pilnuje tego `DokumentyPrawneNieKlamiaTest::test_kazdy_wiersz_listy_gotowosci_ma_dowod`.

| Priorytet | Zadanie | Dowód | Wymaga prawnika? |
|---|---|---|---|
| P0 | Regulamin opublikowany na `/regulamin` (patrz `resources/legal/regulamin.md`) z licencją treści, zasadami moderacji, punktami kontaktowymi (Art. 11, 12, 14 DSA) | `DokumentyPrawneNieKlamiaTest` — dokument jest żywą stroną, bez placeholderów i bez narzędzi, których nie używamy | **Tak — finalna wersja** |
| P0 | Polityka prywatności opublikowana na `/prywatnosc` (patrz `resources/legal/polityka-prywatnosci.md`) z pełną tabelą celów/podstaw/retencji | `DokumentyPrawneNieKlamiaTest`, `PolitykaPrywatnosciWymieniaKazdaUslugeTest` | **Tak — finalna wersja** |
| P0 | Formularz „Zgłoś" spełniający Art. 16 DSA (elektroniczny, jasny, wskazanie lokalizacji i powodu) | `routes/web.php:978` (`/zglos/{type}/{id}`), `ZgloszenieNielegalnejTresciTest` | Nie, ale warto konsultacyjnie |
| P0 | Uzasadnienie decyzji moderacyjnej (Art. 17 DSA) — zamknięta lista podstaw, każda wskazuje punkt zasad | `app/Domain/Moderation/PodstawaDecyzji.php`, `UzasadnienieDecyzjiTest::test_kazdy_punkt_z_listy_istnieje_w_zasadach` | Nie |
| P0 | Wiek minimalny 16 lat wymagany oświadczeniem przy rejestracji | `app/Http/Controllers/Auth/RegisterController.php:132` — `'age_confirmed' => ['accepted']` | Nie |
| P0 | Mechanizm eksportu i usunięcia konta działający end-to-end | `DataExportTest`, `AccountDeletionPurgeTest`, `AccountDeletionCancellationTest` | Nie |
| P0 | Kanał błędów nie wynosi danych osobowych | `BladTrafiaNaWebhookBezDanychOsobowychTest` — **pilnowane testem, nie trzeba sprawdzać ręcznie** | Nie |
| P0 | Procedura zgłaszania do organów przy CSAM/zagrożeniu życia (Art. 18 DSA) | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` ścieżka operacyjna stoi w `MODERATION_PLAYBOOK.md` §7.1, a od 20.09.2026 jest tam także §7.1a — **projekt ścieżki prawnej z trzema pytaniami do zadania prawnikowi**, jawnie nierozstrzygnięty. Zostało: potwierdzić organ i podstawę **przed startem, nie w trakcie incydentu** | **Tak — potwierdzić ścieżkę zgłoszeniową** |
| P0 | DPA/umowy powierzenia z **realnymi** odbiorcami danych: Railway, Cloudflare (R2, Turnstile, Web Analytics), OpenAI, dostawca poczty, a przy logowaniu zewnętrznym Google i Meta | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` lista do odhaczenia — co i gdzie sprawdzić u każdego z ośmiu odbiorców — stoi w `REJESTR_UMOW_POWIERZENIA.md`; **czy umowy są podpisane, widać wyłącznie w panelach dostawców i w szafie z umowami**. Przy Meta umowa powierzenia jest **niewłaściwym instrumentem** (osobny administrator) — ten wiersz zamyka opis ról, nie podpis | **Tak — przegląd umów** |
| P0 | Rejestr czynności przetwarzania (Art. 30 RODO) sporządzony | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` rejestr istnieje — `REJESTR_CZYNNOSCI_PRZETWARZANIA.md`, siedemnaście czynności wyprowadzonych z kodu. Zostały pola, których z kodu wyprowadzić się nie da i które są w nim oznaczone jako `DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:` — IOD, regiony usług u Railway i Cloudflare, podstawy przekazań poza EOG z datami, okres życia kopii zapasowej | **Tak — przegląd** |
| P0 | Cloudflare Web Analytics: statystyka pozostaje bezciasteczkowa | `AnalitykaBezCiasteczekTest`, `WdrozenieAnalitykiOdwiedzinTest` — od tego zależy wiersz P1 o banerze niżej | Nie |
| P0 | OpenAI: treść wpisu i pomniejszone zdjęcie wychodzą poza EOG — granica opisana w polityce i egzekwowana w kodzie | `app/Moderacja/KlientOpenAI.php`, polityka §„Przekazywanie poza EOG"; `PolitykaPrywatnosciWymieniaKazdaUslugeTest` | **Tak — podstawa przekazania** |
| P0 | Logowanie kontem Google i Facebookiem: zakres danych zgodny z polityką | `PolitykaPrywatnosciWymieniaKazdaUslugeTest`; `/health` na produkcji potwierdza, że obie drogi są włączone | **Tak — rola Meta jako osobnego administratora** |
| P0 | `SESSION_SECURE_COOKIE` ustawione na produkcji | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` w repozytorium stoi `.env.example:47 SESSION_SECURE_COOKIE=false` (wartość lokalna). Wartości produkcyjnej nie widać z kodu — odczytać w panelu Railway | Nie |
| P0 | `zadania_nieudane` w `/health` wyjaśnione przed wpuszczeniem ludzi | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` `/health` mówi `degraded` wyłącznie na kolejce; tabeli `failed_jobs` nie da się odczytać bez konsoli produkcyjnej (#713 A1). Nie kasować bez zrozumienia przyczyny | Nie |
| P1 | Baner cookies — niepotrzebny, dopóki statystyka jest bezciasteczkowa (D-092); wrócić do tematu przy zmianie dostawcy albo dołożeniu identyfikatorów | `AnalitykaBezCiasteczekTest` — gdy padnie, ten wiersz staje się P0 | **Tak, przy zmianie dostawcy** |
| P1 | Szablon zgłoszenia naruszenia do UODO + szablon powiadomienia użytkowników przygotowany z wyprzedzeniem | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` oba szablony i ścieżka decyzyjna stoją w `SZABLONY_NARUSZENIE_DANYCH.md`. Zostały dwie rzeczy, których dokument nie może rozstrzygnąć za właściciela: **kto stwierdza naruszenie i kto go zastępuje**, oraz **droga złożenia zgłoszenia do UODO sprawdzona ZANIM będzie potrzebna** | Zalecane |
| P1 | Ustalenie i udokumentowanie polityki retencji backupów (max czas życia kopii z danymi po usunięciu konta) | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` retencja danych w aplikacji jest egzekwowana dziesięcioma komendami (§7.3), ale kopie zapasowe rządzą się osobnym cyklem — #193, #594 | Nie |
| P1 | Weryfikacja aktualnego statusu ustawy krajowej wdrażającej DSA i roli UKE jako koordynatora | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` stan prawny zmienia się poza repozytorium | **Tak** |
| P1 | Uproszczony wewnętrzny system odwołań od decyzji moderacyjnych (dobrowolnie, mimo zwolnienia z Art. 20 DSA) | `app/Http/Controllers/AppealController.php`, `app/Http/Controllers/ReporterAppealController.php` — odwołania działają dla autora treści i dla zgłaszającego | Nie |
| P1 | Rozstrzygnąć obrazek liczący otwarcia listów u dostawcy poczty (EmailLabs) i wyłączyć go po jego stronie (patrz 7.1) | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` wyłącznik jest w panelu dostawcy, nie w kodzie (#204, #713 A3) | **Tak** |
| P2 | Ocena, czy skanowanie zdjęć pod kątem CSAM (hash-matching) rodzi dodatkowe obowiązki RODO/DPIA | `BRAK:` takiego skanowania nie ma; ocena dopiero przed ewentualnym wdrożeniem | **Tak, przed wdrożeniem** |
| P2 | Test WCAG 2.1 AA na kluczowych ekranach (rejestracja, publikacja, profil) | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` automat axe chodzi w CI, ale nie zastępuje odsłuchu czytnika ekranu ani fizycznego urządzenia (#713 C1, C2) | Nie |
| P2 | Polityka wobec zdjęć z rozpoznawalnymi osobami trzecimi w tle (wizerunek) w regulaminie | `BRAK:` regulamin tego nie rozstrzyga | Zalecane skonsultować |
| P2 | Rejestr podprocesorów (transparency) utrzymywany na bieżąco | `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` lista odbiorców w §7.2 jest punktem wyjścia; rejestr publiczny to decyzja właściciela | Nie |

### 7.2 Kto naprawdę dostaje dane — lista zweryfikowana wobec kodu

Ta lista powstała z odczytu kodu 20 września 2026 i zastępuje domysły.
Każda pozycja to **realny kanał wyjścia danych poza ten serwer**.

| Odbiorca | Co do niego trafia | Gdzie to widać w kodzie |
|---|---|---|
| Railway | cała aplikacja i baza | hosting — poza repozytorium |
| Cloudflare R2 | zdjęcia i ich warianty | `config/filesystems.php` |
| Cloudflare Turnstile | adres IP i cechy przeglądarki przy **siedmiu** formularzach | `config/kuking.php` → `turnstile.miejsca`; pilnuje `RozjazdyAudytuZgodnosciTest::test_polityka_wymienia_kazdy_formularz_za_turnstile` |
| Cloudflare Web Analytics | adres strony, odnośnik, rodzaj przeglądarki, czas wczytania | `app/Support/AnalitykaCloudflare.php`; bezciasteczkowe — `AnalitykaBezCiasteczekTest` |
| OpenAI | treść wpisu i pomniejszone zdjęcie, bez danych wskazujących osobę | `app/Moderacja/KlientOpenAI.php` |
| Dostawca poczty (EmailLabs) | adres e-mail odbiorcy i treść listu | `config/mail.php`, `app/Domain/Security/DziennyBudzetListow.php` |
| Google | przy logowaniu kontem Google: potwierdzenie tożsamości, e-mail, imię | `app/Http/Controllers/Auth/GoogleLoginController.php` |
| Meta | przy logowaniu Facebookiem — Meta jest tu **osobnym administratorem** | `app/Http/Controllers/Auth/FacebookLoginController.php`, `app/Http/Controllers/Auth/FacebookDeauthorizeController.php` |

**Sentry i PostHog nie są na tej liście, bo ich w tym projekcie nie ma i nigdy
nie było.** Do 19 września lista gotowości wymagała wobec nich umów i konfiguracji
— czyli blokowała start warunkami niemożliwymi do spełnienia. Pilnuje tego teraz
`DokumentyPrawneNieKlamiaTest::test_dokument_wewnetrzny_nie_wymienia_narzedzi_ktorych_nie_uzywamy`.

### 7.3 Retencja — dziesięć komend, nie dwie

| co | okres | komenda |
|---|---|---|
| powiadomienia | 3 miesiące | `kuking:sprzataj-powiadomienia` |
| sygnały produktowe | 90 dni | `kuking:sprzataj-sygnaly` |
| dziennik audytu | 12 miesięcy | `kuking:sprzataj-audyt` |
| sprawy moderacyjne | 36 miesięcy | `kuking:sprzataj-sprawy-moderacyjne` |
| paczki z danymi | 7 dni | `kuking:sprzataj-eksporty` |
| zdjęcia nieprzypięte | — | `kuking:sprzataj-osierocone-zdjecia` |
| konta po karencji | 30 dni | `kuking:usun-wygasle-konta` |
| wiadomości „Napisz do nas" | 12 miesięcy od załatwienia | `kuking:sprzataj-wiadomosci` |
| wygasłe zaproszenia do konta | termin w wierszu | `kuking:sprzataj-zaproszenia` |
| wygasłe żądania zmiany adresu e-mail | termin w wierszu | `kuking:sprzataj-zmiany-adresu` |

Istnienie każdej z nich pilnuje
`DokumentyPrawneNieKlamiaTest::test_komendy_wymienione_w_procedurach_istnieja`.

### 7.1 Luki, które zniknęły z dokumentów widocznych dla ludzi (11 września 2026)

Regulamin i polityka prywatności nosiły pod ostatnim paragrafem notatkę autora
do samego siebie — *„Czego w tym dokumencie jeszcze nie ma, a będzie: …"* —
a w sekcji „Źródła" odsyłacz do tego pliku i nawias `[numer artykułu do
potwierdzenia]`. Właściciel kazał je usunąć: dokument, który sam o sobie mówi
„tego tu jeszcze nie ma", czyta się jak brudnopis, a nie jak wiążąca umowa.

**Usunięcie noty nie wypełnia obowiązku, tylko przestaje o nim przypominać.**
Dlatego to, czego noty dotyczyły, jest spisane tutaj — razem z tym, co przy
okazji okazało się już nieaktualne.

| Czego dotyczyła nota | Podstawa prawna | Stan na dziś | Gdzie ta sprawa żyje teraz |
|---|---|---|---|
| Tożsamość i adres podmiotu prowadzącego serwis (regulamin) | DSA Art. 11–12; art. 5 ustawy o świadczeniu usług drogą elektroniczną; RODO Art. 13 ust. 1 lit. a | **To nie jest już luka.** Dane spółki, KRS, NIP, REGON i adres stoją w §1 regulaminu i w §1 polityki od 8 września 2026 — nota była nieaktualna | `config/kuking.php` (`kuking.podmiot`), pilnuje tego `DokumentyPrawneNieKlamiaTest::test_tozsamosc_administratora_zgadza_sie_z_konfiguracja` |
| Dostawca poczty (polityka) | RODO Art. 13 ust. 1 lit. e — kategorie odbiorców | **To nie jest już luka.** EmailLabs (Vercom S.A.) stoi w tabeli dostawców w §3 polityki razem z miejscem przechowywania danych — nota była nieaktualna | Tabela w §3 polityki, pilnuje jej `PolitykaPrywatnosciWymieniaKazdaUslugeTest` |
| Liczba dni, przez które usunięte dane żyją w kopiach zapasowych | RODO Art. 13 ust. 2 lit. a — okres przechowywania | **Luka otwarta.** Okres nie jest ustalony z dostawcą hostingu | Opisane wyżej: sekcja 2.8 i wiersz P1 w checkliście („Ustalenie i udokumentowanie polityki retencji backupów"). Sama polityka mówi o tym dalej wprost w §7 pkt 4 — to zdanie o stanie usługi, nie notatka, i zostaje |
| Dokładny numer artykułu PKE odpowiadającego dawnemu Art. 173 Prawa telekomunikacyjnego | Ustawa Prawo komunikacji elektronicznej (2024) | **Luka otwarta.** Polityka wymienia teraz samą ustawę i przedmiot regulacji, bez numeru artykułu — numeru nie zgadujemy | Opisane wyżej: sekcja 5.1 |
| Czy obrazek liczący otwarcia listów, dokładany przez dostawcę poczty, wymaga od nas czegoś więcej niż rzetelnej informacji | Art. 5 ust. 3 dyrektywy 2002/58/WE i odpowiadające przepisy PKE (dostęp do informacji w urządzeniu końcowym); RODO Art. 6 | **Luka otwarta.** Do 11 września 2026 pytanie stało wyłącznie w polityce, w zdaniu „to zostaje do potwierdzenia" — nigdzie indziej nie było zapisane | Nowy wiersz P1 w checkliście wyżej. Sam fakt — że dostawca to robi i że wyłączamy to po jego stronie — zostaje w §3 polityki |

Odsyłacz *„Zobacz pełną listę źródeł w `COMPLIANCE.md`"* zniknął z obu
dokumentów bez zamiennika: ten plik jest w repozytorium, a nie na stronie, więc
czytelnik regulaminu nie ma jak go otworzyć i nie wie, czym jest. Same sekcje
„Źródła" zostają — podstawa prawna podana w dokumencie jest dla czytelnika
wartością, a nie notatką redakcyjną.

Powrotu notatek roboczych do dokumentów widocznych dla ludzi pilnuje
`DokumentyPrawneNieKlamiaTest::test_brak_notatek_roboczych_o_pisaniu_dokumentu`.

### Rzeczy, które wymagają prawnika przed publikacją — podsumowanie
1. Finalna treść regulaminu i polityki prywatności (licencja treści to najczęściej kwestionowany zapis).
2. Przegląd umów powierzenia (DPA) z każdym podprocesorem i potwierdzenie podstawy transferu danych poza EOG (DPF, SCC).
3. Potwierdzenie aktualnego stanu ustawy krajowej wdrażającej DSA i praktyki UKE jako koordynatora.
4. Decyzja o cookies/PostHog — czy wdrażać baner czy konfigurację bez-zgodową.
5. Ścieżka zgłaszania CSAM/zagrożenia życia do organów (Art. 18 DSA) — czy potrzebna umowa/kontakt z konkretną jednostką.
6. Ocena obowiązków wynikających z Art. 28 DSA (ochrona małoletnich) w świetle wytycznych Komisji z 2025 r. — obszar świeży i zmienny.
7. Ewentualne DPIA dla modułu moderacji i skanowania zdjęć.

---

## Źródła

- [Regulation (EU) 2022/2065 — Digital Services Act, EUR-Lex](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32022R2065)
- [Article 16 — Notice and action mechanisms, CMS DigitalLaws](https://www.cms-digitallaws.com/en/dsa/article-16/)
- [Article 17 — Statement of reasons, CMS DigitalLaws](https://www.cms-digitallaws.com/en/dsa/article-17/)
- [Article 19: Exclusion for micro and small enterprises — DSA Library](https://dsa-library.com/article/29/)
- [Article 24 — Transparency reporting obligations for online platforms, CMS DigitalLaws](https://www.cms-digitallaws.com/en/dsa/article-24/)
- [Article 28 — Online protection of minors, CMS DigitalLaws](https://www.cms-digitallaws.com/en/dsa/article-28/)
- [Beyond the Tech Giants: Why the DSA's Rules on the Protection of Minors May Now Affect All Online Platforms — Taylor Wessing](https://www.taylorwessing.com/en/insights-and-events/insights/2025/10/beyond-the-tech-giants)
- [Digital Services Act: Questions and Answers — European Commission](https://digital-strategy.ec.europa.eu/en/faqs/digital-services-act-questions-and-answers)
- [USTAWA z dnia 18 grudnia 2025 r. o zmianie ustawy o świadczeniu usług drogą elektroniczną — Sejm](https://orka.sejm.gov.pl/proc10.nsf/ustawy/1757_u.htm)
- [Nowa rola dla prezesa UKE — WNP.pl](https://www.wnp.pl/tech/mc-rzad-przyjal-projekt-wyznaczajacy-prezesa-uke-na-koordynatora-ds-uslug-cyfrowych,1068229.html)
- [Wdrożenie DSA w Polsce — Cyberpolicy NASK](https://cyberpolicy.nask.pl/wdrozenie-dsa-w-polsce/)
- [Artykuł 8 RODO — Zgoda dziecka na usługi społeczeństwa informacyjnego — GDPR.pl](https://gdpr.pl/baza-wiedzy/akty-prawne/interaktywny-tekst-gdpr/artykul-8-warunki-wyrazenia-zgody-przez-dziecko-w-przypadku-uslug-spoleczenstwa-informacyjnego)
- [Zgoda dziecka na przetwarzanie danych — RODOradar.pl](https://rodoradar.pl/zgoda-dziecka-przetwarzanie-danych/)
- [Prawo komunikacji elektronicznej a ochrona danych osobowych — Grant Thornton](https://grantthornton.pl/publikacja/prawo-komunikacji-elektronicznej-a-ochrona-danych-osobowych/)
- [Cookies a pomiar oglądalności – kiedy wymagana jest zgoda? — ODO24](https://odo24.pl/blog-post.pliki-cookies-a-pomiar-ogladalnosci-jak-uniknac-naruszenia-przepisow)
- [Analytics cookies: CNIL/CNPD exemptions, ICO still requires consent — Luxgap](https://luxgap.com/articles/cookies-analytics-exemption-cnil-cnpd-consentement-ico-2026)
- [Czy przepisy kulinarne chroni prawo autorskie — Prawo.pl](https://www.prawo.pl/biznes/czy-przepisy-kulinarne-chroni-prawo-autorskie,512468.html)
- [Przepis kulinarny nie jest utworem, ale jego prezentacja może podlegać ochronie — Prawo.pl](https://www.prawo.pl/biznes/przepisy-kulinarne-ochrona-prawna,520707.html)
- [Prawo autorskie od kuchni — DZP](https://www.dzp.pl/blog/ip/media/prawo-autorskie-od-kuchni-ochrona-prawna-przepisow-kulinarnych)
- [Czy przepis kulinarny może stanowić utwór chroniony przez polskie prawo — LGL kancelaria](https://lgl-iplaw.pl/2020/02/czy-przepis-kulinarny-moze-stanowic-utwor-chroniony-przez-polskie-prawo/)
- [Mazurek pod ochroną. Prawo autorskie w kuchni — Legalna Kultura](https://legalnakultura.pl/pl/prawo-w-kulturze/prawo-w-praktyce/news/2667,mazurek-pod-ochrona-prawo-autorskie-w-kuchni)
- [Opublikowano Polski Akt o Dostępności — PARP](https://www.parp.gov.pl/component/content/article/88869:opublikowano-polski-akt-o-dostepnosci)
- [Kogo dotyczy EAA? Wyjaśniamy nieoczywiste — Fundacja Widzialni](https://widzialni.org/kogo-dotyczy-eaa-wyjasniamy-nieoczywiste,new,mg,6,403)
- [Europejski akt o dostępności — Wikipedia PL](https://pl.wikipedia.org/wiki/Europejski_akt_o_dost%C4%99pno%C5%9Bci)
