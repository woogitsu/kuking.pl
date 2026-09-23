# Uwagi audytu — gałąź `gpt/zawieszone-konto` (#926)

## 2026-09-20 20:42Z — uwagi audytu, NIE polecenie

Decyzja, co z nimi zrobić, należy do autora i koordynatora.
Pełny kontekst: `_wspolne/skrzynka/meldunki/AUDYT-2026-09-20-2042.md`.

### Z15 — WAGA ŚREDNIA — punkt 3: wpis do `docs/DECISIONS.md` ogłasza decyzję właściciela, której źródła nie umiem potwierdzić

Commit `2c561ce0` dopisuje do `docs/DECISIONS.md`:

> „## Decyzja właściciela #926 — prywatne czynności podczas zawieszenia (20 września 2026)
> Właściciel wybrał wariant 2: […]”

Czego nie umiem potwierdzić z odczytu:

- w `skrzynka/zlecenia/gpt-zawieszone-konto.md` nie ma słów „wariant”, „właściciel”
  ani „decyzja” — nie znalazłem tam tego wyboru;
- meldunek stanowiska z 21:49:16 (+02:00) mówi wprost: „sprawdziłem plik
  `zlecenia/gpt-zawieszone-konto.md` — jeszcze nie istnieje”;
- inne stanowiska tego dnia **odmówiły** wpisywania decyzji właściciela do dziennika
  (np. `gpt/offline-obietnica`: „Nie dopisuję tego jako zatwierdzonej decyzji do
  `docs/DECISIONS.md`”), więc konwencja jest tu asymetryczna.

**Nie twierdzę, że decyzji nie było** — mogła paść w treści zlecenia, którego nie
widzę. Twierdzę, że **dziennik decyzji niesie teraz atrybucję, której nie da się
odtworzyć z repozytorium ani ze skrzynki**. To rzecz do jednego zdania potwierdzenia
od koordynatora, nie do poprawiania kodu.

Drobiazg przy okazji: wpis nie ma numeru `D-…`, choć wszystkie sąsiednie mają
(ostatni to `D-224`). Jeśli to celowe rozróżnienie „decyzja właściciela” od
„decyzja projektowa”, warto to gdzieś zapisać, bo inaczej następny audyt
zgłosi to ponownie.

### Czego szukałem i NIE znalazłem — to też jest wynik

- **Publikacja w czasie zawieszenia tylnymi drzwiami: sprawdzone, czysto.**
  Dopuszczenie `collections.store` podczas zawieszenia mogło otworzyć tworzenie
  **publicznego** zeszytu. Nie otworzyło: `CollectionController::store()` przekazuje
  widoczność do polityki (`$this->authorize('create', [Collection::class, $data['visibility']])`),
  a `CollectionPolicy::create()` zwraca
  `$user->isActive() || ($user->isSuspended() && $visibility === 'private')`.
  Pokrywa to `test_suspension_does_not_allow_public_notebook_publication_or_foreign_notebook`.
- **Strażnik `KazdaTrasaZIdentyfikatoremPodPolicyTest` został ROZSZERZONY, nie osłabiony** —
  dodano `cooking.reset` z macierzą dostępu `[$W, $O, $O, $O, $O]`. Właściwy kierunek.
- **Memoizacja blokad w `UserPolicy` (punkt 8):** sprawdziłem, czy `follow` bywa
  autoryzowane poza HTTP — tam `request()` żyje długo i pamięć podręczna mogłaby się
  zestarzeć. Jedyne wywołanie to `SocialController:31`. Dodatkowo
  `test_follow_permission_refreshes_between_reads_and_write` jest dokładnie kontrolą
  dodatnią tego rozwiązania. **Bez zastrzeżeń.**
- **Punkt 8 — „poprawne dane nigdy nie znikają”:** pokryte przez
  `test_private_notebook_choice_and_validation_preserve_entered_data`.
- **Punkt 8 — `$fillable`:** gałąź nie rusza `app/Models/`.
- **Punkt 6:** brak migracji.
- **Punkt 9:** w dodanych wierszach brak wzorców z listy pułapek powłoki.
