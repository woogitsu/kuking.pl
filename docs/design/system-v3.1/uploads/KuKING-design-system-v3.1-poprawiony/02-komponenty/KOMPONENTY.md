> **Aktualizacja v3.1 · 7 września 2026.** Zmiany z audytu opisuje [AUDYT-V3.1.md](../AUDYT-V3.1.md). W sprawach motywu, stopki, pozycjonowania nagłówka i skali 200% ten dokument oraz `07-wdrozenie/MOTYW-V3.1.md` mają pierwszeństwo przed poniższym opisem v3.0. Paleta i znak pozostają bez zmian.

# Komponenty — specyfikacja

Ten plik opisuje każdy komponent systemu: po co jest, na których z piętnastu
ekranów stoi, jakie ma warianty i stany, z jakich klas się go składa, jak
wygląda jego najkrótszy poprawny kod, co czyta czytnik ekranu i czego przy nim
nie wolno zrobić.

Żywy podgląd wszystkiego, co tu opisane — **[`galeria.html`](galeria.html)**:
każdy komponent, każdy stan, oba motywy, na jednej stronie, bez klikania
i bez skryptu.

**Trzy zasady, z których wynika reszta.** Cytuję je raz, żeby nie powtarzać ich
przy każdym komponencie:

1. **Jeden akcent na powierzchnię** (D-110). Na jednej karcie dokładnie jedna
   rzecz ma kolor marki; na jednym ekranie dokładnie jedna akcja jest
   przyciskiem głównym.
2. **Hierarchia jest w rozmiarze i wadze, nie w kolorze.** Tytuł 24 px / 800,
   treść 20 px, autor 18 px / 700, metadane 15 px w atramencie stonowanym.
3. **Żadnej wysokości na sztywno** na elemencie z tekstem — zawsze `min-height`
   i wcięcie, żeby przy 140% skali tekst się zawinął, a nie został ucięty.

**Skąd biorą się teksty w przykładach.** Każdy napis interfejsu — etykieta
przycisku, podpowiedź, pusty stan, komunikat błędu — jest przepisany
z `COPY_STYLE.md` §6 co do znaku. Nazwy dań, imiona i daty są danymi, nie
napisami interfejsu; są zmyślone i dobrane tak, żeby zgadzały się ze zdjęciem
(zdjęcie pierogów podpisane „Pierogi ruskie po babci”, nie „Rosół”).

---

## Spis: komponent → na ilu z 15 ekranów

| Komponent | Ekranów | Które |
|---|---|---|
| Przycisk | **15** | wszystkie; na 15 jako przełącznik motywu w stopce |
| Pole formularza | 7 | 03, 04, 07, 09, 12, 13, 14 (plus pole szukania w belce) |
| Grupa wyboru „Kto to widzi” | 2 | 12, 13 |
| Pole zaznaczenia | 3 | 04, 13, 14 |
| Wybór zdjęcia | 2 | 12, 13 |
| Karta wpisu — pełna | 2 | 02, 08 |
| Karta wpisu — zwarta | 4 | 05, 06, 09, 10 |
| Karta przepisu | 6 | 02, 06, 07, 08, 09, 10 |
| Karta „Ugotowałem” | 2 | 05, 07 |
| Plakietka | 8 | 02, 05, 07, 08, 09, 10, 11, 13 |
| Chip zakresu | 2 | 06, 09 |
| Awatar | 8 | 02, 05, 06, 07, 08, 09, 10, 11 (plus belka górna) |
| Komunikat | 6 | 03, 04, 07, 12, 13, 14 |
| Podsumowanie błędów | 4 | 03, 04, 12, 13 |
| Plakietka autozapisu | 1 | 13 |
| Pusty stan | 8 | 02, 05, 06, 07, 08, 09, 10, 11 |
| Kroki kreatora | 1 | 13 |
| Belka górna | **15** | wszystkie |
| Nawigacja boczna | 7 | 08, 09, 10, 11, 12, 13, 14 (od 1024 px) |
| Dolny pasek nawigacji | 7 | 08, 09, 10, 11, 12, 13, 14 (do 1024 px) |
| Moduł szyny | 2 | 08, 09 |
| Wiersz powiadomienia | 1 | 11 |
| Tekst na zdjęciu | 2 | 01, 07 |
| Stopka | **15** | wszystkie |
| Potwierdzenie akcji destrukcyjnej | 4 | 05, 07, 08, 13 |
| Wątek komentarzy | 1 | 07 |
| Stronicowanie „Pokaż więcej” | 8 | 02, 05, 06, 07, 08, 09, 10, 11 |
| Części ekranu przepisu | 1 | 07 |
| Tabela | 1 | 15 |

Numery ekranów są te same co w `01-stan-obecny/inwentarz-ekranow.md`.

---

## 1. Przycisk

**Do czego jest.** Uruchamia akcję i mówi wprost, która akcja na tym ekranie
jest ważniejsza od pozostałych.

**Gdzie występuje.** Na wszystkich piętnastu ekranach.

**Warianty (cztery wagi).**

| Klasa | Kiedy | Ile na ekran |
|---|---|---|
| `.btn-primary` | jedna akcja, po którą ekran istnieje | **dokładnie jeden** |
| `.btn-secondary` | akcja realna, ale nie główna („Zapisz szkic”, „Obserwuj”) | ile trzeba |
| `.btn-quiet` | akcja poboczna („Anuluj”, „Zapisz”, licznik komentarzy) | ile trzeba |
| `.btn-danger` | akcja, której nie da się cofnąć samodzielnie | jeden, w osobnej sekcji |

Do tego dwa modyfikatory: `.btn-duzy` (56 px, akcja główna na telefonie)
i `.btn-pelny` (na całą szerokość kolumny).

**Stany.**

- **spoczynek** — tło i obwódka według wagi, tekst 18 px / 700, minimum 48 px
  wysokości;
- **najechanie** — tło ciemnieje o jeden stopień (`--color-brand-solid-hover`
  dla głównego, `--color-surface-sunken` dla wtórnego i cichego); nic się nie
  przesuwa;
- **fokus klawiatury** — pierścień `--color-focus` z **dwupikselowym halo
  w kolorze tła pod spodem**. Halo nie jest ozdobą: niebieski pierścień
  bezpośrednio na terakocie daje 1.0–1.2:1, bo oba kolory mają zbliżoną
  jasność. Dwa piksele tła sprawiają, że pierścień styka się z powierzchnią,
  wobec której jego kontrast jest policzony (5.03:1 jasny, 7.17:1 ciemny).
  Na karcie halo bierze kolor karty, nie strony — stąd osobna reguła
  `.card .btn:focus-visible`;
- **wciśnięty** — jeden piksel w dół. Tyle, żeby palec dostał potwierdzenie,
  za mało, żeby układ drgnął;
- **wyłączony** — 55% krycia i **zawsze** zdanie obok (`.btn-wyjasnienie`),
  co zrobić, żeby go odblokować;
- **błąd** — przycisk nie ma stanu błędu. Błąd należy do pola albo do
  formularza, nie do przycisku.

**Klasy.** `.btn` + waga + ewentualnie `.btn-duzy`, `.btn-pelny`;
`.btn-wyjasnienie` pod wyłączonym; `.rzad-przyciskow` na kilka obok siebie
(zawija się przy 320 px zamiast wystawać); `.danger-zone` + `.danger-zone-tytul`
na akcję nieodwracalną.

```html
<button type="submit" class="btn btn-primary">Opublikuj</button>

<button type="submit" class="btn btn-primary" disabled>Opublikuj</button>
<span class="btn-wyjasnienie">Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego opublikować.</span>

<div class="rzad-przyciskow">
  <button type="submit" class="btn btn-primary">Opublikuj</button>
  <button type="submit" class="btn btn-secondary">Zapisz szkic</button>
  <a class="btn btn-quiet" href="/dodaj/przepis">Wstecz</a>
</div>
```

**Dostępność.**

- `<button>` do akcji, `<a>` do przejścia pod adres. Link stylizowany na
  przycisk zostaje linkiem: da się go otworzyć w nowej karcie, a czytnik czyta
  „odnośnik”, nie „przycisk”.
- Etykieta jest treścią elementu, nie `aria-label` — czytnik i oko mają
  słyszeć i widzieć to samo.
- Ikona wewnątrz ma `aria-hidden="true"` i **nigdy nie występuje sama**.
- Wyłączony: `disabled` na `<button>`. Jeśli przycisk ma zostać w kolejności
  fokusu (bo chcemy, żeby dało się do niego dojść i usłyszeć wyjaśnienie), użyj
  `aria-disabled="true"` i powiąż wyjaśnienie przez `aria-describedby`.
- Kolejność fokusu jest kolejnością w kodzie: główny przed wtórnym, wtórny
  przed cichym, destrukcyjny na końcu i w osobnej sekcji.

**Czego NIE robić.**

- **Nie stawiaj dwóch przycisków głównych na ekranie.** Wtedy żaden nie jest
  główny — dokładnie to jest problemem nr 3 dzisiejszej tablicy, gdzie „Napisz
  komentarz” i „Zapisz” wyglądają identycznie.
- **Nie zmniejszaj przycisku poniżej 48 px** i nie zamieniaj go na samą ikonę —
  ani w karcie, ani w belce.
- **Nie stawiaj „Usuń” obok „Zapisz”.** Minimum `--spacing-8` odstępu albo
  osobna sekcja z linią i nagłówkiem. Ręka, która trafia obok, nie ma cofnięcia.
- **Nie wyłączaj przycisku po cichu.** `disabled` bez zdania „co zrobić” to
  ślepy zaułek — reguła „nigdy” nr 6.
- **Nie zastępuj `outline` niczym słabszym.** Halo jest zamiennikiem
  równoważnym, brak obwódki nie jest.

---

## 2. Pole formularza

**Do czego jest.** Zbiera jedną informację i mówi przed wpisaniem, czego
oczekujemy.

**Gdzie występuje.** 03 logowanie, 04 rejestracja, 07 przepis (komentarz),
09 szukaj, 12 dodaj zdjęcie, 13 dodaj przepis, 14 czytelność. Plus pole
szukania w belce górnej.

**Warianty.**

*Typ:* tekst, obszar tekstowy (`textarea`), liczba (`type="number"` albo
`inputmode="numeric"`), lista rozwijana (`select`), hasło, adres e-mail.

*Szerokość* dobrana do treści (D-109) — bo prostokąt na czterdzieści znaków
pod pytaniem „Ile porcji?” mówi człowiekowi, że oczekujemy zdania:

| Klasa | Szerokość | Do czego |
|---|---|---|
| `.field-input-rok` | 8ch | rok, godzina |
| `.field-input-liczba` | 10ch | porcje, minuty, ilość |
| `.field-input-krotkie` | 22ch | nazwa składnika, „po kim ten przepis” |
| `.field-input-srednie` | 40ch | nazwa przepisu, tytuł |
| bez klasy | 100% | akapit, adres, opis |

`textarea` ma dwie wysokości: 7rem zwykła i 12rem `.field-input-dlugie` dla
historii przepisu — wysokość pola jest zaproszeniem do pisania.

**Stany.**

- **spoczynek** — tło wgłębione, dwupikselowa obwódka `--color-border-strong`
  (3.51:1 wobec własnego tła), tekst 18 px;
- **najechanie** — ciemnieje sama obwódka. **Tło się nie zmienia**: zmiana tła
  pod kursorem dezorientuje, bo wygląda jak zmiana stanu, a nie jak wskazanie;
- **fokus klawiatury** — trzypikselowa obwódka `--color-focus` z odstępem 2 px;
- **wciśnięty** — pole nie ma takiego stanu;
- **wyłączony** — 60% krycia; obok zdanie, dlaczego (np. „Nazwy nie da się
  zmienić po założeniu konta.”);
