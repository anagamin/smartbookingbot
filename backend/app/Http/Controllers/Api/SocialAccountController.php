<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\Request;

class SocialAccountController extends Controller
{
    public function index(Request $request)
    {
        return SocialAccount::query()
            ->where('user_id', $request->user()->id)
            ->get(['id', 'provider', 'provider_user_id', 'expires_at', 'scopes', 'meta', 'created_at'])
            ->map(function (SocialAccount $a) {
                $row = $a->toArray();
                $meta = $row['meta'] ?? [];
                if (is_array($meta)) {
                    unset($meta['callback_secret']);
                    $row['meta'] = $meta;
                }

                return $row;
            });
    }

    public function destroy(Request $request, SocialAccount $socialAccount)
    {
        abort_if($socialAccount->user_id !== $request->user()->id, 403);
        $socialAccount->delete();

        return response()->json(['ok' => true]);
    }
}
