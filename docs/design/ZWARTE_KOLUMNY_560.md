# Zwarte kolumny publicznych wpisów — #560

## Zakres i stan

Alfa 0.33 jest przygotowana lokalnie. Push, CI i wdrożenie tego pakietu
nie są jeszcze potwierdzone. Baza porównania:
320c1d7173377f0f293afbab905b0f1bb6a806f7 (Alfa 0.32).

Dotyczy wyłącznie „Świeżo z Kuking” na stronie dla gościa. Zwykły Grid
odsuwał trzecią kartę pod wysokość wyższej drugiej karty. Moduł
`resources/js/landing-wpisy.js` wyznacza niezależne pozycje w dwóch
kolumnach. Nie przenosi kart w DOM; pozostają kolejność chronologiczna,
linki, komponent wpisu i jedna kolumna na telefonie. Bez JavaScriptu
zostaje wcześniejsza czytelna siatka i działające linki do wpisów.

ResizeObserver obserwuje kontener i karty. Próg pochodzi z właściwości
CSS ustawianej w istniejącym media query 64rem. Pomiar znalazł błąd
pierwszej wersji: liczenie obliczonych torów Grid utrzymywało niejawną
drugą kolumnę po zwężeniu 1440→390. Naprawiono przyczynę i dodano
regresję zmiany szerokości w tej samej stronie. Margines przewijania
kontrolek listy wynosi 12 px, aby obrys nie kończył się na krawędzi okna.

## Rzeczywiście wykonany odbiór lokalny

Izolowany Laravel w Ubuntu WSL, PostgreSQL 18 na 127.0.0.1:55439,
baza kuking_560_browser. Cztery jawnie lokalne wpisy testowe mają różne
długości, zdjęcie z dostarczonego ZIP przetworzone przez istniejący
pipeline mediów i status ready. Nie zapisano nic na produkcji.

| Scenariusz | Wynik | Dowód |
|---|---|---|
| 320, 360, 390, 414, 768, 1440; oba motywy; tekst 100/140%; zoom 100/200% | 48/48 PASS | evidence/kolumny560/wyniki.json |
| Kolejność wszystkich 36 przystanków Tab w każdej konfiguracji, fokus, odstępy i brak overflow | PASS | ten sam raport |
| Kontrolowany wzrost wysokości pierwszej karty i przywrócenie treści | PASS w 48 konfiguracjach | ten sam pomiar |
| Zmiana 1440→390→1440 bez przeładowania | PASS | regresja scripts/zwarte-kolumny.mjs |
| Zdjęcie: Enter otwiera dialog, Escape zamyka i zwraca fokus; „Czytaj dalej” prowadzi do wpisu | PASS, 320/1440 w obu motywach | evidence/kolumny560/akcje.json |
| Bez JavaScriptu, 320/1440, rzeczywisty link do wpisu | PASS | evidence/kolumny560/akcje.json |
| Font domyślny przeglądarki 32 px oraz tekst 140% | 10 konfiguracji do 768 px PASS; 1440 light FAIL | ograniczenie poniżej |

Rzeczywisty zoom ustawiano przez chrome.tabs.setZoom i odczytywano
chrome.tabs.getZoom. To osobny test od Page.setFontSizes. Układ okna
dobrano tak, aby szerokość CSS odpowiadała wartości w tabeli.
Obejrzano końcowe zrzuty z prawdziwymi gotowymi zdjęciami: desktop jasny
100%, mobile ciemny 140% z zoomem 200%, oraz zrzut błędu fontu 32 px.
Niezależny reviewer obejrzał dwa pierwsze i nie znalazł blokera w kodzie.

Regresja jest wywoływana przez scripts/port-projektu.mjs: 24 konfiguracje
bez dodatkowego zoomu oraz zmiana szerokości. Lokalny pomiar 48 wariantów
rozszerza ten zakres; nie należy przypisywać go automatycznie wynikowi CI.

## Fizyczne kontrole ujemne

Pięć osobnych zmian prawdziwych źródeł w kopii wykonawczej, każda z buildem:

1. Usunięcie inicjalizacji modułu — TRYB_KOLUMN.
2. Zmiana wysokości wiersza z 1 na 2 px — PUSTA_PRZERWA.
3. Usunięcie obserwacji kart — PUSTA_PRZERWA po wzroście treści.
4. Przywrócenie liczenia torów zamiast właściwości CSS — KOLUMNY_PO_ZMIANIE_OKNA.
5. Usunięcie marginesu przewijania fokusu — FOKUS_ZASLONIETY.

Wszystkie oblały właściwą asercję. Przed każdą kopia poza repo,
po każdej przywrócenie MD5 i mtime oraz ponowny build i dodatni wynik.
Szczegóły: evidence/kolumny560/negatywy.json.
Test poprawiono również w dwóch miejscach pomiarowych: pomija celowo
niefokusowalne avatary tabindex=-1 i bada każdy rzeczywisty prostokąt
wielowierszowego linku, a nie pusty środek jego prostokąta zbiorczego.

## Oddzielny istniejący brak

Przy domyślnym foncie przeglądarki 32 px, tekście aplikacji 140% i oknie
1440×900 próg 64rem daje jedną kolumnę. Link powiększenia zdjęcia ma
około 772 px wysokości; po Tab dolna krawędź wychodzi poza viewport.
Obrys pozostaje częściowo widoczny, ale pełna asercja widoczności nie
przechodzi. Nie uznajemy tego za PASS ani za błąd nowych kolumn.

Odtworzono to również po fizycznym podstawieniu CSS, app.js i landing
z bazowego SHA main. Potem przywrócono dokładne źródła, MD5 i mtime,
wykonano build i dodatni test kolumn. Dowody: font-baza.json i
font-baza.log w evidence/kolumny560. Ten przypadek wymaga osobnej poprawki
fokusu zdjęć, bez globalnego blur, ukrywania overflow lub obcinania zdjęcia.

Lista kart jest ustalana przy inicjalizacji. Obecny landing renderuje ją
serwerowo; dopisywanie kart do tego samego kontenera bez nawigacji wymagałoby
rozszerzenia obserwacji. Nie dodano niepotrzebnego mechanizmu na przyszłość.
Pełny port identyfikacji pozostaje CZĘŚCIOWO.
