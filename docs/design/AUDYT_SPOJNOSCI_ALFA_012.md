# Dalszy odbiór marki — Alfa 0.12

13 września 2026. Status: PR #495 wdrożony i sprawdzony na produkcji; wykryto opóźnioną aktualizację istniejącej instalacji PWA, poprawianą osobno w Alfa 0.13.

## Odbiór wydania

PR [#495](https://github.com/woogitsu/kuking.pl/pull/495), źródło
`ce93a141a536c36fdf83a2d5684f6a3d9d336b89`, scalony jako
`e4f9c21b57ccffa7f6c7a28f668239fbe7612323`.
CI [#929](https://github.com/woogitsu/kuking.pl/actions/runs/34752403608):
wszystkie dziewięć zadań success. Odczyt logów potwierdził:

- PHP: 3674 testy / 74019 asercji, runner `kuking-wsl-DOM-NEW-02`;
- wyścigi: 5 / 44, runner `kuking-wsl-DOM-NEW-03`;
- port: 98 wariantów i kontrole ujemne, kreator: 24/24;
- axe: zero naruszeń, przepełnień poziomych i zasłonięć fokusu;
- Lighthouse, Pint, Larastan, audyt zależności oraz buildy Vite i Docker: success.

Lighthouse zaliczył 8/8 ekranów: wydajność 92–99. SEO wyniosło 100
na liczonych stronach; logowanie ma celowy noindex (wynik 58 niewliczany
zgodnie z istniejącym skryptem). Nie zmieniano progów ani tego wyjątku.

CI main [#930](https://github.com/woogitsu/kuking.pl/actions/runs/34752861494)
również ma dziewięć sukcesów; log pełnych testów: 3674 / 74019.
Workflow [Deploy #610](https://github.com/woogitsu/kuking.pl/actions/runs/34753336933)
zakończył się success. Railway deployment `ca152aaf-c03c-4e02-9c04-f1510d7ec3fb`
ma SUCCESS od 11:01:04 UTC dla `e4f9c21b57ccffa7f6c7a28f668239fbe7612323`.
Domena pokazuje Alfa 0.12 i e4f9c21. Ponowny obchód tych samych 16 publicznych
dróg dał 96/96 odpowiedzi 200, bez przewijania poziomego, ze zgodnym motywem
i lokalnym Inter. Wszystkie 30 wariantów z widgetem miało compact, właściwy
motyw i załadowaną ramkę Cloudflare. Obejrzano m.in. zrzut logowania 320 px.

Zachowana sesja początkowo nadal miała cache `kuking-v1`, mimo tych nawigacji.
Odpowiedź `/sw.js` zawierała nowy kod, lecz nagłówek przez Cloudflare wynosił
`Cache-Control: public, max-age=14400`; Caddy w źródle przyznawał mu 3600 s.
Jawne `registration.update()` zainstalowało `kuking-alfa-012` i usunęło stary
cache. Po odłączeniu sieci wyłącznie w przeglądarce wyświetlił się właściwy
polski ekran offline z systemowym fontem i tłem `rgb(243, 244, 241)`, bez
przewijania poziomego; po przywróceniu sieci wróciła Alfa 0.12.
To dowód działania nowego workera po aktualizacji, nie dowód niezawodnej
automatycznej aktualizacji. Uszczelnienie tej drogi jest zakresem Alfa 0.13.

`/health` po wdrożeniu nadal zwraca degraded wyłącznie z powodu nieudanych
zadań kolejki. Nie ukryto tego stanu zmianą healthchecku.

## Potwierdzona baza i produkcja

Repozytorium main oraz aktywne wdrożenie Alfa 0.11 wskazywały
`dbd1cb920f872233f8cc8f240f94273f26f634e8`. Railway production:
`ba872757-91a9-4850-97bb-1e9dceaba112`, SUCCESS; domena kuking.pl
jest przypisana do tego serwisu i pokazuje ten sam skrót w stopce.
PR #494 scalony, #493 nie wymaga osobnego scalenia.

CI #928 (`34749126034`) ma dziewięć sukcesów w najnowszym zestawie.
Powtórzono wyłącznie wyścigi: job `103704702033`, runner
`kuking-wsl-DOM-NEW-02`, 5 testów / 44 asercje, PostgreSQL 18.6.
Pierwsza próba joba `103702460527` miała failure, brak kroków i runnera,
a log odpowiadał 404 BlobNotFound. API nie udostępniło adnotacji, ale później
odczytano ją w zalogowanej przeglądarce Chrome, po rozwinięciu
[Open job annotations](https://github.com/woogitsu/kuking.pl/actions/runs/34749126034/job/103702460527):
„The job was not started because it repeatedly failed to be acquired (5 attempts).”
To potwierdza, że testy w pierwszym zadaniu nie wystartowały. Nie jest to
czerwień asercji aplikacji. Nie ustalono głębszej przyczyny pięciu nieudanych
prób przydzielenia zadania. Brak startu w lokalnych dziennikach runnerów
w przedziale 09:15–09:21 UTC jest zgodny z adnotacją. D-105 i
continue-on-error pozostają bez zmian; nie ma dowodu dwudziestu kolejnych
zielonych przebiegów wymaganych do zmiany tej decyzji.

`/health` zgłasza degraded przez cztery nieudane zadania kolejki.
Logi poprzedniego wdrożenia `79e3a9d1-a5ef-446d-9f2e-18a01c6a98d3`
pokazują te same cztery już o 07:00:47 UTC, przed Alfa 0.11.
Brak pierwotnych wyjątków w odczytanych logach; nie wykonano retry ani
usuwania zadań. To otwarty problem operacyjny, nie wynik audytu wyglądu.

## Znalezione różnice

- 500/503: samodzielny szkielet nadal używał Georgii i starej palety.
  Teksty zapewniały o stanie danych i terminie powrotu bez dowodu.
- Offline i eksport: stare kolory; eksport dodatkowo szeryfowy font.
- Siedem standardowych MailMessage korzystało z domyślnego wyglądu Laravel,
  poza zakresem wcześniejszych 11 własnych szablonów.
- Service worker traktował stałe adresy ikon jak wersjonowane pliki Vite.
  Cache-first utrzymywał stary znak; nowy worker odświeża ikony i manifest
  z sieci, z fallbackiem do cache. HTML prywatnych stron nadal nie jest zapisywany.
- Produkcyjne formularze z Turnstile: szerokość strony 337 px przy oknie
  320 px; miejsce widgetu 246 px, jego zawartość 300 px. Wariant compact
  ma 150 px, flexible ma minimum 300 px i nie rozwiązuje tego problemu.
  Źródło: [konfiguracja Cloudflare](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/).

## Zakres pomiarów

Produkcja: 16 publicznych dróg, dwa motywy, 1440/320 px oraz podwojona
czcionka bazowa przy 320 px — 96 odczytów. Sprawdzono stronę główną,
odkrywanie, wyszukiwarkę, publiczny przepis i tryb gotowania, cudzy profil,
logowanie, rejestrację, odzyskiwanie hasła i link logowania, pomoc, kontakt,
opis serwisu, zasady, regulamin i prywatność. Oglądano zrzuty Start,
przepisu, profilu, wyszukiwania i logowania. Wykryte przewijanie formularzy
z widgetem zweryfikowano osobnym pomiarem po załadowaniu widgetu.

200% w skryptach oznacza CDP Page.setFontSizes, czyli zwiększenie bazowej
czcionki, nie fizyczne powiększenie całej strony w interfejsie przeglądarki.
Nie raportujemy tych dwóch mechanizmów jako jednego testu.

Wiadomości renderowano lokalnie bez wysyłki i bez kont w bazie.
Nowe testy siedmiu klas: 7 testów / 209 asercji; dotychczasowe testy treści
i własnych szablonów: 10 / 220. W Chromium 320/640 px długi adres wymagał
zawijania (335 → 320 px); po poprawce brak przewijania, CTA 183 × 56 px.
To nie jest test dostarczenia poczty ani wszystkich klientów pocztowych.

Samodzielne ekrany: test renderowania 500/503 i eksportu bez bazy oraz
odczytu offline — 3 testy / 59 asercji. Service worker jest wykonywany
z prawdziwego źródła w izolowanym środowisku testowym: odświeżenie ikon,
fallback offline, zachowanie cache CSS i brak cache prywatnego HTML.

Przeglądarka lokalna: 50 wariantów samodzielnych ekranów (500, 503,
eksport jasny i offline w obu motywach; 320/360/390/414/1440 px,
czcionka bazowa 16/32 px). Brak przewijania poziomego, linki co najmniej
48 px, rzeczywiste dojście Tab i cały obrys fokusu w oknie. Pomiar
wykrył obcięcie fokusu przy dolnej krawędzi oraz łamanie słowa przycisku
przez powiększone odstępy; oba poprawiono. Dodatkowa kontrola ujemna
rzeczywistego CSS odtworzyła obcięcie, po przywróceniu MD5 komplet przeszedł.

Kontrole ujemne zmieniały prawdziwe źródła: trzy w motywie wiadomości,
pięć w ekranach samodzielnych oraz wyłączenie odświeżania ikon i obsługi
pełnego cache w workerze (końcowy MD5 `d35210e90e1899feb4862d1053ced79b`).
Każda oblała właściwą asercję, pliki przywrócono z kopii poza repo,
porównano MD5 i powtórzono test dodatni.

Turnstile: 29 nowych testów odpowiedzi siedmiu tras i wyboru motywu oraz
28 istniejących testów walidacji — razem 57 / 664. Kontrole ujemne zmieniały
compact na flexible i wymuszały jasny motyw. Po przywróceniu bajtów oraz
czasu modyfikacji Blade komplet ponownie przeszedł. Sama zgodność MD5
nie wystarcza do unieważnienia wcześniej skompilowanego szablonu.

Rzeczywisty widget Cloudflare z oficjalnym kluczem testowym: 112 lokalnych
wariantów (siedem tras, 320/360/390/414 px, czcionka bazowa 16/32 px,
oba motywy). We wszystkich załadowano ramkę challenges.cloudflare.com;
brak przewijania poziomego dokumentu, body i kontenera widgetu. Przy 320 px
kontener miał 246/246 px szerokości/scrollWidth, przy większej czcionce
206/206 px. Nie wysyłano formularzy ani nie rozwiązywano challenge.

Obchód lokalnego konta demonstracyjnego: 216 wariantów dwunastu ekranów
formularzy i ustawień bez przewijania poziomego oraz 108 wariantów trybu
gotowania, formularza „Ugotowałem”, zeszytu i kreatora. W kroku trzecim
kreatora wykryto 332 px przy oknie 320 px i dużej czcionce w obu motywach.
Znaleziono też dosłowne `&#10;` w podpowiedziach składników i przygotowania.
Odmowa dostępu zwykłego konta do moderacji była oczekiwaną bramką uprawnień,
nie pomiarem wyglądu panelu moderatora.

Po poprawce `kroki-kreatora.mjs`: 24/24 warianty trzech kroków przy
320/390 px, bazowej czcionce 16/32 px i obu motywach. Kontrola ujemna
usuwająca `flex-wrap` wykryła także znikanie znaczników (szerokość zero),
dlatego test mierzy każdy znacznik, nie tylko scrollWidth dokumentu.
Test jest podłączony do CI po porcie, na osobnej bazie `kuking_port_pomiar`.
Podpowiedzi: 1 test renderowanego DOM / 7 asercji; osobne kontrole ujemne
składników i przygotowania oblały właściwe porównania. Przywrócone MD5:
CSS `7a5b8821551f524823864c72846a1eb8`, widok formularza
`6b14f8c5476f1a3a9d7eb84c522e41bb`; po odbudowie i wyczyszczeniu Blade
powtórzono kontrole dodatnie.

Lokalny PostgreSQL 18.6 działa w odizolowanym klastrze na porcie 55439.
Pierwszy pełny przebieg wykazał m.in. błędy czasu; nowo założony klaster
miał Europe/Warsaw. Po ustawieniu wymaganego UTC osobny test strefy
przeszedł 8/8 (11 asercji). Nie zmieniano konfiguracji produkcji ani testów,
żeby ukryć tę różnicę środowiska. Pierwszego przebiegu nie traktujemy jako
końcowej weryfikacji: dodatkowo nakładał się na lokalne mutacje źródeł.

Końcowy pełny przebieg PHP na kopii tych samych źródeł na dysku Linuksa:
**3674 testy, 74019 asercji, wszystkie passed**. Porównano bajty plików
aplikacji, testów i skryptów z checkoutem Windows. Kopia ma własny link
do mediów; skopiowany link wskazujący poprzedni katalog prawidłowo
został wcześniej wykryty przez HealthCheckTest i poprawiony lokalnie.
Pint, Larastan, składnia PHP i skryptów powłoki, testy skryptów, odwracalność
migracji oraz build assetów przeszły. Lokalnych wyników nie przypisujemy CI.

Pierwszy lokalny przebieg `port-projektu.mjs` przerwał build npm kodem 143
w części kontroli ujemnych. `finally` przywrócił CSS (MD5
`7f4d3ea196d8ba8bce093708c52da4b4`) i odbudował assety. Nie liczymy tego
przebiegu jako sukcesu pełnego portu. Pomiary wykorzystujące te same assety
w czasie mutacji odrzucono; końcowe kontrole wykonuje się na przywróconym źródle.

Powtórzony pełny port zakończył się `PORT_OK`: 98 pomiarów dodatnich,
wykrycie zwężenia kolumny i czterech regresji marki, odtworzenie źródła
z tym samym MD5 oraz końcowe pomiary po przywróceniu. Oddzielne axe-core
i Lighthouse zostały następnie potwierdzone w CI #929 opisanym wyżej.

## Granice i wycofanie

Brak migracji, zmiany uprawnień, danych, tras lub tokenów wiadomości.
Wycofanie: revert wydania i przebudowanie aplikacji. Nowy cache workera
nie zawiera prywatnego HTML. Cofnięcie do starego workera przywróci też
jego stare zasady przechowywania ikon.

Nie potwierdzono pełnego przejścia zalogowanego użytkownika na produkcji,
testu czytnikiem ekranu, wszystkich klientów poczty ani przyczyny problemu
przydzielania pierwszego zadania wyścigów. Konta demonstracyjne i modele
testowe były lokalne.
