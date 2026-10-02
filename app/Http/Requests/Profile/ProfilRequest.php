<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wejście publicznego profilu (`profile.show`, `/@nazwa`).
 *
 * Wyjęte z `ProfileController::show()` bez zmiany zachowania (issue #970):
 * ta sama lista zakładek, ten sam zakres lat, ta sama wartość domyślna.
 *
 * TU NIE MA ANI JEDNEJ REGUŁY, KTÓRA ODSYŁA Z BŁĘDEM — jak w
 * `ListaKontRequest`. Zakładka, rok i fraza przychodzą z paska adresu
 * (odnośniki zakładek i archiwum, formularz `GET`), nie z pól wypełnionych
 * źle. `?zakladka=cokolwiek` ma dać zakładkę „Wszystko", `?rok=cokolwiek`
 * całe archiwum, a nie przekierowanie ani pustą stronę. Dlatego `rules()`
 * jest puste; pilnuje tego `ProfilRequestTest`.
 *
 * Adres profilu nie jest autoryzacją (AGENTS.md §7): Policy `viewProfile`
 * zostaje w kontrolerze, bo potrzebuje konta znalezionego po nazwie z trasy.
 * Fraza „Szukaj w moich wykonaniach" jest zwracana surowa —
 * `FrazaWUgotowanych::zAdresu()` sama ją czyści i zwraca zdania błędu.
 */
final class ProfilRequest extends FormRequest
{
    /** @var list<string> */
    public const ZAKLADKI = ['przepisy', 'ugotowane'];

    public const ZAKLADKA_DOMYSLNA = 'wszystko';

    public const PIERWSZY_ROK = 1990;

    public const OSTATNI_ROK = 2999;

    public function authorize(): bool
    {
        // Świadomie true — patrz nagłówek klasy (Policy w kontrolerze).
        return true;
    }

    /**
     * Świadomie puste — patrz nagłówek klasy.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /** Zakładka z adresu; nieznana wartość to „wszystko". */
    public function zakladka(): string
    {
        $zakladka = $this->query('zakladka');

        return in_array($zakladka, self::ZAKLADKI, true) ? $zakladka : self::ZAKLADKA_DOMYSLNA;
    }

    /** Rok archiwum z adresu, ale tylko jeśli wygląda na rok; inaczej null (całe archiwum). */
    public function rok(): ?int
    {
        $rok = (int) $this->query('rok', 0);

        return $rok >= self::PIERWSZY_ROK && $rok <= self::OSTATNI_ROK ? $rok : null;
    }

    /** Surowa fraza `?szukaj=` — do `FrazaWUgotowanych::zAdresu()`. */
    public function szukaj(): mixed
    {
        return $this->query('szukaj');
    }

    /**
     * Wybór „Zrobię ponownie” na własnej zakładce „Ugotowane” (#2460).
     *
     * Włącza go WYŁĄCZNIE dokładnie `?ponownie=1`. Tablica (`ponownie[]=1`),
     * `0`, `true`, pusty napis i każda inna wartość to „wyłączony” — bez
     * błędu 500 i bez przypadkowego włączenia, zgodnie z regułą tego
     * parsera (nic nie odsyła z błędem). Czy filtr w ogóle działa, rozstrzyga
     * kontroler: tylko właściciel, tylko zakładka „Ugotowane”.
     */
    public function ponownie(): bool
    {
        return $this->query('ponownie') === '1';
    }
}
