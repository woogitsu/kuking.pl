# Pomiar liczby zapytań przy autoryzacji zdjęcia (MEDIA-03, #286)

**Data:** 10.09.2026
**Zakres:** WYŁĄCZNIE pomiar. Zero zmian w `app/Domain/Media` ani w
`MediaController`. `git diff` na kodzie produkcyjnym w tej gałęzi jest pusty —
jedyna zmiana to nowy plik testu, który ten pomiar wykonuje
(`tests/Feature/PomiarZapytanAutoryzacjiZdjeciaTest.php`).

## Dlaczego ten dokument w ogóle powstał

Audyt zewnętrzny (trzecia warstwa) postawił hipotezę: „autoryzacja jednego
zdjęcia to co najmniej pięć zapytań, a jedna strona feedu generuje ponad sto
żądań obrazków". `docs/research/audyt-2026-09-10/SPRAWDZENIE.md` mówi wprost,
że tej konkretnej hipotezy nikt jeszcze nie sprawdził przy pliku — trzecia
warstwa audytu w tym miejscu jest **niesprawdzoną hipotezą**, nie ustaleniem.
Jedna teza z tej samej serii audytów już okazała się fałszywa (D-064 prostuje
wcześniejszy fałszywy alarm o limicie 384 MB), więc zasada tej sesji brzmi:
**zmierz, zanim uwierzysz**. Ten dokument jest tym pomiarem.

## Metoda

Wzorzec jest już w repozytorium i został użyty bez zmian: `DB::enableQueryLog()`
/ `DB::getQueryLog()` / `DB::flushQueryLog()`, porównanie „mało vs dużo" — ten
sam wzorzec co w `tests/Feature/Visibility/PostSasiedniWpisTest.php`
(`test_liczba_zapytan_nie_rosnie_z_liczba_wpisow_autora`) i w
`tests/Feature/ListyIWyszukiwarkaTest.php`.

Dwa testy w `tests/Feature/PomiarZapytanAutoryzacjiZdjeciaTest.php`:

1. **Jedno zdjęcie** — widz zalogowany, ale NIE właściciel i NIE moderator
   (świadomie: właściciel/moderator dostają `true` z `DostepDoZdjecia::moze()`
   PRZED jakimkolwiek zapytaniem o rodziców, więc zaniżyliby wynik i nie
   odpowiadaliby na pytanie audytu — audyt mówił o zwykłym widzu przeglądającym
   feed). Zdjęcie przypięte do publicznego wpisu, jeden `GET` na
   `route('media.show', …)`, policzone zapytania.

2. **„Mało vs dużo"** — dokładnie ta sama operacja (jedno przekierowanie na
   jedno zdjęcie), powtórzona tyle razy, ile zdjęć byłoby na stronie feedu:
   raz dla strony z 3 zdjęciami, raz dla strony z 30. Sumujemy zapytania ze
   WSZYSTKICH tych osobnych żądań HTTP, bo dokładnie tak działa przeglądarka —
   `MediaController` nie jest wołany przy renderowaniu HTML feedu, tylko
   osobno z każdego `<img src>`.

Świadomie **osobno** — sam audyt ostrzega, że assercja na liczbie zapytań
całej strony łapie też zapytania z innych miejsc i test przejdzie po
optymalizacji, która nie dotknęła resolvera. Tu mierzone jest wyłącznie
`MediaController::show()` → `DostepDoZdjecia::moze()`.

## Zmierzone liczby

### Jedno zdjęcie, widz nie-właściciel, wpis publiczny: **15 zapytań**

Rozbicie zapytań (z surowego `DB::getQueryLog()`, ta sama kolejność):

