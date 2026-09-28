# Web Push: trwałe porażki i utracone ponowienia — runbook (#2053)

Alarm „Web Push: N grup z trwałą porażką transportu … M grup z utraconym
ponowieniem …” przychodzi z `kuking:sprawdz-push` (co godzinę, minuta 50,
kanał `blad_webhook`). Ten dokument mówi, co to znaczy, jak to obejrzeć
i jak rozliczyć bez wysyłania komuś drugiej kopii.

**Najpierw spokój:** powiadomienia w serwisie są na miejscu. Nie dotarło
wyłącznie szturchnięcie na telefon albo komputer. To nie jest utrata danych.

## 1. Co czujka rozróżnia

Każda grupa pushu to wiersze `notifications` z tym samym `push_grupa_id`
(stare wiersze bez UUID liczą się osobno). Rezerwacja = `push_proba_at`.

| Stan | Warunek | Alarm? |
|---|---|---|
| w toku | `push_wyslano_at IS NULL`, `push_zakonczono_at IS NULL`, rezerwacja młodsza niż `KUKING_PUSH_OSIEROCENIE_MINUT` (30) **albo** w `jobs` czeka/trwa ponowienie `WyslijPowiadomieniePush` niosące ID **tego powiadomienia** | nie (zaległą kolejkę zgłasza `kuking:sprawdz-kolejke`) |
| **utracone ponowienie** | jak wyżej, ale rezerwacja starsza niż próg i **żadnego** ponowienia z jej ID w `jobs` | **tak** — kod `utracone_ponowienie` |
| **trwała porażka** | `push_wynik = 'porazka_transportu'` — wyczerpane `KUKING_PUSH_MAKS_PROB_TRANSPORTU` prób | **tak** — kod `porazka_transportu` |
| świadomie anulowane | `push_wynik = 'anulowano'` (przeczytane, niewidoczne, konto bez dostępu, rezerwacja > 48 h — #2052) | nie |
| rozliczone ręcznie | `push_wynik = 'zamknieto_recznie'` | nie |
| wysłane | `push_wyslano_at IS NOT NULL` | nie |

Dlaczego osłoną jest ID powiadomienia, a nie odbiorca: retry z #1992 ginie
właśnie przez zamek unikalności ŚWIEŻEGO zadania tego samego odbiorcy. To
świeże zadanie przy limicie 1/dobę liczy sierotę jako zajęty slot, dostaje
„odłóż” i przez 48 h wraca do `jobs`. Dopasowanie po odbiorcy zasłaniałoby
sierotę na cały ten czas.

Awaryjny wyłącznik (`KUKING_POWIADOMIENIA_ZEWNETRZNE=false`) wstrzymuje
liczenie utraconych ponowień — przy wyłączonym kanale zadanie wraca bez
zamknięcia rezerwacji i każda wyglądałaby na utraconą. Tabela czujki
pokazuje wtedy „kanał wyłączony: tak”. Po ponownym włączeniu rezerwacje,
których ponowienie przepadło w tym czasie, zgłoszą się jako
`utracone_ponowienie` — rozlicz je według §3.1. Trwałe porażki są liczone
zawsze. Brak `VAPID_PRIVATE_KEY` przy WŁĄCZONYM kanale nie jest wyjątkiem:
to błąd konfiguracji i alarmuje.

Dlaczego `failed_jobs` tego nie widzi: trwała porażka kończy zadanie
**sukcesem** (push nie jest listem poleconym, zadanie nie ma czego ponawiać),
a utracone ponowienie (#1992: kolizja zamka unikalności, brak klucza VAPID
w workerze, ręcznie skasowane zadanie) nie zostawia zadania wcale.

Alarm trwa, **dopóki człowiek nie rozliczy grup** (§3). Powtórka co
`KUKING_PUSH_ALARM_CISZA_GODZIN` (24 h); po rozliczeniu przychodzi jedno
odwołanie „wszystkie grupy bez wysyłki są rozliczone”.

## 2. Diagnoza — same agregaty

Najpierw sama czujka, bez dzwonienia:

```bash
php artisan kuking:sprawdz-push --bez-alarmu
```

Potem rozkład w bazie. Zapytanie **nie wybiera** identyfikatorów, adresów
urządzeń ani treści — tylko liczby i czasy:

```sql
SELECT
  CASE
    WHEN push_wynik = 'porazka_transportu' THEN 'porazka_transportu'
    WHEN push_zakonczono_at IS NULL THEN 'otwarta_rezerwacja'
  END                                                       AS stan,
  count(DISTINCT COALESCE(push_grupa_id::text, id::text))  AS grupy,
  count(DISTINCT user_id)                                   AS odbiorcy,
  min(push_proba_at)                                        AS najstarsza_rezerwacja,
  max(push_proba_at)                                        AS najnowsza_rezerwacja,
  date_trunc('hour', min(push_proba_at))                    AS godzina_poczatku
FROM notifications
WHERE push_proba_at IS NOT NULL
  AND push_wyslano_at IS NULL
  AND (push_zakonczono_at IS NULL OR push_wynik = 'porazka_transportu')
GROUP BY 1;
```

Rozkład w czasie (czy to jedna awaria usługi push, czy stały szum):

```sql
SELECT date_trunc('hour', push_zakonczono_at) AS godzina,
       count(DISTINCT COALESCE(push_grupa_id::text, id::text)) AS grupy
FROM notifications
WHERE push_wynik = 'porazka_transportu' AND push_wyslano_at IS NULL
GROUP BY 1 ORDER BY 1;
```

Co sprawdzić obok:

- dziennik serwera: `Web Push: trwała porażka transportu` (pola `proby`,
  `nieudane_urzadzenia`, `wszystkie_urzadzenia`, `juz_obsluzone`) i `Web Push: nieudana
  wysyłka` (pole `usluga` — np. FCM, Mozilla). Skok na jednej usłudze = jej
  awaria, nie nasza;
- `Web Push: brak VAPID_PRIVATE_KEY w procesie kolejki` — wtedy **każde**
  ponowienie ginie i rośnie `utracone_ponowienie`. Napraw zmienną w usłudze
  workera, dopiero potem rozliczaj;
- `php artisan kuking:sprawdz-kolejke` — czy worker w ogóle chodzi.

## 3. Rozliczenie — bezpieczna procedura

**Domyślnie ZAMYKAMY, NIE PONAWIAMY.** Z bazy nie da się odczytać, które
urządzenia tej grupy już dostały push: przy częściowym sukcesie część
telefonów go ma. Zbiorcze wyzerowanie `push_proba_at` wysłałoby im drugą
kopię — a starej treści (imię, tytuł przepisu) nie wolno powtarzać po
zmianie widoczności (#2052). Push ma sens przez chwilę; po godzinach
powiadomienie i tak czeka w serwisie.

### 3.1. Zamknięcie (zalecane)

W jednej transakcji, najpierw na sucho (`BEGIN; … ROLLBACK;`), potem
`COMMIT`. Liczba zmienionych wierszy powinna zgadzać się z diagnozą.

```sql
BEGIN;

-- trwałe porażki: rezerwacja już zamknięta, zmieniamy tylko kod
UPDATE notifications
   SET push_wynik = 'zamknieto_recznie'
 WHERE push_wynik = 'porazka_transportu'
   AND push_wyslano_at IS NULL;

-- utracone ponowienia: zamykamy rezerwację (próg jak w czujce, 30 min)
UPDATE notifications n
   SET push_zakonczono_at = now(), push_wynik = 'zamknieto_recznie'
 WHERE n.push_proba_at IS NOT NULL
   AND n.push_wyslano_at IS NULL
   AND n.push_zakonczono_at IS NULL
   AND n.push_proba_at < now() - interval '30 minutes'
   AND NOT EXISTS (
       SELECT 1 FROM jobs j
        WHERE strpos(j.payload, 'WyslijPowiadomieniePush') > 0
          AND strpos(j.payload, n.id::text) > 0);

COMMIT;
```

Następny przebieg czujki (albo `php artisan kuking:sprawdz-push`) wyśle
jedno odwołanie. `push_proba_at` zostaje — grupa nie wraca do puli.

### 3.2. Ponowienie (tylko wyjątkowo)

Tylko gdy **wszystkie** trzy warunki są spełnione:

1. dziennik pokazuje pełną awarię usługi w KAŻDYM wpisie „trwała porażka”
   z okresu awarii: `juz_obsluzone = 0` **i** `nieudane_urzadzenia` =
   `wszystkie_urzadzenia`. Samo „nieudane = wszystkie” NIE wystarcza:
   w ponowieniu `wszystkie_urzadzenia` liczy tylko urządzenia, które
   jeszcze nie dostały pushu, a te, które dostały go w pierwszej próbie,
   stoją w `juz_obsluzone`. Przy `juz_obsluzone > 0` choćby w jednym
   wpisie **ponowienie jest zakazane** — te urządzenia dostałyby drugą
   kopię; takie grupy tylko zamykasz (§3.1). Wpisy sprzed #2053 nie mają
   tego pola — dla nich też tylko zamknięcie;
2. powiadomienia są młodsze niż `KUKING_PUSH_MAKS_WIEK_GODZIN` (48 h)
   i nieprzeczytane (`read_at IS NULL`) — starszych zadanie i tak nie wyśle;
3. właściciel świadomie się zgodził (to jest operacja na danych produkcji).

Wtedy zamiast §3.1 otwórz rezerwacje z powrotem **tylko dla tych grup**:

```sql
UPDATE notifications
   SET push_proba_at = NULL, push_grupa_id = NULL,
       push_zakonczono_at = NULL, push_wynik = NULL
 WHERE push_wynik = 'porazka_transportu'
   AND push_wyslano_at IS NULL
   AND read_at IS NULL
   AND created_at > now() - interval '48 hours'
   AND push_zakonczono_at BETWEEN '<początek awarii>' AND '<koniec awarii>';
```

Grupy wrócą do puli przy następnym zdarzeniu odbiorcy i przejdą zwykłe
reguły: aktualna widoczność, cisza nocna, dzienny limit, świeża treść.
Nie wysyłaj ich pętlą z konsoli.

## 4. Konfiguracja

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `KUKING_PUSH_OSIEROCENIE_MINUT` | 30 (min. 10) | po ilu minutach rezerwacja bez zadania to utracone ponowienie |
| `KUKING_PUSH_ALARM_CISZA_GODZIN` | 24 | powtórka alarmu o tym samym stanie |
| `KUKING_PUSH_MAKS_PROB_TRANSPORTU` | 3 | ile prób, zanim zapadnie trwała porażka |

Kod: `App\Domain\Notifications\Push\StanWysylkiPush`,
`App\Domain\Notifications\Push\AlarmWysylkiPush`,
`App\Console\Commands\SprawdzPush`. Schemat: `docs/DATABASE.md`,
sekcja `notifications`, kolumna `push_wynik`.
