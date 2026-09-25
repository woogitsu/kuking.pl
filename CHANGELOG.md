# Co się zmieniło w Kuking

## Nieopublikowane

- Na stronie powiadomień przycisk „Oznacz wszystkie jako przeczytane” pojawia się tylko wtedy, gdy są nieprzeczytane powiadomienia. Jeśli między wczytaniem strony a kliknięciem wszystko zostało już przeczytane (np. w drugiej karcie), strona mówi, że nie było nic do oznaczenia, zamiast potwierdzać zmianę, której nie było (#1402).
- Komunikaty błędów w formularzu zgłoszenia treści niezgodnej z prawem, w „Napisz do nas” i przy zdjęciu kroku przepisu nazywają pole tak, jak stoi na ekranie — zamiast „target url” albo „steps.0.photo”. Źle wpisane obecne hasło przy zmianie adresu e-mail dostaje wskazówkę: „Wpisz swoje obecne hasło — to, którym logujesz się dziś”.
- Ustawienia prywatności otwarte dawno temu nie zapiszą Was z powrotem na tygodniowy e-mail. Jeśli w międzyczasie wypisaliście się odnośnikiem z e-maila albo w innej karcie, zapis starego formularza nic nie zmienia, a przy polu pojawia się wyjaśnienie i odnośnik „Otwórz aktualne ustawienia” — wybory z formularza zostają na miejscu.
- Strona główna nie zostaje pusta, gdy tuż przed jej otwarciem przestaliście kogoś obserwować, zablokowaliście kogoś albo zniknął ostatni wpis z Waszych tagów. Zamiast pustego ekranu pokazuje wtedy wpisy z tagów albo „Świeżo z Kuking” (#983).
- Moderator nie rozstrzyga już zgłoszenia, które dotyczy jego własnego wpisu, przepisu, komentarza albo profilu — taką sprawę zamyka ktoś inny z moderacji. Panel mówi wprost, dlaczego decyzji nie zapisał.

- List z linkiem do zalogowania, który utknął w kolejce dłużej niż ważność linku, już nie wychodzi — zamiast martwego linku wystarczy poprosić o nowy. List, który wychodzi z opóźnieniem, mówi, ile minut naprawdę zostało, zamiast obiecywać pełne pół godziny (#889). Gdy formularz logowania linkiem prosi „Kliknij „Wyślij mi link” jeszcze raz”, wpisany adres e-mail zostaje w polu (#890).
- Gdy dwie osoby w tej samej chwili zmieniają nazwę użytkownika na tę samą wolną nazwę, osoba, która zapisze drugą, nie widzi już błędu serwera. Wraca do formularza z komunikatem „Ta nazwa jest już zajęta — wybierz inną”, a imię, opis, region i specjalność zostają tak, jak je wpisała (#887).
- Przy każdym polu hasła jest przycisk „Pokaż hasło”, który odsłania wpisane hasło, żeby przed wysłaniem sprawdzić literówkę albo włączony Caps Lock. Drugie naciśnięcie („Ukryj hasło”) znów je zasłania, a przy wysyłaniu formularza hasło zasłania się samo. Menedżer haseł i wklejanie działają jak dotąd (#948).

- Style i skrypty strony zostają zapamiętane w przeglądarce już po pierwszej wizycie, więc kolejne wejście nie pobiera ich ponownie, a przy braku sieci wygląd strony się nie rozsypuje. Gdy w pamięci przeglądarki brakuje miejsca, strona nadal działa normalnie (#1348).

- Zakładka „Przepisy” na profilu wczytuje się szybciej: podpis autora na kartach przepisów jest pobierany raz dla całej strony, a nie osobno dla każdej karty. Kolejność i liczba przepisów na stronie się nie zmieniają (#1374).
- Ekran „Potwierdź swój adres e-mail” nie obiecuje już wiadomości, gdy serwis nie wysyła poczty. Zamiast „Wysłaliśmy wiadomość” i rady o folderze „Spam” mówi, że wiadomość nie przyjdzie, i podaje adres, pod którym człowiek pomoże potwierdzić adres inaczej. Przycisk „Wyślij wiadomość jeszcze raz” pokazuje się tylko wtedy, gdy list naprawdę może wyjść (#1335).
- Kontrola poczty (`/health`, `kuking:sprawdz-poczte` i formularze wysyłające listy) nie uznaje już za działającą pocztę łańcucha `failover` ani `roundrobin`, w którym jest zapis do dziennika (`log`) albo do pamięci (`array`) — także pod własną nazwą mailera. Domyślny wpis `failover` wysyła teraz przez EmailLabs, a w zapasie przez SMTP, zamiast kończyć się dziennikiem (#1084).

- Panel moderacji: na liście oznaczeń automatu liczby przy zakładkach „Nowe”, „W trakcie” i „Rozpatrzone” liczą oznaczenia automatu, a nie zgłoszenia od ludzi. Liczba w zakładce zgadza się z liczbą pozycji, które pokaże jej kliknięcie (#990).
- Na kroku „Kogo chcesz obserwować?” osoba znaleziona wyszukiwarką nie powtarza się już w „Osobach, które polecamy” — jej miejsce na liście polecanych zajmuje następna osoba (#1299). Gdy po błędzie w formularzu wracają Wasze zaznaczenia, nazwa, która w międzyczasie przeszła na inne konto, nie wraca zaznaczona przy nowej osobie; ekran mówi o tym wprost, a reszta zaznaczeń zostaje (#1340).
- Wyszukiwarka znajduje teraz przepisy, które możecie otworzyć: osoba obserwująca autora znajdzie jego przepis „dla obserwujących”, a autor — własny przepis „tylko dla mnie”. Takie wyniki mają na karcie plakietkę „Tylko dla obserwujących” albo „Tylko dla mnie”. Gość i osoba nieobserwująca nadal widzą w wynikach wyłącznie przepisy publiczne, a szkice nie trafiają do wyników (#1320).
- Pytanie ma teraz jeden adres — stronę w dziale pytań. Stary odnośnik do pytania jako zwykłego wpisu przenosi na właściwą stronę, a mapa strony dla wyszukiwarek podaje pytania pod ich własnym adresem — także te, które mają sam tytuł (#968).
- Strona przepisu bez gotowego zdjęcia nie wysyła już wyszukiwarkom niepełnych danych o przepisie. Gdy zdjęcie się przygotuje, dane pojawią się same (#1005).
- Po potwierdzeniu nowego adresu e-mail komunikat mówi wprost, że inne urządzenia zostały wylogowane, a stare odnośniki do logowania i do ustawienia hasła przestały działać. Link do ustawienia hasła wysłany wcześniej na stary adres jest teraz kasowany, a bieżąca przeglądarka dostaje nowy identyfikator sesji (#979).
- Strona tagu, który nie ma jeszcze żadnego wpisu widocznego dla wszystkich, nie jest już indeksowana przez wyszukiwarki (`noindex, follow`), a link do niej w spisie tagów ma `rel="nofollow"`. Dla ludzi nic się nie zmienia: strona działa, pokazuje prawdziwe zero i zaprasza do dodania pierwszego wpisu. Po pierwszym publicznym wpisie strona wraca do indeksu sama.
- Liczba wpisów przy tagu w spisie tagów nie liczy już zapowiedzi przepisu, którego nie widać publicznie — przepisu tylko dla obserwujących, prywatnego, ukrytego albo usuniętego. Liczba zgadza się teraz z tym, co gość zobaczy na stronie tagu.
- Adres strony, z której pochodzi przepis, musi zaczynać się od http:// albo https:// — w kreatorze i w formularzu „Dopisz szczegóły”. Adres zapisany wcześniej w innej postaci nie blokuje poprawiania przepisu, ale nie jest już pokazywany jako odnośnik.
- Zaproszenie „Ugotowałem” pokazuje się tylko tam, gdzie da się z niego skorzystać. Gość i konto zawieszone nie widzą już przycisku, który i tak skończyłby się odmową — ani przy przepisie, ani w trybie gotowania.
- W trybie gotowania jest „Zacznij od początku”. Odhaczone kroki znikają dopiero po potwierdzeniu: wyjście z ekranu i anulowanie zostawiają postęp, a inne przepisy oraz historia wykonań zostają nietknięte.
- Poprawka własnego komentarza odrzucona wyłącznie dlatego, że minęło piętnaście minut, nie zabiera już napisanego tekstu. Tekst wraca w polu do skopiowania, razem z drogą powrotu do rozmowy, i działa bez JavaScriptu. Okno edycji nie jest przez to przedłużane, a cudzy ani usunięty komentarz tą drogą nie wraca.
- Stopka jest wyraźnie niższa, a pod nią nie ma już pustego szarego pasa — strona kończy się tam, gdzie stopka, a miejsce na dolną belkę nawigacji zostaje tylko wtedy, gdy belka naprawdę jest na ekranie.
- Przy bardzo dużym tekście w przeglądarce przycisk „Wygląd” nie zasłania już na końcu strony przełącznika jasnego i ciemnego wyglądu ani numeru wersji w stopce — stopka zostawia mu miejsce pod spodem.
- Paczka z danymi nie zawiera już cudzego przepisu z zeszytu, którego autor przestał go Wam pokazywać — bo zmienił go na prywatny, ukryła go moderacja, jest blokada albo konto autora jest zamknięte. Taki przepis nie ma w paczce tytułu, autora ani Waszej notatki; każdy zeszyt podaje tylko, ile takich pozycji jest. Zapis w zeszycie zostaje, więc gdy autor znów udostępni przepis, wróci on w kolejnej paczce.
- „Oznacz wszystkie jako przeczytane” działa tylko na powiadomieniach, które widać na liście. Powiadomienie od osoby, z którą jest blokada, zostaje nieprzeczytane — po odblokowaniu wraca jako nowe, a nie jako coś, co już przeczytaliście.
- Szukanie znajomych w pierwszych krokach po rejestracji nie traci miejsca na Wasz własny profil. Gdy pasuje więcej niż pięć osób, widać pięć z nich i podpowiedź, żeby wpisać dokładniejsze imię.
- Dalsze strony profilu, spisu tagów, Odkrywaj i Poradźcie oraz zakładki profilu i filtry Poradźcie mają własny adres kanoniczny i własny adres w karcie udostępniania. Wyszukiwarka nie traktuje już ich jako kopii pierwszej strony, a dopiski śledzące (np. utm) dalej są z adresu usuwane.
- Profil otwarty z inną wielkością liter w nazwie (np. `/@basia_1971` zamiast `/@Basia_1971`) i każda strona otwarta przez `www` wskazują wyszukiwarce i karcie udostępniania jeden adres: zapisaną nazwę na `kuking.pl`. Stare linki dalej działają. Linki z przycisku „Podziel się” (WhatsApp, e-mail, Facebook) też prowadzą zawsze na `kuking.pl`, nawet gdy strona była otwarta przez `www`.

## Alfa 0.68 — minutnik przy gotowaniu i formularze, które nie gubią wpisanego tekstu

- Formularze ze zdjęciami przestały gubić to, co już wpisaliście. Wcześniej jedno źle wypełnione pole potrafiło zabrać całą resztę formularza razem z poprawnie wypełnionymi polami — teraz błąd zostaje przy swoim polu, a Wasz tekst czeka na miejscu. Podsumowanie błędów w kreatorze przepisu prowadzi do kroku, na którym to pole naprawdę jest.
- Postęp w trybie gotowania przeżywa poprawkę przepisu. Do tej pory każdy zapis przepisu po cichu odznaczał wszystkie odhaczone kroki, nawet gdy ich treść się nie zmieniła.
- Przy kroku przepisu jest minutnik, a ekran nie gaśnie, gdy gotujecie. Minutnik odlicza rzeczywisty czas — zmiana godziny w telefonie już go nie skróci ani nie przedłuży.
- Powiększone zdjęcie pokazuje swój opis. Gdy się nie wczyta, zamiast pustego miejsca pojawia się krótka informacja i przycisk „Spróbuj ponownie”.
- Wpis da się wyjąć z zeszytu. Karta wpisu ma przycisk „Usuń z zeszytu” obok odnośnika „Masz to w zeszycie”, a po kliknięciu od razu można zapisać ponownie.
- Stopka, okruszki nad przepisem i filtr na stronie pytań mają większe pismo i większe pola do kliknięcia — takie same, jakich wymagamy na pozostałych ekranach.
- Wyszukiwarka czyta frazę dosłownie: „100%” szuka teraz „100%”, a nie wszystkiego, co zawiera „100”. Wklejony adres z polskimi znakami staje się działającym odnośnikiem, a nie zwykłym tekstem.
- Pod komentarzem widać, ile znaków jeszcze zostało. Powiadomienie o komentarzu prowadzi wprost do właściwego wątku, a z komentarza usuniętego mimo odpowiedzi nie wystaje już jego dawna treść — ani w powiadomieniu, ani w pobranych danych konta.
- Ekran odwołania dla osoby zgłaszającej mówi, co się stało ze zgłoszoną treścią, ale nie ujawnia, jaką karę dostał jej autor. Potwierdzenie zgłoszenia i odwołanie po terminie przestały obiecywać rzeczy, które się nie wydarzą.
- „Zablokuj” i „Zdejmij blokadę” trafiają w tę osobę, którą widzieliście na ekranie. Jeśli w międzyczasie zmieniła nazwę użytkownika, serwis odmawia i mówi o tym po polsku, zamiast po cichu zablokować kogoś innego.
- Odwołanie i cofnięcie usunięcia konta są chronione przed zgadywaniem hasła tak samo jak logowanie. Komunikat po zablokowaniu podpowiada logowanie linkiem z poczty, a udana zmiana hasła blokadę zdejmuje.

## Alfa 0.67 — tagi ze zdjęciami z Waszych kuchni

- Wszystkie tagi mają większe kafelki, a katalog wykorzystuje szerokość ekranu komputera.
- Kafel pokazuje dostępne publiczne zdjęcie wpisu i jego autora. Gdy zdjęcia brakuje, pozostaje czytelna nazwa, licznik wpisów i znak Kuking.
- Kontrola przed wysłaniem zmian (`scripts/check.sh`) umie puścić baterię testów na kilku rdzeniach — `KUKING_TESTY_ROWNOLEGLE=6` skróciło ją z 310 do 105 sekund przy tych samych 4402 testach. Domyślnie nadal chodzi szeregowo: w pomiarze z 21 września równoległy przebieg wykonał jedną asercję mniej (83721 wobec 83722) i przyczyna pozostaje niewyjaśniona, więc zrównoleglenie jest świadomym wyborem, a nie zachowaniem domyślnym. Nic z tego nie zmienia działania serwisu.

## Alfa 0.66 — porządek w rozmowach i przygotowanie Poradźcie

- Przy błędzie odpowiedzi lub poprawki komentarza formularz zachowuje tekst i wskazuje właściwe pole.
- Przygotowaliśmy „Poradźcie”: pytania o gotowanie, odpowiedzi i kolejkę dla gospodarza. Dział pozostaje wyłączony do zakończenia odbioru i stopniowego uruchomienia.
- Usunięta odpowiedź z dalszą rozmową pozostawia miejsce dla tej rozmowy, ale nie zwiększa liczby odpowiedzi na pytanie.

## Alfa 0.65 — odnośniki prowadzą tam, gdzie obiecują

- „Poszukaj przepisów” w pustym zeszycie otwiera wyszukiwarkę przepisów. Odnośnik nad przepisem prowadzący do tablicy wpisów ma teraz zgodną z nią nazwę „Świeżo z Kuking”.

## Alfa 0.64 — wykonania i odpowiedzi liczone uczciwie

- Przy przepisie liczba wykonań obejmuje także kolejne gotowania tej samej osoby. Podpisy mówią teraz o wykonaniach i odpowiedziach, zamiast przedstawiać je jako liczbę osób.

## Alfa 0.63 — zobacz, co gotują inni pod tym tagiem

- Strony tagów pokazują kolaż najnowszych publicznych zdjęć od różnych osób. Zdjęcie prowadzi do wpisu, a przycisk dodawania otwiera formularz z wybranym tagiem.
- Promowane tagi mają karty ze zdjęciami i krótkim zaproszeniem. Pozostałe tagi nadal znajdziesz na liście alfabetycznej.

## Alfa 0.62 — zdjęcia i osoby przy tagach

- Przy tagach z co najmniej pięcioma publicznymi zdjęciami od trzech osób zobaczysz liczbę zdjęć i ich autorów. Przy mniejszym zbiorze strona tagu zaprasza do dodania własnego wpisu. Kolejność tagów pozostaje bez zmian.

## Alfa 0.61 — jasna instrukcja po zbyt dużym zdjęciu

- Jeśli jedno z nowych zdjęć wpisu przekracza limit, formularz prosi o ponowny wybór wszystkich nowych zdjęć. Opis i ustawienie widoczności pozostają zachowane.

## Alfa 0.60 — komentarze i wykonania bez cofania

- Na stronie przepisu przejście do kolejnych komentarzy zachowuje wybraną stronę wykonań — i odwrotnie. Możesz przeglądać obie listy bez wracania do początku.

## Alfa 0.59 — obie listy zostają na swoim miejscu

- W zeszycie przejście do kolejnej strony wpisów zachowuje wybraną stronę przepisów — i odwrotnie. Możesz przeglądać obie listy bez ciągłego wracania do początku.

## Alfa 0.58 — prawidłowy stan obserwowania

- Na listach obserwujących i obserwowanych przyciski pokazują, kogo obserwujesz. Po kliknięciu „Obserwuj” zobaczysz „Przestań obserwować”, także po ponownym otwarciu listy.
- Cofnięcie obserwowania nie wymaga już przejścia na profil.

## Alfa 0.57 — tagi podczas pisania wpisu

- W opisie wpisu możesz wpisać `#sernik` i wybrać tag z podpowiedzi. Przy istniejących tagach zobaczysz liczbę publicznych wpisów dostępnych dla Ciebie.
- Tagi wpisane lub wklejone do opisu zapisują się przy publikacji. Usunięcie hashtagu podczas edycji nie usuwa taga dodanego osobno ręcznie.
- Ręczne dodawanie tagów nadal działa. Wybrany wcześniej tag nie jest jednocześnie proponowany jako nowy, a ukryte tagi nie pojawiają się w odnośnikach pod wpisami.

## Alfa 0.56 — wybór zeszytu przy zapisie

- Przy przepisie i wpisie możesz wybrać własny zeszyt, również gdy treść jest już zapisana w innym miejscu. Szybki zapis do „Zapisanych” nadal jest dostępny.
- Pełne nazwy zeszytów zawijają się na małym ekranie. Jeśli wybrany zeszyt został usunięty przed zapisem, formularz pokazuje błąd przy właściwym wyborze.

## Alfa 0.55 — wskazanie błędnego stanu wiadomości

- Jeśli zapis stanu wiadomości w panelu zostanie odrzucony, odnośnik w podsumowaniu błędów prowadzi do wyboru stanu.
- Komunikat przy polu jest powiązany ze wszystkimi opcjami. Wpisana notatka pozostaje w formularzu.

## Alfa 0.54 — osobne notatki promowanych tagów

- Po błędnym zapisie notatki tekst i komunikat pozostają przy wybranym tagu. Pozostałe formularze zachowują własne wartości.
- Odnośnik w podsumowaniu błędów prowadzi do właściwej notatki. Usunięto powtórzony dopisek o nieobowiązkowym polu.

## Alfa 0.53 — czytelny błąd wyboru decyzji w odwołaniu

- Jeśli przy rozpatrywaniu odwołania nie wybrano wyniku, komunikat u góry prowadzi do właściwego pola.
- Wyjaśnienie pojawia się również przy wyborze decyzji, wyłącznie w wysłanym formularzu. Pozostałe odwołania zachowują własne wartości.

## Alfa 0.52 — widoczny fokus potwierdzeń w panelu

- Przy przechodzeniu klawiaturą do czyszczenia tablicy i kolażu panel pozostawia miejsce na cały obrys aktywnego przycisku, także przy zwiększonym tekście.
- Dodano regresje rozwijanych potwierdzeń i pomocy pocztowej, rzeczywistego zoomu 200% oraz fizyczne kontrole ujemne. Nie oznacza to zakończenia całego odbioru panelu #581.

## Alfa 0.51 — pełne nazwy dolnej nawigacji

- Skróty Start, Szukaj, Dodaj, Moje i Profil zachowują pełne nazwy na wąskim ekranie (#638).
- Przy większym tekście przyciski przechodzą do kolejnego rzędu; nie zmniejszamy pisma ani obszaru dotyku.

## Alfa 0.50 — krótsza nawigacja panelu na telefonie

- Narzędzia moderacji można rozwinąć przyciskiem „Nawigacja panelu”, dzięki czemu szybciej dociera się do treści (#581).
- Powrót do Kuking pozostaje widoczny. Na komputerze oraz bez JavaScriptu spis narzędzi jest rozwinięty.
- Menu obsługuje dotyk, mysz i klawiaturę; przy zmianie szerokości nie chowa aktywnego linku.

## Alfa 0.49 — propozycja instalacji po powrocie

- Po powrocie zalogowanej osoby serwis może raz zaproponować instalację, jeśli przeglądarka ją udostępnia (#278).
- Propozycja nie zasłania strony. Zamknięcie jest zapamiętywane na koncie; bez obsługi instalacji panel pozostaje ukryty.
- Wybranie instalacji nie jest liczone jako jej ukończenie.

## Alfa 0.48 — linki we wpisach i komentarzach

- Adresy HTTP, HTTPS i www we wpisach, komentarzach i odpowiedziach są klikalne (#634).
- Przed przejściem do innej witryny pokazujemy jej domenę, pełny adres i ostrzeżenie. Nie jest to skan antywirusowy ani zapewnienie o bezpieczeństwie strony.

## Alfa 0.47 — kolaż po wyczyszczeniu wyboru

- Kolaż powitalny pokazuje również jeden, dwa lub trzy dostępne zdjęcia.
- Nowsze wpisy bez gotowych zdjęć nie wypychają zdjęć z automatycznego doboru.
- Gdy nie ma dostępnych zdjęć, powitanie zajmuje jedną kolumnę bez pustego miejsca po prawej.

## Alfa 0.45 — przycisk rejestracji przy dużym tekście

- Na wąskim ekranie przycisk rejestracji zostawia więcej miejsca na pełny napis, zachowując wybrany rozmiar tekstu (#621).
- Przejście klawiaturą pozostawia zapas na obrys przycisku przy krawędzi okna.

## Alfa 0.44 — powiększanie zdjęć

- Zdjęcie można nadal otworzyć kliknięciem lub dotykiem, a link „Powiększ zdjęcie” pozostaje widoczny przy obsłudze klawiaturą także pod wysokimi zdjęciami (#561).
- Szybkie zamknięcie i ponowne otwarcie podglądu nie usuwa już wyświetlanego zdjęcia.
- Fokus linków w komunikatach ma czytelniejszy kontrast w ciemnym motywie.

## Alfa 0.43 — dokładniejsze odliczanie minutnika

- Minutnik uwzględnia czas, który minął podczas wstrzymania karty przez przeglądarkę (#571). Po wznowieniu nie odlicza pominiętych sekund od nowa.
- Bieżący czas można odczytać czytnikiem ekranu bez automatycznego ogłaszania każdej sekundy (#569).

## Alfa 0.42 — dalsze wyniki wyszukiwania

- „Pokaż więcej” pozwala dotrzeć do przepisów i osób poza pierwszymi 200 wynikami (#568).
- Dalsze strony pokazują zakres wyników i pozwalają wrócić do początku, również gdy wyniki w międzyczasie znikną.
- Przeglądanie dalszych przepisów zachowuje pozycję listy osób i odwrotnie. Filtry czasu, prywatność i blokady nadal obowiązują.

## Alfa 0.41 — proporcje mniejszej skali

- Przy rozmiarze tekstu poniżej 100% odstępy i zapas wewnątrz kontrolek zmniejszają się razem z tekstem; cele dotykowe zachowują minimum 48 px (#589).
- Domyślne odstępy przy 100% i 140% pozostają bez zmian. Wybór skali opisuje też zagęszczenie układu.

## Przygotowane — bezpieczeństwo logowania (#584)

- Wylogowanie innych urządzeń unieważnia również ich zapamiętane logowanie. Bieżąca sesja pozostaje aktywna; po jej utracie trzeba zalogować się ponownie. Ta sama ochrona obejmuje zmianę i reset hasła oraz decyzje o zamknięciu lub zawieszeniu konta.

## Alfa 0.40 — wygląd panelu moderacji

- Panel moderacji korzysta ze wspólnej identyfikacji: neutralnej nawigacji, czytelnych kart, formularzy i filtrów. Dłuższe nazwy narzędzi zawijają się obok ikon (#581).
- Zachowane są oznaczenie trybu moderacji, pełna szerokość pracy i dotychczasowe działania.
- Filtry dat mają więcej miejsca przy powiększonym tekście. Obrys klawiatury pozostaje widoczny także na ikonie kalendarza.
- Tabelę użytkowników można przewijać w dostępnym obszarze ekranu; przejście klawiszem Tab odsłania jej kolejne linki.
- „Sygnały automatu” pokazują podsumowanie błędów formularza, także gdy brakuje identyfikatora grupy. Notatka pozostaje do poprawienia.

## Alfa 0.39 — kolejka gospodarza

- Panel „Bez odpowiedzi” obejmuje również przepisy i wykonania „Ugotowałem”, z przejściem do komentarzy. Licznik uwzględnia dostęp gospodarza, a własne dopiski autora nie udają odpowiedzi innej osoby (#579).
- Odpowiedź z panelu ponownie sprawdza dostępność wpisu i zachowuje tekst po błędzie.

## Alfa 0.38 — szybkie ustawienia wyglądu

- Panel Aa · Wygląd pozwala od pierwszej wizyty zmienić rozmiar tekstu i motyw, także bez konta. Zapamiętuje wybór i pozwala wrócić do ustawień domyślnych (#574).

## Alfa 0.37 — pasek podczas przewijania

- Pasek z logo, logowaniem i rejestracją chowa się podczas przewijania w dół i wraca przy przewijaniu w górę. Fokus klawiatury przywraca pasek.

## Alfa 0.36 — niedostępne zapisy w zeszycie

- Zeszyt informuje o zapisach, których nie możesz teraz zobaczyć — także przepisach. Nie pokazuje mylącego pustego stanu ani prywatnych treści; komunikat pasuje również do cudzego publicznego zeszytu (#567).

## Alfa 0.35 — ponowne wysłanie formularza

- Ekran odzyskiwania formularza nie przypisuje każdego błędu potwierdzenia zbyt długiemu otwarciu strony. Wskazuje ponowienie wysłania i zachowuje osobne informacje o odzyskanej treści, zdjęciach oraz logowaniu (#549).

## Alfa 0.34 — odmiana czasu minutnika

- Instrukcja, podgląd przepisu i komunikat po uruchomieniu minutnika używają poprawnej formy „na 1 minutę” oraz „na 1 sekundę”. Czas i działanie odliczania pozostają bez zmian (#548).

## Alfa 0.33 — 15 września 2026

- Strona powitalna: kolejne wpisy zaczynają się pod poprzednią kartą we własnej kolumnie, bez pustych przerw wynikających z wysokości sąsiedniej karty (#560). Układ reaguje na rozwijanie treści, zdjęcia i zmianę szerokości; kolejność DOM oraz pojedyncza kolumna na telefonie pozostają.

## Alfa 0.32 — fotografie na stronie powitalnej

- Sekcja „Co się dziś gotuje” pokazuje najpierw duże fotografie dań, potem zwarte wizytówki osób. Gość dostaje jedno wspólne zaproszenie do założenia konta. Boczne tablice zachowują dotychczasowy układ, a dobór treści i zasady widoczności pozostają bez zmian (#557).

## Alfa 0.31 — szersze menu konta

- Menu „Konto” ma więcej miejsca na nazwy pozycji, w tym panel moderacji i wylogowanie. Przy zawijaniu belki pozostaje przy prawej krawędzi, a w wąskim i niskim oknie rozwija się w dostępnym miejscu pod przyciskiem (#555).

## Alfa 0.30 — zdjęcia w pustej kolumnie profilu

- Na cudzym profilu bez tagów i zeszytów pokazujemy trzy ostatnie widoczne wpisy ze zdjęciami. Zdjęcie i data prowadzą do wpisu; treści prywatne pozostają chronione (#551).

## Alfa 0.29 — prawdziwe komunikaty po publikacji

- Po pierwszym wpisie wskazujemy formularz kolejnego zdjęcia, bez niezmierzonej obietnicy szybszego dodawania (#545).
- „Ugotowałem” potwierdza zapis wykonania, bez obietnicy powiadomienia o własnym gotowaniu lub dla wymazanego autora. Ponowne wysłanie nadal zapisuje jedno wykonanie (#547).

## Alfa 0.28 — sprawdzone ekrany wejścia

- Łączenie konta z Facebookiem opisuje przycisk wejścia i możliwe potwierdzenie u dostawcy, bez obietnicy jednego kliknięcia (#542).
- Ekran braku adresu z Facebooka kieruje do pełnego formularza i nie obiecuje e-maila po każdym wykonaniu przepisu (#542).
- Automat dostępności obejmuje pięć stanów po powrocie z Google/Facebooka i zachowuje częściowy raport po błędzie pomiaru (#345).

## Alfa 0.27 — czytelność na małych ekranach

- Na wąskim, niskim ekranie i przy bardzo dużej czcionce obie belki nawigacji przewijają się ze stroną, aby nie zasłaniać formularzy ani zaznaczenia klawiatury (#434, #492).
- Na telefonie o szerokości 390 px powiadomienia z licznikiem i dostęp do konta mieszczą się w jednym rzędzie bez zmniejszania tekstu.
- Komunikat zbyt długiego imienia podaje rzeczywisty limit w rejestracji i ustawieniach profilu (#538).
- Usuwanie komentarza i odpowiedzi ma systemowy odstęp od zwykłych działań (#444).

- Fokus klawiatury na przyciskach w podpowiedziach pozostaje czytelny również przy najechaniu w ciemnym motywie (#539).

## Alfa 0.26 — czytelne przyciski w podpowiedziach

- Przyciski dodania kolejnego zdjęcia, zmiany kolejności zdjęć i dokończenia szkicu zachowują czytelny napis również wewnątrz podpowiedzi, w obu motywach (#534).
- Zwykłe linki w podpowiedziach zachowują kolor dobrany do ich tła.

## Alfa 0.25 — poprawny zapis i jasne komunikaty przepisów

- Składnik o nazwie do 240 znaków zapisuje się w całości przy tworzeniu i edycji przepisu (#526).
- Automatyczny zapis sprawdza długość i poprawność pól przed zmianą przepisu. Błędny tekst pozostaje w formularzu, a poprzednia zapisana wersja jest zachowana (#528).
- Po cofnięciu można wrócić do błędnego składnika lub kroku przygotowania i go poprawić.

- Potwierdzenie zapisu i opis udostępniania rozróżniają treści prywatne, dla obserwujących i publiczne (#530).

## Alfa 0.24 — pełny tekst po odrzuceniu formularza

- Odzyskiwanie po wygaśnięciu sesji lub przekroczeniu limitu zapytań mieści długie przepisy dopuszczone przez formularz. Ponowienie utworzenia i edycji zachowuje wszystkie kroki (#524).
- Podsumowanie walidacji mówi „Sprawdź formularz” również przy zbyt długiej lub nieprawidłowej wartości. Instrukcje rejestracji nie nazywają każdego błędu brakiem danych (#527).
- Zdjęcia nadal trzeba wybrać ponownie; ograniczenia rozmiaru odzyskiwania i ochrona danych wrażliwych pozostają.

## Alfa 0.23 — uczciwe informacje o odzyskiwaniu formularza

- Ekran limitu zapytań rozróżnia pełne i częściowe odzyskanie tekstu; pomoc zdjęcia nie obiecuje zachowania brakujących pól (#523).
- Ekrany wygaśniętej sesji i limitu informują, gdy zbyt duży formularz uniemożliwił odzyskanie tekstu. Nie uznają tego za pusty formularz.
- Publiczny formularz zgłoszenia treści po odmowie CSRF nie nakazuje zakładania konta ani ponownego logowania.

## Alfa 0.22 — polecane tagi w wyszukiwaniu

- Przed wpisaniem zapytania wyszukiwarka pokazuje kafle rzeczywistych polecanych tagów, z ich opisami i odnośnikami.
- Zarówno przy pełnej, jak i pustej liście można przejść do wszystkich tagów; pozostaje też droga do aktualności.

## Alfa 0.21 — precyzyjne komunikaty

- Instrukcje logowania przez Google i Facebooka opisują sposób wejścia bez obietnicy liczby kliknięć.
- Powiadomienie o pierwszym wpisie zachęca do odpowiedzi bez nieudokumentowanego twierdzenia o zachowaniu nowych osób.
- Podsumowanie automatu podaje rzeczywisty okres oznaczeń i nie obiecuje aktualnej widoczności treści po działaniach moderatorów.

## Alfa 0.20 — zainteresowania i powiadomienia

- Wybór zainteresowań ma większe kafle rzeczywistych tematów; wszystkie trzy kroki pozostają opcjonalne.
- Nagłówek zainteresowań jest spójny z pozostałymi stronami, a instrukcja mieści się także przy dużym powiększeniu tekstu.
- Instrukcja nie obiecuje wypełnienia strony głównej przy braku treści.
- Zwykłe powiadomienia ustawiają akcję obok treści na szerokim ekranie; pełne decyzje moderacyjne zachowują dotychczasową strukturę.
- Powiadomienia bez dostępnego autora mają pełne zdanie zamiast brakującej nazwy.

## Alfa 0.19 — zeszyty zgodne z wizualizacją

- Zeszyty mają ciemne karty, a ostatnie zapisy znajdują się pod nimi w głównej części strony.
- Przepisy w zeszycie mają większe zdjęcia nad pełnymi tytułami i układają się w siatkę dopasowaną do dostępnego miejsca.
- Klawiatura zaznacza krótkie „Zobacz przepis”, dzięki czemu długi tytuł nie wypycha fokusu pod nawigację.
- Instrukcja pustego zeszytu wyjaśnia zapisywanie bez obietnicy stałego dostępu do każdej treści.

## Alfa 0.18 — kolejne ekrany zgodne z wizualizacją

- Logowanie i rejestracja mają osobną kartę formularza obok zaproszenia.
- Na szerokim ekranie tytuł, opis i dane przepisu stoją obok zdjęcia; wszystkie akcje są pod nimi.
- Profil ma większy awatar oraz jeden zestaw czytelnych statystyk pod ciemnym nagłówkiem.
- Strona publiczna wyjaśnia zeszyty, widoczność i pobieranie własnych treści w trzech kartach.
- Przy jednoczesnym dużym powiększeniu pisma i tekstu aplikacji wąski nagłówek mieści znak garnka bez poziomego przewijania.
- Obrys zaznaczonej klawiaturą zakładki mieści się w jej ramie także przy powiększeniu strony.

## Alfa 0.17 — publiczna strona zgodna z wizualizacją

- Trzy otwarte, numerowane kroki z odnośnikami zastępują białe kafle „Jak działa”.
- Duży blok „Twój przepis. Czyjś dobry obiad.” stoi bezpośrednio pod krokami, z publicznym zdjęciem i podpisem autora.
- Tablica osób, dania i najnowsze wpisy pozostają dostępne niżej.

## Alfa 0.16 — spójne karty osób

- Karty proponowanych osób na Odkrywaj i w wyszukiwaniu mają te same proporcje co na Start.

## Alfa 0.15 — kompozycja strony głównej zgodna z wizualizacją

- Krótkie powitanie, odrębny nagłówek aktualności i duży tytuł w ciemnym kaflu dodawania z pierścieniem.
- Na komputerze Start / Odkrywaj / Mój zeszyt oraz osobne Szukaj. Mobilne pięć pozycji bez zmian.
- Ciemny wstęp „Co dobrego u innych?” i osobne karty osób oraz dań; zachowane rzeczywiste propozycje, notatki, podglądy i obserwowanie.
- Nowy wygląd obejmuje także zalogowane wejście przez `/`, wyszukiwanie i stronę publicznych wpisów. Pomoc wyjaśnia kolejność strumienia.

## Alfa 0.14 — czytelne wiadomości i dokładniejsze instrukcje

- Powiększyliśmy drobne teksty w e-mailach oraz linki w tygodniowym podsumowaniu.
- Wiadomość o pobraniu danych podaje także godzinę wygaśnięcia linku.
- Instrukcje logowania opisują dodatkowe potwierdzenie na stronie. Ustawienia adresu e-mail nie sugerują już wysyłania hasła pocztą.
- Instrukcje zabezpieczenia konta i edycji przepisu dokładniej opisują wymagane kroki.
- Przy edycji opublikowanego przepisu komunikaty mówią o zapisanych zmianach, a nie o szkicu.
- Pobrana paczka danych obsługuje ciemny wygląd systemu. Ostrzeżenie o przygotowywanych zdjęciach nie obiecuje już terminu ich gotowości.

## Alfa 0.13 — aktualizacja zainstalowanej aplikacji

Poprawiliśmy pobieranie aktualizacji w aplikacji zapisanej na telefonie, aby nowy ekran braku połączenia docierał także do osób korzystających ze starszej wersji. Aktualizacja nie przeładowuje otwartego formularza.

## Alfa 0.12 — spójny wygląd także poza głównymi ekranami

- Ekrany awarii i braku internetu oraz pobrane dane mają nową oprawę i czytelny krój pisma.
- Pozostałe wiadomości systemowe mają spójny wygląd. Długie adresy nie rozpychają wiadomości na telefonie.
- Zainstalowana aplikacja odświeża ikony po zmianie marki i zachowuje dostępny ekran bez internetu.
- Komunikaty awarii podają, co zrobić, bez niepotwierdzonych zapewnień o stanie danych lub terminie powrotu.
- Sprawdzenie antyspamowe mieści się na wąskim telefonie i korzysta z wybranego jasnego lub ciemnego wyglądu.
- Podpowiedzi składników i przygotowania mają poprawne nowe linie. Wskaźnik kroków przepisu mieści się także przy dużym tekście na wąskim ekranie.

## Alfa 0.11 — klawiatura i duży tekst

Karty dań można nadal otwierać po kliknięciu zdjęcia lub opisu. Przy poruszaniu się klawiaturą fokus obejmuje czytelną nazwę, dzięki czemu wysoka karta nie chowa go pod nawigacją.

## Alfa 0.10 — spójne podstrony i wiadomości

- Nagłówek każdego profilu ma tę samą grafitową oprawę. Tytuły przepisów korzystają z nowej typografii.
- Opisy w ustawieniach i przy wyborach formularza są czytelniejsze.
- Wiadomości e-mail otrzymały nową paletę, prosty krój pisma i spójne przyciski.
- Instrukcje logowania i pomocy są krótsze i precyzyjniejsze.

## Alfa 0.9 — pełny układ nowej marki

Pływająca nawigacja, ciemny blok publikacji, nowe karty i typografia. Spójny wygląd profilu, zeszytów, wyszukiwarki, przepisów i formularzy. Funkcje korzystają z dotychczasowych danych i ustawień konta.

Ten plik jest dla **ludzi**, nie dla programistów. Piszemy tu, co widać
na ekranie — nie jak się nazywa klasa, którą przy okazji przeniesiono.

Każde podbicie numeru wersji (`config/kuking.php`, klucz `kuking.wersja.etykieta`)
ma tu swój wpis. Jedno pilnuje drugiego: wersja bez wpisu jest numerem bez
treści, a wpis bez wersji nie da się z niczym powiązać.

Numer rośnie przy każdej zmianie, którą **człowiek zobaczy**: nowy ekran,
zmieniony układ, nowa funkcja, inne zachowanie formularza. Poprawki bez śladu
w interfejsie — testy, refaktor, dokumentacja — numeru nie ruszają, więc i tu
ich nie ma.

---

## Alfa 0.8 — 12 września 2026

### Wygląd i strona główna

- Jasne neutralne tło, białe karty i grafitowe pismo. Czerwone akcenty mają
  osobne odcienie do jasnego i ciemnego motywu.
- Powitanie na stronie głównej dostało wyraźniejszą oprawę.
- Podpis przy dodawaniu zdjęcia ma teraz 18 px zamiast 16 px przy domyślnym
  rozmiarze tekstu. Przy dużej czcionce opis nadal przechodzi do własnego wiersza.

### Formularze i zeszyt

- Błędy formularzy mówią, co poprawić, i prowadzą do odpowiedniego pola.
- Nieprawidłowy wybór zeszytu daje komunikat z prośbą o odświeżenie strony
  i ponowny wybór, zamiast błędu serwera.

### Wpisy

- Szkic ma podpis „Szkic — jeszcze nieopublikowany” zamiast pustego odnośnika daty.

---

## Alfa 0.7 — 12 września 2026

### Zdjęcia

- **Po dodaniu zdjęcia widać zdjęcie, a nie napis o nim.** Do dziś po
  opublikowaniu wpisu każdy — zawsze, nie od czasu do czasu — dostawał zdanie
  „Twoje zdjęcie się jeszcze przygotowuje". Przyczyna nie leżała w obciążeniu,
  tylko w kolejności: wgranie zdjęcia i publikacja wpisu to jedno żądanie, więc
  w chwili rysowania strony nie było jeszcze ani jednej gotowej wersji zdjęcia.
  Teraz jedna powstaje od razu. Waży **61,5 kB zamiast 6,14 MB** oryginału
  i zostaje potem w serwisie, więc telefon pobiera 66,8 kB tam, gdzie wcześniej
  178,6 kB.
- **Zdjęcie widać już w trakcie wysyłania**, jeszcze zanim dojedzie na serwer —
  prosto z pamięci telefonu. Wcześniej pokazywało się jako znaczek zajmujący
  **niecałą połowę** szerokości; teraz bierze całą.
- **Wpis z kilkoma zdjęciami, z których część się jeszcze przygotowuje, nie
  rozjeżdża się już w bok.** Blok z komunikatem brał pół szerokości karty
  i pięć wierszy — teraz całą i dwa.

### Karta wpisu i strumień

- **Menu przy wpisie to trzy kropki, bez podpisu.** Tak samo jak w miejscach,
  które nasi ludzie znają od lat. Sam przycisk zszedł ze **125 px na 48 px**,
  a główka karty na wąskim telefonie z osiemnastu wierszy na osiem.
- **Data przy wpisie z tego roku nie powtarza roku** — „12 września, 10:04"
  zamiast „12 września 2026, 10:04". Przy starszych wpisach rok zostaje, bo
  bez niego data przestaje być prawdą.
- **Wpis, który jest tylko wskazaniem przepisu, prowadzi wprost do przepisu.**
  Wcześniej otwierał pustą stronę — bez zdjęcia i bez przepisu — z której
  trzeba było kliknąć jeszcze raz. Przy okazji: taka strona pokazywała
  „publicznie" także pod przepisem widocznym wyłącznie dla obserwujących.
- **Zakładka „Świeżo z kuKING" odzyskała zjedzoną spację**, a awatary przy
  wpisach przestały być ściskane w owal.

### Komentarze

- **„Odpowiedz" i „Popraw" stoją obok siebie**, a nie jedno pod drugim — blok
  akcji zszedł ze 196 px na 138 px. Przy najwęższych telefonach nadal się
  zawijają, bo naprawdę się nie mieszczą.
- **Zniknęła pustka nad „Napisz komentarz"**, a między podpisem pola a samym
  polem pojawił się odstęp, którego tam nie było wcale.

### Profil i ustawienia

- **`@nazwa` stoi obok imienia**, a nie w osobnym wierszu. Rząd przycisków
  profilu wjechał dzięki temu **nad zgięcie ekranu** — na telefonie pierwszy
  raz widać go bez przewijania.
- **„Ustawienia" prowadzą na stronę o nazwie „Ustawienia".** Do dziś ten napis
  otwierał ekran zatytułowany „Czytelność". Nowa strona jest spisem wszystkich
  dziewięciu ekranów ustawień.
- **Menu konta przy awatarze** — „Mój profil", „Ustawienia", „Wyloguj się" —
  i „Powiadomienia" w pasku górnym na telefonie. Wcześniej własny profil był
  jedyną drogą do obsługi konta z telefonu.

### Strona główna

- **Powitanie przestało zmyślać porę dnia.** „Dobry wieczór" witało od 15:00,
  a godzinę serwis liczył w strefie serwera — latem o 11:50 uważał, że jest
  9:50, a po 23:00 mówił „Dzień dobry". Teraz wita „Witaj" i pyta, co dziś
  gotujesz; to jest prawdą o każdej porze i w każdym kraju.

### Pod spodem (bez zmian na ekranie, ale warto wiedzieć)

- Komenda pokazująca, do kogo nie doszedł list z serwisu — bez pokazywania
  żetonu z takiego listu.
- Dziennik decyzji urósł o dziesięć wpisów (D-172 … D-181), w tym o trzy
  REGUŁY, a nie pojedyncze poprawki: o tym, że czerwień w testach nie musi
  pochodzić ze zmiany, którą właśnie oglądasz; o tym, że reguła CSS oparta na
  „pierwszym elemencie" nie trafia w żadne widoczne pole formularza; i o tym,
  że skrócenie listy kolumn w zapytaniu potrafi po cichu zgasić zdjęcie.

---

## Alfa 0.6 — 11 września 2026

### Dla wszystkich

- **Nazwa serwisu wygląda wszędzie tak samo: kuKING, dwukolorowo.** Do tej pory
  w zdaniach pisaliśmy „Kuking", a dwukolorowy zapis trzymaliśmy na jedno
  miejsce na ekranie. Teraz nazwa jest pisana tak samo w nagłówkach, w tekście
  i w stopce — bo to nazwa mieszkańca tego serwisu, nie żart, który trzeba
  racjonować. Nazwa zostaje zwykłym „Kuking" tam, gdzie kolory nie działają
  (tytuł okna, temat listu, opis zdjęcia) oraz w komunikatach o błędach,
  w sprawach moderacyjnych i w dokumentach — tam nie ma miejsca na charakter.
- **Przycisk na stronie powitalnej mówi teraz, co obiecujemy:** „Zostań
  kuKINGiem — bez opłat i bez reklam". Zamiast „to darmowe", które brzmiało
  jak sprzedaż.
- **Podpowiedź pod komentarzem mówi, ILE wystarczy, a nie JAK pisać:**
  „Choćby jedno zdanie. Pytanie do autora też jest w porządku."
- **Mniej tłumaczenia się w tekstach.** Na stronie „Napisz do nas" cztery różne
  miejsca zapewniały, że odpisuje człowiek, a nie automat — zostało jedno, to,
  za którym stoi konkret. Powtarzane cztery razy budziło dokładnie to
  podejrzenie, które miało uspokoić.
- **Ekran „Dopisz szczegóły" przestał obiecywać, że nie trzeba przewijać.**
  Stało tam „nie musisz nic przewijać ani szukać", a zmierzona wysokość tej
  strony to od **10 249 px** (sam tytuł i puste wiersze) do **16 586 px**
  (osiem składników i sześć kroków) — czyli od 16 do 26 ekranów telefonu. Cała
  informacja została: wszystko jest na jednej stronie, nic nie jest
  obowiązkowe, wypełnij tyle, ile chcesz, a poprawnie wpisane dane nie zginą.
- **„Świeżo z Kuking" nie ma już pustej prawej kolumny.** U zalogowanych trzecia
  kolumna była zarezerwowana i puściusieńka — **656 px pustki** przy szerokim
  oknie, **452 px** przy węższym — a gość dostawał całą stronę zwiniętą do
  wąskiej szpalty. Teraz stoi tam tablica „kuKINGi na dziś", ta sama, która na
  tym ekranie już była, tylko niżej. Strona skróciła się z 10 557 do 8 898 px,
  a kolumna z tekstem ma tyle samo miejsca co przedtem.
- **Odstępy na stronie przepisu.** Pięć par bloków tekstu stało dosłownie na
  zero pikseli — tytuł kleił się do wiersza z autorem, nagłówek „Składniki" do
  listy, „Skąd ten przepis" do pierwszego akapitu. Teraz każda para ma odstęp,
  ten sam na telefonie i na komputerze, i rośnie razem z tekstem, gdy ktoś
  powiększy czcionkę w przeglądarce.
- **Źródło przepisu pokazuje się dokładnie tak, jak je wpisałeś.** Widok
  doklejał z przodu „Po", więc wpisane „Nasze smaki" wychodziło jako „Po Nasze
  smaki.", a „po mamie" jako „Po po mamie.". Samo pytanie w formularzu też się
  zmieniło — pyta teraz „od kogo albo skąd", bo o to właśnie chodzi.
- **Regulamin i polityka prywatności mówią o usłudze, a nie o sobie.** Zniknęły
  zdania o tym, jak dokument był pisany i co sobie o nim myślimy. **Wszystkie
  niewygodne fakty zostały** — również te o braku podpisanych umów powierzenia,
  braku inspektora ochrony danych i nieustalonym okresie życia danych
  w kopiach zapasowych. Poprawił się przy tym błąd merytoryczny: §8 mówił
  „przez pierwsze 24 godziny", a termin liczy się od pierwotnej decyzji.
- **W logotypie kolor marki został tylko na „King".** „.pl" jest ciemne, tak jak
  „ku" — jeden akcent w znaku zamiast dwóch.
- **Awatar bez zdjęcia przestał się zwijać do rozmiaru litery.** Konto, które nie
  dodało zdjęcia profilowego, pokazuje inicjał w kółku o właściwej wielkości.
- **Wyłączony przycisk w karuzeli nie drga przy naciśnięciu**, a główne pole
  wyszukiwania ma tę samą wysokość co pozostałe pola w serwisie.

### Pod spodem (bez zmian na ekranie, ale warto wiedzieć)

- **Komendy konsolowe odmieniają rzeczownik przez liczbę.** „Dopisano 1 wpisów"
  zniknęło z czterech komend. Reguła odmiany była w projekcie od dawna — po
  prostu nie była tam użyta.

---

## Alfa 0.5 — 11 września 2026

### Dla wszystkich

- **Prośba o link do zalogowania nie wpuszcza już na cudze konto.** Jeśli ktoś
  założył konto na Twój adres, a ten adres nigdy nie został u nas
  potwierdzony, formularz „Wyślij mi link do zalogowania" przysyła
  teraz wiadomość z **ustawieniem nowego hasła**, a nie przycisk wchodzący
  prosto na to konto. Po ustawieniu hasła stare hasło przestaje działać, a
  wszystkie otwarte sesje na tym koncie zostają zamknięte — nawet jeśli ktoś
  obcy z nich korzystał.
- **Kliknięcie odnośnika z tej wiadomości potwierdza adres.** Od tej chwili
  konto wraca do zwykłego logowania jednym przyciskiem.
- Ekran po wysłaniu formularza wygląda **dokładnie tak samo** jak dotąd i
  dokładnie tak samo dla adresu, który konta u nas nie ma — żeby nie dało się
  z niego wyczytać, kto ma tu konto, a kto nie.

---

## Alfa 0.4 — 11 września 2026

### Dla wszystkich

- **Dodany przepis jest wreszcie widoczny tam, gdzie ludzie patrzą.** Do tej
  pory opublikowany przepis stał wyłącznie na profilu autora i w wyszukiwarce
  — czyli tam, gdzie trzeba go było już szukać. Teraz pokazuje się w „Świeżo
  z Kuking", w feedzie osób, które autora obserwują, i na tablicy „kuKINGi na
  dziś", z tytułem, zdjęciem i przyciskiem „Ugotowałem" od razu pod ręką.
- **To nie jest kopia przepisu, tylko droga do niego.** Poprawiony tytuł albo
  wymienione zdjęcie widać w strumieniu natychmiast, bez czekania i bez
  drugiego kliknięcia.
- **Przepis schowany, usunięty albo zawężony do obserwujących znika ze
  strumieni razem z przepisem** — nie zostaje po nim żadna karta.
- **Dwa kliknięcia „Opublikuj" dają jedną pozycję w strumieniu, nie dwie.**

---

## Alfa 0.3 — 11 września 2026

### Dodawanie przepisu przestało odstraszać

- **Ekran dodawania przepisu pyta o sześć rzeczy zamiast prawie stu.** Zdjęcie,
  tytuł, składniki, przygotowanie, kto to zobaczy, „Opublikuj". Porcje, czasy,
  trudność, pochodzenie przepisu i skan starej kartki przeniosły się na osobny
  ekran „Dopisz szczegóły" — wypełniasz je **po** opublikowaniu albo wcale.
- **Składniki i przygotowanie wpisuje się zwykłym tekstem.** Można wkleić listę
  z kartki albo z maila — każdy wiersz stanie się składnikiem, a pusta linia
  rozdzieli kroki. Nie trzeba już dodawać pól po jednym.
- **Przepis bez listy składników też da się opublikować.** Jeśli znasz danie
  z głowy i wolisz opisać je zdaniem, nic Cię nie zatrzyma. Składniki możesz
  dopisać później.
- **Zaproszenie „dopisz szczegóły" pojawia się tylko wtedy, gdy naprawdę jest
  co dopisać.** Przepis wypełniony do końca go nie dostaje.
- **Nad każdym formularzem dodawania widać obie drogi** — „Zdjęcie i kilka
  słów" oraz „Cały przepis". Wcześniej w większości miejsc w ogóle nie było
  widać, że istnieje ta druga.

### Więcej treści na ekranie, mniej przewijania

- **Strona przepisu ma drugą kolumnę.** „Ugotowałem", „Zapisuję", „Gotuję"
  i „Podziel się" stoją obok treści, a nie nad nią — strona zrobiła się
  o kilkaset pikseli krótsza, a „Ugotowałem" widać wyżej.
- **„Świeżo z Kuking" i „Co się dziś gotuje" układają się w dwie kolumny**
  tam, gdzie jest na nie miejsce. Lista skróciła się prawie o połowę.
- **Pola do wpisywania są większe** — jednowierszowe 64 px zamiast 56,
  wielowierszowe 176 px zamiast 128.

Przy powiększonej czcionce wszędzie wraca jedna kolumna. Nic się nie chowa.

### Dla moderatorów i administratorów

- **Panel bierze całą szerokość okna.** Tabela kont na szerokim monitorze
  (od około 1600 px) mieści się bez przewijania w bok. Na węższym ekranie
  tabela dalej się przewija — ale w swoim polu, nie całą stroną.
- **Puste kolejki mówią pełnym zdaniem**, zamiast jednej linijki tekstu.

### Dokumenty

- **Regulamin i polityka prywatności nie mówią już o sobie, że nie były
  sprawdzone przez prawnika.** Wszystkie zdania o tym, jak działa serwis,
  zostały bez zmian — zniknęła tylko uwaga o tym, kto tych dokumentów nie
  czytał.
---

## Alfa 0.2 — 11 września 2026

### Dla wszystkich

- **Długie wpisy nie zajmują już całego ekranu.** Wpis dłuższy niż osiem
  wierszy albo czterysta znaków pokazuje początek i odnośnik „Czytaj dalej",
  który prowadzi na stronę wpisu. Lista składników liczy się po wierszach,
  a nie po znakach — bo to wiersze zjadają ekran.
- **Przycisk „Zostań kuKINGiem" nie rozpada się już na telefonie.** Wcześniej
  napis łamał się w środku wyrazów („Zost / ań kuKINGi / em"); teraz mieści
  się w dwóch wierszach łamanych na spacjach.
- **Gość widzi stronę w pełnej szerokości**, z prawą szyną, tak samo jak
  osoba zalogowana. Wcześniej strona zwężała się bez powodu.
- **Logotyp:** człon „King" wrócił do koloru marki.
- **Wejście kontem Google i Facebooka stoi nad formularzem**, a nie pod nim.
  Wcześniej widziała je tylko osoba, która i tak wpisała już hasło.
- **„Co się dziś gotuje" pokazuje więcej.** Wybór gospodarza jest teraz
  uzupełniany automatycznie do pełnej tablicy — wcześniej zaznaczenie choćby
  jednej pozycji w panelu wyłączało dobieranie i strona zostawała w połowie
  pusta.
- **Karty wpisów i sekcje stron przestały wyglądać identycznie.** Sześć
  różnych rzeczy — karta wpisu, formularz, sekcja strony, blok szyny, ramka
  z wyjaśnieniem, kafel do kliknięcia — miało do tej pory ten sam wygląd.
  Teraz widać, co jest treścią, co trzeba wypełnić, a co tylko wyjaśnia.

### W formularzach

- **Pola w jednym rzędzie stoją równo.** „Na ile porcji" wisiało wyżej niż
  „Przygotowanie" i „Gotowanie", bo ma krótszy podpis.
- **Rozmiar tekstu schodzi niżej niż dotąd** — doszły trzy mniejsze rozmiary
  dla osób, którym domyślny jest za duży.

### Dla moderatorów

- **Menu poza panelem pokazuje jedno wejście, nie dziewięć pozycji.**
  Przy wejściu stoi liczba rzeczy czekających we wszystkich kolejkach razem;
  rozbicie na kolejki jest w panelu.
- **Zdjęcia w kolażu na stronie powitalnej wybiera się w panelu**, spośród
  zdjęć z wpisów publicznych. Wcześniej były wpisane na sztywno.
- **Tablica dnia i karty wpisów mają równy rytm**, a rzadsze akcje schowały
  się do menu „Więcej".

### Pod spodem (bez zmian na ekranie, ale warto wiedzieć)

- Kopię bazy i próbę jej odtworzenia robi się dwoma komendami, a próba
  sprawdza **wynik**, nie kod wyjścia.
- Cofnięcie migracji, które skasowałoby dowód zgody z RODO art. 7, **odmawia**
  i mówi, co zrobić zamiast tego.

---

## Alfa 0.1 — pierwsze wydanie

Wersja, od której zaczęliśmy. Historia sprzed 11 września 2026 jest
w historii repozytorium — ten plik zakładamy dziś i nie odtwarzamy go wstecz,
bo wpisy pisane z pamięci po fakcie są gorsze niż ich brak.
