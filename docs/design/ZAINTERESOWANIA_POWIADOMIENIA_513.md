# Zainteresowania i zwykłe powiadomienia — #513 / PR #519

**Stan: pełny port lokalny zakończony sukcesem; PR pozostaje draft przed końcowym CI.** Alfa 0.20 jest
wersją gałęzi. Potwierdzona produkcja to Alfa 0.19, opisana w
[odbiorze wdrożenia](ODBIOR_PRODUKCJI_ALFA_019.md).

Baza: main `34b4b61109ebd1e1c808066191609672cd5ae332`. Zakres: D-212,
konstytucja 1.8. Wzorzec pochodzi z oryginalnego ZIP zachowanego w repo.

## Potwierdzone braki i poprawki

Wąski formularz zainteresowań zastępują duże kafle rzeczywistych promowanych
tagów. Natywne checkboxy, zielone zaznaczenie, pełna lista, `old()`, CSRF,
POST, pomijanie i trzy opcjonalne kroki pozostają zachowane. Nie powstają
fikcyjne zainteresowania ani obietnica zapełnienia strony głównej.

Pięć zwykłych typów powiadomień otrzymuje akcję obok treści na szerokim
widoku: ugotowanie, komentarz, odpowiedź, obserwowanie i zapis. Wąski ekran
zachowuje układ pionowy. Moderacja pozostaje poza tym układem, z pełnym
uzasadnieniem i drogą odwołania. Mechanizmy odczytu i uprawnień nie zmieniają się.

Odbiór ujawnił jeszcze dwa rzeczywiste błędy: nagłówek zainteresowań był
pomijany przez wspólny selektor typografii, a instrukcja w legendzie wychodziła
poza 320 px przy bazowym foncie 32 px i tekście aplikacji 140%. Poprawiono
selektor w `marka-rama.css` oraz zawijanie legendy w `marka-onboarding.css`.
Nie zmniejszano tekstu ani nie ukrywano globalnego overflow.

## Macierz wykonana lokalnie

| Ekran / stan | Wygląd i tekst | Mobile / skala | Test i ograniczenie |
|---|---|---|---|
| Zainteresowania, pełna lista | Pełne nazwy, większy nagłówek, zaznaczenie | 320/360/390/414/768/1440, oba motywy, tekst 100/140, font 16/32 i połączenie 32+140 | Rzeczywista geometria, kliknięcie, Space, Tab 10/10; reprezentatywne zrzuty 320/140 obejrzane |
| Zwykłe powiadomienia | Pięć typów, akcja obok na desktopie, brak autora/celu, odczytane/nowe | Ta sama macierz | Geometria, pełna treść, Tab 11/11, POST pięciu zdarzeń i GET dokładnych celów HTTP 200 |
| Decyzja moderacyjna | Pełny tekst i odwołanie utworzone przez akcję domenową | Ta sama macierz | Osobny układ, brak obcinania; nie jest to audyt wszystkich decyzji i paneli |
| Puste listy | Właściwe komunikaty i dalsza droga | 320/1440, zadane oba motywy: 8 odczytów | HTTP, treść, brak poziomego overflow; bez pełnego Tab, skal i pomiaru przeliczonego koloru |
| Paginacja powiadomień | Prawdziwy adres drugiej strony i 9 pozostałych pozycji | 320/1440, zadane oba motywy: 4 przejścia | HTTP i treść; nie dziedziczy pełnej macierzy |
| Zapis i odczyt | Zainteresowanie bez JS, brak celu i oba przyciski oznaczania wszystkich | Rzeczywiste formularze lokalne | Niezależny odczyt bazy i przywrócenie wszystkich `read_at` oraz tagów; bez produkcyjnych zapisów |

Moduł `zainteresowania-powiadomienia-marki.mjs`: **96 konfiguracji** i osiem
kontroli ujemnych, sukces. Końcowy pełny port obejmuje **80 wariantów
prawdziwego zoomu dziesięciu tras**, w tym 16 nowych wariantów #513.
Potwierdzone `chrome.tabs.getZoom=2`, DPR 2 i viewport połowy szerokości;
oba motywy i tekst 100/140. Przeszło również sześć rzeczywistych negatywów
zoomu. Wcześniejszy osobny odbiór subagenta miał 64 warianty i nie zastępuje
tego końcowego przebiegu.

## Kontrole ujemne prawdziwych źródeł

Każda kontrola CSS/Blade zmienia rzeczywisty plik kopii wykonawczej po
kopii poza repo, odbudowuje assety, wymaga właściwego błędu, przywraca MD5
oraz mtime i przechodzi ponowny pomiar dodatni.

| Mutacja | Wykryty błąd |
|---|---|
| Usunięcie zawijania legendy | `K513_OVERFLOW` |
| Usunięcie selektora nagłówka | `K513_NAGLOWEK` |
| Włączenie moderacji do zwykłych kart w Blade | `K513_ZAKRES` |
| Siatka 900 px | `K513_OVERFLOW` |
| Ucięcie nazwy tagu do jednej linii | `K513_TEKST` |
| Usunięcie tła zaznaczenia | `K513_STAN` |
| Akcja ponownie pod treścią na desktopie | `K513_UKLAD` |
| Kolumny powiadomienia 850 + 200 px | `K513_OVERFLOW` |

Przywrócone MD5: onboarding CSS `c0af97d89632030305ac35dada229761`, rama
`72e97c37dadbe65eed758a1746c2e54e`, Blade powiadomień
`8bcd161a9edbb03b4a09dfff28ba7912`, CSS powiadomień
`435008e5e40d69a246d125c4199081a4`. Zrzuty powstają w
`storage/port-projektu/513`; CI zachowuje je w artefakcie portu.

