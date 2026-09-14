<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SYSTEM_KEY = 'admin.users';

    private const PERMISSION = 'manager-users|reset-subordinate-passwords';

    public function up(): void
    {
        DB::table('menus')
            ->where('system_key', self::SYSTEM_KEY)
            ->update(['permission' => self::PERMISSION]);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('system_key', self::SYSTEM_KEY)
            ->update(['permission' => 'manager-users']);
    }
};
