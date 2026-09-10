<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\Media;

/**
 * Zdjęcia wgrane, ale do niczego nieprzypięte.
 *
 * SKĄD SIĘ BIORĄ
 * Od czasu naprawy C1 zdjęcia wgrywają się PRZED walidacją reszty formularza,
 * żeby nieudana walidacja nie kasowała człowiekowi wyboru z galerii telefonu.
 * Kto zamknie kartę zamiast poprawić błąd, zostawia wiersz w `media` i pliki
 * na dysku, do których nic nie prowadzi.
 *
 * Istniały zresztą i wcześniej: gdy `PublishPost` rzucał wyjątek po wgraniu
 * zdjęć, dokładnie to samo zostawało po sobie — tylko rzadziej.
 *
 * DLACZEGO Z ZAPASEM CZASU
 * Nieprzypięte zdjęcie sprzed pięciu minut to najprawdopodobniej formularz,
 * który ktoś właśnie poprawia w drugiej karcie. Skasowanie go byłoby dokładnie
 * tą samą krzywdą, której ta naprawa miała zapobiec, tylko zadaną z drugiej
 * strony.
 */
final class OsieroconeZdjecia
{
    /**
     * Lista odwołań mieszka w `KasujZdjecie` — kasowanie zdjęcia i sprzątanie
     * osieroconych to dwie drogi do tej samej, nieodwracalnej operacji,
     * a dwie kopie listy to dwie okazje do rozjazdu.
     */
    public function __construct(
        private readonly int $godzinKarencji = 24,
        private readonly KasujZdjecie $kasowanie = new KasujZdjecie,
    ) {}

    /**
     * @return int ile zdjęć skasowano
     */
    public function posprzataj(bool $naSucho = false): int
    {
        $zapytanie = Media::query()->where('created_at', '<', now()->subHours($this->godzinKarencji));

        foreach (KasujZdjecie::ODWOLANIA as [$tabela, $kolumna]) {
            $zapytanie->whereNotExists(function ($sub) use ($tabela, $kolumna): void {
                $sub->selectRaw('1')->from($tabela)->whereColumn("{$tabela}.{$kolumna}", 'media.id');
            });
        }

        $skasowane = 0;

        // Porcjami i per wiersz: przerwanie w połowie ma zostawić bazę
        // i dysk w stanie zgodnym ze sobą, a nie w połowie jednej wielkiej
        // transakcji.
        //
        // TRANSAKCJI NIE OTWIERAMY TUTAJ (issue #285, D-083). Przedtem stała
        // w tym miejscu i obejmowała także kasowanie plików w R2 — czyli
        // trzymała otwartą transakcję przez całe wejście na cudzy serwer,
        // a jej ewentualne wycofanie i tak nie przywróciłoby ani jednego
        // skasowanego pliku. Granicę commitu ma teraz `KasujZdjecie`, które
        // jako jedyne wie, co wolno zrobić przed nią (przejęcie wiersza pod
        // blokadą), a co dopiero po (pliki).
        $zapytanie->chunkById(100, function ($zdjecia) use ($naSucho, &$skasowane): void {
            foreach ($zdjecia as $zdjecie) {
                if ($naSucho) {
                    $skasowane++;

                    continue;
                }

                if ($this->kasowanie->jesliNieuzywane($zdjecie)) {
                    $skasowane++;
                }
            }
        });

        return $skasowane;
    }
}