- **błąd** — `.field.has-error` pogrubia obwódkę do 3 px w kolorze
  `--color-danger` (5.51:1 wobec tła pola) i pod polem staje `.field-error`:
  ikona ostrzeżenia plus zdanie w schemacie **co się stało → dlaczego → co
  zrobić**.

**Klasy.** `.field` (opakowanie), `.field-etykieta`, `.field-wymagane`,
`.field-podpowiedz`, `.field-input` + szerokość, `.field-help`, `.field-error`,
`.field.has-error`. Dwa pola w jednym wierszu: `.rzad-pol` (zawija się przy
320 px i zeruje górny margines pól w środku). Sekcje długiego formularza:
`.form-section`, `.form-section-title`, `.form-section-opis`, `.form-actions`.

```html
<div class="field">
  <label class="field-etykieta" for="nazwa">
    Nazwa przepisu <span class="field-wymagane">wymagane</span>
  </label>
  <span class="field-podpowiedz" id="nazwa-pod">Tak, jak mówisz o nim w domu.</span>
  <input class="field-input field-input-srednie" type="text" id="nazwa" name="nazwa"
         aria-describedby="nazwa-pod" required>
</div>

<div class="rzad-pol">
  <div class="field">
    <label class="field-etykieta" for="minuty">Ile minut</label>
    <input class="field-input field-input-liczba" type="number" id="minuty" name="minuty">
  </div>
  <div class="field">
    <label class="field-etykieta" for="porcje">Ile porcji</label>
    <input class="field-input field-input-liczba" type="number" id="porcje" name="porcje">
  </div>
</div>

<div class="field has-error">
  <label class="field-etykieta" for="tresc">Napisz kilka słów</label>
  <textarea class="field-input" id="tresc" name="tresc"
            aria-invalid="true" aria-describedby="tresc-blad"></textarea>
  <span class="field-error" id="tresc-blad">
    <svg class="alert-ikona" aria-hidden="true"><use href="#ikona-ostrzezenie"></use></svg>
    Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego opublikować.
  </span>
</div>
```

**Dostępność.**

- `<label for>` powiązana z `id` pola — zawsze, także przy polu szukania
  w belce (tam etykieta jest w `.tylko-dla-czytnika`, bo obok stoi przycisk
  z napisem „Szukaj”).
- Podpowiedź i błąd wchodzą do `aria-describedby`; przy błędzie dodatkowo
  `aria-invalid="true"`.
- Etykieta stoi **nad** polem, podpowiedź też. Człowiek czyta ją, zanim zacznie
  pisać, a nie po tym, jak skończy.
- Oznaczamy **wymagane**, nie „(nieobowiązkowe)” (D-106). Nad formularzem stoi
  jedno zdanie: „Wymagane są trzy pola — resztę wypełnij, jeśli chcesz.”
- Nigdzie nie ma gwiazdki: gwiazdka jest umową, której nasz odbiorca nie
  podpisywał, a czytnik czyta ją jako „gwiazdka”.
- Poprawne dane po błędzie walidacji **nie znikają**.
- `.field-error` ma `overflow-wrap: anywhere` (sekcja 16.1 arkusza). To nie
  jest kosmetyka: komunikat „Najprościej wpisać trzy słowa, na przykład:
  zielonapietruszkarano.” pochodzi wprost z `COPY_STYLE.md` i bez tego
  rozpychał kolumnę przy 320 px i skali 150%. `break-word` z warstwy `base`
  łamie wiersz, ale **nie zmniejsza minimalnej szerokości** — robi to dopiero
  `anywhere`.

**Czego NIE robić.**

- **Nie zastępuj etykiety podpowiedzią w środku pola.** Podpowiedź znika po
  pierwszej literze i człowiek zostaje z pustym prostokątem — reguła „nigdy”
  nr 8.
- **Nie sklejaj etykiety z opisem.** Brak `display: block` daje „WszyscyTakże
  osoby bez konta” — to problem nr 8 z paczki właściciela i nie jest to
  literówka, tylko brak jednej deklaracji.
- **Nie dawaj wszystkim polom tej samej szerokości.** Prostokąt jest obietnicą
  długości odpowiedzi.
- **Nie oznaczaj gwiazdką ani kolorem samym.** Kolor nigdy nie jest jedynym
  nośnikiem informacji.
- **Nie ustawiaj `height` na polu.** Przy 140% skali tekst zostanie ucięty.

---

## 3. Grupa wyboru „Kto to widzi”

**Do czego jest.** Ustawia widoczność wpisu albo przepisu jednym spojrzeniem —
wszystkie trzy możliwości są widoczne naraz, więc nie trzeba niczego rozwijać,
żeby dowiedzieć się, co się wybiera.

**Gdzie występuje.** 12 dodaj zdjęcie, 13 dodaj przepis.

**Warianty.** Jeden. Trzy karty: **Wszyscy**, **Tylko obserwujący**, **Tylko
ja**. Liczba kolumn bierze się z **miejsca, które komponent naprawdę ma**
(`repeat(auto-fit, minmax(min(13rem, 100%), 1fr))`), a nie z szerokości okna —
to decyzja **D-112**. Wcześniej próg liczony od okna wciskał trzy karty
w kolumnę, w której mieści się jedna.

**Stany.**

- **spoczynek** — karta na tle wypukłym, dwupikselowa obwódka, kółko 24 px;
- **najechanie** — tło wgłębione;
- **wybrany** — obwódka i kółko w kolorze marki, tło `--color-brand-tint`, opis
  w `--color-brand-tint-ink` (6.67:1). Wybór widać po **trzech** rzeczach
  naraz: kółku, obwódce i tle;
- **fokus klawiatury** — obwódka obejmuje **całą kartę**, nie samo kółko
  (`.choice:has(input:focus-visible)`);
- **wyłączony, błąd** — grupa ich nie ma: zawsze jedna z trzech możliwości jest
  zaznaczona, więc nie da się jej zostawić pustej.

**Klasy.** `.choice-grid` > `.choice` > `.choice-naglowek` (kółko +
`.choice-label`) + `.choice-help`. Opakowaniem jest `fieldset.field`, który
w sekcji 16.1 arkusza dostał `min-width: 0` i zdjętą systemową ramkę.

```html
<fieldset class="field">
  <legend class="field-etykieta">Kto to widzi</legend>
  <div class="choice-grid">
    <label class="choice" for="widz-wszyscy">
      <span class="choice-naglowek">
        <input type="radio" name="widocznosc" id="widz-wszyscy" value="wszyscy" checked>
        <span class="choice-label">Wszyscy</span>
      </span>
      <span class="choice-help">Także osoby bez konta.</span>
    </label>
    <!-- „Tylko obserwujący” / „Osoby, które Cię obserwują.” -->
    <!-- „Tylko ja” / „Twój prywatny zeszyt.” -->
  </div>
</fieldset>
```

**Dostępność.**

- `<fieldset>` z `<legend>` — czytnik przeczyta „Kto to widzi, grupa”, a potem
  każdą możliwość z jej opisem.
- Wszystkie trzy `input` mają ten sam `name`, więc strzałkami przechodzi się
  między nimi, a Tab wychodzi z grupy. To jest natywne zachowanie przycisków
  radiowych i nie wolno go nadpisywać.
- Opis stoi w `.choice-help`, który jest osobnym blokiem, a nie ciągiem dalszym
  etykiety.
- Kółko ma 24 px, ale klikalny jest cały `<label>`.
- `<fieldset>` ma w arkuszu przeglądarki `min-width: min-content` i **nie
  zmniejsza się poniżej zawartości** — bez `min-width: 0` grupa miała 919 px
  w oknie 768 px. Semantyka `fieldset` + `legend` zostaje, bo dzięki niej
  czytnik ogłasza pytanie przy każdej możliwości; znika tylko systemowa ramka,
  która wygląda jak pudełko, którego nikt nie zaprojektował.

**Czego NIE robić.**

- **Nie zamieniaj tego na listę rozwijaną.** Lista chowa dwie z trzech
  możliwości i wymaga kliknięcia, żeby dowiedzieć się, co się traci. Kit v2
  rozbił się między innymi o to.
- **Nie skracaj opisów do jednego słowa.** „Tylko ja” bez „Twój prywatny
  zeszyt” brzmi jak ukrycie wpisu przed samym sobą.
- **Nie rób czterech kolumn.** Trzy karty w rzędzie to maksimum; przy czterech
  opis się nie mieści i wraca pokusa, żeby go skrócić.

---

## 4. Pole zaznaczenia

**Do czego jest.** Włącza albo wyłącza jedną rzecz. Zawsze niezależną od
pozostałych — inaczej to jest grupa wyboru, nie pole zaznaczenia.

**Gdzie występuje.** 04 rejestracja (regulamin), 13 dodaj przepis (opcje),
14 czytelność.

**Warianty.** Jeden.

**Stany.** spoczynek · najechanie (cały wiersz jest celem, więc kursor zmienia
się nad całym wierszem) · zaznaczony (znak w kolorze marki) · fokus klawiatury
(obwódka na kwadraciku) · wyłączony (60% krycia, obok nawias z powodem).
Stanu błędu nie ma na samym polu — niezaznaczony obowiązkowy regulamin
zgłasza `.field-error` pod grupą.

**Klasy.** `.pole-zaznaczenia`.

```html
<label class="pole-zaznaczenia" for="zgoda">
  <input type="checkbox" id="zgoda" name="zgoda" value="1">
  <span>Rozumiem, że po 30 dniach moje wpisy, przepisy i zdjęcia zostaną usunięte na stałe</span>
</label>
```

**Dostępność.**

- Klikalny jest cały wiersz, bo `<input>` siedzi w `<label>`. Wiersz ma minimum
  44 px wysokości, kwadracik 24 px — palec trafia w wiersz.
- Zaznaczenie ogłasza czytnik samo („zaznaczone / niezaznaczone”); nie dokładaj
  do tego `aria-checked`.
- Tekst etykiety jest pełnym zdaniem, nie hasłem. Przy rzeczy nieodwracalnej
  zdanie mówi wprost, co się stanie.

**Czego NIE robić.**

- **Nie zostawiaj samego kwadracika z etykietą obok, ale poza `<label>`.**
  Wtedy klikalne jest 24 px zamiast całego wiersza.
- **Nie używaj pola zaznaczenia jako przełącznika, który działa natychmiast**
  bez „Zapisz”, jeśli strona musi działać bez skryptu — bez skryptu nic się nie
  zapisze, a człowiek zobaczy zaznaczone pole i uwierzy, że zapisał.

---

## 5. Wybór zdjęcia

**Do czego jest.** Dodaje zdjęcie z telefonu albo z komputera, po polsku.

**Gdzie występuje.** 12 dodaj zdjęcie, 13 dodaj przepis.

**Warianty.** Jeden — duży obszar. Nie ma wariantu „mały”, bo to jest główna
akcja obu tych ekranów.

**Stany.** spoczynek (przerywana obwódka na tle wgłębionym) · najechanie
(obwódka w kolorze marki, tło `--color-brand-tint`) · fokus klawiatury (obwódkę
dostaje **etykieta**, bo pole jest niewidoczne) · błąd (`.field-error` pod
obszarem, z konkretnym powodem odrzucenia pliku). Stanu wyłączonego nie ma.

**Klasy.** `.wybor-zdjecia-input` (schowane pole) + `.wybor-zdjecia` >
`.wybor-zdjecia-ikona`, `.wybor-zdjecia-tytul`, `.wybor-zdjecia-opis`.

