# Monetyzacja

> ## ⚠️ SPROSTOWANIE, 9 września 2026
>
> **Ten plik do dziś opisywał hipotezę premium za 14,99–19,99 zł/mies. i „rozsądne
> reklamy" w pakiecie darmowym. Jedno i drugie jest nieprawdą od 6 września**,
> kiedy właściciel zapisał stanowisko wprost — a plik został nietknięty i przez
> trzy dni kłamał każdemu, kto czytał go zamiast wątku
> [issue #36](https://github.com/woogitsu/kuking.pl/issues/36).
>
> Poprawione zgodnie z **D-038**: gdy dokument i decyzja mówią co innego,
> poprawiamy to, co jest nieprawdą.

---

## Stanowisko właściciela — to jest cała odpowiedź

**Zarabianie nie jest celem. Portal ma być przyjemny do korzystania.**

To nie jest „monetyzacja później". To zmiana wagi całego zagadnienia: przychód
nie jest powodem, dla którego cokolwiek w produkcie wygląda tak, jak wygląda.

Cztery konsekwencje, zapisane, żeby to nie zostało samą deklaracją:

1. **Żadna decyzja produktowa nie jest podejmowana z powodu przychodu.** Jeśli
   propozycja funkcji ma w uzasadnieniu „to da się spieniężyć" i nic poza tym —
   to jest argument odrzucony, nie argument.
2. **Reklama jest wykluczona, nie odłożona.** Łamie „spokojny, czytelny
   interfejs" z `AGENTS.md` §5, a reklama behawioralna dokłada zgodę RODO
   i obowiązki informacyjne DSA. To nie jest kwestia ceny ani wykonania.
3. **Nie budujemy infrastruktury płatności** ani niczego, co ją zakłada, dopóki
   nie ma konkretnej rzeczy do sprzedania i ludzi, którzy jej chcą.
4. **Rzeczy dziś darmowe zostają darmowe.** Wsteczne zabranie funkcji, którą
   ktoś już ma, jest gorsze niż nigdy jej nie dać — szczególnie w grupie, która
   trzyma w serwisie rodzinne przepisy.

Źródło: komentarz właściciela w issue #36 z 6 września 2026
([`issuecomment-5559906060`](https://github.com/woogitsu/kuking.pl/issues/36#issuecomment-5559906060)).

## Co jest dziś w kodzie

**Nic.** Sprawdzone 9 września: zero infrastruktury płatności, zero paywalla,
zero reklam. Produkt jest w całości zgodny z tą decyzją i nie wymaga żadnej
zmiany, żeby był.

Koszt utrzymania alfy to rząd **poniżej 50 USD/mies.** — oszacowanie z widełek
w `docs/COSTS.md` (Railway 5–30 USD, R2 1,50–15 USD), nie osobna liczba
źródłowa. Przy takim koszcie budowanie systemu subskrypcji dla przychodu rzędu
dziesiątków złotych miesięcznie jest nieopłacalne operacyjnie — niezależnie od
tego, czy ktoś by chciał.

## Co research wykluczył, i dlaczego

Pełna analiza z dwunastoma werdyktami i źródłami: `docs/research/MONETYZACJA.md`.
Ten dokument powstał **przed** decyzją właściciela i jest z nią zgodny — jego
własna rekomendacja to nie sprzedawać niczego teraz. Warto go zachować właśnie
dlatego, że **zamyka** temat z uzasadnieniem, a nie go otwiera.

| Pomysł | Werdykt | Powód |
|---|---|---|
| Reklama display | **NIE, wykluczone** | Garnek.pl — polski poprzednik — zamknął się dokładnie z tego powodu: przychody z reklam przestały pokrywać koszty. Reklama nie ratuje takiego serwisu, tylko psuje go po drodze |
| Subskrypcja ogólna (filtry, brak reklam) | **NIE** | Trzy benchmarki mówią to samo: Strava 2% konwersji przy 195 mln kont, Duolingo 9,2% po latach optymalizacji. Subskrypcja konsumencka wymaga masy krytycznej, której Kuking nie ma i długo nie będzie mieć |
| Eksport / kopia zapasowa jako płatna | **NIE, nigdy** | To obowiązek z RODO (prawo do przenoszenia danych), nie funkcja premium. Sprzedawanie tego byłoby sprzedawaniem cudzego prawa |
| Wsparcie autora (model Patreona) | **NIE** | Przy 300 000 aktywnych twórców tylko ~4% zarabia powyżej 100 tys. USD/rok. Przy skali Kukinga dałoby to zero realnego dochodu komukolwiek — i rozczarowanie zamiast pieniędzy |
| Licencjonowanie danych | **NIE, zamach na fundament** | To samo ryzyko zaufania, które zabiło Naszą-Klasę |
| Sprzedaż B2B insightów | **NIE teraz** | Wymaga skali tysięcy kont |
| Treści sponsorowane | **NIE teraz** | Warunek: skala plus jednoznaczne oznaczenie. Dziś nie ma ani jednego, ani drugiego |
| Afiliacja | **później, niski priorytet** | Warunek: realny ruch z wyszukiwarek |
| Planer / tryb gotowania jako premium | **pytanie przedwczesne** | `docs/ROADMAP.md` „V1 gate" i tak blokuje te funkcje do potwierdzenia retencji (WAC/D30) |
| Druk „rodzinnej książki" | **być może później, BEZ ceny na dziś** | Trzy niezależne firmy (CEWE Fotojoker, Photobox, Printbox) żyją z fotoksiążek na polskim rynku, więc zachowanie zakupowe istnieje. Ale kolejność jest odwrotna: najpierw zbudować funkcję z `docs/product/SOUL.md` §4.3, żeby była miła, a **dopiero** gdy ludzie z niej korzystają, zapytać garstkę, ile zapłaciliby za wydruk |
| Zbiórka / darowizny na hosting | **dopuszczalne** | Jedyna opcja bez kosztu zaufania. Bez znanej kwoty i bez obietnic |
| Opłata równa kosztowi hostingu | **to jest dzisiejszy stan** | Właściciel płaci za hosting i to jest w porządku |

Dwa zastrzeżenia do liczb, oznaczone też w samym researchu: teza, że spadek
subskrypcji Cookpada wynika konkretnie z premium za wyszukiwanie, jest
wnioskowaniem z listy funkcji, nie cytatem z raportu spółki. Liczby Patreona
i Substacka pochodzą z artykułów branżowych, nie z komunikatów tych firm.

## Gdyby temat kiedyś wrócił

Warunek wstępny do poważnej rozmowy: **„Closed alpha gate" z `docs/ROADMAP.md`**
— 20 realnych użytkowników. Przed tym progiem nie ma komu zadać pytania o cenę,
więc każda odpowiedź byłaby wymyślona.

I jedna zasada, która przetrwa każdą przyszłą decyzję: **co darmowe, zostaje
darmowe.** Nowa rzecz może być płatna. Istniejąca — nie.

## Reguły reklamowe — zachowane, choć nieaktualne

Reklama jest wykluczona, więc poniższe nie ma dziś zastosowania. Zostaje jako
zapis tego, co uznano za nieprzekraczalne, gdyby ktokolwiek kiedyś wracał do
tematu — i jako miara tego, jak daleko posunięta musiałaby być zmiana zdania.

Nigdy: reklama między składnikiem a krokiem przepisu; reklama udająca przycisk;
modal blokujący przepis; format zasłaniający główną akcję.

Reklama behawioralna łamie przy tym więcej niż estetykę — wymaga zgody z RODO
i oznaczeń z DSA, których dziś nie mamy i nie chcemy mieć.
