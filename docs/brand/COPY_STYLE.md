# Głos Kuking — jak piszemy

Ten dokument jest **wiążący dla każdego tekstu widocznego dla użytkownika**:
przyciski, nagłówki, puste stany, błędy, e-maile, powiadomienia.

Jeśli piszesz cokolwiek, co przeczyta człowiek — piszesz według tego pliku.
Słownik funkcji i lista słów zakazanych: `BRAND_EXTENDED.md`.

---

## 1. Jeden akapit, który wystarczy zapamiętać

Kuking mówi jak **sąsiadka, która dobrze gotuje i nie ma potrzeby się popisywać**.
Ciepło, krótko, z lekkim uśmiechem. Żart jest **w tle**, nigdy na pierwszym planie —
ma być zauważony przy drugim czytaniu, nie wywalczyć sobie uwagę przy pierwszym.

Test, który przechodzi każdy nasz tekst:

> Czy 65-letnia Basia z Podkarpacia przeczytałaby to na głos córce
> **bez zażenowania** — ani swojego, ani jej?

Jeśli tekst brzmi jak reklama, jak aplikacja do medytacji albo jak wnuczek
tłumaczący coś babci — do przepisania.

---

## 2. kuKING — najważniejsza decyzja w całym systemie

W słowie **Ku-KING** siedzi **KING**. To jest cała zabawa i trzeba ją rozegrać
dokładnie w jeden sposób.

### kuKING to nazwa mieszkańca, nie tytuł za osiągnięcia

**kuKING** = ktoś, kto tu gotuje. Tak jak „forumowicz", „wikipedysta",
„nasz-klasowicz". Neutralne, ciepłe określenie **przynależności**.

To jest kluczowe rozstrzygnięcie, bo `MASCOT_CONCEPT.md` zawiera twardy zakaz:

> **Nigdy „Jesteś królem kuchni!"** — korona jest żartem o garnku, nie
> komplementem dla użytkownika.

Ten zakaz zostaje w mocy i **nie kłóci się** z „Zostań kuKINGiem". Różnica jest
w tym, o czym jest żart:

| | O czym jest żart | Ocena |
|---|---|---|
| „Zostań kuKINGiem" | o nazwie serwisu | ✅ wolno |
| „Witaj w gronie kuKINGów" | o przynależności | ✅ wolno |
| „Jesteś prawdziwym kuKINGiem!" | o użytkowniku | ❌ zakaz |
| „Top kuKINGi tygodnia" | o hierarchii | ❌ zakaz |

