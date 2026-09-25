## D-183 · Martwe zadanie z kolejki kasuje się po wygaśnięciu żetonu, nie ponawia

**Data:** 12 września 2026 · PR #460 · Status: **obowiązuje**

### Kontekst

`/health` stoi w `degraded` przez cztery zadania `UstawienieNowegoHasla` z 9 września
2026, czyli z awarii SMTP naprawionej przez D-047.

### Dlaczego `queue:retry` jest tu odpowiedzią złą

`config/auth.php` daje żetonowi resetu hasła **60 minut od wystawienia**. Ponowienie po
dniach wysłałoby ludziom list o zmianie hasła z linkiem, który już nie działa. Człowiek,
który o nic dziś nie prosił, klika i widzi „link wygasł". Odruch „ponów, co padło" jest
tu gorszy niż nicnierobienie.

### Decyzja

`kuking:martwe-zadania` rozdziela dwa przypadki, których `queue:retry` i `queue:flush`
nie rozdzielają:

- pokazuje, co stoi w `failed_jobs` — kiedy, jaka klasa i **ilu ludzi** to dotyczy
  (różne konta, nie wiersze: jedna osoba klikała zwykle kilka razy);
- kasuje **wyłącznie** zadania, których żeton już nie żyje, a próg czyta z konfiguracji
  osobno dla każdej klasy (60 min z `config/auth.php`, 30 min z `config/kuking.php`);
- trybem domyślnym jest `--na-sucho`, a przy `--skasuj --na-sucho` naraz wygrywa ta
  intencja, **którą da się cofnąć**;
- zadania spoza listy żetonów i te z żetonem jeszcze żywym zostają nietknięte.

### Żeton nie wychodzi na ekran w żadnej gałęzi

Także w tej, w której komenda nie umie odczytać wiersza. Surowego `payload` ani
`exception` nie drukujemy nigdzie, a `unserialize()` dostaje listę dozwolonych klas,
na której klas powiadomień nie ma. Pilnuje tego osobny test z **kontrolą dodatnią**
(asercją, że żeton naprawdę leży w ładunku) — bez niej asercja „żetonu nie widać"
przechodziłaby też wtedy, gdyby żetonu tam w ogóle nie było.

📄 `app/Console/Commands/MartweZadania.php` · `app/Http/Controllers/HealthController.php` ·
`MartweZadaniaTest` · D-047 · D-057