```html
<input class="wybor-zdjecia-input" type="file" id="zdjecie" name="zdjecie"
       accept="image/jpeg,image/png,image/webp">
<label class="wybor-zdjecia" for="zdjecie">
  <svg class="wybor-zdjecia-ikona" aria-hidden="true"><use href="#ikona-aparat"></use></svg>
  <span class="wybor-zdjecia-tytul">Dodaj zdjęcie</span>
  <span class="wybor-zdjecia-opis">Na telefonie kliknij tutaj, a potem wybierz „Galeria” albo „Zrób zdjęcie”.</span>
</label>
```

**Dostępność.**

- **Kolejność w kodzie jest wymuszona:** `input` musi stać **bezpośrednio
  przed** etykietą, bo reguła fokusu to `.wybor-zdjecia-input:focus-visible +
  .wybor-zdjecia`. Zamiana kolejności kasuje widoczny fokus.
- Pole jest schowane dla oka, ale zostaje w drzewie — klawiatura i czytnik
  widzą je normalnie. To nie jest `display: none`.
- Kliknięcie etykiety wyzwala pole. To natywne zachowanie HTML: działa bez
  skryptu i bez atrybutu `style=`.
- `accept` ogranicza listę plików, ale **nie zastępuje walidacji po stronie
  serwera** ani komunikatu błędu po polsku.
- Aparat jest wyśrodkowany przez `margin-inline: auto`, a nie przez
  `text-align`: reset daje każdemu `<svg>` `display: block`, więc wyrównanie
  tekstu go nie dotyczy i ikona przyklejała się do lewej krawędzi.

**Czego NIE robić.**

- **Nie pokazuj natywnego przycisku pliku.** Rysuje „Choose File / No file
  chosen” po angielsku i **żaden atrybut tego nie zmienia** (D-107, problem
  nr 7). W serwisie, w którym każde słowo jest po polsku, to jest usterka.
- **Nie ukrywaj pola przez `display: none` ani `visibility: hidden`** — znika
  wtedy także dla klawiatury i czytnika.
- **Nie rób z tego jedynej drogi.** Przeciąganie pliku myszą jest dodatkiem,
  nigdy jedynym sposobem.

---

## 6. Karta wpisu

**Do czego jest.** Pokazuje jedno danie: kto je ugotował, co to jest, jak
wygląda i co można z tym zrobić. To najczęściej powtarzany element serwisu,
więc każdy jego głośny szczegół mnoży się przez piętnaście.

**Gdzie występuje.** Pełna: 02 Świeżo z Kuking, 08 tablica. Zwarta: 05 profil
(archiwum), 06 strona tagu, 09 wyniki szukania, 10 zeszyt.

**Warianty.**

1. **pełna** — główka, tytuł, treść, duże zdjęcie 4:3, stopka z akcjami;
2. **bez zdjęcia** (`.karta-bez-zdjecia`) — ciepłe pole `surface-brand-wash`
   i tekst 22 px. Wygląda na wpis pisany, a nie na zdjęcie, którego nie udało
   się wczytać (D-105);
3. **zwarta** (`.karta-wpisu--zwarta`) — zdjęcie 16:9, tytuł, jedna linia
   metadanych; znika treść i pasek akcji. Maksimum **dwie kolumny**
   (`.siatka-kart`, z `align-items: start`: karta bez zdjęcia jest niższa
   i dociąganie jej do równej wysokości robiło pod nią pustą płaszczyznę).

**Stany.** spoczynek · najechanie (unosi się sam cień, nic nie skacze) · fokus
klawiatury — **na elemencie w karcie, nie na karcie**: fokus dostaje tytuł
albo przycisk w stopce, a halo pierścienia bierze wtedy kolor karty. Karta nie
ma stanów wyłączonego ani błędu.

**Klasy.** `.strumien` (odstępy między kartami) · `.karta-wpisu` ·
`.karta-glowka` > `.avatar`, `.karta-glowka-tekst` > `.karta-autor`,
`.karta-meta`, `.karta-meta-kropka` · `.karta-tytul` · `.karta-tresc` ·
`.karta-zdjecie` albo `.karta-bez-zdjecia` · `.karta-stopka` +
`.karta-stopka-odstep`.

```html
<article class="karta-wpisu">
  <div class="karta-glowka">
    <span class="avatar"><img src="/zdjecia/halina.jpg" alt="Zdjęcie profilowe: Halina"></span>
    <div class="karta-glowka-tekst">
      <a class="karta-autor" href="/@halina">Halina</a>
      <div class="karta-meta">
        <span>wczoraj, 18:40</span>
        <span class="karta-meta-kropka" aria-hidden="true">·</span>
        <span class="badge badge-cichy">konto przykładowe</span>
      </div>
    </div>
  </div>
  <h2 class="karta-tytul"><a href="/dania/123">Pierogi ruskie po babci</a></h2>
  <p class="karta-tresc">Robione w niedzielę, z kapustą i grzybami.</p>
  <img class="karta-zdjecie" src="/zdjecia/123.jpg"
       alt="Talerz pierogów polanych zesmażoną cebulką i posypanych szczypiorkiem">
  <div class="karta-stopka">
    <button type="submit" class="btn btn-primary">Ugotowałem</button>
    <button type="submit" class="btn btn-quiet">Zapisz</button>
    <a class="btn btn-quiet karta-stopka-odstep" href="/dania/123#komentarze">8 komentarzy</a>
  </div>
</article>
```

**Dostępność.**

- `<article>`, bo karta jest samodzielnym kawałkiem treści.
- Tytuł jest nagłówkiem (`<h2>` w strumieniu, `<h3>` w sekcji) — dzięki temu
  czytnik potrafi przeskakiwać między wpisami po nagłówkach.
- **Alt zdjęcia opisuje, co jest na talerzu**, a nie „zdjęcie dania”. Alt jest
  jedyną rzeczą, którą osoba niewidoma dostaje zamiast treści tej karty.
- Kropka rozdzielająca metadane ma `aria-hidden="true"` — czytnik nie ma
  czytać „kropka”.
- Kolejność fokusu: autor → tytuł → akcje w stopce. Zgadza się z kolejnością
  w kodzie, więc nie trzeba `tabindex`.
- Licznik komentarzy jest linkiem z pełnym napisem („8 komentarzy”), nie samą
  liczbą przy ikonie.

**Czego NIE robić.**

- **Nie rób z całej karty jednego linku.** Czytnik przeczyta wtedy całą kartę
  jako jedną nazwę odnośnika, a w środku i tak są przyciski, których nie da się
  zagnieździć w `<a>`.
- **Nie powtarzaj głośnej plakietki w każdej karcie.** „Konto przykładowe”
  w kolorze marki, piętnaście razy pod rząd, było najgłośniejszym elementem
  strony (problem nr 2). Tu jest cicha, przy dacie (D-103).
- **Nie zmniejszaj zdjęcia do miniatury** i nie rób więcej niż dwóch kolumn.
  Zdjęcie jest dowodem, że przepis wyszedł u zwykłego człowieka; miniatura
  odbiera mu tę funkcję.
- **Nie dawaj karcie stanu „wyłączona”.** Wpis albo jest widoczny, albo go nie
  ma.
- **Nie nakładaj tekstu na zdjęcie karty** bez podkładu — do tego jest osobny
  komponent (§24).

---

## 7. Karta przepisu

**Do czego jest.** To karta wpisu z jedną rzeczą więcej: paskiem danych, który
odpowiada na trzy pytania zadawane **przed** gotowaniem — ile to trwa, na ile
osób i czy dam radę.

**Gdzie występuje.** 02, 06, 07 (przepisy powiązane), 08, 09, 10.

**Warianty.** Pełna i zwarta — tak samo jak karta wpisu. W wariancie zwartym
pasek danych zostaje: to jest właśnie ta informacja, dla której ktoś przegląda
listę.

**Stany.** Jak karta wpisu. Dodatkowo przycisk „Zapisz” ma stan
**naciśnięty/nie** (`aria-pressed`), bo przepis jest w Zeszycie albo go nie ma.

**Klasy.** Wszystkie klasy karty wpisu plus `.karta-przepisu-dane` >
`.dana-przepisu` > `.dana-przepisu-ikona`.

```html
<article class="karta-wpisu">
  <img class="karta-zdjecie" src="/zdjecia/456.jpg"
       alt="Pizza z mozzarellą, pomidorkami i świeżą bazylią na drewnianej desce">
  <h2 class="karta-tytul"><a href="/przepisy/456">Pizza z pieca w ogrodzie</a></h2>
  <div class="karta-przepisu-dane">
    <span class="dana-przepisu"><svg class="dana-przepisu-ikona" aria-hidden="true"><use href="#ikona-zegar"></use></svg>90 minut</span>
    <span class="dana-przepisu"><svg class="dana-przepisu-ikona" aria-hidden="true"><use href="#ikona-porcje"></use></svg>6 porcji</span>
    <span class="dana-przepisu"><svg class="dana-przepisu-ikona" aria-hidden="true"><use href="#ikona-czapka"></use></svg>Średnie</span>
  </div>
  <div class="karta-stopka">
    <button type="submit" class="btn btn-primary" aria-pressed="false">Zapisz</button>
  </div>
</article>
```

**Dostępność.**

- Każda dana ma **ikonę i podpis**: „90 minut”, nie zegarek z liczbą 90.
  Jednostki pełnym słowem, liczby cyframi.
- `aria-pressed` na „Zapisz” — i tekst też się zmienia („Zapisz” →
  „Zapisano”), bo sam stan ARIA nie jest widoczny dla oka.
- Trzy dane to maksimum. Czwarta zaczyna wyglądać jak tabela.

**Czego NIE robić.**

- **Nie zamieniaj poziomu trudności na gwiazdki ani kropki.** „Średnie” jest
  słowem, które każdy rozumie tak samo; trzy z pięciu gwiazdek nie.
- **Nie chowaj czasu i porcji do środka przepisu.** To jest informacja, na
  podstawie której ktoś decyduje, czy w ogóle wchodzić.

---

## 8. Karta „Ugotowałem”

**Do czego jest.** Pokazuje, że przepis wyszedł u kogoś w domu. To jest
najmilsza część dla autora przepisu i jedyny dowód, że przepis w ogóle działa.

**Gdzie występuje.** 07 przepis (sekcja „Komu wyszło”), 05 profil (co ta osoba
ugotowała).

**Warianty.**

1. **ze zdjęciem** — z uwagą „Jak wyszło?”;
2. **bez zdjęcia** — samo kliknięcie „Ugotowałem” plus zdanie. Jest
   pełnoprawne: „Nie musisz wypełniać żadnego pola — wystarczy, że klikniesz
   »Wyślij«.”

**Stany.** Jak karta wpisu — spoczynek i najechanie. Nie ma stanów fokusu na
samej karcie, wyłączenia ani błędu.

**Klasy.** Te same co karta wpisu; wiersz metadanych niesie odnośnik do
przepisu. Osobnej klasy nie ma i na razie nie potrzebuje — powód w „Drobiazgi,
które zostają do decyzji” na końcu tego pliku.

```html
<article class="karta-wpisu">
  <div class="karta-glowka">
    <span class="avatar"><img src="/zdjecia/basia.jpg" alt="Zdjęcie profilowe: Basia"></span>
    <div class="karta-glowka-tekst">
      <a class="karta-autor" href="/@basia">Basia</a>
      <div class="karta-meta">
        <span>ugotowała z przepisu <a href="/przepisy/456">Pizza z pieca w ogrodzie</a></span>
        <span class="karta-meta-kropka" aria-hidden="true">·</span>
        <span>3 dni temu</span>
      </div>
    </div>
  </div>
  <p class="karta-tresc">Wyszło. I to się liczy. Dałam mniej sera, bo tyle miałam.</p>
  <img class="karta-zdjecie" src="/zdjecia/789.jpg" alt="Pizza upieczona w domowym piekarniku, na blasze">
</article>
```

