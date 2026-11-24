<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use App\Models\Restaurant;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\ErrorCorrectionLevel;

class AdminQrController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.qr.index', [
            'restaurants' => $request->user()->accessibleRestaurants()->orderBy('name')->get(),
            'baseUrl' => $this->baseUrl($request),
        ]);
    }

    public function show(Request $request, Restaurant $restaurant): View
    {
        return view('admin.qr.show', ['restaurant' => $restaurant, 'url' => $this->url($request, $restaurant)]);
    }

    public function image(Request $request, Restaurant $restaurant): Response
    {
        $result = (new SvgWriter())->write(new QrCode(
            data: $this->url($request, $restaurant), errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 360, margin: 32,
        ));

        return response($result->getString(), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="satset-'.$restaurant->slug.'.svg"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * With WhatsApp ready, the QR opens a chat with the bot directly (the visitor's first message starts the flow).
     * Otherwise it opens the outlet page as before.
     */
    private function url(Request $request, Restaurant $restaurant): string
    {
        $whatsappEntry = app(\App\Services\WhatsAppService::class)->entryUrl($restaurant);
        if ($whatsappEntry !== null) {
            return $whatsappEntry;
        }

        return $this->baseUrl($request).route('outlets.show', $restaurant->slug, false);
    }

    /**
     * APP_URL, unless it still points at this computer while the admin is browsing via its
     * network address (e.g. http://192.168.1.10:8000). Then phones on the same Wi-Fi can scan it.
     */
    private function baseUrl(Request $request): string
    {
        $publicUrl = app(\App\Services\PublicUrl::class);
        $appUrl = $publicUrl->configured();
        if (! $publicUrl->isLocal($appUrl)) {
            return $appUrl;
        }
        // Admin is browsing via a LAN address or a tunnel: that address is reachable from phones.
        if (! $publicUrl->isLocal($request->getSchemeAndHttpHost())) {
            return $request->getSchemeAndHttpHost();
        }

        return $publicUrl->base();
    }
}
