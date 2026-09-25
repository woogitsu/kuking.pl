## D-232 — Dopisek przy składniku bez ilości: dwa ekrany, dwa świadomie różne zachowania (#878, #764/#1197, #1222)

22 września 2026, jawne rozstrzygnięcie właściciela w PR #1222. Strona
przepisu i tryb gotowania traktują `no_amount` INACZEJ i tak ma zostać.
To nie jest rozjazd do naprawienia ani przeoczenie po scaleniu — jest to
decyzja, i jest zapisana tutaj właśnie po to, żeby następna osoba nie wzięła
jej za usterkę i nie „ujednoliciła" dwóch ekranów jednym commitem.

**Strona przepisu (`resources/views/pages/recipes/show.blade.php`) nie dopisuje
niczego.** Ten ekran jest tekstem autora — co do znaku. „Sól do smaku",
„mleko ile weźmie", „olej do smażenia" to zdania, które człowiek napisał
świadomie, i serwis nie dokłada do nich swoich słów. Każdy dopisek o dozowaniu
byłby zgadywaniem za autora: „mleko ile weźmie" mówi o konsystencji ciasta,
„olej do smażenia" o zastosowaniu — żadne z nich nie jest doprawianiem, a to
właśnie mierzyło #878. Pilnuje tego
`tests/Feature/SkladnikBezIlosciTest::test_przepis_zachowuje_tekst_autora_bez_dopisywania_sposobu_dozowania`,
porównując wiersze listy znak w znak.

**Tryb gotowania (`resources/views/pages/recipes/cooking.blade.php`) dopisuje
„— do smaku".** Ten ekran nie jest tekstem autora, tylko widokiem roboczym:
człowiek stoi przy garnku, zerka znad patelni i ma jedną rękę wolną. Gołe
„sól" w rozwiniętej liście wygląda w tej sytuacji jak brak informacji — jak
coś, co się zgubiło po drodze — i wysyła gotującego z powrotem na stronę
przepisu, żeby sprawdził, czy czegoś nie brakuje. Dopisek jest tam po to, żeby
jednoznacznie powiedzieć „nic nie zginęło, sypnij ile lubisz", i nie musi być
dosłownym cytatem z autora, bo ten ekran niczego nie cytuje. Pilnuje tego
`tests/Feature/CookingModeTest::test_skladniki_pokazuja_grupy_i_do_smaku`.
Warunek z #44 zostaje: dopisku nie ma, gdy autor sam napisał „do smaku"
w tekście składnika, żeby nie wyszło „sól do smaku — do smaku".

**Trzecia treść odpada.** PR #1222 proponował jedno neutralne „— bez podanej
ilości" na obu ekranach, w nowym wspólnym komponencie
`resources/views/components/wiersz-skladnika.blade.php`. Komponent nie miał
wołającego — żaden widok go nie renderował — a jego test
`WierszSkladnikaJedenKontraktTest` pilnował treści sprzecznej z OBOMA
istniejącymi testami naraz: wymagał dopisku tam, gdzie #878 wymaga jego braku,
i innego dopisku tam, gdzie #764/#1197 wymaga „do smaku". Oba pliki zostały
z gałęzi usunięte. Samo słowo „bez podanej ilości" jest zresztą nadal
zgadywaniem — mówi czytelnikowi, że czegoś na ekranie nie ma, zamiast pomóc
mu gotować.

**Konsekwencja dla przyszłych zmian.** `SkladnikBezIlosciTest`
i `CookingModeTest` pilnują DWÓCH RÓŻNYCH zachowań i żadnego z nich nie wolno
osłabić ani skasować „dla spójności". Czerwień jednego z nich po wprowadzeniu
wspólnego komponentu nie jest dowodem, że test jest zły — jest dowodem, że
komponent zgubił tę różnicę. Jeden wspólny wiersz składnika jest dopuszczalny
tylko wtedy, gdy rozróżnia ekran-cytat od ekranu-roboczego, i tylko po
ponownej decyzji właściciela.
