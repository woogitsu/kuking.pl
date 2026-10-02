# Kartka bez martwych akcji i ciemnej ramy (#2600)

Źródło: syntetyczny artefakt `wydruk-a4-3a77c7437e344fea3e97b929f60c796d4bfc5473`
z PR #2592, run `36955495911`. Na pierwszej stronie `krotki-2-porcje-jasny-gosc.pdf`
widać odnośnik „Pokaż ilości z przepisu”, na drugiej zamknięty zwijacz „Jak to liczymy”.
W `dlugi-2-porcje-ciemny-gosc.pdf` i `pomocnik-dlugi-qr-ciemny-gosc.pdf`
każda strona ma grubą czarną ramę. Ten run był czerwony przez osobne błędy
pomiaru #2592; nie jest dowodem odbioru po poprawce.

Reguły `@media print` ukrywają wyłącznie odnośnik w objaśnieniu przeliczenia
porcji oraz przycisk rozwijania szczegółów szacunku odżywczego. Wybrana liczba
porcji, zdanie o przeliczeniu, sam szacunek i jego zastrzeżenia, składniki,
instrukcje, czas kroku, autor, adres i kod QR pozostają. Ekran nie zmienia się.
Jasne tło i schemat kolorów obejmują również korzeń dokumentu i marginesy A4,
nie tylko `body` oraz jego dzieci.

`scripts/wydruk-przepisu.mjs` mierzy w media print widoczność obu martwych
elementów oraz białe tło i jasny schemat korzenia dla zwykłego przepisu,
kartki pomocnika, zeszytu i kart QR. Fizyczna kontrola ujemna na syntetycznym
przepisie osobno odkrywa odnośnik, zwijacz i przyciemnia korzeń: każda mutacja
musi wywołać własny błąd, a po odtworzeniu pomiar musi przejść. PDF A4 nadal
jest zapisywany do artefaktu CI; zielony wynik DOM nie zastępuje obejrzenia
wszystkich stron PDF z dokładnego nowego SHA.

Nie zmieniamy paginacji długiego zeszytu: w starym artefakcie ostatnia strona
ma tylko ostatni krok i adres. Jej scalenie z poprzednią stroną wymaga osobnego
pomiaru bez zmniejszania pisma ani łamania kroku. Cofnięcie tej poprawki to
przywrócenie dwóch reguł druku i kontroli #2600; dane ani schemat bazy się nie
zmieniają.
