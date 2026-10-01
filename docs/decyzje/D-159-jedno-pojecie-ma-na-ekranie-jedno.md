## D-159 · Jedno pojęcie ma na ekranie JEDNO słowo — i nazwa usunięta z modelu danych musi zejść też z napisów

**Data:** 11 września 2026 · **Decyzja właściciela** · PR #409 · Status: **obowiązuje** ·
rozwinięcie D-021

### Co było na ekranie

D-021 (7 września) usunęła obiekt `Temat`, zostawiając same tagi, i podała
powód wprost:

> „dwa znaczyłyby, że osoba 50+ musi zrozumieć, czym «temat» różni się od
> «tagu», a to jest pytanie, na które sam produkt nie ma dobrej odpowiedzi"

**Tamta decyzja usunęła OBIEKT. Słowo zostało w napisach** — i cztery dni
później interfejs mówił do człowieka dwoma słowami o jednej rzeczy:

| gdzie | co stało |
|---|---|
| `pages/tags/index.blade.php` | `<h1>Wszystkie tematy</h1>`, `<title>`, `meta description`, dwa `<h2>`, dwa `aria-label`, pusty stan, akapit wprowadzający, „Pokaż więcej tematów" |
| `pages/tags/show.blade.php` | odnośnik „wszystkie tematy" w okruszkach |
| `components/post-card.blade.php` | `aria-label="Tematy tego wpisu"` |
| **`pages/settings/tags.blade.php`** | **„Wybierz temat i kliknij «Obserwuj ten tag»"** |

Ostatni wiersz to oba słowa **w jednym zdaniu**, na jednym ekranie, o jednej
czynności. Trasa nazywała się przy tym `/tagi`, a strona mówiła „tematy".

### Decyzja

> **Jedno pojęcie ma na ekranie jedno słowo. Gdy nazwa schodzi z modelu
> danych, schodzi także z napisów — inaczej decyzja jest wykonana w bazie
> i niewykonana tam, gdzie ją widać.**

Na ekranie obowiązuje **„tag"**. Słowo „temat" w znaczeniu klasyfikacji treści
do interfejsu nie wraca.

### Dlaczego to nie jest kosmetyka

Sprawa wyszła przy rozstrzyganiu nazwy dla #369–#372. Koncept „Tematy jako
miejsca" (#376) **nie przywraca obiektu** — mówi o bogatszej stronie tagu,
z instrukcją „nie wdrażać nowej tabeli bez potrzeby". Ale przywracał **słowo**:
gdyby strona tagu nazwała się „Temat", problem z D-021 wróciłby w nazewnictwie
zamiast w schemacie, czyli dokładnie tam, gdzie czytelnik go widzi.

### Cena, wprost

Trzy z tych miejsc to `<h1>`, `<title>` i `meta description` na `/tagi` —
**stronie publicznej z ruchem z wyszukiwarki**. Zmiana napisów, które czyta
Google, nie jest zmianą wyłącznie w interfejsie i została właścicielowi
zgłoszona osobno. Sama reguła kolejności i liczniki bez zmian.

### Komentarz cytujący inny komentarz poprawia się razem z nim

`TagController` cytował komentarz `Post::scopeTylkoOdAktywnychAutorow()`, który
mówił „feed tematów". **Poprawienie tylko cytatu zrobiłoby z niego nieprawdę**,
więc zmienione są oba. To ta sama zasada co w D-157, tylko w mniejszej skali:
zapis, który cytuje inny zapis, starzeje się razem z nim.

### Strażnik jest ZAWĘŻONY do ekranów tagów, i to nie z ostrożności

`tests/Feature/JednoSlowoNaTagiTest.php` sprawdza `/tagi`, `/tag/{slug}`
i `/ustawienia/tagi`. Nie skanuje całego repozytorium, bo **„temat" ma tu
drugie, całkowicie uprawnione znaczenie: temat listu** — `app/Mail/*`,
`app/Notifications/*` (`PodsumowanieTygodnia::temat()`), a `COPY_STYLE.md`
wymienia „temat listu" wprost jako jedno z miejsc, gdzie nazwa serwisu zostaje
zwykłym „Kuking". **Zakaz globalny oblewałby na poczcie i zostałby wyłączony
w tydzień** — a strażnik, którego się wyłącza, nie jest strażnikiem.

### Dopasowanie na granicach wyrazu, nie podciągiem

Pierwsza wersja detektora szukała podciągu „temat". Miała dwie wady, i druga
jest poważna: **oblałaby na słowie „tematyczny"**, czyli na „grupach
tematycznych" z #22 — nazwie całkowicie uprawnionej. Obowiązuje
`(?<!\p{L})temat(?:y|ów|u|em|ach|owi|ami|ce)?(?!\p{L})`, a kontrola samego
detektora sprawdza **oba kierunki**: że łapie „tematy", „temat" i „tematów",
i że **przepuszcza** „grupy tematyczne" oraz „tematyka wpisu".

### Test mierzy NASZ tekst, nie treść od ludzi

Człowiek ma prawo utworzyć tag nazwany „Temat dnia" i wtedy to słowo pojawi się
na stronie **zgodnie z prawem**. Dane testowe nie zawierają go ani raz, więc
każde trafienie pochodzi z szablonu. Napisane w docbloku testu, żeby nikt nie
uznał tego za lukę i nie „naprawił" strażnika w stronę zakazu treści
użytkownika.

### Scenariusz testu musi renderować sabotowany fragment

Test strony tagu zakłada **wpis z tagiem**, nie sam tag: karta wpisu pokazuje
chipsy tylko tam, gdzie relacja jest doładowana (`relationLoaded('tags')`). Bez
wpisu sabotowany `aria-label` **nie renderuje się wcale** i test przechodziłby,
nie mierząc go — to czwarta z przyczyn nieoblanej kontroli ujemnej z `AGENTS.md`.

### Co zostało nietknięte i dlaczego

Panel gospodarza `/admin/tagi-promowane`: „Temat tygodnia" jest tam **nazwą
planowanej funkcji z #18**, a ta decyzja jest przed właścicielem — nie
przesądza się jej przy okazji. „Ta lista zastępuje dawne Tematy" zostaje, bo
jest **historycznie prawdziwe**: ta lista naprawdę zastąpiła usunięty obiekt.

Nazwy metod testowych, nazwa pliku `SpisTematowTest.php` i dane testowe — to
nie interfejs, więc poza zakresem tej decyzji.

**Zmiana wymaga:** decyzji właściciela o nazwie funkcji z #18, jeśli miałaby
pociągnąć za sobą panel gospodarza.

📄 `resources/views/pages/tags/` · `resources/views/pages/settings/tags.blade.php` ·
`resources/views/components/post-card.blade.php` ·
`tests/Feature/JednoSlowoNaTagiTest.php` · D-021 · D-157 · issue #18 · issue #22
