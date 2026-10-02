# Wybór „Bez ilości” w porównaniu wersji — #2449

Migawka zapisana od #896 zawiera przy każdym składniku `no_amount` jako
wartość logiczną. Porównanie dwóch jawnych wartości pokazuje zmianę wyboru
oddzielnie od tekstu składnika. Nie dopisuje „do smaku” ani nie zmienia strony
przepisu czy trybu gotowania (D-232).

Starsza migawka może nie mieć tego klucza. Brak jest stanem nieznanym, nie
wartością `false`. Przy sparowanym składniku ekran wymienia ten wybór wśród
danych, których nie da się porównać; pozostałe zmiany składnika nadal pokazuje.
Jeśli oba zapisy mają ten sam jawny wybór, nie powstaje sztuczna różnica.
Składniki nadal paruje się po tekście w dotychczasowej kolejności, również gdy
ten sam tekst występuje kilka razy. Nie odtwarzamy dawnego wyboru z bieżącego
przepisu, z pustej ilości ani z jednostki.

Test domenowy mierzy `porownaj()` dla obu kierunków zmiany, braku klucza,
zmiany kilku pól naraz i powtórzonych składników. Test HTTP sprawdza treść
sekcji składników oraz komunikat o braku danych w wyrenderowanym porównaniu.
Dwie kontrole ujemne oddzielnie cofają wykrycie zmiany i informację o braku
danych; wymagają własnych znaczników błędu. Bez zmiany schematu. Wycofanie kodu
przywróciłoby niepełne porównanie, ale nie zmieni zapisanych migawek.
