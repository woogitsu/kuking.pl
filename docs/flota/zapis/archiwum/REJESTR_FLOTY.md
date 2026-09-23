# Rejestr floty — sesja prowadząca z 20.09.2026

Trigger: zadanie cron `731c051c`, co ~29 min, tylko w tej sesji (ginie z sesją,
wygasa po 7 dniach). Sprawdza obsadę i uzupełnia zwolnione stanowiska.

## Obsada (10 równolegle: 5 opus + 5 sonnet)

| stanowisko | model | gałąź | worktree |
|---|---|---|---|
| r47-skan | opus | `flota/r47-skan` | `kuking-flota\r47-skan` |
| r73-feed | opus | `flota/r73-feed` | `kuking-flota\r73-feed` |
| r49-trasy | opus | `flota/r49-trasy` | `kuking-flota\r49-trasy` |
| hero-ekran | opus | `flota/hero-ekran` | `kuking-flota\hero-ekran` |
| dsa-odwolania | opus | `flota/dsa-odwolania` | `kuking-flota\dsa-odwolania` |
| gotowanie | sonnet | `flota/gotowanie` | `kuking-flota\gotowanie` |
| wyszukiwarka | sonnet | `flota/wyszukiwarka` | `kuking-flota\wyszukiwarka` |
| zeszyty | sonnet | `flota/zeszyty` | `kuking-flota\zeszyty` |
| komentarze | sonnet | `flota/komentarze` | `kuking-flota\komentarze` |
| zdjecia-formularze | sonnet | `flota/zdjecia-formularze` | `kuking-flota\zdjecia-formularze` |

