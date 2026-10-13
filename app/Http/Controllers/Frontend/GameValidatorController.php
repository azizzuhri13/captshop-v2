<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\GameValidator\GameValidatorInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameValidatorController extends Controller
{
    public function __construct(
        protected GameValidatorInterface $validator,
    ) {}

    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_code' => ['required', 'string', 'max:50'],
            'user_id' => ['required', 'string', 'max:50'],
            'zone_id' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $this->validator->checkNickname(
            $validated['game_code'],
            $validated['user_id'],
            $validated['zone_id'] ?? null
        );

        return response()->json($result);
    }
}
