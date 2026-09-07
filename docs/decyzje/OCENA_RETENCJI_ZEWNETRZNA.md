# Retencja: 36 miesięcy nie jest obowiązkiem wynikającym z przedawnienia

**Zostawiłbym 36 miesięcy dla ograniczonego materiału dowodowego sprawy, ale nie dla całego jej surowego „worka danych”; zwykły dziennik skróciłbym do 12 miesięcy, powiadomienia do 3 miesięcy, a bezterminowe wyjątki usunął.** To proponowana polityka po minimalizacji i udokumentowaniu interesu, nie potwierdzenie legalności obecnego automatu.

Materiały: `05-retencja-36-miesiecy/ZADANIE.md`, cały opis decyzji i tabel w `ADR_RETENCJE.md`, `COMPLIANCE.md`. Przepisy i źródła: [L1–L3] w `../zrodla-prawne.md`.

**Rozbieżność stanu:** brief zadania 5 deklaruje uruchomione 36 miesięcy i jedynie rekomendowane 24/24, natomiast nagłówek ADR nadal mówi „PROPOZYCJA, NIC WDROŻONE”; zadanie 3 opisuje wdrożenie oczekujące pierwszego uruchomienia. W tej ocenie przyjmuję deklarację właściciela z zadania 5 jako opis zamierzonego aktualnego stanu, ale nie jako dowód uruchomienia. Przed zmianą polityki trzeba sprawdzić commit, konfigurację oraz wynik rzeczywistego przebiegu.

## A. Trzy werdykty

| Kategoria | Werdykt | Jednozdaniowe uzasadnienie |
|---|---|---|
| Sprawy moderacyjne — 36 miesięcy | **ZŁA PODSTAWA — właściwa jest art. 6 ust. 1 lit. f RODO, z uwzględnieniem art. 17 ust. 3 lit. e** | Art. 442¹ k.c. pomaga ocenić interes w zachowaniu dowodów, lecz nie nakazuje retencji, a 36 miesięcy można rozważać dopiero dla niezbędnego materiału po odrębnym teście konieczności i równowagi. |
| Zwykły dziennik zdarzeń — 24 miesiące | **ZA DŁUGO — proponuję 12 miesięcy** | W opisanym modelu nie wykazano potrzeby dwóch lat wszystkich zdarzeń, a dowody konkretnych sporów powinny trafiać do ograniczonej dokumentacji sprawy, zamiast wydłużać cały dziennik. |
| Powiadomienia — 24 miesiące | **ZA DŁUGO — proponuję 3 miesiące** | Powiadomienie ma zwrócić uwagę na zdarzenie, nie zastępować bezterminowego archiwum relacji ani dokumentacji decyzji dostępnej do odwołania. |

**Zastrzeżenie do 36:** nie zatwierdzam 36 miesięcy dla wszystkich adresów e-mail, nazw, załączników, duplikatów treści i metadanych tylko dlatego, że leżą w tych samych tabelach. Jeżeli system nie umie oddzielić materiału dowodowego od zbędnych danych, problemem jest także zakres przechowywania, a nie tylko liczba w konfiguracji. [L1, art. 5 i 25]

## B. Odpowiedzi na sześć pytań

### 1. Czy art. 442¹ k.c. jest właściwą podstawą?

**Nie jako podstawa prawna samego przechowywania.** Przepis określa przedawnienie pewnych roszczeń o naprawienie szkody wyrządzonej czynem niedozwolonym; nie ustanawia obowiązku archiwizacji dokumentacji moderacyjnej przez 36 miesięcy. Spór może ponadto wynikać z umowy, naruszenia dóbr osobistych albo innych podstaw, więc nie każdy pozew będzie podlegał identycznemu terminowi. [L2]

Rozdziel trzy rzeczy:

| Pytanie | Odpowiedź dla tego projektu |
|---|---|
| Dlaczego wolno przetwarzać zwykłe dane po zakończeniu bieżącej moderacji? | Co do zasady rozważ art. 6 ust. 1 lit. f: konkretny uzasadniony interes w ustaleniu, dochodzeniu lub obronie roszczeń, po teście celu, konieczności i równowagi. |
| Dlaczego w konkretnym przypadku można odmówić usunięcia niezbędnego dowodu? | Art. 17 ust. 3 lit. e, w zakresie rzeczywiście potrzebnym do roszczeń; to wyjątek od usunięcia, a nie samodzielna uniwersalna podstawa każdego przetwarzania. |
| Co z danymi zdrowotnymi lub dotyczącymi przestępstw w zgłoszeniu? | Oprócz art. 6 trzeba ocenić osobno art. 9, w tym rzeczywistą niezbędność z ust. 2 lit. f, albo art. 10 i właściwe upoważnienie prawne; nie wystarczy nazwać całego archiwum „dowodowym”. |

