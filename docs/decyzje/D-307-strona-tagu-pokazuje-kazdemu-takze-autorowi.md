## D-307 — Strona tagu pokazuje każdemu, także autorowi, tylko wpisy publiczne (#1338, 26 września 2026)

**Decyzja właściciela z 26 września 2026.**

**Reguła.** `/tag/{slug}` pokazuje KAŻDEMU widzowi — gościowi, zalogowanej
osobie, obserwującej autora i samemu autorowi — wyłącznie opublikowane wpisy
publiczne od aktywnych autorów. Własne wpisy „tylko dla obserwujących”
i „tylko dla mnie” nie pojawiają się na stronie tagu autorowi; wpisy „tylko
dla obserwujących” nie pojawiają się tam obserwującym. Zapowiedź przepisu
idzie tą samą bramką z `null` — własny niepubliczny przepis autora też nie
wypływa. Blokady (w którąkolwiek stronę) dalej odcinają publiczne wpisy
przez `Post::widoczneDla($widz)`.

**Dlaczego.** Strona tagu jest miejscem publicznym i indeksowanym. Jeden
zakres dla listy, licznika w spisie (D-087) i warunku indeksowania (#1007)
sprawia, że wszystkie trzy znaczą to samo; autor ma swoje niepubliczne wpisy
w „Moje wpisy” (w „Moje”, D-328).

**Skutek dla #681/#1392.** Zdanie „Jeden Twój wpis z tym tagiem widzisz
tylko Ty…” zostaje, liczone osobnym zapytaniem ograniczonym do wpisów widza,
i mówi teraz, że taki wpis się tu nie pojawia, z odnośnikiem do „Moje wpisy”
(D-328) — listy wszystkich własnych wpisów z widocznością opisaną słowami.

**Odwraca** pierwszy commit PR #1845, który przypinał odwrotną regułę
(własne wpisy w każdej widoczności na stronie tagu).

**Nie dotyczy** feedu obserwowanych tagów na Starcie (`TagFeed`) — tam
zostaje `widoczneDla($widz)` z `published()` (#1561).

**Pilnują:** `FeedTagowTylkoOpublikowaneTest::test_strona_tagu_pokazuje_kazdemu_tylko_wpisy_publiczne_takze_autorowi`,
`TagiObserwowanieTest::test_obserwujacy_nie_widzi_na_stronie_tagu_wpisu_dla_obserwujacych`,
`StronaTaguBramkaPrzepisuTest::test_obserwujaca_i_autorka_widza_tylko_zapowiedz_publicznego_przepisu`,
`ZeroWpisowWSpisieTagowTest`.
