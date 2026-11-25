<?php

namespace App\Modules\PublicMenus;

use App\Modules\Media\MediaStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublicMenuController
{
    public function show(PublicMenuRequest $request, string $slug, PublicMenus $menus): PublicMenuResource
    {
        return new PublicMenuResource($menus->read($slug, $request->filter('lang')));
    }

    public function media(string $slug, string $media, string $variant, PublicMenus $menus): StreamedResponse
    {
        return MediaStorage::variant($menus->media($slug, $media, $variant), $variant);
    }
}
