# Poświadczenia nigdy w $fillable — pełna reguła

Przeniesione z `AGENTS.md` §7 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

- **żadnego POŚWIADCZENIA w `$fillable`, w żadnej tabeli** — `password`,
  `remember_token`, `two_factor_*`, każde `*_token`, `*_secret`, `*_token_hash`.
  Kto zapisze taką kolumnę, ten wchodzi na konto bez znajomości hasła.
  Wchodzą tam jawnymi, nazwanymi metodami (`assignEmail()`,
  `assignPassword()`, `connectGoogle()`) albo przez `forceFill()` w jednej
  nazwanej akcji domenowej. `ip_hash` i `checksum_sha256` to NIE są
  poświadczenia — reguła mówi „poświadczenie", nie „ciąg szesnastkowy".
  Pilnuje tego `tests/Feature/WrazliweKolumnyPozaMasowymPrzypisaniemTest.php`,
  który przechodzi po WSZYSTKICH modelach i wylicza kolumny wrażliwe
  z schematu bazy i z relacji, a nie z listy przepisanej z palca. Kolumny
  wrażliwe spoza kategorii poświadczeń (stan treści, klucze właściciela,
  widoczność) wolno w `$fillable` zostawić, ale wyłącznie z wpisem w rejestrze
  tego testu mówiącym, skąd ta wartość pochodzi, jeśli nie z żądania.
