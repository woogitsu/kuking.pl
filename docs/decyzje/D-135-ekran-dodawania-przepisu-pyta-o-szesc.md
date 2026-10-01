## D-135 · Ekran dodawania przepisu pyta o SZEŚĆ rzeczy, a przepis wolno opublikować bez ani jednego składnika

**Data:** 11 września 2026 · Issue #364 · Zgłosił i zgodził się właściciel · Status: **obowiązuje**

### Zgłoszenie

„te dodawanie przepisów jest zbyt skomplikowane dla mnie, 32 latka który ogarnia
programowanie itp a co dopiero dla seniora", a po obejrzeniu ekranu: „trzeba uprościć
to i usunąć te tysiące pól, przycisków, informacji itp bo seniorzy dostaną oczopląsu".

### Co było na ekranie

Policzone, nie oszacowane: **98 kontrolek** na jednym ekranie dodawania przepisu.
Pola porcji, czasów, trudności, pochodzenia, roku „w rodzinie od", skanu kartki,
grup składników, jednostek, uwag przy składniku, czasów przy kroku — wszystko naraz,
przed pierwszym zdjęciem.

### Decyzja

Ekran dodawania pyta o **sześć** rzeczy: zdjęcie, tytuł, składniki, przygotowanie,
widoczność, przycisk publikacji. Reszta przechodzi na osobny ekran „Dopisz szczegóły",
dostępny **po** opublikowaniu. Limit jest pilnowany testem
(`DodawaniePrzepisuSzescKontrolekTest::LIMIT_KONTROLEK = 6`), a nie dobrą wolą —
inaczej wróciłby po jednym polu naraz.

### Przepis bez składników wolno opublikować — i to jest najtrudniejsza część tej decyzji

Walidacja ma `ingredients` jako `nullable`, a `skladniki_tekst` może być puste.
Test `test_przepis_z_samym_zdjeciem_tytulem_i_tekstem_da_sie_opublikowac` stwierdza
wprost: zero składników, jeden krok, przepis opublikowany — i asercja
„Przepis bez składników nie ma prawa ich sobie dorobić".

Zgłosiłem to właścicielowi jako świadomy koszt: baza przepisów bez składników jest
gorsza do wyszukiwania i do „co mam w lodówce". Odpowiedź brzmiała **„ok daję zgodę"**.

Uzasadnienie, które za tym stoi: **przepis, którego ktoś nie opublikował, ma zero
składników tak samo.** Ktoś, kto zna rosół z głowy, opisze go zdaniem i nie będzie
rozpisywał gramatury — a jeśli wymusimy listę, nie opublikuje nic. Brakujące
składniki da się dopisać później; nieopublikowany przepis nie wraca.

### Zaproszenie do dopisania szczegółów istnieje tylko wtedy, gdy jest co dopisać

`App\Domain\Recipes\CoMoznaDopisac` pyta o dziesięć pól, o zdjęcie główne oraz
o to, czy przepis ma **ani jednego** składnika albo kroku. Komunikat po publikacji
kieruje do „Edytuj" tylko wtedy, gdy odpowiedź brzmi „tak". Przepis wysłany
z wypełnionym wszystkim dostałby inaczej przycisk prowadzący do formularza bez ani
jednego pustego pola — czyli martwy przycisk z D-053.

Reguła stoi w domenie, nie w widoku, bo pyta o nią więcej niż jedno miejsce
i wszystkie muszą odpowiadać tak samo.
