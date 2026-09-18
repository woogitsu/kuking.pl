# Zawieszenie pomiaru przy częściowej odpowiedzi HTTP — odtworzenie i poprawka

**Zgłoszenie:** komentarz do PR #679 z 18.09.2026, SHA `0e5d27079007195173c98768f8b474e315c1f44a`.
**Zakres:** wiarygodność **przyrządu**. To nie jest pomiar wydajności Kukinga.

> **Żadna liczba z tego dokumentu nie jest wynikiem wydajnościowym portalu.**
> Stanowiskiem jest `scripts/serwer-scenariuszy-605.mjs` — mały serwer HTTP
> na porcie przydzielanym dynamicznie, który udaje złe odpowiedzi. Aplikacja,
> baza, kolejka, media i produkcja nie były dotykane. Rampy nie uruchamiano.

---

## 1. Co było zepsute

Funkcja `zadanie()` w `scripts/generator-obciazenia-605.mjs` miała dwie
niezależne usterki, obie widoczne dopiero wtedy, gdy odpowiedź jest zła —
czyli dokładnie pod nasyceniem, po które ten przyrząd istnieje.

1. **Obietnica nie kończyła się nigdy, gdy odpowiedź została urwana po
   nagłówkach.** Rozwiązanie następowało wyłącznie w `res.on('end')` albo
   `req.on('error')`. Serwer, który wysłał nagłówki i kilka bajtów, a potem
   zamknął gniazdo, nie daje ani jednego, ani drugiego — daje `res.aborted`,
   `res.error` i `close` z `res.complete === false`.
2. **`req.setTimeout(limitMs)` to limit bezczynności gniazda, nie limit czasu
   żądania.** Każdy przychodzący fragment resetuje go od nowa, więc serwer
   sączący dane w nieskończoność trzyma pomiar bez końca.

Skutek dla serii: `wLocie` nigdy nie wracało do zera, `w_locie_szczyt` rosło
bez powodu, a domknięcie serii czekało pełne 120 s na żądania, które nie miały
się jak skończyć.

## 2. Odtworzenie — przed poprawką

Funkcja wzięta **dosłownie** z SHA `0e5d2707` (wycięta z tekstu pliku, bez
edycji) i skierowana na serwer scenariuszy. Skrypt: [`odtworzenie-przed-poprawka.mjs`](odtworzenie-przed-poprawka.mjs).
Surowe wyjście obu przebiegów: [`odtworzenie-zawieszenia.json`](odtworzenie-zawieszenia.json).

```bash
git show 0e5d270:scripts/generator-obciazenia-605.mjs > /tmp/g.mjs
node docs/infra/evidence/obciazenie605/odtworzenie-przed-poprawka.mjs /tmp/g.mjs
```

| scenariusz | `limitMs` | stan | wynik |
|---|---:|---|---|
| A. poprawna odpowiedź | 2000 | ZAKOŃCZONE po 6 ms | 200 |
| B. brak odpowiedzi | 300 | ZAKOŃCZONE po 302 ms | `timeout` |
| **C. odpowiedź urwana po nagłówkach** | 200 | **ZAWIESZONE** (watchdog 4000 ms) | brak |
| D. nagłówki bez zakończenia body | 300 | ZAKOŃCZONE po 302 ms | `timeout` |
| **E. fragmenty 12 B co 75 ms (12 sztuk)** | 200 | ZAKOŃCZONE po **906 ms** | **200 „poprawne"** |
| **F. fragmenty bez końca** | 200 | **ZAWIESZONE** (watchdog 4000 ms) | brak |

Po próbach na stanowisku zostało **1 otwarte połączenie**.

Wiersz E jest dowodem na drugą usterkę osobno: przy zadanym limicie 200 ms
żądanie zakończyło się **poprawnym** statusem 200 po 906 ms. Limit nigdy nie
dotyczył czasu żądania.

## 3. Zachowanie po poprawce

Te same sześć scenariuszy, ten sam serwer, poprawiona funkcja. Stary `limitMs`
odpowiada dziś `bezczynnoscMs`; doszedł jawny `calkowityMs`.

| scenariusz | bezczynność / całkowity | stan | powód | czas |
|---|---:|---|---|---:|
| A. poprawna odpowiedź | 2000 / 3000 | ZAKOŃCZONE | `ok` (200) | 5 ms |
| B. brak odpowiedzi | 300 / 5000 | ZAKOŃCZONE | `bezczynnosc` | 301 ms |
| **C. odpowiedź urwana po nagłówkach** | 200 / 5000 | **ZAKOŃCZONE** | **`urwana`** (5 B odebranych) | **22 ms** |
| D. nagłówki bez zakończenia body | 300 / 5000 | ZAKOŃCZONE | `bezczynnosc` | 302 ms |
| E. fragmenty 12 B co 75 ms (12 sztuk) | 200 / 5000 | ZAKOŃCZONE | `ok` (200) | 902 ms |
| **F. fragmenty bez końca** | 200 / **1000** | **ZAKOŃCZONE** | **`deadline`** | **1000 ms** |

Po próbach na stanowisku zostało **0 otwartych połączeń**.

