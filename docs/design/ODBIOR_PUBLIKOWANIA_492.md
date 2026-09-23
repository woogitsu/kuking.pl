# Odbiór publikowania i zapisów — #492, Alfa 0.29

## Stan i zakres

Lokalny odbiór 14 września 2026 na `bd852701f6e5471fc6325c9b9e20b3f201b607c4`; końcowa instrukcja i potwierdzenia Ugotowałem mają dodatkowy odbiór po poprawce #547 w tym samym pakiecie PR #546. Nie przypisywać wcześniejszych zrzutów Ugotowałem poprawionej treści. [Poprawka pierwszego wpisu](PIERWSZY_WPIS_545.md), [poprawka Ugotowałem](KOMUNIKAT_UGOTOWALEM_547.md).

Wdrożenie tego pakietu jeszcze niepotwierdzone. Pełny port: **CZĘŚCIOWO**.

## Rzeczywiste przebiegi

| Przebieg | Wynik | Ograniczenie |
|---|---|---|
| Zdjęcie → opis 4001 znaków → błąd → skrócenie → publikacja | Zdjęcie i opis zachowane, powstał prywatny wpis | Bez błędnego formatu pliku i wszystkich etapów kolejki |
| Edycja wpisu → opis 4001 znaków → błąd → poprawienie → zapis → ponowne otwarcie | Poprawiony tekst odczytany, widoczność pozostała prywatna; link błędu prowadzi do pola | Nie jest to test zamiany pliku: taka funkcja nie występuje na tym ekranie |
| Zdjęcia opublikowanego wpisu z jednym zdjęciem | Prawdziwy stan bez niepotrzebnego wyboru kolejności i wyglądu | Wiele zdjęć i przestawianie nadal osobnym odbiorem |
| Prosty przepis → nazwa Ab → błąd → poprawienie → publikacja | Prywatny przepis bez opcjonalnego zdjęcia i składników; zachowany krok przygotowania | Nie rozszerzać wyniku na wszystkie błędy pełnego formularza |
| Pełny formularz → sam tytuł i prywatność → Zapisz szkic | Szkic zapisany, następnie odczytany jako draft/private w bazie | Proste `/dodaj/przepis` nie oferuje zapisu szkicu |
| Ponowne otwarcie szkicu → kreator → składnik → przygotowanie, zdjęcie kroku i minuta → podgląd → publikacja | Rzeczywiste przejście kroków; zapisane ingredient, step, zdjęcie i publikacja/private | Nie sprawdzono każdej operacji dodawania/usuwania wierszy |
| Ugotowałem → 2001 znaków → błąd → skrócenie → Wyślij | Powstało wykonanie własnego przepisu; zero powiadomień zgodnie z zasadami | To nie dowód powiadomienia innego autora. Ujawniło błąd tekstu #547 |

Odczyt bazy po publikacji: dwa przepisy published/private, pierwszy 0 składników/1 krok, drugi 1 składnik/1 krok. Początkowo jedno wykonanie i zero powiadomień. Dalsze kontrolowane wysłania #547 zwiększyły liczbę lokalnych wykonań do sześciu; odtwarzanie tego samego klucza nie zwiększało liczby. Nie usuwano fixture — są potrzebne do następnych odbiorów.

## Geometria, klawiatura i ogląd

