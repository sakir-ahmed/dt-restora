<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IngredientCategoryRequest;
use App\Import\CsvFile;
use App\Import\IngredientCategoryImporter;
use App\Models\IngredientCategory;
use App\Support\DeletionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IngredientCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('inventory.view');

        return Inertia::render('admin/ingredient-categories/Index', [
            'categories' => IngredientCategory::query()->withCount('ingredients')->ordered()->get(),
            'canManage' => $request->user('staff')?->can('inventory.manage'),
        ]);
    }

    public function store(IngredientCategoryRequest $request): RedirectResponse
    {
        IngredientCategory::query()->create([...$request->validated(), 'sort_order' => (int) $request->validated('sort_order', 0)]);

        return back()->with('success', __('inventory.category_created'));
    }

    /**
     * The sample CSV an import expects, headers and example rows.
     */
    public function importSample(): StreamedResponse
    {
        $this->authorize('inventory.manage');

        return (new IngredientCategoryImporter)->sample();
    }

    /**
     * What an import of the uploaded CSV would do, row by row.
     */
    public function importPreview(Request $request): JsonResponse
    {
        $this->authorize('inventory.manage');

        $importer = new IngredientCategoryImporter;

        $csv = CsvFile::fromUploadedFile(CsvFile::validatedUpload($request), $importer->headers());

        return response()->json($importer->preview($csv));
    }

    /**
     * Create the submitted rows: from the CSV preview or the add-multiple
     * form, the payload is the same and everything is re-validated.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorize('inventory.manage');

        return response()->json((new IngredientCategoryImporter)->run(CsvFile::validatedRows($request)));
    }

    public function update(IngredientCategoryRequest $request, IngredientCategory $category): RedirectResponse
    {
        $category->update([...$request->validated(), 'sort_order' => (int) $request->validated('sort_order', 0)]);

        return back()->with('success', __('inventory.category_updated'));
    }

    public function destroy(Request $request, IngredientCategory $category): RedirectResponse
    {
        $this->authorize('inventory.manage');

        $blockers = DeletionGuard::blockers([__('inventory.related_ingredients') => $category->ingredients()]);

        if ($blockers !== []) {
            return back()->with('error', DeletionGuard::message($category->name, $blockers));
        }

        $category->delete();

        return back()->with('success', __('inventory.category_deleted'));
    }
}
