{{--
    Zwykły tekst, bez HTML-a. Otwiera się w Notatniku na każdym komputerze.
    Plik zapisujemy z sygnaturą UTF-8 (BOM) — dzięki temu polskie znaki
    wyświetlają się poprawnie także w starym Notatniku, który bez sygnatury
    potraktowałby plik jako Windows-1250 i rozsypał polskie znaki.
--}}
TWOJE DANE Z KUKING.PL
======================

Paczka przygotowana: {{ \App\Support\Czas::data($generatedAt, 'j F Y, H:i') }}
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

{{--
    Katalog opisujemy TYLKO wtedy, gdy naprawdę jest w paczce. `ZipArchive`
    nie tworzy pustych katalogów, więc na koncie bez przepisów archiwum ma
    cztery pliki (zmierzone `unzip -l`), a ten spis obiecywał katalogi
    „przepisy" i „zdjecia". Ta sama usterka była już w `index.html`
    (pilnuje jej `DataExportTest::test_paczka_bez_zdjec_nie_obiecuje_katalogu_ktorego_nie_ma`)
    — tam naprawiona, tu została. Człowiek najświeższy w serwisie czytał
    więc w pliku „CZYTAJ TO NAJPIERW", że ma w paczce katalogi, których
    jego eksplorator plików nie pokaże.
--}}
@if($recipeCount > 0)
przepisy/
    Każdy Twój przepis jako osobna strona do czytania i do wydruku.
    Otwierają się dwuklikiem, tak samo jak index.html.
    Przepisów w paczce: {{ $recipeCount }}