Dla czynności konkretnie wymaganych przez DSA można rozważać art. 6 ust. 1 lit. c, wskazując obowiązek i jego zakres. Nie wolno z tego wywieść, że DSA nakazuje trzyletnie zachowanie wszystkich danych po zamknięciu zgłoszenia. [L1, L3]

**Co spisać w teście interesu:** jakie rzeczywiste rodzaje sporów są prawdopodobne; który element dokumentacji pozwala odpowiedzieć na zarzut; dlaczego wystarczy lub nie wystarczy streszczenie; jaki jest wpływ na zgłaszającego i osoby opisane; kto ma dostęp; kiedy usuwa się identyfikatory; jak działa sprzeciw, usunięcie i wstrzymanie kasowania konkretnego dowodu. Sama możliwość, że „ktoś kiedyś pozwie”, jest za ogólna.

### 2. Od kiedy naprawdę biegnie termin?

**Nie od administracyjnego zamknięcia sprawy i nie zawsze po prostu od zdarzenia.** W podstawowym wariancie art. 442¹ § 1 chodzi o moment, gdy poszkodowany dowiedział się albo przy zachowaniu należytej staranności mógł dowiedzieć się o szkodzie i osobie zobowiązanej do jej naprawienia; przepis zawiera też granicę dziesięcioletnią od zdarzenia. Inne warianty dotyczą m.in. szkody wynikłej z przestępstwa, szkody na osobie i małoletnich. Art. 118 przewiduje ponadto zasadę końca roku kalendarzowego dla terminów nie krótszych niż dwa lata. [L2, art. 118 i 442¹]

Dlatego **36 miesięcy od zamknięcia nie jest ani wiernym odtworzeniem przedawnienia, ani gwarancją, że potem nikt skutecznie nie wystąpi z roszczeniem**. Może skończyć się przed końcem właściwego terminu; zamknięcie przewlekłej sprawy może też niepotrzebnie wydłużyć retencję.

Jako **operacyjny początek retencji** zamknięcie jest rozsądne, jeśli zdefiniujesz je jako końcowe załatwienie połączonej sprawy: zgłoszenia, decyzji i wniesionych odwołań. Nie resetuj licznika po otwarciu widoku, dopisaniu technicznej notatki, eksporcie ani zmianie niewpływającej na rozstrzygnięcie.

**Błąd konstrukcyjny ADR:** jednakowe „36” dla `reports.resolved_at`, `moderation_actions.created_at` i `appeals.decided_at` nie oznacza wspólnego końca. Decyzja z 1 stycznia i odwołanie rozstrzygnięte 1 czerwca mają różne daty graniczne. Usunięcie rodzica w styczniu może przez kaskadę skasować odwołanie, którego własny okres kończy się dopiero w czerwcu. Zachowaj wspólny identyfikator sprawy i termin końcowy albo sprawdzaj wszystkie nieprzeterminowane zależności; samo pomijanie odwołań „otwartych” nie wystarcza.

### 3. Czy 36 miesięcy przechodzi proporcjonalność?

**Nie dla całego obecnie opisanego zestawu bez dodatkowego uzasadnienia; warunkowo dla ograniczonego rdzenia dowodowego.** Proponuję następujące rozdzielenie, zamiast arbitralnego stwierdzenia, że każda kolumna potrzebna jest przez tyle samo czasu:

| Warstwa | Proponowany czas | Zawartość i warunek |
|---|---|---|
| Sprawa aktywna | Do rzeczywistego zakończenia, z okresowym przeglądem zaległości | Kontakt i treść potrzebne do obsługi, bez odkładania spraw na zawsze w statusie „open”. |
| Rdzeń dowodowy | **36 miesięcy od końcowego zamknięcia** | Istotna treść lub jej konieczny fragment, zastosowana wersja reguły/podstawa, decyzja, daty, przebieg odwołania, niezbędne identyfikatory; dostęp ograniczony do obsługi sporu. |
| Kontakt zgłaszającego i zbędne dane poboczne | **12 miesięcy od końcowego zamknięcia**, krócej, gdy nie są potrzebne | Usunięcie imienia, e-maila, zbędnych danych w treści i metadanych; dalsze zachowanie konkretnego identyfikatora wyłącznie, gdy jego znaczenie dowodowe jest wykazane. |
| Dowód w konkretnej trwającej sprawie prawnej | Według udokumentowanego wstrzymania kasowania | Tylko objęty sprawą materiał, przegląd co 6 miesięcy, zakończenie blokady po ustaniu udokumentowanej potrzeby; nie cały profil ani cała baza. |

