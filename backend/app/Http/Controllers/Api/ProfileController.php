<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sex' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'services_description' => ['nullable', 'string', 'max:20000'],
        ]);

        $request->user()->update($data);

        return response()->json([
            'user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'sex' => $request->user()->sex,
                'services_description' => $request->user()->services_description,
            ],
        ]);
    }
}
