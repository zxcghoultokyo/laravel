<?php

namespace App\Http\Controllers\Contractor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (session('contractor_authenticated')) {
            return redirect()->route('contractor.rozetka.index');
        }

        return view('contractor.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $validUsername = config('services.contractor.username');
        $validPasswordHash = config('services.contractor.password_hash');

        if (
            $request->input('username') === $validUsername
            && Hash::check($request->input('password'), $validPasswordHash)
        ) {
            $request->session()->regenerate();
            $request->session()->put('contractor_authenticated', true);
            $request->session()->put('contractor_username', $validUsername);

            return redirect()->route('contractor.rozetka.index');
        }

        return back()->withErrors([
            'credentials' => 'Невірний логін або пароль.',
        ])->withInput(['username' => $request->input('username')]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget(['contractor_authenticated', 'contractor_username']);

        return redirect()->route('contractor.login');
    }
}
