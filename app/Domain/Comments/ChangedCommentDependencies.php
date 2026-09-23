<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use RuntimeException;

/** Sygnał ponownego odkrycia zależności, nigdy opakowanie błędu PostgreSQL. */
final class ChangedCommentDependencies extends RuntimeException {}
