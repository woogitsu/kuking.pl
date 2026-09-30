## D-261 — Wykonanie zbanowanego kucharza znika także pod bezpośrednim adresem (25 września 2026)

**Data:** 25 września 2026 · Audyt A5-07 (dawniej B-03) · Status: **obowiązuje,
wariant bezpieczniejszy — do potwierdzenia przez właściciela**

**Co.** `CookedEventPolicy::view()` odmawia obcym, gdy kucharz nie jest
`jestDostepnyJakoAutor()` — czyli przy `banned` tak samo jak przy
`pending_delete`. Ta sama polityka pilnuje zdjęć wykonania
(`DostepDoZdjecia`). Moderator i sam kucharz przechodzą jak dotąd.
Dane nie są zmieniane: po `reinstate()` wykonanie wraca dla wszystkich.

**Dlaczego.** Pytanie „ile z historii zbanowanego konta zostaje publiczne”
czekało na decyzję. Do tego czasu gość dostawał pod `/ugotowane/{uuid}` 200
z notatką, nazwą i awatarem (zdjęcia z `Cache-Control: public`), a profil tej
osoby, jej przepisy (`RecipePolicy::view()`) i galeria „Komu wyszło” (`CookedEvent::
scopeWidoczneDla()`) — odmowę. Jedno pytanie, dwie odpowiedzi. Wybieramy
odpowiedź zgodną z resztą serwisu i ostrzejszą: łatwiej później otworzyć
niż odwołać treść, która już wyciekła.

**Czego to nie zmienia.** `suspended` dalej przechodzi (ta sama granica co
`jestDostepnyJakoAutor()`). Nic nie jest kasowane; zdanie „treść zostaje,
znika tylko wyróżnienie” z `KomusWyszloWidocznoscTest` znaczy od dziś
„dane zostają w bazie”, a nie „zostają publiczne”.

**Znana granica.** Kopie zdjęć już zapisane w pamięci podręcznej CDN przed
banem wygasają według swojego `Cache-Control` — ta decyzja ich nie czyści.

**Skutki uboczne.** Komentowanie świadomie zostaje — decyzja właściciela
z 25 września 2026 („Nie, komentarze zostają”). Obcy nie otworzy wykonania
zbanowanego kucharza, ale komentarz pod nim przechodzi: `cooked.comment`
i `LockCommentContext` pytają o osobną zdolność `CookedEventPolicy::comment()`,
która różni się od `view()` tylko tym, że ban kucharza nie zamyka rozmowy
(karencja usunięcia, blokada i stan przepisu — jak w `view()`). Lokalna analiza spamu (D-241)
działa dalej: `GranicaWysylki::pozaAutorem()` podmienia w kopii zbanowanego
kucharza na aktywnego, tak jak autora wpisu i przepisu. Do OpenAI taki
komentarz nie wychodzi.

Dowody: `tests/Feature/KarencjaUsunieciaChowaWykonanieTest.php`
(gość, obcy zalogowany, zdjęcie; kontrola dodatnia: moderator i powrót po
zdjęciu bana), `tests/Feature/Visibility/KomusWyszloWidocznoscTest.php`,
`tests/Feature/KomentarzSprawdzaSwiezyStanTest.php` (zbanowany kucharz —
komentarz przechodzi; karencja — odmowa),
`KarencjaUsunieciaChowaWykonanieTest::test_zbanowany_kucharz_nie_zamyka_komentowania`,
`tests/Feature/GranicaWysylkiDoOpenAiTest.php` („wykonanie autor_zbanowany”).
