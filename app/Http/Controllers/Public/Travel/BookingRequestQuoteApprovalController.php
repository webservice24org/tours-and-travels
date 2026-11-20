<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Travel;

use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Http\Controllers\Controller;
use App\Models\Travel\TourBookingRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner-only POST endpoints; access secrets are never included in GET URLs. */
final class BookingRequestQuoteApprovalController extends Controller
{
    public function __construct(private readonly TourBookingQuoteAcceptanceManager $quotes) {}

    public function guestPreview(Request $request): JsonResponse
    {
        [$row, $token] = $this->guestInput($request);
        return $this->privateResponse($this->quotes->previewGuest($row, $token));
    }

    public function guestAccept(Request $request): JsonResponse
    {
        [$row, $token] = $this->guestInput($request);
        $input = $request->validate($this->acceptRules());
        return $this->privateResponse($this->quotes->acceptGuest($row, $token,
            (int) $input['revision'], (string) $input['fingerprint']));
    }

    public function customerPreview(Request $request, string $reference): JsonResponse
    {
        return $this->privateResponse($this->quotes->previewCustomer(
            $this->verifiedOwner($request), $this->ownedRequest($reference)
        ));
    }

    public function customerAccept(Request $request, string $reference): JsonResponse
    {
        $input = $request->validate($this->acceptRules());
        return $this->privateResponse($this->quotes->acceptCustomer(
            $this->verifiedOwner($request), $this->ownedRequest($reference),
            (int) $input['revision'], (string) $input['fingerprint']
        ));
    }

    /** @return array{0:TourBookingRequest,1:string} */
    private function guestInput(Request $request): array
    {
        $input = $request->validate([
            'reference' => ['required', 'regex:/^BRQ-[a-f0-9]{32}$/D'],
            'access_token' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $row = TourBookingRequest::query()->where('reference_key', $input['reference'])->firstOrFail();
        return [$row, (string) $input['access_token']];
    }

    private function verifiedOwner(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->email_verified_at !== null, 403);
        return $actor;
    }

    private function ownedRequest(string $reference): TourBookingRequest
    {
        abort_unless(preg_match('/^BRQ-[a-f0-9]{32}$/D', $reference) === 1, 404);
        return TourBookingRequest::query()->where('reference_key', $reference)->firstOrFail();
    }

    /** @return array<string,array<int,string>> */
    private function acceptRules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ];
    }

    /** @param array<string,int|string|bool> $quote */
    private function privateResponse(array $quote): JsonResponse
    {
        return response()->json(['quote' => $quote])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
