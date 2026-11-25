<?php

namespace App\Modules\PublicMenus;

use App\Shared\Http\QueryRequest;

final class PublicMenuRequest extends QueryRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['lang' => ['sometimes', 'string', 'in:en,ar']];
    }
}
