<?php

namespace App\Filament\Resources\SaasMappingReviewResource\Pages;

use App\Filament\Resources\SaasMappingReviewResource;
use App\Filament\Resources\SaasMappingReviewResource\Widgets\ReconciliationProgress;
use Filament\Resources\Pages\ListRecords;

final class ListSaasMappingReviews extends ListRecords
{
    protected static string $resource = SaasMappingReviewResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ReconciliationProgress::class];
    }
}
