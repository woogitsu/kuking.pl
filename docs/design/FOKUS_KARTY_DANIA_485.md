# Fokus karty dania — #485

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

Status: poprawka przygotowana, wyniki końcowego CI w toku.

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
