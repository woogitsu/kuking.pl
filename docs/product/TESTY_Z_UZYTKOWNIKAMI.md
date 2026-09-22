# Badanie użyteczności przed betą — protokół R1

Wersja 1.0, 20 września 2026. Powiązanie: [#15](https://github.com/woogitsu/kuking.pl/issues/15).
**Materiał przygotowany; żadna sesja z człowiekiem nie została tu wykonana.**

## 1. Zakres i warunek rozpoczęcia

Celem jest sprawdzenie, czy człowiek sam osiąga cel i rozumie skutek swoich
czynności. Nie mierzymy znajomości nazw przycisków. Kuking jest społecznością
ludzi, którzy gotują; nie przedstawiamy go uczestnikom jako serwisu „dla seniorów”.

Ten protokół obejmuje osiem zadań na zalogowanym koncie ćwiczeniowym. Zastępuje
poprzednią instrukcję operacyjną tego pliku, ale **nie zmienia bramki #15**:
13 rzeczywistych sesji (5 osób 50–59, 5 osób 60–69, 3 osoby 70+), Android,
iPhone i komputer, przynajmniej dwie osoby bez doświadczenia publikowania,
naprawione blokery oraz wnioski w standardzie UX. Pięć osób może stanowić
pierwszą rundę rozpoznawczą; nie spełnia tego kryterium.

Rejestracja, komentarz, usuwanie wpisu, blokowanie osoby i eksport z zakresu
#15 nie mieszczą się w tej rundzie. Właściciel musi zaplanować ich osobne
sprawdzenie przed uznaniem #15 za zakończone. Nie skreślamy ich z issue.
Tryb gotowania pozostaje w [dodatku](SCENARIUSZE_UZUPELNIAJACE_15.md).

Praca nad tym dokumentem nie obejmuje kontaktów, rekrutacji ani zbierania
informacji o ludziach. Wariant R1 jest bez nagrań, zdjęć uczestnika, własnych
adresów e-mail i prywatnych materiałów. Używamy wyłącznie ćwiczeniowych kont,
zdjęć i przepisów w odizolowanej instancji, niedostępnych publicznej społeczności.
Nie tworzymy pozornych wykonań na produkcji. Historyczne zalecenia #15 dotyczące
prawdziwych kont i nagrania nie są procedurą tej rundy. Ten wariant nie bada
rejestracji ani wiarygodności serwisu przy podawaniu własnych danych.

Właściciel organizuje ewentualne sesje osobno. W repozytorium zostają puste
szablony i podsumowania pozbawione danych identyfikujących, nigdy lista kontaktów.
Kod sesji R1-S01 nie ma klucza łączącego z nazwiskiem. Nie zapisujemy wieku,
płci, głosu, adresów, danych logowania ani prywatnych cytatów. Ewentualne
sprawdzenie składu grupy pozostaje po stronie właściciela, poza tym materiałem.

## 2. Przygotowanie — lista prowadzącego, nie uczestnika

Zarezerwuj do 60 minut: 5 na wstęp, do 40 na zadania, 5 na pytania, 10 na
przerwy i zakończenie; później 15 minut na uporządkowanie notatek. Limit czasu
zadania chroni przed zmęczeniem, nie definiuje sprawności człowieka.

Dzień przed sesją wykonaj poniższe czynności. Nie zaczynaj spotkania z brakami.

1. Wpisz w [kartę rundy](KARTA_BADANIA_15.md) SHA aplikacji, wersję protokołu,
   adres instancji, wariant danych, flagi funkcji i datę próby technicznej.
   Zamroź wersję i dane na całą rundę. Nie zakładaj, że najnowszy main jest
   tą wersją, którą widzi przeglądarka.
2. Wydziel bazę i media badania. Dla stanowiska floty obowiązuje wyłącznie
   PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_gpt-testy-50plus`.
   Testy automatyczne i sesje z ludźmi nie mogą działać na niej jednocześnie.
   Nie uruchamiaj seedera na produkcji ani współdzielonej bazie.
3. Przygotuj osobne konto ćwiczeniowe dla każdej sesji i konto autora B.
   Oba aktywne i zweryfikowane, bez blokad. Logowanie wykonaj przed pomiarem.
   B nie jest prawdziwym użytkownikiem i nie otrzymuje zewnętrznych wiadomości.
   Wyłącz wysyłkę poczty, push i integracje zewnętrzne w instancji ćwiczeń;
   powiadomienia wewnętrzne i przetwarzanie zdjęć muszą działać.
4. Przygotuj publiczny **wewnątrz instancji ćwiczeń** przepis B „Żurek na
   domowym zakwasie”: składniki, co najmniej trzy kroki, zdjęcie w stanie
   `ready`. Dodaj dwa inne przepisy i wpis ze zdjęciem żurku bez przepisu,
   aby wynik nie był jedyną kartą. Konto uczestnika nie ma zapisu tego przepisu
   ani wykonania. Zapisz adresy w karcie danych, niewidocznej dla uczestnika.
5. Na urządzeniu przygotuj folder „Ćwiczenie Kuking”: zdjęcie potrawy JPG bez
   danych lokalizacyjnych i osób; kartę receptury: „Zupa marchewkowa — ćwiczenie”,
   „2 marchewki, 500 ml wody”, „Pokrój marchew. Gotuj w wodzie do miękkości.”.
   Ten sam zestaw dla całej rundy. Uczestnik może przepisać tekst własnymi
   słowami, nie wnosi rodzinnych materiałów. Nie wymagaj wykonania potrawy.
6. Przygotuj niezależny własny przepis uczestnika „Zupa próbna” i jedno
   nieprzeczytane powiadomienie o wykonaniu go przez B. Wywołaj rzeczywistą
   akcję „Ugotowałem” z konta B w środowisku ćwiczeń; nie wpisuj samego licznika
   do bazy. To stan T6, niezależny od wyniku T2 i T5. Zachowaj go nieprzeczytany.
7. Dla T7 przygotuj dokładnie 40 widocznych tematów, w tym „Chleb” (obserwowany)
   i „Zupy” (nieobserwowany). Oprócz Chleba zaznacz cztery inne i zapisz ich
   nazwy. Zapisz pełną kolejność 40 pozycji; odtwarzaj ją w każdej sesji.
   To kontrolowany przypadek, nie twierdzenie o wielkości list produkcyjnych.
8. Przejdź sam wszystkie osiem zadań przez interfejs. Potwierdź po ponownym
   wejściu: zapis treści, zdjęcia, odbiorców, zeszytu, wykonania, powiadomienia
   i zbioru obserwowanych tagów. W karcie wpisz PASS/STOP dla każdego stanu
   startowego. Brak treści, niedziałający upload lub powiadomienie = STOP
   przygotowania, nie wynik uczestnika. Nie uznawaj samego istnienia trasy
   ani zielonego testu PHP za tę próbę.
9. Zostaw naturalne ustawienia czcionki, jasności i przeglądarki urządzenia.
   Nie przełączaj na mały tekst, żeby sztucznie wywołać T8. Nie loguj na
   prywatne konta. Jeśli nie da się oddzielić ćwiczenia od prywatnych danych,
   przerwij przygotowanie i rozstrzygnij z właścicielem warunki sesji.

Kolejność: **T1 → T2 → T3 → T4a → T5 → T6 → T4b → T7 → T8**.
T4 ma dwa etapy jednego zadania. [Osobne karty do czytania](KARTY_ZADAN_15.md)
nie zawierają kryteriów ani podpowiedzi. T5–T6 oddzielają etapy T4; wpisz faktyczną długość odstępu. T5 ponownie
pokazuje ten sam przepis, więc może pomóc w jego rozpoznaniu w T4b. Ten wpływ
jest stały w obu rundach; nie jest to niezależny test pamięci. Uczestnik widzi wyłącznie bieżący tekst
zadania, nigdy kryterium, hipotezę ani adres. Wydruk tekstów: minimum 18 px
lub 14 pt. Nie dawaj do czytania szerokiej tabeli prowadzącego.

## 3. Co mówi prowadzący

Na początku przeczytaj:

> „Sprawdzamy serwis, nie Twoje umiejętności. Tu są wyłącznie dane do ćwiczenia.
> Nie wpisuj własnych danych ani nie wybieraj prywatnych zdjęć. Nie nagrywamy.
> Zapiszę tylko to, co pomaga lub przeszkadza w wykonaniu zadań. Możesz zrobić
> przerwę albo zakończyć w dowolnym momencie. Proszę mówić, czego teraz szukasz
> i co zamierzasz zrobić. Nie będę od razu pomagać, bo chcemy zobaczyć, gdzie
> sam serwis nie daje jasnej drogi. Czy możemy zacząć?”

Brak zgody na rozpoczęcie oznacza koniec, bez namawiania. Przerwa lub wycofanie
nie są usterką produktu ani porażką osoby. Nie obiecuj pełnej anonimowości
samego spotkania; notatki mają opisywać interakcję bez identyfikujących szczegółów.

| Sytuacja | Wolno powiedzieć lub zrobić | Zapis |
|---|---|---|
| Niezrozumiały cel | Powtórz dosłownie tekst zadania, bez wskazywania drogi | Q: powtórzenie, czas i słowa |
| Cisza | Poczekaj co najmniej 10 sekund; najwyżej raz na zadanie: „O czym teraz myślisz?” | Q: neutralne przypomnienie |
| „Co mam kliknąć?”, prośba o pomoc | „Co chcesz teraz osiągnąć?”; po odpowiedzi pozwól dalej próbować | Q; zanotuj dokładne miejsce i pytanie |
| „Czy dobrze?” | „Po czym poznasz, że cel został osiągnięty?” | Q; nie potwierdzaj poprawności |
| Rezygnacja lub limit | „Zatrzymajmy to zadanie. Dziękuję, to nam pomaga zobaczyć trudność.” | Stop próby samodzielnej |
| Błędna strona po wcześniejszym zadaniu | Dopiero po zatrzymaniu czasu ustaw stan startowy następnego | R: reset organizacyjny |
| Ryzyko ujawnienia danych lub dyskomfort | Zatrzymaj od razu; nie zachowuj prywatnej treści w notatkach | W: przerwanie, ogólny powód |

**Zabronione przed oceną:** nazwanie przycisku, ikony, menu lub „Zeszytu”,
wskazywanie wzrokiem/palcem, sugerowanie scrollowania, pytania „a trzy kropki?”,
klikanie za osobę, pokazywanie przykładu rozwiązania, „to proste”, „świetnie,
właśnie tak”. Nie tłumacz pojęcia, którego zrozumienie badamy. Naturalną pomoc
widoczną w produkcie można czytać i wykorzystywać; nie jest pomocą prowadzącego.

Każda wskazówka o drodze — nawet przypadkowa — to **H** i wyklucza sukces
samodzielny. Zapisz jej dosłowną treść i czas. Pomoc jest dopuszczalna dopiero
po zapisaniu niepowodzenia próby samodzielnej, jeśli osoba chce kontynuować;
wynik po pomocy wpisz osobno. Nie wydłużaj próby po limicie, aby dostać sukces.
Nie podpowiadaj i nie naprawiaj interfejsu w trakcie rundy.

## 4. Osiem zadań i kryteria ustalone przed badaniem

Czas od końca odczytania celu i udostępnienia stanu startowego do deklaracji
zakończenia, rezygnacji lub limitu. Zapisuj sekundy i osobno przerwy. Pytania
kontrolne po zakończeniu nie wchodzą do czasu, lecz mogą zmienić ocenę
zrozumienia skutku. Limity są organizacyjne, nie SLA produktu.

### T1 — Pokaż danie (6 minut)

**Czytaj:** „Wyobraź sobie niedzielny obiad ze zdjęcia w folderze ćwiczenia.
Pokaż go innym osobom w tym serwisie i dopisz kilka słów.”

Start: `/home`, konto bez wcześniejszych własnych wpisów ze zdjęciem.
Sukces: istnieje opublikowany wpis ze wskazanym zdjęciem i niepustym opisem,
widoczny dla innych kont ćwiczeniowych; osoba rozpoznaje, że został opublikowany.
Pytania po: „Co się teraz stało?” i „Kto może to zobaczyć?”. Odpowiedź musi
odpowiadać rzeczywistej widoczności, którą prowadzący sprawdza po próbie.
Sam podgląd zdjęcia albo szkic nie zalicza zadania. Zapisz czas pierwszej
publikacji **od startu T1**, nie opisuj go jako czasu od wejścia/rejestracji.

### T2 — Zachowaj własny przepis (7 minut)

**Czytaj:** „Zachowaj tutaj przepis z kartki ćwiczenia, żeby jutro można było
według niego ugotować. Ma być dostępny tylko dla Ciebie.”

Start: `/home`, kartka z recepturą obok urządzenia.
Sukces: zapisany przepis lub świadomie wybrany szkic zawiera nazwę, oba składniki
z ilościami i obie czynności; jest prywatny i po ponownym wejściu zachowuje treść.
Nie wymuszaj kreatora ani publikacji; zapisz wybraną drogę i typ zapisu.
Pytania po: „Po czym poznajesz, że przepis został zachowany?” i „Kto go widzi?”.
Treść wyłącznie w otwartym formularzu nie wystarcza. Jeśli wystąpi walidacja,
zapisz, co z wpisanych danych zostało i czy osoba sama poprawiła problem.
Nie wywołuj błędu celowo; brak błędu = walidacja nieobserwowana, nie PASS.

### T3 — Znajdź przepis (4 minuty)

**Czytaj:** „Chcesz przygotować żurek. Znajdź tutaj przepis i sprawdź, czego
potrzeba oraz jak go przygotować.”

Start: `/home`, przygotowane przepisy i mylący wpis opisane w §2.
Sukces: osoba otwiera przepis na żurek i wskazuje składniki oraz przygotowanie.
Sam wpis ze zdjęciem, osoba lub wynik bez otwarcia nie wystarczają. Każda droga
w produkcie jest poprawna, także katalog tematów. Zapisz frazę, wybór kategorii
wyniku, ślepą uliczkę i ewentualne użycie katalogu; nie wymuszaj katalogu,
aby „zaliczyć” hipotezę #681.

### T4 — Zachowaj i odnajdź (3 + 3 minuty)

**T4a czytaj:** „Ten przepis przyda się za tydzień. Zrób tak, żeby można było
łatwo do niego wrócić w tym serwisie.”

Start: przepis z T3. Po niepowodzeniu T3 prowadzący otwiera przygotowany żurek
jako R, zanim uruchomi czas T4a. Sukces etapu: trwały zapis właściwego przepisu
na koncie; nie wymagamy nowego zeszytu. Zakładka przeglądarki to alternatywna
strategia, odnotuj ją, ale nie spełnia celu „w tym serwisie”.

**T4b czytaj dopiero po T5–T6:** „Wróć teraz do zachowanego wcześniej przepisu.”

Start: `/home`, bez otwartej karty przepisu. Sukces celu: otwarcie tego samego
przepisu. Osobno zapisz, czy prowadziły do niego własne zapisane treści, czy
ponowne wyszukanie. Ta druga droga spełnia cel, ale NIE dowodzi odnajdywania
zapisu przez nawigację. Nie zamieniaj preferowanej przez projektanta drogi
w ukryte kryterium porażki.
**Łączny sukces T4 wymaga obu etapów.** Jeśli T4a się nie udało, przygotuj zapis
jako R przed T4b; T4b oceniaj osobno, T4 nie dostaje łącznego sukcesu.
Nie jest to test pamięci po tygodniu — przerwa trwa tylko kilka minut.

### T5 — Pokaż wykonanie cudzego przepisu (5 minut)

**Czytaj:** „W tym ćwiczeniu wyobraź sobie, że żurek według tego przepisu jest
już gotowy. Pokaż autorowi i innym osobom zdjęcie tego wykonania, tak żeby było wiadomo,
według którego przepisu powstało. Użyj zdjęcia ćwiczeniowego; możesz dopisać uwagę.”

Start: przepis B na żurek; prowadzący otwiera go przed pomiarem, bez pokazywania
przycisku. Sukces: nowe wykonanie powiązane z właściwym przepisem i kontem,
z podanym zdjęciem; osoba rozumie, że zgłasza wykonanie, nie zapis na później.
Pytaj po: „Co autor dowie się z tej czynności?”. Oczekujemy rozumienia informacji
o wykonaniu, nie znajomości kanału technicznego. Komentarz lub samodzielny wpis
bez powiązania to inna czynność. **Osobno** po sesji sprawdź powiadomienie
na koncie B: tak/nie/niezweryfikowane. Jego brak jest problemem technicznym,
nie błędem osoby. Symulacja nie dowodzi, że uczestnik cokolwiek ugotował.

### T6 — Zrozum wiadomość (3 minuty)

**Czytaj:** „Pojawiła się tu wiadomość o Twoim przepisie. Sprawdź, co się
wydarzyło, i pokaż treść, której dotyczy.”

Start: `/home`, niezależne nieprzeczytane powiadomienie przygotowane w §2.
Sukces: odnalezienie wiadomości, trafne wskazanie kto/co zrobił i otwarcie
właściwej treści. Pytaj po: „Czego dotyczyła ta wiadomość?”. Sam licznik lub
oznaczenie wszystkiego jako przeczytane nie wystarcza. Polecenie zapowiada
wiadomość, więc wynik nie dowodzi spontanicznego zauważania powiadomień.

### T7 — Zmień zainteresowania (5 minut)

**Czytaj:** „W tym ćwiczeniu interesują Cię już zupy, a chleb przestaje Cię
interesować. Zmień swoje zainteresowania w serwisie. Pozostałe mają zostać.”

Start: `/home`; dokładnie ustalona lista 40 tematów z §2.
Sukces: po zapisie i ponownym wejściu Chleb nie jest obserwowany, Zupy są,
a cztery pozostałe obserwowania nie zmieniły się. Zapisz osobno odnalezienie
ustawień, obu tematów i potwierdzenia zapisu oraz przypadkowe zmiany.
Nie wymagaj filtra/doładowania — obserwuj ich użycie, jeśli badany SHA je ma.
Warianty 10 i 100 tematów wymagają osobnych rund z identycznym schematem danych;
nie mieszaj ich z 40 ani nie pokazuj tej samej osobie trzech wariantów jako
trzech niezależnych pierwszych prób. Decyzja o budowie filtra już zapadła.

### T8 — Ułatw sobie czytanie (4 minuty)

**Czytaj:** „Chcesz przeczytać ten przepis większym tekstem. Dopasuj go tak,
żeby wygodnie przeczytać składniki i przygotowanie.”

Start: przygotowany przepis B, bieżące naturalne ustawienia urządzenia.
Sukces: osoba samodzielnie powiększa tekst przez produkt, przeglądarkę lub
system i dociera do składników oraz przygotowania; nic nie odcina drogi
powrotu. Nie wymagaj znalezienia konkretnego widżetu. Zapisz drogę, początkową
i końcową skalę, zasłonięcia oraz deklarację czytelności. Odmowa powiększania,
bo tekst już wygodny = W, nie porażka; nie pogarszaj ustawień dla wyniku.

## 5. Hipotezy i źródła — karta wyłącznie dla prowadzącego

Poniższe pomiary są przejęte, a nie wykonane w tej pracy. Stan zgłoszenia nie
mówi, czy testowana wersja zawiera poprawkę. Hipoteza nie jest nowym zgłoszeniem
błędu; zachowanie poprawne też jest wartościowym wynikiem.

| Podejrzane miejsce | Źródło i co naprawdę zmierzono | Co obserwować |
|---|---|---|
| Długa lista „Twoje tagi” | [pomiar cudzy: #858, komentarz 20.09](https://github.com/woogitsu/kuking.pl/issues/858#issuecomment-5751034532): 100 tematów, 320 px, tekst 200% → 27 515 px wysokości. Koszt ekranu, nie zachowanie ludzi. Właściciel zdecydował o filtrze i doładowaniu | T7: czy oba tematy można znaleźć i zachować pozostałe wybory; także czas, porzucenie i użycie filtra |
| Kafle tematów i droga do przepisu | [pomiar cudzy: #681, raport](../design/FOTOGRAFICZNE_TAGI_681.md): 36 konfiguracji układu, kliknięcie/dotyk emulowany; brak badania fizycznego telefonu | T3: czy zdjęcie i nazwa pomagają rozpoznać cel. Brak użycia katalogu = hipoteza nieobserwowana |
| Powiększenie i zasłanianie | [#684](https://github.com/woogitsu/kuking.pl/issues/684), [pomiar cudzy: raport #681, odbiór końcowy](../design/FOTOGRAFICZNE_TAGI_681.md): zasłanianie przez Wygląd pozostawało ograniczeniem | T8: czy osoba znajduje dowolną skuteczną drogę, czy potrafi czytać i wrócić |
| „Zapisuję”, „Moje”, „Zeszyt” | [#473 i pomiar cudzy: walidacja wyboru](WALIDACJA_WYBORU_ZESZYTU.md): 14 przypadków / 102 asercje w CI dowodzą zapisu i walidacji, nie rozumienia nazw | T4a/b: czy zapis jest rozpoznany i odnajdywany; obejścia osobno |
| Zdjęcie a przepis, zapis prywatny, błędy | [#647 i pomiar cudzy: odbiór](../design/TAGI_W_OPISIE_647.md): ukryty wynik podpowiedzi bez JS wykryty mimo zielonych testów sesji; [dodatek #15](SCENARIUSZE_UZUPELNIAJACE_15.md) rozdziela drogi przepisu | T1/T2: rozróżnienie obiektów, pewność zapisu, widoczność i zachowanie danych przy naturalnym błędzie |
| Wykonanie a zapis na później | [#15](https://github.com/woogitsu/kuking.pl/issues/15), [AGENTS §1](../../AGENTS.md) i [dodatek](SCENARIUSZE_UZUPELNIAJACE_15.md): wymaganie powiadomienia, nie dowód jego zrozumienia | T5/T6: wybór właściwej czynności, interpretacja zdarzenia i przejście do treści |
| HEIC | [#119](https://github.com/woogitsu/kuking.pl/issues/119), D-064: świadome odrzucenie formatu, potrzebny pomiar z iPhone'a | R1 używa JPG, więc NIE weryfikuje tej hipotezy. Osobny wariant z kontrolowanym HEIC i JPG, przed zamknięciem zakresu #119 |

Po wszystkich zadaniach, bez sugerowania odpowiedzi, można spytać na widocznym
aktualnym ekranie: „O czym jest ta sekcja?”, „Co przedstawia ten znak?” oraz
„Do kogo, Twoim zdaniem, jest ten serwis?”. Zapisz dokładny widoczny nagłówek
i odpowiedź. To pytania rozpoznawcze #5/D-013, nie dziewiąte zadanie ani ocena
gustu. Nie sugeruj „dla dzieci”, „dla starszych”, „ranking”. Jeśli badana
wersja nie pokazuje dawnej nazwy, oznacz ją nieobserwowaną; nie przywracaj jej.

## 6. Wyniki porównywalne między rundami

Użyj [KARTY_BADANIA_15.md](KARTA_BADANIA_15.md). Każdy etap ma dokładnie jeden kod:

- **S** — wszystkie kryteria spełnione samodzielnie, bez H; naturalne cofnięcie
  lub błąd naprawiony samodzielnie nie odbiera S.
- **H** — osiągnięto cel po wskazówce prowadzącego; to nie sukces samodzielny.
- **N** — kryterium nieosiągnięte: limit, rezygnacja z zadania z powodu interfejsu,
  błędna treść/widoczność, także gdy osoba uważa, że skończyła.
- **X** — nieważna próba: brak danych, awaria środowiska badania, pomylony stan
  startowy. Nie maskuj X błędu aplikacji: błąd produktu po poprawnym starcie = N
  z osobnym oznaczeniem technicznym.
- **W** — niepodjęte lub wycofane z powodów pozaproduktowych; ogólny powód.

Neutralne Q z tabeli wypowiedzi zapisuj osobno od wyniku zadania. R to przygotowanie stanu, nie pomoc
w bieżącym zadaniu. Osobno zapisuj najwyższy poziom pomocy i rezultat po pomocy.
Jeśli podpowiedź padła, a cel nadal nieosiągnięty, wynik N + flaga H.

Dla każdego zadania raportuj liczby **S / (S+H+N)**, a obok H, N, X, W oraz
liczbę zaplanowanych prób. Nie wpisuj automatycznie „z 13”. T4a i T4b mają
osobne wiersze, łączny T4 tylko z obu ważnych etapów; reset po porażce T4a
nie zamienia T4 w sukces. Przy dwóch ważnych etapach łączny wynik T4:
S tylko dla S+S, H gdy oba osiągnięte i co najmniej jeden H, N gdy któryś N;
przy X lub W brak ważnego wyniku łącznego (X ma pierwszeństwo przed W). Czas zestawiaj oddzielnie dla S, H i N; czas do
limitu nie jest czasem ukończenia. Dla małej próby podaj obserwacje i zakres,
nie deklarację statystycznej poprawy na podstawie średniej.

Bloker w rozumieniu #15: nieukończenie bez wskazania drogi, porzucenie przez
trudność produktu, niezamierzona nieodwracalna czynność lub błędne rozumienie
odbiorców. X/W nie są automatycznie blokerami UX, lecz X może zatrzymać badanie.
Każdy bloker wymaga osobnego opisu i sprawdzenia po naprawie przed betą.
Zachowaj porządek wymagany w #15: liczba dotkniętych osób, potem blokowanie
zadania; problem ujawnienia treści oznacz jawnie niezależnie od częstości.
Jedno zdarzenie prywatności nie staje się mało ważne dlatego, że wystąpiło raz.

Oddziel **obserwację**, **cytat** i **interpretację**. Zdanie prowadzącego,
które pomogło, jest kandydatem do zmiany, nie automatycznie gotowym tekstem UI.
Przy pytaniu produktowym wpisz warianty, koszt, dowody i decyzję właściciela;
nie zamieniaj wniosku z pięciu sesji w test automatyczny narzucający wybór.

Kolejna runda: zachowaj cele, kryteria, kolejność, limity, dane i profil
urządzenia. Zmień numer rundy i SHA. Zmianę protokołu oznacz nową wersją oraz
listą nieporównywalnych zadań. Wyniki po poprawce w środku rundy wydziel.
Osoby wracające znają drogę — ich wyniki opisz jako ponowne użycie, osobno od
pierwszego kontaktu. Nie łącz różnych list tagów, urządzeń i podpowiedzi w
jeden procent. Dodaj także to, co działało i powinno zostać zachowane.

## 7. Czego to badanie nie dowodzi; decyzje właściciela

Pięć osób nie daje statystyki populacji, reprezentatywności ani pewności, że
brak znalezionego problemu oznacza jego brak. Także trzynaście sesji nie daje
certyfikatu dostępności. Nie zmierzymy retencji, WAC/D30, gotowości do publikacji
własnego materiału, rzeczywistego gotowania, działania przy dużym ruchu,
bezpieczeństwa, dostarczalności e-maila ani pełnej zgodności WCAG.
Powrót po kilku minutach nie dowodzi powrotu po tygodniu. Sukces prowadzącego
lub automatu nie jest wynikiem użytkownika. Dane ćwiczeniowe obniżają realny
koszt błędu, więc nie dowodzą zaufania do publikacji własnych treści.

Przed sesjami właściciel zapisuje w karcie:

| Decyzja | Warianty i koszt |
|---|---|
| Pierwsza runda | 5 sesji rozpoznawczych (około 6 h 15 min wraz z notatkami, bez przygotowania) albo harmonogram pełnych 13 (około 16 h 15 min). Pięć nie zastępuje bramki #15 |
| Brakujące zadania #15 | Osobna sesja rejestracji/komentarzy/usuwania/blokowania/eksportu: większy nakład i osobna procedura; zmiana zakresu bramki: jawna decyzja w issue, nie domniemanie z R1 |
| Środowisko i dane | Izolowana kopia z próbnymi kontami ogranicza realizm, ale daje powtarzalność. Sesje na kontach rzeczywistych wymagają odrębnego planu i zasad danych; nie są objęte tą instrukcją |
| Skala list tagów | R1 = 40. Dodatkowe 10/100 = dodatkowe rundy; bez wiedzy o realnych listach nie nazywamy 100 przypadkiem typowym. Nie cofamy decyzji o filtrze z #858 |

Nie zmieniamy UI, migracji ani decyzji produktu tym protokołem. Właściciel
po badaniu zatwierdza listę problemów, osobno rozstrzyga pytania produktowe
i dopiero na podstawie rzeczywistych sesji ocenia bramkę #15.