Wszystkie odgałęzione od `main` = `534e0a51` (po scaleniu #790).

## Dlaczego flota stoi POZA katalogiem `Codex`

To nie jest kwestia gustu. Sesja prowadząca działa w worktree pod
`Codex\.claude\worktrees\…`, a harness **blokuje `Write`/`Edit` na każdej ścieżce
pod `C:\Users\matma\Documents\Codex`** — łącznie z `Codex\kuking.pl`. To prawdziwy
strażnik narzędziowy, nie tekst informacyjny, i **nie wolno go obchodzić zapisem
przez Bash** (Bash tamtędy przechodzi, ale strażnik istnieje właśnie po to, żeby
zapisy nie trafiały poza gałąź sesji).

Pierwsza próba postawiła flotę w `Codex\flota\…` i agenci odbijali się od bariery.
Jeden z nich **słusznie odmówił** obejścia jej przez Bash i zameldował problem —
dzięki temu przyczyna się znalazła, zamiast zostać zamaskowana. Naprawa jest
strukturalna: `git worktree move` poza `Codex`, do
`C:\Users\matma\Documents\kuking-flota\`. Nic przy tym nie zginęło — ani commity,
ani pliki robocze.

**Wystawiając nowego agenta, od razu w prompcie podaj pełną ścieżkę
`C:\Users\matma\Documents\kuking-flota\<stanowisko>`.** Jeśli agent zgłosi blokadę
zapisu, sprawdź najpierw, czy nie pracuje przypadkiem pod `Codex`.

Osobno, i nadal prawdziwe: agenci dziedziczą zdanie środowiska **„Run all commands
from this directory"** i biorą je za polecenie właściciela. Napisz w prompcie, że
mają jawne upoważnienie do pracy w swoim stanowisku floty.

## Druga pułapka: dwa repozytoria o mylących nazwach

- `C:\Users\matma\Documents\Codex` — **osobny klon, NIE ten**. Ma własne `main`,
  własne gałęzie, `origin` wskazuje na nieistniejącą ścieżkę. Tu stoi worktree sesji.
- `C:\Users\matma\Documents\Codex\kuking.pl` — **repozytorium kanoniczne**.
  `origin` = `https://github.com/woogitsu/kuking.pl.git`. Tu żyją wszystkie gałęzie
  z przekazań i wszystkie SHA się zgadzają.

## Środowisko floty

- Pamięć podręczna zależności: `/home/mateusz/flota/vendor-cache/{vendor,node_modules}`
  (kopia, nie symlink — symlink wywraca `JednoDekodowanieZdjeciaTest`).
- Runtime stanowiska: `/home/mateusz/flota/<stanowisko>-run`, stawiany przez
  `_wspolne/przygotuj-runtime.sh` (rsync całego drzewa z wykluczeniami, nie listą).
- Własna baza per stanowisko: `kuking_flota_<stanowisko>` na `127.0.0.1:55439`.
  Dzięki temu równoległe testy się nie zderzają — zderzenia z 20.09 brały się
  ze **wspólnej** bazy, nie z równoległości samej w sobie.

## Pchanie

Agenci **nie pushują**. Pchanie idzie szeregowo przez kolejki w WSL prowadzone
przez sesję. `kolejka8.sh` obejmuje wszystkie gałęzie z przekazania §3, łącznie
z force-pushami dla #725 (`fad7bc83`) i #786 (`271d2665`) oraz priorytetowym
`fix/m4-przeplyw-pomiar` (`76f385f9`). Jedno pchnięcie = 6–7 min (hook `pre-push`
uruchamia pełną baterię).

## Kolejka pchania floty (`kolejka9.sh`)

Czeka na `kolejka8.sh`, potem pcha gałęzie **z pliku, który można dopisywać w locie**:

```
/home/mateusz/flota/do-pchniecia.txt   ← dopisz gałąź, gdy stanowisko zamelduje gotowość
/home/mateusz/flota/pchniete.txt       ← co już poszło (skrypt sam dopisuje)
/home/mateusz/flota/kolejka9.log       ← przebieg
```

Dopisanie gałęzi:
```
wsl -d Ubuntu -- bash -lc "echo 'flota/<stanowisko>' >> /home/mateusz/flota/do-pchniecia.txt"
```

Skrypt kończy się po 20 minutach bez nowych pozycji. Gdy zgaśnie, a coś czeka —
uruchom go ponownie tym samym poleceniem (czyta `pchniete.txt`, więc nie powtórzy).

**Szeregowo, bo dwa równoległe przebiegi zderzają się na bazie** i dają fałszywe
porażki: 142 failures, deadlocki, `relation "contact_messages" does not exist`.
Jedno pchnięcie trwa 6–7 min, bo hook `pre-push` uruchamia pełną baterię.
W tym repozytorium **nie ma hooka `pre-commit`** — pchnięcie jest pierwszym sprawdzeniem.

## ZMIANA TRYBU — 20.09.2026, polecenie właściciela

**Najwyżej 3 agentów Opus naraz. Skończonych agentów NIE zastępujemy nowymi.**
Powód: zużycie limitu. Flota wygasza się przez naturalne ubywanie, nie przez
ubijanie pracujących — ubicie w locie traci to, co już zostało wydane, i gubi
niezacommitowaną pracę.

Gałęzie skończonych stanowisk nadal dopisujemy do kolejki pchania — to nic nie
kosztuje i bez tego praca nie trafi do repozytorium.

## AWARIA PCHANIA 20.09.2026 — przyczyna i naprawa

Przez ponad trzy godziny kolejki meldowały postęp, którego nie było. Złożyły
się na to **trzy** usterki, każda maskująca poprzednią.

### 1. Skrypt uznawał nieudane pchnięcie za udane

`echo "$br" >> "$ZROBIONE"` stało bezwarunkowo po `git push`. Gałąź, której
pchnięcie padło, znikała z kolejki tak samo cicho jak wypchnięta. **Poprawione:**
wpis do „zrobionych" tylko przy kodzie wyjścia zero; porażki idą do
`nieudane.txt` i wracają do kolejki, a po trzech próbach odpadają z adnotacją.

### 2. Stanowisko pchania było OSIEROCONE

`/home/mateusz/kuking-paneldiag-run/.git` wskazywał na
`/home/mateusz/kuking-703-push` — worktree, który przestał istnieć. Skutek:
`git fetch` i `git checkout` cicho nie działały, **drzewo nigdy się nie
zmieniało**, a hook mielał stary, pomieszany kod. Objaw był mylący, bo liczba
porażek ROSŁA między próbami tej samej gałęzi: 202 → 235 → 683, z „błędem
składni PHP", którego nie było w żadnej naszej gałęzi.

To ten sam objaw, przed którym ostrzega przekazanie: `fatal: not a git
repository` przy istniejącym katalogu i pozornie poprawnym pliku `.git`.

**Poprawione strukturalnie:** stanowisko jest teraz **klonem**
(`/home/mateusz/flota/push-run`), nie worktree. Klonu nie da się osierocić ani
przyciąć — znika cała klasa tej awarii. Ma własny `vendor`, własny `.env`
z kluczem i zainstalowany hook `pre-push`, więc bramka działa.

### 3. Weryfikacja po NAZWIE gałęzi zamiast po SHA

Odtwarzając listy, porównywałem nazwy gałęzi z `ls-remote`. `codex/717-panel-kolejki`
i `praca/izolacja-bazy-klon` są na origin — ale pod **starymi** SHA, bo
force-pushe nigdy nie przeszły. Porównanie po nazwie uznawało je za zrobione.
**Poprawione:** `_wspolne/odtworz-listy.sh` porównuje SHA lokalny ze zdalnym.

### Dwie rzeczy warte zapamiętania

- **Kolejka, która nie sprawdza kodu wyjścia, jest gorsza niż brak kolejki** —
  bo melduje postęp. Trzy godziny raportowałem wypchnięte gałęzie, a wypchnięta
  była jedna.
- **Zawieszony `git-remote-https` palił cały rdzeń przez 33 godziny.** Osierocony,
  bez postępu, zostawiony przez wcześniejszą kolejkę. Warto go szukać przy
  każdym niewyjaśnionym obciążeniu: `ps -eo pid,etime,pcpu,args | grep git-remote`.

### Próg obciążenia

Obie kolejki czekają, aż `load average` spadnie poniżej **8**, i sprawdzają co
minutę. Powód: hook uruchamia pełną baterię na współdzielonym PostgreSQL-u,
a pod obciążeniem daje to `Deadlock detected` i kilkadziesiąt fałszywych
porażek — czerwień, która nic nie mówi o kodzie, za cenę siedmiu minut.

## Wdrożenie potwierdza się na stronie, nie w panelu — 20.09.2026

Po scaleniu #789 zobaczyłem w Railway wdrożenie w stanie **`WAITING`**
i zameldowałem właścicielowi „wydanie jest w drodze". Skończyło się stanem
**`SKIPPED`** o 15:39 i na produkcji dalej stał poprzedni commit.

**Przyczyna, której nie znałem, meldując:** CI jest bramką wdrożenia.
Czerwone zadanie `Port marki` → workflow `Deploy` pominięty → Railway nie
wdraża. Jedna migocząca asercja minutnika zatrzymuje wydanie tak samo
skutecznie jak prawdziwa usterka.

**Błąd był jednak mój, nie Railwaya:** podałem **stan przejściowy jako wynik**.
`WAITING` nie znaczy „wyjdzie", tylko „jeszcze nie wiadomo".

**Reguła:** wdrożenie potwierdzasz **na żywej stronie**, nie w panelu:

```
curl -s https://kuking.pl/o-kuking | grep -o "wydanie [^<]*"
```

Stopka niesie datę wydania i skrócony SHA. Tego odczytu nie da się pomylić
ze stanem przejściowym. Ta sama reguła co przy kolejce pchania:
**sprawdzaj skutek, nie zapowiedź skutku** — patrz sekcja o awarii pchania,
gdzie „5 wypchniętych" oznaczało naprawdę jedną gałąź.
