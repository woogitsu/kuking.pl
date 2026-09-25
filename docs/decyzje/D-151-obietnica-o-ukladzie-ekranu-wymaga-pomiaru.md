## D-151 · Obietnica o układzie ekranu wymaga pomiaru dokładnie tak samo jak obietnica o czasie

**Data:** 11 września 2026 · PR #396 · Status: **obowiązuje** · rozwinięcie D-114

### Zdanie, które było nieprawdą

`resources/views/pages/recipes/szczegoly.blade.php` mówił:

> „Wszystko jest na jednej stronie — **nie musisz nic przewijać ani szukać**."

### Ile naprawdę trzeba przewijać

Zmierzone w Chromium wzorcem z `scripts/dostepnosc.mjs`, zalogowany przez
prawdziwy formularz, adres `/przepisy/{slug}/edycja`. Skrypt sprawdza, że `h1`
to faktycznie „Dopisz szczegóły" — żeby nie zmierzyć strony błędu:

| stan przepisu | okno 360 px | okno 1280 px |
|---|---:|---:|
| sam tytuł, 3 puste wiersze składników i kroków | **10 249 px** = 16 ekranów | **7 836 px** = 9,8 ekranu |
| 8 składników i 6 kroków | **16 586 px** = 25,9 ekranu | **13 065 px** = 16,3 ekranu |

Kontrolek renderuje się **40** (stan pusty) do **70** (wypełniony).

### Dlaczego to jest ta sama sprawa co D-114

D-114 zabroniło pisać „zajmuje minutę" bez mechanizmu albo pomiaru, który to
pokrywa. **Obietnica o układzie ekranu jest obietnicą z miarą — tylko miarą jest
piksel, a nie sekunda.** „Nie musisz nic przewijać" mówi o wysokości strony
i liczbie kontrolek; jedno i drugie da się zmierzyć w minutę, i jedno i drugie
mówiło coś innego niż zdanie. To nie jest „AI voice" — to **zdanie
nieprawdziwe**, w tej samej klasie co ekran logowania obiecujący temat
wiadomości, której część ludzi nie dostanie.

Człowiek, któremu obiecano brak przewijania, a przewija 26 ekranów, nie myśli
„ładny copywriting". Myśli, że serwis nie mówi prawdy — i przy grupie 50+
kosztuje to od razu.

### Co zostało napisane zamiast

> „Wszystko jest na jednej stronie. Nic tu nie jest obowiązkowe: wypełnij tyle,
> ile chcesz, i zapisz. Poprawnie wpisane dane nie zginą."

**Obietnica znikła, cała informacja została.** „Na jednej stronie" zostaje, bo to
prawda i odróżnia ten ekran od kreatora w trzech krokach.

### Gdzie stoją liczby

W komentarzu Blade nad tym akapitem — żeby następna osoba, która chce dopisać to
zdanie z powrotem, przeczytała najpierw pomiar. Strażniki: dwa nowe pliki,
**13 testów**, wszystko na wyrenderowanym HTML-u; przy każdej asercji „czegoś
nie ma" stoi kontrola dodatnia, żeby test nie przechodził dlatego, że strona się
nie wyrenderowała.

| sabotaż | wynik |
|---|---|
| przywrócone „nie musisz nic przewijać ani szukać" | **CZERWONE** — 2 testy, komunikat z liczbami z pomiaru |
| usunięte przy okazji zdanie o nieobowiązkowości | **CZERWONE** — 2 testy: „Zniknęła informacja, że żadne pole nie jest wymagane" |

Druga kontrola nie jest ozdobą: przy zdejmowaniu nieprawdziwej obietnicy
najłatwiej zabrać razem z nią informację, którą ta obietnica niosła.

**Zmiana wymaga:** ekranu, który naprawdę mieści się bez przewijania
w zmierzonym stanie. Wtedy zdanie wraca — z pomiarem.

📄 `docs/brand/COPY_STYLE.md` · `scripts/dostepnosc.mjs` · D-114 · D-099 · D-106
