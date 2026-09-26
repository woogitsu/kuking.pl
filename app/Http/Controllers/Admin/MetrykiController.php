<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Analytics\MetrykiDoboru;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\View\View;

/**
 * Metryki doboru bez profilowania (issue #1814, D-281).
 *
 * Tylko czyta i tylko agregaty — bez list osób i wpisów. Progi z
 * `kuking.metryki` są materiałem do decyzji właściciela (D-275), nie
 * przełącznikiem: przekroczenie niczego samo nie zmienia.
 */
class MetrykiController extends Controller
{
    public function index(MetrykiDoboru $metryki): View
    {
        $this->authorize('przegladajMetryki', User::class);

        return view('pages.admin.metryki', ['m' => $metryki->wszystkie()]);
    }
}
