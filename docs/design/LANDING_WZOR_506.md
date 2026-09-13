# Publiczna strona według wizualizacji — Alfa 0.17

13 września 2026, issue #506, D-208. Baza kodu:
`94a7b436180d64480bb46feca9bc150af8ca116c`.
Stan: poprawka scalona i wdrożona, odbiór publicznego fragmentu wykonany
13 września 2026. Granice odbioru są opisane poniżej.

## Źródło i różnice

Właściciel wskazał [fragment wizualizacji](references/landing-wzor-506.png)
z otwartymi trzema kolumnami oraz ciemnym blokiem bezpośrednio pod nimi.
Produkcja miała białe kafle, mały tytuł „Jak działa” i tablicę pomiędzy
krokami a blokiem „Ugotowałem”. Wspólna paleta nie oznaczała zgodności
kompozycji. Widok `pages/landing.blade.php` potwierdził tę różnicę.

Poprawka: duże nagłówki, kolumny 01–03 z rzeczywistymi linkami, ciemny
zaokrąglony blok z publicznym zdjęciem i podpisem, a następnie istniejąca
tablica i wpisy. Na małym ekranie kolumny układają się pionowo. Nie
przenosimy ucięcia trzeciej kolumny z przesłanego kadru do aplikacji.

Fotografia pochodzi z pierwszego elementu `HeroKolaz::doKolazu()` —
z dotychczasowym filtrem publiczności, aktywnego autora i stanu `ready`.
Podpis oznacza autora zdjęcia, nie wykonanie przepisu. Gdy kolekcja jest
pusta (również przy mniej niż czterech dopuszczonych zdjęciach), zostaje
blok tekstowy. Nie ma stocku, nowego konta ani fikcyjnego powiadomienia.

## Regresje

- PHP: **41 testów / 196 asercji** razem z istniejącymi regresjami kolażu.
  Kolejność sekcji, trzy akcje, puste zdjęcie, prawdziwe `HeroPick`, autorstwo,
  wycofanie po zmianie wpisu na prywatny i medium na `pending`.
- Trzy kontrole ujemne prawdziwego Blade: błędny link, `thumb` zamiast
  `feed`, fikcyjny autor. Każda wykryta; kopia poza repo, MD5 i mtime
  przywrócone, ponowne 41/196 poprawne.
- `scripts/port-projektu.mjs`: **224 warianty poprawne**, w tym 36 nowych
  pomiarów strony publicznej: 320, 360, 390, 414, 768 i 1440 px, oba motywy,
  tekst 100%, 140% i emulacja podwojenia bazowej czcionki.
  Pomiar obejmuje otwarte kolumny, wielkość nagłówka, ciemny blok
  i obecność obrazu. Trzy kontrole ujemne prawdziwego CSS wykryły każdy
  sabotaż; odtworzono MD5 `9dcf3b7a9d32e8bc8e3d8c4cce68646f`.
  MD5 przywróconego Blade: `80dd0e4ffb455911c61c2dbfc5562475`.
  Obejrzano lokalne zrzuty nowych sekcji; obraz logo w danych skanera
  jest atrapą testową, więc nie potwierdza wyglądu zdjęcia produkcyjnego.

## Odbiór i ograniczenia

Niezależny audyt: rzeczywiste dojście klawiszem Tab od początku strony
na 320×900 px, tekst 100% i 140%. Wszystkie trzy linki kroków oraz CTA
mają widoczny, niezasłonięty fokus (8/8). Przy 140% między nagłówkiem
a aktywnym linkiem pozostaje ponad 198 px. Zrzut całego długiego elementu
może zawierać nałożony nagłówek w miejscu przewinięcia; nie jest to dowód
zasłonięcia aktywnego celu. Obejrzano także zrzuty rzeczywistego Tab.

Pełny niezależny `dostepnosc.mjs` został przerwany przy przekazaniu:
część axe bez zgłoszeń, pomiary układu dotarły do 414 px / 140%.
Nie jest to pełny wynik pozytywny. Log lokalny: `output/landing506-a11y.log`.
Wcześniejszy przebieg ze starym buildem nie jest dowodem odbioru.
Przerwany pomiar lokalny nie jest wynikiem CI: późniejszy pełny przebieg
CI wykonał skaner do końca, z wynikami podanymi niżej.
Podwojenie bazowej czcionki nie jest rzeczywistym zoomem przeglądarki.
Nie utożsamiamy tej poprawki z pełnym odbiorem wszystkich stanów portalu.

## Potwierdzony odbiór CI i produkcji

- [PR #507](https://github.com/woogitsu/kuking.pl/pull/507), SHA
  `8e40a3249783c35491cc8d6e769b94ac63f278eb`, scalony jako
  `b8b3092d1111acb2aa24890361d6355b46661e87` po dziewięciu poprawnych
  zadaniach [CI](https://github.com/woogitsu/kuking.pl/actions/runs/34767396074).
- Odczytane logi tego CI: **3696 testów PHP / 74689 asercji**, wyścigi
  **5 / 44**, dostępność **axe44/44 i układ49/49**, zero naruszeń oraz
  zasłonięć fokusu. Czerwone próby wewnątrz logów są kontrolami ujemnymi,
  po których źródła przywrócono i wykonano poprawne próby.
- [CI main](https://github.com/woogitsu/kuking.pl/actions/runs/34769081844)
  i [Deploy](https://github.com/woogitsu/kuking.pl/actions/runs/34769667966)
  zakończyły się sukcesem. Pominiętego Deploy nie zaliczamy jako wdrożenia.
- Railway: deployment `7b03a02a-78be-4604-b668-12689a915398`, potwierdzony
  w panelu produkcji; odpowiadające zgłoszenie GitHub deployment
  `6423844736` ma SHA `b8b3092d1111acb2aa24890361d6355b46661e87` i status
  **success** z 13 września, **16:48:07 UTC**.
- Żywa anonimowa strona pokazuje **Alfa0.17 / b8b3092**. Sprawdzono
  **24 warianty**: 320/360/390/414/768/1440 px, oba motywy oraz tekst
  100/140%. Wszystkie HTTP200, trzy kroki, bez poziomego przepełnienia.
  Motyw i skala były ustawiane wyłącznie dla pomiaru renderu w sesji gościa;
  nie jest to test zapisu preferencji konta.
- Zdjęcie publicznego wpisu wczytane (naturalna szerokość większa od zera),
  podpis „Zdjęcie: Ewa Kapica.”. Obejrzano rzeczywiste zrzuty kroków oraz
  ciemnego bloku ze zdjęciem na komputerze i ciemnego wariantu mobilnego.
  Przy 320px/140% treść jest długa i zawija się; nie obcinano jej.
- CSS `app-BgCJc3rw.css` pobrany z produkcji ma SHA-256
  `e6fadd975a313e443ef113b92af193baeb1717499b0f56ce42c057d3715516dc`,
  identyczny z buildem w kontekście Docker. Inter jest lokalnym fontem,
  odczytane rozmiary tekstu:18px oraz25,2px.

Odbiór dotyczy wdrożenia aplikacji z PR507. Późniejsze zapisanie tego raportu
jest osobnym commitem dokumentacji. Pełna identyfikacja nadal ma luki
w innych kompozycjach: [audyt paczki](AUDYT_PACZKI_MARKI_508.md).
