## D-059 · Newslettera redakcyjnego nie budujemy — tygodniowe podsumowanie (D-057) jest odpowiedzią na to pytanie

**Data:** 10 września 2026 · Research: `docs/research/NEWSLETTER.md` (#241) · Status: **obowiązuje**

Właściciel pytał, czy da się wysyłać cotygodniowy newsletter z wyróżnionymi
przepisami — czy pozwala na to polityka prywatności i EmailLabs. Research
odpowiedział: prawnie da się, ale wymaga **osobnej zgody marketingowej**
(art. 398 Prawa komunikacji elektronicznej), a darmowy budżet poczty
(300 listów/dobę) jest już w całości rozdysponowany, więc newsletter kosztuje
od pierwszego dnia. Po przeczytaniu właściciel zdecydował, dosłownie:
*„z tym newsletterem to odpuszczamy w tej formie, którą ja pisałem. Rób jak
zrobiłeś"*.

Czyli:

1. **Nie powstaje** drugi kanał pocztowy, druga zgoda ani ekran zapisu na
   newsletter. `users` nie dostaje kolumny `wants_newsletter`.
2. **Zostaje to, co jest** — tygodniowe podsumowanie od gospodarza (D-057):
   opt-in, spersonalizowane, wysyłane tylko do osób, które je włączyły, na
   podstawie zgody, którą już mamy udokumentowaną.
3. **Wyróżnianie przepisów, jeśli wróci, idzie na stronę, nie w pocztę** —
   cotygodniowa kolekcja redakcyjna (Pętla 4 z `docs/product/RETENTION_LOOPS.md`),
   linkowana z istniejącego podsumowania. Bez rankingu (AGENTS.md §12), bez
   nowego kanału i bez nowej zgody.

### Dlaczego to jest zapisane, choć „nic nie robimy"

Bo pytanie wróci — newsletter jest pierwszą rzeczą, którą się proponuje przy
rozmowie o wzroście. Bez tego wpisu ktoś (agent albo właściciel po pół roku)
zacznie research od zera i skończy na tej samej odpowiedzi, albo — gorzej —
dobuduje kanał, którego prawnej podstawy nikt nie sprawdził.

**Warunek powrotu do tematu:** zgoda prawnika na brzmienie zgody
marketingowej (issue #8), płatny plan poczty i jawna zgoda właściciela, że
redakcja to trwały, cotygodniowy obowiązek człowieka. Sam research zostaje
w `docs/research/NEWSLETTER.md` — nie trzeba go robić drugi raz.

**Pliki:** `docs/research/NEWSLETTER.md` (nagłówek stanu) · ten wpis.
Kodu ta decyzja nie zmienia — to jej cały sens.
