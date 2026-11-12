<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Catalog\LabelService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** FR-305: barcode label PDF (A4 sheet or 50 × 25 mm roll). */
class LabelController extends Controller
{
    public function __construct(private readonly LabelService $labels) {}

    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:500'],
            'template' => ['required', Rule::in(LabelService::TEMPLATES)],
            'show_price' => ['sometimes', 'boolean'], 'show_name' => ['sometimes', 'boolean'], 'show_size' => ['sometimes', 'boolean'],
            'generate_missing' => ['sometimes', 'boolean'], 'start_position' => ['sometimes', 'integer', 'min:1', 'max:27'],
        ]);
        $pdf = $this->labels->render($data['items'], $data);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="orbin-labels-'.$data['template'].'.pdf"']);
    }
}
