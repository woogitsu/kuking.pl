## D-239 — Wspólny licznik całej poczty i kolejność wygaszania (#732, 22 września 2026)

> Numer: gałąź `fix/732-wspolny-licznik-poczty` niosła tę decyzję jako D-225,
> a ten numer (i D-226, D-227 — numery zajęte na gałęziach, nie na `main`,
> bez własnego nagłówka w tym dzienniku) zajęły w międzyczasie inne decyzje
> na `main`. D-239 to pierwszy numer wolny na `origin/main` i na wszystkich
> gałęziach zdalnych w dniu przeniesienia (reguła D-235: ustępuje gałąź,
> której numeru nie ma jeszcze na `main` — reguła koordynacji numeracji
> między gałęziami, opisana w `docs/flota/MAPA_NUMEROW_DECYZJI.md` i
> `docs/flota/KOLEJNOSC_SCALANIA.md`; D-235 sama nigdy nie scaliła się jako
> osobny wpis, więc pod tym numerem nie szukaj nagłówka w tym pliku).
> Treść to intencja tamtej gałęzi przeniesiona na
> obecny kod, bez części o drodze zgłoszenia DSA (osobna decyzja, nie ta).

Do tej zmiany każda funkcja wysyłająca wiele listów miała własny sufit dobowy
i widziała **tylko swój**, a listy bez sufitu — potwierdzenie rejestracji
i przypomnienie hasła — nie były liczone wcale. Rezerwa transakcyjna (100 listów
z puli 300) istniała wyłącznie jako zdanie w komentarzu `config/kuking.php`
i nic jej nie pilnowało.

Zmierzono dwie dziury tej samej rodziny. `/nie-pamietam-hasla` nie ma ani sufitu
na adres, ani budżetu poczty: `limits.password_reset` to 5 próśb na 10 minut
z adresu IP, czyli 720 na dobę, a każda może iść na **inny** adres. Jeden sprawca
z jednego łącza wysyła listy na 300 różnych skrzynek i opróżnia pulę EmailLabs
300/dobę w około 70 minut. Ponawianie potwierdzenia adresu
(`limits.verification_resend`, 6 na minutę z konta, bez sufitu dobowego) robi to
samo z jednego niepotwierdzonego konta w około 50 minut. W obu przypadkach
pierwszą rzeczą, która przestaje działać, jest **potwierdzenie rejestracji
i logowanie linkiem** — czyli wejście dla nowych ludzi.

**Decyzja właściciela: jeden wspólny licznik poczty dla wszystkich dróg, nie
osobne sufity.** Wpis przy `limits.kontakt_odpowiedz` zapowiadał to wprost —
gdyby taki licznik powstał, ma być **jednym** miejscem tej decyzji. Osobnych
progów przy poszczególnych drogach więc nie dopisujemy.

Licznik jest rozszerzeniem `App\Domain\Security\DziennyBudzetListow`, a nie nową
warstwą nad nią: licznik zagnieżdżony w drugim liczniku jest w tej klasie od
D-085 (zaproszenia leżą wewnątrz budżetu logowania linkiem), a osobna warstwa
oznaczałaby drugą implementację atomowej rezerwacji — czyli drugą kopię reguły.

Sam wspólny licznik nie dokłada ochrony przed przekroczeniem 300; tego pilnuje
dostawca. Dokłada **kolejność wygaszania**, bo odrzucony list przepada (worker ma
trzy próby w sześć minut). Progi w `kuking.poczta.progi_wygaszania` mówią, ile
listów z puli dana klasa ma zostawić nietkniętych:

- **240 — `podsumowanie`, gaśnie pierwsze.** Liczba wynika z rachunku
  300 − `digest.dzienny_limit` (60). Podsumowanie, które nie doszło, jest niczym.
