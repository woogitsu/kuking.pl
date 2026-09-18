# Cele linków — #667

WIP, jeszcze bez push, PR i wdrożenia. Baza 97d32ea8690d6e88bb5b46f756416eb059243370.

Rzeczywisty render Laravel potwierdził trzy błędy: okruszek „Przepisy”
prowadził do tablicy wpisów, a dwa puste stany zeszytu pod „Poszukaj przepisów”
również otwierały tę tablicę. Początkowa regresja: 4 testy, 13 asercji,
3 oczekiwane porażki; formularz wyszukiwania bez frazy działał poprawnie.

Zmiana: HTML i BreadcrumbList JSON-LD wspólnie używają nazwy „Świeżo z Kuking”
dla istniejącego /odkryj. Oba CTA pustego zeszytu prowadzą do
/szukaj?sekcja=przepisy. Wyszukiwarka zachowuje zakres i prosi o wpisanie frazy;
nie przedstawiamy jej jako katalogu wszystkich przepisów.

Po zmianie: 4 testy / 17 asercji PASS. Testy wskazują konkretne linki w main,
okruszki i JSON-LD; nie opierają się na globalnej obecności adresu.
Runtime /home/mateusz/kuking-667-tests, osobna baza kuking_667_tests,
127.0.0.1:55439, UTC. Nie modyfikowano produkcji.

Pozostało: fizyczne kontrole ujemne, regresje rodzin, formatowanie i analiza,
ogląd w przeglądarce, niezależne review, wersja po koordynacji Alfy 0.64
z PR671, zwykły hook/push, CI i odbiór wdrożenia.
## Dalsze kontrole lokalne

Cztery fizyczne mutacje prawdziwych źródeł Blade (osobno okruszek HTML,
JSON-LD oraz oba CTA) zostały wykryte. Kopie poza repo; po każdej mutacji
potwierdzono MD5 i mtime oraz dodatni przebieg testu. Dowód roboczy:
output/negative-results.json. Końcowe niezależne review: output/review667.md,
bez znalezionych blokerów, z jawnym brakiem odbioru wizualnego.

Szerszy przebieg LinkDestinationLabelsTest, SearchTest,
KompozycjaZeszytowMarkiTest i ZawartoscZeszytuTest: 25 testów /127 asercji PASS.
Pint początkowo wykrył brak końcowego LF nowego testu; dodano LF bez zmian
asercji, a powyższy przebieg wykonano już po tej korekcie.

## Odbiór przeglądarkowy — pierwszy etap

48 konfiguracji (6 szerokości 320/360/390/414/768/1440, oba motywy,
100/140%, przepis i pusty zeszyt) PASS: brak poziomego overflow, rzeczywiste
kliknięcie okruszka otwiera /odkryj, CTA otwiera /szukaj?sekcja=przepisy,
wpisanie „lokalny” i wysłanie formularza zachowuje zakres przepisów.
Pierwszy przebieg bez sesji zatrzymał się na wymaganym logowaniu do zeszytu;
po lokalnym uwierzytelnieniu właściciela powyższy pełny przebieg przeszedł.
Cookie tylko w runtime, bez sekretów w repo; poczta array.

Obejrzano na razie light-140-320-recipe i dark-140-320-collection.
Okruszek i CTA mieszczą się. Przy 320/140% istniejący długi tytuł przepisu
łamie niektóre słowa, a dolna nawigacja zajmuje dwa rzędy — to obserwacje
istniejącego układu, nie dowód nowej regresji #667. Pełnego wyglądu nie uznano
na tej podstawie za ukończony.

Wyniki output/browser667/results.json, helper output/ui667.mjs.
Pozostaje ogląd pozostałych zrzutów, rzeczywisty zoom200 i odbiór pustej
listy zeszytów (HTTP regresja obu pustych stanów jest zielona).

## Pusta lista i rzeczywisty zoom — domknięcie pomiaru

Pusta lista zeszytów: dodatkowe 24 konfiguracje z rzeczywistym kliknięciem CTA
oraz wysłaniem wyszukiwania, PASS (output/browser667/index-results.json).
Łącznie zwykły odbiór obejmuje 72 konfiguracje trzech powierzchni.
Rzeczywisty zoom200 przy tekście140: 6 scen (3 powierzchnie ×2 motywy),
innerWidth320 i DPR2, brak poziomego overflow. Oba CTA kliknięte przy zoomie
prowadzą do właściwego adresu. Wynik output/browser667/zoom-results.json.

Nieudane próby po pierwszym pomiarze ujawniły HTTP429 z /motyw: automatyczny
odbiór z częstą zmianą ustawień wyczerpał limit lokalnego IP. Dodatkowo zapis
wyglądu używa fetch, więc oczekiwanie pełnej nawigacji było błędem harnessu.
Skrypt czeka teraz na odblokowanie kontrolek i kontroluje status zapisu.
Wyczyszczono wyłącznie cache plikowy izolowanego runtime667, nie produkcję.
Końcowy pełny pomiar zwrócił 16 zapisów wyglądu HTTP200 i 6 scen PASS.
Nie wyłączono limitu ani nie zmieniono kodu zabezpieczenia aplikacji.

## Ogląd końcowy i dowody do PR

Obejrzano wszystkie 18 końcowych zrzutów (trzy powierzchnie, oba motywy,
układy desktop/wąski/zoom) w zestawieniach oraz wybrane osobno.
Poprawiane linki mieszczą się i są osiągalne po przewinięciu również przy
zoom200/tekście140. Wybrane zrzuty, wyniki i review zapisano w
 docs/design/evidence/links667/. Odbiór dotyczy zmienionych odnośników,
nie oznacza zatwierdzenia całego istniejącego układu nawigacji ani tytułów.
Brak nowego CSS, JS, migracji czy zmiany uprawnień.
