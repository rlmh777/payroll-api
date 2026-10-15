<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class JournalEntryController extends Controller
{
    public function index()
    {
        return response()->json(
            JournalEntry::query()
                ->with('payrollRun')
                ->latest('entry_date')
                ->paginate()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required','uuid'],
            'entry_date' => ['required','date'],
            'memo' => ['required','string'],
            'source' => ['required','string','max:191'],
            'payroll_run_id' => ['required','uuid','exists:payroll_runs,id'],
        ]);

        $entry = JournalEntry::create($data);

        return response()->json($entry->load('payrollRun'), Response::HTTP_CREATED);
    }

    public function show(JournalEntry $journalEntry)
    {
        return response()->json($journalEntry->load('payrollRun'));
    }

    public function update(Request $request, JournalEntry $journalEntry)
    {
        $data = $request->validate([
            'entry_date' => ['sometimes','required','date'],
            'memo' => ['sometimes','required','string'],
            'source' => ['sometimes','required','string','max:191'],
            'payroll_run_id' => ['sometimes','required','uuid','exists:payroll_runs,id'],
        ]);

        $journalEntry->update($data);

        return response()->json($journalEntry->load('payrollRun'));
    }

    public function destroy(JournalEntry $journalEntry)
    {
        $journalEntry->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}


