<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimalne potwierdzenie obsługi żądania usunięcia konta (RODO art. 17).
 *
 * Schemat, uzasadnienie każdej kolumny i plan wycofania stoją w migracji
 * `2026_09_21_100000_utworz_potwierdzenia_zadan_rodo` i w `docs/DATABASE.md`;
 * rozstrzygnięcia projektowe — w `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`.
 * Tu jest tylko to, czego nie da się powiedzieć schematem.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TEN MODEL NIE MA I NIE DOSTANIE ŚCIEŻKI ODCZYTU DLA CZŁOWIEKA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Od decyzji właściciela z 21.09.2026 `konto_id` zostaje w wierszu także po
 * wykonaniu żądania. To znaczy, że rejestr UMIE odpowiedzieć na pytanie „czy
 * ta osoba usunęła konto" — i dokładnie dlatego nie wolno mu dać ekranu,
 * trasy ani endpointu, który by o to pytał. Korzystanie z powiązania jest
 * odczytem ręcznym przy sprawie od regulatora, nie funkcją produktu. Pilnuje
 * tego `tests/Feature/RejestrPotwierdzenRodoNieMaEkranuTest.php`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  `$fillable` — CO WPUSZCZAMY I DLACZEGO TAK WĄSKO
 * ────────────────────────────────────────────────────────────────────────
 *
 * AGENTS.md: `status` i `role` użytkownika NIGDY w `$fillable`, bo masowe
 * przypisanie z tablicy zbudowanej gdzie indziej potrafi podnieść uprawnienia
 * bez ani jednej linijki, która by o tym mówiła. Ta sama ostrożność obowiązuje
 * tutaj, tylko stawką nie są uprawnienia, lecz PRAWDZIWOŚĆ DOWODU i ZEGAR
 * RETENCJI.
 *
 * W `$fillable` stoi więc wyłącznie OPIS SPRAWY, którego przekręcenie nie
 * zmienia ani tego, co się stało, ani tego, kiedy wiersz zniknie:
 *  - `rodzaj` — dziś jedna wartość, pilnowana CHECK-iem;
 *  - `otrzymano` — data wpływu, pisana raz, przy przyjęciu żądania;
 *  - `wersja_procedury` — która procedura to wykonała;
 *  - `wyjatki` — czego nie usunięto i z jakiej reguły to wynika.
 *
 * POZA `$fillable` — i to jest lista, do której nie dopisuj nic bez powodu
 * napisanego obok:
 *  - `numer` — jedyne powiązanie wiersza z człowiekiem, które człowiek ma
 *    u siebie. Losuje go `RejestrPotwierdzenRodo`, nikt inny;
 *  - `wynik` — to jest `status` tej tabeli. Od niego zależy, CO ten wiersz
 *    twierdzi o wykonaniu prawa z art. 17;
 *  - `zakres` — co dokładnie wykonano (D-022). Ma pochodzić z faktycznego
 *    zakresu wymazania, nie z tablicy, którą ktoś złożył po drodze;
 *  - `zakonczono` — POCZĄTEK ZEGARA RETENCJI. Masowo przypisywalna data
 *    końca to masowo przypisywalny termin skasowania dowodu;
 *  - `konto_id` — wskaźnik na żywe dane osobowe (patrz wyżej);
 *  - `wstrzymanie_do`, `wstrzymanie_sprawa` — wstrzymanie kasowania. To jest
 *    wyjątek od retencji; wyjątek nadawany z tablicy przestaje być wyjątkiem.
 *
 * Wszystko z drugiej listy ustawia `App\Domain\Compliance\RejestrPotwierdzenRodo`
 * przypisaniem wprost, więc każda taka zmiana jest widoczna w kodzie, który ją
 * robi, a nie w kształcie tablicy przekazanej skądinąd.
 */
class PotwierdzenieZadaniaRodo extends Model
{
    use HasUuids;

    protected $table = 'potwierdzenia_zadan_rodo';

    /** Żądanie przyjęte, karencja jeszcze biegnie. */
    public const WYNIK_W_TOKU = 'w_toku';

    /** Dane wymazane (`EraseAccountData`). */
    public const WYNIK_WYKONANE = 'wykonane';

    /** Człowiek zmienił zdanie w karencji (`CancelAccountDeletion`). */
    public const WYNIK_COFNIETE = 'cofniete';

    /** Żądania nie wykonano i podano powód. */
    public const WYNIK_ODMOWA = 'odmowa';

    /**
     * Uzasadnienie tej listy — i tego, czego na niej NIE MA — w komentarzu
     * klasy. Nie dopisuj tu kolumny bez powodu napisanego obok.
     *
     * @var list<string>
     */
    protected $fillable = [
        'rodzaj',
        'otrzymano',
        'wersja_procedury',
        'wyjatki',
    ];

    /**
     * Konto wnioskodawcy — `null` po tym, jak `users` przestanie istnieć
     * (`ON DELETE SET NULL`), a nie po wykonaniu żądania: od decyzji
     * właściciela z 21.09.2026 wskaźnik zostaje także wtedy.
     *
     * @return BelongsTo<User, $this>
     */
    public function konto(): BelongsTo
    {
        return $this->belongsTo(User::class, 'konto_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `date`, nie `datetime` — doba zamiast sekundy jest w tej tabeli
            // minimalizacją, nie zaokrągleniem (uzasadnienie w migracji).
            'otrzymano' => 'date',
            'zakonczono' => 'date',
            'wstrzymanie_do' => 'date',
        ];
    }
}