| # | Zapytanie | Skąd |
|---|---|---|
| 0 | `select * from media where id = ?` | wiązanie trasy (route model binding) |
| 1 | `update users set ostatnio_widziany_at = ? …` | middleware sesji, nie autoryzacja zdjęcia |
| 2–6 | po jednym SELECT na: wpisy, „Ugotowałem", profil, przepisy, kroki | `DostepDoZdjecia::rodzice()` **pierwszy raz**, jako widz — **dokładnie pięć**, zgodnie z hipotezą audytu |
| 7 | `select * from users where id = ?` | `PostPolicy::view()` — leniwe dociągnięcie `$post->author` |
| 8 | `select exists(select * from blocks …)` | `PostPolicy::view()` — sprawdzenie blokady |
| 9–13 | te same pięć SELECT-ów | `DostepDoZdjecia::rodzice()` **drugi raz**, jako ANONIM — `MediaController::show()` woła `moze(null, $media)` osobno, żeby rozstrzygnąć nagłówek `Cache-Control` |
| 14 | `select * from users where id = ?` | `PostPolicy::view()` jako anonim — autor dociągany od nowa (nowa instancja `Post`) |

**Wniosek o samej hipotezie „co najmniej pięć":** hipoteza jest
**potwierdzona jako dolna granica, ale niepełna**. Pięć zapytań to
rzeczywiście koszt JEDNEGO wywołania `rodzice()` — ale widz, który nie jest
właścicielem, płaci ten koszt **dwa razy** (raz dla siebie, raz jako anonim,
przy liczeniu nagłówka cache), plus dwa-trzy zapytania samej `PostPolicy`,
plus dwa zapytania nie mające nic wspólnego z autoryzacją zdjęcia (routing,
sesja). Rzeczywisty koszt jednego zdjęcia jest **większy niż to, co
postawił audyt**, nie mniejszy.

### Strona feedu — 3 zdjęcia vs 30 zdjęć

| Scenariusz | Suma zapytań | Średnio na zdjęcie |
|---|---|---|
| 3 zdjęcia | 45 | 15,00 |
| 30 zdjęć | 450 | 15,00 |
| **stosunek 30÷3** | **10,00** (dokładnie 30/3) | — |

Koszt na zdjęcie jest **stały i identyczny** z pomiarem pojedynczego zdjęcia
(15) — nie ma żadnego dodatkowego N+1 wewnątrz samego `rodzice()` zależnego od
tego, ile zdjęć jest już na stronie. Suma rośnie **dokładnie liniowo** z
liczbą zdjęć, bo każde zdjęcie płaci ten sam koszt w **osobnym** żądaniu HTTP,
bez niczego współdzielonego między żądaniami (żadnego cache'u — architektura
opisana w komentarzu `MediaController` nie ma na to miejsca dziś).

**Ekstrapolacja na realną skalę** (`ZdjeciaLimitZapytanTest` i
`config('kuking.limits.zdjecie')` zakładają już dziś, że jedna strona feedu to
grubo ponad sto żądań o zdjęcia — to jest ISTNIEJĄCE, niezależnie potwierdzone
założenie w repozytorium, nie coś, co ten dokument wymyśla):

| Zdjęć na stronie | Zapytania SQL wyłącznie na autoryzację zdjęć |
|---|---|
| 15 (jedna strona `/odkryj`, domyślny `page_size`) | 225 |
| 100 | 1 500 |
| 150 (budżet z `ZdjeciaLimitZapytanTest`) | 2 250 |

To są zapytania **oprócz** tego, co strona HTML feedu i tak już wykonuje, i
oprócz zapytań sesji/limitera dla każdego z tych stu-kilkudziesięciu żądań.
Audyt miał rację co do kierunku: to jest realne obciążenie, i to jest
obciążenie tego samego silnika PostgreSQL, który jednocześnie obsługuje cache,
sesje i kolejkę (AGENTS.md, `docs/ARCHITECTURE.md`).

## Wniosek — czy hipoteza audytu jest potwierdzona

**Potwierdzona, ale nieprecyzyjna w dobrą stronę: rzeczywistość jest gorsza
niż „co najmniej pięć".** Zmierzony koszt jednego zdjęcia dla zwykłego widza
to **15 zapytań**, nie 5 — audyt trafił we właściwy mechanizm (`rodzice()`
budujący pięć zapytań niezależnie od tego, czy pierwszy rodzic już
wystarczyłby) i nie doliczył **podwójnego przejścia** tego mechanizmu
(widz + anonim dla `Cache-Control`) ani zapytań samej `PostPolicy`. Druga część
hipotezy — „ponad sto żądań obrazków na stronę feedu" — jest zgodna z tym, co
repozytorium już samo zakłada w `config/kuking.php` i pilnuje
`ZdjeciaLimitZapytanTest`. Nie jest to więc fałszywy alarm w stylu D-064: **to
jest realny, zmierzony koszt**, i to koszt większy niż postawiona hipoteza.

