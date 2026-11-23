<?php

namespace App\Support;

final class FilmunityBrandAssets
{
    /** @return array{full: string, mini: string} */
    public static function paths(): array
    {
        $result = [];
        foreach (['full', 'mini'] as $variant) {
            $result[$variant] = '/brand/filmunity-logo-transparent.png';
            foreach (['svg', 'png'] as $extension) {
                $path = '/brand/filmunity-logo-'.$variant.'.'.$extension;
                if (! is_file(public_path($path))) {
                    continue;
                }
                if ($extension === 'svg' && ! self::safeSvg((string) file_get_contents(public_path($path)))) {
                    continue;
                }
                $result[$variant] = $path.'?v='.filemtime(public_path($path));
                break;
            }
        }

        return $result;
    }

    public static function safeSvg(string $source): bool
    {
        if (strlen($source) > 1048576 || preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet|javascript:|data:|url\(\s*["\x27]?[^#]/i', $source)) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument;
            if (! $document->loadXML($source, LIBXML_NONET) || $document->documentElement?->localName !== 'svg') {
                return false;
            }
            $allowed = ['svg', 'g', 'path', 'defs', 'linearGradient', 'radialGradient', 'stop', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'title', 'desc', 'clipPath', 'mask', 'symbol', 'use'];
            foreach ($document->getElementsByTagName('*') as $element) {
                if (! in_array($element->localName, $allowed, true)) {
                    return false;
                }
                foreach ($element->attributes as $attribute) {
                    if (str_starts_with(strtolower($attribute->name), 'on') || ($attribute->localName === 'href' && ! str_starts_with($attribute->value, '#'))) {
                        return false;
                    }
                }
            }

            return true;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
