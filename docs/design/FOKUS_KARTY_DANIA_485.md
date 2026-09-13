# Fokus karty dania — #485

## Ponowna weryfikacja — 13 września 2026

W audycie Alfa 0.14 wykonano lokalnie 36 wariantów, rzeczywiste przejście
Tab oraz kliknięcia zdjęcia, opisu i wolnego obszaru karty. Trzy kontrole
ujemne źródłowego CSS wykryto; przywrócenie MD5 potwierdzono. Test przeszedł
również w [CI PR #499](https://github.com/woogitsu/kuking.pl/actions/runs/34759245908).
Zakres i ograniczenia odbioru opisuje
[raport Alfa 0.14](AUDYT_KOMPLETNOSCI_MARKI_ALFA_014.md).
Emulacji powiększonego fontu nie zaliczamy jako rzeczywistego zoomu przeglądarki.

## Przyczyna potwierdzona w przeglądarce

CI 34742989087, job 103685908785: /home i /szukaj przy 320 px.
Kontrolką była cała karta kuking-board-post-link, nie sama nazwa autora.

| Wariant | Wysokość linku | Dostępna przestrzeń |
|---|---:|---:|
| Tekst aplikacji 140% | 467,72 px | około 325 px między belkami |
| Czcionka przeglądarki 200% | 834,75 px | okno 740 px, dolna belka zaczyna się na 499,63 px |

Zwiększanie scroll-padding nie zmieści takiego elementu. Nie skracamy
nazwy ani notatki gospodarza i nie maskujemy pomiaru obcięciem strony.

## Zmiana

Wiersz zachowuje jeden odnośnik do wpisu. Odnośnik jest na widocznej pełnej
nazwie, a pseudoelement rozciąga jego obszar kliknięcia na zdjęcie, opis
i wolne miejsce. Fokus obejmuje rzeczywistą, krótszą kontrolkę, której
prostokąt przeglądarka przewija do widoku. Nazwa dostępna zawiera również
kontekst dania. Podgląd i notatka nadal są widoczne i dostępne.

## Odbiór

Status sprawdzony 13 września 2026: scalone w PR #494 i wdrożone jako
Alfa 0.11, commit `dbd1cb920f872233f8cc8f240f94273f26f634e8`.
CI #928 (`34749126034`) zakończył się sukcesem; test fokusu przeszedł.
Railway deployment `ba872757-91a9-4850-97bb-1e9dceaba112` ma status SUCCESS,
a produkcyjna stopka pokazuje Alfa 0.11 / dbd1cb9. Nie oznacza to ręcznego
sprawdzenia wszystkich danych produkcyjnych.

Pomiar obejmuje 320/360/414 px, tekst 100%/140% i czcionkę przeglądarki
200%, oba motywy, /home i /szukaj. Wymaga pełnego przejścia Tab, znalezienia
linków i całej kontrolki w oknie bez pokrycia przez belki. Oddzielne
rzeczywiste kliknięcia zdjęcia, opisu i wolnego miejsca muszą otworzyć wpis.

Trzy kontrole ujemne zmieniają źródło CSS: kontrolka o wysokości 900 px,
wyłączenie rozciągnięcia kliknięcia i ukrycie jednej z kart. Zbiór
odwiedzonych odnośników musi odpowiadać wszystkim oczekiwanym kartom,
w tym fixture z najdłuższą nazwą. Wymagają porażki właściwego
pomiaru i sukcesu po przywróceniu przez cp, rebuild i porównaniu MD5.
Testy DOM nadal wymagają jednego linku i poprawnego rodzicielstwa kolumn.

Fixture używa aktualnie dozwolonej nazwy (limit 40 znaków). Nie jest
dowodem ręcznego odbioru wszystkich historycznych nazw w bazie, której
kolumna dopuszczała wcześniej 100 znaków.