## Co proponuję zrobić dalej (opisowo — bez wdrażania w tym PR)

Zgodnie z kolejnością z issue #286 — **cache jest ostatni, nie pierwszy krok**.

### Krok 1 — nie liczyć całego grafu uprawnień dwa razy

`MediaController::show()` woła `$this->dostep->moze($widz, $media)`, a zaraz
potem, dla zalogowanego widza, jeszcze raz `$this->dostep->moze(null, $media)`
wyłącznie po to, żeby rozstrzygnąć nagłówek `Cache-Control`. To jest DOKŁADNIE
6 z 15 zmierzonych zapytań (drugie `rodzice()` plus jedno zapytanie
`PostPolicy` jako anonim). `DostepDoZdjecia::rodzice()` mogłaby zwrócić listę
rodziców RAZ, a wywołujący kod sprawdzić widoczność dla obu „widzów" na tej
samej liście, zamiast budować ją od nowa.

**Ile to da, licząc na zmierzonych liczbach:** z 15 do ok. 9 zapytań na
zdjęcie (−40%). Na stronie ze 100 zdjęciami: z 1 500 do ok. 900 zapytań.

### Krok 2 — jedno zapytanie zamiast pięciu w `rodzice()`

Pięć osobnych `SELECT`-ów (wpisy, „Ugotowałem", profil, przepisy, kroki)
wykonuje się ZAWSZE, nawet gdy pierwszy już przesądza sprawę — bo dopiero PO
wszystkich pięciu kod pyta Gate. Można to zmienić w dwie strony, obie opisane
w issue #286 punkt 2: albo przerywać na pierwszym trafieniu (średnio mniej niż
5, ale nie gwarantowane), albo połączyć pięć zapytań w jedno przez `UNION`
(dokładnie 1, zawsze). Druga opcja jest przewidywalna i łatwiejsza do
utrzymania — nie zależy od tego, który typ rodzica akurat jest najczęstszy.

**Ile to da:** w połączeniu z krokiem 1, z 9 do ok. 5 zapytań na zdjęcie —
czyli DOKŁADNIE tyle, ile postawił audyt, tylko naprawdę piętnaście stanie się
pięcioma. Na stronie ze 100 zdjęciami: z 900 do ok. 500 zapytań. Licząc od
punktu startowego: **1 500 → 500, redukcja o dwie trzecie**, bez cache'a,
bez Redisa, bez żadnej nowej zależności.

### Krok 3 — cache decyzji, dopiero teraz i pod czterema warunkami

Issue #286 stawia to wprost jako ostatni krok, i słusznie: cache widoczności
zdjęcia musi unieważniać się przy **czterech** zdarzeniach — zmianie
widoczności treści, blokadzie (w obie strony, jak D-080), decyzji moderacyjnej
i zmianie statusu autora. Pominięcie jednego z nich pokazuje komuś zdjęcie,
którego nie ma prawa zobaczyć — a to jest błąd, który nie wywala żadnego
testu, jeśli nikt go nie napisze. **To NIE jest argument za Redisem** — jeśli
w ogóle, to kandydat na istniejący wzorzec z `config` (`cache_locks` /
`CACHE_STORE=database`, jak w D-072/D-076), nie na nowy mechanizm.

**Czy w ogóle jest potrzebny po krokach 1–2:** to jest PYTANIE, nie
rekomendacja. Po redukcji do ~5 zapytań na zdjęcie (500 na stronie ze 100
zdjęciami) trzeba by zmierzyć REALNE obciążenie PostgreSQL pod produkcyjnym
ruchem (nie tylko liczbę zapytań, ale czas i konkurencję o połączenia z
cache/sesją/kolejką, które współdzielą tę samą bazę), zanim ktokolwiek
zaproponuje cache — dokładnie tak, jak żąda `AGENTS.md` §3.

