# Testy z realnymi użytkownikami 50+ — zestaw do przeprowadzenia

**Issue:** #15 (P0, bramka przed publiczną betą) · **Stan ścieżek sprawdzony:** 10 września 2026.

Ten plik jest **narzędziem do użycia w dniu badania**, nie opisem metody.
Standard z `docs/UX_50_PLUS.md` jest hipotezą, dopóki nie zobaczymy, jak realna
osoba próbuje z tego skorzystać: automat sprawdzi kontrast i rozmiar przycisku,
ale nie zobaczy momentu, w którym ktoś patrzy w ekran i pyta **„co mam teraz
kliknąć?"**. To pytanie jest najważniejszą daną z całego badania.

Skład grupy, scenariusze i kryteria akceptacji stoją w issue #15 i w
`docs/UX_50_PLUS.md` — tutaj jest to, czego tam nie ma: **czym sprawdzić, że
sesja się nie zmarnuje, co powiedzieć, co zapisać i jak policzyć wynik.**

---

## 1. Przed pierwszą sesją: dziesięć ścieżek naprawdę istnieje

Sesja z osobą 70+, która zgodziła się poświęcić godzinę, nie może umrzeć na
brakującej funkcji. Sprawdzone w kodzie 10 września 2026 (adresy z
`routes/web.php`):

