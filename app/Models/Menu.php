<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Menu extends Model
{
    use HasUuids;

    protected $table = 'menus';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'parent_id',
        'title',
        'route',
        'icon',
        'permission',
        'order',
        'is_active',
        'type' // 'menu' or 'submenu'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer'
    ];

    // Self-referential relationships
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('order');
    }

    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    // Scope for root menus (no parent)
    public function scopeRootMenus($query)
    {
        return $query->whereNull('parent_id');
    }

    // Scope for submenus (has parent)
    public function scopeSubMenus($query)
    {
        return $query->whereNotNull('parent_id');
    }

    // Scope for active menus
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission', 'name');
    }

    // Helper method to get menu hierarchy
    public function getHierarchy()
    {
        return $this->load('children.allChildren');
    }
}
