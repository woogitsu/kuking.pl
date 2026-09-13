# Konstytucja marki Kuking

Wersja 1.2, 13 września 2026. Kierunek pełnego portu zaakceptowany przez
właściciela i wdrożony jako Alfa 0.9 (PR #488, D-206).
Ten dokument opisuje standard marki. Zakres dowodów oraz ograniczenia
odbioru zawiera [raport Alfa 0.9](../design/WERYFIKACJA_ALFA_09.md).

## Rdzeń

Kuking łączy ludzi przez to, co gotują. Zdjęcie i kilka słów są pełnoprawnym
wpisem. Nie trzeba przygotować przepisu, żeby uczestniczyć. Przepis, zeszyt
i „Ugotowałem” rozwijają relację wokół codziennego gotowania.

Prostota wygrywa z liczbą funkcji, zrozumiałość z modnym układem, a prawdziwe
wykonanie z polubieniem. Strumień obserwowanych jest chronologiczny.
Nie tworzymy publicznych rankingów ani presji na codzienne publikowanie.

## Charakter

Nowoczesny, spokojny, konkretny i ciepły. Domowa kuchnia bez kostiumowej
nostalgii. Profesjonalny wygląd nie wymaga perfekcyjnych potraw.
Czytelność jest standardem produktu; nie oznaczamy go jako „dla seniorów”.

Nie zakładamy umiejętności ani preferencji człowieka na podstawie wieku.
Persony i pomysły wymagają badania; nie są wynikami badania.

## Głos

Mówimy po partnersku, czasownikami i krótkimi zdaniami. Najpierw pokazujemy
działanie, potem potrzebne wyjaśnienie. Nie komentujemy własnego tonu i nie
tłumaczymy użytkownikowi decyzji projektowych przy kontrolce.

| Miejsce | Sposób pisania |
|---|---|
| Zaproszenie i strona powitalna | Ciepło, z miejscem na charakter marki |
| Strumień, przepis i profil | Ciepło i konkretnie |
| Formularz i ustawienia | Rzeczowo: co wpisać, wybrać i zapisać |
| Błąd, bezpieczeństwo, moderacja i prawo | Poważnie, bez żartu i gry nazwą |

Obowiązują gotowe teksty i rozstrzygnięcia w [COPY_STYLE.md](COPY_STYLE.md)
oraz [GLOS_MARKI.md](GLOS_MARKI.md). Ten dokument nie zmienia nazw czynności:
„Ugotowałem”, „Zapisuję”, „Napisz kilka słów”, „Opublikuj”.

## Nazwa i znak

Logotyp: **KuKing.pl**. „Ku” i „.pl” są grafitowe, „King” czerwone.
W ciemnym motywie stosujemy jasne odpowiedniki tych kolorów.
W tekście bieżącym używamy komponentu `x-kuking-word`, zgodnie z D-145.
W tytule strony, opisie dla czytnika, metadanych i tekście bez formatowania:
„Kuking”. Nazwa występuje raz w akapicie, nagłówku lub punkcie listy.

Znak to **garnek, pokrywka w formie korony i uśmiech**. Zachowujemy istniejący
komponent `resources/views/components/kuking-mark.blade.php`. Korona jest
grą z nazwą, nie rangą użytkownika. Nie zamieniamy znaku na literę K.
Przy logotypie znak jest dekoracyjny dla czytnika; samodzielnie dostaje nazwę.

## System wizualny

Neutralne jasne tło, białe powierzchnie, grafitowe pismo i czerwony akcent.
Kolory mają role: czerwień wyróżnia czynność lub wybór, nie zastępuje opisu
błędu ani fokusu. Ciemny motyw jest świadomym wyborem użytkownika. Ciemny
kafel publikacji w jasnym motywie jest elementem kompozycji, nie zmianą motywu.
Wartości palety pozostają w `resources/css/tokens.css`; ich opis i sposób
pomiaru są w [NOWY_STYL.md](../design/NOWY_STYL.md).

### Rama i nawigacja

Nagłówek jest odrębną, zaokrągloną powierzchnią odsuniętą od krawędzi okna.
Na komputerze mieści znak, nawigację i dostęp do konta. Zwykły użytkownik
nie ma dodatkowego lewego paska menu. Treść ma wyraźną oś czytania;
opcjonalna prawa kolumna mieści istniejące informacje pomocnicze.
Panel moderacji zachowuje osobny tryb nawigacji i kontrolę uprawnień.

Na telefonie treść przechodzi do jednej szerokiej kolumny. Dolna nawigacja
jest pływającą, zaokrągloną powierzchnią z pozycjami **Start, Szukaj, Dodaj,
Moje, Profil**. Dodaj wyróżnia ciemny znak plus z widocznym podpisem.
Aktywna pozycja pozostaje rozpoznawalna także bez koloru. Nagłówek i pasek
nie mogą zasłaniać treści ani elementu z fokusem; odstęp uwzględnia ich
rzeczywistą wysokość oraz bezpieczny obszar urządzenia. Przy dużym tekście
pierwszeństwo ma dostęp do całej treści, nawet kosztem przyklejenia paska.

### Powierzchnie i komponenty

| Rodzina | Standard |
|---|---|
| Kafel publikacji na Start | Grafitowa powierzchnia, jasny tekst, wyraźna czynność publikacji; zachowany pomocniczy tekst i cała powierzchnia klikalna |
| Nagłówek profilu | Grafitowa powierzchnia, prawdziwy awatar, czytelna nazwa i opis; czynności zgodne z uprawnieniami |
| Karty wpisów i przepisów | Jasne w jasnym motywie, z miękkim cieniem i oddechem; promień głównych powierzchni około 24–26 px przy skali domyślnej |
| Zdjęcia | Dominują w karcie, z łagodnie zaokrąglonymi narożnikami; kadrowanie respektuje istniejący tryb zdjęcia |
| Formularze i zeszyty | Spójne powierzchnie i nagłówki, wyraźnie obrysowane pola, etykiety nad polem |
| Przyciski | Zaokrąglenie około 14 px, wyraźny stan interakcji i widoczny opis; akcja główna odróżniona od pomocniczej |
| Stany puste i komunikaty | Ta sama rama i rytm, konkretna informacja oraz działająca droga dalej |

Cień oddziela powierzchnie; nie zastępuje wymaganego kontrastu obramowania
kontrolki. W ciemnym motywie karty korzystają z odpowiednich tokenów.
Zaokrąglenia nie mogą obcinać menu, podpisów, fokusu ani powiększonego tekstu.

### Typografia i czytelność

Inter pozostaje lokalnym fontem z systemowym stosem zastępczym. Nagłówki,
przyciski, metadane i treść używają jednej rodziny; nie przenosimy historycznej
propozycji szeryfu z prototypu. Nagłówek ma wyraźną skalę i mocną wagę,
a wielkość dostosowuje się do szerokości. Długie zdania zachowują spokojny
rytm, z wysokością linii około 1,55–1,65.

Tekst podstawowy i pola mają minimum **18 px**, ważne kontrolki minimum
**48 px**. Ustawienia czytelności i jawne wyjątki z AGENTS.md pozostają w mocy.
Nie uzyskujemy podobieństwa do szkicu przez zmniejszenie tekstu, ograniczenie
zoomu ani ukrycie nazw. Ważna czynność ma widoczny opis; wyjątek trzech kropek
na karcie wpisu nie rozszerza się na pozostałe przyciski.

Układ pozostaje używalny przy **320 px** szerokości oraz tekście przeglądarki
powiększonym do **200%**. Przy powiększaniu rośnie w dół, a długie nazwy
zawijają się w dostępnej szerokości. Kolumna treści nie może kurczyć się do
pionowego ciągu liter. Nie maskujemy błędu siatki przez obcięcie całej strony.

## Zakres przeniesienia

Pełny port obejmuje wspólną ramę oraz wszystkie rodziny ekranów aplikacji:
wejście, społeczność, szukanie, publikację, przepisy, zeszyty, profile,
powiadomienia, ustawienia, pomoc, bezpieczeństwo i moderację. Plan pokrycia
oraz kryteria odbioru są w [PORT_PROJEKTU.md](../design/PORT_PROJEKTU.md).
Sama zmiana tokenów i powitania nie stanowi wykonania tego zakresu.

Przenosimy język wizualny do istniejących komponentów Blade. Trasy,
autoryzacja, widoczność treści, formularze, powiadomienia i dane zachowują
swoje znaczenie. Statyczne akcje, przykładowe osoby i liczniki ze szkicu nie
stają się funkcjami produkcyjnymi przez skopiowanie HTML.

## Fotografia i zaufanie

Pokazujemy prawdziwe jedzenie i ludzi, z zachowaniem autorstwa i uprawnień.
Zdjęcie wygenerowane ani stockowe nie jest dowodem ugotowania przepisu.
Nie tworzymy fikcyjnych kont, komentarzy, ocen, wykonań ani liczników ruchu.

Przepisy redakcyjne mogą rozpocząć archiwum, ale publikujemy je z jawnego
konta redakcji, po rzeczywistym przygotowaniu i sprawdzeniu. Pomoc AI przy
szkicu nie zastępuje gotowania. Szczegóły: [SEO_START_REDAKCYJNY.md](SEO_START_REDAKCYJNY.md).
Kuking pozostaje bez reklam i opłat za korzystanie, zgodnie z D-146.

## Relacja z dokumentacją

AGENTS.md pozostaje źródłem zasad projektu. Konstytucja opisuje kierunek
marki; COPY_STYLE i GLOS_MARKI rozstrzygają wykonanie tekstów. Aktualny kod
tokenów oraz NOWY_STYL opisują paletę; PORT_PROJEKTU opisuje pełny zakres
przeniesienia układu i komponentów. Historyczne prototypy
nie są specyfikacją backendu ani dowodem działania funkcji.

Przed scaleniem wymagane są testy aplikacji, pomiar dostępności oraz kontrola
telefonu przy szerokości 320, 360, 390 i 414 px oraz czcionce przeglądarki
powiększonej do 200%. Brak wyniku zapisujemy jako brak weryfikacji.
