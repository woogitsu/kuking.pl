<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Domyślne komunikaty walidacji — issue #86
|--------------------------------------------------------------------------
|
| Bez tego pliku KAŻDE pole bez własnego, ręcznie napisanego komunikatu
| (drugi argument $request->validate()) dostawało domyślny tekst Laravela —
| po angielsku. Przykład z issue: „The family since year field must be at
| least 1850." AGENTS.md: błędy po polsku, mówiące co zrobić.
|
| Klucze i zagnieżdżenie ODPOWIADAJĄ `vendor/laravel/framework/.../lang/en/
| validation.php` jeden do jednego — Laravel wybiera je po nazwie reguły,
| więc struktura nie jest do negocjacji, tylko treść.
|
| ODMIANA LICZEBNIKA (`:odmiana(jeden|kilka|wiele)`)
| Ten plik jest zwykłą tablicą PHP i nie umie sam policzyć, czy „5 znaków"
| ma formę liczby pojedynczej, „kilka" czy „wiele" — Laravel woła zwykłe
| `Lang::get()`, nie `trans_choice()`. Dlatego komunikaty `min`/`max` dla
| string/array/file niosą wzorzec `:odmiana(jeden|kilka|wiele)`, który
| PO WYBORZE reguły (a nie tutaj) podstawia `App\Support\OdmianaWalidacji`,
| zarejestrowany jako `Validator::replacer()` w `AppServiceProvider` —
| korzysta z TEJ SAMEJ, jedynej reguły odmiany co reszta serwisu
| (`App\Support\Odmiana::rzeczownik()`), nie z drugiej kopii.
|
| Reguły `between`, `size`, `gt`, `gte`, `lt`, `lte` nie mają dziś w Kuking
| ANI JEDNEGO wywołania (sprawdzone: `grep` po całym `app/`) — dostają więc
| tylko formę „wiele" na sztywno (`znaków`, `pozycji`...), bez odmiany.
| Jeśli kiedyś ktoś użyje którejś z nich, trzeba dopisać ten sam wzorzec
| i ten sam replacer, którego już używają `min`/`max`.
|
*/

