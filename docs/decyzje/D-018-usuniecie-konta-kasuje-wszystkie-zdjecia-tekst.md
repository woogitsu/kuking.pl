## D-018 · Usunięcie konta kasuje wszystkie zdjęcia, tekst zostaje zanonimizowany

**Data:** 6 września 2026 · Status: **obowiązuje** · audyt W4-01, issue #93

Ekran usuwania konta kazał potwierdzić: „Rozumiem, że po 30 dniach moje wpisy,
przepisy i zdjęcia zostaną usunięte na stałe". `EraseAccountData` kasował
jednak wyłącznie zdjęcie profilowe, a resztę — wpisy, przepisy, komentarze
i WSZYSTKIE pozostałe zdjęcia — zostawiał przy zanonimizowanym koncie,
z komentarzem tłumaczącym, dlaczego tak jest lepiej.

To nie był spór o interpretację RODO. To była obietnica złożona konkretnym
zdaniem, pod którym człowiek musiał postawić haczyk, i niedotrzymana.

### Co odrzucono

**Kasowanie wszystkiego, tak jak mówił ekran.** Kod robiłby wtedy dokładnie to,
co obiecuje, bez żadnych gwiazdek — i to jest realna zaleta. Cena: znikają
cudze wątki. Komentarz, na który ktoś odpowiedział, urywa się w połowie.
Przepis, który ktoś ugotował i ma w swoim zeszycie, przestaje istnieć. Przy
społeczności liczonej w dziesiątkach osób to są widoczne dziury, a zabieramy
je ludziom, którzy o nic nie prosili.

**Zostawienie kodu i poprawienie samego ekranu.** Najtańsze. Ale wymagałoby
świadomej podstawy prawnej na trzymanie CZYJEGOŚ ZDJĘCIA po tym, jak ta osoba
poprosiła o usunięcie konta — a takiej podstawy nie ma sensu szukać, skoro
zdjęcie da się skasować bez straty dla nikogo innego.

### Co wybrano

**Zdjęcia kasujemy wszystkie. Tekst zostaje, zanonimizowany.**

Ze zdjęciem jest inaczej niż z tekstem i to jest sedno tej decyzji. Tekst
przepisu po podmianie podpisu przestaje być danymi osobowymi. Zdjęcie nie:
dane są w pikselach — twarz, wnętrze mieszkania, dokument na stole — a
w oryginale jeszcze EXIF z datą, modelem telefonu i miejscem. Anonimizacja
podpisu nie zmienia tam absolutnie niczego.

Kasujemy oryginały, warianty i czyścimy cache CDN-u — bo skasowanie pliku
w buckecie to nie to samo co zniknięcie z internetu (audyt G-03).

**Cena, wprost:** wpis, w którym było zdjęcie, zostaje bez niego. Komponent
`x-photo` pokazuje w takim stanie komunikat, a nie pustą ramkę. Kto chce
usunąć konkretny przepis albo wpis w całości, ma to zrobić sam przed
skasowaniem konta — i ekran mówi mu to wprost.

**Ekran mówi teraz dokładnie to, co kod robi**, w dwóch listach: co znika i co
zostaje. Test wiąże te dwie rzeczy ze sobą, bo raz już się rozjechały i nikt
tego nie zauważył przez kilkanaście commitów.

**Zmiana wymaga:** potwierdzenia prawnika przy okazji weryfikacji regulaminu
(issue #8), gdyby uznał, że zanonimizowany tekst też wymaga innej podstawy.

📄 `app/Domain/Users/Actions/EraseAccountData.php` ·
`resources/views/pages/settings/data.blade.php` · `docs/legal/COMPLIANCE.md` ·
issue #8
