## D-131 · Napis przycisku jest JEDNYM elementem — `inline-flex` rozbija tekst na osobne elementy flex

**Data:** 11 września 2026 · Zgłosił właściciel · Status: **obowiązuje**

### Objaw

Na telefonie główny przycisk strony powitalnej wyglądał tak:

```
Zost    kuKINGi      — to
ań        em      darmowe
```

### Przyczyna — nie „za długi napis"

`.btn` jest `display: inline-flex`, a kontener flex robi z KAŻDEGO kawałka tekstu
między elementami inline **osobny element flex**. Napis
`Zostań <x-kuking-word/> — to darmowe` to trzy węzły, czyli trzy niezależnie
zawijane elementy rozdzielone `gap`. Do tego `.btn` ma `overflow-wrap: anywhere`
(obrona przed wypchnięciem strony przy 200% czcionki), więc każdy z nich łamał się
w ŚRODKU WYRAZU, bo osobno był za wąski.

### Zmierzone, ramka 320 px

Bez owinięcia: 3 elementy flex, napis w pięciu kawałkach, przycisk 114 px wysokości.
Z owinięciem: 1 element, dwa wiersze łamane na spacjach, 84 px.

### Reguła

Napis przycisku owinięty jednym elementem. **Przycisk z ikoną to POPRAWNE dwa
elementy flex** — `gap` między ikoną a podpisem jest tam po to, żeby był, i tego się
nie „naprawia".

Strażnik pyta o wyrenderowany HTML: dla każdego `.btn` liczy bezpośrednie węzły
tekstowe. Dwa lub więcej to błąd, bo dwa węzły tekstowe mogą być rozdzielone tylko
elementem inline.
