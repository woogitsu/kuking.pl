## D-023 · Oryginał zdjęcia traci współrzędne GPS przy wgraniu

**Data:** 7 września 2026 · Status: **obowiązuje** · weryfikacja W5 (pomiar)

Warianty pokazywane w serwisie powstają przez przekodowanie do WebP, więc
EXIF w nich nie ma. **Oryginał był zapisywany bajt w bajt** —
`StoreUploadedImage.php:180`, `put($objectKey, $file->get())` — i komentarz
w kodzie mówił to wprost: *„ORYGINAŁ zachowuje go w całości — łącznie ze
współrzędnymi GPS, czyli adresem kuchni użytkownika"*.

Oryginał nie jest kasowany po przetworzeniu (eksport RODO ma oddać
człowiekowi jego zdjęcie, nie zmniejszoną kopię) i **trafia do paczki
danych**.

**Dlaczego to jest problem, a nie świadomy kompromis:** obecna, opublikowana
polityka prywatności mówi *„Nie zbieramy: numeru telefonu, dokładnego adresu
zamieszkania, **lokalizacji GPS**"*. To zdanie było nieprawdziwe. RODO patrzy
na przechowywanie, nie na użycie — „nie czytamy tego pola" nie znaczy „nie
zbieramy".

### Co odrzucono

**Zostawić oryginał w całości i poprawić politykę.** Uczciwe i tanie.
Odrzucone, bo cena jest realna: przechowujemy adres domu grupy 50+ w pliku,
którego do niczego nie używamy. Zdanie w polityce nie jest tu problemem —
problemem jest samo dane. Poprawianie dokumentu, żeby pasował do
niepotrzebnego zbierania, jest odwrotnością minimalizacji.

**Wyczyścić też oryginały już wgrane.** Najczystszy stan końcowy. Odrzucone
NA TERAZ, bo modyfikuje pliki, które ludzie już wgrali, i tego nie da się
cofnąć. Do zrobienia osobno, świadomie, po sprawdzeniu, ile takich plików
w ogóle jest.

### Co wybrano

**Blok GPS wypada z oryginału w chwili wgrania. Reszta EXIF zostaje.**

Aparat, obiektyw, data, orientacja — wszystko to zostaje, bo to jest
informacja o zdjęciu, którą właściciel może chcieć odzyskać z eksportu.
Wypada wyłącznie lokalizacja, bo to jest informacja o CZŁOWIEKU, nie
o zdjęciu.

Zdanie w polityce staje się prawdziwe bez zmiany dokumentu — a to jest
lepszy kierunek naprawy niż przepisywanie obietnicy pod kod.

> **Uzupełnienie z 9 września — decyzja bez zmian, wykonanie było dziurawe
> (A6-02).** `UsunGps` deklarowała cztery kontenery, a szukała bloku TIFF
> przez `strpos($bajty, "Exif\0\0")`. Ten prefiks jest częścią segmentu APP1
> **w JPEG-u**; w PNG (chunk `eXIf`) i WebP (chunk `EXIF`) dane chunku to
> zgodnie ze specyfikacją już sam blok TIFF, bez prefiksu. Poprawnie zapisane
> PNG i WebP przechodziły więc przez sanitator NIETKNIĘTE, ze współrzędnymi
> w środku. Znalazł to audyt zewnętrzny, odczytując zapisane pliki
> niezależnym dekoderem. Blok TIFF jest teraz znajdowany po strukturze
> kontenera, a `OryginalTraciGpsTakzeWPngIWebpTest` pilnuje PNG i WebP osobno.
>
> **Czego to nadal nie obejmuje, wprost:** EXIF-u zapisanego w PNG jako tekst
> (`zTXt`/`iTXt` z profilem „Raw profile type exif"), metadanych XMP w żadnym
> kontenerze — XMP potrafi nieść własne pola lokalizacji — ani AVIF-a inaczej
> niż przez awaryjne szukanie nagłówka w bajtach. To są znane, nieprzykryte
> luki, nie przeoczenie.
>
> **Decyzja „nie ruszamy oryginałów już wgranych" zostaje** — właściciel
> potwierdził ją ponownie 9 września. Naprawa dotyczy wyłącznie nowych wgrań.
>
> **Uzupełnienie z 23 września — XMP i tekstowy profil EXIF w PNG (#1004).**
> Dwie z trzech luk wyżej są zamknięte. XMP niesie własne współrzędne
> (`exif:GPSLatitude`, `GPSDest*`, lokalizacje IPTC, pola producentów)
> w dowolnych przestrzeniach nazw, więc nie szukamy w nim pól: **cały pakiet
> XMP zamieniamy na spacje**, w miejscu, bez zmiany długości. To jest świadome,
> wąskie odstępstwo od „reszta metadanych zostaje": aparat, obiektyw, data
> i orientacja żyją w EXIF-ie, który zostaje; z XMP wypada zwykle historia
> edycji. Tak samo wypadają PNG-owe „Raw profile type …". AVIF nadal jest
> czyszczony wyłącznie szukaniem w bajtach (EXIF po nagłówku, XMP po ramce
> pakietu) — bez parsera ISOBMFF. Decyzja o starych oryginałach bez zmian.

📄 `app/Domain/Media/UsunGps.php` ·
`app/Domain/Media/Actions/StoreUploadedImage.php` ·
`tests/Feature/OryginalTraciGpsTakzeWPngIWebpTest.php` ·
`tests/Feature/OryginalTraciGpsZXmpTest.php` ·
`resources/legal/polityka-prywatnosci.md`