return [

    'accepted' => 'Zaznacz pole „:attribute”, żeby przejść dalej.',
    'accepted_if' => 'Zaznacz pole „:attribute”, kiedy „:other” ma wartość „:value”.',
    'active_url' => 'Adres w polu „:attribute” nie działa. Sprawdź, czy jest wpisany poprawnie.',
    'after' => 'Data w polu „:attribute” musi być późniejsza niż :date.',
    'after_or_equal' => 'Data w polu „:attribute” musi przypadać na :date albo później.',
    'alpha' => 'Pole „:attribute” może zawierać tylko litery.',
    'alpha_dash' => 'Pole „:attribute” może zawierać tylko litery, cyfry, myślniki i podkreślniki.',
    'alpha_num' => 'Pole „:attribute” może zawierać tylko litery i cyfry.',
    'any_of' => 'Wartość w polu „:attribute” jest nieprawidłowa.',
    'array' => 'Pole „:attribute” musi być listą.',
    'array_keys' => 'Pole „:attribute” musi zawierać wyłącznie następujące pozycje: :values.',
    'ascii' => 'Pole „:attribute” może zawierać tylko zwykłe litery i cyfry, bez polskich znaków i emoji.',
    'base64' => 'Pole „:attribute” musi być poprawnie zakodowane (Base64).',
    'before' => 'Data w polu „:attribute” musi być wcześniejsza niż :date.',
    'before_or_equal' => 'Data w polu „:attribute” musi przypadać na :date albo wcześniej.',
    'between' => [
        'array' => 'Pole „:attribute” musi zawierać od :min do :max pozycji.',
        'file' => 'Plik w polu „:attribute” musi ważyć od :min do :max kilobajtów.',
        'numeric' => 'Pole „:attribute” musi mieścić się w przedziale od :min do :max.',
        'string' => 'Pole „:attribute” musi mieć od :min do :max znaków.',
    ],
    'boolean' => 'Pole „:attribute” przyjmuje tylko wartość tak/nie.',
    'can' => 'Wartość w polu „:attribute” jest niedozwolona.',
    'confirmed' => 'Powtórzone pole „:attribute” nie zgadza się z pierwszym. Wpisz to samo w obu polach.',
    'contains' => 'W polu „:attribute” brakuje wymaganej wartości.',
    'current_password' => 'To hasło jest nieprawidłowe.',
    'date' => 'Pole „:attribute” musi być poprawną datą.',
    'date_equals' => 'Data w polu „:attribute” musi być równa :date.',
    'date_format' => 'Data w polu „:attribute” musi mieć format :format.',
    'decimal' => 'Pole „:attribute” musi mieć dokładnie :decimal miejsc po przecinku.',
    'declined' => 'Pole „:attribute” musi pozostać odznaczone.',
    'declined_if' => 'Pole „:attribute” musi pozostać odznaczone, kiedy „:other” ma wartość „:value”.',
    'different' => 'Pola „:attribute” i „:other” muszą się różnić.',
    'digits' => 'Pole „:attribute” musi mieć dokładnie :digits cyfr.',
    'digits_between' => 'Pole „:attribute” musi mieć od :min do :max cyfr.',
    'dimensions' => 'Zdjęcie w polu „:attribute” ma nieprawidłowe wymiary.',
    'distinct' => 'Pole „:attribute” zawiera powtórzoną wartość. Usuń duplikat.',
    'doesnt_contain' => 'Pole „:attribute” nie może zawierać żadnej z wartości: :values.',
    'doesnt_end_with' => 'Pole „:attribute” nie może kończyć się żadną z wartości: :values.',
    'doesnt_start_with' => 'Pole „:attribute” nie może zaczynać się żadną z wartości: :values.',
    'email' => 'Adres w polu „:attribute” wygląda na niepełny. Sprawdź, czy nie brakuje kropki albo znaku @.',
    'encoding' => 'Pole „:attribute” musi być zakodowane w :encoding.',
    'ends_with' => 'Pole „:attribute” musi kończyć się jedną z wartości: :values.',
    'enum' => 'Wybrana wartość w polu „:attribute” jest nieprawidłowa. Wybierz jedną z dostępnych.',
    'exists' => 'Wybrana wartość w polu „:attribute” nie istnieje. Odśwież stronę i spróbuj ponownie.',
    'extensions' => 'Plik w polu „:attribute” musi mieć jedno z rozszerzeń: :values.',
    'file' => 'Pole „:attribute” musi być plikiem.',
    'filled' => 'Pole „:attribute” musi mieć wartość.',
    'gt' => [
        'array' => 'Pole „:attribute” musi zawierać więcej niż :value pozycji.',
        'file' => 'Plik w polu „:attribute” musi ważyć więcej niż :value kilobajtów.',
        'numeric' => 'Pole „:attribute” musi być większe niż :value.',
        'string' => 'Pole „:attribute” musi mieć więcej niż :value znaków.',
    ],
    'gte' => [
        'array' => 'Pole „:attribute” musi zawierać co najmniej :value pozycji.',
        'file' => 'Plik w polu „:attribute” musi ważyć co najmniej :value kilobajtów.',
        'numeric' => 'Pole „:attribute” musi być większe lub równe :value.',
        'string' => 'Pole „:attribute” musi mieć co najmniej :value znaków.',
    ],
    'hex_color' => 'Pole „:attribute” musi być poprawnym kolorem w zapisie szesnastkowym.',
    'image' => 'Plik w polu „:attribute” musi być zdjęciem — JPG, PNG albo WebP.',
    'in' => 'Wybrana wartość w polu „:attribute” jest nieprawidłowa. Wybierz jedną z pokazanych opcji.',
    'in_array' => 'Pole „:attribute” musi występować w „:other”.',
    'in_array_keys' => 'Pole „:attribute” musi zawierać przynajmniej jedną z pozycji: :values.',
    'integer' => 'Pole „:attribute” musi być liczbą całkowitą.',
    'ip' => 'Pole „:attribute” musi być poprawnym adresem IP.',
    'ipv4' => 'Pole „:attribute” musi być poprawnym adresem IPv4.',
    'ipv6' => 'Pole „:attribute” musi być poprawnym adresem IPv6.',
    'json' => 'Pole „:attribute” musi być poprawnym tekstem w formacie JSON.',
    'list' => 'Pole „:attribute” musi być listą kolejno ponumerowaną od zera.',
    'lowercase' => 'Pole „:attribute” musi być zapisane małymi literami.',
    'lt' => [
        'array' => 'Pole „:attribute” musi zawierać mniej niż :value pozycji.',
        'file' => 'Plik w polu „:attribute” musi ważyć mniej niż :value kilobajtów.',
        'numeric' => 'Pole „:attribute” musi być mniejsze niż :value.',
        'string' => 'Pole „:attribute” musi mieć mniej niż :value znaków.',
    ],
    'lte' => [
        'array' => 'Pole „:attribute” może zawierać najwyżej :value pozycji.',
        'file' => 'Plik w polu „:attribute” może ważyć najwyżej :value kilobajtów.',
        'numeric' => 'Pole „:attribute” musi być mniejsze lub równe :value.',
        'string' => 'Pole „:attribute” może mieć najwyżej :value znaków.',
    ],
    'mac_address' => 'Pole „:attribute” musi być poprawnym adresem MAC.',
    'max' => [
        // `:odmiana(...)` — patrz nagłówek pliku. Podstawia je `OdmianaWalidacji`
        // (rejestracja w `AppServiceProvider`), NIE ten plik.
        'array' => 'Pole „:attribute” może zawierać najwyżej :max :odmiana(pozycję|pozycje|pozycji).',
        'file' => 'Plik w polu „:attribute” może ważyć najwyżej :max :odmiana(kilobajt|kilobajty|kilobajtów).',
        'numeric' => 'Pole „:attribute” nie może być większe niż :max.',
        'string' => 'Pole „:attribute” jest za długie — może mieć najwyżej :max :odmiana(znak|znaki|znaków).',
    ],
    'max_digits' => 'Pole „:attribute” może mieć najwyżej :max cyfr.',
    'mimes' => 'Plik w polu „:attribute” musi być jednego z typów: :values.',
    'mimetypes' => 'Plik w polu „:attribute” musi być jednego z typów: :values.',
    'min' => [
        // `:odmiana(...)` — patrz nagłówek pliku i komentarz przy `max` wyżej.
        'array' => 'Pole „:attribute” musi zawierać co najmniej :min :odmiana(pozycję|pozycje|pozycji).',
        'file' => 'Plik w polu „:attribute” musi ważyć co najmniej :min :odmiana(kilobajt|kilobajty|kilobajtów).',
        'numeric' => 'Pole „:attribute” musi być nie mniejsze niż :min.',
        'string' => 'Pole „:attribute” jest za krótkie — potrzeba co najmniej :min :odmiana(znak|znaki|znaków).',
    ],
    'min_digits' => 'Pole „:attribute” musi mieć co najmniej :min cyfr.',
    'missing' => 'Pole „:attribute” musi pozostać puste.',
    'missing_if' => 'Pole „:attribute” musi pozostać puste, kiedy „:other” ma wartość „:value”.',
    'missing_unless' => 'Pole „:attribute” musi pozostać puste, chyba że „:other” ma wartość „:value”.',
    'missing_with' => 'Pole „:attribute” musi pozostać puste, kiedy podano „:values”.',
    'missing_with_all' => 'Pole „:attribute” musi pozostać puste, kiedy podano wszystkie z: „:values”.',
    'multiple_of' => 'Pole „:attribute” musi być wielokrotnością :value.',
    'not_in' => 'Wybrana wartość w polu „:attribute” jest nieprawidłowa.',
    'not_regex' => 'Pole „:attribute” ma nieprawidłowy format.',
    'numeric' => 'Pole „:attribute” musi być liczbą.',
    'password' => [
        'letters' => 'Hasło musi zawierać przynajmniej jedną literę.',
        'mixed' => 'Hasło musi zawierać przynajmniej jedną wielką i jedną małą literę.',
        'numbers' => 'Hasło musi zawierać przynajmniej jedną cyfrę.',
        'symbols' => 'Hasło musi zawierać przynajmniej jeden znak specjalny.',
        'uncompromised' => 'To hasło pojawiło się już w wyciekach danych z innych serwisów. Wybierz inne.',
    ],
    'present' => 'Pole „:attribute” musi występować w formularzu.',
    'present_if' => 'Pole „:attribute” musi występować w formularzu, kiedy „:other” ma wartość „:value”.',
    'present_unless' => 'Pole „:attribute” musi występować w formularzu, chyba że „:other” ma wartość „:value”.',
    'present_with' => 'Pole „:attribute” musi występować w formularzu, kiedy podano „:values”.',
    'present_with_all' => 'Pole „:attribute” musi występować w formularzu, kiedy podano wszystkie z: „:values”.',
    'prohibited' => 'Pole „:attribute” jest niedozwolone.',
    'prohibited_if' => 'Pole „:attribute” jest niedozwolone, kiedy „:other” ma wartość „:value”.',
    'prohibited_if_accepted' => 'Pole „:attribute” jest niedozwolone, kiedy „:other” jest zaznaczone.',
    'prohibited_if_declined' => 'Pole „:attribute” jest niedozwolone, kiedy „:other” jest odznaczone.',
    'prohibited_unless' => 'Pole „:attribute” jest niedozwolone, chyba że „:other” ma jedną z wartości: :values.',
    'prohibits' => 'Pole „:attribute” wyklucza obecność pola „:other”.',
    'regex' => 'Pole „:attribute” ma nieprawidłowy format.',
    'required' => 'Pole „:attribute” jest wymagane. Uzupełnij je, żeby wysłać formularz.',
    'required_array_keys' => 'Pole „:attribute” musi zawierać pozycje: :values.',
    'required_if' => 'Pole „:attribute” jest wymagane, kiedy „:other” ma wartość „:value”.',
    'required_if_accepted' => 'Pole „:attribute” jest wymagane, kiedy „:other” jest zaznaczone.',
    'required_if_declined' => 'Pole „:attribute” jest wymagane, kiedy „:other” jest odznaczone.',
    'required_unless' => 'Pole „:attribute” jest wymagane, chyba że „:other” ma jedną z wartości: :values.',
    'required_with' => 'Pole „:attribute” jest wymagane, kiedy podano „:values”.',
    'required_with_all' => 'Pole „:attribute” jest wymagane, kiedy podano wszystkie z: „:values”.',
    'required_without' => 'Pole „:attribute” jest wymagane, kiedy nie podano „:values”.',
    'required_without_all' => 'Pole „:attribute” jest wymagane, kiedy nie podano żadnej z wartości: „:values”.',
    'same' => 'Pole „:attribute” musi być takie samo jak „:other”.',
    'size' => [
        'array' => 'Pole „:attribute” musi zawierać dokładnie :size pozycji.',
        'file' => 'Plik w polu „:attribute” musi ważyć dokładnie :size kilobajtów.',
        'numeric' => 'Pole „:attribute” musi wynosić dokładnie :size.',
        'string' => 'Pole „:attribute” musi mieć dokładnie :size znaków.',
    ],
    'starts_with' => 'Pole „:attribute” musi zaczynać się jedną z wartości: :values.',
    'string' => 'Pole „:attribute” musi być tekstem.',
    'timezone' => 'Pole „:attribute” musi być poprawną strefą czasową.',
    'unique' => 'Ta wartość w polu „:attribute” jest już zajęta. Wybierz inną.',
    'uploaded' => 'Nie udało się wysłać pliku w polu „:attribute”. Spróbuj ponownie.',
    'uppercase' => 'Pole „:attribute” musi być zapisane wielkimi literami.',
    'url' => 'Pole „:attribute” musi być poprawnym adresem strony, zaczynającym się od https://',
    'ulid' => 'Pole „:attribute” musi być poprawnym identyfikatorem ULID.',
    'uuid' => 'Pole „:attribute” musi być poprawnym identyfikatorem UUID.',

    /*
    |--------------------------------------------------------------------------
    | Komunikaty niestandardowe
    |--------------------------------------------------------------------------
    |
    | Puste świadomie: każdy kontroler w Kuking woła $request->validate($rules,
    | $messages) i podaje własne komunikaty INLINE (trzeci parametr), a nie
    | przez klucz "validation.custom.*". Ten plik jest siatką bezpieczeństwa
    | pod te przypadki, w których nikt inline'a nie napisał — nie miejscem,
    | gdzie się je duplikuje.
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Nazwy pól — sekcja attributes
    |--------------------------------------------------------------------------
    |
    | Bez tego komunikat byłby po polsku, ale nazywałby pole jego techniczną
    | nazwą — "Pole family_since_year jest wymagane" zamiast "Pole rok, od
    | którego przepis jest w rodzinie...". Dokładnie to jest połowa problemu
    | z issue #86.
    |
    | Klucze zebrane z KAŻDEGO $request->validate() w app/Http/Controllers
    | (patrz raport PR) — nie tylko z tych pól, które dziś mają własny
    | komunikat błędu, bo generyczny komunikat wyżej może kiedyś dostać
    | nowe pole bez inline'a.
    |
    | Kilka nazw (`note`, `body`, `reason`) powtarza się w więcej niż jednym
    | formularzu o nieco innym znaczeniu (uwaga do wykonania kontra notatka
    | moderatora; treść komentarza kontra treść odwołania) — nazwa poniżej
    | jest świadomie ogólna, żeby pasowała do obu kontekstów. Pole z GENUINE
    | różnym znaczeniem w nowym miejscu powinno dostać własną nazwę przez
    | czwarty argument $request->validate() ($customAttributes), a nie zepsuć
    | tu wspólny wpis.
    |
    */

    'attributes' => [
        'name' => 'nazwa zeszytu',
        'description' => 'opis',
        'visibility' => 'widoczność',
        'login' => 'e-mail albo nazwa użytkownika',
        'password' => 'hasło',
        'password_confirmation' => 'powtórzone hasło',
        'photos' => 'zdjęcia',
        'photos.*' => 'zdjęcie',
        'note' => 'notatka',
        'changes_note' => 'notatka o zmianach w przepisie',
        'would_make_again' => 'odpowiedź „zrobię jeszcze raz”',
        'perceived_difficulty' => 'trudność przepisu',
        'actual_minutes' => 'rzeczywisty czas gotowania',
        'body' => 'treść',
        'parent_id' => 'komentarz, na który odpowiadasz',
        'topics' => 'wybrane tematy',
        'topics.*' => 'wybrany temat',
        'follow' => 'wybrane osoby do obserwowania',
        'follow.*' => 'wybrana osoba',
        'action' => 'decyzja',
        'reason_code' => 'powód decyzji',
        'user_message' => 'wiadomość do osoby zgłoszonej',
        'suspend_days' => 'długość zawieszenia',
        'suspend_days_custom' => 'własny termin zawieszenia w dniach',
        'wpisy' => 'wybrane wpisy',
        'wpisy.*' => 'wybrany wpis',
        'osoby' => 'wybrane osoby',
        'osoby.*' => 'wybrana osoba',
        'notatki' => 'notatki do wpisów',
        'notatki.*' => 'notatka',
        'media_ids' => 'zdjęcia',
        'media_ids.*' => 'zdjęcie',
        'topic_id' => 'temat',
        'email' => 'adres e-mail',
        'token' => 'link do ustawienia hasła',
        'display_name' => 'imię, którym mamy Cię nazywać',
        'username' => 'nazwa użytkownika',
        'age_confirmed' => 'potwierdzenie wieku',
        'terms_accepted' => 'zgoda na zasady serwisu',
        'confirm' => 'potwierdzenie',
        'text_scale' => 'rozmiar tekstu',
        'wants_weekly_digest' => 'zgoda na cotygodniowe podsumowanie',
        'bio' => 'opis profilu',
        'region' => 'region',
        'speciality' => 'specjalność kulinarna',
        'avatar' => 'zdjęcie profilowe',
        'reason' => 'powód',
        'title' => 'nazwa przepisu',
        'summary' => 'krótki opis przepisu',
        'servings' => 'liczba porcji',
        'prep_minutes' => 'czas przygotowania',
        'cook_minutes' => 'czas gotowania',
        'difficulty' => 'poziom trudności',
        'source_type' => 'źródło przepisu',
        'source_person' => 'osoba, od której masz przepis',
        'source_note' => 'historia przepisu',
        'source_url' => 'adres strony źródłowej',
        'family_since_year' => 'rok, od którego przepis jest w rodzinie',
        'hero_photo' => 'zdjęcie główne',
        'source_scan' => 'skan albo zdjęcie oryginalnego przepisu',
        'ingredients' => 'składniki',
        'ingredients.*.text' => 'nazwa składnika',
        'ingredients.*.group_name' => 'nazwa grupy składników',
        'ingredients.*.note' => 'uwaga do składnika',
        'steps' => 'kroki przygotowania',
        'steps.*.instruction' => 'treść kroku',
        'details' => 'szczegóły zgłoszenia',
        'outcome' => 'rozstrzygnięcie',
        'decision_note' => 'uzasadnienie decyzji',
    ],

];