**Dostępność.**

- Zdanie „ugotowała z przepisu X” jest **tekstem**, nie ikoną garnka. Czytnik
  przeczyta pełne zdanie, a nie „garnek, odnośnik”.
- Odnośnik do przepisu ma nazwę przepisu jako treść, nie „tutaj”.

**Czego NIE robić.**

- **Nie dawaj tu głośnej plakietki „Ugotowałem”.** Cała sekcja nazywa się
  „Komu wyszło” — plakietka powtórzona w każdej karcie przestaje cokolwiek
  znaczyć (D-103). Głośna wolno raz na ekran, i jest nią licznik przy
  przepisie.
- **Nie rób z tego oceny.** Bez gwiazdek, bez „wyszło / nie wyszło”, bez
  procentów. To nie jest recenzja, tylko dowód.
- **Nie zmniejszaj zdjęcia do miniatury** — reguła „nigdy ściana miniaturek”
  dotyczy tego miejsca najmocniej.

---

## 9. Plakietka

**Do czego jest.** Dokłada do elementu jedno słowo o jego stanie albo
pochodzeniu.

**Gdzie występuje.** 02, 05, 07, 08, 09, 10, 11, 13.

**Warianty (trzy wagi).**

| Klasa | Wygląd | Kiedy |
|---|---|---|
| `.badge-cichy` | bez tła i ramki, 15 px, atrament stonowany | to, co powtarza się w każdym elemencie listy — „konto przykładowe” |
| `.badge-spokojny` | tło wgłębione | domyślna: „Szkic”, „Tylko ja”, „nowe” |
| `.badge-cooked` / `.badge-sukces` / `.badge-blad` | tło akcentu, sukcesu, błędu | **raz na ekran** |

> **Reguła, która rządzi tym komponentem (D-103):** plakietkę głośną wolno użyć
> raz na ekran. Jeśli coś powtarza się w każdym elemencie listy, to z definicji
> jest ciche.

**Stany.** Plakietka nie ma stanów. Nie da się jej nacisnąć, sfokusować ani
wyłączyć. Jeśli musi być klikalna — to jest chip (§10), nie plakietka.

**Klasy.** `.badge` + waga.

```html
<span class="badge badge-cichy">konto przykładowe</span>
<span class="badge badge-spokojny">Szkic</span>
<span class="badge badge-cooked">
  <svg class="dana-przepisu-ikona" aria-hidden="true"><use href="#ikona-garnek"></use></svg>
  Ugotowano 12 razy
</span>
```

**Dostępność.**

- Plakietka jest zwykłym tekstem — czytnik przeczyta ją w kolejności. Nie
  dokładaj `role="status"`: to nie jest komunikat, który się pojawia.
- Treść plakietki musi być zrozumiała bez koloru. „Do poprawy” na czerwonym tle
  ma znaczyć to samo w druku czarno-białym.
- `--text-meta` (15 px) jest jedynym rozmiarem poniżej 16 px w systemie
  i **wolno go użyć wyłącznie w plakietce cichej**.

**Czego NIE robić.**

- **Nie rób plakietki klikalnej.** Ma 15–16 px i nie ma 48 px celu dotyku.
- **Nie stawiaj dwóch głośnych plakietek obok siebie.** Druga kasuje pierwszą.
- **Nie zastępuj plakietką etykiety.** „Tylko ja” przy wpisie mówi o stanie;
  wybór widoczności robi się w grupie wyboru, nie klikając w plakietkę.

---

## 10. Chip zakresu

**Do czego jest.** Zawęża listę wyników do jednego rodzaju rzeczy.

**Gdzie występuje.** 06 strona tagu, 09 szukaj.

**Warianty.** Jeden — chip jest zawsze odnośnikiem albo przyciskiem.

**Stany.** spoczynek · najechanie (tło wgłębione) · **bieżący**
(`aria-current="true"` albo `aria-pressed="true"`: tło `brand-tint`, obwódka
w kolorze marki, napis grubszy — 6.67:1) · fokus klawiatury (obwódka
`--color-focus`). Nie ma stanów wyłączonego ani błędu; zakres bez wyników
pokazuje pusty stan, a nie wyłączony chip.

**Klasy.** `.chipsy` > `.chip`.

```html
<nav class="chipsy" aria-label="Zakres wyników">
  <a class="chip" href="?zakres=wszystko" aria-current="true">Wszystko</a>
  <a class="chip" href="?zakres=przepisy">Przepisy</a>
  <a class="chip" href="?zakres=dania">Dania</a>
  <a class="chip" href="?zakres=osoby">Osoby</a>
</nav>
```

**Dostępność.**

- Chip jest **przyciskiem, więc ma 48 px wysokości**, nie 32. Rząd chipów to
  rząd celów dotyku, a nie ozdoba nad wynikami.
- Bieżący zakres poznaje się po `aria-current` **i po grubości napisu** — kolor
  nie jest jedynym nośnikiem.
- Rząd chipów jest `<nav>` z etykietą, żeby czytnik nazwał go, zanim zacznie
  wyliczać.
- Chipy **zawijają się** do drugiego wiersza. Nigdy nie przewijają się
  w poziomie: przewijana lista chowa część możliwości i wymaga gestu.

**Czego NIE robić.**

- **Nie rób z chipów paska przewijanego w bok.** Jedyne miejsce z przewijaniem
  poziomym w tym serwisie to karuzela zdjęć i to jest świadomy wyjątek.
- **Nie dawaj więcej niż pięciu chipów.** Szósty znaczy, że potrzebna jest
  inna nawigacja.
- **Nie rób chipa samą ikoną.**

---

## 11. Awatar

**Do czego jest.** Pokazuje, czyja jest ta karta, ten komentarz, to
powiadomienie.

**Gdzie występuje.** 02, 05, 06, 07, 08, 09, 10, 11 oraz w belce górnej na
każdym ekranie zalogowanego.

**Warianty.** `.avatar-sm` 40 px (wiersz, komentarz) · `.avatar` 48 px (karta) ·
`.avatar-lg` 80 px (główka profilu). Bez zdjęcia — pierwsza litera imienia na
tle `brand-tint`.

**Stany.** Awatar nie ma stanów. Kiedy jest odnośnikiem, stany należą do
odnośnika, nie do niego.

**Klasy.** `.avatar` + rozmiar.

```html
<span class="avatar"><img src="/zdjecia/halina.jpg" alt="Zdjęcie profilowe: Halina"></span>

<!-- bez zdjęcia: litera jest ozdobą, imię stoi obok w tekście -->
<span class="avatar" aria-hidden="true">H</span>
```

**Dostępność.**

- Kiedy imię stoi **obok** awatara (a stoi prawie zawsze), zdjęcie nie musi go
  powtarzać: `alt="Zdjęcie profilowe: Halina"` jest w porządku, `alt=""` też.
  Czego nie wolno: `alt="awatar"` ani braku `alt`.
- Wariant z literą ma `aria-hidden="true"` — pojedyncza litera przeczytana
  przez czytnik jest szumem.
- Awatar sam nie bywa jedynym odnośnikiem do profilu. Obok zawsze stoi imię
  i ono też jest odnośnikiem.

**Czego NIE robić.**

- **Nie wstawiaj sylwetki z ikony zamiast litery.** Wygląda jak zdjęcie, które
  się nie wczytało.
- **Nie zmniejszaj poniżej 40 px.** Twarz w kółku 24 px jest plamą.

---

## 12. Komunikat

**Do czego jest.** Mówi, że coś się udało, nie udało albo że warto coś
wiedzieć, zanim się kliknie dalej.

**Gdzie występuje.** 03, 04, 07, 12, 13, 14.

**Warianty.** `.alert-sukces` · `.alert-blad` · `.alert-info`.

**Stany.** Komunikat nie ma stanów. Pojawia się i jest.

**Klasy.** `.alert` + odmiana, `.alert-ikona`.

```html
<div class="alert alert-sukces" role="status">
  <svg class="alert-ikona" aria-hidden="true"><use href="#ikona-ptaszek"></use></svg>
  <span>Przepis opublikowany. Teraz ktoś może z niego ugotować.</span>
</div>

<div class="alert alert-blad" role="alert">
  <svg class="alert-ikona" aria-hidden="true"><use href="#ikona-ostrzezenie"></use></svg>
  <span>Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie.
        Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła”.</span>
</div>
```

**Dostępność.**

- **`role="alert"` dla błędu** (przerywa czytnik, bo człowiek musi to usłyszeć
  teraz) i **`role="status"` dla sukcesu oraz informacji** (nie przerywa).
  Pomyłka w tę drugą stronę jest hałasem, w pierwszą — przemilczeniem.
- Komunikat błędu trzyma schemat: **co się stało → dlaczego → co zrobić**.
  Zdanie bez trzeciej części jest niedokończone.
- Ikona ma `aria-hidden="true"`; niesie ją zawsze tekst obok.
- Komunikat stoi **nad** treścią, której dotyczy, a nie pod nią.
- `.alert` ma `overflow-wrap: anywhere` z tego samego powodu co `.field-error`:
  bardzo długie słowo w treści komunikatu nie może rozpychać kolumny.

**Czego NIE robić.**

- **Nie pisz kodu HTTP.** Zawsze zdanie po polsku mówiące, co zrobić.
- **Nie żartuj w błędzie.** Człowiek ma wtedy problem, nie nastrój na żarty —
  i nie ma tu gry słowem „kuKING”.
- **Nie rób komunikatu, który sam znika po dwóch sekundach.** Minimum tyle, ile
  trwa przeczytanie przy 150% skali, albo do zamknięcia ręką.
- **Nie polegaj na samym kolorze tła.** Ikona i słowo muszą wystarczyć.

---

## 13. Podsumowanie błędów

**Do czego jest.** Zbiera na górze formularza wszystko, czego brakuje, i prowadzi
do każdego pola jednym kliknięciem — także wtedy, gdy formularz ma cztery
tysiące pikseli wysokości.

**Gdzie występuje.** 03, 04, 12, 13.

**Warianty.** Dwa nagłówki, zależnie od liczby: **„Jednej rzeczy jeszcze
brakuje”** i **„Kilku rzeczy jeszcze brakuje”**. Liczba mnoga przy jednym
błędzie jest drobnym kłamstwem, które ludzie zauważają.

**Stany.** Komponent nie ma stanów — istnieje albo nie.

**Klasy.** `.error-summary`, `.error-summary-title`.

```html
<div class="error-summary" role="alert" tabindex="-1">
  <h2 class="error-summary-title">Kilku rzeczy jeszcze brakuje</h2>
  <ul>
    <li><a href="#haslo">Hasło musi mieć co najmniej 10 znaków.</a></li>
    <li><a href="#nazwa">Nazwa przepisu jest pusta.</a></li>
  </ul>
</div>
```

**Dostępność.**

- `role="alert"` — czytnik ogłasza podsumowanie od razu po przeładowaniu
  strony.
- `tabindex="-1"` pozwala serwerowi przenieść tu fokus po odesłaniu formularza.
- **Skok do pola działa bez skryptu**: to zwykły odnośnik `#id`. Element
  wskazany adresem dostaje w warstwie `base` tę samą obwódkę co przy fokusie
  (`.field:target`), więc po skoku widać, do którego pola się trafiło.
- Kolejność błędów na liście jest kolejnością pól w formularzu, nie kolejnością
  ważności.
- Tekst pozycji to ten sam tekst, który stoi przy polu. Dwa różne opisy tego
  samego błędu to dwa błędy.

**Czego NIE robić.**

- **Nie pisz „Formularz zawiera błędy”.** To nie mówi ani co, ani gdzie.
- **Nie zostawiaj samego podsumowania bez błędów przy polach** ani odwrotnie.
- **Nie czyść poprawnie wypełnionych pól** przy odesłaniu formularza.

