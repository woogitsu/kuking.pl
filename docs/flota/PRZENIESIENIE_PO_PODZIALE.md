# Przeniesienie otwartego PR-a po podziale dziennika decyzji (30.09.2026)

Właściciel zdecydował (najpierw 25.09, odświeżone 30.09.2026), że
`docs/DECISIONS.md` (ponad 21 tys. wierszy, dopisywany zawsze na końcu, konflikt
w każdej parze równoległych PR-ów z nową decyzją) dzielimy na jeden plik na
decyzję:

| Było | Jest |
|---|---|
| `docs/DECISIONS.md` — całość dziennika | `docs/decyzje/D-NNN-krotki-slug.md` (jedna decyzja; `U-NNN-…` dla uzupełnienia do issue), a `docs/DECISIONS.md` to **indeks** z tabelą generowaną z plików |

Stary plik **nadal istnieje**, więc git zwykle zgłasza `CONFLICT (content)`
w `docs/DECISIONS.md`. Treść decyzji przeniesiono bez zmian (jedyna różnica:
względne odnośniki markdown w trzech wpisach dostały `../`, bo plik leży katalog
głębiej). Numery i nagłówki `## D-NNN …` się nie zmieniły, więc odwołania
„D-NNN w `docs/DECISIONS.md`” działają dalej przez tabelę indeksu.

**Zasady bez wyjątków:** scalaj (`git merge`), nie rebase'uj; bez `--force`,
`reset --hard`, `--no-verify` i gołego `git stash`. Rozwiązanie „weź moje”
w `docs/DECISIONS.md` jest **zawsze złe**: przywraca stary układ, a
`DziennikDecyzjiZgodnyZIndeksemTest` obleje (z komunikatem, który odsyła tutaj).

## 0. Czy mnie to dotyczy?

```sh
git fetch origin main
BAZA=$(git merge-base HEAD origin/main)
git diff --stat "$BAZA" HEAD -- docs/DECISIONS.md
```

Pusto — nic z tej instrukcji Cię nie dotyczy, scalaj normalnie.

## A. Nowa albo zmieniona decyzja w `docs/DECISIONS.md`

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
sprzed scalenia:
`python3 scripts/decyzje-przenies.py --z <sha> --baza $(git merge-base <sha> origin/main)`.

Ręcznie, bez skryptu: wpis z Twojej gałęzi (`git diff "$BAZA" HEAD -- docs/DECISIONS.md`)
wklej do nowego pliku `docs/decyzje/D-NNN-krotki-slug.md` — pierwszy wiersz to
nagłówek `## D-NNN · Tytuł`, podsekcje `### `. Względne odnośniki markdown
dostają `../`. Numer sprawdź: `ls docs/decyzje/D-NNN-*` ma być puste, inaczej
weź `php scripts/decyzje-indeks.php --nastepny`.

## B. Zamknięcie scalenia

```sh
php artisan test --filter='DziennikDecyzji|NumeryDecyzjiMajaWpisyTest|OdnosnikiDziennikaDecyzjiIstniejaTest'
./scripts/check.sh
git add docs/DECISIONS.md docs/decyzje
git commit        # w opisie: „przeniesione po podziale (PRZENIESIENIE_PO_PODZIALE.md)”
git push -u origin <ta-sama-gałąź>
```

## Skąd wziął się podział (dla porządku)

Układ wygenerował jednorazowo `scripts/decyzje-podziel.py` z dziennika na
`origin/main` z 30.09.2026 (287 wpisów: 286 decyzji i „Uzupełnienie #369”).
Skrypt jest w repozytorium i da się go puścić na starszym układzie
(`--z <ref> --sucho`), żeby sprawdzić, że żadna linia treści nie ginie.
