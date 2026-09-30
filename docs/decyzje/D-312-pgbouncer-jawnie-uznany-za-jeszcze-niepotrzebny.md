## D-312 — PgBouncer jawnie uznany za jeszcze niepotrzebny; wraca przy nazwanych progach (#600, #598, 26 września 2026)

Dotyczy **#600** (punkt definicji gotowości „PgBouncer jest wdrożony albo
jawnie uznany za jeszcze niepotrzebny”) i **#598**

**Problem.** #600 każe wdrożyć PgBouncer tylko wtedy, gdy budżet połączeń
z #598 tego wymaga, i nie dokładać warstwy „na zapas”. Liczby z #598 są
w `docs/DATABASE.md` („Budżet połączeń PostgreSQL”), ale decyzji na ich
podstawie nikt nie zapisał, więc punkt #600 wisiał otwarty bez właściciela.

**Liczby, na których stoi decyzja** (wszystkie z `docs/DATABASE.md` §598
i komentarzy #598 z 17.09.2026):

| Co | Wartość | Skąd |
|---|---|---|
| `max_connections` / rezerwa superusera | 500 / 3 → 497 miejsc | odczyt produkcji 17.09.2026 |
| równoległość HTTP na replikę | `max_threads = 4` | log startowy FrankenPHP 17.09.2026 |
| budżet szczytowy dzisiejszej topologii | 16 (6 w spoczynku, 13 w oknie wdrożenia, +3 CLI) | policzone, pomiar lokalny modelu wykonania |
| każda dodatkowa replika `web` | +4 w spoczynku, +8 w oknie wdrożenia | policzone |

Budżet zajmuje ok. 3% puli. Druga replika `web` z #600 dokłada 8 w oknie
wdrożenia, osobny worker `media` jeden proces (dwa w oknie wdrożenia).
Proces kolejki więcej w roli `all` (D-311, jeśli wejdzie) to +1 w spoczynku
i +2 w oknie wdrożenia. Żadna z tych zmian nie zbliża budżetu do progu
ostrzegawczego 50.

**Decyzja.** PgBouncera **nie wdrażamy** na obecnej i na planowanej
topologii z #595/#600 (2 repliki `web`, osobny worker, scheduler). Punkt #600
jest tą decyzją zamknięty — nie przez brak czasu, tylko przez liczby.

**Kiedy decyzja wraca — którykolwiek z warunków:**

1. `kuking:budzet-polaczen` przekracza próg ostrzegawczy (50) poza oknem
   wdrożenia — alarm na `blad_webhook`;
2. planowany budżet szczytowy (policzony wg tabeli wyżej) przekracza 125,
   czyli próg krytyczny — np. przy kilkunastu replikach `web` albo po
   podniesieniu `max_threads`;
3. zmiana planu bazy obniża `max_connections` poniżej czterokrotności
   budżetu szczytowego;
4. HA/failover Postgresa (#604) wymaga stabilnej warstwy połączeń.

**Czego ta decyzja NIE stwierdza.** Że szczyt z produkcji jest znany: szeregu
czasowego z produkcji nadal nie ma (#598 zostaje otwarte). Pierwszy odczyt
z dziennika (`scripts/szczyt-polaczen-z-dziennika.php`, `docs/DATABASE.md`
§598 G) albo alarm z warunku 1 ma pierwszeństwo przed liczbami policzonymi.

### Wycofanie
Decyzja nie zmienia kodu, schematu ani konfiguracji. Wdrożenie PgBouncera
po spełnieniu warunku to osobna zmiana (`DB_HOST`/port poolera w
`.railway/railway.ts`, tryb transakcyjny wymaga sprawdzenia `SET` sesyjnych
— m.in. `lock_timeout` z `LimitBlokadMigracji`, który migracje muszą
dostawać z bezpośredniego połączenia).
