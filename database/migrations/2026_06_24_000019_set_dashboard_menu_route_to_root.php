<?php

use App\Models\Menu;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Menu::query()
            ->where('route', '/dashboard')
            ->update(['route' => '/']);
    }

    public function down(): void
    {
        Menu::query()
            ->where('route', '/')
            ->where('name', 'Dashboard')
            ->update(['route' => '/dashboard']);
    }
};
