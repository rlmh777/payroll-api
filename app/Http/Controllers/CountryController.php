<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Country::all();
    }


    /**
     * Display the specified resource.
     */
    public function show(Country $country): JsonResponse
    {
        return response()->json($country, 200);

    }

}