Powód nie jest estetyczny, a produktowy. Ponad połowa osób 50+ w mediach
społecznościowych **nigdy nic nie publikuje**. Komplement za publikację
podnosi poprzeczkę („skoro to ma być królewskie, to ja nie mam czego pokazać").
Nazwa przynależności ją **obniża** — wystarczy tu być.

### Odmiana

Używamy tylko form, które brzmią naturalnie:

- **kuKING** — mianownik: „Zostań kuKINGiem", „nowy kuKING"
- **kuKINGiem** — narzędnik: „Zostań kuKINGiem"
- **kuKINGów** — dopełniacz mnogi: „2 431 kuKINGów"
- **kuKINGi** — mianownik mnogi w znaczeniu **rzeczy, nie osób**: „kuKINGi na dziś"

Form, których **nie używamy**, bo brzmią sztucznie albo dziwnie w mowie:
`kuKINGowi`, `kuKINGu`, `kuKINGowie`, `kuKINGce`, `kuKINGówka`.
Jeśli zdanie wymaga takiej formy — przepisz zdanie, nie odmieniaj słowa na siłę.

### Forma żeńska: nie tworzymy jej

Głównymi użytkownikami Kuking są kobiety 60+ (w badaniu UTW **83,8% słuchaczy
to kobiety**). Żadna żeńska forma od „kuKING" nie brzmi po polsku dobrze —
każda próba wychodzi albo pretensjonalnie, albo śmiesznie w złym sensie.

Dlatego: **kuKING jest nazwą rodzaju wspólnego, jak „gość" w „mamy gościa"**,
i używamy go w liczbie mnogiej albo bezosobowo. Zamiast szukać żeńskiej formy,
zmieniamy konstrukcję zdania.

```text
❌ Jesteś kuKINGiem od 2 lat
❌ Jesteś kuKINGką od 2 lat
✅ Gotujesz z nami od 2 lat
✅ W Kuking od 2 lat
```

W pozostałych tekstach zwracamy się **bezpośrednio, przez „Ty"** i unikamy
rodzaju: „Napisz kilka słów", „Zapisz", „Pokaż, co dziś ugotowałeś".

> Uwaga na formy czasowników. „ugotowałeś" w haśle głównym jest już utrwalone
> i zostaje. W tekstach roboczych wolimy konstrukcje bez rodzaju: **„Co dziś
> gotujesz?"** działa dla wszystkich i jest krótsze.

### Dawkowanie: jeden żart na ekran

**Maksymalnie jedna gra słowem kuKING na ekran.** Dwa razy na jednej stronie
zamieniają dowcip w nachalność.

**kuKING nigdy nie pojawia się w:**

- komunikacie błędu — człowiek ma wtedy problem, nie nastrój na żarty,
- wiadomości moderacyjnej,
- regulaminie, polityce prywatności i zasadach,
- powiadomieniu o cudzej aktywności („Halina ugotowała Twój rosół" jest już
  doskonałe — dodanie „kuKING" tylko by je rozmyło),
- formularzu, który ktoś właśnie wypełnia.

### Zapis i dostępność

Piszemy `kuKING` — małe „ku", wersaliki „KING". W kodzie:

```html
<span class="kuking-word" aria-label="kuking">ku<strong>KING</strong></span>
```

Powód `aria-label`: część czytników ekranu literuje wersaliki wewnątrz wyrazu
(„ku-ka-i-en-gie"), co dla osoby korzystającej z czytnika zamienia nazwę
w bełkot. `aria-label` z zapisem małymi literami to naprawia.
`[do potwierdzenia testem NVDA i VoiceOver]`

W tekstach niesformatowanych (e-maile tekstowe, alt, tytuł strony) piszemy
zwyczajnie: **Kuking**.

---

## 3. Rejestr — cztery poziomy i gdzie który obowiązuje

| Poziom | Gdzie | Jak brzmi |
|---|---|---|
| **Ciepły z żartem** | strona główna dla gości, puste stany, ekran po pierwszym wpisie, digest | „Zostań kuKINGiem", „kuKINGi na dziś" |
| **Ciepły bez żartu** | feed, profil, przepis, przyciski, powiadomienia | „Pokaż, co dziś ugotowałeś", „Zapisuję" |
| **Rzeczowy** | formularze, ustawienia, pomoc | „Wybierz zdjęcie z telefonu", „Rozmiar tekstu" |
| **Poważny** | błędy, moderacja, usuwanie konta, prawne | „To zdjęcie waży za dużo. Maksymalny rozmiar to 15 MB — wybierz mniejsze." |

Ruch odbywa się **tylko w dół**. Ekran z poziomu „poważny" nigdy nie dostaje
żartu z góry. Ekran „ciepły z żartem" może być rzeczowy, jeśli tak wyjdzie lepiej.

---

## 4. Skąd bierzemy humor (i skąd nie)

### Bierzemy

**Niedopowiedzenie.** Najlepszy nasz żart to zdanie, które nie próbuje być żartem.

```text
✅ Wyszło. I to się liczy.
✅ Nie musi być ładne. Ma być prawdziwe.
✅ Rosół nie znosi pośpiechu. Ta strona też nie.
```

**Rozpoznanie realnego życia w kuchni.** Śmieszne jest to, co prawdziwe.

```text
✅ „mleko — ile weźmie"
✅ Pisz tak, jak mówisz: „szklanka mąki", „2 duże cebule".
✅ Jeśli nie masz teraz czasu — zapisz szkic. Nic nie zginie.
```

**Ciepła autoironia serwisu wobec siebie.**

```text
✅ Garnuś nic nie mówi. Garnuś patrzy.
✅ Tu nie ma rankingów. Nie ma kogo wyprzedzać.
```

### Nie bierzemy

```text
❌ Hej! 🎉 Zróbmy to razem!
❌ Ups! Coś poszło nie tak 😅
❌ Twoja kulinarna przygoda właśnie się zaczyna!
❌ Odkryj świat smaków w naszej społeczności
❌ Tapnij, żeby kontynuować
❌ Jesteś na fali! Świetna robota!
```

Cztery reguły techniczne:

1. **Zero emoji w tekstach interfejsu.** Emoji w nawigacji są ikonami
   z podpisem, nie żartem, i to jest jedyne ich zastosowanie.
2. **Jeden wykrzyknik na ekran, najwyżej.** Zwykle zero.
3. **Bez wielokropków** zawieszających napięcie („Zaraz zobaczysz...").
4. **Bez pytań retorycznych** poza jednym: „Co dziś ugotowałeś?" — to jest
   pytanie prawdziwe, oczekujemy odpowiedzi.

---

## 5. kuKINGi na dziś — tablica polecanych

Sekcja z kilkoma osobami i kilkoma wpisami wartymi zobaczenia dzisiaj.
Odpowiednik „Dla Ciebie" z innych portali, tylko **bez algorytmu i bez rankingu**.

### Co to jest, a czym nie jest

| Jest | Nie jest |
|---|---|
| krótka, redakcyjna albo chronologiczna | rankingiem popularności |
| 3-4 osoby + 3-4 wpisy | ścianą kafelków |
| powodem, żeby kogoś zaobserwować | listą „najlepszych" |
| zmienna z dnia na dzień | miejscem, do którego się awansuje |

To rozstrzygnięcie wynika z `AGENTS.md`: publiczne rankingi natychmiast dzielą
ludzi na dwie klasy i wyłączają publikowanie u większości. „kuKINGi na dziś"
to **zaproszenie**, nie wyróżnienie.

### Teksty tej sekcji

```text
Nagłówek:        kuKINGi na dziś
Podtytuł:        Kilka osób i kilka dań, które dziś warto zobaczyć.
Przycisk osoby:  Obserwuj
Przycisk wpisu:  Zobacz
Stopka sekcji:   Jutro będzie tu ktoś inny.
Pusty stan:      Dziś jeszcze nikogo nie wybraliśmy. Zajrzyj do „Świeżo z Kuking”.
```

Ostatnie zdanie stopki jest ważne: mówi wprost, że to się zmienia i nie jest
tabelą wyników.

### ⚠️ Zastrzeżenie do sprawdzenia na ludziach

Dla **rzeczownika osobowego** forma `kuKINGi` jest w polszczyźnie
deprecjatywna — ta sama, która daje „profesory" i „chłopy".

Nazwa zostaje, bo `kuKING` jest tu użyty w znaczeniu **rzeczy**
(por. „mityng → mityngi"), a tablica pokazuje dania obok ludzi. Ale to jest
rozumowanie zza biurka i musi zostać sprawdzone na realnych osobach
w testach 50+ (issue #15), jednym pytaniem: **„o czym jest ta sekcja?"**

Gotowe alternatywy, gdyby test wypadł źle:

| Alternatywa | Dlaczego działa |
|---|---|
| **Dziś u kuKINGów** | dopełniacz mnogi nie jest formą deprecjatywną, gra słowem zostaje |
| **Co się dziś gotuje** | nie odmienia słowa wcale, problem znika u źródła |

Decyzja i uzasadnienie: `../DECISIONS.md` D-013.

### Nazwy odrzucone i dlaczego

| Nazwa | Dlaczego nie |
|---|---|
| Top kuKINGi | ranking — wprost zakazany |
| Polecane dla Ciebie | brzmi jak algorytm, którego nie mamy |
| Odkrywaj | „Explore" po polsku, na liście słów zakazanych |
| Trendy w Kuking | korpo-mowa, obca tej grupie |
| Gwiazdy Kuking | tworzy influencerów, czego świadomie nie chcemy |
| Warto zobaczyć | poprawne, ale nudne — a nazwa jest jednym z niewielu miejsc, gdzie wolno nam być zabawnymi |

---

## 6. Gotowe teksty — do wklejenia

Kolumna „miejsce" wskazuje realny plik albo ekran.

### Wejście i konto

| Miejsce | Tekst |
|---|---|
| hasło główne | Pokaż, co dziś ugotowałeś. |
| hasło drugie | Gotujemy po swojemu. |
| przycisk rejestracji | Zostań kuKINGiem — to darmowe |
| przycisk obok | Najpierw się rozejrzę |
| nagłówek rejestracji | Zostań kuKINGiem |
| pod nagłówkiem | Cztery pola i gotowe. Nie pytamy o numer telefonu ani o datę urodzenia. |
| po rejestracji | Konto gotowe. Miło Cię widzieć. |
| koniec onboardingu | Wszystko gotowe, {imię} |
| pod tym | Możesz od razu pokazać, co dziś ugotowałeś — albo najpierw się rozejrzeć. Jedno i drugie jest w porządku. |
| logowanie, podpowiedź | Możesz wpisać e-mail albo swoją nazwę — obojętnie które. |

### Publikacja

| Miejsce | Tekst |
|---|---|
| pytanie dnia, rano | Dzień dobry, {imię}. Co dziś gotujesz? |
| pytanie dnia, wieczór | Dobry wieczór, {imię}. Pokaż, co dziś wyszło. |
| przycisk główny | Dodaj zdjęcie tego, co ugotowałeś |
| pole tekstowe | Napisz kilka słów |
| podpowiedź pod polem | Na przykład: „Rosół na niedzielę, z kaczki od sąsiada. Wyszedł złoty." |
| wybór zdjęcia | Na telefonie kliknij tutaj, a potem wybierz „Galeria" albo „Zrób zdjęcie". |
| po pierwszym wpisie | Gotowe. To Twój pierwszy wpis — od teraz masz swoje archiwum. |
| po kolejnym | Opublikowane. Dziękujemy. |
| autosave szkicu | Szkic zapisany. |

### Przepis

| Miejsce | Tekst |
|---|---|
| zachęta do zapisu szkicu | Jeśli nie masz teraz czasu — zapisz szkic. Nic nie zginie i wrócisz do tego, kiedy zechcesz. |
| sekcja pochodzenia | Skąd ten przepis |
| pod nagłówkiem sekcji | To najczęściej czytana część przepisu. Ludzie chcą wiedzieć, po kim on jest. |
| pole „po kim" | Po kim ten przepis |
| podpowiedź | po mamie, Halinie |
| pole historii | Historia tego przepisu |
| podpowiedź | Skąd go znasz, kiedy się go gotuje, co Ci się z nim wiąże. To zostaje w rodzinie. |
| skan kartki | Jeśli masz przepis zapisany ręcznie — zrób mu zdjęcie. Zostanie przy przepisie. |
| składniki, podpowiedź | Pisz tak, jak mówisz: „szklanka mąki", „2 duże cebule", „mleko — ile weźmie". Nie musisz nic przeliczać na gramy. |
| kroki, podpowiedź | Jeden krok to jedna czynność. Krótkie kroki łatwiej czytać przy garnku. |
| po publikacji | Przepis opublikowany. Teraz ktoś może z niego ugotować. |

### Ugotowałem

| Miejsce | Tekst |
|---|---|
| przycisk na przepisie | Ugotowałem |
| nagłówek sekcji | Ugotowałeś z tego przepisu? |
| pod nagłówkiem | {autor} naprawdę chce o tym wiedzieć. Wystarczy jedno kliknięcie. |
| formularz, uspokojenie | Nie musisz wypełniać żadnego pola — wystarczy, że klikniesz „Wyślij". |
| zdjęcie efektu | To jest najmilsza część dla autora przepisu. Zdjęcie nie musi być ładne. |
| pole uwagi | Jak wyszło? |
| pole zmian | Zrobiłem coś po swojemu? |
| po wysłaniu | Zapisane. {autor} dowie się, że ktoś ugotował z tego przepisu. |
| powiadomienie autora | {imię} ugotowała Twój rosół. |
| sekcja pod przepisem | Komu wyszło |
| pod nagłówkiem | Zdjęcia od ludzi, którzy naprawdę to zrobili u siebie. |

### Puste stany

| Miejsce | Tekst |
|---|---|
| pusty feed | Jeszcze nic tu nie ma |
| + wyjaśnienie | Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe. |
| pusty zeszyt | Zeszyt jest jeszcze pusty |
| + wyjaśnienie | Kiedy znajdziesz przepis, który chcesz zachować, kliknij przy nim „Zapisuję". Trafi tutaj i zawsze go znajdziesz. |
| puste archiwum, własne | Twoje archiwum jest jeszcze puste |
| + wyjaśnienie | Od pierwszego zdjęcia zaczyna się Twoje archiwum. Za rok będziesz mogła tu wrócić i zobaczyć, co wtedy gotowałaś. |
| brak powiadomień | Nie ma jeszcze żadnych powiadomień |
| + wyjaśnienie | Tu pojawi się informacja, kiedy ktoś ugotuje z Twojego przepisu albo napisze komentarz. |
| brak wyników szukania | Nic nie znaleźliśmy |
| + wyjaśnienie | Nie ma jeszcze przepisu, który by pasował do „{fraza}". Może to Ty go dodasz? |
| brak komentarzy | Jeszcze nikt tu nic nie napisał. Możesz być pierwsza albo pierwszy. |

### Błędy — poziom „poważny", zero żartów

Wzór: **co się stało → dlaczego → co zrobić.**

| Sytuacja | Tekst |
|---|---|
| zdjęcie za duże | To zdjęcie waży za dużo. Maksymalny rozmiar to 15 MB — wybierz mniejsze zdjęcie. |
| nie jest obrazem | Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP. |
| pusty wpis | Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego opublikować. |
| hasło za krótkie | Hasło musi mieć co najmniej 10 znaków. Najprościej wpisać trzy słowa, na przykład: zielonapietruszkarano. |
| hasło z wycieku | To hasło pojawiło się już w wyciekach danych z innych serwisów. Wybierz inne. |
| zła nazwa użytkownika | Nazwa użytkownika może zawierać tylko litery bez polskich znaków, cyfry i podkreślnik. Na przykład: basia_z_podkarpacia. |
| nazwa zajęta | Ta nazwa jest już zajęta. Spróbuj dodać coś na końcu. |
| błąd logowania | Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie. Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła". |
| za dużo prób | Za dużo prób logowania. Spróbuj ponownie za {n} min. |
| podsumowanie, 1 błąd | Jednej rzeczy jeszcze brakuje |
| podsumowanie, więcej | Kilku rzeczy jeszcze brakuje |
| brak internetu | Nie ma teraz połączenia z internetem |
| + wyjaśnienie | Kuking potrzebuje internetu, żeby pokazać nowe wpisy. Sprawdź Wi-Fi albo dane w telefonie i spróbuj jeszcze raz. |

### Rzeczy nieodwracalne

Tu obowiązuje **pełna szczerość i zero łagodzenia**.

| Sytuacja | Tekst |
|---|---|
| usunięcie wpisu | Na pewno usunąć ten wpis? Tej operacji nie da się cofnąć samodzielnie. |
| usunięcie przepisu | Na pewno usunąć ten przepis? Wykonania i komentarze innych osób też przestaną być widoczne. |
| blokada osoby | Zablokować {imię}? Nie zobaczycie już wzajemnie swoich treści. |
| przed usunięciem konta | Zanim to zrobisz, warto najpierw pobrać swoje dane. |
| potwierdzenie | Rozumiem, że po 30 dniach moje wpisy, przepisy i zdjęcia zostaną usunięte na stałe |
| po zgłoszeniu | Konto zostało oznaczone do usunięcia. Masz 30 dni, żeby zmienić zdanie — wystarczy, że się zalogujesz i napiszesz do nas. |

### E-mail

| Miejsce | Tekst |
|---|---|
| temat digestu, gdy ktoś ugotował | {imię} ugotowała Twój rosół |
| temat digestu, ogólny | Co się działo w Kuking w tym tygodniu |
| nadawca | imię gospodarza, nigdy „Zespół Kuking" |
| stopka wypisania | Nie chcesz tych wiadomości? Wyłącz je jednym kliknięciem. Bez pytań. |
| eksport gotowy | Twoje dane są gotowe do pobrania |
| + treść | Przygotowaliśmy paczkę ze wszystkim, co tu wrzuciłaś. Otworzysz ją na swoim komputerze, także wtedy, gdyby Kuking kiedyś przestał istnieć. |

---

## 7. Lista kontrolna przed wysłaniem tekstu

- [ ] Da się to przeczytać na głos bez zażenowania?
- [ ] Zero emoji, najwyżej jeden wykrzyknik?
- [ ] Gra słowem kuKING występuje **co najwyżej raz** na tym ekranie?
- [ ] Na pewno nie ma jej w błędzie, moderacji ani tekście prawnym?
- [ ] Komunikat błędu mówi, **co zrobić**?
- [ ] Nie ma słów z listy zakazanych (`BRAND_EXTENDED.md`)?
- [ ] Nie ma komplementu za publikację ani śladu rankingu?
- [ ] Konstrukcja nie zakłada rodzaju tam, gdzie da się tego uniknąć?
- [ ] Zdanie nie jest dłuższe niż trzeba? (Skreśl trzy słowa. Zwykle da się.)

---

## 8. Decyzje podjęte i te, które zostały

### Podjęte

**Dawka: umiarkowana** (decyzja właściciela, 5 września 2026 — `../DECISIONS.md` D-009).

`kuKING` w 3-4 miejscach: rejestracja, tablica „kuKINGi na dziś", licznik
społeczności, digest. Maksymalnie raz na ekran.

Odrzucone świadomie:

| Odrzucone | Dlaczego |
|---|---|
| `kuKINGujesz` | nowy czasownik trzeba zrozumieć, a nasz odbiorca nie lubi zgadywać |
| `Mój kuKING` w nawigacji | nawigacja ma być przewidywalna, nie dowcipna |
| forma żeńska | żadna nie brzmi po polsku dobrze |
| dawka minimalna | „kuKINGi na dziś" to jedna z mocniejszych rzeczy w tym pomyśle, szkoda jej |

**„Zostań kuKINGiem" zastępuje „Załóż konto"** tam, gdzie jest miejsce na
kontekst: strona główna i nagłówek `/register`. W wąskim pasku nawigacji
zostaje krótkie „Załóż konto".

### Zostały

1. **Imię gospodarza w e-mailach.** Bez prawdziwego imienia digest traci
   większość swojej wartości. Czeka na rozstrzygnięcie, kto jest gospodarzem
   (`../DECISIONS.md` D-012).
2. **Weryfikacja u realnych użytkowników.** Dawka jest wybrana rozsądnie, ale
   dopiero testy z osobami 50+ (#15) powiedzą, czy „kuKING" bawi, czy męczy.

## Referencje

`BRAND_EXTENDED.md` (słownik i słowa zakazane) · `MASCOT_CONCEPT.md` (zakaz
komplementowania koroną) · `../UX_50_PLUS.md` (wzorce błędów) ·
`../product/SOUL.md` (mikro-copy pustych stanów) · `../research/AUDIENCE_50_PLUS.md`
