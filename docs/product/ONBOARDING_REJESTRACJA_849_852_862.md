# Zachowanie wyborów podczas pierwszego wejścia

Zakres: #851, #852, #862, #849 i #850. Baza pracy:
`534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`.

## Wybór osób

Jeden formularz przenosi zaznaczenia przy wyszukiwaniu i czyszczeniu frazy.
Obie czynności wykonują GET, a „Dalej” POST. Token CSRF jest wartością
przycisku „Dalej”, więc wyszukiwanie nie zapisuje go w adresie. Działa to
także bez JavaScriptu. Osoba występująca w wynikach i rekomendacjach ma
jeden checkbox. Wybrane osoby spoza wyników można odznaczyć.

Kontekst wyboru należy do konta i sesji, wygasa po 30 minutach i jest
usuwany po przejściu do końca onboardingu, także przy pominięciu. Nazwy
nie są trwale zapisywane w przeglądarce. Odtwarzanie profili przechodzi
przez `UserPolicy::follow`; blokada lub niedostępność konta nadal odcina
wyświetlenie wyboru. Po błędzie formularza pierwszeństwo ma wysłane
wejście, a nie parametry wcześniejszego adresu.

Zapis nadal przyjmuje najwyżej 20 pozycji. Brak pola, pusta lista i null
oznaczają pusty wybór. Duplikaty są usuwane bez rozróżniania wielkości
liter. Już istniejące obserwowanie spełnia wybór i nie tworzy kolejnego
powiadomienia. Pominięcie części albo wszystkich pozycji daje zbiorczą
instrukcję na ekranie końcowym, bez ujawniania blokad. Wyjątki infrastruktury
nie stają się zwykłą odmową domenową.

## Domknięcie konta zewnętrznego

Podpowiedź nazwy przechodzi tę samą regułę `ReservedUsername`, którą stosuje
zapis, zarówno dla podstawy, jak i kolejnych kandydatów z sufiksem.
Rezerwacja nadal dotyczy pełnych nazw; `Adminowicz` pozostaje dozwolony.
Podpowiedź nie rezerwuje nazwy i zapis ponownie sprawdza zajętość.

Po wysłaniu formularza z wygasłą tożsamością sesja zachowuje na 30 minut
wyłącznie tekst imienia i nazwy mieszczący się w limitach pól. Szkic jest
związany z dostawcą i identyfikatorem jego konta. Ponowne przejście przez
OAuth tym samym kontem przywraca pola; inne konto ich nie dziedziczy.
Wygaśnięcie szkicu daje instrukcję ponownego wpisania. Szkic nie przedłuża
tożsamości, nie zawiera tokenów ani oświadczeń i znika po założeniu konta.

## Sprawdzanie i wycofanie

Regresje HTTP: `OnboardingZachowujeWyborTest`, `OnboardingWynikZapisuTest`,
`PropozycjaNazwyZewnetrznejTest`, testy domknięcia w obu klasach
`LogowanieKontem*Test`. Scenariusz przeglądarkowy:
`node scripts/onboarding-browser.mjs` (także `--bez-js`), po przygotowaniu
runtime, migracji własnej bazy i buildzie assetów. Fixture odmawia pracy
poza bazą `kuking_flota_gpt-onboarding` na `127.0.0.1:55439`.

OAuth w testach ma kontrolowane odpowiedzi dostawców. Nie jest to próba
na prawdziwych kontach Google/Facebooka ani odbiór produkcji.

Brak zmian schematu i migracji. Wycofanie polega na cofnięciu commitów
tego pakietu; utworzone obserwowania i konta zostają. Niewykorzystywane
klucze szkiców znikną wraz z sesją. Cofnięcie przywraca opisane usterki.

## Pomiary własne — 20 września 2026

Przejęty szkic zabezpieczono commitem `84740083`, po czym trzy zmienione
pliki przywrócono do bazy `534e0a51` przed pomiarami. Szkic nie służył jako
dowód poprawności. Nie przejęto cudzych wyników testów.

