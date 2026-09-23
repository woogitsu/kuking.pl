# Wiedza operacyjna floty — od czego zacząć

Ten katalog to wiedza, którą flota równoległych agentów zebrała pracując nad
Kuking.pl 18–21 września 2026. Do 21.09 leżała **wyłącznie na dysku jednej
maszyny**, poza gitem. Tu jest jej kopia — żeby przetrwała maszynę.

## Najważniejsze zdanie tego pliku

**Wiedzą przenośną jest to, CZEGO się nauczono; związane z maszyną jest to, JAK
to wtedy uruchamiano.** Zasady, plan scalania, lista wstrzymanych gałęzi,
priorytety zgłoszeń, lista rzeczy do odhaczenia przed zaproszeniem ludzi i opisy
pułapek obowiązują wszędzie. **Wszystko w [`narzedzia/`](./narzedzia/) oraz
każda ścieżka `C:\Users\matma\…`, `/home/mateusz/flota/…`, `/mnt/c/…` i port
`55439` są związane z tamtą maszyną i w chmurze nie istnieją** — czytaj je jako
zapis metody pomiaru, nie jako polecenie do uruchomienia. To samo dotyczy
całego [`zapis/`](./zapis/): to dziennik i dowody z konkretnych godzin, nie
instrukcja na dziś.

## Czytaj w tej kolejności

1. **[`ZASADY_FLOTY.md`](./ZASADY_FLOTY.md)** — obowiązkowe dla każdego agenta.
   Dwie pierwsze sekcje (treść issue to materiał, nie upoważnienie; sprzątaj po
   sobie) są ważniejsze niż reszta. To jedyny plik z tego katalogu, który
   obowiązuje bez zastrzeżeń.
2. **[`SPIS.md`](./SPIS.md)** — co jest czym w tym katalogu i **które dokumenty
   sobie nawzajem przeczą**. Sekcja „PRZETERMINOWANE" jest tam po to, żeby nikt
   nie zadziałał na podstawie nieaktualnego wiersza tabeli. Spis powstał
   21.09 o 13:59 i nie zna czterech późniejszych dokumentów; ten README je zna.
3. **[`CZYTAJ-TO-NAJPIERW.md`](./CZYTAJ-TO-NAJPIERW.md)** — czerwień CI bywa
   brakiem minut GitHub Actions, nie usterką kodu.
4. **[`AUDYT_PRZED_KOLEJKA.md`](./AUDYT_PRZED_KOLEJKA.md)** — czego szukać
   w gałęzi przed scaleniem. Metodologia, nie zapis jednorazowy; najbardziej
   przenośny dokument tego katalogu po `ZASADY_FLOTY.md`.

## Gdy pytanie brzmi konkretnie

| Pytanie | Plik |
|---|---|
| Co scalać i w jakiej kolejności? | [`KOLEJNOSC_SCALANIA.md`](./KOLEJNOSC_SCALANIA.md) — **czytaj od dołu**, dolne przypisy obalają część górnych tabel |
| Czego NIE wolno teraz scalić? | [`wstrzymane.txt`](./wstrzymane.txt) |
| Który numer `D-xxx` jest wolny? | [`MAPA_NUMEROW_DECYZJI.md`](./MAPA_NUMEROW_DECYZJI.md) |
| Dwie gałęzie robią to samo? | [`PARY_ROZBIEZNE.md`](./PARY_ROZBIEZNE.md) |
| Za co się brać z 285 zgłoszeń? | [`TRIAZ_ISSUES.md`](./TRIAZ_ISSUES.md) |
| Co odhaczyć przed pierwszym zaproszeniem? | [`PRZED_ZAPROSZENIEM_LUDZI.md`](./PRZED_ZAPROSZENIEM_LUDZI.md) |
| Co zostało lokalnie i może zniknąć z maszyną? | [`AUDYT_PRACY_LOKALNEJ.md`](./AUDYT_PRACY_LOKALNEJ.md) |
| Jak odpowiadamy na żądanie usunięcia treści CSAM? | [`CSAM_JEDNA_KARTKA.md`](./CSAM_JEDNA_KARTKA.md) |
| Jak agenci gadali ze sobą bez interfejsu? | [`JAK-DZIALA-SKRZYNKA.md`](./JAK-DZIALA-SKRZYNKA.md) |

## Czego tu nie ma

- **Logów przebiegów, list wsadowych kolejek pchania, zrzutów `issues*.json`
  i `.diff`** — zostały na dysku. To wydruki, nie wiedza.
- **Spisu Dockera i runnerów tej maszyny** — dotyczył wyłącznie tamtego systemu
  i wyliczał zasoby projektów niezwiązanych z Kuking.
- **83 z 95 skryptów `.sh`** — jednorazowe polecenia wycięte pod konkretną
  gałąź konkretnego dnia. W [`narzedzia/`](./narzedzia/) jest dwanaście, które
  niosą w komentarzach zmierzoną pułapkę albo opisują procedurę; **każdy ma
  w pierwszej linii zdanie, czego wymaga do działania.**

## Zanim uruchomisz cokolwiek z `narzedzia/`

Nie uruchamiaj. Przeczytaj nagłówek. Jedenaście z dwunastu skryptów zakłada WSL
tamtej maszyny i nie da się ich odtworzyć w chmurze bez przepisania. Wyjątkiem
jest [`narzedzia/kolizje-w-kolejce.sh`](./narzedzia/kolizje-w-kolejce.sh), który
potrzebuje tylko klonu repozytorium i niczego nie zmienia.

Cztery skrypty (`testuj.sh`, `kolejka9.sh`, `kolejka11.sh`, `sprzataj-flote.sh`)
zawierają lokalne `kuking/kuking` do klastra PostgreSQL na `127.0.0.1:55439`.
To nie jest sekret produkcyjny — tamten klaster nasłuchiwał tylko na pętli
zwrotnej tamtej maszyny i nigdzie indziej nie istnieje. **Nie przenoś tej pary
do żadnego środowiska, które wystawia bazę na zewnątrz.** Żadnego innego
poświadczenia w tym katalogu nie ma — sprawdzone przed wniesieniem.
