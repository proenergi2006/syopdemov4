<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SSOController extends Controller
{
    public function login(Request $request)
    {
        $payload = json_decode(
            base64_decode($request->token),
            true
        );

        $user = User::where(
            'username',
            $payload['username']
        )->first();

        if (!$user) {
            return response()->json([
                'message' => 'User tidak ditemukan'
            ], 403);
        }

        Auth::login($user);

        $request->session()->regenerate();

        return response()->json([
            'success' => true
        ]);
    }
}
