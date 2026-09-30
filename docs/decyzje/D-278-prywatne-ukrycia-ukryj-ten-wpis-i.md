## D-278 — Prywatne ukrycia: „Ukryj ten wpis” i „Ukryj tę osobę”, 30 dni, bez agregacji (#1810, #1781, 25 września 2026)

**Data:** 25 września 2026 · Decyzja właściciela (#1781, kryteria #1810) · Status: **obowiązuje**

### Decyzja

Druga połowa jawnych poleceń widza z listy D-275 („mniej”, obok „więcej” =
obserwuj). Ukrywamy **pojedynczy wpis** i **osobę**; tag później. Domyślnie
**na 30 dni** (`kuking.ukrycia.dni`), po terminie wraca samo; lista
w Ustawieniach → „Ukryte” pokazuje datę końca, „Zostaw ukryte” (bez terminu)
i „Przywróć”. Miejsce: menu trzech kropek, nad „Zgłoś” (wyjątek D-172 się nie
rozszerza).

**Gdzie działa:**

| | Start (Obserwowani) | Odkrywanie | Tablica: wybór gospodarza | Tablica: część automatyczna, propozycje osób | Tygodniowy list | Profil, wyszukiwarka, link |
|---|---|---|---|---|---|---|
| Ukryty wpis | znika | znika | znika | znika | znika | karta zwinięta: „Ten wpis ukrywasz tylko dla siebie. Pokaż” |
| Ukryta osoba | **nie działa** dla osób obserwowanych; **znika** z wpisów „Z tagu: …” | znika | **nie działa** | znika | nie działa | nie działa |

Ukrycie osoby działa wyłącznie tam, gdzie serwis sam podsuwa ludzi. W
Obserwowanych nic nie znika poza bramkami, blokadami i tym, co widz sam
wskazał palcem (pojedynczy wpis albo osoba w gałęzi tagów) — AGENTS.md §8.

> **Dopisek (26 września 2026, decyzja właściciela, #1781).** Wpis ukrytej
> osoby, który przychodzi na Start **wyłącznie przez obserwowany tag**, znika
> — tag podsuwa autora, którego widz nie wybrał (`FollowingFeed`: gałąź tagów
> z `bezUkrytychOsob`). Wpisy osób obserwowanych wprost są zawsze widoczne,
> także z obserwowanym tagiem. Test:
> `UkryjWpisIOsobeTest::test_ukryta_osoba_znika_ze_startu_takze_przez_obserwowany_tag`. Kogoś, kogo się obserwuje,
się nie ukrywa: menu pokazuje wtedy „Przestań obserwować”, a akcja odmawia.
Wybór gospodarza to oznaczony wybór, nie podsunięcie — ukrycie osoby go nie
zdejmuje (ukrycie konkretnego wpisu — tak).

**Słowa.** Każdy komunikat mówi „tylko dla Ciebie”, bo „ukryj” ma w serwisie
drugie znaczenie (moderacja ukrywa treść wszystkim). Po akcji `status_powrot`
z „Cofnij”, bez „czy na pewno”. Osobę ukrywa się przez ekran wyboru (GET +
POST, bez JS) z nazwą dosłownie, zakresem, „Nie powiadamiamy tej osoby”
i linią „Ktoś Ci dokucza? … Zablokuj … albo zgłoś”.

**Bez agregacji** (EROD 3/2025 pkt 95). Ukrycia nie wpływają na zasięg
autora, nie trafiają do moderacji ani analityki, autor nie dostaje
powiadomienia. „Ugotowałem” dalej powiadamia autora ukrytego przez kucharza —
ukrycie nie jest czwartym wyjątkiem z AGENTS.md §1. Ostrzeżenie „ukrywasz już
co najmniej jedną trzecią osób, które ostatnio coś pokazały” liczy się
z ukryć TEGO widza i publicznej aktywności autorów
(`kuking.ukrycia.prog_ostrzezenia`, `okno_aktywnosci_dni`).

**Dane.** Tabela `hides` (docs/DATABASE.md), rollback odmawia przy aktywnych
ukryciach (D-088). Eksport: sekcja `ukryte` (co, do kiedy); kto ukrył to
konto — na żądanie z uzasadnieniem art. 15 ust. 4, jak blokady.

Pusty stan Odkrywania (D-276) liczy teraz blokady i aktywne ukrycia i prowadzi
do listy „Ukryte”.

### Zdanie do strony „Jak dobieramy wpisy” (#1811)

> Możesz ukryć pojedynczy wpis albo osobę — tylko dla siebie, domyślnie na 30
> dni. Ukryty wpis znika z Twoich list; ukryta osoba znika z miejsc, w których
> sami podsuwamy Ci ludzi. Nikogo o tym nie powiadamiamy i nie liczymy, ile
> osób kogoś ukryło. Wszystko cofniesz w Ustawieniach → Ukryte.

### Wycofanie

Wycofanie funkcji wymaga decyzji, co z aktywnymi ukryciami (rollback migracji
odmawia). Kod: `app/Domain/Ukrycia`, `UkryciaController`, zakresy w `Post`
i `User`, filtry w `FollowingFeed`, `DiscoverFeed`, `DailyBoard`,
`ZbierzTresciDigestu`.

📄 `app/Domain/Ukrycia/Ukrycia.php` · `app/Http/Controllers/UkryciaController.php` ·
`tests/Feature/UkryjWpisIOsobeTest.php` · `tests/Feature/CofniecieMigracjiUkrycNieOdslaniaTest.php` · D-275 · D-276
