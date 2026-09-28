# Co nowego w Kuking

Kuking rośnie krok po kroku. Tu piszemy, co się zmieniło — prostym językiem,
bez fachowych słów. Pełna, techniczna lista wszystkich zmian (także tych,
które widać tylko „pod maską”) jest w `CHANGELOG.md` w repozytorium.

Numer wydania (np. „Alfa 0.68.005”) widzicie w stopce każdej strony, razem
z datą i skrótem kodu — to przydaje się, gdy zgłaszacie nam usterkę.

## Spis wydań

- [Najnowsze zmiany](#najnowsze-zmiany)
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
wpisać kawałek tytułu przepisu, żeby go znaleźć wśród swoich zapisów, bez
pamiętania, do którego zeszytu trafił — przy każdym wyniku widać zeszyty,
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