---

## 14. Plakietka autozapisu

**Do czego jest.** Mówi, że szkic jest bezpieczny, żeby nikt nie bał się
zamknąć strony w połowie długiego formularza.

**Gdzie występuje.** 13 dodaj przepis.

**Warianty.** Jeden.

**Stany.** Pojawia się po zapisaniu i zostaje. Nie ma stanów najechania, fokusu
ani wyłączenia — to nie jest kontrolka.

**Klasy.** `.autosave-badge`.

```html
<p class="autosave-badge" aria-live="polite">
  <svg class="alert-ikona" aria-hidden="true"><use href="#ikona-ptaszek"></use></svg>
  Szkic zapisany.
</p>
```

**Dostępność.**

- **`aria-live="polite"`, nigdy `assertive`.** Człowiek właśnie pisze; czytnik
  ma dokończyć zdanie i dopiero potem powiedzieć „Szkic zapisany”.
- Element z `aria-live` musi być w drzewie **zanim** zmieni się jego treść —
  wstawiony razem z tekstem nie zostanie ogłoszony.
- Kropka na końcu jest częścią tekstu i zostaje. Bez wykrzyknika.

**Czego NIE robić.**

- **Nie pisz „Autosave aktywny”.** Nazwa funkcji to „Szkic zapisany.”
- **Nie migaj tym co dziesięć sekund.** Plakietka pojawia się przy zapisie
  i zostaje do następnego.

---

## 15. Pusty stan

**Do czego jest.** Mówi, co się tu pojawi i co zrobić, żeby się pojawiło.

**Gdzie występuje.** 02, 05, 06, 07, 08, 09, 10, 11.

**Warianty.** Jeden układ, różne teksty — wszystkie gotowe w `COPY_STYLE.md`
§6 („Puste stany”). Znak nad tekstem jest znakiem marki albo ikoną pasującą do
miejsca (lupa przy braku wyników).

**Stany.** Komponent nie ma stanów.

**Klasy.** `.empty-state`, `.empty-state-znak`, `.empty-state-title`,
`.empty-state-opis`.

```html
<div class="empty-state">
  <svg class="empty-state-znak" aria-hidden="true"><use href="#ikona-znak"></use></svg>
  <p class="empty-state-title">Zeszyt jest jeszcze pusty</p>
  <p class="empty-state-opis">Kiedy znajdziesz przepis, który chcesz zachować,
     kliknij przy nim „Zapisuję”. Trafi tutaj i zawsze go znajdziesz.</p>
  <a class="btn btn-primary" href="/odkryj">Zobacz przepisy</a>
</div>
```

**Dostępność.**

- Tytuł pustego stanu **nie musi być nagłówkiem strony** — jest `<p>`, żeby nie
  zaśmiecać konspektu nagłówków fałszywym poziomem. Nagłówkiem jest tytuł
  sekcji nad nim.
- Znak jest ozdobą: `aria-hidden="true"`.
- Akcja jest zawsze jedna i zawsze konkretna.

**Czego NIE robić.**

- **Nigdy „Brak danych” ani „Nic tu nie ma” bez ciągu dalszego.** Pusty stan
  bez akcji jest ślepym zaułkiem — reguła z inwentarza komponentów.
- **Nie żartuj z tego, że ktoś jeszcze nic nie dodał.** „Twoje archiwum jest
  jeszcze puste” z wyjaśnieniem, po co archiwum, działa; żart o pustce nie.
- **Nie rysuj dużej ilustracji.** Znak 64 px wystarczy; obrazek na pół ekranu
  spycha akcję poniżej krawędzi.

---

## 16. Kroki kreatora

**Do czego jest.** Mówi, na którym z trzech kroków formularza przepisu stoi
człowiek i ile zostało.

**Gdzie występuje.** 13 dodaj przepis.

**Warianty.** Trzy kroki, trzy adresy (D-108): `/dodaj/przepis`,
`/dodaj/przepis/skladniki`, `/dodaj/przepis/kroki`. Każdy krok kończy się
`POST`-em, który zapisuje szkic i przenosi dalej.

**Stany.** Kropka ma dwa: zrobiona (`data-done="true"`, kolor marki)
i niezrobiona (kolor obwódki). Cały komponent nie ma stanów najechania ani
fokusu — to jest opis, nie kontrolka.

**Klasy.** `.wizard-steps`, `.wizard-steps-current`, `.wizard-steps-nazwa`,
`.wizard-steps-track`, `.wizard-steps-dot`.

```html
<div class="wizard-steps">
  <p class="wizard-steps-current">Krok 2 z 3 <span class="wizard-steps-nazwa">— składniki</span></p>
  <div class="wizard-steps-track" role="presentation">
    <span class="wizard-steps-dot" data-done="true"></span>
    <span class="wizard-steps-dot" data-done="true"></span>
    <span class="wizard-steps-dot"></span>
  </div>
</div>
```

**Dostępność.**

- **Postęp jest napisany słowami**: „Krok 2 z 3 — składniki”. Kropki są
  dodatkiem i mają `role="presentation"`, bo nie niosą nic ponad to zdanie.
- Nagłówek strony mówi to samo co krok. Jeśli nagłówek mówi „Krok 1 z 3”,
  a strona pokazuje wszystko naraz, to nagłówek kłamie (problem nr 10).
- „Wstecz” jest zwykłym linkiem — działa przycisk „wstecz” przeglądarki
  i otwieranie w nowej karcie.

**Czego NIE robić.**

- **Nie zostawiaj samych kropek.** Trzy identyczne kreski nie mówią nic komuś,
  kto nie zna tej konwencji.
- **Nie rób kropek klikalnymi.** Skok do kroku 3 z pominięciem 2 zostawia szkic
  w stanie, którego serwer nie umie zapisać.
- **Nie pokazuj wszystkich trzech kroków na jednej stronie z nagłówkiem
  „Krok 1 z 3”.** Albo trzy strony, albo jedna z uczciwym nagłówkiem — wersja
  jednostronicowa żyje pod `/dodaj/przepis/wszystko`.

---

## 17. Belka górna

**Do czego jest.** Trzyma znak serwisu, wejście do szukania i dwie akcje konta
w tym samym miejscu na każdej podstronie.

**Gdzie występuje.** Na wszystkich piętnastu ekranach.

**Warianty.** Gość (znak + „Zaloguj się” + „Załóż konto”) i zalogowany
(znak + szukanie + „Powiadomienia” + „Konto”).

**Stany.** Belka nie ma stanów; mają je elementy w środku (pole, przyciski).

**Klasy.** `.topbar` > `.topbar-wnetrze` > `.wordmark` (+ `.wordmark-znak`,
`.wordmark-koncowka`), `.topbar-szukaj` > `.topbar-szukaj-kolumna`,
`.topbar-akcje`.

```html
<header class="topbar">
  <div class="topbar-wnetrze">
    <a class="wordmark" href="/">
      <svg class="wordmark-znak" aria-hidden="true"><use href="#ikona-znak"></use></svg>
      <span>Ku<span class="wordmark-koncowka">King</span>.pl</span>
    </a>
    <form class="topbar-szukaj" role="search" action="/szukaj">
      <span class="topbar-szukaj-kolumna">
        <label class="tylko-dla-czytnika" for="q">Szukaj przepisu albo osoby</label>
        <input class="field-input" type="search" id="q" name="q" placeholder="Szukaj przepisu albo osoby">
      </span>
      <button type="submit" class="btn btn-secondary">
        <svg class="side-nav-ikona" aria-hidden="true"><use href="#ikona-lupa"></use></svg>
        Szukaj
      </button>
    </form>
    <div class="topbar-akcje">
      <a class="btn btn-quiet" href="/powiadomienia">
        <svg class="side-nav-ikona" aria-hidden="true"><use href="#ikona-dzwonek"></use></svg>
        Powiadomienia
      </a>
      <a class="btn btn-quiet" href="/@basia">
        <span class="avatar avatar-sm"><img src="/zdjecia/basia.jpg" alt=""></span>
        Konto
      </a>
    </div>
  </div>
</header>
```

**Dostępność.**

- Zapis nazwy musi być **jednym elementem w siatce**: `.wordmark` ma
  `gap: var(--spacing-2)` między swoimi dziećmi, więc `Ku<span>King</span>.pl`
  rozjeżdża się na „Ku King .pl”. Cały napis idzie w jeden `<span>`, a odstęp
  zostaje tam, gdzie ma być — między znakiem a napisem.
- Awatar w akcji „Konto” ma `alt=""`, bo napis „Konto” stoi obok.
- Pole szukania ma prawdziwą `<label>` schowaną dla oka, nie sam `placeholder`.
- Belka jest w tej samej siatce co treść pod spodem: pole nigdy nie jest
  szersze od kolumny, którą opisuje (problem nr 6).
- Pole siedzi w `.topbar-szukaj-kolumna`, bo `.topbar-szukaj` jest rzędem
  i sam ułożyłby etykietę **obok** pola. Kolumna daje polu `flex: 1` oraz
  `min-width: 0` i trzyma etykietę nad polem, gdyby miała być widoczna.
- **Poniżej 1024 px pole szukania znika w całości** (sekcja 16.1 arkusza):
  znak, pole i dwie akcje z napisami mają razem około 600 px. Na telefonie
  szukanie jest pozycją dolnego paska. `.topbar-wnetrze` i `.topbar-akcje`
  zawijają się, więc akcje schodzą do drugiego wiersza, zamiast wystawać.
- Pierwszym elementem strony przed belką jest odnośnik „Przejdź do treści”
  (`.tylko-dla-czytnika`, wraca na ekran przy fokusie).

**Czego NIE robić.**

- **Nie przywracaj pola szukania na telefonie.** Znak, pole i dwie akcje mają
  razem około 600 px — przy 320 px to jest przewijanie strony w poziomie.
  Arkusz ukrywa je poniżej 1024 px i to jest odpowiedź, a nie obejście.
- **Nie zdejmuj napisów z akcji, żeby się zmieściły.** Ikona bez podpisu jest
  zakazana; jeśli coś się nie mieści, ma zniknąć całe, a nie stracić napis.
- **Nie licz belce szerokości osobno od siatki treści.** Wtedy strona ma dwie
  różne siatki na jednym ekranie.

---

## 18. Nawigacja boczna

**Do czego jest.** Stała lista sześciu miejsc serwisu na ekranie od 1024 px.

**Gdzie występuje.** 08, 09, 10, 11, 12, 13, 14 (u zalogowanego).

**Warianty.** Jeden. Sześć pozycji: Start, Szukaj, Dodaj, Zeszyt,
Powiadomienia, Moje.

**Stany.** spoczynek · najechanie (tło wgłębione) · **bieżąca**
(`aria-current="page"`: tło `brand-tint`, atrament `brand-tint-ink`, waga 800) ·
fokus klawiatury (obwódka).

**Klasy.** `.side-nav` > `.side-nav-lista` > `.side-nav-item` +
`.side-nav-ikona`.

```html
<nav class="side-nav" aria-label="Nawigacja główna">
  <ul class="side-nav-lista">
    <li><a class="side-nav-item" href="/home" aria-current="page">
      <svg class="side-nav-ikona" aria-hidden="true"><use href="#ikona-dom"></use></svg>Start</a></li>
    <li><a class="side-nav-item" href="/szukaj">
      <svg class="side-nav-ikona" aria-hidden="true"><use href="#ikona-lupa"></use></svg>Szukaj</a></li>
    <!-- Dodaj · Zeszyt · Powiadomienia · Moje -->
  </ul>
</nav>
```

**Dostępność.**

- `<nav aria-label="Nawigacja główna">` — strona ma kilka nawigacji (główną,
  dolną, chipy, stopkę) i każda musi mieć własną nazwę.
