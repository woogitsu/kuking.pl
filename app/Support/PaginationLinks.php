<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

final class PaginationLinks
{
    /**
     * Zachowuje pozycję drugiej listy, bez przenoszenia obcych parametrów.
     * Numer spoza zakresu docinamy do ostatniej strony TEJ SAMEJ listy.
     * Nie zmieniamy danych ani bieżącej strony otrzymanej odpowiedzi.
     *
     * @param  LengthAwarePaginator<array-key, mixed>  $target
     * @param  LengthAwarePaginator<array-key, mixed>  $other
     */
    public static function preserveOtherPage(LengthAwarePaginator $target, LengthAwarePaginator $other): void
    {
        $page = min($other->currentPage(), $other->lastPage());

        if ($page > 1) {
            $target->appends($other->getPageName(), $page);
        }
    }
}