Wiersz E zostaje celowo: wolna, ale **skończona** odpowiedź mieszcząca się
w całkowitym deadline nadal jest poprawna. Poprawka nie zamienia wolnych
odpowiedzi w fałszywe błędy — to jest kontrola przeciwna do wiersza F.

## 4. Co jeszcze zostało naprawione w serii

- **Domknięcie zawsze się kończy.** Po zakończeniu napływu seria czeka
  `--domkniecie` (domyślnie 30 s), a potem **zrywa** to, co zostało w locie,
  i liczy jako `anulowanych_przy_domykaniu`. `w_locie_na_koniec` musi być 0.
- **Ctrl+C zapisuje wynik.** Pierwszy `SIGINT`/`SIGTERM` kończy napływ, anuluje
  żądania w locie i zapisuje wynik z `przerwana: true`; drugi kończy proces.
- **Brakująca odpowiedź nie poprawia wyniku.** Żądania porzucone przez
  `--maks_w_locie` są w mianowniku `blad_percent`; pominięte z braku celu
  w manifeście stoją osobno i są opisane jako ograniczenie przyrządu.
  Wynik podaje `p95` (z poprawnych) **i** `p95_z_bledami`, a przy niezerowym
  odsetku błędów dopisuje ostrzeżenie do `razem.uwagi[]`.
- **Koniec z `process.exit(0)`.** Proces zamyka agenta i kończy się sam;
  gdyby po dwóch sekundach nadal żył, mówi to na stderr. Sprawdzone: po
  zamknięciu stanowiska i agenta proces wychodzi w 0 ms, kodem 0.
- **Literówka w liczbie zatrzymuje bieg.** Wcześniej `--rps dwadziescia` dawało
  zero wysłanych żądań, `blad_procent: 0`, puste `uwagi` i kod wyjścia 0,
  a `--calkowity duzo` — `setTimeout(fn, NaN)`, czyli 1 ms i kilkadziesiąt
  procent nieistniejących błędów.
- **Czas mierzony przed sklejeniem treści**, a seria w ogóle jej nie zbiera.
  Wcześniej `Buffer.concat().toString()` wpadało do zmierzonego czasu
  odpowiedzi; na ciałach rzędu megabajta (trasa `/zdjecia/*` to 25 % mieszanki)
  zawyżało to raportowane p95 blisko dwukrotnie.
- **Percentyl rangą najbliższą** zamiast `floor`, który przy n = 20 podawał
  maksimum jako „p95"; obok percentyli stoi teraz liczność próbki.

## 5. Regresje i kontrole ujemne

```bash
node scripts/przyrzad-605.test.mjs
```

21 sprawdzeń, ok. 40 s, bez bazy i bez aplikacji (na Windows 20: przerwania
serii sygnałem system nie wykonuje, więc to sprawdzenie jest jawnie POMINIĘTE).
W tym siedem **fizycznych kontroli ujemnych** — każda wycina fragment poprawki
z kopii generatora i wymaga, żeby odpowiadające jej sprawdzenie OBLAŁO:

| wycięte z kopii | skutek |
|---|---|
| rozpoznanie odpowiedzi urwanej (`aborted` + niepełny `close`) | zerwanie traci powód `urwana` |
| komplet obsługi zerwanego strumienia (stan z `0e5d2707`) | **żądanie wisi** |
| całkowity deadline | strumień bez końca wisi |
| anulowanie sygnałem | żądanie wisi mimo `abort()` |
| liczenie także nieudanych odpowiedzi | `blad_procent` spada do zera mimo zrywanych odpowiedzi |
| porzucone przez limit w locie w mianowniku | zdławiony napływ zaniża odsetek błędów |
| walidacja liczb z wiersza poleceń | `--rps dwadziescia` daje pusty „udany" pomiar, kod wyjścia 0 |

Przy okazji zmierzone: dla `res.destroy()` w środku body na Node 24 padają
`aborted` i niepełny `close`, a `res.on('error')` nie odpala się wcale. Ten
nasłuch zostaje na inne błędy strumienia, ale kontrola ujemna mierzy KOMPLET,
nie pojedynczy nasłuch — i tylko komplet daje zawieszenie z `0e5d2707`.

Po kontrolach test porównuje sumę MD5 oryginału generatora z sumą sprzed
uruchomienia — żeby psucie kopii nie mogło po cichu dotknąć pliku w repozytorium.

## 6. Usterka przyrządu a ograniczenie portalu

Wszystko powyżej to **usterka przyrządu**. Nie jest to obserwacja o Kukingu
i nie wolno z tego wyciągać wniosków o zachowaniu portalu pod obciążeniem.

Co to zmienia dla samego pomiaru #605: wyniki serii zdjętych przyrządem sprzed
tej poprawki byłyby niewiarygodne dokładnie tam, gdzie miały być najcenniejsze —
za punktem nasycenia, gdzie zerwane i przeciągnięte odpowiedzi są spodziewane.
W katalogu `obciazenie605/` nie ma takich wyników: rampy nie zdjęto, a jedyny
plik z czasami, `kontrola-przyrzadu.json`, pochodzi z przebiegu bez zerwań
(`blad_procent: 0`), więc jego liczby nie są tą usterką dotknięte. Nadal
obowiązuje zapisane przy nim ostrzeżenie, że **nie jest to wynik pomiarowy**.
