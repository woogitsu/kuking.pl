## D-142 · Bramka R2 sprawdza z serwera to, co się da; pusta lista publicznych adresów to NIEPRZEJŚCIE, nie zieleń

**Data:** 11 września 2026 · Issue #120 · Status: **obowiązuje**

### Sprawa

Issue #120 zamknęło stronę aplikacyjną (własny sterownik `r2` bez ACL, osobne
buckety, dysk oryginałów bez klucza `url`), ale zostawiło **dwanaście punktów
do sprawdzenia ręcznie** na prawdziwym R2 — bo z PHP nie widać panelu Cloudflare.

Dwanaście ręcznych punktów to bramka, której nikt nie przejdzie dwa razy:
pierwszy raz z zapałem, drugi nigdy. A konfiguracja bucketu może się zmienić
bez jednej linijki w tym repozytorium.

### Decyzja

Komenda `kuking:bramka-r2` robi z serwera wszystko, co się da: pyta prawdziwe R2
prawdziwymi żądaniami i mówi po polsku, co z nich wyszło. Punkty, których
z serwera sprawdzić **nie da się** (wgranie zdjęcia z telefonu, kasowanie
z bazy), wypisuje na końcu jako pozostałe do zrobienia — zamiast udawać, że
ich nie ma.

### Najważniejsze: pusta lista adresów znaczy „NIE WIEMY", a nie „nic nie jest publiczne"

Issue żąda dowodu, że oryginał nie wyjdzie „przez KAŻDĄ publiczną ścieżkę":
własną domenę, `r2.dev` i endpoint konta. Z konfiguracji dawał się wyprowadzić
**jeden** z tych adresów — endpoint — bo klucz `url` został z dysków mediów
świadomie zdjęty, a domena i `r2.dev` żyją wyłącznie w panelu Cloudflare.

Bramka pytała więc o adres, którym nikt nie chodzi, milczała o adresie, którym
chodzi przeglądarka, i **świeciła na zielono**. Dokładnie ta klasa usterki,
przed którą sama ostrzega: narzędzie melduje sukces, oglądając co innego, niż
się wydaje.

Dlatego publiczne adresy trzeba bramce **zadeklarować**
(`KUKING_R2_PUBLICZNE_ADRESY`), razem z tymi, które mają być wyłączone.
Wyłączenie `r2.dev` jest udowodnione dopiero wtedy, gdy spod adresu `pub-….r2.dev`
przyszła odmowa. Pusta lista to nieprzejście.

Odmową jest `403`/`404`. `301` na inny host nie jest odmową — jest przekierowaniem
w miejsce, którego bramka nie sprawdziła.

### Granice, które komenda trzyma

Nie kasuje niczego i domyślnie nic nie zapisuje. `--zapis` dokłada JEDEN plik
tekstowy w prefiksie `bramka/` i kasuje go po sprawdzeniu — i mówi o tym
przed zrobieniem. Klucze API nigdy nie idą na wyjście, nawet fragmentami;
adresów podpisanych też nie wypisujemy w całości, bo sygnatura w podpisanym
adresie jest jednorazowym prawem dostępu do czyjegoś zdjęcia, a wyjście tej
komendy trafia do zgłoszeń i do dokumentacji.
