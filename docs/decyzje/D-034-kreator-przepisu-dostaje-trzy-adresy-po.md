## D-034 · Kreator przepisu dostaje trzy adresy, po jednym na krok

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **przyjęta,
niezbudowana**

Dziś są dwa adresy: `/dodaj/przepis` (kreator Livewire w trzech krokach,
wymaga JavaScriptu) i `/dodaj/przepis/jedna-strona` (ten sam formularz zwykłym
POST-em). Kroki istnieją — „Krok 1 z 3", „Krok 2 z 3", „Krok 3 z 3" —
ale **wszystkie trzy mieszkają pod jednym adresem**.

Właściciel przyjął wariant z trzema adresami (`/dodaj/przepis`,
`/dodaj/przepis/skladniki`, `/dodaj/przepis/kroki`) plus jednostronicowy
`/dodaj/przepis/wszystko`.

**Dlaczego adres, a nie stan w komponencie.** Krok bez własnego adresu nie ma
przycisku „wstecz" przeglądarki, nie da się go dodać do zakładek, nie wraca po
odświeżeniu i nie działa bez JavaScriptu — a „ważne funkcje działają bez
JavaScriptu" jest zasadą projektu, nie preferencją. Dla osoby, która spisuje
przepis babci przez dwadzieścia minut, odświeżona strona bez adresu kroku
znaczy: od początku.

**Co musi wejść razem z tym:** zapis szkicu na serwerze po każdym kroku
(inaczej trzy adresy tylko rozkładają utratę danych na trzy razy),
przekierowanie ze starego adresu jednostronicowego i sprawdzenie
podświetlenia „Dodaj" w nawigacji na wszystkich czterech adresach — to już raz
było zepsute.

**Zmiana wymaga:** nowej decyzji właściciela.

📄 `resources/views/components/recipe-wizard.blade.php` · `routes/web.php` ·
D-108 (system v3.1)