@else
{{--
    Ta gałąź podlega tej samej zasadzie co gałąź o zdjęciach niżej: zdanie
    opisuje PACZKĘ. `index.html` mówił tak od początku („W tej paczce nie ma
    jeszcze żadnego przepisu"), README orzekał o koncie. Dziś przepis nie ma
    stanu „odrzucony", więc nie było to kłamstwo — ale na koncie, które swoje
    przepisy skasowało, słowo „jeszcze" jest już fałszywe, a paczka nie ma
    powodu mówić o koncie dwóch różnych rzeczy w dwóch swoich plikach.
--}}
przepisy/
    Tego katalogu w tej paczce NIE MA — nie weszedł do niej żaden
    przepis. Pojawi się, gdy dodasz przepis i poprosisz o paczkę
    ponownie.
@endif

wpisy.html
    Twoje wpisy z gotowania, zapisane wykonania przepisów
    i Twoje komentarze.
@if($photoCount > 0)

{{--
    „Zdjęcia, które weszły do tej paczki", a nie „wszystkie Twoje zdjęcia".
    Zmierzone na prawdziwym archiwum: do paczki wchodzą wyłącznie zdjęcia
    ze statusem `ready` (`ExportPhotoPlan`), a konto potrafi mieć obok nich
    zdjęcia odrzucone albo skasowane — z plikami w storage. Słowo
    „wszystkie" było wtedy po prostu nieprawdziwe. Liczba niżej opisuje
    paczkę i zostaje bez zmian, bo ona akurat była prawdziwa.
--}}
zdjecia/
    Zdjęcia, które weszły do tej paczki — po jednym pliku.
    Nazwa każdego zaczyna się od daty, na przykład:
    2027-03-14-rosol.webp
    Zdjęć w paczce: {{ $photoCount }}
@elseif($photosStillProcessing === 0)
{{--
    ZDANIE OPISUJE PACZKĘ, NIE KONTO — i to jest cała poprawka.

    Ta gałąź nie znaczy „nie masz zdjęć". Znaczy: żadne zdjęcie nie weszło
    do paczki i żadne nie jest w drodze. `ExportPhotoPlan` liczy tylko
    `ready` (do paczki) oraz `pending`/`processing` (w drodze), więc konto
    z SAMYMI zdjęciami odrzuconymi ma tu `photoCount = 0`
    i `photosStillProcessing = 0` — i słyszało „nie masz jeszcze w Kuking
    żadnego zdjęcia" o zdjęciach, które samo wgrało i widzi w serwisie
    jako odrzucone. To samo dotyczy zdjęć skasowanych.

    Nowe zdanie mówi wyłącznie to, co paczka naprawdę wie o sobie, i nie
    orzeka o zawartości konta. Zakres danych eksportu ani uprawnienia
    NIE zmieniają się o nic: paczka nadal nie wypisuje zdjęć odrzuconych
    ani niczego o powodzie odrzucenia.

    Warunek powrotu katalogu podany jest wprost („zdjęcie, które widać
    w serwisie"), bo samo „dodaj zdjęcie" byłoby dla konta z odrzuconymi
    nieprawdą: ono zdjęcia dodało. Piszemy o tym, co człowiek widzi
    w serwisie, a nie o stanie `ready` — nazwa stanu nic mu nie mówi.

    Przy zdjęciach w drodze to zdanie się NIE pojawia (issue #113) —
    wtedy wchodzi UWAGA niżej. Pilnuje tego
    `EksportMowiOZdjeciachWDrodzeTest`, z osobną asercją na każdą gałąź.
--}}

zdjecia/
    Tego katalogu w tej paczce NIE MA — nie weszło do niej żadne
    zdjęcie. Pojawi się, gdy będziesz mieć w Kuking zdjęcie, które
    widać w serwisie, i poprosisz o paczkę ponownie.
@endif
@if($photosStillProcessing > 0)
@php
    // Liczebnik i czasownik odmieniają się tak samo (1 / 2-4 / 5+ i nastki),
    // więc obie formy bierze ta sama funkcja — inaczej wyszłoby
    // „3 zdjęć nie zmieściło się", czyli zdanie po polsku niepoprawne.
    $ile = $photosStillProcessing;
    $zdjecia = \App\Support\Odmiana::rzeczownik($ile, 'zdjęcie', 'zdjęcia', 'zdjęć');
    $zmiescilo = \App\Support\Odmiana::rzeczownik($ile, 'zmieściło', 'zmieściły', 'zmieściło');
    $sie = \App\Support\Odmiana::rzeczownik($ile, 'przygotowywało', 'przygotowywały', 'przygotowywało');
@endphp

    UWAGA: {{ $ile }} {{ $zdjecia }} nie {{ $zmiescilo }} się w tej paczce.
    W chwili jej budowania {{ $sie }} się jeszcze do pokazania w serwisie.
    Poproś o nową paczkę, gdy przygotowywanie zdjęć się zakończy. Przed usunięciem konta sprawdź, czy zawiera wszystkie Twoje zdjęcia.
@endif
{{--
    ZDJĘCIA, KTÓRE DO PACZKI NIE WEJDĄ NIGDY (issue #692).

    Ten sam komunikat co w `index.html` i z tego samego powodu — paczka
    nie ma prawa mówić dwóch różnych rzeczy o jednej sytuacji w dwóch
    swoich plikach. Pełne uzasadnienie (dlaczego DWA bloki, dlaczego
    różny ton i dlaczego oba pod warunkiem `> 0`, skoro reguła
    w `dane.json` stoi bezwarunkowo) jest przy tym samym bloku
    w `index.blade.php`; nie przepisujemy go tu drugi raz.

    Tu jest wyłącznie LICZBA. Zakres danych paczki nie zmienia się o nic.
--}}
@if($photosRejected > 0)
@php
    // Liczebnik i oba czasowniki odmieniają się tak samo (1 / 2-4 / 5+
    // i nastki) — wszystkie formy z tej samej funkcji.
    $ileOdrzuconych = $photosRejected;
    $zdjeciaOdrzucone = \App\Support\Odmiana::rzeczownik($ileOdrzuconych, 'zdjęcie', 'zdjęcia', 'zdjęć');
    $weszloOdrzucone = \App\Support\Odmiana::rzeczownik($ileOdrzuconych, 'weszło', 'weszły', 'weszło');
    $wejdzieOdrzucone = \App\Support\Odmiana::rzeczownik($ileOdrzuconych, 'wejdzie', 'wejdą', 'wejdzie');
    $ichOdrzuconych = \App\Support\Odmiana::rzeczownik($ileOdrzuconych, 'go', 'ich', 'ich');
@endphp

    UWAGA: {{ $ileOdrzuconych }} {{ $zdjeciaOdrzucone }} nie {{ $weszloOdrzucone }} do tej paczki
    i nie {{ $wejdzieOdrzucone }} do żadnej następnej.
    Nie udało się {{ $ichOdrzuconych }} przygotować do pokazania w serwisie, a tego już
    się nie cofnie — nowa paczka nic tu nie zmieni.
    Oryginały, które masz u siebie na komputerze albo w telefonie,
    możesz wgrać do Kuking jeszcze raz.
@endif
@if($photosDeleted > 0)
@php
    $ileSkasowanych = $photosDeleted;
    $zdjeciaSkasowane = \App\Support\Odmiana::rzeczownik($ileSkasowanych, 'zdjęcie', 'zdjęcia', 'zdjęć');
    $weszloSkasowane = \App\Support\Odmiana::rzeczownik($ileSkasowanych, 'weszło', 'weszły', 'weszło');
    $wejdzieSkasowane = \App\Support\Odmiana::rzeczownik($ileSkasowanych, 'wejdzie', 'wejdą', 'wejdzie');
@endphp

    {{ $ileSkasowanych }} {{ $zdjeciaSkasowane }}, które skasowano z Kuking,
    nie {{ $weszloSkasowane }} do tej paczki i nie {{ $wejdzieSkasowane }} do żadnej następnej.
    Skasowane zdjęcie znika z serwisu razem ze swoimi plikami,
    więc nie ma już czego do paczki włożyć.
@endif

dane.json
    Ten sam zestaw danych w formacie dla programów. Przydaje się,
    jeśli zechcesz przenieść swoje przepisy do innego serwisu.
    Nie musisz go otwierać — dla człowieka jest index.html.

CZYTAJ-TO-NAJPIERW.txt
    Ten plik.


DOBRA RADA
----------

Skopiuj całą tę paczkę w dwa miejsca — na komputer i na pendrive
albo dysk zewnętrzny. Jeśli jeden nośnik się zepsuje, Twoje przepisy
zostaną na drugim.

@if($recipeCount > 0)
{{-- Ta rada odsyła do katalogu — więc podlega tej samej regule co spis
     wyżej: na koncie bez przepisów katalogu „przepisy" w paczce nie ma
     i nie ma czego otwierać. --}}
Jeśli chcesz mieć przepis na papierze: otwórz go z katalogu "przepisy"
i wciśnij Ctrl+P (drukowanie). Strona jest przygotowana tak, żeby
wydruk był czytelny.
@endif


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
