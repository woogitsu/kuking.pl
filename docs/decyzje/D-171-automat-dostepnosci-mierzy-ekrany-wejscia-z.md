## D-171 · Automat dostępności mierzy ekrany wejścia z atrapami kluczy dostawców, ale nie z atrapą dostawcy

**Data:** 12 września 2026 · PR #422 · issue #345 · Status: **obowiązuje**

### Kontekst

Blok „Wejdź kontem Google / Facebooka" renderuje się pod warunkiem
`Google::dziala()` / `Facebook::dziala()`. Środowiska pomiaru (`.env`
deweloperski, job `dostepnosc` w CI) mają klucze **puste**, więc axe nie zobaczył
tych przycisków **ani razu** — mimo że `/login` i `/register` były na liście
`EKRANY` od początku.

**Zmierzone** (320 px, `<main>`): `/login` z kluczami 40 węzłów i 2 znaki marki,
bez kluczy 25 węzłów i **zero**; `/register` odpowiednio 58 i 43. To ta sama klasa
fałszywej zieleni co D-106.

Do tego `/ustawienia/bezpieczenstwo` — jedyne miejsce z „Połącz konto Facebooka"
(D-098) — **nie był mierzony wcale**, ani przez axe, ani przez układ; stoi za
`auth`, więc `PomiarDostepnosciObejmujeStronyPubliczneTest` z założenia go nie
widzi.

### Rozstrzygnięcie

Automat stawia swój serwer z **atrapami czterech kluczy**
(`KLUCZE_DOSTAWCOW_DO_POMIARU`) i **twardo sprawdza, że rząd przycisków naprawdę
wyszedł**; niepowodzenie kończy przebieg. Wartość klucza nie wchodzi do HTML-a
(widok pyta tylko *czy* klucze są), więc renderowany kod jest co do znaku ten sam
co na produkcji i nie wychodzi z tego ani jedno żądanie do dostawcy. Do listy
dochodzi `/ustawienia/bezpieczenstwo`.

Progów **nie ruszano**. Skan: 40/40 → **41/41** axe, 45/45 → **46/46** układ,
zero naruszeń przed i po. Naprawiać nie było czego.

### Czego to nie zmienia

Ekrany za zgodą dostawcy (`/wejdz/{google,facebook}/{domknij,polacz}` oraz
`auth.facebook-bez-adresu`) zostają **niezmierzone** i zostają wypisane jako dług
nazwany w `WYJATKI`. Czytają z sesji tożsamość, którą zakłada wyłącznie
`callback()` po wymianie kodu u dostawcy, a adres wymiany jest **stałą w kodzie**,
idzie z serwera i nie da się go wskazać konfiguracją — zmierzone: przy ustawionych
atrapach kluczy wszystkie cztery dalej oddają 302 na `/login`.

**Trzy drogi rozważone i odrzucone:**
* zapis klucza sesji z zewnątrz (choćby przez `tinker`) — **właz obchodzący
  D-098**, granica, której nie przekracza ani `stanModeratora()`, ani
  `stanPrzedKodem2FA()`;
* nadpisanie adresu punktu tokenu konfiguracją — wywraca model bezpieczeństwa
  opisany w `KlientGoogle` („nie sprawdzamy podpisu, bo token odbieramy wprost
  z punktu Google po TLS") i robi ze zmiennej środowiskowej drogę do podstawienia
  tożsamości oraz wycieku sekretu klienta;
* serwer-atrapa za proxy z podłożonym CA — wymaga zaufanego CA w procesie
  aplikacji, co jest gorsze niż to, co naprawia.

Atrapa **całego dostawcy** zostaje osobną pracą z #345 i osobną decyzją; **dług to
pięć ekranów, nie cztery.**

### Zauważone o środowisku

Drzewo bez zbudowanego frontu daje **mylący komunikat o poczcie**, bo każda strona
zwraca 500 (`ViteManifestNotFoundException`). Wart osobnego zgłoszenia.

📄 `scripts/dostepnosc.mjs` · D-098 · D-106 · D-132 · issue #345 · issue #278
