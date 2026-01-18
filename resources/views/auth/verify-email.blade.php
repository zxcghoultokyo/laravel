<x-guest-layout>
    <div class="mb-6 text-center">
        <h2 class="text-2xl font-bold text-gray-900">Підтвердіть email</h2>
        <p class="mt-2 text-sm text-gray-600">Дякуємо за реєстрацію! Перевірте вашу пошту і натисніть на посилання для підтвердження.</p>
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 p-3 rounded-lg bg-emerald-50 text-sm text-emerald-700">
            ✓ Нове посилання надіслано на вашу email адресу!
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-primary-button>
                Надіслати ще раз
            </x-primary-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm text-gray-600 hover:text-emerald-600">
                Вийти
            </button>
        </form>
    </div>
</x-guest-layout>