To **moja rekomendacja projektowa**, a nie ustawowe progi 12 lub 36 miesięcy. Nie ma dowodu, że 12 miesięcy identyfikacji jest zawsze konieczne; jest to ostrożny maksymalny okres standardowy do obronienia w teście interesu, z możliwością wcześniejszego usunięcia. Żądanie osoby ocenia się indywidualnie, a nie odpowiada automatycznie „wróć za trzy lata”. [L1]

Rozpatrz oddzielnie fałszywe oskarżenie, zwykłe naruszenie netykiety, ujawnienie adresu, materiał zdrowotny i potencjalnie przestępczy. Kopia zgłoszenia zawierająca cudzy adres nie staje się nieszkodliwa, kiedy znika e-mail zgłaszającego. Nie zachowuj całego zdjęcia, jeśli dowodem jest wyłącznie fragment podpisu. Nie przechowuj na własną rękę materiału, którego posiadanie może być zabronione — potrzebna jest osobna procedura zabezpieczenia i przekazania organom.

### 4. Czy DSA narzuca inne okresy?

**DSA nie ustanawia ogólnego okresu 36 miesięcy przechowywania zgłoszeń ani 24 miesięcy logów lub powiadomień.** Trzeba odróżnić obsługę konkretnej sprawy, dostęp do skargi, sprawozdania i udzielanie informacji organowi. [L3]

| Przepis | Znaczenie dla retencji |
|---|---|
| Art. 16–17 | Wymagają odpowiednio obsługi zawiadomień i uzasadnień dla odbiorców objętych decyzją; nie są nakazem zachowania całej sprawy przez trzy lata. |
| Art. 20 | Jeśli obowiązuje, trzeba zapewnić co najmniej sześć miesięcy na skargę od poinformowania o decyzji; sześć miesięcy to nie 180 dni i niekoniecznie data utworzenia rekordu. |
| Art. 19 | Mikro- i małe przedsiębiorstwa zasadniczo korzystają z wyłączenia obowiązków sekcji 3, z wyjątkiem art. 24 ust. 3; status trzeba ocenić według unijnej definicji, a nie samego braku JDG. |
| Art. 15 ust. 2 | Zawiera własne wyłączenie obowiązku sprawozdania dla określonych mikro- i małych przedsiębiorstw; nie należy przypisywać tego wyłączenia art. 19. |
| Art. 24 ust. 1–3 | Sprawozdawczość, publikacja liczby aktywnych odbiorców i informacja na żądanie to odrębne obowiązki; ust. 3 nie jest samym obowiązkiem publicznej publikacji co pół roku. |
| Art. 24 ust. 5 | Dotyczy przekazywania uzasadnień do bazy Komisji, w swoim zakresie podmiotowym i bez danych osobowych; to nie art. 17 nakazuje wszystkim bez wyjątku tę publikację. |

**Nie kasować wdrożonego odwołania tylko dlatego, że możliwe jest zwolnienie.** Regulamin już obiecuje to użytkownikowi. Trzeba dotrzymać tej funkcji także przy skracaniu retencji, niezależnie od kwalifikacji ustawowej.

Do sprawozdań zwykle da się zaprojektować odrębne zestawienia liczbowe bez nazw i surowej treści. Zachowaj definicje kategorii, wersję zapytania oraz kontrolę kompletności; samo nazwanie drobnych, łatwo rozpoznawalnych zestawień „anonimowymi” nie zapewnia anonimowości. Okres raportowania nie oznacza automatycznie tego samego okresu przechowywania każdego rekordu źródłowego.

### 5. Czy dane zgłaszającego i treść powinny mieć różne okresy?

**Tak — gdy pełnią różne funkcje, nie powinny dziedziczyć jednego okresu wyłącznie z powodu modelu bazy.** W tym projekcie rekomenduję opisane wyżej 12 miesięcy dla kontaktu po zamknięciu oraz 36 dla niezbędnego rdzenia. Wyjątek to konkretna sprawa, w której tożsamość sama stanowi niezbędny dowód, np. powtarzające się nadużycie lub kwestionowana autentyczność zgłoszenia.

