# Szersze menu Konto — #555, Alfa 0.31

## Problem i zmiana

Zrzut właściciela pokazywał menu o szerokości przycisku „Konto”: wylogowanie
zawijało się na dwa wiersze, a pozycja moderatora na trzy. W app.css lista
miała min-width i max-width 100% szerokości przycisku. Dawny komentarz
uzasadniał to szerszym, historycznym napisem „Moje konto”.

Lista ma teraz szerokość do 22rem, ograniczoną szerokością okna z rezerwą
64px. Sam przycisk nie jest poszerzony. Automatyczny lewy margines utrzymuje
Konto po prawej także po zawinięciu belki. W dotychczasowym wariancie
statycznym dla bardzo wąskiego/niskiego okna menu korzysta z dostępnej
szerokości paska. Pozostają natywne details, przewijanie i formularz POST
wylogowania z CSRF. Nie zmieniono uprawnień ani obsługi akcji.

## Wykonane kontrole lokalne

- Build Vite i 72 pary kontrastu: poprawnie.
- MenuKontaPrzyAwatarzeTest oraz WazneAkcjeMajaPrawdziwyFormularzTest:
  19 testów / 149 asercji, poprawnie.
- scripts/menu-konta.mjs: 48 konfiguracji — szerokości CSS 320, 360, 390,
  414, 768, 1440; oba motywy; tekst aplikacji 100/140%; zoom 100/200%.
  Rzeczywisty zoom ustawia i odczytuje chrome.tabs; fizyczne okno przy 200%
  ma dwukrotną szerokość, aby zachować podany viewport CSS. Wysokość CSS600.
- Każdy wariant: menu w ekranie, bez przewijania poziomego dokumentu,
  cztery pozycje moderatora, CSRF, cztery rzeczywiste przejścia Tab,
  widoczny fokus i trafienie środka kontrolki, minimum48px wysokości.
- Dodatkowo24 warianty wysokości CSS360: szerokości320/390/768, oba zoomy,
  motywy i skale. Obejmują statyczne menu w niskim oknie. Obejrzano także
  320/zoom200/jasny/tekst140 po dojściu do ostatniej pozycji; naturalne
  przewinięcie odsłania fokus. Pomiar rozpoczyna Tab od przycisku Konto,
  nie dowodzi wcześniejszej drogi do niego ani otwarcia przez Enter.
- Przy1440 menu ma352px, a wylogowanie mieści się w jednym wierszu.
  Przy320 menu ma256px i mieści się od x40 do296.
- Obejrzano końcowe zrzuty 320/zoom200/jasny, 320/zoom100/ciemny,
  1440/zoom200/ciemny i1440/zoom100/jasny przy tekście140%.
  Pierwszy zapis PNG przy zoom200 był ucięty przez klip narzędzia;
  końcowy przebieg zapisuje cały fizyczny viewport przez CDP.
- Rzeczywista kontrola ujemna app.css: przywrócenie szerokości przycisku
  powoduje MENU_ZBYT_WASKIE i kod1. Po przywróceniu bajtów i mtime,
  weryfikacji MD5 i ponownym buildzie te same cztery warianty przechodzą.

Dowody: [wyniki](evidence/menu555/wyniki.json),
[niskie okna](evidence/menu555/niskie-okna.json),
[kontrola ujemna](evidence/menu555/negatyw.json).
Parametry uruchomienia: BASE_URL lokalnego home, STORAGE_STATE lokalnej
sesji moderatora, CHROMIUM_PATH i OUTPUT_DIR. QUICK ogranicza odbiór do1440
i zoom100, pozostawiając oba motywy oraz obie skale tekstu.
HEIGHT i WIDTHS umożliwiają odrębny pomiar niskich okien. W pierwszym JSON
viewport oznacza szerokość ekranu, a width szerokość menu; późniejszy zapis
ma również jawne viewportWidth/viewportHeight. Kontrolę ujemną powtórzono
na końcowym skrypcie. Niezależne review nie wskazało blokera CSS.

## Granice i dostarczenie

Pomiar dotyczy lokalnego serwera8033 i PostgreSQL55439, bazy
kuking_publikacja492. Na czas pomiaru istniejące jawne konto testowe dostało
rolę moderatora; poprzednia rola została zachowana poza repo i przywrócona.
Nie zmieniano kont produkcyjnych. Nie wysyłano wylogowania ani innych akcji.
Lokalna kolejka moderatora była pusta: nie twierdzimy, że wykonano pomiar
wszystkich wartości licznika. Nie testowano fizycznego telefonu ani czytnika.

Wyniki powyżej nie są deklaracją ukończonego CI ani wdrożenia.
Końcowy SHA i odbiór produkcji należy odczytać z PR powiązanego z #555.
