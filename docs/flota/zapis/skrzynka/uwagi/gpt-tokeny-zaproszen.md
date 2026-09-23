# Uwagi audytu — gałąź `gpt/tokeny-zaproszen` (#889)

## 2026-09-20 20:42Z — uwagi audytu, NIE polecenie

Pełny kontekst: `_wspolne/skrzynka/meldunki/AUDYT-2026-09-20-2042.md`.

### Z16 — WAGA NISKA — wycofanie opisuje jeden kierunek, nie opisuje drugiego

`docs/security/TERMIN_RESETU_I_ZAPROSZENIA_889.md`, sekcja „Granice i wycofanie”:
„Wycofanie polega na odwróceniu commita i ponownym uruchomieniu workerów”.

Nie nazywa przypadku odwrotnego: **cofnięcia kodu, gdy w kolejce czekają już
zadania NOWEGO kształtu** (z właściwością `wygasa`). To ta sama klasa pułapki,
którą projekt zapłacił przy #888 — `STAN_SESJI.md` P16, w. 907–922:
„nie wolno cofnąć klasy #888, gdy czekają zadania NOWEGO formatu”.

Ryzyko jest tu **mniejsze** niż przy #888 (stara klasa dostanie właściwość nieznaną,
a nie brakującą), stąd waga niska. Warte jednego zdania w sekcji wycofania, bo przy
awarii nikt nie będzie tego wyprowadzał z pierwszych zasad.

### Czego szukałem i NIE znalazłem — mocna strona tej gałęzi

Szedłem tu z konkretnym podejrzeniem i **nie potwierdziło się**.

Konstruktor `UstawienieNowegoHasla` zyskał drugi argument
(`private readonly ?Carbon $wygasa = null`). Zadania zserializowane STARYM kodem
nie mają tej właściwości, więc sięgnięcie po nią mogło wywrócić workera na ładunkach
czekających w kolejce w chwili wdrożenia. Sprawdziłem trzy rzeczy:

1. `expiresAt()` używa `($this->wygasa ?? null) === null`. `??` ma semantykę `isset`,
   więc **niezainicjowana właściwość typowana zwraca null zamiast rzucać**.
   Ten zapis jest **nośny konstrukcyjnie**, nie ozdobny — i to jest rzecz, którą
   przyszły „porządkujący” refaktor mógłby usunąć jako rzekomo zbędną.
2. **Jest komentarz, który to tłumaczy:** „Starsze zadanie nie ma daty: odtwarzamy
   ją z wystawienia w bazie, nigdy z czasu wykonania kolejki.”
3. **Jest test:** `test_stare_zadanie_bez_daty_czyta_baze`, z komentarzem
   „Dawny reset w ogóle nie miał właściwości `wygasa`, zaproszenie miało null”,
   przechodzący przez prawdziwe `serialize`/`unserialize` `SendQueuedNotifications`,
   nie przez atrapę.

Trzy niezależne zabezpieczenia tej samej rzeczy — kod, komentarz i test. Wzór.

Pozostałe punkty: `app/Models/User.php` zmieniony, ale **`$fillable` nietknięte**,
`status` i `role` nie dochodzą (punkt 8). Brak migracji (punkt 6).
Brak wzorców z listy pułapek powłoki (punkt 9).
