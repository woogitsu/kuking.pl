{{--
    Zwykły tekst, bez HTML-a. Otwiera się w Notatniku na każdym komputerze.
    Plik zapisujemy z sygnaturą UTF-8 (BOM) — dzięki temu polskie znaki
    wyświetlają się poprawnie także w starym Notatniku, który bez sygnatury
    potraktowałby plik jako Windows-1250 i rozsypał polskie znaki.
--}}
TWOJE DANE Z KUKING.PL
======================

Paczka przygotowana: {{ $generatedAt->translatedFormat('j F Y, H:i') }}
@if($displayName)
Dla: {{ $displayName }}
@endif

CO ZROBIĆ NAJPIERW
------------------

Kliknij dwa razy na plik "index.html".

Otworzy się w przeglądarce (Chrome, Edge, Firefox — w tej, którą masz)
i zobaczysz spis wszystkiego, co jest w tej paczce. Stąd przejdziesz
do każdego swojego przepisu.

Internet NIE jest do tego potrzebny. Ta paczka działa sama.


CO JEST W ŚRODKU
----------------

index.html
    Spis treści. Od tego zaczynasz.

przepisy/
    Każdy Twój przepis jako osobna strona do czytania i do wydruku.
    Otwierają się dwuklikiem, tak samo jak index.html.
    Przepisów w paczce: {{ $recipeCount }}

wpisy.html
    Twoje wpisy „co dziś ugotowałam”, zapisane wykonania przepisów
    i Twoje komentarze.

zdjecia/
    Wszystkie Twoje zdjęcia, po jednym pliku.
    Nazwa każdego zaczyna się od daty, na przykład:
    2027-03-14-rosol.webp
    Zdjęć w paczce: {{ $photoCount }}

dane.json
    Ten sam zestaw danych w formacie dla programów. Przydaje się,
    jeśli będziesz chciała przenieść swoje przepisy do innego serwisu.
    Nie musisz go otwierać — dla człowieka jest index.html.

CZYTAJ-TO-NAJPIERW.txt
    Ten plik.


DOBRA RADA
----------

Skopiuj całą tę paczkę w dwa miejsca — na komputer i na pendrive
albo dysk zewnętrzny. Jeśli jeden nośnik się zepsuje, Twoje przepisy
zostaną na drugim.

Jeśli chcesz mieć przepis na papierze: otwórz go z katalogu "przepisy"
i wciśnij Ctrl+P (drukowanie). Strona jest przygotowana tak, żeby
wydruk był czytelny.


O KOMENTARZACH INNYCH OSÓB
--------------------------

Pod Twoimi wpisami i przepisami są komentarze innych ludzi. Zapisaliśmy
ich treść, datę i podpis, którym te osoby się przedstawiały. NIE ma tu
ich adresów e-mail ani żadnych ich danych kontaktowych — to nie są Twoje
dane i nie możemy Ci ich przekazać.


DLACZEGO TA PACZKA ISTNIEJE
---------------------------

Bo Twoje przepisy należą do Ciebie. Masz prawo je dostać i przenieść
gdziekolwiek chcesz (RODO artykuł 15 i artykuł 20). I dlatego, że
serwisy internetowe czasem znikają — a Twój rosół nie powinien zniknąć
razem z nimi.

Jeśli coś jest niejasne, napisz do nas: {{ $contactEmail }}
