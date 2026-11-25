<?php

namespace App\Modules\PublicMenus;

use App\Shared\Http\StrictRequest;

final class MenuSettingsRequest extends StrictRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        if ($this->routeIs('publication.publish')) {
            return ['expectedDraftRevision' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/D']];
        }

        return ['defaultLocale' => ['required', 'string', 'in:en,ar']];
    }
}