### Co świadomie NIE jest tu proponowane

- Redis do cache'owania decyzji o widoczności — brak zmierzonego wąskiego
  gardła PO krokach 1–2, a `AGENTS.md` wymaga zmierzonej potrzeby przed nowym
  mechanizmem.
- Zmiana trasy `media.show` na strumieniowanie bajtów przez PHP zamiast
  przekierowania — to jest OSOBNA decyzja architektoniczna (uzasadniona w
  komentarzu `MediaController`) i nie ma nic wspólnego z liczbą zapytań do
  autoryzacji.
- Cokolwiek w sprzątaczu osieroconych zdjęć ani w przypinaniu zdjęcia do
  wpisu — to jest MEDIA-01 (#285), świadomie poza zakresem tego zadania.

## Kontrola ujemna — dowód, że oba testy naprawdę mierzą to, co mają

| Test | Sabotaż | Wynik przed | Wynik po sabotażu | Po przywróceniu |
|---|---|---|---|---|
| `test_autoryzacja_jednego_zdjecia_publicznego_wpisu` | `Post::query()->whereHas('media',…)->get()` w `DostepDoZdjecia::rodzice()` zamieniony na pętlę: `pluck('id')` całej tabeli `posts`, potem `Post::query()->with('media')->find($pid)` PO JEDNYM na każdy wiersz, filtrowanie w PHP | ZIELONY, 15 zapytań | **CZERWONY** — 19 zapytań, próg 18 | ZIELONY, 15 zapytań, `git diff` puste |
| `test_suma_zapytan_rosnie_proporcjonalnie_do_liczby_zdjec_na_stronie` | (ten sam sabotaż) | ZIELONY, stosunek 10,00 | **CZERWONY** — średnio 147 zapytań na zdjęcie (próg 20); koszt na zdjęcie przestał być stały, bo pętla skaluje się z liczbą WSZYSTKICH wpisów w bazie, nie tylko z liczbą zdjęć na stronie | ZIELONY, stosunek 10,00 |

Uwaga uczciwa wobec własnego progu: przy tym konkretnym sabotażu próg
pojedynczego zdjęcia (18) złapał regresję z marginesem zaledwie 1 zapytania
(19 vs próg 18) — sabotaż w tym miejscu w bazie z JEDNYM wpisem dodaje tylko
+4 zapytania. To test „mało vs dużo" (drugi w tabeli) złapał tę samą regresję
znacznie wyraźniej (147 zamiast 15 na zdjęcie, 7,3× więcej), bo N+1 zależny od
skali ujawnia się dopiero przy większej ilości treści w bazie — to jest
dokładnie ta właściwość, po którą wzorzec „mało vs dużo" istnieje, i dlatego
ten dokument trzyma oba testy razem, nie tylko pierwszy.

## Czego ten dokument NIE dowodzi

- Czasu odpowiedzi ani obciążenia połączeń do PostgreSQL pod prawdziwym
  ruchem — `DB::getQueryLog()` liczy zapytania, nie milisekundy ani liczbę
  jednoczesnych połączeń. Do oceny, czy krok 3 (cache) jest w ogóle potrzebny,
  potrzebny byłby osobny pomiar pod obciążeniem, nie ten dokument.
- Zachowania na PostgreSQL 18 (produkcja/CI) — pomiar wykonany lokalnie na
  PostgreSQL 16 (rozbieżność wersji opisana w
  `docs/zlecenia/2026-09-10-przekazanie-pracy.md` §0). Liczba zapytań nie
  powinna zależeć od wersji silnika (to jest liczba zapytań SQL wysłanych
  przez Eloquent, nie plan wykonania), ale nie zostało to osobno sprawdzone
  na 18.
- Kosztu zapytań poza autoryzacją (limiter, sesja, CDN) — mierzone jest
  wyłącznie `DostepDoZdjecia`/`PostPolicy` plus nieuniknione minimum
  (routing, `ostatnio_widziany_at`).
