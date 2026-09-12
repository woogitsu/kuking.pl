# Konstytucja marki Kuking

Wersja 1.1, 12 września 2026. Kierunek zaakceptowany przez właściciela;
integracja z aplikacją jest przedmiotem tego pakietu zmian.

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

## Obraz i układ

Neutralne jasne tło, białe powierzchnie, grafitowe pismo i czerwony akcent.
Ciemny motyw jest świadomym wyborem, a nie automatycznym odwróceniem kolorów.
Dokładne role i sposób sprawdzania opisuje [NOWY_STYL.md](../design/NOWY_STYL.md).

Inter pozostaje lokalnym fontem z systemowym stosem zastępczym. Nie dokładamy
drugiej rodziny fontów. Tekst podstawowy i pola mają minimum 18 px przy
domyślnej skali; główne kontrolki co najmniej 48 px. Ustawienia czytelności
i świadome wyjątki z AGENTS.md pozostają w mocy.

Na telefonie treść ma szeroką, pojedynczą kolumnę. Przy powiększaniu tekstu
układ rośnie w dół. Nie maskujemy przepełnienia przez obcięcie strony.
Nawigacja: Start, Szukaj, Dodaj, Moje, Profil. Ważna czynność ma widoczny opis;
wyjątki pozostają wyłącznie tymi nazwanymi w AGENTS.md.

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
tokenów oraz NOWY_STYL opisują przenoszoną paletę. Historyczne prototypy
nie są specyfikacją backendu ani dowodem działania funkcji.

Przed scaleniem wymagane są testy aplikacji, pomiar dostępności oraz kontrola
telefonu przy szerokości 320, 360, 390 i 414 px oraz czcionce przeglądarki
powiększonej do 200%. Brak wyniku zapisujemy jako brak weryfikacji.
