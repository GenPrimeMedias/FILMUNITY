<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class MembershipQr
{
    public static function data(string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240, 4), new SvgImageBackEnd);

        return 'data:image/svg+xml;base64,'.base64_encode((new Writer($renderer))->writeString($url));
    }
}
