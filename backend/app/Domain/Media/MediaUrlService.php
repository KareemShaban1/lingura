<?php

namespace App\Domain\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Private media is never public: clients receive short-lived signed URLs only.
 * S3-compatible disks use native pre-signed URLs; local disks use a signed streaming route.
 */
class MediaUrlService
{
    public function temporaryUrl(string $path): string
    {
        $disk = config('learning.media.disk');
        $expires = now()->addMinutes(config('learning.media.signed_url_ttl_minutes'));

        if (config("filesystems.disks.{$disk}.driver") === 's3') {
            return Storage::disk($disk)->temporaryUrl($path, $expires);
        }

        return URL::temporarySignedRoute('media.stream', $expires, ['path' => rtrim(strtr(base64_encode($path), '+/', '-_'), '=')]);
    }
}
