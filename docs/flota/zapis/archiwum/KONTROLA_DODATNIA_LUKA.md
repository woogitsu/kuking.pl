# Luka kontroli dodatniej — co robi mechanizm, czego nie łapie i co proponuję

**20 września 2026, 20:42Z.** Wynik zadania A drugiego przebiegu audytu.
To jest **propozycja zmiany instrukcji dla całej floty**, nie polecenie i nie poprawka.
Niczego nie zmieniono w kodzie.

---

## 1. Co ten mechanizm naprawdę robi

`scripts/kontrole-negatywne-alfa08.py`, wołany z `.github/workflows/ci.yml:565`.

Dla każdego wpisu z listy `checks` (dziś **pięć wpisów**, dotyczących **trzech** testów):

1. kopiuje plik źródłowy do katalogu tymczasowego, liczy MD5 „przed”;
2. **mutuje źródło** — celowo psuje dokładnie to, czego test pilnuje;
3. sprawdza, że MD5 się zmieniło (`Mutacja nie zmieniła źródła.` → przerwanie);
4. uruchamia test i **wymaga, żeby OBLAŁ** — i to nie byle jak:
   `if not expected_success and "FAILED" not in result.stdout: raise RuntimeError("Brak dowodu niezaliczonej asercji; sama awaria procesu nie wystarczy.")`
   — czyli sama wywrotka procesu nie jest uznawana za czerwień;
5. przywraca plik i sprawdza, że MD5 wróciło do „przed”;
6. uruchamia test ponownie i wymaga, żeby **przeszedł**.

To jest dobrze zrobione. `replace_once()` odmawia, gdy wzorzec mutacji trafia
w zero albo w więcej niż jedno miejsce — czyli mechanizm sam się psuje głośno,
gdy kod ucieknie spod niego. **Nie mam zastrzeżeń do tego, jak działa.**
Problem jest w tym, czego nie obejmuje.

## 2. Co znaczy brak wpisu dla nowej poprawki

Test, który **czyta źródło i asertuje na tekście** (CSS, Blade, workflow, skrypt),
przestaje cokolwiek pilnować w chwili, gdy pilnowana rzecz zmieni nazwę albo
zostanie przykryta inną regułą. Wtedy nie świeci na czerwono — **świeci na zielono
i nie sprawdza niczego**. To ta sama klasa usterki co „skan, który nie znajduje
żadnego pliku, PRZECHODZI”, tylko przesunięta o jeden poziom: skan działa,
ale mierzy pustkę.

Wpis w `checks` jest jedynym mechanizmem w tym repozytorium, który **zapisuje na
trwałe** dowód, że dany test potrafi zapalić. Bez wpisu dowód istnieje najwyżej
w pamięci stanowiska, które go kiedyś uruchomiło.

## 3. Skala — liczby z dzisiejszego drzewa

Policzone na `origin/main`, wzorzec „test czyta źródło”:
`file_get_contents(` albo `resource_path(` albo `base_path(`.

| Miara | Liczba |
|---|---:|
| Wszystkich plików testowych (`tests/Feature`, `tests/Unit`) | **599** |
| Z nich: testy czytające źródła (strażnicy tekstu) | **170** |
| Z nich: objętych wpisem w `checks` | **3** |
| **Strażników tekstu bez zapisanego dowodu, że potrafią zapalić** | **167** |

Trzy objęte to `WyborZeszytuMaWalidacjeTest`, `KafelDodawaniaPrzyDuzymTekscieTest`
oraz pojedyncza metoda `test_wejscie_do_panelu_pokazuje_sume_kolejek`.

**Zastrzeżenie granicy pomiaru:** 170 to liczba plików pasujących do wzorca
tekstowego, nie orzeczenie, że każdy z nich jest strażnikiem wymagającym
kontroli dodatniej. Część czyta pliki pomocniczo (fikstury, manifesty).
Podaję to jako rząd wielkości i mianownik, nie jako listę zadań.

## 4. Dlaczego stanowiska tego nie dopisują — trzy przyczyny, żadna to nie niedbalstwo

