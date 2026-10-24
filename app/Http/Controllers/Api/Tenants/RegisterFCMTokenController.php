<?php

namespace App\Http\Controllers\Api\Tenants;

use App\Http\Controllers\Controller;
use App\Models\Tenants\User;
use Illuminate\Http\Request;

class RegisterFCMTokenController extends Controller
{
    public function __invoke(Request $request)
    {
        // Only the token: `is_owner`, `email` and `password` are fillable on User too.
        $data = $request->validate([
            'fcm_token' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::find(auth()->id());
        $user->fill($data);
        $user->save();

        return $this->success([]);
    }
}
