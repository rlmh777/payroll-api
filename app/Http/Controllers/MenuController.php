<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Services\MenuAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MenuController extends Controller
{
    public function __construct(
        private readonly MenuAuthorizationService $menuAuthorizationService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && ! $user->can('menu-crud')) {
            $menus = $this->menuAuthorizationService->menusForUser($user);

            return response()->json($menus);
        }

        $query = Menu::with(['children', 'parent']);

        // Filter by menu type
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        // Filter by parent_id (for submenus)
        if ($request->has('parent_id')) {
            if ($request->input('parent_id') === 'null') {
                $query->whereNull('parent_id'); // Root menus
            } else {
                $query->where('parent_id', $request->input('parent_id'));
            }
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('module_code')) {
            $query->where('module_code', $request->input('module_code'));
        }

        // Get hierarchy if requested
        if ($request->boolean('hierarchy')) {
            $query->rootMenus();
        }

        // Sort by order
        $query->orderBy('order', 'asc');

        $menus = $query->get();

        return response()->json($menus);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'parent_id' => 'nullable|uuid|exists:menus,id',
            'title' => 'required|string|max:255',
            'route' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:255',
            'permission' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'type' => 'nullable|string|in:menu,submenu',
            'module_code' => 'nullable|string|max:64|exists:modules,code',
            'source' => 'nullable|string|in:system,custom',
            'system_key' => 'nullable|string|max:128|unique:menus,system_key',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Auto-set type based on parent_id
        if (! $request->has('type')) {
            $request->merge(['type' => $request->has('parent_id') ? 'submenu' : 'menu']);
        }

        if (! $request->has('source')) {
            $request->merge(['source' => 'custom']);
        }

        if (! $request->filled('module_code')) {
            $request->merge(['module_code' => config('modules.default_module', 'payroll')]);
        }

        $menu = Menu::create($request->all());

        return response()->json([
            'message' => 'Menu created successfully',
            'data' => $menu->load(['children', 'parent']),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Menu $menu): JsonResponse
    {
        return response()->json($menu->load(['children', 'parent']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Menu $menu): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'parent_id' => 'nullable|uuid|exists:menus,id',
            'title' => 'string|max:255',
            'route' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:255',
            'permission' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'type' => 'nullable|string|in:menu,submenu',
            'module_code' => 'nullable|string|max:64|exists:modules,code',
            'source' => 'nullable|string|in:system,custom',
            'system_key' => 'nullable|string|max:128|unique:menus,system_key,'.$menu->id,
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Prevent setting parent to self or creating circular references
        if ($request->has('parent_id') && $request->input('parent_id') === $menu->id) {
            return response()->json(['errors' => ['parent_id' => ['Cannot set parent to self']]], 422);
        }

        $menu->update($request->all());

        return response()->json([
            'message' => 'Menu updated successfully',
            'data' => $menu->load(['children', 'parent']),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Menu $menu): JsonResponse
    {
        // Check if menu has children
        if ($menu->children()->exists()) {
            return response()->json([
                'message' => 'Cannot delete menu with children. Delete children first.',
            ], 422);
        }

        $menu->delete();

        return response()->json([
            'message' => 'Menu deleted successfully',
        ]);
    }
}