- **100 — `zwykla`.** Równe `poczta.rezerwa_transakcyjna`; próg jest pierwszym
  mechanizmem, który tę rezerwę naprawdę dowozi. Tu stoi **przypomnienie hasła**:
  też jest drogą powrotu na konto, ale prosi o nie ktokolwiek z zewnątrz, na cudzy
  adres, bez dowodu, że adres do niego należy — czyli jest to dokładnie ta droga,
  którą zmierzony sprawca opróżniał pulę. Tu stoi też odpowiedź z „Napisz do nas".
- **0 — `wejscie`, gaśnie ostatnie.** Potwierdzenie rejestracji (także jego
  ponowienie) i logowanie linkiem sięgają po ostatni list doby.

Komunikat po odmowie mówi, **co zrobić teraz**, i ma dwa warianty: pusta pula
(„nie czekaj na niego, spróbuj jutro albo napisz do nas") i ścisk na blokadzie
licznika („kliknij jeszcze raz"). Wzorcem jest komunikat wyczerpanego budżetu
logowania linkiem.

### Zwrot rezerwacji trafia w dobę rezerwacji (#1061)

Przy przenoszeniu naprawiona została wada znana z audytu: `zwolnij()` liczył
klucz z `now()` w chwili zwrotu, więc rezerwacja z 23:59:59 oddana po północy
zdejmowała miejsce z **nowej** doby. Po dołożeniu wspólnego licznika błąd
dotyczyłby dwóch liczników naraz. Obiekt pamięta teraz doby swoich rezerwacji
i oddaje ostatnią do jej własnego klucza; doba jest wyznaczana raz na
sprawdzenie i zajęcie. `zajmij()` wołane wprost (list próbny `--tylko`) liczy się
też we wspólnej puli.

### Czego ta zmiana nie robi — powiedziane wprost

**Nie gwarantuje, że list logowania wyjdzie zawsze.** Chroni klasę `wejscie`
przed biuletynem, przed zalaniem przypomnienia hasła i przed odpowiedziami
moderatora — te drogi nie ruszą ostatnich 100 listów doby. Ale klasa `wejscie`
dzieli te listy **między siebie**: kto zakłada dziesiątki kont (rejestracja jest
otwarta z decyzji właściciela; `limits.register` = 5 na 10 minut z IP) albo
klika „Wyślij wiadomość jeszcze raz" z niepotwierdzonego konta
(`verification_resend` = 6 na minutę), ten nadal może zjeść pulę do zera, a wtedy
link do logowania nie wyjdzie. Człowiek dostaje wtedy jawny komunikat: że listu
nie będzie, żeby nie czekał, że może zalogować się hasłem i gdzie odpisuje
człowiek (`BiuletynNieZabieraListowWejsciaTest`). Osobna klasa albo sufit dla
ponowienia potwierdzenia to osobna decyzja, tutaj świadomie niepodjęta.

Nie dzieli też puli między konkretnych ludzi: jeden sprawca nadal wypali klasę
`zwykla` i zabierze tego dnia odpowiedzi z „Napisz do nas". Poza licznikiem
pozostają listy niskonakładowe z rodziny moderacyjnej (decyzje w sprawie
zgłoszeń, potwierdzenia odwołań, dobowe podsumowanie automatu, eksport danych,
ostrzeżenia o zmianie adresu) — pojedyncze sztuki na dobę, ale dopóki się nie
liczą, wspólna pula pokazuje mniej, niż serwis naprawdę wysłał. To jest znana
i nazwana niedokładność, nie przeoczenie.

📄 `app/Domain/Security/DziennyBudzetListow.php`,
`app/Domain/Security/WyslijPotwierdzenieAdresu.php`,
`tests/Feature/WspolnyLicznikPocztyTest.php`,
`tests/Feature/PodzialLimituPocztyTest.php`,
`tests/Feature/ZwrotRezerwacjiPoPolnocyTest.php`,
`tests/Feature/BiuletynNieZabieraListowWejsciaTest.php`
