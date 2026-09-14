# Mieszana próbka karuzeli — #431

Baza `595f41fa8a4c21642f21a566c27946c5811125a9`. Zmieniono automat
`scripts/dostepnosc.mjs` i dodano `scripts/fixtures/karuzela-mieszana.php`
oraz `.mjs`. Nie zmieniono galerii aplikacji ani decyzji D-191:
kwadratowa ramka i `object-fit: contain` są zamierzone.

## Pokrycie

Własny lokalny wpis ma dwa rzeczywiste PNG: 600×800 i 800×450. Jego ID
zastępuje przypadkowy wybór karuzeli zarówno w macierzy axe/układu, jak
i w pomiarze bez JavaScript. Brak próbki oznacza błąd. Istniejący blok
pomiarowy zastąpiono wspólnym eksportem, bez drugiego pełnego audytu.
Kontrola wyboru zdjęcia i opóźnienia CSS z #536 pozostaje niezmieniona.

Lokalnie sprawdzono **8 wariantów / 40 stanów**: 320 i 390 CSS px,
zwykły tekst, tekst aplikacji 140%, font przeglądarki 32 px i prawdziwy
zoom 200%. Każdy obejmuje początek, klik dalej/wstecz i Tab+Enter
dalej/wstecz. Osiem Tabów prowadziło do „Następne zdjęcie”, dwa kolejne
do „Poprzednie zdjęcie”. Obrazy dekodowano po rzeczywistym GET.

Wymagane są HTTP 200, obie naturalne orientacje zdjęć, ruch slajdów,
brak przewijania dokumentu w bok, ramka 1:1 i contain, stała wysokość
taśmy oraz kontrolki co najmniej 48×48 px bez obcięcia tekstu. Aktywna
kontrolka Tab mieści się w viewport i przechodzi hit-test środka.

Zoom ustawiono przez `chrome.tabs.setZoom(2)` i odczytano `getZoom`, DPR
oraz `innerWidth`. Font 32 px jest odrębnym wariantem, nie substytutem
zoomu. Obejrzano reprezentatywne zrzuty. Nie dowodzi to pełnej zgodności
WCAG, kontrastu fokusu, obu motywów ani działania fizycznego telefonu.
Wysokie kontrolki przy dużym foncie wymagają przewijania pionowego.

## Izolacja i sprzątanie

Wykonanie: `/tmp/kuking-proof431-20260914`, własny storage, PostgreSQL
`127.0.0.1:55439`, baza `kuking_proof431`, UTC, poczta `array`,
PHP 8.4.24 i Chromium 153.0.8010.12. Bez danych produkcji.

Fixture sprawdza rozwiązaną konfigurację połączenia: pgsql, local/testing,
loopback, lokalny dysk public i dokładną nazwę kuking_a11y,
kuking_test_a11y albo kuking_proof431. CI dopuszcza kuking_test wyłącznie
przy GITHUB_ACTIONS=true. Nazwa kuking_test_a11y zachowuje zgodność
z lokalnym `check.sh --dostepnosc`.

Sześć odmów guardu potwierdzono: production, SQLite, podobna obca nazwa,
kuking_test poza CI, obcy host i obcy DB_URL. Przygotowanie jest
transakcyjne. Sprzątanie sprawdza ID, marker i prefiks wszystkich mediów;
usuwa wyłącznie własny wpis, jego media i katalog. Odmowa usunięcia
katalogu nie zgłasza sukcesu i pozostawia rekord umożliwiający ponowienie.
Rzeczywisty chmod 0555 potwierdził odmowę; po przywróceniu uprawnień
ponowienie przeszło. Kontrolny obcy fixture pozostał nietknięty.
Po końcowym sprzątaniu: zero własnych wpisów, mediów i katalogów.
SIGKILL lub awaria hosta mogą uniemożliwić sprzątanie izolowanej próbki.

## Regresje prawdziwych źródeł

Pięć mutacji zakończyło się kodem 1 z właściwej asercji:

| Fizyczna mutacja | Wykryty problem |
|---|---|
| aspect-ratio 1/1 → auto | Utrata ramki D-191 |
| contain → cover | Utrata ramki D-191 |
| Kontrolki 20 px | Cel mniejszy niż 48 px |
| Oba PNG pionowe | Brak mieszanej próbki |
| Brak zdjęć fixture | Brak dwóch rzeczywistych zdjęć |

Po zmianach CSS rzeczywiście budowano Vite. Kopie poza repo:
`/tmp/kuking431-neg-backup-wu2_mkxo/`. MD5 i mtime przywrócono po każdej
mutacji, następnie ponownie przeszło 8 wariantów. Końcowe liczby i hashe
są w [zapisie kontroli](evidence/431/negative-results.json).

Pint, składnia obu modułów i `GaleriaMieszanychOrientacjiTest`
**5 testów / 33 asercje PASS**. Niezależny review doprowadził do
uzupełnienia kontroli cleanup. Pełnego automatu dostępności nie uruchamiano
w tym lokalnym odbiorze; wynik integracji i CI należy podać osobno.
