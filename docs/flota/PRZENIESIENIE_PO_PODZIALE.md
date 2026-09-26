# Przeniesienie otwartego PR-a po podziale dwóch plików (25.09.2026)

Właściciel zdecydował 25.09.2026, że dwa pliki, przez które powstawała
większość konfliktów w otwartych PR-ach, dzielimy na wiele małych plików:

| Było (jeden plik) | Jest | Gałąź podziału |
|---|---|---|
| `scripts/kontrole-negatywne-alfa08.py` (wszystkie kontrole) | `scripts/kontrole_negatywne/kNN_<obszar>.py`, stary plik to cienki punkt wejścia | PR #1478, `claude/kontrole-negatywne-katalog` |
| `docs/DECISIONS.md` (ponad 16 tys. wierszy) | `docs/decyzje/D-NNN-krotki-slug.md`, `docs/DECISIONS.md` to indeks | `claude/decyzje-podzial` |

Oba stare pliki **nadal istnieją**, więc git zwykle zgłasza
`CONFLICT (content)`, a nie `modify/delete`. Kroki niżej są takie same
w obu przypadkach: jeśli git mówi `CONFLICT (modify/delete)`, zrób to samo,
co przy `content` („weź plik z main”, potem przenieś swoje wpisy).

**Zasady bez wyjątków:** scalaj (`git merge`), nie rebase'uj; bez `--force`,
`reset --hard`, `--no-verify` i gołego `git stash`. Rozwiązanie „weź moje”
w którymkolwiek z tych dwóch plików jest **zawsze złe**: przywraca stary
układ, a strażnicy obleją (z komunikatem, który odsyła tutaj).

## 0. Czy mnie to dotyczy?

```sh
git fetch origin main
BAZA=$(git merge-base HEAD origin/main)
git diff --stat "$BAZA" HEAD -- scripts/kontrole-negatywne-alfa08.py docs/DECISIONS.md
```

Pusto — nic z tej instrukcji Cię nie dotyczy, scalaj normalnie. Jest któryś
z plików — zrób sekcję A, B albo obie, **w jednym scaleniu**.

## A. `docs/DECISIONS.md` — nowa albo zmieniona decyzja

```sh
git merge origin/main                          # konflikt w docs/DECISIONS.md
git checkout origin/main -- docs/DECISIONS.md  # indeks z main, nie Twoja wersja
python3 scripts/decyzje-przenies.py            # Twoje wpisy → docs/decyzje/
php scripts/decyzje-indeks.php                 # tabela indeksu z plików
php scripts/decyzje-indeks.php --sprawdz
```

`decyzje-przenies.py` porównuje dziennik z Twojej gałęzi (`HEAD` w trakcie
scalenia) z bazą scalenia i wypisuje, co zrobił z każdym wpisem:

- **nowy wpis** — nowy plik `docs/decyzje/D-NNN-slug.md`, treść bez zmian;
- **numer zajęty na main przez inną decyzję** — Twój wpis dostaje pierwszy
  wolny numer; przepnij odwołania do starego numeru w zmianach swojej gałęzi
  (`git grep -n 'D-NNN\b'`, poprawiasz tylko to, co sam dodałeś);
- **zmiana wpisu, którego main nie ruszał** — skrypt nadpisuje jego plik;
- **zmiana wpisu, który main też zmienił** — nic nie nadpisuje, zostawia
  `*.md.z-galezi` obok i kończy kodem 1: scal obie wersje ręcznie w pliku
  `.md` i usuń `.z-galezi`.

Uruchom go **przed** commitem scalenia: po commicie `HEAD` ma już indeks
z main. Jeśli scalenie jest już zacommitowane, podaj swój ostatni commit
sprzed scalenia: `python3 scripts/decyzje-przenies.py --z <sha> --baza $(git merge-base <sha> origin/main)`.

Na koniec:

```sh
php artisan test --filter='DziennikDecyzjiZgodnyZIndeksemTest|NumeryDecyzjiMajaWpisyTest|OdnosnikiDziennikaDecyzjiIstniejaTest'
git add docs/DECISIONS.md docs/decyzje
```

Ręcznie, bez skryptu (gdy np. nie masz Pythona): wpis z Twojej gałęzi
(`git diff "$BAZA" HEAD -- docs/DECISIONS.md`) wklej do nowego pliku
`docs/decyzje/D-NNN-krotki-slug.md` — pierwszy wiersz to nagłówek
`## D-NNN · Tytuł`, podsekcje `### `. Względne odnośniki markdown dostają
`../` (plik leży katalog głębiej niż stary dziennik). Numer sprawdź:
`ls docs/decyzje/D-NNN-*` ma być puste, inaczej weź
`php scripts/decyzje-indeks.php --nastepny`.

## B. `scripts/kontrole-negatywne-alfa08.py` — nowa albo zmieniona kontrola

```sh
git merge origin/main                    # konflikt w scripts/kontrole-negatywne-alfa08.py
git diff "$BAZA" HEAD -- scripts/kontrole-negatywne-alfa08.py > /tmp/moje-kontrole.diff
git checkout origin/main -- scripts/kontrole-negatywne-alfa08.py   # punkt wejścia z main
```

Każdy swój wpis z `/tmp/moje-kontrole.diff` przenieś do **nowego pliku**
`scripts/kontrole_negatywne/kNN_<obszar>.py` według wzoru z
`scripts/kontrole_negatywne/README.md` (numer `kNN` ustala tylko kolejność;
ten sam numer w dwóch PR-ach nie jest konfliktem):

| W starym pliku | W nowym pliku |
|---|---|
| stałe `PLIK = "…"`, `TEST = "…"` | te same stałe na górze pliku |
| funkcja mutacji `def …(source):` | ta sama funkcja, bez zmian |
| krotka w `checks`: `("nazwa", PLIK, TEST, mutacja),` | `Kontrola("nazwa", PLIK, TEST, mutacja),` w `KONTROLE = [...]` |
| `run_test(TEST, True)` | `TEST` w `KONTROLE_DODATNIE = [...]` |
| `replace_once` | `from kontrole_negatywne._narzedzia import Kontrola, replace_once` |

Zmiana ISTNIEJĄCEJ kontroli (np. nowy punkt mutacji): znajdź, gdzie teraz
mieszka — `grep -rn 'nazwa kontroli' scripts/kontrole_negatywne/` — i zrób
tę samą zmianę w tamtym pliku.

Sprawdzenie:

```sh
python3 scripts/kontrole-negatywne-alfa08.py --lista | grep 'nazwa Twojej kontroli'
php artisan test --filter=StraznikTekstuMaKontroleDodatniaTest
git add scripts/kontrole-negatywne-alfa08.py scripts/kontrole_negatywne
```

Punkt wejścia sam odmawia, jeśli zostały w nim stałe, `checks`, `run_test(`
albo `def` (komunikat odsyła tutaj). Test
`test_punkt_wejscia_kontroli_nie_ma_wpisow_w_starym_ukladzie` łapie w PHPUnit
także przywrócony w całości stary monolit.

## C. Zamknięcie scalenia

```sh
./scripts/check.sh
git commit        # commit scalenia; w opisie: „przeniesione po podziale (PRZENIESIENIE_PO_PODZIALE.md)”
git push -u origin <ta-sama-gałąź>
```