**a) Nie ma takiego kroku w instrukcji.** `AUDYT_PRZED_KOLEJKA.md` wymaga kontroli
dodatniej, ale nie mówi, że ma zostać **zapisana w `checks`**. Stanowiska robią ją
i opisują słowami — co pokazuję w §5.

**b) Skrypt odmawia uruchomienia poza CI:**

```python
if os.environ.get("CI") != "true":
    raise SystemExit("Uruchamiaj wyłącznie w izolowanym zadaniu testowym CI.")
```

Stanowisko **nie może sprawdzić własnego wpisu w chwili, w której by go pisało.**
Dopisanie wpisu na ślepo, z nadzieją, że przejdzie dopiero w CI, jest ryzykowne —
a błąd w mutacji wywraca cały krok CI, nie tylko nowy wpis. Racjonalna reakcja
stanowiska na taki układ to nie dotykać pliku. **To jest główna przyczyna.**

**c) Koszt.** Każdy wpis to dodatkowo **dwa przebiegi `php artisan test --filter`**
(jeden oczekujący czerwieni, jeden zieleni). Przy 2189 minutach GitHuba na 11 dni
i ~65 minutach na przebieg PR-a **uzupełnienie 167 wpisów wstecz jest niewykonalne**
i nie proponuję go.

## 5. Dowód, że dyscyplina istnieje, a zapis nie

To nie jest hipoteza — trzy niezależne stanowiska zrobiły kontrolę dodatnią
i żadne nie zostawiło po niej śladu, który CI mogłoby powtórzyć:

| Gdzie | Co zrobiono | Gdzie to żyje |
|---|---|---|
| `flota/dsa-odwolania`, commit `d291e4a6` | tymczasowa mutacja kontrolera (bezwarunkowy `INSERT` do `audit_log`) → miernik nadal daje `DOMAIN_CHANGED` z właściwego powodu; MD5 przed i po zgodne | **tylko w treści commita** |
| `gpt/zawieszone-konto` | „powtórzono **15 kontroli ujemnych** […] PASS → FAIL z właściwego powodu → PASS, z przywróceniem” (`ZAWIESZENIE_926_ODTWORZENIE.md:46`) | w dokumencie na gałęzi, dowody surowe w katalogu `-PLIKI` **poza repozytorium** |
| `naprawa-858` (odzysk) | plik `docs/research/tagi-filtr-2026-09-20/czerwien-przed-poprawka.txt`, 187 wierszy | **zacommitowany** — najlepszy z trzech, ale i tak nie do powtórzenia przez CI |

**Wniosek:** to nie jest problem dyscypliny stanowisk. To problem trwałości dowodu.
Dowód, którego nie da się powtórzyć jednym poleceniem, starzeje się razem z kodem
i po pierwszym refaktorze nie znaczy już nic.

## 6. Czy da się to wykryć automatycznie — tak, i tanio

**Proponowany strażnik: `StraznikTekstuMaKontroleDodatniaTest`.**

Zakres — **wyłącznie do przodu**, żeby nie generować 167 zaległości:

1. Weź pliki testowe **DODANE** w `git diff --name-status origin/main...HEAD`
   (status `A`, ścieżka `tests/`).
2. Z nich wybierz te, które pasują do wzorca strażnika tekstu
   (`file_get_contents(`, `resource_path(`, `base_path(`) **i** asertują na treści
   (`preg_match`, `assertStringContainsString`, `assertMatchesRegularExpression`).
3. Dla każdego takiego pliku wymagaj **jednego z dwóch**:
   - nazwa klasy albo metody testowej występuje w `scripts/kontrole-negatywne-alfa08.py`, **albo**
   - w docblocku klasy stoi znacznik `@bez-kontroli-dodatniej <powód w jednym zdaniu>`.
4. Komunikat po polsku, mówiący **co zrobić**, nie co się stało — np.:
   „Nowy strażnik czyta źródło i asertuje na tekście. Dopisz go do `checks`
   w `scripts/kontrole-negatywne-alfa08.py` (mutacja + oczekiwana czerwień)
   albo napisz w docblocku `@bez-kontroli-dodatniej <powód>`.”

**Koszt CI: pomijalny.** To skan tekstowy po diffie — zero przebiegów
`php artisan test --filter`. Nie dokłada minut, których brakuje.

