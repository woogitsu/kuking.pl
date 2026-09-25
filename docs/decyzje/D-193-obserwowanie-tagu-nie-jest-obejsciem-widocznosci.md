## D-193 · Obserwowanie tagu nie jest obejściem widoczności przepisu

**Data:** 12 września 2026 · PR #465 · issue #464 · Status: **obowiązuje**

### Co było nie tak

`TagFeed` filtrował wpisy przez `widoczneDla($viewer)` i robił to poprawnie. Ale
zapowiedź przepisu (issue #368) jest na stałe `public` — widoczność ma trzymać
**przepis**, nie jego zapowiedź. Filtr po widoczności **wpisu** przepuszczał więc
zapowiedź przepisu, którego widz zobaczyć nie miał prawa.

`FollowingFeed`, `DiscoverFeed` i `DailyBoard` mają na to `zWidocznymPrzepisem()`
od issue #368. `TagFeed` był jedynym z czterech bez tej bramki — i jednocześnie jedynym,
do którego wpisy trafiają **bez żadnej relacji między widzem a autorem**. Wystarczyło
obserwować ten sam tag.

### Co wyciekało

Nie sam przepis — w niego nie dało się wejść, `RecipePolicy` trzyma. Wyciekał **tytuł**
i **zdjęcie główne**, czyli to, co karta rysuje bez pytania o zgodę. Dla przepisu „tylko
dla obserwujących" to cała treść widoczna z zewnątrz.

Zmierzone na `/home` oczami osoby, która autora nie obserwuje: pełna karta ze zdjęciem,
tytułem, przyciskiem „Ugotowałem" — i plakietką **„Tylko dla obserwujących"** pod
spodem. Karta uczciwie pisała, że to treść dla obserwujących, pokazując ją komuś, kto
nie obserwuje.

### Decyzja

Widoczność treści wskazywanej przez wpis jest **osobną bramką** i musi stać w każdym
zapytaniu, które taki wpis wydaje. Filtr po widoczności samego wpisu jej nie zastępuje
i nigdy nie zastępował — po prostu w trzech strumieniach z czterech stały obok siebie.

`maTresci()` dostaje ten sam warunek co `paginate()`. Gdyby pytała szerzej,
odpowiadałaby „jest co pokazać" o treści, której `paginate()` i tak nie odda, a widz
dostałby pusty strumień zamiast ekranu pustego stanu, który mówi, co zrobić dalej.

### Czego ta decyzja nie zmienia

Świadomy wyjątek w `DailyBoard` (bramka pominięta w agregacie „kto ostatnio
publikował", ze zmierzonym powodem w komentarzu) stoi dalej. Dotyczy wyłącznie
**kolejności** propozycji, nie tego, co widać — pokazuje o jedno konto za dużo, nigdy
o jedną treść za dużo.

📄 `app/Domain/Feed/TagFeed.php` · `FeedTagowNiePokazujeCudzegoPrzepisuTest` ·
`FeedTagowNieGubiKolumnPrzepisuTest` · `app/Models/Post.php`
