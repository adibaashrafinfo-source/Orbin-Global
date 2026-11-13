<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** The list response every screen expects: {data: [...], meta: {page, per_page, total}}. */
trait PagedResponses
{
    /** @param  class-string<JsonResource>  $resource */
    protected function paged(BuilderContract $query, Request $request, string $resource, int $default = 25): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = $query->paginate((int) $request->input('per_page', $default));

        return $resource::collection($page->items())->additional(['meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