- Lista jest `<ul>`, więc czytnik powie „lista, sześć pozycji”.
- Bieżąca pozycja ma `aria-current="page"` **i grubszy napis** — kolor nie jest
  jedynym nośnikiem.
- Każda pozycja ma 48 px wysokości i podpis obok ikony.
- Nawigacja jest przyklejona (`sticky`) pod belką, ale nie przykrywa treści.

**Czego NIE robić.**

- **Nie zwijaj jej do samych ikon** na węższym ekranie. Poniżej 1024 px znika
  cała i zastępuje ją dolny pasek.
- **Nie zmieniaj kolejności pozycji między podstronami.** Nawigacja ma być
  przewidywalna, nie dowcipna.
- **Nie dokładaj siódmej pozycji** bez usunięcia innej.

---

## 19. Dolny pasek nawigacji

**Do czego jest.** To samo co nawigacja boczna, na telefonie: pięć miejsc
w zasięgu kciuka.

**Gdzie występuje.** 08–14 poniżej 1024 px.

**Warianty.** Jeden. **Dokładnie pięć pozycji**, zawsze te same: Start, Szukaj,
Dodaj, Zeszyt, Moje. „Dodaj” jest jedyną pozycją z kolorem marki, bo to główna
akcja produktu.

**Stany.** spoczynek · **bieżąca** (`aria-current="page"`: kolor marki
i waga 800) · fokus klawiatury.

**Klasy.** `.bottom-nav` > `.bottom-nav-item` (+ `.bottom-nav-item-glowna`,
`.bottom-nav-kolko`) + `.bottom-nav-ikona`.

```html
<nav class="bottom-nav" aria-label="Nawigacja dolna">
  <a class="bottom-nav-item" href="/home" aria-current="page">
    <svg class="bottom-nav-ikona" aria-hidden="true"><use href="#ikona-dom"></use></svg>Start</a>
  <a class="bottom-nav-item" href="/szukaj">
    <svg class="bottom-nav-ikona" aria-hidden="true"><use href="#ikona-lupa"></use></svg>Szukaj</a>
  <a class="bottom-nav-item bottom-nav-item-glowna" href="/dodaj">
    <span class="bottom-nav-kolko"><svg class="bottom-nav-ikona" aria-hidden="true"><use href="#ikona-plus"></use></svg></span>
    Dodaj</a>
  <!-- Zeszyt · Moje -->
</nav>
```

**Dostępność.**

- **Kółko „Dodaj” ma podpis.** To jest ta sama reguła co przy dzwonku:
  ikona nie jest opisem akcji.
- `padding-bottom: env(safe-area-inset-bottom)` — pasek nie chowa się pod
  paskiem gestów telefonu.
- `<body>` ma zapas na dole (`.app-body`), żeby ostatni wpis nie chował się pod
  paskiem.
- Pasek ma 60 px wysokości: pozycja dotykowa jest większa niż zwykły cel 48 px,
  bo klika się ją w ruchu.
- Bieżąca pozycja ma trzypikselową kreskę u góry (`box-shadow: inset`) **oraz**
  kolor i grubszy napis — trzy sygnały, z których każdy działa osobno.
- Zmniejszenie podpisu, żeby zrobić miejsce, nie wchodzi w grę: poniżej 16 px
  schodzi w tym systemie wyłącznie plakietka cicha.

**Czego NIE robić.**

- **Nie dokładaj szóstej pozycji.** Pięć podpisów przy 320 px i skali 140% ma
  razem około 346 px, czyli więcej niż okno; `min-width: 0`,
  `overflow-wrap: anywhere` i `hyphens: auto` (sekcja 16.1 arkusza) łamią
  podpis z dywizem, zamiast rozpychać stronę. Szósta pozycja zjadłaby ten
  zapas do zera.
- **Nie zamieniaj podpisów na same ikony**, żeby zrobić miejsce.
- **Nie ukrywaj paska przy przewijaniu.** Znikająca nawigacja jest u tej grupy
  odbiorców odbierana jako awaria.

---

## 20. Moduł szyny

**Do czego jest.** Wypełnia trzecią kolumnę rzeczami, których w kolumnie
głównej nie ma. Najczęściej jest to „kuKINGi na dziś”: kilka osób i kilka dań
wartych zobaczenia.

**Gdzie występuje.** 08 tablica, 09 szukaj.

**Warianty.** Z zawartością i pusty („Dziś jeszcze nikogo nie wybraliśmy.
Zajrzyj do »Świeżo z Kuking«.”).

**Stany.** Moduł nie ma stanów. Przycisk „Obserwuj / Obserwujesz” w środku ma
`aria-pressed` i zmienia napis.

**Klasy.** `.szyna-modul` > `.szyna-tytul`, `.szyna-podtytul`, `.szyna-osoba` >
(`.avatar-sm`, `.szyna-osoba-tekst` > `.szyna-osoba-imie`, `.szyna-osoba-opis`),
`.szyna-stopka`. Wiersz z daniem: `.szyna-danie` > `.szyna-danie-zdjecie`,
`.szyna-danie-tytul`. Kontener kolumny: `.app-rail`. Para
`.tylko-szerokie` / `.tylko-waskie` przełącza, która kopia treści jest widoczna.

```html
<section class="szyna-modul" aria-labelledby="szyna-tytul">
  <h2 class="szyna-tytul" id="szyna-tytul">kuKINGi na dziś</h2>
  <p class="szyna-podtytul">Kilka osób i kilka dań, które dziś warto zobaczyć.</p>
  <div class="szyna-osoba">
    <span class="avatar avatar-sm"><img src="/zdjecia/halina.jpg" alt="Zdjęcie profilowe: Halina"></span>
    <div class="szyna-osoba-tekst">
      <span class="szyna-osoba-imie">Halina</span>
      <span class="szyna-osoba-opis">Zupy i pierogi, Podkarpacie</span>
    </div>
    <button type="submit" class="btn btn-secondary" aria-pressed="false">Obserwuj</button>
  </div>
  <p class="szyna-stopka">Jutro będzie tu ktoś inny.</p>
</section>
```

**Dostępność.**

- `<section aria-labelledby>` — czytnik nazwie sekcję, zanim wejdzie do środka.
- „Obserwujesz” zmienia **napis**, nie tylko `aria-pressed` i kolor.
- Zdanie „Jutro będzie tu ktoś inny.” jest treścią, nie ozdobą: mówi wprost, że
  to nie jest tabela wyników i nie ma tu do czego awansować.
- `.szyna-osoba` zawija się, a `.szyna-osoba-tekst` ma bazę `--spacing-24`:
  przy zwykłej skali przycisk stoi obok imienia, a przy powiększonym tekście
  schodzi niżej — zamiast łamać imię w środku wyrazu. Przycisk „Obserwujesz”
  ma przy skali 150% 211 px i bez tego wiersz nie mieścił się w szynie.
- Zdjęcie w `.szyna-danie` jest małe **celowo**: szyna wskazuje, gdzie
  zajrzeć, a nie pokazuje dania — od tego jest strumień. To nie jest wyjątek od
  zakazu ściany miniaturek, bo to nie jest ściana i nie zastępuje karty.
- `.tylko-szerokie` i `.tylko-waskie` trzymają **dwie kopie tej samej treści**,
  z których w danym momencie widoczna jest dokładnie jedna — czytnik ekranu
  czyta ją raz. Trzecia kolumna znika poniżej 1280 px, więc to, co na szerokim
  ekranie siedzi w szynie, musi mieć swoje miejsce w kolumnie głównej.

**Czego NIE robić.**

- **Nie powtarzaj w szynie treści z kolumny głównej.** Ten sam wpis dwa razy na
  jednym ekranie to problem nr 4 dzisiejszej tablicy.
- **Nie rób z tego rankingu.** Bez „Top”, bez liczników pozycji, bez „najlepsi
  w tym tygodniu” — publiczne rankingi dzielą ludzi na dwie klasy i wyłączają
  publikowanie u większości.
- **Nie wkładaj do szyny niczego, bez czego strona przestaje działać.** Szyny
  nie ma poniżej 1280 px — jeśli treść jest potrzebna, ma drugą kopię
  w `.tylko-waskie`.
- **Nie zostawiaj obu kopii widocznych naraz.** To ten sam tekst dwa razy na
  jednym ekranie, czyli problem nr 4 wejściem kuchennym.

---

## 21. Wiersz powiadomienia

**Do czego jest.** Mówi, że ktoś ugotował z Twojego przepisu, napisał komentarz
albo zaczął Cię obserwować.

**Gdzie występuje.** 11 powiadomienia.

**Warianty.** Przeczytany i nieprzeczytany.

**Stany.** spoczynek · nieprzeczytany (`.wiersz-nieprzeczytany`: tło
`brand-tint` **i** plakietka „nowe”). Stany najechania i fokusu należą do
odnośnika w środku wiersza.

**Klasy.** `.lista-wierszy` > `.wiersz` (+ `.wiersz-nieprzeczytany`) >
`.avatar-sm`, `.wiersz-tresc`, `.wiersz-czas`.

```html
<ul class="lista-wierszy">
  <li class="wiersz wiersz-nieprzeczytany">
    <span class="avatar avatar-sm"><img src="/zdjecia/halina.jpg" alt="Zdjęcie profilowe: Halina"></span>
    <div class="wiersz-tresc">
      <p>Halina ugotowała Twój rosół. <span class="badge badge-spokojny">nowe</span></p>
      <span class="wiersz-czas">2 godziny temu</span>
    </div>
  </li>
</ul>
```

**Dostępność.**

- Nieprzeczytany poznaje się po tle **i po słowie „nowe”** — kolor nie jest
  jedynym nośnikiem informacji.
- Powiadomienie jest pełnym zdaniem z imieniem i rzeczą: „Halina ugotowała Twój
  rosół.”, nie „Nowa aktywność”.
- Czas jest tekstem względnym („2 godziny temu”); pełną datę wolno dołożyć
  w `title` albo w `<time datetime>`.
- Lista jest `<ul>`, więc czytnik poda liczbę pozycji.

**Czego NIE robić.**

- **Nie dawaj powiadomieniom kształtu karty.** To krótkie, jednorodne wiersze —
  karta w tym miejscu udaje treść, której nie ma.
- **Nie oznaczaj nieprzeczytanych samą kropką w kolorze.**
- **Nie wkładaj do wiersza dwóch akcji.** Cały wiersz prowadzi w jedno miejsce.

---

## 22. Tekst na zdjęciu

**Do czego jest.** Kładzie tytuł na zdjęciu tam, gdzie zdjęcie jest większe od
karty: hero strony powitalnej i główka przepisu.

**Gdzie występuje.** 01 powitalna, 07 przepis.

**Warianty.** Jeden.

**Stany.** spoczynek i najechanie (kiedy całość jest odnośnikiem). Fokus
klawiatury rysuje obwódkę wokół całego kadru.

**Klasy.** `.zdjecie-z-napisem` > `img`, `.zdjecie-podklad` >
`.zdjecie-napis`, `.zdjecie-podpis`.

```html
<a class="zdjecie-z-napisem" href="/przepisy/456">
  <img src="/zdjecia/456.jpg" alt="Pizza z mozzarellą, pomidorkami i świeżą bazylią na drewnianej desce">
  <div class="zdjecie-podklad">
    <p class="zdjecie-napis">Pizza z pieca w ogrodzie</p>
    <p class="zdjecie-podpis">Piotr · 90 minut · 6 porcji</p>
  </div>
</a>
```

**Dostępność.**

- **Podkład jest zawsze**, także wtedy, gdy zdjęcie akurat jest ciemne — bo
  następne nie będzie. Biały napis na podkładzie ma 18.37:1 w motywie jasnym
  i 21.00:1 w ciemnym, niezależnie od tego, co jest na talerzu (reguła „nigdy”
  nr 7).
