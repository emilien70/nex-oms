<?php

namespace Modules\CustomerPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\CustomerPortal\Services\PortalDocumentService;
use Modules\CustomerPortal\Services\PortalPresentationService;
use Modules\CustomerPortal\Services\PortalUrlService;
use Modules\CustomerPortal\Services\PortalVerificationService;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Ksef\Exceptions\KsefApiException;
use Symfony\Component\HttpFoundation\Response;

class PortalController extends Controller
{
    public function __construct(private readonly PortalAccessService $access, private readonly PortalPresentationService $presentation,
        private readonly PortalVerificationService $verification, private readonly PortalUrlService $urls) {}

    public function unavailable(): Response
    {
        return $this->page(null, 404);
    }

    public function show(Request $request, string $orderNumber, string $token): Response
    {
        $grant = $this->grant($request, $orderNumber, $token);
        if (! $grant) {
            return $this->page(null, 404);
        }

        return $this->page($this->presentation->data($grant['order'], $grant['settings'], $token, verified: $this->verification->verified($request, $grant)));
    }

    public function details(Request $request, string $orderNumber, string $token): Response
    {
        $grant = $this->grant($request, $orderNumber, $token);
        if (! $grant || ! $grant['settings']->show_address) {
            return $this->page(null, 404);
        }
        if ($this->verification->verified($request, $grant) || ! $grant['settings']->masksPersonalData()) {
            return $this->secure(redirect()->to($this->urls->link($grant['link']), 303));
        }

        return $this->challenge($grant);
    }

    public function verify(Request $request, string $orderNumber, string $token, PortalDocumentService $documents): Response
    {
        $grant = $this->grant($request, $orderNumber, $token);
        if (! $grant) {
            return $this->page(null, 404);
        }
        $invoice = $request->input('invoice');
        if ($invoice !== null && (! is_string($invoice) || preg_match('/^[1-9][0-9]{0,17}$/D', $invoice) !== 1)) {
            return $this->page(null, 404);
        }
        if ($invoice !== null) {
            $document = $grant['order']->invoices()->whereKey($invoice)->first();
            if (! $document || ! $documents->available($document, $grant['settings'])) {
                return $this->page(null, 404);
            }
        }
        if ($this->verification->blocked($request, $grant)) {
            return $this->challenge($grant, $invoice, 'Zbyt wiele prób. Spróbuj ponownie za minutę.', 429);
        }
        if (! $this->verification->verify($request, $grant, $request->input('portal_value'))) {
            return $this->challenge($grant, $invoice, 'Nie udało się potwierdzić danych. Sprawdź wpisaną wartość lub skontaktuj się ze sklepem.', 422);
        }
        $target = $invoice === null ? $this->urls->link($grant['link']) : $this->urls->route('customer-portal.document', [
            'orderNumber' => $grant['order']->id, 'token' => $token, 'invoice' => $invoice,
        ]);

        return $this->secure(redirect()->to($target, 303));
    }

    private function challenge(array $grant, ?string $invoice = null, ?string $error = null, int $status = 200): Response
    {
        $data = $this->presentation->data($grant['order'], $grant['settings'], $grant['link']->token);
        $data['challenge'] = ['action' => $this->urls->route('customer-portal.verify', [
            'orderNumber' => $grant['order']->id, 'token' => $grant['link']->token,
        ]), 'invoice' => $invoice, 'error' => $error, 'method' => $grant['settings']->verificationMethod(),
            'back' => $this->urls->link($grant['link'])];

        return $this->page($data, $status);
    }

