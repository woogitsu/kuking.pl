# Komunikat limitu odczytu zdjęcia (#2648)

Zapisane zlecenie ma kod `limit_osoby`, który historycznie nie rozróżnia
dnia od miesiąca. Nie przypisujemy mu po czasie przyczyny odmowy. Ekran
odczytuje **obecny** licznik osoby; miesiąc ma pierwszeństwo, gdy oba
okresy są wyczerpane. Po odnowieniu limitu oraz bez kontekstu konta pokazuje
neutralny tytuł i instrukcję ponowienia. Nie gwarantuje dostępności modelu ani
globalnego budżetu AI. Zdjęcie i ręczna edycja szkicu pozostają dostępne.

Ten sam priorytet okresu obowiązuje w ostrzeżeniu przed wysłaniem zdjęcia.
Bramka rezerwacji i księga prób nie zmieniają się. Odczyt licznika używa
istniejących indeksów `(user_id, created_at)` w `proby_importu` i
`importy_przepisow`: przy wyczerpanym miesiącu wykonuje dwa `COUNT`,
przy samym dniu cztery (każdy okres obejmuje księgę i starsze zlecenia
bez wpisu w księdze). Dodatkowe odczyty na ekranie postępu występują tylko
dla zlecenia zatrzymanego na osobistym limicie; pozostałe stany ich nie robią.

Regresje HTTP sprawdzają wysłanie i zapis szkicu, ostrzeżenie formularza,
oba limity, obie strony północy w Warszawie przy odnowieniu doby i miesiąca
oraz neutralny starszy kod. Kontrola
ujemna przywraca obietnicę „jutro” w komunikacie zapisanego zlecenia.

Wycofanie: cofnąć commit. Nie ma migracji ani zmian danych; stary komunikat
ponownie będzie mylił miesięczny limit z dziennym.