- Podkład jest **pełny u dołu i przechodzi w przezroczystość ku górze**, więc
  działa także na białym talerzu.
- Napis i podpis muszą być elementami blokowymi (`<p>`), bo ich odstępy są
  marginesami. W `<span>` skleją się w jeden wiersz.
- Alt opisuje zdjęcie, a nie powtarza napisu na nim.

**Czego NIE robić.**

- **Nie kładź tekstu na zdjęciu bez podkładu**, nawet jeśli akurat wygląda
  dobrze na tym jednym zdjęciu.
- **Nie rozjaśniaj podkładu, żeby było „ładniej”.** Kontrast jest tu policzony,
  nie dobrany na oko.
- **Nie kładź na zdjęciu przycisków.** Akcja stoi pod kadrem.

---

## 23. Stopka

**Do czego jest.** Trzyma odnośniki prawne, zdanie o serwisie i **jedyne
miejsce, w którym przełącza się motyw**.

**Gdzie występuje.** Na wszystkich piętnastu ekranach.

**Warianty.** Jeden.

**Stany.** Stopka nie ma stanów; przełącznik motywu ma dwa przyciski, z których
jeden jest `aria-pressed="true"`.

**Klasy.** `.stopka` > `.stopka-wnetrze` > `.stopka-linki`.

```html
<footer class="stopka">
  <div class="stopka-wnetrze">
    <p class="stopka-linki">
      <a href="/zasady">Zasady</a>
      <a href="/prywatnosc">Prywatność</a>
      <a href="/regulamin">Regulamin</a>
      <a href="/pomoc">Pomoc</a>
    </p>
    <form class="rzad-przyciskow" action="/ustawienia/motyw" method="post">
      <span class="pomoc">Wygląd strony:</span>
      <button type="submit" class="btn btn-secondary" name="motyw" value="jasny" aria-pressed="true">Jasny</button>
      <button type="submit" class="btn btn-quiet" name="motyw" value="ciemny" aria-pressed="false">Ciemny</button>
    </form>
    <p class="meta">Prowadzimy to na własną rękę.</p>
  </div>
</footer>
```

**Dostępność.**

- Przełącznik motywu jest **formularzem**, nie skryptem. Działa bez
  JavaScriptu, a wybór zapisuje się na koncie.
- Wybrany motyw ma `aria-pressed="true"` **i inną wagę przycisku**.
- Motyw bierze się wyłącznie z wyboru człowieka — reguły `prefers-color-scheme`
  w arkuszu nie ma i nie będzie (D-019). Telefon nie ma prawa przełączać
  wyglądu sam.
- Stopka ma zapas na dole (`--control-height-touch`), żeby nie chowała się pod
  dolnym paskiem.

**Czego NIE robić.**

- **Nie chowaj przełącznika motywu w ustawieniach i nigdzie indziej.** Ma być
  tam, gdzie człowiek go szuka po pierwszym zetknięciu z ciemnym ekranem.
- **Nie rób z przełącznika ikonki słońca i księżyca.** Dwa przyciski z napisami
  „Jasny” i „Ciemny” mówią, co się stanie.
- **Nie wkładaj do stopki nawigacji serwisu.** Od tego są dwie nawigacje wyżej.

---

## 24. Potwierdzenie akcji destrukcyjnej

**Do czego jest.** Daje ostatni moment na zawrócenie przed rzeczą, której nie da
się cofnąć samodzielnie.

**Gdzie występuje.** 05 profil, 07 przepis, 08 tablica (własny wpis),
13 dodaj przepis (porzucenie szkicu).

**Warianty.** Jeden — `<details>`. Pierwsze kliknięcie rozwija pytanie,
prawdziwy przycisk stoi dopiero w środku.

**Stany.** zamknięte · rozwinięte (`open`) · najechanie i fokus klawiatury na
znaczniku rozwijającym (`<summary>` wygląda i zachowuje się jak przycisk).

**Klasy.** `.confirm` na `<details>`, `.confirm-summary` (razem z `.btn`
i wagą) na `<summary>`, `.confirm-body` na pytanie, `.confirm-question` na samo
zdanie, `.rzad-przyciskow` na dwie odpowiedzi. Wszystkie są w sekcji 16.8
arkusza.

```html
<details class="confirm">
  <summary class="confirm-summary btn btn-danger">Usuń wpis</summary>
  <div class="confirm-body">
    <p class="confirm-question">Na pewno usunąć ten wpis? Tej operacji nie da się cofnąć samodzielnie.</p>
    <form class="rzad-przyciskow" action="/dania/123" method="post">
      <input type="hidden" name="_method" value="DELETE">
      <button type="submit" class="btn btn-danger">Tak, usuń</button>
      <a class="btn btn-secondary" href="/dania/123">Zostaw</a>
    </form>
  </div>
</details>
```

**Dostępność.**

- `<details>` zamiast `confirm()` z dwóch powodów naraz: **działa bez skryptu**
  i **jest widoczne**. Systemowe okno potwierdzenia pojawia się w miejscu, na
  które nikt nie patrzy, i u osób 50+ bywa po prostu przeoczone.
- `<summary>` jest natywnie w kolejności fokusu i reaguje na spację oraz Enter.
  Klasa `.btn` ustawia mu `display: inline-flex`, co w Chromium i Firefoksie
  usuwa systemowy trójkącik; `.confirm-summary` dokłada `list-style: none`
  i `::-webkit-details-marker`, żeby nie zależeć od tego skutku ubocznego.
- Pytanie ma czerwoną obwódkę (`.confirm-body`), która odróżnia je od zwykłej
  karty — to nie jest karta, tylko ostatni moment na zawrócenie.
- Pytanie jest pełnym zdaniem mówiącym, co zniknie: przy przepisie także to, że
  **znikną cudze wykonania i komentarze**.
- „Zostaw” jest po lewej albo po prawej, ale zawsze **jest** i nigdy nie jest
  samym „×”.

**Czego NIE robić.**

- **Nie stawiaj przycisku destrukcyjnego bez tego kroku.**
- **Nie używaj `confirm()` ani modalu sterowanego skryptem.** Serwis musi
  działać bez JavaScriptu, a modal na modalu jest zakazany.
- **Nie łagodź.** „Archiwizuj” zamiast „Usuń” jest eufemizmem, po którym ludzie
  są zdziwieni skutkiem.

---

## 25. Wątek komentarzy

**Do czego jest.** Rozmowa pod przepisem albo daniem: pytania o zamienniki,
odpowiedzi autora, uwagi.

**Gdzie występuje.** 07 przepis.

**Warianty.** Komentarz i odpowiedź na komentarz. **Jeden poziom
zagnieżdżenia**, nie więcej.

**Stany.** Wiersz nie ma stanów. Pole odpowiedzi ma stany pola formularza.
Pusty wątek: „Jeszcze nikt tu nic nie napisał. Możesz być pierwsza albo
pierwszy.”

**Klasy.** `.lista-wierszy` > `.wiersz` > `.avatar-sm`, `.wiersz-tresc`
(`.karta-autor`, treść, `.karta-meta`). Odpowiedź poznaje się po zdaniu
„w odpowiedzi do {imię}” w `.meta`, a dla oka dodatkowo po klasie
`.watek-odpowiedzi` (wcięcie plus pionowa kreska, sekcja 16.9 arkusza).

```html
<ul class="lista-wierszy">
  <li class="wiersz">
    <span class="avatar avatar-sm"><img src="/zdjecia/halina.jpg" alt="Zdjęcie profilowe: Halina"></span>
    <div class="wiersz-tresc">
      <a class="karta-autor" href="/@halina">Halina</a>
      <p>U mnie ciasto zawsze się rwie. Ile trzymasz je pod ściereczką?</p>
      <div class="karta-meta">
        <span>wczoraj, 20:10</span>
        <span class="karta-meta-kropka" aria-hidden="true">·</span>
        <a href="/dania/123/odpowiedz/9">Odpowiedz</a>
      </div>
    </div>
  </li>
  <li class="wiersz watek-odpowiedzi">
    <span class="avatar avatar-sm"><img src="/zdjecia/basia.jpg" alt="Zdjęcie profilowe: Basia"></span>
    <div class="wiersz-tresc">
      <span class="meta">w odpowiedzi do Haliny</span>
      <a class="karta-autor" href="/@basia">Basia</a>
      <p>Pół godziny. I nie wałkuję od razu całego, tylko po kawałku.</p>
    </div>
  </li>
</ul>
```

**Dostępność.**

- **Odpowiedź jest oznaczona słowami**, a wcięcie z kreską jest dodatkiem dla
  oka, nie zamiennikiem. Samo wcięcie przy 320 px jest za małe, żeby je
  zauważyć, a przy trzech poziomach zjada połowę szerokości — stąd jeden
  poziom zagnieżdżenia i ani jednego więcej.
- „Odpowiedz” prowadzi pod adres z formularzem — działa bez skryptu.
- Tekst wpisany przez człowieka zachowuje łamania wierszy i łamie bardzo długie
  słowa, żeby nie rozpychał kolumny.
- Lista jest `<ul>`; czytnik poda liczbę komentarzy.

**Czego NIE robić.**

- **Nie zagnieżdżaj głębiej niż o jeden poziom.**
- **Nie sortuj komentarzy po „popularności”.** Kolejność jest chronologiczna.
- **Nie zwijaj wątku pod „pokaż 8 odpowiedzi”** bez adresu, pod który da się
  wejść bez skryptu.

---

## 26. Stronicowanie „Pokaż więcej”

**Do czego jest.** Dokłada następną porcję listy i zostawia człowieka w tym
samym miejscu strony.

**Gdzie występuje.** 02, 05, 06, 07, 08, 09, 10, 11.

**Warianty.** Jeden. Na końcu listy przycisk znika, a zostaje zdanie.

**Stany.** spoczynek · najechanie · fokus klawiatury · **koniec listy**
(przycisku nie ma).

**Klasy.** `.srodek` + `.stos` na układ, `.btn btn-secondary btn-duzy` na
przycisk, `.pomoc` na licznik.

```html
<div class="srodek stos">
  <p class="pomoc" aria-live="polite">Pokazujemy 10 z 48 wpisów.</p>
  <form action="/home" method="get">
    <input type="hidden" name="strona" value="2">
    <button type="submit" class="btn btn-secondary btn-duzy">Pokaż więcej</button>
  </form>
</div>
```

**Dostępność.**

- `aria-live="polite"` na liczniku — po dołożeniu porcji czytnik powie
  „Pokazujemy 20 z 48 wpisów”, nie przerywając czytania.
- **Licznik mówi, ile zostało.** Lista bez widocznego końca jest u tej grupy
  odbiorców powodem, żeby przestać przewijać.
- Bez skryptu jest to zwykły `GET` z numerem strony i kotwicą do miejsca,
  w którym człowiek stanął.

**Czego NIE robić.**

- **Nigdy przewijanie bez końca bez alternatywy** — reguła „nigdy” nr 3.
  Nieskończona lista nie ma stopki, a w stopce są zasady i przełącznik motywu.
- **Nie zamieniaj tego na numerowane strony.** „Pokaż więcej” nie wymaga
  celowania w cyfrę 3.
- **Nie gub pozycji przewijania** po dołożeniu porcji.

---

## 27. Części ekranu przepisu

**Do czego jest.** Pięć rzeczy, które istnieją wyłącznie na ekranie przepisu
i nie składają się z klocków karty.

**Gdzie występuje.** 07 przepis.

**Warianty.** Nie ma. Każda z pięciu części występuje raz na ekran.