    private function grant(Request $request, string $orderNumber, string $token): ?array
    {
        $keys = ['portal-miss-ip:'.hash('sha256', (string) $request->ip()),
            'portal-miss-order:'.hash('sha256', $request->ip().'|'.$orderNumber)];
        abort_if(RateLimiter::tooManyAttempts($keys[0], 60) || RateLimiter::tooManyAttempts($keys[1], 15), 429);
        $grant = $this->access->resolve($token, orderNumber: (int) $orderNumber);
        if (! $grant) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, 60);
            }
        }

        return $grant;
    }

    public function preview(Request $request, Order $order): Response
    {
        abort_unless($this->access->ready(), 503);

        return $this->page($this->presentation->data($order, $this->access->settings(), preview: true));
    }

    public function document(Request $request, string $orderNumber, string $token, string $invoice, PortalDocumentService $documents, InvoicePdfFilenameGenerator $names): Response
    {
        $grant = $this->grant($request, $orderNumber, $token);
        if (! $grant) {
            return $this->page(null, 404);
        }

        $document = $grant['order']->invoices()->whereKey($invoice)->first();
        if (! $document || ! $documents->available($document, $grant['settings'])) {
            return $this->page(null, 404);
        }
        if ($grant['settings']->verifiesDocuments() && ! $this->verification->verified($request, $grant)) {
            return $this->challenge($grant, $invoice);
        }

        return $this->pdf($grant['order'], $invoice, $documents, $names);
    }

    public function previewDocument(Order $order, string $invoice, PortalDocumentService $documents, InvoicePdfFilenameGenerator $names): Response
    {
        abort_unless($this->access->ready(), 503);

        return $this->pdf($order, $invoice, $documents, $names, true);
    }

    private function pdf(Order $order, string $invoice, PortalDocumentService $documents, InvoicePdfFilenameGenerator $names, bool $preview = false): Response
    {
        $invoice = $order->invoices()->whereKey($invoice)->first();
        $settings = $this->access->settings();
        if (! $invoice || ! $documents->available($invoice, $settings, $preview)) {
            return $this->page(null, 404);
        }
        try {
            $contents = $documents->contents($invoice, $settings, $preview);
        } catch (InvoiceDomainException|KsefApiException) {
            return $this->page(null, 404);
        }

        return $this->secure(response($contents, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$names->downloadName($invoice).'"']));
    }

    public function image(Request $request, string $orderNumber, string $token, string $item): Response
    {
        $grant = $this->grant($request, $orderNumber, $token);
        if (! $grant || ! $grant['settings']->show_images) {
            return $this->page(null, 404);
        }

        return $this->productImage($grant['order'], $item);
    }

    public function previewImage(Order $order, string $item): Response
    {
        abort_unless($this->access->ready() && $this->access->settings()->show_images, 404);

        return $this->productImage($order, $item);
    }

    private function productImage(Order $order, string $item): Response
    {
        $product = $order->items()->whereKey($item)->first()?->product;
        $path = $product?->thumbnail_path ?: $product?->image_path;

        return $this->imageFile($path, 'products/'.($product?->id ?? 0).'/');
    }

    public function logo(Request $request, string $orderNumber, string $token): Response
    {
        $grant = $this->grant($request, $orderNumber, $token);
        if (! $grant) {
            return $this->page(null, 404);
        }

        return $this->imageFile($grant['settings']->logo_path, 'customer-portal/');
    }

    public function previewLogo(Order $order): Response
    {
        return $this->imageFile($this->access->settings()->logo_path, 'customer-portal/');
    }

    private function imageFile(?string $path, string $prefix): Response
    {
        if (! $path || ! str_starts_with($path, $prefix) || str_contains($path, '..') || ! Storage::disk('local')->exists($path)
            || Storage::disk('local')->size($path) > 10 * 1024 * 1024) {
            return $this->secure(response('', 404));
        }
        $contents = Storage::disk('local')->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $this->secure(response('', 404));
        }

        return $this->secure(response($contents, 200, ['Content-Type' => $mime]));
    }

    private function page(?array $data, int $status = 200): Response
    {
        $nonce = Str::random(32);
        $response = $this->secure(response()->view('customer-portal.show', compact('data', 'nonce'), $status));
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; img-src 'self' data:; base-uri 'none'; form-action 'self' https://paynow.pl https://*.paynow.pl; frame-ancestors 'none'");

        return $response;
    }

    private function secure(Response $response): Response
    {
        foreach (['Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY'] as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
