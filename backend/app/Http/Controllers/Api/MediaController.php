<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams private media for local disks. Only reachable through a valid, unexpired signature. */
class MediaController extends Controller
{
    public function stream(Request $request, string $path): StreamedResponse
    {
        $decoded = base64_decode(strtr($path, '-_', '+/'), true);
        abort_if($decoded === false || str_contains($decoded, '..'), 404);

        $disk = Storage::disk(config('learning.media.disk'));
        abort_unless($disk->exists($decoded), 404);

        return $disk->response($decoded, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