**Stany.** Żadna z nich nie ma stanów. `.szyna-danie` i odnośniki w środku mają
stany odnośnika.

**Klasy.**

| Klasa | Co robi |
|---|---|
| `.przepis-zdjecie` | jedyne zdjęcie w serwisie poza kartą: 3:2 zamiast 4:3, bo pod nim stoi panel, a nie tekst wpisu |
| `.wiersz-autora` | wiersz autora pod tytułem; `.karta-glowka` ma wcięcia liczone dla wnętrza karty i poza kartą rozjeżdża się z tytułem |
| `.pochodzenie` + `.pochodzenie-tytul` | „Skąd ten przepis” — najczęściej czytana część przepisu i jedyne miejsce na tym ekranie z ciepłym tłem marki |
| `.lista-skladnikow` | wiersz oddzielony kreską, bez pudełka w pudełku |
| `.kroki` | kroki numerowane licznikiem CSS |

```html
<img class="przepis-zdjecie" src="/zdjecia/456.jpg"
     alt="Pizza z mozzarellą, pomidorkami i świeżą bazylią na drewnianej desce">
<h1>Pizza z pieca w ogrodzie</h1>
<div class="wiersz-autora">
  <span class="avatar avatar-sm"><img src="/zdjecia/piotr.jpg" alt="Zdjęcie profilowe: Piotr"></span>
  <a class="karta-autor" href="/@piotr">Piotr</a>
  <span class="meta">przepis po dziadku</span>
</div>

<section class="pochodzenie">
  <h2 class="pochodzenie-tytul">Skąd ten przepis</h2>
  <p>Po dziadku. Piekł ją w niedziele, w piecu, który sam zmurował za stodołą.</p>
</section>

<ul class="lista-skladnikow">
  <li>3 szklanki mąki</li>
  <li>mleko — ile weźmie</li>
</ul>

<ol class="kroki">
  <li>Wymieszaj mąkę z wodą i odstaw pod ściereczką na pół godziny.</li>
  <li>Rozgrzej piec. Poczekaj, aż kamień będzie gorący.</li>
</ol>
```

**Dostępność.**

- Kroki są `<ol>`, a numer **rysuje licznik CSS**, nie treść. Dzięki temu
  zmiana kolejności nie wymaga przepisywania numerów, a czytnik i tak poda
  „lista, 3 pozycje” i numer każdej.
- Numer kroku jest atramentem stonowanym, nie kolorem marki: na jednym ekranie
  kolor marki ma przycisk główny, a nie pięć numerków (D-110).
- „Skąd ten przepis” jest `<section>` z nagłówkiem, bo to samodzielna część
  dokumentu, po której ludzie skaczą.
- Składniki i kroki są listami, nie akapitami z myślnikami — czytnik podaje
  wtedy, ile ich jest, zanim zacznie czytać.

**Czego NIE robić.**

- **Nie wpisuj numeru kroku w treść** („1. Wymieszaj…”). Czytnik przeczyta go
  dwa razy, a przy zmianie kolejności numery kłamią.
- **Nie chowaj składników pod rozwijany panel.** Przy garnku mają być widoczne
  obok kroków, a nie za kliknięciem.
- **Nie rób z „Skąd ten przepis” cytatu ozdobnego.** To treść, którą ludzie
  czytają najczęściej, a nie ramka na dekorację.

---

## 28. Tabela

**Do czego jest.** Jedyny kształt treści w serwisie, którego nie da się złożyć
z kart: zestawienie danych w długim dokumencie prawnym.

**Gdzie występuje.** 15 polityka prywatności (oraz regulamin i zasady, których
w piętnastce nie ma).

**Warianty.** Jeden.

**Stany.** Tabela nie ma stanów.

**Klasy.** `.tabela-otoczka` (pudełko, które się przewija) > `.tabela` >
`th`, `td`.

```html
<div class="tabela-otoczka">
  <table class="tabela">
    <thead>
      <tr><th scope="col">Dane</th><th scope="col">Po co</th><th scope="col">Jak długo</th></tr>
    </thead>
    <tbody>
      <tr><td>Adres e-mail</td><td>Logowanie i odzyskanie hasła</td><td>Do usunięcia konta</td></tr>
      <tr><td>Adres IP</td><td>Zabezpieczenie przed nadużyciami</td><td>90 dni</td></tr>
    </tbody>
  </table>
</div>

```

**Dostępność.**

- `<th scope="col">` w `<thead>` — bez tego czytnik nie powie, do której
  kolumny należy komórka, i tabela staje się ciągiem słów.
- Tabela przewija się **we własnym pudełku**, nigdy razem ze stroną. To drugi,
  po karuzeli zdjęć, świadomy wyjątek od zakazu przewijania w poziomie.
- Nad tabelą stoi zdanie mówiące, co w niej jest — przy 320 px widać naraz
  jedną kolumnę i to zdanie bywa jedyną orientacją.
- Tekst w komórce ma `vertical-align: top`: przy trzech wierszach zawinięcia
  wyrównanie do środka rozjeżdża wiersz.

**Czego NIE robić.**

- **Nie zamieniaj tabeli na listę definicji, „bo lepiej się zawija”.**
  Zestawienie trzech kolumn jest tabelą i czytnik ma prawo to wiedzieć.
- **Nie używaj tabeli do układu strony.**
- **Nie zostawiaj tabeli bez `.tabela-otoczka`.** Wtedy przy 320 px przewija
  się cała strona, a to jest złamanie twardego ograniczenia.

---

# Co z tych propozycji weszło do systemu

Przy składaniu galerii wyszło dziewięć rzeczy, których w
`02-komponenty/komponenty.css` nie było. Pięć pierwszych to **usterki zmierzone
automatem `07-wdrozenie/sprawdz-uklad.mjs` na prawdziwej przeglądarce**, nie
propozycje kosmetyczne. Wszystkie dziewięć zostało przyjętych i **są w arkuszu,
w sekcji 16 „Uzupełnienia z budowy makiet i galerii”** — ta lista zostaje jako
zapis, skąd się wzięły i co dokładnie naprawiają. Szczegóły każdej z nich stoją
przy właściwym komponencie wyżej.

| | Co to było | Gdzie jest teraz | Opisane przy |
|---|---|---|---|
| P-1 | `<fieldset>` nie schodzi poniżej zawartości: grupa „Kto to widzi” miała 919 px w oknie 768 px | 16.1 `fieldset.field { min-width: 0 }` + zdjęta systemowa ramka | §3 |
| P-1b | `.choice-grid` przełączała kolumny progiem liczonym od **okna**, a nie od kontenera | 16.2, `repeat(auto-fit, minmax(min(13rem, 100%), 1fr))` — **decyzja D-112**, bo to zmiana zachowania na czterech ekranach | §3 |
| P-2 | dolny pasek przy 320 px i skali 140% (dzisiejsze maksimum konta) miał ~346 px | 16.1 `.bottom-nav-item { min-width: 0; overflow-wrap: anywhere; hyphens: auto }` | §19 |
| P-3 | „zielonapietruszkarano” z `COPY_STYLE.md` rozpychało kolumnę przy 150% skali | 16.1 `.field-error, .alert { overflow-wrap: anywhere }` | §2, §12 |
| P-4 | belka: znak + pole + dwie akcje ≈ 600 px, przy 320 px przewijanie strony | 16.1 `.topbar-wnetrze`/`.topbar-akcje` zawijają się, `.topbar-szukaj` znika poniżej 1024 px, doszła `.topbar-szukaj-kolumna` | §17 |
| P-5 | „Obserwujesz” przy 150% ma 211 px i wiersz nie mieścił się w szynie | 16.1 `.szyna-osoba { flex-wrap: wrap }` + `.szyna-osoba-tekst { flex: 1 1 var(--spacing-24) }` | §20 |
| P-6 | brak klas potwierdzenia destrukcyjnego (były w dzisiejszym `app.css`) | 16.8 `.confirm`, `.confirm-summary`, `.confirm-body`, `.confirm-question` | §24 |
| P-7 | brak wcięcia odpowiedzi w wątku | 16.9 `.watek-odpowiedzi` | §25 |
| P-8 | ani jednej reguły dla `<table>`, a ekran 15 jest „długim dokumentem z tabelami” | 16.10 `.tabela-otoczka`, `.tabela`, `.tabela th/td` | §28 |
| P-9 | ikona w „Dodaj zdjęcie” stała po lewej, bo reset daje `<svg>` `display: block` | 16.7 `.wybor-zdjecia-ikona { margin-inline: auto }` | §5 |

Razem z nimi doszły rzeczy zgłoszone przy składaniu ekranów, których galeria
wcześniej nie pokazywała, a teraz pokazuje: `.przepis-zdjecie`,
`.wiersz-autora`, `.pochodzenie`, `.lista-skladnikow`, `.kroki`, `.szyna-danie`,
`.rzad-pol`, `.siatka-kart { align-items: start }` oraz para
`.tylko-waskie` / `.tylko-szerokie`. Opisane są przy §27, §20, §2 i §6.

## Drobiazgi, które zostają do decyzji

- **Karta „Ugotowałem” nie ma własnej klasy.** Składa się w całości z klas
  karty wpisu i to wystarcza; osobna klasa przydałaby się dopiero wtedy, gdyby
  wiersz „ugotowała z przepisu X” miał wyglądać inaczej niż zwykłe metadane.
- **Stronicowanie nie ma własnej klasy** — `.srodek` + `.stos` + `.btn-duzy`
  robią dokładnie to, co trzeba. Klasa `.pokaz-wiecej` byłaby nazwą bez
  zawartości.
- **`select.field-input`** rysuje systemową strzałkę i w Chromium wygląda
  poprawnie w obu motywach (`color-scheme` jest ustawiony). Zostawiam bez
  własnych reguł do czasu sprawdzenia na Safari.

---

# Czego świadomie NIE zrobiłem

1. **Nie dopisałem sam ani jednej reguły do `tokens.css` i `komponenty.css`.**
   Dziewięć braków opisałem i zgłosiłem; do arkusza wpisał je właściciel
   paczki, jako sekcję 16, i dopiero wtedy galeria i ten plik zaczęły ich
   używać.
2. **Nie zostawiłem w galerii ani jednego pudełka „to jest brak w systemie”.**
   Kiedy braki weszły do arkusza, pudełka i podpisy o nich zniknęły, a
   komponenty stoją normalnie. Jedyne, co przewija się we własnym zakresie, to
   tabela — i to jest jej zaprojektowane zachowanie, nie obejście.
3. **Nie pokazałem stanu „najechanie” prawdziwym `:hover`-em.** Na statycznej
   stronie nie da się go utrwalić, więc każdy taki egzemplarz jest kopią
   odpowiedniej reguły `:hover` z arkusza, opisaną w bloku `<style>` galerii.
   To jedyny powód, dla którego galeria ma jeszcze własny `<style>`: poza
   symulacją stanów i układem samej galerii wszystko idzie z arkusza.
4. **Nie zrobiłem galerii ekranów.** Ten plik opisuje klocki; z czego składa
   się każdy z piętnastu ekranów, mówią szablony w `03-szablony/`.
5. **Nie użyłem trzech małych zdjęć z podglądu** (`soup`, `cake`, `pasta` mają
   92 px szerokości). Karta pokazuje zdjęcie na pełną szerokość kolumny, więc
   rozciągnięte 92 px wyglądałoby na usterkę renderowania, a nie na fotografię.
   W galerii pracują `pierogi.png` (520 px) i `pizza.png` (690 px).
6. **Nie rozstrzygnąłem sam, czy `.choice-grid` ma przejść z progu okna na próg
   kontenera.** To zmiana zachowania komponentu na czterech ekranach, nie
   poprawka — zgłosiłem ją i została przyjęta przez właściciela jako
   **decyzja D-112**.
