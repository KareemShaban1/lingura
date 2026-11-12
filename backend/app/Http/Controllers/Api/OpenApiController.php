<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class OpenApiController extends Controller
{
    public function __invoke(): Response
    {
        return response(file_get_contents(base_path('openapi.yaml')), 200, ['Content-Type' => 'application/yaml']);
    }
}
