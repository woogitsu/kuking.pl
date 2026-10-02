# Co nowego w Kuking

Kuking rośnie krok po kroku. Tu piszemy, co się zmieniło — prostym językiem,
bez fachowych słów. Pełna, techniczna lista wszystkich zmian (także tych,
które widać tylko „pod maską”) jest w `CHANGELOG.md` w repozytorium.

Numer wydania (np. „Alfa 0.68.005”) widzicie w stopce każdej strony, razem
z datą i skrótem kodu — to przydaje się, gdy zgłaszacie nam usterkę.

## Spis wydań

- [Najnowsze zmiany](#najnowsze-zmiany)
- [Alfa 0.78 — co zużyć najpierw, lista zakupów i wydruk zeszytu](#alfa-078)
- [Alfa 0.77 — wspólny zeszyt dla rodziny i import przepisu w tle](#alfa-077)
- [Alfa 0.76 — planer z wyszukiwarką, „Co mam w domu” i zeszyt bez konta](#alfa-076)
- [Alfa 0.75 — szukanie w wykonaniach i pewniejsze „Ugotowałem”](#alfa-075)
- [Alfa 0.74 — zdjęcia w formularzu i porcje przy gotowaniu](#alfa-074)
- [Alfa 0.73 — import przepisu i wygodniejsze gotowanie](#alfa-073)
- [Alfa 0.72 — spokojniejsze zdjęcia i powiadomienia](#alfa-072)
- [Alfa 0.71 — przepis z kartki i koszt dania](#alfa-071)
- [Alfa 0.70 — spokojniejsze wpisy i gotowanie](#alfa-070)
- [Alfa 0.69 — czytelniejsze powiadomienia i wygodniejsze gotowanie](#alfa-069)
- [Alfa 0.68 — minutnik przy gotowaniu i formularze, które nie gubią wpisanego tekstu](#alfa-068)
- [Alfa 0.67 — tagi ze zdjęciami z Waszych kuchni](#alfa-067)
- [Alfa 0.66 — porządek w rozmowach i przygotowanie „Poradźcie”](#alfa-066)
- [Alfa 0.65 — odnośniki prowadzą tam, gdzie obiecują](#alfa-065)
- [Alfa 0.64 — wykonania i odpowiedzi liczone uczciwie](#alfa-064)
- [Alfa 0.63 — zobacz, co gotują inni pod tym tagiem](#alfa-063)
- [Alfa 0.62 — zdjęcia i osoby przy tagach](#alfa-062)

## Najnowsze zmiany

Tu pojawią się funkcje, które jeszcze nie mają numeru wydania.

### Ile sztuk wychodzi z przepisu

Przy pierogach, bułkach czy ciasteczkach liczy się nie liczba porcji, tylko
liczba sztuk. Autor może teraz dopisać do przepisu, ile gotowych sztuk
wychodzi z podanych ilości, na przykład „24 pierogi”. To osobna informacja:
liczba pierogów nie mówi, ile osób nakarmi przepis, więc porcje zostają
tak, jak były.

Na stronie przepisu, nad składnikami, wpisujecie, ile sztuk chcecie zrobić,
na przykład 36, i naciskacie „Przelicz składniki”. Ilości przeliczają się
jak przy porcjach: 24 na 36 to półtora raza więcej. Działa bez
JavaScriptu, a link z wybraną liczbą możecie wysłać rodzinie. Wybieracie
albo porcje, albo sztuki — nigdy oba naraz. Przepisy, w których autor
nie podał sztuk, wyglądają tak jak dotąd.

## Alfa 0.78

**Co zużyć najpierw, lista zakupów i wydruk zeszytu.**

### Co zużyć w pierwszej kolejności

Przy produktach na liście „Co mam w domu” możecie teraz wpisać termin
z opakowania: dzień, a do tego, czy to „Należy zużyć do”, czy „Najlepiej
spożyć przed”. Datę wybieracie z trzech list (dzień, miesiąc, rok) albo
jednym przyciskiem — „Za 3 dni”, „Za tydzień”, „Za 2 tygodnie”, „Za miesiąc”.
Możecie dopisać ilość własnymi słowami („pół kostki”) i zaznaczyć, że produkt
leży w zamrażarce. Wszystko to jest dobrowolne, a listę widzicie tylko Wy.

Lista układa się sama: na górze „Zużyj w pierwszej kolejności” — produkty,
których termin minął albo upływa w ciągu 3 dni, od najwcześniejszego; niżej
„Później”, „Bez terminu” i „Mrożone”. Pod spodem stoi jedno zdanie, które
mówi, dlaczego kolejność jest taka: ustawia ją tylko data, którą sami
wpisaliście. Termin to Wasza notatka z opakowania — nie oceniamy, czy produkt
nadaje się do jedzenia, więc przed użyciem zawsze go sprawdźcie.

Na stronie „Co ugotuję z tego, co mam?” jest przycisk „Najpierw to, co się
psuje”: na górze przepisy, w których jest najwięcej Waszych produktów
z krótkim terminem, a przy każdym zdanie „Zużyjesz: …”. Na Starcie, gdy są
takie produkty, pojawia się jedno krótkie zdanie z przyciskiem „Zobacz, co
ugotować”. Kto chce, może też zaznaczyć na stronie „Co mam w domu” pole
„Chcę dostawać w sobotę e-mail o produktach do zużycia” — jest wyłączone,
dopóki go nie zaznaczycie. Taki list przychodzi najwyżej raz w tygodniu, tylko
wtedy, gdy jest co na nim wymienić, a wypisać się z niego można jednym
kliknięciem na dole listu, bez logowania.

### Kartka z przepisem dla pomocnika

Obok przycisku „Drukuj przepis” jest nowy: „Drukuj dla pomocnika”. To krótsza
kartka na blat dla osoby, która gotuje razem z Wami: bez opisu i bez rodzinnej
historii przepisu, za to z liczbą porcji, składnikami i krokami dużym drukiem.
Jeśli wcześniej wybierzecie „Na ile porcji?”, na kartce będą już przeliczone
ilości. Przyciskiem „Dodaj kod QR do kartki” możecie dołożyć kod, którym
pomocnik otworzy ten przepis w telefonie — kod jest tylko dla przepisów
publicznych, a przy prywatnym go nie ma.

### Wydrukuj cały zeszyt jako książkę

Na stronie zeszytu jest przycisk „Wydrukuj zeszyt”. Otwiera stronę, którą
drukujecie zwykłym Ctrl+P albo zapisujecie jako PDF: okładka z nazwą zeszytu,
spis treści i każdy przepis na osobnej kartce — ze składnikami, krokami,
podpisem autora i notatką z zeszytu, jeśli ją macie. Są tam tylko te przepisy,
które widzicie Wy, a przy każdym może być jedno małe zdjęcie; przyciskiem
„Bez zdjęć” wydrukujecie samo pismo. Kto stracił dostęp do wspólnego zeszytu,
nie wydrukuje go już ani nie otworzy.

### Karta z kodem QR do rozdawania

Przy publicznym przepisie, w części „Podziel się”, oraz na publicznym profilu
jest przycisk „Wydrukuj kartę z kodem”. To jedna kartka dużym drukiem:
tytuł przepisu (albo nazwa profilu), duży kod QR i ten sam adres zapisany
literami. Można ją rozdać na zajęciach w kole gospodyń czy uniwersytecie
trzeciego wieku albo wręczyć rodzinie. Osoba, która zeskanuje kod telefonem,
od razu zobaczy przepis — konto nie jest do tego potrzebne. W kodzie jest
tylko publiczny adres, nic, co dotyczy drukującej osoby. Karty nie ma dla
przepisów prywatnych, ukrytych i usuniętych.

### Wersja przepisu, z której gotowaliście

Kiedy zapisujecie „Ugotowałem”, pamiętamy, którą wersję przepisu mieliście
przed oczami. Autor mógł ją potem zmienić, ale na stronie swojego wykonania
klikacie „Zobacz wersję” i czytacie dokładnie to, z czego gotowaliście —
składniki i kroki z tamtego dnia. Widzicie to tylko Wy: nikt inny, także
autor przepisu, nie zobaczy, z której wersji gotowaliście. Jeśli tamtej
wersji już nie ma (stare wersje porządkujemy po dwóch latach, autor mógł ją
też ukryć), napiszemy o tym wprost, a Wasze wykonanie, notatka i zdjęcia
zostają bez zmian. Wykonania zapisane przed tą zmianą nie mają przypiętej
wersji.

### Ile naprawdę trwa gotowanie według gotujących

Na stronie przepisu, obok czasu podanego przez autora, może pojawić się
drugie zdanie: „Gotujący zwykle potrzebują około 60 min (na podstawie 7 osób).”
Bierzemy je z czasów, które sami wpisujecie w „Ugotowałem”. Zdanie pokazujemy
dopiero wtedy, gdy czas podało co najmniej pięć różnych osób — każda liczy się
raz, nawet jeśli gotowała ten przepis wiele razy — a liczbę zaokrąglamy do pięciu
minut. Czasów dłuższych niż doba nie liczymy. Nie widać, kto podał jaki czas,
a osoby, które zablokowaliście, nie wchodzą do Waszej liczby. Dopóki
osób jest mniej, nie piszemy nic.

### Kilka potraw naraz, każda z własnym minutnikiem

Gotujecie obiad z kilku dań? Na stronie przepisu i w trybie „Gotuję” jest
nowy przycisk „Dodaj do kolejki gotowania”. W kolejce zmieszczą się cztery
przepisy. Na ekranie kolejki przełączacie się między potrawami dużymi,
podpisanymi przyciskami, np. „Zupa — krok 2 z 5”, a każda potrawa pamięta
swój krok. Minutniki wszystkich potraw są na jednej liście z nazwą dania,
np. „Zupa, krok 2: 4:12”, i każdy można osobno anulować. Gdy czas minie,
usłyszycie ten sam dźwięk co zawsze i zobaczycie napis, np. „Zupa: czas
minął”. Kolejka jest zapamiętana tylko w Waszej przeglądarce — nie trafia
na konto — i znika sama po 24 godzinach albo po kliknięciu „Wyczyść kolejkę”.
Zamknięcie karty kończy działające minutniki, więc po powrocie ustawiacie je
od nowa. Bez włączonego JavaScriptu kolejki nie ma, a gotowanie jednego
przepisu działa jak dotąd.

### Zmiana nazwy w adresie profilu nie psuje starych linków

Kiedy zmienicie nazwę użytkownika w ustawieniach profilu, dawny adres
(na przykład z wydrukowanej karty z kodem QR albo z wiadomości sprzed
tygodnia) dalej działa: przenosi na Wasz profil pod nową nazwą. Tak samo
działają listy „Obserwujący” i „Obserwowani” oraz kanał profilu. Jeśli ktoś
inny zajmie Waszą dawną nazwę, adres prowadzi już do niego. Gdy usuniecie
konto, dawne nazwy znikają razem z nim.

### Wszystkie wersje regulaminu

Na górze regulaminu są dwa nowe przyciski. „Pobierz regulamin” zapisuje go
na Waszym komputerze albo telefonie jako zwykły plik tekstowy. „Wszystkie
wersje regulaminu” prowadzi do listy każdej wersji, jaką opublikowaliśmy,
z datą — każdą można przeczytać i pobrać. Przy zakładaniu konta zapisujemy
datę wersji, którą akceptujecie, więc zawsze da się sprawdzić, jak regulamin
wtedy brzmiał.

### Wszystkie wersje polityki prywatności

Polityka prywatności ma teraz takie same dwa przyciski jak regulamin:
„Pobierz politykę” i „Wszystkie wersje polityki”. Na liście są wersje
od 25 września 2026 — każdą można przeczytać i pobrać. Jeśli potrzebujecie
wcześniejszego brzmienia, napiszcie do nas, a wyślemy je.

### Alergeny w przepisie — przygotowane, na razie wyłączone

Przygotowaliśmy oznaczanie alergenów. Autor przepisu może w kroku ze
składnikami zaznaczyć, które z czternastu alergenów z unijnej listy są
w przepisie, i potwierdzić, że lista jest pełna. Słownik podpowiada, co warto
zaznaczyć, ale niczego nie zaznacza sam — decyduje autor. Pod składnikami
zawsze stoi jedno z trzech zdań: „Alergeny według autora: …” z listą,
„Autor nie zaznaczył żadnego z 14 alergenów” albo „Alergeny: nie sprawdzono”.
Brak zaznaczenia nigdy nie znaczy, że czegoś w przepisie
nie ma. W wyszukiwarce będzie pole „Bez wskazanych alergenów (według
autorów)”; pokaże tylko przepisy, w których autor potwierdził listę, a te
niesprawdzone pominie. To zaznaczenia autorów, nie badania — przy gotowych
produktach zawsze czytajcie etykiety. Nie zapamiętujemy, jakie alergeny
wybieracie. Funkcja zostaje na razie wyłączona: włączymy ją dopiero po
spotkaniach z osobami, które sprawdzą, czy wszystko jest zrozumiałe.

### „Nie licz mnie w statystykach”

W ustawieniach, w części „Prywatność”, jest nowy przycisk „Nie licz mnie
w statystykach”. Po jego kliknięciu nie zapisujemy już, kiedy ostatnio
zajrzeliście do Kuking ani co robicie w serwisie, a po zalogowaniu strony nie
mają skryptu statystyki odwiedzin. Wszystko inne działa tak samo — zniknie
tylko podpowiedź o dodaniu Kuking do ekranu telefonu, bo liczy ona powroty
z tej samej daty. Jeśli zmienicie zdanie, przycisk „Licz mnie znowu” to cofa.

### „Jak wyszło?” po trybie gotowania

Jeśli w trybie gotowania dojdziecie do ostatniego kroku, a nie zapiszecie
„Ugotowałem”, na stronie Start pojawi się jedno zdanie: nazwa przepisu
i „jak wyszło?”. Przycisk „Pokaż zdjęcie” otwiera zwykły formularz
„Ugotowałem”, a „Nie teraz” chowa pytanie. Pytanie znika też samo po kilku
dniach albo wtedy, gdy zapiszecie wykonanie, i dla tego samego gotowania już
nie wraca. Widzicie je tylko Wy — nie przychodzi mailem ani powiadomieniem.

### „Dziękuję” pod komentarzem

Pod cudzym komentarzem, który stoi pod Waszym wpisem, przepisem albo
„Ugotowałem”, jest przycisk „Dziękuję”. Jedno dotknięcie wystarczy — nie trzeba
pisać odpowiedzi. Osoba, która napisała komentarz, dostaje w powiadomieniach
krótką wiadomość z odnośnikiem do tego komentarza. Pod komentarzem zostaje
napis „Podziękowano za ten komentarz.”, który widzicie tylko Wy i autor
komentarza. Nie ma licznika ani rankingu podziękowań. Podziękowania nie można
cofnąć, a drugie dotknięcie nic nie dubluje. Jeśli chcecie odpowiedzieć
własnymi słowami, „Odpowiedz” działa jak dotąd.

### Napis „Autor przepisu” w rozmowie

Gdy autor przepisu odpowiada pod swoim przepisem albo pod czyimś wykonaniem
tego przepisu, obok imienia widać napis „Autor przepisu” — albo „Autorka
przepisu”, jeśli ta osoba wybrała formę żeńską w pytaniu „Jak mamy do Ciebie
pisać?”. Od razu wiadomo, że odpowiedź przyszła od osoby, od której przepis
pochodzi. Przy „Mojej wersji” napis dostaje autor tej wersji.

### Ściągawka do wydruku

Ktoś pomógł Wam założyć konto i zaraz wyjeżdża? W Ustawieniach i na ekranie
„Wszystko gotowe” jest przycisk „Wydrukuj ściągawkę”. To jedna kartka dużym
drukiem: adres strony, Wasza nazwa użytkownika, częściowo zasłonięty adres
e-mail, jak wejść bez hasła i jak w trzech krokach dodać zdjęcie obiadu.
Hasła ani żadnego kodu na kartce nie ma, więc może leżeć na widoku.

### Wspomnienia z Waszych „Ugotowałem”

Na stronie głównej, obok dawnych wpisów, wracają teraz także Wasze własne
„Ugotowałem” z tego samego dnia sprzed roku albo kilku lat — z przyciskiem
„Ugotuj znowu”. Widzicie je tylko Wy. Pojedyncze wspomnienie schowacie
przyciskiem „Nie pokazuj mi tego więcej”, a wszystkie naraz wyłączycie
w Ustawieniach → Prywatność.

### Ugotujmy razem — jeden przepis na cały tydzień

Na stronie „Ugotujmy razem” jest przepis tygodnia, który wybrał gospodarz.
Gotuje, kto chce — nie trzeba się nigdzie zapisywać. Przycisk „Ugotuję w tym
tygodniu” otwiera przepis w trybie gotowania, a po ugotowaniu wystarczy dodać
zdjęcie i kilka słów przez „Ugotowałem”. Wtedy Wasze wykonanie pojawi się na tej
stronie obok innych z tego tygodnia, od najnowszego. Tydzień trwa od
poniedziałku do niedzieli. Niżej są poprzednie tygodnie razem ze zdjęciami
z tamtych dni. Odnośnik do tej strony jest na „Świeżo z Kuking”.
### Nowe wpisy w czytniku kanałów

Jeśli korzystacie z czytnika kanałów (na przykład Feedly albo Inoreader), możecie
w nim śledzić czyjś profil, tag albo publiczny zeszyt bez zaglądania na stronę.
Wystarczy wkleić do czytnika adres profilu, tagu lub zeszytu — czytnik sam znajdzie
kanał. Pokażemy w nim najwyżej 30 najnowszych wpisów i przepisów, dokładnie tych,
które widzi każdy bez logowania: bez wpisów tylko dla obserwujących, bez prywatnych
i bez notatek z zeszytu. Prywatny zeszyt nie ma kanału.

### Przycisk „Zgłoś” widać także bez logowania

Przy przepisie, wpisie i komentarzu widać teraz odnośnik „Zgłoś (po zalogowaniu)”
także wtedy, gdy nie jesteście zalogowani. Zgłoszenie spamu, nękania albo
niebezpiecznej porady nadal wymaga konta — poprosimy o zalogowanie i od razu
otworzymy formularz. Treść niezgodną z prawem możecie zgłosić bez konta:
przy przepisie i wpisie odnośnik do tego formularza stoi obok. Strony „Pomoc”
i „Napisz do nas” opisują to samo.

### Lista zakupów

Jest nowy ekran „Lista zakupów” — prywatna lista, którą widzicie tylko Wy. Znajdziecie
ją w „Moje” i w planerze tygodnia. Pozycję dopisujecie ręcznie, odhaczacie ją, gdy
jest w koszyku (i możecie to cofnąć), a przyciskiem „Wyczyść odhaczone” usuwacie
kupione rzeczy. Na stronie przepisu jest przycisk „Dodaj składniki do listy
zakupów”, a w planerze „Dodaj składniki” przy przepisie: składniki trafiają na
listę dokładnie tak, jak napisał je autor, jedna linia to jedna pozycja. Niczego
nie sumujemy i nie łączymy, więc „2 jajka” i „3 jajka” zostają dwiema pozycjami —
liczycie to Wy. Każda pozycja mówi, skąd jest: „Z przepisu” z jego tytułem albo
„Dopisane ręcznie”. Gdy dodacie składniki tego samego przepisu drugi raz, najpierw
zapytamy, czy na pewno. Jeśli autor ukryje albo usunie przepis, pozycje zostają na
Waszej liście jako zwykły tekst, tylko bez tytułu przepisu. Lista działa bez
żadnych ozdobników: zwykłe przyciski, duże litery, żadnego przeciągania. Nie ma
jeszcze listy wspólnej dla domowników ani pracy bez internetu — to na później.

### Jak mamy do Was pisać?

W ustawieniach profilu, a także na ostatnim kroku po założeniu konta, jest nowe
pytanie „Jak mamy do Ciebie pisać?”. Możecie wybrać formę żeńską, męską albo
neutralną. Neutralna jest zaznaczona od początku i tak piszemy do wszystkich,
dopóki ktoś nie wybierze inaczej — możecie też pominąć to pytanie i nic się nie
zmieni. Nie zgadujemy niczego z imienia ani z konta Google czy Facebooka.
Wybraną formę zobaczą też inni, bo tak będziemy o Was pisać, na przykład
„Ania ugotowała Twój przepis”. Przy formie żeńskiej przycisk na końcu gotowania
nazywa się „Ugotowałam”. Ta sama forma pojawia się teraz także na przycisku
„Ugotowałem” pod przepisem i na kartach wpisów, w powitaniu w powiadomieniach
i w powiadomieniu o tym, że ktoś ugotował z Waszego przepisu, a także w stopce
listów o logowaniu, haśle i zmianie adresu e-mail. Bez wyboru teksty zostają
bez rodzaju, a stopka tych listów brzmi „pokaż, co dziś gotujesz”. Na razie
zmienia to tylko część miejsc w serwisie, kolejne dołączymy po kolei. Wybór zmienicie w każdej chwili, a kiedy usuniecie
konto, zniknie razem z nim.

### Ile masz czasu? Wybierz w wyszukiwarce przepisów

Pod zakresami wyszukiwania jest nowy wiersz „Ile masz czasu?”. Możecie wybrać
„Do 15 minut”, „Do 30 minut” albo „Do godziny” i zobaczyć tylko przepisy, które
się w tym zmieszczą. Liczymy przygotowanie i gotowanie razem. Przepis, przy
którym autor nie podał czasu, nie trafia do żadnego z tych progów — nie
udajemy, że gotuje się w zero minut. Wybrany czas jest zaznaczony, a wrócić do
wszystkich przepisów możecie jednym kliknięciem w „Bez limitu czasu”. Wybór
zostaje w adresie strony, więc działa po odświeżeniu i można go komuś wysłać.
Dawny przycisk „Do 30 minut” działa dalej, także w starych linkach.

### Wyślijcie komuś swój zeszyt

Na stronie zeszytu, który ma widoczność „wszyscy”, jest teraz przycisk
„Podziel się”. Rozwija listę: WhatsApp, e-mail i Facebook, a pod spodem
widoczny adres, który można zaznaczyć i skopiować (na telefonie jest też
przycisk „Skopiuj adres” i systemowe okno wysyłania). Osoba, która dostanie
adres, otworzy zeszyt bez zakładania konta i zobaczy tylko te przepisy, które
sama ma prawo zobaczyć. Dotyczy to także wspólnego zeszytu z bliskimi, jeśli
ma widoczność „wszyscy”. Zeszytu „Tylko ja” ani zeszytu „Zapisane” nie da się
w ten sposób wysłać — na stronie takiego zeszytu przeczytacie, co zmienić,
jeśli chcecie go udostępnić.

### Kalorie na porcję także dla wyszukiwarek

Jeśli przy przepisie widzicie „Szacunkowe wartości odżywcze (na porcję)”, tę
samą liczbę kalorii podajemy teraz także w danych, które czytają wyszukiwarki.
Robimy to tylko wtedy, gdy liczba naprawdę jest na stronie: autor nie ukrył
sekcji, znamy skład co najmniej 90% składników i autor podał liczbę porcji.
Gdy któregoś z tych warunków brakuje, wyszukiwarkom też nic nie podajemy.

### Zobaczcie, co autor zmienił w przepisie

Gdy przepis był poprawiany i ma co najmniej dwie zapisane wersje, pod nim
pojawia się przycisk „Historia zmian”. Znajdziecie tam listę wersji z datami,
możecie obejrzeć każdą z nich osobno i sprawdzić, co zmieniło się względem
poprzedniej. Zmiany są opisane słowami: „Dodano”, „Usunięto” albo „Zmieniono”,
a przy zmienionych składnikach i krokach widać, jak było i jak jest. To pomaga,
gdy wracacie do zapisanego albo wydrukowanego przepisu i chcecie wiedzieć, czy
zmieniły się proporcje lub sposób przygotowania. Historia pokazuje tekst i dane
przepisu, bez zdjęć, i widzi ją każdy, kto widzi sam przepis — nic ponadto. Jeśli
starsza wersja nie zapisała jakiegoś pola, piszemy o tym wprost, zamiast
zgadywać. Pamiętajcie, że zapisana wersja zachowuje treść z chwili zapisu, także
tę, którą autor usunął później — dlatego autor może ukryć pojedynczą wersję
(o tym niżej). Starych wersji nie trzymamy
w nieskończoność: wersja zapisana ponad 24 miesiące temu znika, ale trzy najnowsze
wersje przepisu zostają zawsze.

### Ukryjcie jedną wersję przepisu

Zdarza się, że w starszej wersji przepisu zostało coś, czego nie chcecie już
pokazywać — na przykład numer telefonu babci albo nazwisko sąsiadki. W „Historii
zmian” przy każdej starszej wersji Waszego przepisu jest teraz przycisk „Ukryj
wersję”. Najpierw pokażemy, co się stanie, a dopiero przycisk „Tak, ukryj”
ją chowa. Ukrytej wersji nie zobaczy nikt poza Wami i moderacją, a porównanie
zmian ją pominie i napisze, że coś pominęło. Wy dalej ją widzicie, z napisem
„Ukryta”, i w każdej chwili możecie ją przywrócić. Najnowszej wersji nie da się
ukryć, bo to jest to, co widać na stronie przepisu: żeby usunąć z niej tekst,
poprawcie przepis i zapiszcie zmiany — wtedy poprzednią wersję można już ukryć.

Wersję może też ukryć moderacja, gdy coś w niej narusza zasady. Wtedy dostaniecie
powiadomienie: której wersji to dotyczy, na jakiej podstawie i dlaczego. Jeśli
uważacie, że to pomyłka, możecie się odwołać — a gdy przyznamy Wam rację, wersja
od razu wróci do historii zmian.

### Zgłoście konkretną wersję przepisu

Starsza wersja przepisu może zawierać coś, czego nie powinno być w sieci — na
przykład cudzy numer telefonu — nawet jeśli sam przepis jest w porządku. Na
ekranie każdej starszej wersji w „Historii zmian” jest teraz przycisk „Zgłoś
wersję” z numerem wersji. Zgłaszacie dokładnie tę wersję, nie cały przepis.
Bez konta zobaczycie „Zgłoś wersję (po zalogowaniu)”, a treść niezgodną
z prawem możecie zgłosić także bez konta. Najnowszej wersji nie zgłaszacie
osobno, bo to jest sam przepis — wtedy zgłaszacie przepis. Moderacja może
wersję ukryć w całości: historia zmian jest niezmienna, więc nie wycinamy z niej
pojedynczych zdań. Autor dostaje powiadomienie z podstawą i uzasadnieniem
i może się odwołać, a Wy dostajecie odpowiedź tak samo jak przy każdym innym
zgłoszeniu.

### Zacznijcie gotować na telefonie, dokończcie na tablecie

W trybie „Gotuję” jest nowy, całkiem opcjonalny przycisk „Zapamiętuj postęp na
moim koncie” — widzą go tylko osoby zalogowane. Po jego kliknięciu odhaczone
kroki tego przepisu czekają na Waszym koncie, więc po otwarciu tego samego
przepisu na innym urządzeniu widzicie, dokąd doszliście. Jeśli niczego nie
włączycie, wszystko działa jak dotąd: postęp zostaje tylko w tej przeglądarce.
Zapamiętany postęp znika sam po 24 godzinach od ostatniej zmiany; możecie go też
wyczyścić („Zacznij od początku”) albo w każdej chwili wyłączyć i usunąć z konta.
Kiedy gotujecie na dwóch urządzeniach naraz, Kuking mówi, że postęp zmienił się
gdzie indziej, i pokazuje aktualny stan. Razem z krokami zapamiętujemy też
składniki zaznaczone jako przygotowane (zapisujecie je przyciskiem „Zapisz
zaznaczenie składników”) i wybraną liczbę porcji — tylko dla przepisów, dla
których włączyliście zapamiętywanie. Minutniki zostają w przeglądarce. Postęp jest
prywatny: widzicie go tylko Wy, trafia do paczki z Waszymi danymi i znika razem
z kontem.

### Wczytajcie z powrotem własną paczkę z danymi

W „Twoich danych” jest nowy odnośnik „Wczytaj swoją paczkę”. Wybieracie plik
ZIP, który wcześniej pobraliście z Kuking, a my najpierw pokazujemy, co w nim
jest: ile przepisów, własnych wpisów i zeszytów można wczytać, co macie już na
koncie, co powtarza się w samej paczce i czego wczytać się nie da — z powodem
napisanym po ludzku. Niczego nie zapisujemy, dopóki nie zaznaczycie pozycji
i nie klikniecie „Wczytaj zaznaczone”. Wszystko, co wczytamy, jest prywatne:
przepisy czekają w szkicach, wpisy widzicie tylko Wy, zeszyty są „Tylko ja” —
o publikacji zdecydujecie sami, później. Zdjęć z paczki na razie nie
przenosimy, pytań z Poradźcie też nie wczytujemy (pytanie jest zawsze publiczne,
a wczytane treści mają zostać prywatne — w podglądzie piszemy o tym wprost), a konta,
haseł, zgód i komentarzy innych osób nie odtwarzamy w ogóle.
Jedno kliknięcie wczytuje najwyżej 50 pozycji, a to samo wczytanie drugi raz
niczego nie podwoi. Wybrany plik czeka na Waszą decyzję dwie godziny, a jeśli go
porzucicie, kasujemy go sami w nocnym sprzątaniu.

## Alfa 0.77

**Wspólny zeszyt dla rodziny.**

### Wspólny zeszyt dla rodziny

Zeszyt z przepisami możecie teraz udostępnić bliskim — na przykład mężowi,
córce albo siostrze, z którą razem planujecie niedzielny obiad. Na ekranie
zeszytu wybierzcie „Zaproś do wspólnego zapisywania” i wpiszcie nazwę konta tej osoby albo
wyślijcie jej jednorazowy link. Zaproszona osoba może dopisywać i wyjmować
przepisy oraz wpisy, a przy każdej pozycji widać, kto ją dodał.

Zeszyt ma nadal jednego właściciela: tylko Wy zmieniacie jego nazwę,
widoczność i usuwacie go. Dostęp może mieć najwyżej pięć osób, a zeszytu
„Zapisane” nie da się udostępnić. Można w każdej chwili odebrać komuś dostęp,
a zaproszona osoba może sama odejść — to, co dopisała, zostaje w zeszycie.
Gdy jedna z osób zablokuje drugą albo usunie konto, wspólne zapisywanie
między nimi się kończy.

## Alfa 0.76

**Wyszukiwarka w Planerze, „Co mam w domu”, zapis przepisu po rejestracji, wydruk przepisu i zeszyt bez konta.**

### Szukajcie w zeszytach także po składniku

Pole „Szukaj w moich zeszytach” znajduje teraz zapisane przepisy nie tylko po
tytule, ale i po składniku — wpiszcie na przykład „cukinia”, a zobaczycie
przepisy z Waszych zeszytów, w których cukinia jest na liście składników. Gdy
tytuł nie zawiera wpisanego słowa, pod wynikiem stoi „Pasuje przez składnik”.
Polskie znaki nadal nie mają znaczenia, a widzicie tylko te przepisy, które
możecie dziś otworzyć.

### Co mam w domu i co z tego ugotuję

W zeszycie jest nowa sekcja „Co mam w domu”. Wpisujecie, co macie w kuchni —
jeden produkt naraz, a pod polem pojawiają się podpowiedzi ze składników
z przepisów. Potem wystarczy dotknąć „Co ugotuję z tego, co mam?”, żeby
zobaczyć przepisy, do których brakuje Wam najmniej. Przy każdym stoi
dopisek, na przykład „Masz 5 z 7 składników. Brakuje: …”. Kolejność jest
jedna i napisana na ekranie: najpierw przepisy z najmniejszą liczbą brakujących
składników, a przy remisie te krótsze w przygotowaniu — popularność
przepisu nie ma na nią wpływu. Listę widzicie tylko Wy, na jednej liście może
być do 150 produktów, a przy wymazaniu konta znika razem z nim.

### Zapisz przepis do zeszytu jeszcze przed założeniem konta

Czytacie przepis bez konta i chcecie go zachować? Kliknijcie „Zapisz do zeszytu”
przy przepisie, załóżcie konto albo zalogujcie się (przycisk „Masz konto?
Zaloguj się i zapisz”). Po pierwszych krokach wrócicie na ten sam przepis,
z rozwiniętym wyborem zeszytu. Niczego nie zapisujemy za Was — przepis trafi
do zeszytu dopiero wtedy, gdy sami go wybierzecie. Jeśli w międzyczasie autor
ukryje przepis albo zmieni jego widoczność, nie otworzymy wyboru zeszytu.

### Przepis do wybranego dnia prosto z Planera

W Planerze przy każdym dniu tygodnia znajdziecie pole „Nazwa przepisu”.
Wpiszcie kilka liter, kliknijcie „Szukaj przepisu” i przy znalezionym daniu
wybierzcie „Dodaj do planu”. Nie trzeba już wchodzić na stronę przepisu.
Po dodaniu wracacie do tego samego dnia z tą samą frazą, więc od razu możecie
dopisać kolejne danie.

### Przepis wydrukowany na kartce

Przy przepisie jest przycisk „Drukuj przepis”. Otwiera od razu okno drukowania,
a jeśli przeglądarka nie wczyta skryptu, ten sam przycisk podpowiada, jakie
klawisze nacisnąć albo co wybrać w menu telefonu. Na kartce zostają tytuł,
autor, adres przepisu, porcje, składniki z uwagami, wszystkie kroki i „Skąd ten
przepis”. Menu, przyciski i komentarze nie idą na papier, a długi przepis
mieści się na 4 stronach A4, nie na 8–9. Litery na kartce mają co najmniej
12 punktów.

### Zeszyt można pokazać bez konta

Jeśli ustawicie zeszyt na „Wszyscy”, otworzy się także osobom bez konta — możecie
wysłać link rodzinie. Osoba niezalogowana zobaczy w nim tylko publiczne przepisy
i wpisy, a zamiast przycisków zapisu dostanie „Zaloguj się” albo „Załóż konto”.
Zeszyt ustawiony na „Tylko ja” (także domyślny, dopóki go nie zmienicie) nadal
widzicie tylko Wy.

### Jak dobieramy wpisy

Nowa strona „Jak dobieramy wpisy” opisuje każdą listę w serwisie: Start,
„Świeżo z Kuking”, tablicę na dziś, wyszukiwarkę i tygodniowy e-mail. Mówi też
wprost, czego nie robimy: nie układamy wpisów według popularności ani reakcji
i nie uczymy się Waszego gustu z tego, co oglądacie. Pod nagłówkiem „Świeżo
z Kuking” jest odnośnik „Skąd te wpisy i jak to zmienić”, a gdy kogoś ukrywacie,
widzicie „Ukrywasz wpisy N osób. Zmień”. Pozycje na tablicy wybrane przez
gospodarza mają napis „Wybór gospodarza”.

### Inne pytania na ten temat

Pod pytaniem w „Poradźcie” jest sekcja „Inne pytania na ten temat” z odnośnikami
„Pytania:” i nazwą tagu. Prowadzą do listy pytań z tym tagiem, a nie do ogólnej strony
tagu z daniami. Na liście tag zostaje widoczny, a odnośnik „Pokaż wszystkie
tagi” go zdejmuje. Przycisk „Czeka na odpowiedź” zawęża pytania i nie gubi
wybranego tagu.

## Alfa 0.75

**Znajdziecie swoje wcześniejsze wykonania, a „Ugotowałem” jest jeszcze pewniejsze.**

### Szukajcie we własnych wykonaniach

Na swoim profilu, w zakładce „Ugotowane”, jest pole „Szukaj w moich
wykonaniach”. Wpiszcie kawałek tytułu przepisu, żeby zobaczyć tylko te razy,
kiedy go gotowaliście — na przykład żeby porównać dzisiejszy żurek z zeszłorocznym.
Polskie znaki nie mają znaczenia: „zurek” znajdzie „Żurek”. Wpisana fraza
zostaje po kliknięciu „Pokaż więcej”, a gdy nic nie pasuje, dostaniecie
podpowiedź i przycisk „Pokaż wszystkie wykonania”. Pole widać tylko na
własnym profilu i dopiero wtedy, gdy macie już jakieś wykonania.

## Alfa 0.74

**Zdjęcia przy przepisie i wybrana liczba porcji zostają z Wami.**

### Zdjęcia zostają po poprawieniu formularza

Jeśli przy dodawaniu lub edycji przepisu wybierzecie zdjęcia, a inne pole
wymaga poprawki, formularz pokaże wybrane już zdjęcia. Możecie poprawić tekst
i wysłać przepis ponownie bez szukania tych samych plików w telefonie.

### Wybrane porcje zostają przy gotowaniu

Na stronie przepisu wybierzcie liczbę porcji przy składnikach, a potem otwórzcie
„Gotuję”. Rozwinięta lista składników pokaże ilości przeliczone na ten wybór.
Liczba porcji zostaje przy przechodzeniu między krokami i po rozpoczęciu od
początku. Po zakończeniu gotowania wrócicie do przepisu z tym samym wyborem.

## Alfa 0.73

**Przepis ze strony lub PDF i składniki odhaczane podczas gotowania.**

### Przepis ze strony lub pliku PDF

Na ekranie „Dodaj przepis” możecie wkleić adres strony z przepisem albo dodać
plik PDF. PDF z tekstem odczytujemy u siebie; skanowane strony oraz tekst strony
bez danych przepisu mogą trafić do OpenAI tylko po zgodzie dla tego wysłania.
Odczytana treść trafia do prywatnego szkicu, który możecie
poprawić przed publikacją. Adres źródłowej strony zostaje przy przepisie;
zdjęć z niej nie pobieramy. Przed publikacją potwierdzacie, że tekst został
sprawdzony. Jeśli strona nie pozwala na pobranie przepisu, zapisujemy sam
adres i podpowiadamy, jak wpisać treść ręcznie.
Każda droga importu dzieli limit 5 prób dziennie i 30 miesięcznie.

### Składniki odhaczane w trybie „Gotuję”

Przy dłuższym przepisie łatwo zgubić się w tym, co już jest odmierzone.
W trybie „Gotuję” rozwiń „Składniki” i dotknij składnika, który masz
przygotowany — pojawi się przy nim „Przygotowane”, a pod listą zobaczysz, ile
jeszcze zostało. Zaznaczenie pamięta ta karta przeglądarki, także po przejściu
do następnego kroku. Przycisk „Wyczyść zaznaczenie składników” zaczyna listę
od nowa i nie rusza odhaczonych kroków.

## Alfa 0.72

**Spokojniejsze zdjęcia i powiadomienia.**

Gdy przygotowanie zdjęcia chwilowo się nie uda, wpis dalej pokaże, że zdjęcie
jest w trakcie przygotowania. Informacja o niepowodzeniu pojawi się dopiero
po ostatniej próbie. Nie trzeba usuwać wpisu ze zdjęciem, które może się
jeszcze pokazać.

Jeśli wpis, o którym przyszło powiadomienie „Smakowicie wygląda”, został
usunięty, powiadomienie powie o tym zamiast prowadzić do pustej strony.
Przy kopiowaniu tygodnia Planer poda też liczbę pominiętych pozycji spoza
dozwolonego zakresu dat i podpowie, jaki tydzień wybrać.

## Alfa 0.71

**Przepis z kartki i orientacyjny koszt dania.**

### Ile może kosztować danie

Przy swoim przepisie możecie podać przybliżony koszt całego dania. Wpiszcie
kwotę w złotych; pole można też zostawić puste. Jeśli koszt podał autor,
zobaczycie go przy przepisie, a w wyszukiwarce znajdziecie przepisy do 20 zł.
Gdy kwoty nie ma, Kuking może pokazać orientacyjny przedział obliczony z
publicznych cen składników. Źródło cen jest podane przy wyniku, a cena w Waszym
sklepie może być inna.

### Przepis z kartki lub zeszytu

Jeśli macie przepis zapisany na kartce, możecie dodać jego zdjęcie zamiast
przepisywać wszystko ręcznie. Odczytany tekst trafi do prywatnego szkicu;
sprawdźcie go ze zdjęciem i poprawcie niepewne słowa przed pokazaniem przepisu
innym. Odczyt wymaga osobnej zgody. Gdy ta możliwość nie jest dostępna,
nadal możecie wpisać przepis samodzielnie.

### Urządzenia z dostępem

W Ustawieniach możecie otworzyć „Urządzenia z dostępem”. Gdy aplikacja
Kuking na telefon będzie dostępna, zobaczycie tu urządzenia zalogowane na
Wasze konto i odetniecie wybrane urządzenie albo wszystkie naraz. Dostęp
do API aplikacji pozostaje na razie wyłączony.

### Smakowicie wygląda

Pod cudzym wpisem możecie teraz nacisnąć „Smakowicie wygląda”, gdy chcecie
dać znać, że danie wpadło Wam w oko. Ten sam przycisk pozwala to cofnąć.
Na stronie wpisu widać, kto tak napisał, bez pokazywania liczby reakcji.
Autor dostanie o nich jedną wiadomość dziennie, żeby nie zagłuszały
„Ugotowałem”.

## Alfa 0.70

**Spokojniejsze wpisy i gotowanie.**

Zdjęcia we wpisach układają się czytelnie także wtedy, gdy mają różne
proporcje albo są trzy. Strona tagu pokazuje tylko publiczne wpisy;
własne mniej widoczne wpisy nadal znajdziecie w „Moich wpisach”.
Przy pustej zakładce „Ugotowane” można od razu przejść do wyszukiwarki
przepisów. Poprawiliśmy też zachowanie wartości odżywczych po decyzji
moderatora.

## Alfa 0.69

**Czytelniejsze powiadomienia i wygodniejsze gotowanie.**

### Szacunkowe wartości odżywcze przepisu

Pod składnikami przepisu możecie zobaczyć szacunkowe wartości na porcję:
energię, białko, tłuszcz i węglowodany. Pokazujemy liczby tylko wtedy,
gdy znamy skład co najmniej 90% masy potrawy. Jeśli brakuje ilości ważnego
składnika, powiemy dlaczego nie możemy ich obliczyć. Pod „Jak to liczymy”
znajdziecie źródła danych i sposób przeliczania miar. Autor przepisu może
ukryć tę sekcję i później znów ją pokazać.

### Moje wpisy

W zakładce „Moje” znajdziecie teraz przycisk „Moje wpisy”. Prowadzi do
Waszych wpisów od najnowszego — również tych prywatnych, dla
obserwujących oraz szkiców. Zobaczycie tam także własne wpisy ukryte
przez moderację. Przy każdym jest napisane, kto może go zobaczyć i w jakim
jest stanie. Tę listę otworzycie tylko Wy.

### Ta strona

Numer wersji w stopce każdej strony prowadzi teraz właśnie tutaj — na tę
stronę. Kliknięcie otwiera ją od razu przy opisie bieżącego wydania, a nie
od góry. Skrót commita i data w stopce zostają na swoim miejscu — nadal
przydadzą się, gdy będziecie zgłaszać nam usterkę.

### Numer wersji z końcówką wdrożenia

Numer w stopce ma teraz dodatkową końcówkę, np. „Alfa 0.68.005” zamiast
samego „Alfa 0.68” — dwa różne wdrożenia tego samego dnia dają się teraz
odróżnić na pierwszy rzut oka, bez porównywania skrótów kodu z pamięci.
Końcówka rośnie sama, przy każdym wdrożeniu, i wraca do „.001”, gdy
zmienia się duży numer wydania. Przy każdej nowej funkcji na tej stronie
zobaczycie teraz też, od którego dokładnie wydania działa — „od Alfa
0.68.005”, i to na stałe: dopisek zostaje przy tym opisie, nawet gdy
wydanie doczeka się własnego numeru i wprowadzka trafi do jego sekcji.

### Zróbcie swoją wersję cudzego przepisu

Pod przepisem, który możecie otworzyć — także takim „tylko dla obserwujących” —
jest teraz przycisk **„Zrób swoją wersję”**. Jednym kliknięciem dostajecie
własną, prywatną kopię: składniki i kroki możecie zmieniać do woli (mniej
soli, inny ser, własne proporcje), ale bez cudzych zdjęć — te zostają przy
oryginale. Nad tytułem Waszej wersji zawsze widać, na podstawie czyjego
przepisu powstała, z odnośnikiem do autora — tego podpisu nie da się usunąć.
Wersji, w której nic naprawdę nie zmieniliście, nie da się opublikować —
Kuking podpowie wtedy, że chyba wystarczy zwykłe „Ugotowałem”. Autor
oryginału dostaje jedno powiadomienie, gdy Wasza wersja po raz pierwszy
staje się widoczna dla innych, i widzi pod swoim przepisem listę wersji,
które z niego powstały. Gdy autor usunie oryginał, Wasza wersja zostaje —
tylko podpis mówi, że oryginału już nie ma.

### Prywatna notatka przy przepisie w zeszycie

Przy przepisie albo wpisie we własnym zeszycie możecie dopisać notatkę
dla siebie, na przykład „na urodziny taty — mniej soli”. Notatkę widzicie
tylko Wy — nie zobaczy jej ani autor, ani nikt inny, kto ogląda zeszyt,
nawet publiczny. Ten sam przepis w dwóch Waszych zeszytach może mieć dwie
różne notatki. Żeby ją usunąć, wystarczy wyczyścić pole i zapisać.

### Szukajcie we własnych zeszytach

Na ekranie „Twój zeszyt” jest pole „Szukaj w moich zeszytach”. Wystarczy
wpisać kawałek tytułu przepisu albo składnika, żeby go znaleźć wśród swoich
zapisów, bez pamiętania, do którego zeszytu trafił — przy każdym wyniku widać zeszyty,
w których leży. Polskie znaki nie mają znaczenia.

### Dopiszcie przepis do własnego wpisu

Do własnego wpisu ze zdjęciem dania można teraz dopisać przepis: w menu
„…” przy wpisie jest „Dopisz przepis”. Otwiera się zwykły formularz
przepisu, już ze zdjęciem z wpisu — nie trzeba go wgrywać drugi raz. Sam
wpis zostaje taki, jaki był, z treścią i komentarzami; kto ma widzieć
przepis, wybieracie osobno.

### Wspomnienie w rocznicę założenia konta

W rocznicę założenia konta na stronie głównej pojawia się jedno zdanie od
gospodarza, na przykład „Gotujesz z nami od roku — dziękuję, że jesteś.”.
Widzi je tylko właściciel konta, bez maila i bez powiadomienia. Wyłącza
się je tym samym przełącznikiem co wspomnienia (Ustawienia → Prywatność).

**Poprawki tego wydania w jednym zdaniu:** sporo drobiazgów za kulisami
i w interfejsie — uczciwsze liczniki i powiadomienia, czytelniejsze
komunikaty błędów, bezpieczniejsza obsługa zgłoszeń i odwołań w panelu
moderacji oraz kilka poprawek szybkości i wyszukiwania. Pełna, techniczna
lista — jak zawsze — w `CHANGELOG.md`.

## Alfa 0.68

**Minutnik przy gotowaniu i formularze, które nie gubią wpisanego tekstu.**

### Minutnik przy kroku przepisu

W trybie gotowania, przy każdym kroku, możecie teraz włączyć minutnik.
Odlicza on naprawdę upływający czas — zmiana godziny w telefonie go nie
skróci ani nie przedłuży — a ekran telefonu nie gaśnie, dopóki gotujecie,
więc nie trzeba go co chwilę odblokowywać rękami umazanymi w mące.

### „Usuń z zeszytu” prosto z karty wpisu

Na karcie wpisu, obok odnośnika „Masz to w zeszycie”, jest teraz przycisk
„Usuń z zeszytu”. Rozmyśliliście się i chcecie zapisać go z powrotem?
Wystarczy kliknąć jeszcze raz — bez szukania w ustawieniach zeszytu.

Poprawki tego wydania w jednym zdaniu: formularze ze zdjęciami nie gubią już
wpisanego tekstu przy błędzie, postęp gotowania przeżywa poprawkę przepisu,
powiększone zdjęcie i wyszukiwarka działają dokładniej, a stopka, okruszki,
komentarze i kilka ekranów moderacji mają czytelniejsze i bezpieczniejsze
zachowanie.

## Alfa 0.67

**Tagi ze zdjęciami z Waszych kuchni.**

### Zdjęcia na kaflach tagów

Katalog tematów (tagów) ma teraz większe kafle, a na każdym — publiczne
zdjęcie i podpis autora, jeśli takie zdjęcie jest pod danym tematem. Łatwiej
dzięki temu zobaczyć, co naprawdę się pod tematem gotuje, zanim się w niego
klikniecie.

Poprawki tego wydania w jednym zdaniu: dla osób rozwijających Kuking
kontrola przed wysłaniem zmian nauczyła się liczyć testy szybciej, bez
wpływu na to, co widzicie na ekranie.

## Alfa 0.66

**Porządek w rozmowach i przygotowanie „Poradźcie”.**

### „Poradźcie” — pytania o gotowanie

Przygotowaliśmy nowy dział: **Poradźcie**. Będzie można w nim zadać pytanie
o gotowanie i dostać odpowiedzi od innych osób z Kuking, a gospodarz
działu będzie mógł uporządkować kolejkę pytań. Dział zostaje na razie
wyłączony — otworzymy go stopniowo, gdy będziemy gotowi.

Poprawki tego wydania w jednym zdaniu: formularz odpowiedzi i poprawki
komentarza nie gubi już wpisanego tekstu przy błędzie, a usunięta odpowiedź
z dalszą rozmową nie zawyża już licznika odpowiedzi na pytanie.

## Alfa 0.65

**Odnośniki prowadzą tam, gdzie obiecują.**

Same poprawki, jedno zdanie: „Poszukaj przepisów” w pustym zeszycie otwiera
teraz wyszukiwarkę przepisów, a odnośnik do tablicy wpisów nazywa się tak
samo, jak strona, do której prowadzi.

## Alfa 0.64

**Wykonania i odpowiedzi liczone uczciwie.**

Same poprawki, jedno zdanie: liczba „Ugotowałem” przy przepisie liczy teraz
także kolejne gotowania tej samej osoby, a podpisy mówią o wykonaniach
i odpowiedziach, a nie o liczbie osób.

## Alfa 0.63

**Zobaczcie, co gotują inni pod tym tagiem.**

### Kolaż zdjęć na stronie tagu

Strona każdego tematu (tagu) pokazuje teraz kolaż najnowszych publicznych
zdjęć od różnych osób, które o nim pisały — zdjęcie prowadzi wprost do
wpisu. Najpopularniejsze tematy mają dodatkowo własną kartę ze zdjęciem
i krótkim zaproszeniem; pozostałe wciąż znajdziecie na liście
alfabetycznej.

## Alfa 0.62

**Zdjęcia i osoby przy tagach.**

### Kto i ile razy gotował pod danym tagiem

Przy temacie, pod którym jest już co najmniej pięć publicznych zdjęć od
trzech różnych osób, zobaczycie liczbę zdjęć i autorów. Przy mniejszym
zbiorze strona tematu zaprasza do dodania własnego, pierwszego wpisu.
