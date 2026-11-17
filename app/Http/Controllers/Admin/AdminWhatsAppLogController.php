<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWhatsAppMessage;
use App\Models\WhatsappLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AdminWhatsAppLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $status = $request->string('status')->toString();
        $logs = WhatsappLog::query()->with(['restaurant', 'queueTicket'])
            ->when(in_array($status, ['received', 'pending', 'sending', 'sent', 'delivered', 'read', 'failed', 'skipped'], true),
                fn ($query) => $query->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        return view('admin.whatsapp-logs.index', compact('logs', 'status'));
    }

    /**
     * Queue a failed or stuck outgoing message again. The owner checks the outlet phone first,
     * because an uncertain send may already have reached the customer.
     */
    public function retry(Request $request, WhatsappLog $log): RedirectResponse
    {
        abort_unless($log->canRetry(), 409, 'Pesan ini tidak bisa dikirim ulang.');

        $requeued = WhatsappLog::query()->whereKey($log->id)->where('direction', 'outbound')
            ->where(fn ($query) => $query->where('status', 'failed')
                ->orWhere(fn ($stuck) => $stuck->where('status', 'sending')
                    ->where('updated_at', '<=', now()->subMinutes(WhatsappLog::STUCK_SENDING_MINUTES))))
            ->update(['status' => 'pending', 'attempts' => 0, 'error_code' => null, 'updated_at' => now()]);
        if ($requeued) {
            Log::info('WhatsApp message queued again by owner', ['log_id' => $log->id, 'user_id' => $request->user()->id]);
            DeliverWhatsAppMessage::dispatch($log->id)->afterCommit();
        }

        return back()->with('status', $requeued ? 'Pesan dijadwalkan untuk dikirim ulang.' : 'Status pesan sudah berubah; tidak dikirim ulang.');
    }
}
