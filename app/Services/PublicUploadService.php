<?php

namespace App\Services;

use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicUploadService
{
    public function store(
        UploadedFile $file,
        string $directory,
        string $disk = 'public',
        string $validationKey = 'files',
    ): string {
        $handle = fopen($file->getRealPath(), 'rb');
        $prefix = $handle ? fread($handle, 4096) : '';

        if (is_resource($handle)) {
            fclose($handle);
        }

        $isSvg = strtolower($file->getClientOriginalExtension()) === 'svg'
            || in_array(strtolower((string) $file->getMimeType()), [
                'image/svg+xml',
                'application/svg+xml',
            ], true)
            || str_contains(
                strtolower($prefix ?: ''),
                '<svg',
            );

        if (! $isSvg) {
            $path = $file->store($directory, $disk);

            if (! $path) {
                throw ValidationException::withMessages([
                    $validationKey => 'File gagal disimpan. Silakan coba kembali.',
                ]);
            }

            return $path;
        }

        if ((int) $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                $validationKey => 'Ukuran file SVG maksimal 5 MB.',
            ]);
        }

        $contents = file_get_contents($file->getRealPath());
        $sanitized = $this->sanitizeSvg($contents ?: '', $validationKey);
        $path = trim($directory, '/').'/'.Str::uuid().'.svg';

        if (! Storage::disk($disk)->put($path, $sanitized)) {
            throw ValidationException::withMessages([
                $validationKey => 'File SVG gagal disimpan. Silakan coba kembali.',
            ]);
        }

        return $path;
    }

    public function sanitizeSvg(string $svg, string $validationKey = 'files'): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || strtolower($document->documentElement?->localName ?? '') !== 'svg') {
            throw ValidationException::withMessages([$validationKey => 'File SVG tidak valid.']);
        }

        $blockedElements = ['script', 'foreignObject', 'iframe', 'object', 'embed', 'style'];

        foreach ($blockedElements as $element) {
            $nodes = $document->getElementsByTagName($element);

            while ($nodes->length > 0) {
                $nodes->item(0)?->parentNode?->removeChild($nodes->item(0));
            }
        }

        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim(strtolower($attribute->nodeValue));

                if (
                    str_starts_with($name, 'on') ||
                    $name === 'style' ||
                    (in_array($name, ['href', 'xlink:href'], true) && ! str_starts_with($value, '#'))
                ) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }

        return $document->saveXML($document->documentElement) ?: '';
    }
}
