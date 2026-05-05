<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentHistoryController extends Controller
{
    public function index()
    {
        $team = Auth::user()->currentTeam;

        if (!$team) {
            return response()->json([], 200);
        }

        $payments = Payment::where('team_id', $team->id)
            ->orderBy('paid_at', 'desc')
            ->get();

        return response()->json($payments);
    }
}