## Integracja i niezależny przegląd

Port i zależny test kroków kreatora przeniesiono razem do osobnego,
obowiązkowego zadania `port_marki` z własną bazą PostgreSQL i limitem 20 minut.
Dotychczasowe zadanie dostępności zbliżało się do limitu (18 min 34 s w PR
#517). Nie usunięto żadnego pomiaru, negatywu ani progu. Filtr obejmuje nowy
moduł i fixture. `PortMarkiMaWlasnaBramkeCiTest` pilnuje obecności portu,
zależnego kreatora, obowiązkowości bramki, artefaktów i filtrów.

Niezależny odczyt ujawnił brak przekazania domyślnego środowiska bazy do
poleceń PHP modułu oraz zbyt wąski backup odczytów przed akcją oznaczania
wszystkich. Obie poprawki sprawdzono ponownym odczytem: wspólne `phpEnv`
i odtworzenie wszystkich powiadomień właściciela. Osobny roundtrip dziewięciu
rekordów, w tym jednego bez znacznika fixture, zakończył się sukcesem.

## Wyniki końcowego odbioru lokalnego

Pełny `port-projektu.mjs` zakończył się kodem 0 i `PORT_OK`:
432 warianty wcześniejszych kompozycji, 96 zeszytów, 96 nowych ekranów,
80 prawdziwych zoomów oraz wszystkie jego kontrole ujemne. Nowy moduł
zajął 166397 ms. Uruchomienie bez jawnego `DB_DATABASE` zweryfikowało też
domyślną izolowaną bazę. Zależny kreator: **24/24 warianty**, szkic pozostał
nieopublikowany. Środowisko: WSL, PostgreSQL na 55439, Chromium 1243.

Obejrzano zrzuty mobile 320/140 oraz desktop 1440 w obu motywach:
duży nagłówek, trzy kolumny zainteresowań, zwykłe akcje obok treści,
pełne uzasadnienie moderacyjne i odwołanie. Ogląd ujawnił brak podmiotu
przy `actor=null`. Dodano neutralne „Ktoś” w pięciu zwykłych typach.
`PowiadomienieBezAutoraTekstTest` renderuje 10 wariantów karty (bez autora
i z prawdziwą nazwą); nie udaje testu filtrów widoczności kontrolera.

| Regresja PHP / źródło | Dodatni wynik | Rzeczywiste negatywy i odtworzenie |
|---|---|---|
| `KompozycjaZainteresowanTest` | 2 testy / 21 asercji | 3: ucięta lista, brak CSRF, brak drogi dalej; MD5 `7a40862099e8c1447c0c61949d3ea53b` |
| `PowiadomienieBezAutoraTekstTest` | 1 test / 20 asercji | 5: brak podmiotu osobno dla każdego typu; MD5 `8bcd161a9edbb03b4a09dfff28ba7912` |
| `PortMarkiMaWlasnaBramkeCiTest` | 2 testy / 28 asercji | 4: brak portu, nieblokujący port, pominięty moduł w filtrze, brak kreatora; MD5 `2628dd48508f9464cbbc531927c1b541` |

Każdy negatyw PHP/workflow miał kopię poza repo, rzeczywistą mutację źródła,
wykryte niepowodzenie asercji, odtworzenie MD5/mtime i ponowny wynik dodatni.
Pint nowych plików przeszedł po ujednoliceniu końców linii testu autora.
Wcześniejsze przygotowanie: 36 testów / 192 asercje wybranych rodzin,
72 pary kontrastu i trzy testy dokumentacji / 41 asercji. Pełny port wielokrotnie
odbudował assety; nie mierzył samych niezbudowanych plików CSS.

## Poprawiona konfiguracja testu i review

Pierwszy pełny przebieg zatrzymał się na ochronie przekierowania: serwer
używał `127.0.0.1`, a `app.url` wskazywało `localhost:8000`. Osobny odczyt
potwierdził odrzucenie celu i pięć poprawnych przekierowań po wyrównaniu
konfiguracji. Serwer pomiarowy dostaje teraz swój adres w `APP_URL`;
kod ochrony aplikacji nie został zmieniony. Drugi pełny przebieg przeszedł.

Niezależny końcowy review nie zgłosił uwag blokujących. Sprawdził również
fallbacki autora, escapowanie Blade, pełny backup odczytów i środowisko PHP.
Review był odczytem kodu; powyższe wykonania należą do jawnie opisanych
lokalnych przebiegów, a nie do samego review.

## CI, wdrożenie i ograniczenia

Pierwszy commit `9b6bf6a` wysłano przez pełny wymagany hook; CI
`34782541626` ma wszystkie dziewięć zadań success. To wynik wcześniejszego
commita, nie końcowej regresji opisanej tutaj. Przed scaleniem wymagane są
hook wysyłki i CI końcowego commita z osobnym zadaniem portu.

Alfa 0.20 nie ma jeszcze potwierdzenia Railway. Po scaleniu konieczny jest
osobny odbiór wdrożenia i poprawionych ścieżek produkcji. Produkcyjne dane
i preferencje użytkownika nie były zmieniane w tym odbiorze.
Pełny port marki pozostaje **CZĘŚCIOWY**: #514–516 oraz ograniczenia szerszej
macierzy nie są zamykane przez ten raport. Nie potwierdzono tu wszystkich
typów powiadomień, wszystkich stanów moderacji ani realnych klientów poczty.

Końcowy lokalny `dostepnosc.mjs`: kod 0, axe **44/44 ekranów**, układ **49/49**; zero naruszeń, poziomego overflow, niespójności układu i zasłoniętego fokusu. Końcowy ciemny desktop powiadomień z fallbackiem „Ktoś” został ponownie obejrzany.
