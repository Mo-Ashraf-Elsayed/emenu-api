<?php

namespace App\Modules\Media;

use Illuminate\Database\Eloquent\Builder;

final class ReconcileMedia
{
    public function __invoke(): void
    {
        $records = Media::query()->where('updated_at', '<=', now()->subMinutes(5))->whereNull('purged_at')
            ->where(function (Builder $due): void {
                $due->where('status', 'deleted')
                    ->orWhere(fn (Builder $finished) => $finished->whereIn('status', ['ready', 'failed'])->whereNull('source_cleaned_at'))
                    ->orWhere(fn (Builder $pending) => $pending->where('status', 'pending')
                        ->whereHas('restaurant', fn (Builder $restaurant) => $restaurant->where('status', 'active')));
            })
            ->orderBy('updated_at')->orderBy('id')->limit(250)->get();

        foreach ($records as $record) {
            MediaWorkflow::enqueue($record);
        }
    }
}
