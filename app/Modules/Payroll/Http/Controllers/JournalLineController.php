<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class JournalLineController extends Controller
{
    public function index()
    {
        return response()->json(
            JournalLine::query()
                ->with(['journal', 'account'])
                ->latest('created_at')
                ->paginate()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required','uuid'],
            'journal_id' => ['required','uuid','exists:journal_entries,id'],
            'account_id' => ['required','uuid','exists:accounts,id'],
            'description' => ['nullable','string','max:191'],
            'debit' => ['required','numeric','min:0'],
            'credit' => ['required','numeric','min:0'],
        ]);

        // Enforce (debit = 0 OR credit = 0)
        if (!($data['debit'] == 0 || $data['credit'] == 0)) {
            return response()->json(['message' => 'Either debit or credit must be zero.'], 422);
        }

        $line = JournalLine::create($data);

        return response()->json($line->load(['journal', 'account']), Response::HTTP_CREATED);
    }

    public function show(JournalLine $journalLine)
    {
        return response()->json($journalLine->load(['journal', 'account']));
    }

    public function update(Request $request, JournalLine $journalLine)
    {
        $data = $request->validate([
            'journal_id' => ['sometimes','required','uuid','exists:journal_entries,id'],
            'account_id' => ['sometimes','required','uuid','exists:accounts,id'],
            'description' => ['nullable','string','max:191'],
            'debit' => ['sometimes','required','numeric','min:0'],
            'credit' => ['sometimes','required','numeric','min:0'],
        ]);

        if ((isset($data['debit']) || isset($data['credit']))) {
            $debit = $data['debit'] ?? $journalLine->debit;
            $credit = $data['credit'] ?? $journalLine->credit;
            if (!($debit == 0 || $credit == 0)) {
                return response()->json(['message' => 'Either debit or credit must be zero.'], 422);
            }
        }

        $journalLine->update($data);

        return response()->json($journalLine->load(['journal', 'account']));
    }

    public function destroy(JournalLine $journalLine)
    {
        $journalLine->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}


