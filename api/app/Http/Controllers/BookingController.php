<?php

namespace App\Http\Controllers;

use App\Services\Sessions\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** S06-3 — student self-service session booking. */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function book(Request $request, string $id): JsonResponse
    {
        return response()->json($this->bookings->book($id, $request->user()));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return response()->json($this->bookings->cancel($id, $request->user()));
    }
}
