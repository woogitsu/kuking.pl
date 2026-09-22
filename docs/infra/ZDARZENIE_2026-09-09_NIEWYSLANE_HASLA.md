# Zdarzenie 9 września 2026 — cztery listy „Ustaw nowe hasło”, które nie doszły

**Zapisane 12 września 2026.** Przyczyna jest znana i naprawiona w kodzie.
Ten dokument nie opisuje usterki do naprawienia — opisuje **cztery osoby,
które poprosiły o możliwość powrotu na swoje konto i nie dostały nic**,
oraz cztery wiersze w `failed_jobs`, które po nich zostały.

> **Zanim skasujesz cokolwiek z `failed_jobs`: przeczytaj rozdział 5.**
> Te wiersze wyglądają na śmieć po naprawionej awarii. Nie są śmieciem —
> są jedyną listą osób, którym należy się odpowiedź.

---

## 1. Co dokładnie padło

Wynik `php artisan queue:failed` z produkcji, wklejony przez właściciela
12 września 2026. Cztery zadania, wszystkie tej samej klasy, wszystkie
z 9 września:

| `failed_at` (UTC) | uuid zadania | Kolejka | Klasa |
|---|---|---|---|
| 2026-09-09 16:24:17 | `f68960e4-cc23-4caf-b772-bfce594788f5` | `database@default` | `App\Notifications\UstawienieNowegoHasla` |
| 2026-09-09 14:54:27 | `6cc31ba9-d1d5-4883-a345-ef59e1a745ff` | `database@default` | `App\Notifications\UstawienieNowegoHasla` |
| 2026-09-09 14:42:32 | `0b75523f-19bf-4411-997f-e7042d5a8b65` | `database@default` | `App\Notifications\UstawienieNowegoHasla` |
| 2026-09-09 14:05:04 | `91812b8d-3cdd-4d27-b8e9-b2db2434141a` | `database@default` | `App\Notifications\UstawienieNowegoHasla` |

**Od 9 września nic więcej nie padło.** Zdarzenie jest zamknięte w czasie:
cztery wiersze, dwie i pół godziny, jedna klasa.

Klasa ma znaczenie. `App\Notifications\UstawienieNowegoHasla` to, jak mówi
jej własny docblock, **„jedyna droga powrotu dla kogoś, kto wypadł z konta”**.
Nie newsletter i nie powiadomienie o cudzym komentarzu. Cztery osoby kliknęły
tego dnia „Nie pamiętam hasła”, zobaczyły na ekranie, że list poszedł,
i czekały na wiadomość, która nigdy nie wyszła z serwera.

---

## 2. Dlaczego to padło

**Railway blokuje ruch SMTP na planach Free, Trial i Hobby.** Wychodzi
dopiero od planu Pro. Właściciel jest na Free.

Objaw zmierzony 9 września 2026 w dzienniku Railwaya: zadanie
`App\Notifications\UstawienieNowegoHasla` wchodziło w `RUNNING`
i **nigdy się nie kończyło** — ani `DONE`, ani `FAIL`. Pakiety szły
w próżnię, połączenie wisiało do timeoutu kontenera.

To była **trzecia z rzędu cicha awaria poczty tego samego dnia**, po
`MAIL_MAILER=log` i po `MAIL_SCHEME=tls`. Dwie pierwsze warstwy były wtedy
już naprawione — dostawca poprawny, hasło poprawne, worker chodził — więc
wyglądało to jak awaria bez przyczyny.

Pełny opis warstwy: `docs/infra/POCZTA_URUCHOMIENIE.md`, rozdział 0,
„Trzecia warstwa: plan Railway wyłącza SMTP”.

---

## 3. Co było naprawą

**Własny transport HTTPS do EmailLabs** — `App\Poczta\TransportEmailLabs`,
decyzja **D-047**. Zamiast SMTP, którego Railway nie przepuści, wysyłka idzie
`POST https://api.emaillabs.io/v2.1/email`. Zero nowych paczek Composera
(transport dziedziczy po `symfony/mailer`, który i tak jest w projekcie).

Dlaczego nadal EmailLabs, skoro trzeba było pisać własny transport: powód
prawny, nie techniczny. Vercom S.A. z Poznania, serwery w EOG — dzięki temu
w polityce prywatności zostaje zdanie „Twój adres e-mail przetwarzamy
w Polsce”. Analiza: `docs/decyzje/POCZTA.md` §2.

