# Fokus profilu i panel Wygląd — stan końcowy

Lokalny odbiór istniejącego konta demonstracyjnego ania, bez odtwarzania bazy i bez pełnych testów PHP. Chromium: rzeczywisty zoom karty 2, viewport CSS 320×740, tekst 140%, reduced motion, oba motywy.

## Przyczyna i poprawka

Potwierdzono rzeczywisty błąd: focusin panelu Wygląd wykonywał scrollBy(0,84.125), przesuwając górny obrys linku zmiany awatara pod sticky topbar. Dowód pierwotnego FAIL: result.json i result.png. Bez tego przewijania widget zakrywał prawy dolny odcinek obrysu i podpis (first-without-scroll.png); cztery środkowe punkty starego oracle tego nie wykrywały.

Handler przewija teraz tylko wtedy, gdy cały chroniony prostokąt pozostanie poniżej nagłówka. W przeciwnym razie widget ustępuje do przepływu dokumentu. Sprawdza również wynik rzeczywistego przewinięcia, ograniczanego końcem dokumentu. Powrót po przewinięciu i własnym fokusie zachowuje dostęp klawiaturą. Podczas kliknięcia powrót z przepływu czeka na click, aby pointerup nie trafił w body.

## Wyniki

- final.txt: pełny istniejący oracle /@ania 53/53 w obu motywach, bez obniżania progów.
- Trwały helper sprawdzFokusProfilu w scripts/szybki-wyglad.mjs, wywoływany przez istniejącą rodzinę zoom: rzeczywisty Tab, wszystkie cztery pełne odcinki obrysu względem widgetu, click bez force ze stanu przepływu, Shift+Tab, przewinięcie i powrót fixed, dojście Tab, Enter i Escape.
- final-false-avatar.png / final-true-avatar.png oraz final-*-widget.json/png: końcowy odbiór profilu i osiągalnego przycisku. Zrzuty przycisku w obu motywach obejrzane.
- no-wait.json: 4 warianty natychmiastowego fokusu po ostatniej poprawce.
- panel-scroll.json: 12 wariantów i 108 pozycji przewijania po ostatniej poprawce.
- negative.json i negative-*.txt / restored-*.txt: dwa fizyczne negatywy rzeczywistego JS w kopii natywnej (niebezpieczny scroll oraz przeniesienie podczas stisku). Każdy exit1, odtworzenie identycznego MD5 i mtime, rebuild, następnie oba motywy PASS. Backup poza repo.

## Ograniczenia dowodów

Playwright page.screenshot przy zoom2 i dalekim scrollY dawał pusty wycinek mimo poprawnych rects/hit-test. fixed-widget-tab.png jest wyłącznie diagnostyką tego ograniczenia, NIE dowodem PASS. Końcowe final-*-widget.png korzystają z bezpośredniego Page.captureScreenshot (fromSurface:true, captureBeyondViewport:false), tak samo jak istniejący oracle zoom. Nie wykonano pełnej macierzy portu, testów PHP ani odbioru produkcji.