| # | Zadanie z #15 | Gdzie to jest | Uwaga na dzień sprawdzenia |
|---|---|---|---|
| 1 | Założenie konta | `/register` | captcha Turnstile wymaga JavaScriptu (D-053) — sprawdź, czy przeglądarka uczestnika go nie blokuje |
| 2 | Zdjęcie tego, co ugotowała | `/dodaj/zdjecie` | główna akcja produktu; HEIC z iPhone'a to znane ryzyko (#119) — jeśli osoba ma iPhone'a, **to jest ważniejsze niż cała reszta sesji** |
| 3 | Dodanie przepisu | `/dodaj/przepis` **oraz** `/dodaj/przepis/jedna-strona` | dwie drogi, świadomie. Nie sugeruj, którą wybrać — to jest wynik badania |
| 4 | Znalezienie przepisu na żurek | `/szukaj` | działa tylko na treści zalążkowej: **uruchom `TrescZalazkowaSeeder`** (jest tam „Żurek na domowym zakwasie"). Na pustej bazie to zadanie nie istnieje |
| 5 | Zapisanie przepisu na potem | `/zeszyt`, przycisk przy przepisie | |
| 6 | Komentarz pod czyimś wpisem | `/wpisy/{id}` | konta zalążkowe są **widocznie oznaczone** jako przykładowe (D-025) — zapisz, czy osoba to zauważyła i czy jej to przeszkadzało |
| 7 | Powiększenie tekstu | `/ustawienia/czytelnosc` **albo** ustawienie w telefonie/przeglądarce | pytanie brzmi: którą drogą pójdzie sama. Obie są poprawne, obie coś nam mówią |
| 8 | Usunięcie własnego wpisu | `/wpisy/{id}/edycja` | |
| 9 | Zablokowanie kogoś | profil `/@login` | |
| 10 | Pobranie swoich danych | `/ustawienia/twoje-dane` | paczka powstaje w tle — osoba musi zrozumieć, że ma czekać, a nie klikać drugi raz |

**Zrób te dziesięć rzeczy sam, na tym samym środowisku, dzień przed pierwszą
sesją.** Nie po to, żeby sprawdzić kod — po to, żeby nie tłumaczyć uczestnikowi
awarii wdrożenia.

**Środowisko:** to samo, na którym będzie beta (nie `localhost` na laptopie
prowadzącego). Konta uczestników zakładane naprawdę, ich adresem — inaczej
zadanie nr 1 przestaje istnieć, a to najważniejsze zadanie w całym zestawie.

---

## 2. Zgoda i nagranie

Nagrywamy **ekran, nigdy twarzy** (issue #15). To jest przetwarzanie danych
osobowych: nagranie pokazuje adres e-mail wpisywany w formularzu, treść, którą
osoba pisze, i jej zdjęcia.

**Do przeczytania na głos, przed włączeniem nagrywania:**

> „Chciałbym nagrać sam ekran — bez Pani twarzy i bez głosu, jeśli Pani woli.
> Nagranie zobaczę tylko ja, posłuży do poprawienia serwisu i usunę je po
> trzech miesiącach. Może Pani odmówić i będziemy pracować dalej, mogę też
> przerwać nagrywanie w każdej chwili — wystarczy powiedzieć. Zgadza się Pani?"

Zapisz w karcie sesji: **czy zgoda była, na co (ekran / ekran + głos) i o której
godzinie.** Bez zgody sesja się odbywa — tylko bez nagrania, na notatkach.

Zasady, których nie wolno obejść:
- nagranie **nie idzie** do żadnej chmury poza dyskiem prowadzącego, nie trafia
  do repozytorium i nie jest pokazywane osobom trzecim;
- **usuwane po trzech miesiącach** albo natychmiast po wycofaniu zgody;
- w notatkach i w issues **nie ma imion ani adresów** — uczestnik to „U-04,
  kobieta, 68 lat, Android";
- jeśli osoba wpisze w serwisie coś prywatnego (numer telefonu, adres),
  wykasuj to z jej konta po sesji i powiedz jej o tym.

---

## 3. Skrypt prowadzącego

**Na początku, dosłownie:**

> „Testujemy serwis, nie Panią. Jeśli coś będzie niejasne, to jest błąd, który
> mam znaleźć — im więcej takich miejsc Pani pokaże, tym lepiej. Proszę mówić
> na głos, co Pani myśli i czego szuka. Ja będę milczał, nawet jeśli to będzie
> niewygodne — nie dlatego, że nie chcę pomóc."

**Zasada milczenia.** Gdy osoba utyka, **policz w myślach do dziesięciu**, zanim
cokolwiek powiesz. Cisza jest niewygodna i właśnie dlatego działa: większość
ludzi w tym czasie znajduje drogę sama, a to, czym się przy tym posłużyli, jest
odpowiedzią na pytanie „czego brakuje na ekranie".

**Trzy poziomy podpowiedzi — każdy zapisany w karcie:**

| Poziom | Co mówisz | Co to znaczy w wyniku |
|---|---|---|
| P1 | „Co Pani teraz widzi na ekranie?" (bez wskazywania) | osoba szuka, ekran nie prowadzi |
| P2 | „Gdyby Pani miała zgadnąć, gdzie to może być?" | ekran nie ma czytelnej ścieżki |
| P3 | wskazanie miejsca wprost | **zadanie nieukończone samodzielnie** |

**Czego nie robisz nigdy:** nie tłumaczysz, jak coś działa („to jest taki
przycisk, który…"). Jeśli trzeba tłumaczyć — to jest wynik badania, a nie
przeszkoda w badaniu. Nie mówisz „to proste", nie mówisz „wystarczy tylko", nie
kończysz zdania za uczestnika.

**Na sprzęcie tej osoby**, w jej rozmiarze czcionki i jasności ekranu. Nie
poprawiaj jej ustawień „żeby było widać" — to, że musiała je kiedyś zmienić,
jest częścią danych.

---

## 4. Zadania — brzmienie do przeczytania

Zadanie mówi o CELU, nigdy o funkcji. „Użyj formularza dodawania wpisu" testuje
umiejętność czytania instrukcji; „pokaż, co dziś ugotowałaś" testuje produkt.

1. „Proszę założyć sobie konto w tym serwisie."
2. „Proszę pokazać innym, co dziś Pani ugotowała." *(kryterium: wpis
   opublikowany i widoczny na profilu)*
3. „Ma Pani przepis, który robi Pani od lat. Proszę go tu zapisać, żeby nie
   zginął." *(zapisz, którą z dwóch dróg wybrała i czy wróciła)*
4. „Proszę sprawdzić, czy ktoś tu ma przepis na żurek."
5. „Ten przepis się Pani podoba. Proszę zrobić tak, żeby móc go łatwo znaleźć
   za tydzień."
6. „Proszę napisać coś tej osobie pod jej zdjęciem."
7. „Ten tekst jest dla Pani za mały. Proszę z tym coś zrobić."
8. „Ten wpis dodała Pani przez pomyłkę. Proszę go usunąć."
9. „Ta osoba jest nieprzyjemna i nie chce Pani jej widzieć."
10. „Proszę zabrać ze sobą wszystko, co Pani tu zapisała — na wypadek, gdyby
    serwis kiedyś zniknął."

Po każdym zadaniu jedno pytanie: **„Kto teraz to widzi?"** przy zadaniach 2, 3
i 6. Zrozumienie widoczności własnej treści jest w tym produkcie warunkiem
zaufania, a nie detalem interfejsu.

---

## 5. Karta jednej sesji (do wydruku)

```
Uczestnik: U-__   wiek: __   sprzęt: Android / iPhone / komputer
Publikował(a) kiedykolwiek cokolwiek w internecie: tak / nie
Zgoda na nagranie ekranu: tak / nie      godzina: __:__
Rozmiar czcionki w jego/jej urządzeniu: domyślny / powiększony (jaki: ____)

zadanie | ukończone bez podpowiedzi | podpowiedzi (P1/P2/P3) | czas | gdzie padło „co mam kliknąć?" | cytat
--------|---------------------------|------------------------|------|-------------------------------|-------
   1    |                           |                        |      |                               |
  ...   |                           |                        |      |                               |

Czas do PIERWSZEJ publikacji (zadanie 2, od wejścia na stronę): ____
Miejsca wycofania się z ekranu (adres + co zrobiła zamiast): 
Odpowiedzi na „kto to teraz widzi?" (zad. 2 / 3 / 6):
Czy znalazła powiększanie tekstu bez podpowiedzi: tak / nie / którą drogą:
Rzeczy, które powiedziała same z siebie (dosłownie):
```

**Cytaty zapisuj dosłownie.** „To jest jakieś dziwne" nie znaczy nic; „nie wiem,
czy to już poszło" wskazuje konkretny brak potwierdzenia na konkretnym ekranie.

---

## 6. Jak policzyć wynik

**Bloker** — dowolne z tego:
- zadanie **nieukończone** bez podpowiedzi poziomu P3,
- osoba **porzuciła** ekran i zrobiła coś innego niż zadanie,
- osoba zrobiła coś **nieodwracalnego przez pomyłkę** (usunęła nie to, co
  chciała; opublikowała jako publiczne coś, co miało być prywatne),
- osoba **źle odpowiedziała na „kto to teraz widzi?"** przy własnej treści.

Zestawienie problemów uszereguj **ilością osób, których dotyczył** (nie
„ważnością" w oczach prowadzącego), a w drugiej kolejności tym, czy zablokował
zadanie:

```
problem | ilu z 13 | bloker? | zadanie | co dokładnie widzieli
```

**Bramka przed publiczną betą (issue #15): każdy bloker naprawiony.** To warunek,
nie sugestia. Problem, który dotknął jednej osoby i jej nie zablokował, idzie do
kolejki jako zwykłe issue.

Osobno wypisz **rzeczy, które zadziałały** — bo następna zmiana interfejsu może
je zepsuć, a wtedy nikt nie będzie pamiętał, że kiedyś działały.

---

## 7. Co zrobić z wynikami (bez tego badanie jest tylko wrażeniem)

1. **Każdy bloker = issue** z etykietą `obszar: ux`, opisany słowami uczestnika
   i z numerem uczestnika, nigdy z jego imieniem.
2. **Wnioski dopisz do `docs/UX_50_PLUS.md`** — standard ma się uczyć. Zdanie
   w rodzaju „przy 200% czcionki nikt nie zauważył przycisku w prawym górnym
   rogu" jest warte więcej niż cała reszta tego dokumentu.
3. **Zdanie, które trzeba było wypowiedzieć podczas sesji, jest brakującym
   tekstem na ekranie.** Zapisz je dosłownie — to gotowa treść do wpisania.
4. **Nie naprawiaj w trakcie badania.** Zmiana interfejsu po trzeciej sesji
   znaczy, że kolejne dziesięć osób testowało coś innego, a wyniku nie da się
   zsumować. Wyjątek: awaria, która uniemożliwia zadanie.

---

## 8. Czego ta runda NIE testuje — i dlaczego

- **panelu moderatora i administratora** — używa go jedna osoba, która go
  zna, a stoi za obowiązkowym 2FA;
- **poczty** (potwierdzenia, przypomnienia hasła) — jeśli list nie dojdzie
  w trakcie sesji, sesja umiera; wyślij sobie testowy list wcześniej
  (`php artisan kuking:sprawdz-poczte`);
- **zachowania przy tysiącu wpisów** — tego nie zobaczy trzynaście osób;
- **estetyki.** „Czy się Pani podoba?" nie jest pytaniem badawczym i nie ma
  na nie miejsca w karcie. Pytamy o to, czy dała radę.

## 9. Rekrutacja — jedna rzecz warta rozstrzygnięcia zawczasu

Trzynaście osób 50+, w tym co najmniej dwie, które nigdy nic nie publikowały
w internecie. Najbliższa droga (grupy na Facebooku, `docs/marketing/KAMPANIA_GARNEK.md`)
jest jednocześnie kanałem startu produktu — i to jest realny konflikt:
**pierwsze wrażenie tych osób zostanie zużyte na wersję z blokerami.** Do
rozstrzygnięcia przed rekrutacją: albo szukamy uczestników poza publicznością
startową (rodzina, znajomi, lokalne koło, biblioteka, klub seniora), albo
świadomie zapraszamy z grup — mówiąc wprost, że to jeszcze nie otwarcie,
i wracając do tych osób po naprawie blokerów.

Jedna sesja to realnie **45–60 minut** plus 15 minut na notatki. Trzynaście
sesji to nie jedno popołudnie; rozłóż je na co najmniej dwa tygodnie i pisz
podsumowanie po każdej, nie na końcu.
