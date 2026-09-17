# Pełne etykiety dolnej nawigacji — #638

Status: PR #639 wysłany, po integracji #637 i pełnym hooku. Pierwszy CI wykrył regresję dużego fontu opisaną poniżej; poprawka wymaga nowego hooka i CI. Alfa 0.51 nie jest wdrożona.

Na produkcji Alfa 0.49 / 89c45e6 w Chrome przy viewport320 i clientWidth305 etykieta Szukaj łamała się na Szuka/j. Nav289px, przycisk55.8125px, font18px. Potwierdzono zrzutem i DOM Range.

Nowy blok CSS publicznej nawigacji wyznacza szerokość pełnym tekstem i zachowuje minimum48px. Przy dużym tekście zawija całe przyciski. Padding2px×skala układu rozdziela etykiety. Selektor :where zachowuje późniejsze reguły układu przy bardzo małej szerokości; panel moderacji wyłączony z zakresu. Review wykryło uboczną zmianę padding górnego menu — cofnięto przed końcowym pomiarem.

Końcowe źródła lokalnie:168/168PASS dla305/320/360/390/414/768px, obu motywów, siedmiu skal70–140%, z i bez scrollbar-gutter:stable. Gutter zmienia geometrię belki, choć headless clientWidth nie zmienia się; nie jest to fizyczny telefon ani dokładna emulacja natywnego paska. Dodatkowe305px pokrywa szerokość treści wcześniejszego odtworzenia.

Regresja mierzy pełne etykiety przez Range, font dla skali,48px, containment tekstu i przycisków, brak overlap, jeden rząd przy skali<=100 i navWidth>=289. Dowód evidence/nawigacja638/macierz.json.

Fizyczny negatyw usuwa właściwy blok z prawdziwego CSS, buduje assety i otwiera rzeczywiste /home. Wykryto NAV638_ETYKIETY. Kopia poza repo, zgodne MD5 i mtime po przywróceniu oraz dodatni wynik. Dowód negatyw.json.

Przygotowano wersję Alfa 0.51 i changelog. Obejrzano końcowe zrzuty przy 320 px: jasny motyw 100% zachowuje pięć pełnych etykiet w rzędzie, ciemny 140% z gutter układa przyciski 3+2 bez łamania słów. Integracja #637, pierwszy hook i push zostały wykonane. Po dodatkowej poprawce pozostają ponowny hook, push, CI, merge i odbiór produkcji. Wyniki lokalne nie oznaczają wdrożenia.


## Dodatkowy odbiór zoomu i działań

4/4 konfiguracje: prawdziwy chrome.tabs.setZoom(2), odczyt zoom2/DPR2, szerokości CSS320/720, oba motywy i tekst140%=25.2px. Pięć pozycji osiągniętych kolejno przez Tab, każda z widocznym fokusem i kontrolą zasłaniania; Enter otworzył Profil. W każdej konfiguracji wykonano pięć kliknięć: Start/Szukaj/Dodaj/Moje/Profil, z kontrolą adresu i widocznej treści. Wyłącznie GET po logowaniu fixture, bez wpisów produkcyjnych. Dowód zoom200-interakcje.json.

Pomiar macierzy i zoomu podłączono do grupy rozszerzenia w port-projektu.mjs. Lokalny przebieg eksportowanych funkcji z sesją w formacie obiektu używanym przez ten runner zakończył się wynikiem 168/168 oraz 4/4. Dowód macierzy integracyjnej: evidence/nawigacja638/integracja-macierz.json. To celowany przebieg integracyjny, nie pełny CI.

Po tym przebiegu doprecyzowano test kliknięć: po każdej pełnej nawigacji przywraca on motyw i tekst 140%, ponieważ konto testowe ma domyślne preferencje. Końcowy przebieg w izolowanym runtime kuking-nav638-zoomfinal i bazie kuking_638_zoomfinal na porcie 55439 zakończył się kodem 0: 4/4 konfiguracje, 20 przejść fokusu oraz 20 kliknięć i cztery wejścia do profilu klawiszem Enter. Plik zoom200-interakcje.json pochodzi z tego ostatniego przebiegu i zastępuje wcześniejszy dowód kliknięć przy domyślnej skali po nawigacji.

Niezależny końcowy review nie wskazał blokera w CSS, regresji, integracji ani przywracaniu źródeł po kontroli ujemnej. Wskazany w review brak wykonania ostatniej wersji testu kliknięć został uzupełniony opisanym przebiegiem. Odbiór fizycznego telefonu pozostaje poza zakresem lokalnych pomiarów Chromium.

## Dodatkowa regresja ujawniona przez CI #639

CI 35190274408, job 105101128066: K509_OVERFLOW przy 320 px, foncie
przeglądarki 32 px i skali aplikacji 140% (tekst 50,4 px). To osobny
wariant od zoomu 200%. Reguła 40% w bardzo wąskim układzie nie
mieściła poziomej ikony i całej etykiety. Lokalny rzeczywisty /home
odtworzył scrollWidth 332 przy viewport 320; po poprawce wynosi 320.

W tym układzie pozycje publicznej nawigacji poza Dodaj otrzymują
flex-basis:max-content; Dodaj zachowuje 100%. Nie zmniejszano tekstu ani
nie ukrywano overflow. Osobna regresja obejmuje 24 konfiguracje fontu32
(6 szerokości, 2 motywy, tekst100/140), ze sprawdzeniem pełnych etykiet,
geometrii oraz overflow. Kontrakt jednego rzędu dotyczy zwykłego fontu,
pozostałe asercje nadal obowiązują przy dużym foncie.

Lokalny przebieg: 168 podstawowych + 24 font32 + 4 rzeczywistego zoomu PASS.
Fizyczne usunięcie nowej reguły z CSS wykryte jako NAV638_ETYKIETY;
przywrócono MD5 i mtime, przebieg dodatni PASS. Dowody: font32.json oraz
font32-negative.json. Niezależny odczyt trzech zmienionych plików nie
wykazał blockera. Nie jest to jeszcze wynik ponownego CI ani produkcji.
