<?php

namespace App\Support;

use Closure;

final class MediaUploadRules
{
    public const MAX_FILES = 10;

    public const MAX_FILE_KILOBYTES = 102400;

    public const MAX_TOTAL_BYTES = 104857600;

    public const MIMES = 'jpg,jpeg,png,gif,webp,svg,bmp,avif,ico,mp4,webm,mov,mp3,wav,m4a,ogg';

    public static function files(): array
    {
        return [
            'required',
            'array',
            'min:1',
            'max:'.self::MAX_FILES,
            function (string $attribute, mixed $files, Closure $fail): void {
                if (! is_array($files)) {
                    return;
                }

                $totalBytes = collect($files)->sum(
                    fn ($file) => method_exists($file, 'getSize') ? (int) $file->getSize() : 0,
                );

                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    $fail('Total ukuran file dalam satu upload maksimal 100 MB.');
                }
            },
        ];
    }

    public static function file(): array
    {
        return [
            'required',
            'file',
            'max:'.self::MAX_FILE_KILOBYTES,
            'mimes:'.self::MIMES,
        ];
    }
}
