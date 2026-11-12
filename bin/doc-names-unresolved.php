<?php

declare(strict_types=1);

$illustrative = 'an illustrative Aryeo name from the worked examples';
$ordering = 'eloquent-ordering, which the package requires once eloquent-ordering#14 ships positions, decision record 0030';
$removed = 'a removed name the specification cites on purpose';

return [
    '\\Compatibility' => 'a namespace suffix, not a class',
    'App\\Entities\\Listings\\Compatibility' => $illustrative,
    'App\\Entities\\Listings\\Listing' => $illustrative,
    'ChangeListingAddress' => $illustrative,
    'File' => $illustrative,
    'FloorPlan' => $illustrative,
    'Image' => $illustrative,
    'InitializeFloorPlanAsset' => $illustrative,
    'InitializeLegacyFloorPlan' => $illustrative,
    'InitializeLegacyListing' => $illustrative,
    'InitializeLegacyPhoto' => $illustrative,
    'InitializeListingJob' => $illustrative,
    'Job' => $illustrative,
    'Job::query()' => $illustrative,
    'Link' => $illustrative,
    'Listing' => $illustrative,
    'Listing::makeUuid()' => $illustrative,
    'ListingDependents' => $illustrative,
    'ListingMap' => $illustrative,
    'ListingWriteScope' => $illustrative,
    'MediaWriteScope' => $illustrative,
    'Photo' => $illustrative,
    'Place::class' => $illustrative,
    'PlaceAsset' => $illustrative,
    'PlaceAsset::factory()' => $illustrative,
    'PublishOrDraftJob' => $illustrative,
    'SetDownloadsPermission' => $illustrative,
    'WriteDisplayType' => $illustrative,
    'DB::table()' => 'a facade method, which reflection cannot see',
    'IsOrdered' => $ordering,
    'OrderedParents::lockFirst()' => 'stands for the routine that locks ordering parents first, which eloquent-ordering must publish for Aryeo\'s media write scope, decision record 0026',
    'Placement::after()' => $ordering,
    'Placement::at()' => $ordering,
    'Placement::before()' => $ordering,
    'Compatibility::state()' => $removed,
    'Compatibility::uninstall()' => $removed,
    'Definition\\Successors' => $removed,
];
