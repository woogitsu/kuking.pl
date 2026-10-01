## D-230 — Złożenie `zeszyty` i `jedna-droga`: pytanie na ekranie globalnym wraca, komunikat mówi prawdę o notatce (#775, D-224, D-231, 21 września 2026)

*Ta decyzja nosiła najpierw numer D-229. Straciła go, bo tego samego dnia
dwaj agenci floty niezależnie dostali od właściciela informację, że „pierwszy
wolny numer to D-229" — jeden z nich (gałąź `gpt-n1-powiadomienia`) zajął go
jako pierwszy. Ponieważ ta gałąź miała mniej odwołań do numeru (9 wobec 14 w
`gpt-n1-powiadomienia`), koszt przenumerowania był tu niższy, więc numer
D-229 zostaje przy tamtej decyzji, a ta dostaje D-230.*

> **Sprostowane 22 września 2026 — patrz D-242.** Dwa rozstrzygnięcia poniżej
> przestały obowiązywać, bo przestała być prawdziwa przesłanka, na której obie
> stały: że „`detach()` kasuje notatkę i żadna droga powrotu jej nie odtwarza".
> Rdzeń z #1110 dołożył `restore()`, które przywraca zdjęte wiersze RAZEM
> z notatką i pierwotną datą zapisu. Dlatego:
>
> - **pytanie przed akcją na stronie przepisu (`<x-confirm-button>`) zostało
>   zdjęte** — wyjęcie jest teraz odwracalne w całości, więc wraca reguła
>   D-224 (nie pytamy przed czynnością, którą da się cofnąć), a zakres stoi
>   napisany NAD przyciskiem zamiast w pytaniu;
> - **zdanie „notatka przy nim już nie wróci" zostało zastąpione** zdaniem,
>   które obiecuje przywrócenie — bo notatka wraca.
>
> Reszta tej decyzji — droga wyjęcia na każdym ekranie ze stanem zapisu, jeden
> przycisk na ekran (D-231), liczba zeszytów w komunikacie — obowiązuje dalej.

Dwie gałęzie floty rozwiązały ten sam spór (#775) inaczej i obie miały rację
w jednej połowie. `zeszyty` dodała na stronie przepisu `<x-confirm-button>`
z pytaniem „czy na pewno ze wszystkich zeszytów", ale nie dotknęła
`post-card.blade.php` — na karcie wpisu poza zeszytem nie było żadnej drogi
wyjęcia (`WpisDaSieWyjacZZeszytuTest` obalał to na 4 z 12 scen). `jedna-droga`
dała tę drogę wszędzie i rozstrzygnęła D-231 (jeden przycisk na ekran, zakres
wybiera ekran, licznik zeszytów w komunikacie), ale przy okazji cofnęła
pytanie przed akcją na stronie przepisu — bo D-224 uznało wyjęcie z zeszytu za
w pełni odwracalne.

**Właściciel rozstrzygnął: żadna z tych prac osobno nie zamyka #775, razem
zamykają.** Bierzemy oba mechanizmy:

1. **Z `jedna-droga`**: drogę wyjęcia na każdym ekranie pokazującym „Masz to
   w zeszycie" (D-231 bez zmian) — `post-card.blade.php` dostaje przycisk
   lokalny w środku zeszytu i globalny poza nim, liczbę zeszytów w komunikacie
   (`Odmiana::rzeczownik()`), i „Zapisz ponownie" jako drogę powrotu, która
   wraca DOKŁADNIE tam, skąd wyjęto (`pola['collection_id']`).
2. **Z `zeszyty`**: `<x-confirm-button>` na stronie przepisu, jedynym ekranie
   o zasięgu GLOBALNYM (wyjmuje ze WSZYSTKICH zeszytów naraz).

**Dlaczego pytanie wraca tylko tam.** D-224 miało rację, że pytanie przed
KAŻDĄ odwracalną czynnością uczy odklikiwania. Ale wyjęcie globalne nie jest
w pełni odwracalne: `SavePostToCollection::remove()` i
`SaveRecipeToCollection::remove()` wołają `detach()`, który kasuje wiersz
pivotu RAZEM z `note` (`withPivot(['note'])`). „Zapisz ponownie" przywraca
sam fakt bycia w zeszycie — nie treść notatki, która przy nim stała. To jest
różnica jakościowa, nie kosmetyczna: przy zasięgu lokalnym (jeden, wybrany
zeszyt) ryzyko jest małe i znane z kontekstu ekranu, ale przy zasięgu
globalnym człowiek może stracić notatki w zeszytach, o których w tej chwili
nie myśli. Stąd pytanie PRZED akcją zostaje wyłącznie na ekranie globalnym,
a lokalne wyjęcie (D-231) zostaje jednym kliknięciem bez pytania.

**Komunikat po akcji przestaje obiecywać więcej, niż daje.** Obie gałęzie
pisały po usunięciu „Nie usunęliśmy go z serwisu — możesz go zapisać
ponownie", co sugerowało pełną odwracalność. Nowe brzmienie
(`CollectionController::komunikatPoWyjeciu()`):

- zakres lokalny: „{Przepis/Wpis} wyjęty z zeszytu „{nazwa}”. Możesz zapisać
  go ponownie, ale notatka przy nim już nie wróci."
- zakres globalny, N zeszytów: „{Przepis/Wpis} wyjęty z {N} Twoich zeszytów.
  Możesz zapisać go ponownie, ale notatka przy nim już nie wróci."
- zakres globalny, jeden zeszyt: „{Przepis/Wpis} wyjęty z zeszytu. Możesz
  zapisać go ponownie, ale notatka przy nim już nie wróci."

Zachowanie się nie zmienia — `remove()` i `detach()` robią dokładnie to samo,
co przed tą decyzją. Zmienia się wyłącznie zdanie: mówi teraz, co się NIE
wraca, zamiast sugerować, że wraca wszystko.

**Testy dwóch gałęzi wzajemnie się wykluczały** (`zeszyty` wymagała
`<details class="confirm">` na stronie przepisu, `jedna-droga` wymagała jego
braku) — złożone dają jeden zestaw sprawdzający stan docelowy:
`UsuniecieZZeszytuMaZakresTest::test_strona_przepisu_pyta_przed_usunieciem_i_nazywa_zakres_po_akcji`
zastępuje obie sprzeczne sceny i dokłada kontrolę dodatnią
(`test_strona_przepisu_nie_usuwa_zwyklym_delete_bez_potwierdzenia`).
`WpisDaSieWyjacZZeszytuTest` (issue #776, D-231) zostaje bez zmian zachowania
— dotyczy wyłącznie wpisów (Post), których ekran przepisu (Recipe) nie
obejmuje.

Dowody: `tests/Feature/WpisDaSieWyjacZZeszytuTest.php`,
`tests/Feature/UsuniecieZZeszytuMaZakresTest.php`,
`resources/views/pages/recipes/show.blade.php`,
`app/Http/Controllers/CollectionController.php`.
