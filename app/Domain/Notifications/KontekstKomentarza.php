<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;

/**
 * Wszystko, czego `Notification::urlDoKomentarza()` potrzebuje z BAZY, żeby
 * złożyć adres konkretnego komentarza — policzone z góry dla całej strony
 * powiadomień przez `KontekstyKomentarzy`.
 *
 * DLACZEGO OSOBNY OBIEKT, A NIE GOTOWY ADRES
 * Adres składa model i tylko model (`adresDocelowy()` jest jednym źródłem dla
 * widoku i dla kontrolera — patrz komentarz przy tamtej metodzie). Gdyby to
 * pole niosło gotowy łańcuch, powstałaby DRUGA kopia reguł składania adresu,
 * a rozjazd między nimi wyglądałby tak, że „Zobacz" na liście prowadzi gdzie
 * indziej niż „Zobacz" po oznaczeniu przeczytania. Tutaj jest wyłącznie to,
 * co kosztuje zapytanie.
 */
final class KontekstKomentarza
{
    public function __construct(
        /** Komentarz, o którym mówi powiadomienie (kotwica wskazuje JEGO). */
        public readonly Comment $komentarz,
        /** Treść, pod którą stoi wątek — stąd bierze się adres bazowy. */
        public readonly Post|Recipe|CookedEvent $tresc,
        /**
         * Czy KORZEŃ wątku jest widoczny dla tego odbiorcy. `false` znaczy
         * „wracamy do zwykłego adresu treści" — dokładnie jak przed
         * wprowadzeniem tej klasy, bez ujawniania, gdzie ten wątek jest.
         */
        public readonly bool $korzenWidoczny,
        /**
         * Ile WIDOCZNYCH korzeni stoi przed korzeniem tego wątku (liczone od
         * zera). `null` dla treści, która nie stronicuje komentarzy
         * („Ugotowałem" ładuje je wszystkie naraz) — wtedy numer strony
         * w ogóle nie powstaje.
         */
        public readonly ?int $pozycjaKorzenia,
    ) {}
}
