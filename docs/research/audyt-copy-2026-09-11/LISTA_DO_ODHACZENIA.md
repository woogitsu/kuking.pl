# Audyt copy — lista do odhaczenia przez właściciela

Sposób użycia: przejrzyj kolumnę **Decyzja** i wpisz `TAK` albo `NIE`.
Tam, gdzie jest już wpisane „robię", nie musisz nic robić — to są usterki
faktyczne, nie kwestie gustu, i idą bez pytania.

Podstawa: `AUDYT_COPY.md` w tym katalogu, sprawdzony przy plikach —
wynik weryfikacji w `SPRAWDZENIE.md` (dziewięć z dziesięciu P0 potwierdzone,
jeden trafny pod złym adresem).

---

## Czy problem jest realny — zmierzone, nie oszacowane

Audyt mówi o „przeprojektowanej ludzkości": pojedyncze zdanie brzmi
sympatycznie, trzydzieści tworzy manierę. Policzyłem jego „słowa alarmowe"
w 137 widokach Blade, **po odcięciu komentarzy** (bo surowy `grep` liczy też
długie komentarze techniczne i przeszacowuje problem 2,4 raza):

| słowo | w tekście widocznym | w ilu widokach |
|---|---|---|
| `nic nie` | **36** | **27 ze 137** |
| `naprawdę` | 15 | 10 |
| `od razu` | 15 | 14 |
| `zawsze` | 9 | 8 |
| `po prostu` | 7 | 5 |
| `w porządku` | 7 | 7 |
| pozostałe (`po ludzku`, `normalnie`, `spokojnie`, `najważniejsz`) | 9 | — |
| **razem** | **98** | — |

Surowo byłoby 240. Podaję obie liczby, żeby nikt nie budował na tej wyższej.

**Wniosek:** `nic nie` w co piątym widoku to jest maniera, nie przypadek.
Reszta jest rzadsza, niż sugeruje lista alarmowa.

---

## A. Idzie bez pytania — usterki faktyczne (robię)

To nie są kwestie tonu. Strona przecząca sama sobie, obietnica bez mechanizmu
i przycisk kłamiący o tym, co robi, są usterkami niezależnie od gustu.

| # | Co | Dlaczego to fakt, nie gust | Decyzja |
|---|---|---|---|
| A1 | **419: „nic nie przepadło" stoi bezwarunkowo**, a ostrzeżenie o obcięciu jest pod `@if($formularz->obciete)` | Przy obciętym formularzu strona mówi jednocześnie obie rzeczy — w chwili, gdy człowiek ma wysłać coś, co właśnie napisał | robię |
| A2 | **„Jutro będzie tu ktoś inny"** | Nic tego nie gwarantuje: żadna komenda nie zasila `DailyPick`, a wariant automatyczny sortuje po dacie publikacji. Przy pustym starcie zdanie kłamie najbardziej | robię |
| A3 | **„Obserwuj" dla gościa prowadzi do rejestracji** (`kuking-board.blade.php:71`) | Etykieta obiecuje akcję, której nie wykona. `AGENTS.md` §5: przycisk mówi, co robi | robię |
| A4 | **„Podgląd: tak zobaczą to inni"** przy przepisie, który może być prywatny | `Recipe` ma kolumnę `visibility`; dla prywatnego przepisu zdanie jest nieprawdziwe | robię |
| A5 | **„To zostaje w rodzinie"** przy przepisie, który może być publiczny | jw., odwrotny kierunek | robię |
| A6 | **„Potrzebny tylko wtedy, gdy zapomnisz hasła"** o adresie e-mail | Nieprawda: jest logowanie linkiem (D-056), zmiana adresu i powiadomienia | robię |
| A7 | **„To najczęściej czytana część przepisu"** | Twierdzenie analityczne, którego nikt nie zmierzył | robię |
| A8 | **„Zajmie minutę"** | Mierzalna obietnica, której nie mierzymy | robię |
| A9 | **Przykład hasła `zielonapietruszkarano`** | Uczy przewidywalnego wzorca bez separatorów. **Jest w DWÓCH miejscach**, audyt wymienia jedno | robię |
| A10 | **„zapisz szkic" obok autozapisu** | Dwa modele działania na jednym ekranie — człowiek nie wie, który obowiązuje | robię |

**Uwaga do A2:** stopki nie usuwam, tylko zastępuję. Komentarz w pliku mówi,
że jest częścią funkcji — sygnalizuje, że tablica NIE jest tabelą wyników,
co wprost służy zakazowi rankingów z §12. Usunięcie odsłoniłoby ten problem.

---

## B. Zderza się z Twoją spisaną decyzją — bez Ciebie nie ruszam

