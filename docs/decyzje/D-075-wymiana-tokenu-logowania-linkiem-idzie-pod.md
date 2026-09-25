## D-075 · Wymiana tokenu logowania linkiem idzie pod blokadą wiersza konta — a konflikt unikalności kończy się tą samą neutralną odpowiedzią co adres bez konta

**Data:** 10 września 2026 · Ustalenie **AUTH-02 / RACE-02 (P1)** z audytu
drugiej warstwy · Status: **obowiązuje**

### Co było złamane — zmierzone, nie wywnioskowane

`WyslijLinkDoLogowania` kasowało poprzedni token i zakładało nowy w jednej
transakcji, ale **bez blokady wiersza konta**:

```php
// app/Domain/Security/WyslijLinkDoLogowania.php, stan sprzed tej zmiany
LoginLinkToken::query()->where('user_id', $user->getKey())->delete();
// … a potem INSERT nowego wiersza
```

`login_link_tokens.user_id` jest unikalne (i **ma takie zostać** — to jest
własność bezpieczeństwa z D-056: jeden ważny link na konto, nowa prośba
unieważnia poprzednią). Bez serializacji dwie prośby naraz przechodziły
`DELETE` — każda kasując zero wierszy, bo każda widziała już posprzątane — i
obie szły do `INSERT`. Jedna odbijała się o constraint.

**Brakowało serializacji, nie constraintu.** To jest cała diagnoza.

### Dlaczego to była sprawa bezpieczeństwa, a nie tylko brzydki błąd

Ten formularz jest **świadomie zaprojektowany jako nieodróżnialny** dla adresu
z kontem i bez konta (D-056): ekran mówi „Jeśli na adres … jest konto
w Kuking, wysłaliśmy tam wiadomość", właśnie po to, żeby nie dało się
sprawdzać, kto tu gotuje. Tymczasem:

- dla adresu **bez konta** obie równoległe prośby kończą się spokojną ścieżką
  „nic nie wysyłamy" — bo `handle()` wychodzi, zanim dojdzie do zapisu;
- dla adresu **z kontem** jedna z nich wywalała `UniqueConstraintViolationException`.

**Zmierzone w tym repozytorium przed poprawką**
(`tests/Feature/WyscigLinkuDoLogowaniaTest.php` na `main` @ `fd164ad`,
z wymuszonym konfliktem):

| adres | odpowiedź HTTP |
|---|---|
| jest konto | **500** |
| nie ma konta | **302** |

Nic tego wyjątku nie przechwytywało: leciał do HTTP jako 500. Para
równoległych żądań była więc kanałem enumeracji, i to takim, którego żaden
wspólny komunikat nie zasłania — bo różnicę robił sam kod odpowiedzi.
Turnstile (D-050) i limit trzech próśb na adres na godzinę utrudniają masowe
użycie, ale **nie usuwają złamania kontraktu**: pytanie „czy tu jest konto"
dawało się zadać.

Drugą stroną tej samej usterki jest rzecz zwyczajna: **dwuklik „Wyślij" dawał
500**. Logowanie linkiem jest dla osób 60+ drogą podstawową, nie awaryjną
(`docs/research/AUDIENCE_50_PLUS.md`, D-056), więc podwójne kliknięcie
przycisku jest tam scenariuszem typowym, nie skrajnym.

### Co jest teraz

1. **Blokada wiersza konta przed `DELETE`** — `SELECT … FROM users … FOR
   UPDATE` w tej samej transakcji, w której idzie `DELETE` + `INSERT`. Dwie
   równoległe prośby o link na to samo konto ustawiają się w kolejce, zamiast
   wyprzedzać się nawzajem.
2. **Świeży odczyt konta pod blokadą.** Blokada serializuje, ale nie mówi
   żądaniu, które czekało, że świat się w tym czasie zmienił. Konto mogło
   między odczytem po adresie a wejściem pod blokadę zostać zablokowane albo
   dostać rolę moderatora — a link wchodzący tam, gdzie nie wchodzi hasło,
   byłby obejściem blokady moderacyjnej. Pod blokadą pytamy o to ponownie.
3. **Defensywne przechwycenie `UniqueConstraintViolationException`** →
   `null` → dokładnie ta sama neutralna odpowiedź, którą dostaje adres bez
   konta: bez listu, bez wpisu w dzienniku audytu, bez zajmowania budżetu
   poczty. Blokada powinna wystarczyć, ale **kontrakt antyenumeracyjny nie
   może zależeć od tego, że blokada nigdy nie zawiedzie** — zawieść może
   z powodów spoza tej metody (przyszły drugi punkt wystawiający token,
   komenda konsolowa, seeder, wywołanie akcji wewnątrz cudzej transakcji).
   Ta sama konstrukcja co w `ReportContent` i `ZglosNielegalnaTresc`.

