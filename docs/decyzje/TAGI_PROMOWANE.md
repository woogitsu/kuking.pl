# Lista tagów promowanych — propozycja do zatwierdzenia

**Data: 7 września 2026. Status: DO DECYZJI WŁAŚCICIELA.** Nic z tego nie jest
wpisane do bazy — lista promowanych jest wyborem gospodarza, nie właściwością
tagu (D-021), a ekran do jej ustawienia już istnieje: `/admin/tagi-promowane`.

## Po co ten dokument

Tabela `tag_promotions` jest dziś PUSTA. Czytają ją dwa miejsca:

- **onboarding** (`OnboardingController::interests` → `Tag::promowane()`) —
  ekran „co Cię interesuje" przy zakładaniu konta,
- **szyna na stronie głównej** (`layout.blade.php`).

Pusta lista nie psuje serwisu: onboarding pomija wtedy krok zamiast pokazywać
pustą siatkę (i to jest zapisane w komentarzu tamtej metody, sprawdzone). Ale
oznacza to, że nowa osoba nie dostaje ANI JEDNEJ podpowiedzi, od czego zacząć
obserwowanie — a `docs/product/COLD_START.md` §6.1 stawia właśnie na to, żeby
pierwszy feed nie był pusty. Dziesięć minut pracy w panelu zmienia to
natychmiast, bez pisania kodu.

## Skąd te tagi

Wszystkie z wgranego słownika (D-026) — **każdą nazwę sprawdziłem, że
istnieje w bazie**, więc nie ma tu tagu, którego nie da się kliknąć.
Dobór według trzech reguł, w tej kolejności:

1. **Sezon jest teraz.** Wrzesień w polskim domu to przetwory, śliwki, dynia
   i grzyby. Tag promowany, pod którym nikt dziś nie gotuje, jest gorszy niż
   brak tagu (`COLD_START.md` §7: „tag tygodnia bez uczestników jest gorszy
   niż brak akcji").
2. **Łatwość przed ambicją.** `COLD_START.md` §7 wprost: tag tygodnia ma być
   ŁATWY — każdy to gotuje — a nie ambitny.
3. **Jeden tag z kategorii `pamiec`.** To jest kategoria, dla której ten
   słownik powstał i której nie ma żaden inny serwis kulinarny w Polsce.
   Jeśli coś ma odróżniać Kuking na pierwszym ekranie, to właśnie ona.

## Propozycja: dwanaście tagów

| # | tag | dlaczego teraz |
|---|---|---|
| 1 | `przetwory` | Wrzesień to szczyt. Tag zbiorczy, pod który każdy coś wrzuci. |
| 2 | `ogórki kiszone` | Najczęstszy przetwór domowy w Polsce; próg wejścia zerowy. |
| 3 | `powidła śliwkowe` | Sezon (`sierpień-wrzesień` w słowniku), a robienie ich to całodniowa historia — czyli treść z opowieścią, nie samo zdjęcie. |
| 4 | `kapusta kiszona` | Sezon (`jesień`), i to jest temat, w którym każdy ma „swój sposób”. |
| 5 | `dynia` | Sezon (`wrzesień-listopad`), wdzięczna na zdjęciach. |
| 6 | `sezon grzybowy` | Grzybobranie to wyjście z domu i powód do zdjęcia jeszcze przed gotowaniem. |
| 7 | `szarlotka` | Jabłka są teraz; ciasto, które piecze każdy i każdy inaczej. |
| 8 | `chleb na zakwasie` | Jedyny kandydat na „tag z własnym życiem” (`COLD_START.md` §B1) — ludzie wracają do niego co tydzień. |
| 9 | `rosół` | Niedzielna instytucja. Nie sezonowy, ale gwarantuje treść w każdym tygodniu. |
| 10 | `przepis po babci` | Kategoria `pamiec`. To jest tag, po którym ktoś pozna, że trafił do właściwego serwisu. |
| 11 | `dla wnuków` | Kategoria `okolicznosci`. Powód gotowania, nie potrawa — i powód najbliższy tej grupie. |
| 12 | `początek roku szkolnego` | Sezonowy do końca września (`sezonowy: wrzesień` w słowniku), potem do zdjęcia z listy. |

Kolejność w tabeli to proponowane `position` — od tego, co najbardziej sezonowe.

## Czego świadomie NIE proponuję

- **`grzyby` obok `sezon grzybowy`** — dwa tagi na to samo wydarzenie na jednej
  liście dwunastu. Zostaje ten, który mówi o porze roku.
- **`żurek`** — słownik ma przy nim `sezonowy: Wielkanoc`. Kwiecień, nie
  wrzesień.
- **`przetwory w słoikach`, `czas przetworów`** — obok `przetwory` byłyby
  szumem. Tag promowany ma być rozstrzygnięciem, nie wyborem między trzema
  bliskoznacznymi.
- **niczego z kategorii `diety`** — `bez glutenu` i podobne opisują DEKLARACJĘ
  autora wpisu, nie sprawdzony fakt (uwaga 31 w słowniku). Na liście
  promowanej gospodarza wyglądałyby jak zapewnienie serwisu.

## Co trzeba zrobić w panelu

`/admin/tagi-promowane`, dla każdego tagu: nazwa + pozycja + opcjonalna
notatka („jedno zdanie od gospodarza”). Notatka jest widoczna dla ludzi, więc
jeśli ma być — musi być prawdziwa i konkretna: „Wrzesień to ostatni moment na
śliwki” jest dobre, „Odkryj świat przetworów” nie.

**Kto i kiedy zmienił listę, zapisuje `audit_log`** — bez osobnej kolumny
`promoted_by` (D-021, ten sam wzorzec co „kuKINGi na dziś”).

## Czego ta lista nie rozwiązuje

Gospodarz musi pod nią COKOLWIEK opublikować. `COLD_START.md` §4.2 każe mu
publikować pierwszemu i to jest jedyna część, której nie da się załatwić
konfiguracją: tag promowany z zerem wpisów mówi nowej osobie „tu nikogo nie
ma” wyraźniej niż brak listy.

Osobno, nierozstrzygnięte: **„opiekunowie tagów” z `COLD_START.md` §5 nadal
nie istnieją w kodzie** — `tag_promotions` nie ma `curator_id` ani ekranu do
przypisywania osób. Do czasu dołożenia tego rola jest umową społeczną, nie
funkcją serwisu, i tak trzeba ją zapowiadać.
