# Bramka pomiaru kafla dodawania — regresja #484

Pomiar przeglądarkowy w `scripts/kafel-dodawania.mjs` sprawdza 15 wariantów.
Dotychczas raportował sześć kategorii naruszeń, ale nie ustawiał dla nich
błędu procesu. Zielony wynik CI nie gwarantował więc poprawnego kafla.

Każda niepusta kategoria kończy teraz proces kodem 1: kafel wyższy od okna,
zasłonięcie przez nakładkę, cel mniejszy niż 48 × 48 px, tytuł lub podpis
poniżej 18 px oraz poziome przewijanie. Wcześniejszy błąd techniczny
i niepełne zebranie pomiarów nadal zatrzymują skrypt.

## Sprawdzenie

`node scripts/kafel-dodawania-bramka.test.mjs` uruchamia rzeczywistą końcową
bramkę skryptu w oddzielnych procesach Node, podając dane pomiarowe.
Sprawdza poprawny zestaw, sześć kategorii, obie nakładki, oba wymiary
celu, wcześniejszy błąd oraz niepełny i pusty zestaw.

Kontrola ujemna usuwa kolejno każdą kategorię z kopii źródła poza repo.
Każda mutacja odtwarza fałszywy sukces, który oblewa asercję.
Kopia jest przywracana przez `cp`, a sumy MD5 przed mutacją i po
przywróceniu muszą być identyczne. Test potwierdza też nienaruszenie źródła.

To test decyzji o kodzie procesu, a nie geometrii strony. W CI obok niego
nadal działa rzeczywisty pomiar przeglądarkowy wszystkich 15 wariantów.
Zmiana nie modyfikuje interfejsu ani kryteriów dostępności.
