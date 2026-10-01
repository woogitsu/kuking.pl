## D-032 · Plakietka „konto przykładowe" jest krótka i cicha; głośna wolno raz na ekran

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**
· **odwraca D-025 w części o wyglądzie plakietki**

D-025 kazało oznaczać treść zalążkową tak, żeby grupa 50+ zauważyła to bez
czytania drobnego druku: `.badge-przykladowe` na 18 px, z ramką i tłem
akcentu, w każdym miejscu, gdzie widać autora. Zmierzony skutek: w strumieniu
ta sama plakietka powtarzała się kilkanaście razy na jednym ekranie i była
**najgłośniejszym elementem strony** — głośniejszym niż zdjęcia potraw, po
które ludzie tu przychodzą. Oznaczenie, które powtarza się piętnaście razy pod
rząd, przestaje cokolwiek znaczyć.

**Dwie zmiany naraz, bo to jedna sprawa.**

**Waga.** Domyślna plakietka jest cicha (`.badge-cichy`: bez tła, bez ramki,
16 px, waga 600) i stoi w wierszu metadanych, po kropce, obok daty — czytelna
dokładnie wtedy, gdy ktoś patrzy na autora. Głośna (`.badge-przykladowe`,
wygląd bez zmian) zostaje wyłącznie na **profilu** konta przykładowego, czyli
w jedynym miejscu, gdzie stoi dokładnie raz na ekran.

**Treść.** Jedno brzmienie w całym serwisie: **„konto przykładowe"**, bez
członu „— nie prawdziwa osoba". W obiegu były cztery brzmienia w pięciu
miejscach, a `BRAND_EXTENDED.md` §3 mówi: nazwa funkcji jest jedna i nie ma
synonimów.

**Skrócenie nie kasuje informacji, tylko ją przenosi** — i to jest warunek tej
decyzji, nie dopisek. Pełne zdanie („To konto jest przykładowe: nie ma za nim
prawdziwej osoby") stoi **raz**, jako osobny akapit na profilu konta
przykładowego. Bez niego skrót odbierałby ostrzeżenie zamiast je przesunąć.
Test `KontoPrzykladoweWidoczneTest` pilnuje obu połówek naraz: liczy
wystąpienia obu klas na ekranie, a nie samą obecność napisu — usterka, o którą
tu chodzi, polega na POWTÓRZENIU głośnej plakietki, nie na jej braku.

**Zmiana wymaga:** dowodu z testów z osobami 50+ (#15), że cicha plakietka
w wierszu metadanych bywa przeoczona. Nie „wrażenia, że jest za mała".

📄 `resources/views/components/konto-przykladowe.blade.php` ·
`resources/css/app.css` (`.badge-cichy`, `.badge-przykladowe`) ·
`tests/Feature/KontoPrzykladoweWidoczneTest.php` · D-025 · D-103 (system v3.1)
