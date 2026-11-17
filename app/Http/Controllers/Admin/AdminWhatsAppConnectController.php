<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppService;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Owner page for the WhatsApp number used by wa-gateway (Baileys): scan the QR to link it,
 * see which number is connected, or unlink it.
 */
class AdminWhatsAppConnectController extends Controller
{
    public function show(WhatsAppService $whatsapp): Response
    {
        return response()->view('admin.whatsapp.connect', $this->viewData($whatsapp))
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Polled every 5 seconds by admin.js while the number is not connected.
     */
    public function status(Request $request, WhatsAppService $whatsapp): JsonResponse
    {
        // Background polling must not use up a flash message meant for the next page.
        $request->session()->reflash();
        $data = $this->viewData($whatsapp);

        return response()->json([
            'state' => $data['state'],
            'html' => view('admin.whatsapp.partials.connect-status', $data)->render(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function logout(WhatsAppService $whatsapp): RedirectResponse
    {
        $message = $whatsapp->gatewayLogout()
            ? 'Nomor WhatsApp sudah diputus dari SatSet. Untuk menyambungkan lagi, jalankan ulang wa-gateway lalu scan QR baru.'
            : 'wa-gateway tidak bisa dihubungi, jadi nomor belum diputus. Pastikan gateway berjalan, lalu coba lagi.';

        return redirect()->route('admin.whatsapp.connect')->with('status', $message);
    }

    private function viewData(WhatsAppService $whatsapp): array
    {
        $configured = $whatsapp->baileysConfigured();
        $session = $configured ? $whatsapp->gatewaySession(fresh: true) : [];
        $state = $configured ? $session['state'] : 'not_configured';
        $qr = $state === 'qr' ? $whatsapp->gatewayQr() : null;

        return [
            'provider' => $whatsapp->provider(),
            'enabled' => (bool) config('whatsapp.enabled'),
            'state' => $state,
            'phone' => $session['phone'] ?? null,
            'sentToday' => $session['sent_today'] ?? null,
            'qrImage' => $qr !== null ? $this->qrDataUri($qr) : null,
        ];
    }

    /**
     * The QR string is rendered here (endroid/qr-code) and embedded as a data URI, so it is never
     * stored or served from a cacheable URL.
     */
    private function qrDataUri(string $qr): string
    {
        return (new SvgWriter)->write(new QrCode(
            data: $qr, errorCorrectionLevel: ErrorCorrectionLevel::Low, size: 280, margin: 16,
        ))->getDataUri();
    }
}