- **240 pomiarów**: pięć zwykłych widoków (edycja wpisu, proste dodawanie przepisu, pełne dodawanie, Ugotowałem, zdjęcia z jednym zdjęciem) × 48 konfiguracji. CSS 320/360/390/414/768/1440, oba motywy, tekst 100/140%, rzeczywisty zoom 100/200% przez API karty Chromium i odczyt jego wartości. Brak poziomego przepełnienia; rzeczywisty font 18/25,2 px. Axe w 20 wąskich wariantach bez naruszeń. To nie pomiar rozmiaru każdego celu dotykowego ani pełnego Tab formularzy.
- **12 wariantów błędów**: edycja wpisu, prosta publikacja przepisu i Ugotowałem; CSS 320/tekst 140%, oba motywy i zoomy. Podsumowanie otrzymało fokus. Tab dotarł do jego linku, widoczne wiersze linku nie były zasłonięte, Enter przeniósł fokus do wskazanego pola; axe bez naruszeń.
- W pierwszym pomocniczym pomiarze środek prostokąta wielowierszowego linku trafiał w odstęp między wierszami. Ogląd i hit testing wszystkich fragmentów wykazały widoczny link. Poprawiono pomiar, nie aplikację; nie osłabiono wymogu widoczności żadnego wiersza.
- Rzeczywiste przebiegi dodatkowo zapisują 8 pomiarów edycji, 12 przepisu/Ugotowałem oraz 8 kreatora, przy CSS 320 i tekście 140% w obu motywach. Nie sumować ich z macierzą jako jednorodnego pełnego audytu.
- Obejrzano każdy odmienny etap przebiegów oraz reprezentatywne szerokie widoki pięciu formularzy. Zrzut całej długiej strony zawiera stałą nawigację w aktualnym położeniu okna; sam taki obraz nie dowodzi trwałego zasłonięcia tekstu.

Szerokość fizyczną okna w macierzy mnożono przez zoom, aby zachować wskazaną szerokość CSS. Nie jest to fizyczne okno 320 px z obszarem CSS 160 px. Zrzuty CDP w emulowanym małym viewport czasem zawierają pusty margines lub niepełną wysokość powierzchni. Dla końcowego długiego potwierdzenia #547 użyto rzeczywistego rozmiaru okna Chromium, bez emulacji viewport, i obejrzano pełny widok przy zoomie 200%.

## Dowody i dalsze zadania

Wyniki w `docs/design/evidence/publikacja492/`: `matrix.json`, `errorzoom.json`, `edycja.json`, `recipe-flow.json`, `draft-flow.json`. Lokalne reproduktory i PNG: `output/publikacja492/`, skrypty `output/edycja492.mjs`, `recipe492.mjs`, `draft492.mjs`, `draft-reopen492.mjs`, `wizard492.mjs`, `matrix492.mjs`, `errorzoom492.mjs`. Skrypty publikujące nie są bezpieczne do bezrefleksyjnego powtarzania — fixture już istnieją; część ma jawny znacznik zapobiegający duplikacji.

Historyczna lista braków tego przebiegu obejmowała błędne pliki, wielozdjęciową kolejność/wygląd, pełny Tab i radio, edycję przepisu, 419/429, składniki/zdjęcie/minutnik oraz Wyszło. Późniejsze częściowe odbiory są już zapisane w [aktualnej macierzy](MACIERZ_KOMPLETNOSCI_517.md) i raportach ODBIOR_ZDJEC_492.md, ODBIOR_EDYCJI_WYSZLO_492.md, ODBIOR_ODZYSKIWANIA_492.md oraz ODBIOR_GOTOWANIA_UZUPELNIENIE_492.md. Ta historyczna lista nie jest poleceniem powtórzenia całego zakresu. Nadal nie odebrano fizycznej klawiatury ekranowej, czytnika ekranu i Wake Lock; pozostałe ograniczenia mają osobne wiersze macierzy.

Baza wyłącznie `kuking_publikacja492` na 55439, serwer 8033. Bez zapisów na produkcji, zewnętrznej poczty i zmiany limitów. Sama hipoteza limitu podczas prób #547 nie jest potwierdzonym przypadkiem 429.
## Dostarczenie pakietu

PR546, head8e4da2f, scalony443da38. CI PR i main10/10success, PHP3807/76412. [Potwierdzony odbiór produkcji Alfy0.29](ODBIOR_PRODUKCJI_ALFA_029.md) rozdziela wdrożenie od ograniczonego oglądu zalogowanej strony. Historyczny opis przygotowania wyżej nie jest bieżącym statusem wysyłki.
