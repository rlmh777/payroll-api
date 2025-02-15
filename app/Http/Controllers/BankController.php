<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Http\Request;

class BankController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Bank::all();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        return Bank::create($request->all());
    }

    /**
     * Display the specified resource.
     */
    public function show(Bank $bank)
    {
        return Bank::find($bank.id);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Bank $bank)
    {
        $bank = Banl::findOrFail($bank.id);
        $bank->update($request->all());

        return $bank;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bank $bank)
    {
        $bank = Bank::findOrFail($bank.id);
        $bank->delete();

        return 204;
    }
}