Drugą połową naprawy było to, żeby **następna taka awaria nie była cicha**:
`App\Poczta\OdmowaEmailLabs` dziedziczy po `TransportException`, więc zadanie
kończy się `FAIL` i widać je w `failed_jobs`, a `App\Poczta\ZapiszNieudanyList`
zakłada trwały ślad w `mail_failures`, o którym mówi `/health` i komenda
`kuking:nieudane-listy` (issue #234, **D-062**).

**Uwaga, która tłumaczy, dlaczego ten dokument w ogóle jest potrzebny:**
tamten mechanizm powstał **10 września**, czyli dzień PO tym zdarzeniu.
Wierszy z 9 września w `mail_failures` **nie ma i nigdy nie będzie**.
Jedynym śladem po tych czterech listach jest `failed_jobs`.

---

## 4. Co zostało do zrobienia z resztkami

Dwie rzeczy, w tej kolejności.

### 4.1. Ustalić, kogo to dotyczyło

```bash
php artisan kuking:kto-nie-dostal-listu
```

Komenda czyta `failed_jobs`, rozpakowuje ładunek zadania i wypisuje, do kogo
list nie doszedł i kiedy. **Niczego nie kasuje, niczego nie wysyła i niczego
nie ponawia** — nie ma nawet przełącznika, który by to zmieniał.

Czego się po niej spodziewać:

* cztery wiersze klasy `UstawienieNowegoHasla` z datami z 9 września;
* przy każdym: nazwa z profilu, identyfikator konta, uuid zadania
  i **adres e-mail w postaci skróconej** (`m***@przyklad.pl`);
* akapit „CO Z TYM ZROBIĆ” z ostrzeżeniem o `queue:retry`.

Adresy są skracane domyślnie, bo komenda chodzi w konsoli Railwaya, a jej
zawartość ląduje na zrzutach ekranu. Pełne adresy — dopiero gdy naprawdę
piszesz do tych osób: `--pelne-adresy`. Uzasadnienie tego cięcia (i tego,
dlaczego domena zostaje widoczna) stoi w docblocku komendy.

Adres pochodzi **z konta, nie z ładunku**: w `failed_jobs.payload` adresu
nie ma wcale, jest tylko identyfikator konta. Jeśli ktoś zmienił adres po
awarii, zobaczysz ten nowy; jeśli konto zostało usunięte albo zanonimizowane
(D-022), komenda powie to wprost.

### 4.2. Odpowiedzieć tym osobom, a dopiero POTEM sprzątnąć

Sprzątanie (`php artisan queue:forget <uuid>` albo `php artisan queue:flush`)
to **decyzja właściciela** i ma zapaść po tym, jak te cztery osoby dostaną
odpowiedź. Nie odwrotnie: skasowanie wierszy kasuje jedyną listę adresatów,
którą jeszcze mamy.

---

## 5. Dlaczego `queue:retry` jest tu złą odpowiedzią

Odruch jest oczywisty: skoro poczta jest naprawiona, to wystarczy ponowić
zadania i listy wyjdą. **Nie wystarczy — i byłoby to gorsze niż cisza.**

**Link w tym liście jest ważny 60 minut od WYSTAWIENIA, nie od wysłania**
(`config/auth.php`, `auth.passwords.users.expire`). Token powstał 9 września
około 14:05–16:24 i wygasł tego samego popołudnia. `queue:retry` uruchomione
12 września wyśle więc list z linkiem, który jest **martwy w chwili wysłania**.

Człowiek po drugiej stronie zobaczy wtedy wiadomość o zmianie hasła, o którą
**dziś nie prosił**, kliknie i dostanie błąd. Dla naszej grupy 50+ to jest
dokładnie ten scenariusz, przed którym ostrzega ją bank: niespodziewany list
o haśle z linkiem, który nie działa. Po trzech dniach ciszy jest to gorsza
odpowiedź niż brak odpowiedzi.

**Dobra odpowiedź jest jedna: poprosić te cztery osoby, żeby jeszcze raz
kliknęły „Nie pamiętam hasła”.** Wtedy powstaje świeży token i świeży, żywy
link, a list idzie już naprawionym transportem HTTPS. Kanałem tej prośby jest
skrzynka kontaktowa albo — jeśli właściciel zna te osoby — zwykła wiadomość
od człowieka do człowieka. Nie automat.

Czego **nie** wolno zrobić zamiast tego: wystawić im nowego tokenu
samodzielnie i wysłać linku, o który nikt nie prosił. Reset hasła ma zaczynać
się po stronie człowieka, nie po stronie serwisu.

---

## 6. Rzecz do rozstrzygnięcia: te cztery wiersze trzymają `/health` na `degraded`

**Opis problemu, nie wdrożona zmiana.** `HealthController::sprawdzKolejke()`
liczy **wszystkie** wiersze `failed_jobs`:

```php
$nieudane = DB::table('failed_jobs')->count();
...
if ($nieudane === 0) { return; }
```

Dopóki te cztery trzydniowe wiersze stoją w tabeli, `/health` mówi
`degraded` — i będzie mówił tak w nieskończoność. Koszt jest realny i nie
polega na tym, że „świeci się na pomarańczowo”:

1. **następna, prawdziwa awaria kolejki niczego nie zmieni w tym polu.**
   Cztery wiersze zamieniają się w pięć, `status` był `degraded` i nadal
   jest `degraded`. Alarm, który dzwoni bez przerwy, nie jest alarmem;
2. `docs/infra/MONITORING_BLEDOW.md` §„Co monitorować OPCJONALNIE: stan
   `degraded`” zachęca do pilnowania właśnie tego stanu z zewnątrz — a taki
   monitor po 9 września jest permanentnie czerwony i zostanie wyłączony
   przez człowieka, który ma go dość;
3. skutkiem ubocznym jest presja, żeby **skasować wiersze dla uspokojenia
   sondy**, czyli dokładnie ta czynność, przed którą ostrzega rozdział 5.

Warto zauważyć, że sonda `checks.listy` (`mail_failures`, D-062) ma na to
gotowy wzorzec: wiersz da się **odhaczyć** (`kuking:nieudane-listy --odhacz`),
co gasi alarm, nie kasując śladu. `checks.kolejka` takiego rozróżnienia nie ma
— zna tylko „zero” i „nie zero”.

**Propozycja do decyzji właściciela** (nie wdrożona; `/health` jest w tym
tygodniu przedmiotem równoległej pracy, więc zmiana tam wymaga osobnego PR-a):

* **Wariant A — okno czasowe.** `checks.kolejka` liczy wiersze z ostatnich
  N godzin (np. 24), a starsze wypisuje w polu informacyjnym bez zmiany
  `status`. Zaleta: zero nowych tabel i kolumn. Wada: awaria sprzed 25 godzin
  przestaje być widoczna sama z siebie.
* **Wariant B — odhaczanie, jak przy `mail_failures`.** Znacznik „właściciel
  to widział” trzymany osobno (wiersze `failed_jobs` zostają nietknięte),
  a sonda liczy tylko nieodhaczone. Zaleta: spójne z `checks.listy`, alarm
  gaśnie świadomą czynnością człowieka, a ślad zostaje. Wada: nowa tabelka
  albo kolumna, czyli migracja.

**Rekomendacja: wariant B**, bo jest tym samym pomysłem co D-062 i nie
wprowadza drugiego, innego sposobu myślenia o tej samej rzeczy. Ale to jest
decyzja właściciela, nie autora tego dokumentu.

---

## 7. Czego to zdarzenie uczy na przyszłość

1. **Cicha awaria jest gorsza od głośnej i to się już zmaterializowało
   trzy razy jednego dnia.** Naprawa D-047 zdejmuje przyczynę, D-062 zdejmuje
   ciszę — ale oba mechanizmy powstały PO tym zdarzeniu, więc na to zdarzenie
   nie zadziałały.
2. **`failed_jobs` bywa jedynym miejscem, w którym została lista ludzi.**
   Zanim ktoś zrobi `queue:flush` „bo to stare”, ma mieć czym sprawdzić,
   kto za tymi wierszami stoi. Po to powstała `kuking:kto-nie-dostal-listu`.
3. **Ponowienie zadania nie jest neutralne, gdy zadanie niesie coś, co
   wygasa.** Przy listach z tokenem (`UstawienieNowegoHasla`,
   `PotwierdzenieAdresu`, zaproszenia, zmiana adresu) `queue:retry` po czasie
   ważności wysyła martwy link. To dotyczy każdej przyszłej awarii poczty,
   nie tylko tej.

---

## Referencje

| Co | Gdzie |
|---|---|
| Przyczyna i naprawa (transport HTTPS) | `app/Poczta/TransportEmailLabs.php`, D-047 |
| Dlaczego odmowa nie jest cicha | `app/Poczta/OdmowaEmailLabs.php`, issue #234, D-062 |
| Klasa listu i jej rola | `app/Notifications/UstawienieNowegoHasla.php` |
| Ważność linku (60 minut) | `config/auth.php`, `auth.passwords.users.expire` |
| Kto nie dostał listu (ta komenda) | `app/Console/Commands/KtoNieDostalListu.php` |
| Listy odrzucone przez dostawcę (od 10.09) | `app/Console/Commands/NieudaneListy.php`, tabela `mail_failures` |
| Trzy warstwy awarii poczty | `docs/infra/POCZTA_URUCHOMIENIE.md` §0 |
| Monitoring i stan `degraded` | `docs/infra/MONITORING_BLEDOW.md` §6 |
| Wybór dostawcy (powód prawny) | `docs/decyzje/POCZTA.md` §2 |
