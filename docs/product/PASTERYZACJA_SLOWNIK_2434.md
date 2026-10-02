# Objaśnienie pasteryzacji — poprawka #2434

## Stan od 2.10.2026: hasło usunięte

Decyzją właściciela z 2.10.2026 (wiersz #2343 w D-333) hasła „Pasteryzować”
**nie ma w słowniku** do czasu przeglądu merytorycznego z jawnie zapisanym
źródłem i datą. Krok z „pasteryzować” albo z „mlekiem pasteryzowanym” nie
pokazuje żadnego objaśnienia. Pilnuje tego
`TerminyKulinarneWTrybieGotowaniaTest::test_krok_z_pasteryzacja_nie_pokazuje_objasnienia_do_czasu_przegladu`
(marker `PASTERYZACJA_USUNIETA_DO_PRZEGLADU`), a kontrola ujemna
„Pasteryzacja wraca do słownika bez przeglądu (#2434)” dopisuje hasło
z powrotem. Uwaga pod słownikiem zostaje bez zmian
(`test_uwaga_pod_objasnieniami_nie_obiecuje_oceny_bezpieczenstwa_ani_parametrow_autora`,
marker `PASTERYZACJA_UWAGA_NIE_GWARANTUJE`). Niżej opis poprawki z 1.10.2026,
zostawiony jako historia.

## Błąd i zakres (1.10.2026)

Przegląd z 1.10.2026 wykazał, że hasło „Pasteryzować” polecało zwykły
piekarnik i uznawało parametry dowolnego autora przepisu za wystarczające.
Nie jest to bezpieczna ogólna instrukcja utrwalania przetworów. Poprawka
dotyczy objaśnienia słowa oraz uwagi pod słownikiem; nie zmienia przepisu
użytkownika, metody rozpoznawania słów ani ustawień widoczności słownika.

Nowe objaśnienie odsyła do przebadanych zaleceń dla konkretnego produktu
i składu. Odradza utrwalanie napełnionych słoików w zwykłym piekarniku
i wyjaśnia, że sama gorąca woda nie wystarcza dla wszystkich przetworów.
Nie podaje uniwersalnego czasu ani temperatury. Uwaga pod słownikiem
wyraźnie oddziela objaśnienie słowa od oceny bezpieczeństwa przepisu.

## Źródła sprawdzone 1.10.2026

- [National Center for Home Food Preservation: Equipment and Methods Not Recommended](https://nchfp.uga.edu/how/can/general-information/equipment-and-methods-not-recommended/)
  odradza utrwalanie napełnionych słoików w zwykłym piekarniku i wymaga
  przestrzegania przebadanego procesu wraz z przygotowaniem produktu.
- [National Center for Home Food Preservation: For Safety’s Sake](https://nchfp.uga.edu/how/can/general-information/for-safetys-sake/)
  wskazuje, że przetwory o niskiej kwasowości wymagają odpowiedniej obróbki
  ciśnieniowej; kąpiel we wrzącej wodzie nie jest dla nich wystarczającą
  metodą. Zalecenia zależą od produktu i sposobu przygotowania.

To weryfikacja konkretnego błędu, nie odbiór całego słownika. Przegląd
merytoryczny przez człowieka i decyzja o udostępnieniu pozostają w #2343.

## Dowody

- Test rzeczywistego HTTP `TerminyKulinarneWTrybieGotowaniaTest` sprawdza
  render czterech odmian słowa, ostrzeżenie, uwagę i zachowanie oryginalnej
  instrukcji użytkownika.
- Dwie kontrole ujemne w `kontrole-negatywne-alfa08.py` przywracają stare
  objaśnienie i starą uwagę. Wymagają odpowiednio markerów
  `PASTERYZACJA_BEZ_PIEKARNIKA` i `PASTERYZACJA_UWAGA_NIE_GWARANTUJE`.
- Lokalnie uruchomiono rzeczywistą klasę słownika przez PHP z mbstring:
  cztery odmiany i kontrakt opisu przeszły; izolowany mutant starego opisu
  oblał z oczekiwanym markerem, a oryginał ponownie przeszedł. Kotwice obu
  mutacji są jednoznaczne. Składnia PHP/Python i `git diff --check` przeszły.
- Test HTTP/Blade i obie kontrole ujemne wymagają pełnego CI z PostgreSQL
  18; nie wykonano ich lokalnie z powodu braku zależności i testowej bazy.

## Ryzyko i cofnięcie

Bez migracji, zmiany danych i zależności. Układ natywnego `details` oraz
tekst minimum 18 px pozostają. Techniczne cofnięcie odbywa się przez revert,
ale nie należy przywracać niebezpiecznej porady; przy wycofaniu funkcji
należy wyłączyć słownik albo usunąć wadliwe hasło.
