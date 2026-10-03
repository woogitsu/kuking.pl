<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use RuntimeException;

/** Formularz pochodzi z poprzedniego włączenia zapamiętywania. */
class NieaktualnyPostepGotowania extends RuntimeException {}
