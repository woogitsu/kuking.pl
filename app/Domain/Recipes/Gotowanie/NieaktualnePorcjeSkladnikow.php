<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use RuntimeException;

/** Formularz składników otwarto dla liczby porcji, która już nie obowiązuje na koncie. */
final class NieaktualnePorcjeSkladnikow extends RuntimeException {}