| # | Audyt proponuje | Co na to mówi Twoja decyzja | Decyzja |
|---|---|---|---|
| B1 | „Zostań kuKINGiem — to darmowe" → **„Załóż darmowe konto"** | `AGENTS.md` §11 wprost: *„Wolno «Zostań kuKINGiem»"*. Argument audytu (żart marki w funkcjonalnym CTA + dwie informacje naraz) jest rozsądny, ale to zmiana zasady | ☐ TAK ☐ NIE |
| B2 | Ograniczyć `kuKING` w CTA w ogóle | §11 pozwala raz na ekran; audyt chce zero w CTA | ☐ TAK ☐ NIE |
| B3 | 404: „To nie jest Twoja wina i nic się nie zepsuło" → sam fakt | §5 wymaga, żeby błąd mówił **co zrobić**. Skrócenie do samego faktu może ten wymóg naruszyć — chyba że zostawimy zdanie z instrukcją | ☐ TAK ☐ NIE |
| B4 | „Napisz normalnie, po ludzku" → **„Napisz komentarz"** | To ekran, przy którym sam zgłaszałeś uwagi 10.09 (odstępy, „(wymagane)") | ☐ TAK ☐ NIE |
| B5 | Przyjąć `COPY_STYLE_V2.md` jako rozszerzenie `docs/brand/COPY_STYLE.md` | To nowy dokument wiążący dla wszystkich modeli. Sensowny, ale zmienia zasady pisania w całym projekcie | ☐ TAK ☐ NIE |

---

## C. Ton i redakcja — Twoja zgoda wystarczy, kolizji nie ma

Pogrupowane, żeby nie odhaczać dwudziestu pozycji osobno.

| # | Grupa | Przykłady | Decyzja |
|---|---|---|---|
| C1 | **Landing: usunąć uspokajanie i slogany** | „Gotujemy po swojemu", „Nic więcej nie musisz", „ludzi, którzy gotują naprawdę", „firma, która czeka na Twoje dane", „Na to powiadomienie się tutaj czeka" | ☐ TAK ☐ NIE |
| C2 | **Kontakt: powiedzieć raz zamiast czterech razy** | „Po drugiej stronie jest człowiek, nie automat", „Lepiej dwa razy niż wcale", „nie będziemy go udawać", „inna droga i prowadzi do innej kolejki" | ☐ TAK ☐ NIE |
| C3 | **Logowanie i mail: instrukcja zamiast stylu autora** | „Klikasz — i jesteś w środku", „kliknij **zielony** przycisk", „i już będziesz w środku", „odpisuje człowiek" | ☐ TAK ☐ NIE |
| C4 | **Onboarding: nie tłumaczyć intencji UX** | „Jedno i drugie jest w porządku", „to pomoc…, a nie kolejny obowiązkowy krok" → „Ten krok jest opcjonalny" | ☐ TAK ☐ NIE |
| C5 | **Discover / About: bez antytechnologicznych wtrętów** | „Bez żadnego układania przez komputer" → „ułożone chronologicznie, bez rankingu"; „bez liczników w twarz"; „Ludzie, nie treści" | ☐ TAK ☐ NIE |
| C6 | **Bezpieczeństwo: nie pisać *o* starszej osobie** | „u wnuka, w bibliotece albo u znajomych" → „na wspólnym albo cudzym urządzeniu" | ☐ TAK ☐ NIE |
| C7 | **Przegląd 36 wystąpień „nic nie"** — każde z osobna, zostawić te, które niosą informację | „nic nie zginie" przy autozapisie niesie informację; „nic nie musisz" nie niesie nic | ☐ TAK ☐ NIE |

**C3 zawiera jedną rzecz, która jest też dostępnością, nie tylko tonem:**
„kliknij **zielony** przycisk" wiąże instrukcję z kolorem. `AGENTS.md` §5
zakazuje, żeby cokolwiek ważnego zależało od koloru. Jeśli odhaczysz C3 na
`NIE`, i tak poprawię samo to zdanie.

---

## D. Czego bym NIE robił, choć audyt proponuje

| # | Audyt proponuje | Dlaczego nie |
|---|---|---|
| D1 | „Przepis jest dobry wtedy, kiedy ktoś go ugotował" → złagodzić | To zdanie niesie **całą tezę produktu** — „Ugotowałem" ważniejsze od lajka (`AGENTS.md` §1). Osłabienie go osłabia wyróżnik, a zarzut („logicznie zbyt absolutne") dotyczy hasła marketingowego, od którego absolutność się oczekuje |
| D2 | Przepisać cały `COPY_STYLE.md` | Audyt sam pisze, że ten dokument jest *„znacznie lepszy niż typowy tone of voice"*. Rozszerzenie — tak (B5), zastąpienie — nie |
| D3 | Usunąć „Nie musi być ładne — ma być prawdziwe" | To zdanie zdejmuje lęk przed publikowaniem, a ten lęk jest zmierzony: `docs/research/AUDIENCE_50_PLUS.md:70` podaje, że **własne zdjęcie lub film zamieściło w ostatnim miesiącu tylko 17% internautów 55–64 i 13% z 65+** — przy 70% i 61% używających komunikatorów. Ci ludzie zdjęcia WYSYŁAJĄ, tylko ich nie PUBLIKUJĄ, i to jest jedyny nawyk, który ten produkt ma zmienić. **Ale audyt ma rację co do powtórzeń: zdanie stoi w 5 miejscach** (`home`, `discover`, `notifications` i dwa inne). Ograniczyć do jednego–dwóch: tak. Usunąć: nie |

---

## Jak to wdrożę, gdy odhaczysz

1. **A1–A10 osobnym PR-em**, od razu, z testami regresyjnymi tam, gdzie da się
   napisać sensowny (419 i „Obserwuj" dla gościa dają się przetestować wprost).
2. **C1–C7 drugim PR-em**, ekran po ekranie, z gotowymi wariantami
   z `READY_TO_PASTE.md` jako punktem wyjścia — nie kopiowanymi bezmyślnie.
3. **B1–B5 dopiero po Twoim `TAK`**, każdy z wpisem w `docs/DECISIONS.md`,
   bo każdy zmienia zasadę, a nie tekst.
