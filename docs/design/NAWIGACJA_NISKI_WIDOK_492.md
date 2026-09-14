# Nawigacja przy małej wysokości strony — #434 / #492

Status: lokalny odbiór przed CI i wdrożeniem. Baza
`595f41fa8a4c21642f21a566c27946c5811125a9`.

## Problem i rozwiązanie

Przy rzeczywistym zoomie 200%, obszarze 320×500 CSS px i tekście
aplikacji 140% nagłówek oraz dolna nawigacja pozostawiały około 96 px
na treść. Samo zwiększenie rezerw przewijania nie pomagało: wysoka
kontrolka formularza nadal trafiała pod belkę.

W `marka-rama.css` obie belki przewijają się ze stroną przy szerokości
do 30rem i wysokości do 40rem albo przy istniejącym progu szerokości
15rem. Rezerwy odpiętych belek znikają; pozostaje po 0,5rem na obrys
fokusu. Dolny bezpieczny obszar urządzenia pozostaje uwzględniony.
Zwykłe 320×740 i 390×844 zachowują przypięcie. Nawigacja przy małym
obszarze wymaga przewinięcia do początku lub końca strony; pięć dolnych
linków pozostaje dostępnych także klawiaturą.

Mniejszy poziomy padding dostępu do konta i powiadomień pozwala zmieścić
je razem przy 390 px z licznikiem. Nie zmniejszono tekstu ani celów
dotykowych. Wstępny odczyt potwierdził już istniejący odstęp przed
wordmarkiem i kwadratowe awatary; nie przypisujemy tej gałęzi ich naprawy.

## Rzeczywista regresja

`nawigacja-niski-widok.mjs` jest uruchamiany przez `port-projektu.mjs`.
Oba filtry CI uwzględniają samą zmianę nowego modułu. Wynik lokalny:
**20 konfiguracji** — 16 strony Start i cztery formularza z rzeczywistym
błędem walidacji, z JavaScript i bez niego, oba motywy.
Wykonanie na Chrome for Testing **151.0.7922.34**, własna baza
`kuking_port_nav492_final` na `127.0.0.1:55439`, lokalna poczta array.
[Zapis 20 konfiguracji](evidence/nav492/wyniki-chrome151.json) zachowano
w repo. Tego wyniku nie przypisujemy automatycznie wersji Chromium z CI.

Następnie ten sam niezmieniony kod sprawdzono na **Chromium 153.0.8010.12**,
zgodnym z ostatnimi logami CI: **20/20 dodatnich konfiguracji PASS**,
116,595 s, oba motywy i JS/bez JS. [Osobny zapis](evidence/nav492/wyniki-chrome153.json)
zachowano w repo. Siedmiu negatywów nie powtarzano na 153; ich wyniki
poniżej dotyczą wersji 151.

Start obejmuje 320×740 i 390×844 przy zwykłym tekście, rzeczywisty zoom
200% przy 320×500 i tekście 140% oraz font 32 px razem z tekstem 140%.
To ostatnie połączenie ujawniło dodatkowe zasłanianie wysokiego kafla;
nie usunięto go z macierzy. Zoom potwierdzono przez chrome.tabs.getZoom,
rozmiar obszaru CSS i pomiar rzeczywistego fontu.

Każdy Start: **53/53 przystanki Tab**; każdy formularz błędu: **4/4**.
Osobno sprawdzono rzeczywisty Tab do **5/5 dolnych linków**, ich rozmiary
co najmniej 48×48 px i widoczność. Sprawdzono wszystkie fragmenty
krótkiego komunikatu, kliknięcie odnośnika błędu do pola oraz działanie
kafla dodawania. Obejrzano reprezentatywne zrzuty krótkiego Start i błędu.

Oczekiwanie na requestAnimationFrame przy wyłączonych skryptach przerywało
sam pomiar. Opcja `bezJs` w istniejącym `sprawdzTab` przenosi oczekiwanie
do Node, zachowując wspólny pomiar geometrii, kontrastu, hit-test i rastra
obrysu. Domyślna ścieżka JS zachowuje dotychczasowe oczekiwanie animacji.
Nie włączano skryptów aplikacji, aby uzyskać dodatni wynik.

## Kontrole ujemne i izolacja

Siedem fizycznych mutacji CSS wykryto właściwymi asercjami. Pięć dotyczy
przypięcia obu belek, rezerw i ułożenia powiadomień. Dwie dodatkowe
potwierdzają sam objaw przez niezmieniony `sprawdzTab`: przypięty dół
oraz brak zapasu obrysu w wariancie 32 px / 140% dają
`ZOOM_FOCUS_OCCLUDED`. Wyzerowanie zapasu w krótkim formularzu nie
odtworzyło zasłonięcia; nie przedstawiamy tej próby jako ujemnego dowodu
objawu.

Kopie prawdziwego CSS poza repo, rzeczywisty build po mutacji i
przywróceniu, sprawdzenie MD5 i mtime oraz dodatni pomiar po odtworzeniu.
Końcowy MD5: `a669e604c7f8e83fdf0932095f52c0f6`.
Pełny moduł z siedmioma negatywami trwał lokalnie **139,023 s**.

Fixture korzysta z lokalnej bazy kuking_port*, sprawdza rozwiązane
połączenie i jawny port. W transakcji zapisuje kopię stanu powiadomień
poza repo. `finally` usuwa własne dane i przywraca odczyt; nieudane
przygotowanie kontekstu zamyka przeglądarkę. Niezależny review sprawdził
te zabezpieczenia. Nie wykonywano zapisów na produkcji.

## Granice

To odbiór lokalnego Chromium, nie fizycznej klawiatury ekranowej ani
bezpiecznego obszaru konkretnego telefonu. Nie obejmuje wszystkich stron.
Pełne axe i wcześniejsze regresje uruchamia CI. Historyczny zielony
przebieg 34834435058 trwał 1144 s w samym kroku portu, 1214 s w całym
zadaniu; obecny limit tego zadania wynosi 25 minut. Koszt rozszerzenia
został zmierzony, ale wynik integracji należy potwierdzić osobno.

## Uzupełnienie odbioru #539

Potwierdzono i poprawiono kontrast fokusu przy jednoczesnym najechaniu na przycisk podpowiedzi. Regresja: 88 wierszy pomiarów, pięć kontroli ujemnych rzeczywistego CSS i cztery próby prawdziwego zoomu. Niezależny końcowy odbiór po integracji z nawigacją: ciemny motyw, zoom 200%, tekst 140%, Tab 14/14 PASS wraz z hover + focus-visible. Wcześniejszego nieudanego przebiegu nie traktujemy jako pozytywnego. Szczegóły: [FOKUS_PODPOWIEDZI_539.md](FOKUS_PODPOWIEDZI_539.md). Wdrożenie pakietu wymaga osobnego potwierdzenia.
