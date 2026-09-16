# Kolaż po wyczyszczeniu wyboru — #632

Status: poprawka lokalna, przed wymaganym hookiem, CI i odbiorem produkcji.
Wersja robocza: Alfa 0.47. PR #631 przygotowuje osobno Alfę 0.46;
przed scaleniem trzeba uzgodnić aktualny main i zachować oba changelogi.

## Przyczyna i zakres

HeroKolaz odrzucał cały wynik, jeśli miał mniej niż cztery zdjęcia.
Automat ograniczał ponadto kandydatów do 40 wpisów przed odrzuceniem wpisów
bez gotowych zdjęć. Desktopowy hero zachowywał dwie kolumny także bez kolażu.

Poprawka zachowuje zestawy 1–4, filtruje gotowość przed limitem kandydatów
i dopasowuje CSS do liczby zdjęć. Przy zerze zostaje jedna kolumna.
Filtry publiczności, aktywności autora, gotowości zdjęcia oraz limit dwóch
zdjęć jednej osoby pozostają. Tekst panelu opisuje faktyczny dobór:
najpierw po jednym zdjęciu osoby, następnie najwyżej po dwa.

## Wykonane kontrole

- Rzeczywiste PUT wyboru, DELETE wyczyszczenia i publiczny GET strony:
  regresja najpierw wykazała brak zdjęcia, następnie przeszła po poprawce.
- Dwie rodziny PHP: 31 testów / 88 asercji. Stany 0–4 oraz 41 nowszych
  wpisów bez mediów i 41 z mediami pending/processing/deleted.
- Fizyczne mutacje HeroKolaz: wymóg pełnej czwórki i usunięcie warunku ready.
  Obie wykryte. Kopia poza repo, przywrócone MD5 i mtime, dodatni przebieg.
- Vite i 72 pary kontrastu przeszły.
- Przeglądarka, rzeczywisty Laravel i osobna baza kuking_632_browser na 55439:
  0–4 zdjęcia × 320/360/390/414/768/1440 × dwa motywy × tekst 100/140%.
  120 konfiguracji: liczba kafli, brak overflow i geometria niepełnych zestawów.
- Fizyczna mutacja CSS usuwająca układ niepełnego kolażu: PARTIAL_GAP;
  przywrócenie MD5/mtime i 24 dodatnie konfiguracje jednego zdjęcia.
- Rzeczywisty zoom Chromium 200%: osiem konfiguracji obu motywów i skal
  przy efektywnej szerokości 320 i 720, potwierdzone getZoom=2 i DPR=2.
- Niezależne review kodu nie znalazło blokera; sugestię dodatkowych niegotowych
  zdjęć uwzględniono w regresji.

Dowody pomiarów i zrzuty: `docs/design/evidence/kolaz632/`.
Obrazy lokalnych fixture są techniczne, jednobarwne: dowodzą ładowania
i geometrii, nie stanowią oceny kadrowania prawdziwych zdjęć jedzenia.
Pierwszy próbny pomiar ze złą nazwą zmiennej skali został odrzucony;
końcowy używa data-text-scale. Przerwanego przebiegu przy równoczesnej
zmianie fixture nie zaliczono; końcowe raporty pochodzą z przebiegu szeregowego.

## Do zakończenia

Pierwszy hook zatrzymał wysyłkę: starszy test kompozycji wymagał ukrycia
fotografii po utracie czwartego zdjęcia. Teraz sprawdza dokładny kolejny
publiczny obraz i jego autora, brak prywatnego URL oraz brak ilustracji
po ukryciu wszystkich zdjęć. Lokalna baza miała Europe/Warsaw; wyłącznie
bazie tego zadania ustawiono UTC. Przeniesiono także z PR #631 korektę
normalizacji dokładnej trasy Livewire debug/minified i jej opis.
Celowany przebieg po korektach: 44 testy / 190 asercji.

Wykonano dodatkowy ogląd układów 1–4 z fotografią placków z dostarczonego
ZIP wzorcowego: 320 i 1440 px, osiem zrzutów `photo-*`. To jawnie lokalne
fixture, nie konta ani aktywności produkcyjne. Obraz wypełnia kafle,
podpis jest czytelny, nie występują puste pola siatki.

Pełny hook, CI, scalenie i odbiór
wdrożenia. Produkcja nie została zmieniona tym pakietem. Cały port marki
pozostaje CZĘŚCIOWO. Zakres kandydatów panelu (60) i różnorodność autorów
w automatycznej puli 40 to istniejące ograniczenia, poza tym zgłoszeniem.