„Usunięcie `reporter_id`” nie jest automatycznie anonimizacją. Osobę mogą identyfikować podpis, adres w treści, cytat, odnośnik do konta, nazwa pliku albo połączenie z inną tabelą. Zachowany losowy identyfikator sprawy powiązany z użytkownikiem oznacza zwykle pseudonimizację i nadal podlega RODO. [L1, motyw 26]

Nie przyjmuję argumentu ADR, że rozdzielenie wymaga migracji, więc nie warto go robić. To koszt wdrożenia ochrony danych w projekcie, nie podstawa zachowania zbędnych danych. Minimalny zakres zmiany: nullable/redagowane pola kontaktowe, osobny ograniczony materiał dowodowy, termin redakcji i termin usunięcia, testy połączeń oraz zachowania plików.

### 6. Czy 24 miesiące dziennika i powiadomień są obronne?

**W paczce nie przedstawiono wystarczającego uzasadnienia obu okresów.** Nie dowodzi to, że 24 miesiące jest zawsze nielegalne, ale brak argumentu nie powinien być zastępowany intuicją.

**Dziennik — 12 miesięcy:** wystarczy jako proponowany standard dla przeglądu uprawnień, istotnych zmian i odtwarzania niedawnych incydentów. To nie polecenie trzymania przez rok każdego drobnego zdarzenia ani liczników rate-limit: ich własne, krótsze TTL pozostają osobne. Konkretny incydent lub spór przenosi potrzebny wycinek do dokumentacji sprawy; nie przedłuża wszystkiego. Do dziennika nie kopiować treści zgłoszeń, haseł, tokenów ani całych payloadów.

**Powiadomienia — 3 miesiące:** wystarczy jako proponowana wygoda użytkowa; zapis „Ugotowałem”, komentarz i przepis powinny pozostać w swoich właściwych zbiorach, a nie zależeć od starego dzwonka. Nieprzeczytanie powiadomienia nie przedłuża go bez końca.

**Warunek bezwzględny:** zanim zastosujesz 3 miesiące, decyzja i droga odwoławcza muszą być dostępne niezależnie od powiadomienia przez całe obiecane co najmniej sześć miesięcy, również osobie z zablokowanym kontem oraz zgłaszającemu korzystającemu z linku. Jeżeli jedyna droga to rekord `notifications`, **nie wdrażaj jeszcze 3 miesięcy**; najpierw oddziel doręczenie i dokument sprawy od kopii powiadomienia. Wartość TTL sama nie rozwiąże tej zależności.

## C. Lista „nigdy nie kasuj”: nie do obrony w opisanym kształcie

**Nie ma uzasadnienia dla bezterminowego zachowywania wszystkich trzech zdarzeń wraz z dowolnymi metadanymi.** Rozliczalność z art. 5 ust. 2 i 24 wymaga możliwości wykazania działania, ale nie ustanawia nieskończonej retencji osobowych logów. [L1]

Zastąp wyjątki `account.delete_requested`, `account.delete_cancelled`, `account.data_erased` **minimalnym potwierdzeniem obsługi żądania**, przechowywanym proponowane **36 miesięcy od zakończenia obsługi żądania**: losowy numer sprawy, daty otrzymania i zakończenia, rodzaj żądania, wynik, zakres wykonania, wersja procedury, informacja o wyjątkach i minimalny sposób powiązania z wnioskodawcą potrzebny do rozpatrzenia sporu. Nie kopiuj starego profilu, hasha hasła, fotografii ani całej korespondencji.

Ograniczony identyfikator, również hasz lub HMAC e-maila, nie jest magiczną anonimizacją; oceń możliwość odgadnięcia, ochronę klucza, dostęp oraz konieczność powiązania. Najprostszy projekt może opierać się na numerze sprawy przekazanym również osobie i ograniczonej ewidencji; nie musi odtwarzać usuniętego konta.

Żądanie cofnięte jest **dowodem cofnięcia**, nie wykonania usunięcia. Przechowuj właściwy stan końcowy, nie trzy niekasowalne kopie wszelkich danych. Po 36 miesiącach usuń potwierdzenie osobowe, o ile konkretna udokumentowana sprawa nie wymaga dalszego zachowania; trwale zachować można rzeczywiście anonimowe liczby oraz wersje procedur.

Dodatkowo potrzebny jest mechanizm ponownego zastosowania usunięć po przywróceniu backupu. Osobny rejestr blokujący „wskrzeszenie” danych powinien trwać tak długo, jak istnieją kopie, które mogą je przywrócić, według ustalonego cyklu kopii — **nie bezterminowo z definicji**.

## D. Ostateczne liczby

