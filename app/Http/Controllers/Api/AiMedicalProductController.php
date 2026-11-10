<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiMedicalProductController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('ai_medical_products');
        if ($request->boolean('active_only', true)) {
            $query->where('active', true);
        }
        if (trim((string) $request->query('q', '')) !== '') {
            $search = mb_substr(trim((string) $request->query('q')), 0, 120);
            $query->where('name', 'ilike', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%');
        }
        $perPage = max(1, min(200, (int) $request->query('per_page', 30)));
        return $this->success($query->orderBy('name')->orderBy('id')->paginate($perPage));
    }

    // Admin-only update endpoint; catalogue data is global, not tenant-owned.
    // The UI can be added later without changing this schema.
    public function update(Request $request, int $product)
    {
        $values = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'manufacturer' => 'sometimes|nullable|string|max:255',
            'category' => 'sometimes|required|string|max:128',
            'variant' => 'sometimes|nullable|string|max:255',
            'alternative_names' => 'sometimes|array|max:40',
            'alternative_names.*' => 'required|string|max:255',
            'active' => 'sometimes|boolean',
        ]);
        abort_unless(DB::table('ai_medical_products')->where('id', $product)->exists(), 404);
        if (array_key_exists('alternative_names', $values)) {
            $values['alternative_names'] = json_encode($values['alternative_names'], JSON_UNESCAPED_UNICODE);
        }
        $values['updated_at'] = now();
        DB::table('ai_medical_products')->where('id', $product)->update($values);
        return $this->success(DB::table('ai_medical_products')->where('id', $product)->first());
    }
}
