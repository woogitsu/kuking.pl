## ZANIM UZNASZ CZERWIEN CI ZA USTERKE: SPRAWDZ MINUTY (21.09.2026)

Stan zmierzony przez wlasciciela 21.09 o 08:46 (zrzut ekranu z GitHub Billing):

    2442 min uzyte / 3000 min w pakiecie     reset za 10 dni
    Billable $0 ($14.77 zuzycia - $14.77 znizki)

**Zostalo 558 minut.** Przy ~65 minutach na przebieg PR-a to okolo OSMIU
przebiegow. W kolejce pchania stoi ponad szescdziesiat galezi.

### Czemu to jest w tym pliku, a nie w notatce

Gdy minuty sie skoncza, przebiegi zaczna padac **z powodu, ktory nie ma nic
wspolnego z kodem**. Objaw wyglada jak zwykla czerwien CI. Agent, ktory tego
nie sprawdzi, zacznie szukac usterki w galezi i jej nie znajdzie — albo,
gorzej, "naprawi" cos, co nie bylo zepsute.

**Pierwsza rzecz przy kazdej niewyjasnionej czerwieni: sprawdz licznik minut.**
API rozliczen odmawia naszemu tokenowi (410 + brak zakresu `admin:org`),
wiec trzeba zajrzec w Settings -> Billing w przegladarce.

### Jak to zmierzylem, a jak szacowalem

Wieczorem 20.09 bylo **811/3000**. Szacowalem, ze noc kosztowala ~1170 minut
(10 aktualizacji PR-ow + 8 scalen + przebieg #929). Naprawde kosztowala
**~1631** — moje oszacowanie bylo **zanizone o jedna trzecia**. Nie opieraj
planu wydatkow na szacunku, gdy da sie zobaczyc licznik.

### Co z tego wynika

Push galezi roboczej jest **darmowy** (`ci.yml` wyzwala sie na push tylko do
`main`/`staging`) — kolejka pchania moze pracowac dalej bez ograniczen.
Platne jest wylacznie otwarcie PR-a i scalenie do `main`.

---

# Przekazanie pracy nad Kuking.pl — co jest w tej paczce

Paczka powstała 20 września 2026 na zakończenie sesji, która prowadziła
kilkanaście równoległych stanowisk nad repozytorium `woogitsu/kuking.pl`.

**Sesja, która to dostaje, NIE MA dostępu do maszyny, na której praca powstała.**
Dlatego wszystko, co było tylko w plikach lokalnych, jest tutaj.

---

## Co jest w środku

| Plik | Po co |
|---|---|
| `ZASADY_FLOTY.md` | **Przeczytaj pierwsze.** Twarde zasady pracy w tym repozytorium i pułapki, na które ktoś już się nadział. Każde zlecenie dla agenta powinno się do nich odwoływać. |
| `REJESTR_FLOTY.md` | Co się wydarzyło tego dnia: obsada stanowisk, trzy awarie oprzyrządowania z przyczynami, reguły wyciągnięte z pomyłek. |
| `KOLEJKA_ZADAN.md` | Zadania do wzięcia **oraz komplet decyzji właściciela** z 20.09 — co rozstrzygnięte, co odłożone i z jakim powodem. |
| `_prompty/01..18-*.txt` | Osiemnaście **kompletnych** zleceń do wklejenia do niezależnych sesji. Każdy niesie własną główkę z zasadami — nic nie trzeba składać. |
| `*.sh` | Skrypty oprzyrządowania (runtime testowy, kolejki pchania, odtwarzanie list). **Działają tylko na tamtej maszynie** — są tu jako dokumentacja tego, jak to było zrobione. |

---

## Czego w tej paczce NIE MA i dlaczego

**Kodu.** Cała praca żyje w gałęziach gita. Jeśli zostały wypchnięte —
są w `github.com/woogitsu/kuking.pl`. Jeśli nie — jest osobny plik
`kuking-galezie-*.bundle`, z którego odtwarza się wszystko:

```
git clone kuking-galezie-20260920-XXXX.bundle kuking
cd kuking && git branch -a
```

**Dostępu do środowiska testowego.** Tamta maszyna miała PostgreSQL na
`127.0.0.1:55439`, runtime'y WSL i własne runnery GitHub Actions. Nowa sesja
tego nie ma i **nie powinna próbować tego odtwarzać** — weryfikacją jest CI
przy pull requeście.

---

## Trzy rzeczy, które kosztowały ten dzień najwięcej

Wszystkie trzy to **fałszywe potwierdzenia** — narzędzie meldowało sukces,
nie robiąc tego, co obiecywało. Opisane szerzej w `REJESTR_FLOTY.md`.

1. **Kolejka pchania oznaczała nieudane pchnięcia jako udane**, bo nie patrzyła
   na kod wyjścia. Przez trzy godziny raport mówił „pięć gałęzi wypchniętych",
   a wypchnięta była jedna.
2. **Weryfikacja po NAZWIE gałęzi zamiast po SHA.** Gałąź bywa na `origin` pod
   starym SHA, gdy force-push nie przeszedł — sama obecność nazwy niczego nie
   dowodzi.
3. **Stan przejściowy podany jako wynik.** Wdrożenie w stanie `WAITING`
   zameldowane jako „wydanie w drodze"; skończyło się `SKIPPED`, bo czerwone CI
   blokuje deploy. Wdrożenie potwierdza się **na żywej stronie**:
   `curl -s https://kuking.pl/o-kuking | grep -o "wydanie [^<]*"`.

Wspólny mianownik: **sprawdzaj skutek, nie zapowiedź skutku.** To ta sama
zasada, którą samo repozytorium stosuje do testów („skan, który nie znajduje
żadnego pliku, przechodzi") — tyle że zastosowana do własnych narzędzi.

---

## Od czego zacząć

1. `ZASADY_FLOTY.md` — w całości.
2. `KOLEJKA_ZADAN.md`, sekcja **„Podjęte, do wykonania"** — tam są zadania
   z już rozstrzygniętymi wątpliwościami, więc nie wymagają pytania właściciela.
3. Sprawdź stan na GitHubie: otwarte PR-y, gałęzie `flota/*`, `gpt/*`,
   `robota/*`, i czy `main` jest zielony.
4. Zgłoszenia dokłada bez przerwy osobny automat — warto zacząć dzień od
   `gh issue list --repo woogitsu/kuking.pl --state open --limit 30`.

**Zasada nadrzędna tego projektu:** `AGENTS.md` w korzeniu repozytorium jest
jedynym źródłem prawdy. Wszystko powyżej mu ustępuje.
