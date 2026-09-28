<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\JaResource;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/ja` — kim jestem (D-270). Aplikacja pyta o to po starcie,
 * żeby wiedzieć, czy token jeszcze działa i czyje konto pokazać.
 */
class JaController extends Controller
{
    public function __invoke(Request $request): JaResource
    {
        return new JaResource($request->user()->loadMissing('profile'));
    }
}
