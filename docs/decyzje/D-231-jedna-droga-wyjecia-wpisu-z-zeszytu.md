## D-231 — Jedna droga wyjęcia wpisu z zeszytu, a zakres wybiera ekran (#775, #776 + D-224, 20 września 2026)

Dwie prace powstały równolegle i nie wiedziały o sobie. #789 (D-224) dało
przycisk wyjęcia wszędzie tam, gdzie widać stan zapisu, o zakresie GLOBALNYM
(wszystkie zeszyty widza). #776 dało przycisk wyjęcia o zakresie LOKALNYM
(`collection_id`), ale tylko wtedy, gdy karta stoi w środku zeszytu, którego
widz jest właścicielem. Złożone wprost renderowały się OBOK SIEBIE: na ekranie
zeszytu stały dwa przyciski o prawie identycznych nazwach — „Usuń z zeszytu"
i „Usuń z tego zeszytu" — i różnym zasięgu. Dla grupy 50+ to gorsze niż brak
którejkolwiek drogi: zły wybór kosztuje tu dane w zeszytach, o których nikt
w tym momencie nie myślał.

**Zakres wybiera EKRAN, nie człowiek.**

1. W środku konkretnego zeszytu, gdy widz jest jego właścicielem, stoi
   wyłącznie **„Usuń z tego zeszytu"** — `collection_id` wskazuje ten zeszyt,
   zapis w pozostałych zeszytach zostaje razem z notatką i datą (#775).
   Odnośnik „Masz to w zeszycie" w tym miejscu znika: prowadzi do listy
   zeszytów, a człowiek stojący W zeszycie już wie, że wpis tam leży.
2. Poza zeszytem — w strumieniu, na profilu, w wyszukiwarce, na stronie wpisu
   i na stronie przepisu — stoi wyłącznie **„Usuń z zeszytu"** o zakresie
   globalnym, obok odnośnika „Masz to w zeszycie" (D-224). Nie ma tam „tego
   zeszytu", do którego dałoby się odnieść.
3. **Nigdy oba naraz.** Dowodem jest scena
   `WpisDaSieWyjacZZeszytuTest::test_na_ekranie_jest_dokladnie_jedna_droga_wyjecia`
   — liczy formularze wyjęcia na obu ekranach i sprawdza, że nazwa tej drugiej
   drogi nie pada tam wcale.

**Potwierdzenie PRZED akcją nie wraca.** #775 dokładało na stronie przepisu
`x-confirm-button` z pytaniem „czy na pewno ze wszystkich zeszytów". D-224
rozstrzygnęło odwrotnie i to rozstrzygnięcie zostaje: wyjęcie z zeszytu jest
odwracalne, a pytanie przed każdą odwracalną czynnością uczy odklikiwania
i psuje wagę pytań przy rzeczach naprawdę nieodwracalnych (kasowanie wpisu,
kasowanie zeszytu). Strona przepisu wraca więc do zwykłego formularza DELETE.

**Ale zarzut #775 był słuszny i jest spełniony inaczej.** Brzmiał „usuwa ze
wszystkich zeszytów BEZ UJAWNIENIA ZAKRESU", nie „usuwa bez pytania". Zakres
nazywa więc komunikat PO akcji, i nazywa go LICZBĄ FAKTYCZNĄ:
`SavePostToCollection::remove()` i `SaveRecipeToCollection::remove()` oddają,
z ilu zeszytów naprawdę wyjęto.

- zakres lokalny: „Wpis wyjęty z zeszytu „Obiady". Nie usunęliśmy go
  z serwisu — możesz go zapisać ponownie."
- zakres globalny, kilka zeszytów: „Wpis wyjęty z 3 Twoich zeszytów. …"
- zakres globalny, jeden zeszyt: „Wpis wyjęty z zeszytu. …" — bo zdanie
  o „wszystkich Twoich zeszytach" przy jednym zeszycie straszy bez powodu,
  a straszenie bez powodu uczy ignorowania komunikatów tak samo jak pytanie
  bez powodu.

**Droga powrotu wraca TAM, SKĄD WYJĘTO.** „Zapisz ponownie" (D-224) dostaje
`pola` — po wyjęciu lokalnym niesie `collection_id` tego zeszytu. Bez tego
cofnięcie odkładałoby wpis do zeszytu DOMYŚLNEGO, czyli cicho przenosiłoby go
gdzie indziej; cofnięcie ma przywracać stan, nie tworzyć nowy.

**Nazwy.** „Usuń z zeszytu" i „Usuń z tego zeszytu" nigdy nie stoją razem,
więc jedna nie jest pułapką na drugą, a ekran zawsze niesie kontekst. Trzecie
słowo na tę samą czynność („Wyjmij") byłoby złamaniem `BRAND_EXTENDED.md` §3.

**D-081 zostaje w mocy** — tablica „kuKINGi na dziś" dalej świadomie nie
dolicza stanu zeszytu.

Dowody: `tests/Feature/WpisDaSieWyjacZZeszytuTest.php`,
`tests/Feature/ZeszytUsuwaZapisanyWpisTest.php`,
`tests/Feature/UsuniecieZZeszytuMaZakresTest.php`,
`scripts/wyjecie-z-zeszytu.mjs`.
