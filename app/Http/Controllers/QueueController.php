<?php

namespace App\Http\Controllers;

use App\Models\QueueTicket;
use App\Services\QueueService;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function show(QueueTicket $ticket, QueueService $queueService)
    {
        $ticket->load('restaurant');
        $position = $queueService->calculateCustomerPosition($ticket);
        $estimatedWait = $queueService->estimatedWaitForAhead($ticket->restaurant, $position - 1);

        return response()->view('queue.show', compact('ticket', 'position', 'estimatedWait'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function status(Request $request, QueueTicket $ticket, QueueService $queueService)
    {
        // Background polling must not consume a flash message meant for the next page.
        $request->session()->reflash();
        $ticket->load('restaurant');
        // Polled every 10 seconds per open ticket page: the position is counted once.
        $position = $queueService->calculateCustomerPosition($ticket);

        return response()->json([
            'status' => $ticket->status,
            'position' => $position,
            'people_before' => max($position - 1, 0),
            'estimated_wait' => $queueService->estimatedWaitForAhead($ticket->restaurant, $position - 1),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, QueueTicket $ticket, QueueService $queueService)
    {
        $queueService->cancelTicket($ticket);

        return redirect()->route('queue.show', ['ticket' => $ticket->public_token])->with('status', 'Antrean berhasil dibatalkan.');
    }
}
