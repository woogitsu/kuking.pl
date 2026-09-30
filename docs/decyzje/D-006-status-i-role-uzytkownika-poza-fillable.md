## D-006 · `status` i `role` użytkownika poza `$fillable`

**Data:** wrzesień 2026 · Status: **obowiązuje**

> **Uzupełnienie z 20 września 2026 (audyt rejestru).** Reguła jest dziś
> TRZYelementowa, nie dwuelementowa. Commit `ed6cbf00` (19 września) wyjął
> `posts.kind` z `$fillable` i nazwał je polem STERUJĄCYM „tej samej rodziny
> co `users.status` i `users.role`": `kind` rozstrzyga, czy wpis jest daniem,
> czy pytaniem, a przez to do których strumieni trafia (`scopeEnabledKinds`),
> pod jakim adresem stoi (`url()`) i co przepuści `PostPolicy`. Jedyną drogą
> jest nazwana metoda `Post::oznaczJakoPytanie()`. Tytuł i treść tego wpisu
> mówią o dwóch kolumnach i nie zostały przepisane — reguła obejmuje trzy.

Zmiana stanu konta jest zawsze jawną, nazwaną operacją: `suspend()`, `ban()`,
`markForDeletion()`, `promoteTo()`. To zamyka drogę do przejęcia uprawnień
przez dołożenie pola do formularza.

Ta decyzja została podjęta **po znalezieniu realnego błędu**: usunięcie konta
nie działało, bo `update(['status' => ...])` było ciche.

**Zmiana wymaga:** niczego. To jest zabezpieczenie, nie preferencja.

📄 `AGENTS.md` §7 · `app/Models/User.php` · `tests/Feature/SecurityTest.php`
