<?php

use Illuminate\Support\Facades\Route;
use App\Models\Employee;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test', function () {

    $employee = Employee::whereBlind('socialSecurityNumber','socialSecurityNumberIndex', '34222')->first();
    return response()->json($employee);
});