| Zgłoszenie | Osobiście odtworzona czerwień przed poprawką | Wynik po poprawce |
| --- | --- | --- |
| #851 | 14 istniejących testów / 51 asercji przechodziło, lecz nowy test i rzeczywista przeglądarka gubiły Halinę po wyszukaniu Marka | Przenoszenie wyborów, czyszczenie, odznaczenie, zapis tylko Marka i pominięcie kroku działają |
| #852 | Brak instrukcji po częściowej i całkowitej odmowie | Instrukcja widoczna na końcu; istniejąca relacja nie jest błędem |
| #862 | POST z `follow=null`: HTTP 500, `foreach` otrzymywał null | Brak pola, pusta lista i null nie tworzą relacji ani powiadomień |
| #849 | 5 czerwonych przypadków: propozycje z nazw zastrzeżonych i formularz dostawcy | Wspólna reguła obejmuje podstawę i sufiks; dozwolona zwykła nazwa nadal przechodzi |
| #850 | Oba pełne przebiegi z kontrolowanym OAuth przywracały Basię zamiast własnego imienia | Własne pola wracają; inne konto i przekroczony termin nie dziedziczą szkicu; oświadczenia pozostają niezaznaczone |

Każda z pięciu poprawek przeszła także kontrolę ujemną przez
`scripts/kontrola-ujemna.sh`: PASS → faktyczna zmiana MD5 → FAIL z oczekiwanej
przyczyny → przywrócenie → PASS. Powtarzalny zestaw poleceń zawiera
`scripts/onboarding-kontrole.sh`. Po zakończeniu osobno porównano MD5
odtworzonych plików z wartościami sprzed mutacji — wszystkie zgodne.
Przyrząd potwierdził również przywrócenie mtime na swoim wyjściu.
Jego pliki JSON zapisują pole `przywrocenie` przed końcowym trapem;
wartość „nie wykonane” w tym polu nie jest dowodem końcowego stanu.

Chromium: scenariusz przeszedł z JavaScriptem i bez niego. Przy szerokości
320 px sprawdzono brak przewijania poziomego, co najmniej 18 px w polu
wyszukiwarki i 48 px wysokości przycisków. Obejrzano zrzut ekranu.
Nie mierzono osobno powiększenia 200% ani przeglądarek innych niż Chromium.

`npm run build`: wynik dodatni, w tym 20 testów JS i kontrola 72 par
kontrastu. `vendor/bin/pint` wykonano; końcowe `--test`: 1159 plików poprawnych.

Nie uruchomiono `ProbaOdtworzeniaTest`: jawny wyjątek użytkownika, ponieważ
odwołuje się do współdzielonej bazy prób. Nie wykonywano push, nie otwierano
PR ani nie zmieniano produkcji. Nie zmieniono schematu ani `$fillable`.
Pozostałe pomiary dotyczą wyłącznie własnej bazy na porcie 55439.
Nie pozostało pytanie produktowe wymagające decyzji właściciela.

Commity implementacji na `gpt/onboarding`:

- `847400831aa54d95dcb5e626b89c65c394e44dc4` — zabezpieczenie przejętego szkicu;
- `be058f9ca515a48e031d205e1691e2485b8a4def` — wybór osób, wynik zapisu, null;
- `57bfdfda02fb50d5934d76b963e15cee44501c12` — nazwy zastrzeżone i odzyskanie pól OAuth.

Do kolejki przekazujemy całą gałąź względem `534e0a51`, nie sam snapshot.

Końcowy pełny zestaw: **4412 testów, 83 661 asercji, 341,52 s, zero porażek**.
Uruchomiono przez wspólny `testuj.sh gpt-onboarding` z filtrem
`^(?!.*ProbaOdtworzeniaTest)`. Wcześniejszy pełny przebieg przed dodatkowymi
próbami granicznymi: 4408 testów, 83 650 asercji, także dodatni.

Dodatkowa kontrola ujemna komunikatu limitu: usunięcie `x-blad-grupy`
rzeczywiście psuje test odtwarzający 21 zaznaczeń po POST; przywrócenie
przywraca wynik dodatni. Każdy etap tej kontroli czyści cache Blade, bo
odtworzenie oryginalnego mtime pozostawia nowszy skompilowany widok mutacji.
Pierwszy przebieg bez czyszczenia cache nie dał końcowego PASS; po poprawie
przyrządu cały cykl PASS → FAIL → PASS został wykonany ponownie.