| Element | Liczba | Jedno zdanie uzasadnienia |
|---|---:|---|
| Ograniczony rdzeń sprawy moderacyjnej | **36 miesięcy** | Zachowuję wybraną liczbę jako warunkowy okres dowodowy po teście interesu, nie jako rzekomy obowiązek z k.c. |
| Zwykły dziennik zdarzeń | **12 miesięcy** | Brakuje uzasadnienia dwóch lat wszystkich zdarzeń, gdy konkretne dowody można zachować odrębnie. |
| Powiadomienia | **3 miesiące** | Mają służyć bieżącej informacji, a pełna dokumentacja i prawo odwołania muszą działać poza nimi. |
| Kontakt zgłaszającego w zamkniętej sprawie | **12 miesięcy** | Domyślne oddzielenie kontaktu od dłużej przechowywanego uzasadnienia ogranicza identyfikowalność. |
| Minimalne potwierdzenie obsługi usunięcia konta | **36 miesięcy** | Pozwala udokumentować obsługę bez bezterminowego zachowywania osobowych logów. |

Zmiana dwóch podstawowych TTL może być jedną linią każda, ale **minimalizacja, odwołania, backupy i usunięcie bezterminowych wyjątków nie są jednolinijkową zmianą**.

## E. Trzy zdania do tabeli polityki — wariant docelowy

**Nie wklejać jako opisu obecnego działania przed wdrożeniem i testem.** Liczby celowo mają dokładną postać użyteczną w teście dokumentacji.

**Sprawy moderacyjne:**
> Niezbędną dokumentację zgłoszenia, decyzji i odwołania przechowujemy przez 36 miesięcy od końcowego zamknięcia sprawy, a dane kontaktowe zgłaszającego usuwamy co do zasady po 12 miesiącach od jej zamknięcia, chyba że konkretna trwająca sprawa prawna wymaga zachowania określonych danych dłużej.

**Dziennik zdarzeń:**
> Zwykłe wpisy w dzienniku zdarzeń przechowujemy przez 12 miesięcy od ich zapisania, a niezbędne dowody konkretnych sporów i potwierdzenia obsługi żądania usunięcia konta przechowujemy osobno według opisanych w tej polityce zasad.

**Powiadomienia:**
> Powiadomienia w serwisie przechowujemy przez 3 miesiące od ich utworzenia, niezależnie od tego, czy zostały odczytane, przy czym usunięcie powiadomienia nie usuwa dokumentacji decyzji ani nie skraca terminu odwołania.

**Konieczne uzupełnienie poza tymi trzema wierszami:** osobny wiersz potwierdzeń z okresem **36 miesięcy**, opis wyjątków dla konkretnych postępowań, ograniczenia dostępu, praw osoby i cyklu backupów. Nie chowaj wyjątku bezterminowego pod sformułowaniem „według innych zasad”.

## F. Kontrola wdrożenia, zanim polityka zacznie to obiecywać

1. Porównać konfigurację i wykonywany harmonogram z tekstem, zachować commit oraz wynik przebiegu bez surowych danych osobowych.
2. Sprawdzić miesiące kalendarzowe, końce miesięcy, strefę czasu i granicę „starsze niż”; proces dzienny może wykonać usunięcie dopiero w następnym przebiegu, czego nie należy ukrywać jako gwarancji usunięcia co do sekundy.
3. Sprawdzać całą sprawę, nie trzy niezależne tabele: brak skasowania rodzica z młodszym odwołaniem, aktywną sprawą lub blokadą prawną; blokada i ponowne sprawdzenie stanu chronią przed wyścigiem z nowym odwołaniem.
4. Potwierdzić, że kasowanie partiami jest wznawialne i nie zostawia nadmiarowych plików, duplikatów powiadomień, danych w logach albo indeksach.
5. Przetestować odwołanie po usunięciu powiadomienia, z konta zablokowanego i z linku zgłaszającego; testować rzeczywiste poinformowanie, nie samo ustawienie pola „sent”.
6. Ustalić TTL backupów i odtwarzanie rejestru usunięć; sprawdzić redakcję kontaktu oraz usunięcie osobowego potwierdzenia po jego końcu.
7. Otwarte sprawy przeglądać regularnie, np. co miesiąc, a blokady prawne co 6 miesięcy; zapis „otwarte” nie może zastąpić decyzji o dalszej konieczności przechowywania.

**Wniosek:** liczba 36 może zostać, ale argument i zakres danych muszą się zmienić; 24/24 oraz „na zawsze” nie mają w paczce wystarczającego uzasadnienia.