### Kontrola dodatnia dla samego strażnika — bez niej ta propozycja jest nic niewarta

Strażnik, który wykrywa brak kontroli dodatniej, sam musi mieć dowód, że zapala.
Proponuję **dwie mutacje**, obie dopisane jako wpisy do `checks`, dzięki czemu
mechanizm zaczyna pilnować sam siebie:

| Mutacja | Plik | Oczekiwany wynik |
|---|---|---|
| Usuń z `checks` wpis „Podpis co najmniej 18 px” (dotyczy `KafelDodawaniaPrzyDuzymTekscieTest`, który JEST strażnikiem tekstu) | `scripts/kontrole-negatywne-alfa08.py` | nowy strażnik **czerwony**: znany strażnik tekstu stracił pokrycie |
| Usuń znacznik `@bez-kontroli-dodatniej` z celowo oznaczonego pliku kontrolnego | ten plik | nowy strażnik **czerwony** z drugiej strony — dowód, że furtka jest naprawdę furtką, a nie zawsze-zielonym `else` |

Dodatkowo **kontrola z drugiej strony** (żeby strażnik nie był zawsze czerwony):
plik testowy nieczytający źródeł nie może go zapalić.

### Czego ten strażnik NIE zrobi — mówię wprost

- **Nie oceni jakości kontroli dodatniej.** Sprawdzi, że wpis istnieje, nie że
  mutacja jest sensowna. Słaba mutacja przejdzie.
- **Nie obejmie 167 istniejących strażników.** Świadomie: inaczej pierwszy przebieg
  jest czerwony na całym repozytorium i zostanie wyłączony w tydzień.
- **Furtka `@bez-kontroli-dodatniej` da się nadużyć.** Dlatego ma wymagać powodu
  słownie, a audyt przed kolejką ma te powody czytać — to jest tani, ludzki punkt kontrolny.
- **Nie wykryje strażnika, który zmienia kształt** (np. dzisiejsze
  `scripts/minutnik-regresja.mjs`, wycinające `app.js` tekstowo). To inna klasa
  usterki, opisana osobno w audycie jako Z5.

## 7. Drobiazg przy okazji — linia, która kłamie po dodaniu szóstego wpisu

`scripts/kontrole-negatywne-alfa08.py`, ostatnia linia:

```python
print("Pięć kontroli negatywnych wykryły regresje; źródła przywrócone.")
```

Liczebnik jest **wpisany w tekst**, nie brany z `len(checks)`. Po dodaniu szóstego
wpisu CI nadal wypisze „Pięć”. Waga niska, skutek realny: log CI zaczyna podawać
nieprawdziwą liczbę, a to jest jedyne miejsce, z którego człowiek czyta wynik
tego kroku. Zamiana na `len(checks)` to jedna linia.

## 8. Proponowana zmiana instrukcji — trzy zdania do dopisania

Do `AUDYT_PRZED_KOLEJKA.md` §2 i do instrukcji stanowisk:

> Nowy strażnik czytający źródło (CSS, Blade, workflow, skrypt) wymaga wpisu
> w `checks` w `scripts/kontrole-negatywne-alfa08.py`: mutacja psująca to, czego
> strażnik pilnuje, i nazwa testu, który ma wtedy oblać. Jeśli wpis nie ma sensu
> dla danego strażnika, napisz w docblocku `@bez-kontroli-dodatniej <powód>`.
> Kontrola dodatnia opisana wyłącznie słowami w commicie albo w raporcie
> jest dowodem jednorazowym — po refaktorze nie znaczy już nic.

**Oraz, niezależnie od powyższego:** zdjąć z `kontrole-negatywne-alfa08.py` blokadę
`CI != "true"` albo dodać jawne obejście (np. `KUKING_KONTROLE_LOKALNIE=1`),
żeby stanowisko mogło sprawdzić swój wpis **zanim** go zacommituje.
Dopóki tego nie ma, punkt 8 zostaje życzeniem — stanowisko nie ma jak go spełnić
bez zgadywania. **To jest najważniejsza pojedyncza zmiana z całego tego dokumentu.**
