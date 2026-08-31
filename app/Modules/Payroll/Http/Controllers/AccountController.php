<?php

namespace App\Modules\Payroll\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Account;
use App\Models\AccountType;
use Illuminate\Support\Facades\Validator;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $query = Account::query()->orderBy('name');
        
        // Filter by root accounts only
        if ($request->boolean('root_only')) {
            $query->root();
        }
        
        // Filter by sub-accounts only
        if ($request->boolean('sub_accounts_only')) {
            $query->subAccounts();
        }
        
        // Filter by account_type_id (account type)
        if ($request->has('account_type_id')) {
            $query->where('account_type_id', $request->input('account_type_id'));
        }
        
        // Optionally filter by parent_id to get root accounts or sub-accounts
        if ($request->has('parent_id')) {
            if ($request->input('parent_id') === 'null') {
                $query->whereNull('parent_id');
            } else {
                $query->childrenOf($request->input('parent_id'));
            }
        }
        
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($term) {
                $like = '%'.$term.'%';
                $builder->where('name', 'ilike', $like)
                    ->orWhere('code1', 'ilike', $like)
                    ->orWhere('code2', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like);
            });
        }

        // Optionally eager load relationships
        if ($request->boolean('with_relations')) {
            $query->with('parent', 'children', 'accountType');
        }
        
        // Load full tree structure recursively
        if ($request->boolean('with_descendants')) {
            $query->with(['children' => function ($query) {
                $query->with('children');
            }]);
        }
        
        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'code1' => ['nullable', 'string', 'max:255'],
            'code2' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'account_type_id' => ['nullable', 'integer', 'exists:account_types,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Validate that parent_id doesn't create circular reference
        if (isset($validated['parent_id'])) {
            $parent = Account::find($validated['parent_id']);
            if (!$parent) {
                return response()->json(['errors' => ['parent_id' => ['The selected parent account does not exist.']]], 422);
            }
        }

        $record = Account::create($validated);
        return response()->json($record->load('parent', 'children', 'accountType'), 201);
    }

    public function show(Request $request, Account $account)
    {
        $account->load('parent', 'children', 'accountType');
        
        // Optionally load full descendant tree
        if ($request->boolean('with_descendants')) {
            $account->load(['children' => function ($query) {
                $query->with('children');
            }]);
        }
        
        // Add computed attributes
        $response = $account->toArray();
        $response['depth'] = $account->depth;
        $response['is_root'] = $account->isRoot();
        
        return response()->json($response);
    }

    public function update(Request $request, Account $account)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'code1' => ['nullable', 'string', 'max:255'],
            'code2' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'account_type_id' => ['nullable', 'integer', 'exists:account_types,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Handle parent_id updates
        if (array_key_exists('parent_id', $validated)) {
            // If setting parent_id to null, allow it (making it a root account)
            if ($validated['parent_id'] === null) {
                // This is allowed - setting to root
            }
            // Prevent setting parent_id to self
            elseif ($validated['parent_id'] === $account->id) {
                return response()->json(['errors' => ['parent_id' => ['An account cannot be its own parent.']]], 422);
            }
            // Prevent circular references (can't set parent to a descendant)
            else {
                $newParent = Account::find($validated['parent_id']);
                if (!$newParent) {
                    return response()->json(['errors' => ['parent_id' => ['The selected parent account does not exist.']]], 422);
                }

                // Check if the new parent is a descendant of this account (would create circular reference)
                if ($newParent->isDescendantOf($account)) {
                    return response()->json(['errors' => ['parent_id' => ['Cannot set parent to a descendant account as this would create a circular reference.']]], 422);
                }
            }
        }

        $account->update($validated);
        return response()->json($account->load('parent', 'children', 'accountType'));
    }

    public function destroy(Account $account)
    {
        $account->delete();
        return response()->json(null, 204);
    }

    /**
     * Get all sub-accounts (children) of a specific account
     */
    public function subAccounts(Request $request, Account $account)
    {
        $query = $account->children()->orderBy('name');
        
        // Optionally load nested children
        if ($request->boolean('recursive')) {
            $query->with(['children' => function ($query) {
                $query->with('children');
            }]);
        }
        
        return $query->paginate($request->input('per_page', 15));
    }

    /**
     * Get the full tree structure starting from a root account
     */
    public function tree(Request $request)
    {
        $query = Account::query();
        
        // If a specific root account is provided
        if ($request->has('root_id')) {
            $rootAccount = Account::find($request->input('root_id'));
            if (!$rootAccount) {
                return response()->json(['error' => 'Root account not found'], 404);
            }
            
            $rootAccount->load(['children' => function ($query) {
                $query->with('children');
            }]);
            
            return response()->json($rootAccount);
        }
        
        // Otherwise, return all root accounts with their trees
        $rootAccounts = Account::root()
            ->with(['children' => function ($query) {
                $query->with('children');
            }])
            ->get();
        
        return response()->json($rootAccounts);
    }
}