Czego świadomie **nie** ruszono: treści komunikatu na ekranie logowania
linkiem (napisana po realnej pomyłce 63-letniej testerki, PR #257),
konsumpcji tokenu w `LoginLinkController::store()` (audyt sprawdził ją
osobno — transakcja + `lockForUpdate`, jednorazowość, GET nie konsumuje),
`UNIQUE(user_id)` i wykluczenia moderatorów oraz administratorów z tej drogi.

### Blokada własna, nie `App\Domain\Users\ZamekKonta` — i dlaczego

Ta sama sesja dodała `App\Domain\Users\ZamekKonta` — jedną kolejność blokad
dla operacji na zmianie adresu e-mail (ustalenie AUTH-01 / RACE-01, gałąź
`claude/wyscig-zmiany-adresu`). Ta zmiana **nie używa tamtej klasy**, i to
jest wybór, nie przeoczenie:

- **`ZamekKonta` nie istnieje jeszcze na `main`** ani na żadnej wypchniętej
  gałęzi. Oparcie się na niej robi z tej poprawki bezpieczeństwa zakładnika
  cudzego, niescalonego PR-a — a to jest poprawka P1, która ma dać się
  scalić samodzielnie i samodzielnie być zielona.
- **Dokumentacja by kłamała.** Cały komentarz `ZamekKonta` opisuje wyścig
  przy zmianie adresu e-mail. Skopiowany tutaj wcześniej niż tamta poprawka
  wnosiłby do repozytorium klasę tłumaczącą usterkę, której na `main` nikt
  jeszcze nie naprawił. To jest dokładnie ten dryf dokumentacji, który audyt
  wskazuje jako największe ryzyko tego repozytorium.
- **Druga klasa o tej samej roli byłaby gorsza od obu wyjść.** Własna
  `ZamekTokenu`/`ZamekKonta2` obok tamtej to gwarantowana kolizja nazw
  i fałszywy wybór dla następnej osoby. Dlatego nie ma tu **żadnej** nowej
  klasy: blokada siedzi w prywatnej metodzie
  `WyslijLinkDoLogowania::wymienToken()`, jedynym miejscu, które jej dziś
  potrzebuje.

**KOLEJNOŚĆ BLOKAD ZOSTAJE JEDNA W CAŁYM REPOZYTORIUM: KONTO NAJPIERW.**
To jest ta część, która nie podlega negocjacji, bo dwie różne kolejności
blokad to zakleszczenie, które PostgreSQL rozwiązuje zabiciem jednego
z żądań. Tu blokujemy `users`, a potem dopiero piszemy po
`login_link_tokens` — tak samo, jak `ZamekKonta` blokuje `users`, a potem
`pending_email_changes`. Kolejność jest **pilnowana testem**
(`test_wymiana_tokenu_idzie_pod_blokada_wiersza_konta`), nie tylko
komentarzem: test oblewa się zarówno wtedy, gdy blokady nie ma, jak i wtedy,
gdy jest brana po `DELETE`.

Blokujemy wiersz **konta**, a nie wiersz tokenu, z tego samego powodu co
w `ZamekKonta`: wiersza tokenu może nie być, a `SELECT … FOR UPDATE` na
nieistniejącym wierszu nie blokuje niczego i nie powstrzyma drugiego
`INSERT`-a. Konto istnieje zawsze i jest wspólne dla obu próśb.

**Do zrobienia po scaleniu `claude/wyscig-zmiany-adresu`:** przenieść to
jedno wywołanie na `ZamekKonta::zablokuj()` i skasować prywatną transakcję
tutaj. To jest sprzątanie, nie poprawka — kolejność blokad jest już zgodna,
więc zwłoka nie tworzy ryzyka zakleszczenia.

**Zmiana wymaga:** drugiego miejsca wystawiającego token logowania linkiem
(wtedy blokada MUSI wyjść z tej klasy do wspólnego zamka, bo dwie kopie tej
samej kolejności rozjadą się przy pierwszej zmianie) albo rezygnacji
z `UNIQUE(user_id)` na `login_link_tokens` — czyli z zasady „jeden ważny
link na konto" (D-056), a to jest osobna decyzja i dziś nie ma dla niej
powodu.

📄 `app/Domain/Security/WyslijLinkDoLogowania.php` ·
`app/Http/Controllers/Auth/LoginLinkController.php` ·
`database/migrations/2026_09_10_100000_create_login_link_tokens_table.php` ·
`tests/Feature/WyscigLinkuDoLogowaniaTest.php` ·
`tests/Feature/LogowanieLinkiemTest.php` ·
`app/Domain/Users/ZamekKonta.php` (po scaleniu `claude/wyscig-zmiany-adresu`) ·
D-048 · D-050 · D-056
