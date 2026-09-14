# Pierwszy wpis — prawdziwa podpowiedź, #545

## Stan

14 września 2026, gałąź `test/492-publikowanie-gotowanie`, baza `3a1584764637406676ff78ff7afa4b09df5f34b9`. Alfa 0.29 przygotowana lokalnie. CI, scalenie i wdrożenie jeszcze niepotwierdzone. Pełny port marki: **CZĘŚCIOWO**.

## Odtworzenie i poprawka

Na izolowanej instancji rzeczywisty formularz przyjął zdjęcie, odrzucił opis 4001 znaków i zachował zdjęcie oraz opis. Po skróceniu opisu utworzył prywatny wpis. Podpowiedź po pierwszym wpisie nadal mówiła „teraz idzie najszybciej” i zakładała posiadanie kolejnych zdjęć. To sprzeczne z D-114; test kolejnego wpisu nie wykonywał pierwszej gałęzi.

`resources/views/pages/posts/show.blade.php` mówi teraz: „To Twój pierwszy wpis. Kolejne zdjęcie dodasz przez ten sam formularz.” Zachowano link do dodawania i warunek widoczności wyłącznie dla autora. `COPY_STYLE.md` wymaga osobnego sprawdzenia obu stanów. Wersja i changelog: Alfa 0.29.

## Testy lokalne i kontrola ujemna

- Pint: dwa zmienione pliki PHP bez uwag.
- `TekstyMowiaPrawdeTest` i `WykonczenieProduktuTest`: **31 testów / 104 asercje**.
- Nowy test wykonuje rzeczywisty GET wpisu autora mającego jeden wpis i bada podpowiedź zawierającą link do dodawania. Dotychczasowe testy nadal obejmują kolejne wpisy i brak podpowiedzi u innej osoby.
- Dwie fizyczne mutacje rzeczywistego Blade w kopii wykonawczej: pełna stara treść z main oraz pominięcie gałęzi pierwszego wpisu. Każda oblała nowy test; po przywróceniu test przeszedł.
- Kopia poza repo: `/tmp/kuking545-negatywy-4n_3ysae/show.blade.php`. Przywrócony MD5 `a6c9e52b443183ddcfc3fbffc0ad2a3d`, mtime ns `1789412493124323300`. Po zmianie i przywróceniu czyszczono skompilowane widoki.
- Niezależny review kodu i regresji nie wskazał blokera. Test GET nie jest testem całego wysłania formularza; osobny rzeczywisty przebieg opisano wyżej.

[Wyniki kontroli ujemnych](evidence/publikacja492/negatywy545.json) i [końcowa geometria](evidence/publikacja492/final545.json).

## Przeglądarka

Końcowe źródła po poprawce: **48 konfiguracji** — CSS 320, 360, 390, 414, 768 i 1440 px; oba motywy; tekst 100/140%; rzeczywisty zoom 100/200% przez `chrome.tabs.setZoom` i odczyt `getZoom`. Fizyczny viewport mnożono przez zoom, aby zachować podaną szerokość CSS; nie jest to fizyczne 320 px pomniejszone do CSS 160 px. Brak poziomego przepełnienia; przycisk co najmniej 48 px wysokości.

Cztery warianty CSS 320 / tekst 140% (oba motywy i zoomy): rzeczywisty Tab **10/10 kontrolek głównej treści**, kontrola zasłaniania i axe bez naruszeń. Nie jest to pełny audyt WCAG ani wszystkich kontrolek formularza publikowania.

Zapisano osiem końcowych zrzutów; obejrzano reprezentatywne układy 320 jasny, 320 ciemny z zoomem, 1440 jasny i 1440 ciemny z zoomem. Dla jasnego 320/zoom 100% zrzut CDP zawiera pusty margines powierzchni przechwytywania; odczyt przeglądarki nadal potwierdza CSS 320 i scrollWidth 320. Nie uznano tego za przepełnienie aplikacji. Zrzuty lokalnie: `output/publikacja492/final545-*.png`.

Odrębnie błąd formularza sprawdzono przy 320 px / tekst 140% w obu motywach: fokus na podsumowaniu, brak poziomego przepełnienia i axe bez naruszeń. To nie ta sama macierz 48 konfiguracji.

## Izolacja i granice

Baza `kuking_publikacja492`, PostgreSQL 55439, lokalny serwer 8033. Powstał wyłącznie lokalny prywatny wpis i lokalne konto odbioru, z fotografią z dostarczonej wizualizacji. Wiadomości w pamięci, bez zewnętrznej wysyłki. Sesja poza repo; fixture pozostawiono do kolejnego odbioru edycji. Nie zmieniano danych produkcyjnych.

Nie sprawdzono tutaj błędnego pliku, wszystkich etapów przetwarzania zdjęć, edycji, publikacji przepisu ani wysłania Ugotowałem. Kolejkę uzgodniono w macierzy #492. Nie uznawać brakującego pomiaru za błąd aplikacji.
## Dostarczenie pakietu

PR546, head8e4da2f, scalony443da38. CI PR i main10/10success, PHP3807/76412. [Potwierdzony odbiór produkcji Alfy0.29](ODBIOR_PRODUKCJI_ALFA_029.md) rozdziela wdrożenie od ograniczonego oglądu zalogowanej strony. Historyczny opis przygotowania wyżej nie jest bieżącym statusem wysyłki.
