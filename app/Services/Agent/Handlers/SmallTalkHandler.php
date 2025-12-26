<?php

namespace App\Services\Agent\Handlers;

use App\DTO\AgentResponseDTO;
use App\Services\Ai\AiRouter;
use Illuminate\Support\Facades\Log;

/**
 * Handler for smalltalk intent (greetings, thanks, etc.).
 */
class SmallTalkHandler
{
    public function __construct(
        private AiRouter $aiRouter,
    ) {}

    /**
     * Handle smalltalk request.
     */
    public function handle(string $message, array $plan, array $context): AgentResponseDTO
    {
        try {
            $prompt = "Користувач написав: \"{$message}\"

Ти — консультант магазину тактичного спорядження (плитоноски, шоломи, бронеплити, взуття).

Напиши коротку відповідь українською (до 12 слів):
- Природно, як жива людина (не 'радію зустрічі', не 'вітаю в нашому магазині')
- Дружньо, але без зайвого пафосу
- Можна з емодзі (але не багато)
- Покажи готовність допомогти

Приклади добрих відповідей на привіт:
- 'Привіт! Що шукаємо?'
- 'Вітаю 👋 Чим можу допомогти?'
- 'Привіт! Розкажи, що потрібно.'

Поверни ТІЛЬКИ текст відповіді, без лапок.";

            $response = $this->aiRouter->callOpenAI($prompt, 0.7, 50);
            $reply = trim($response, " \n\r\t\"'");

            if (empty($reply) || mb_strlen($reply) > 100) {
                $reply = $this->getRandomGreeting($message);
            }

            return AgentResponseDTO::smallTalk($reply);
        } catch (\Throwable $e) {
            Log::warning('SmallTalkHandler: AI failed', ['error' => $e->getMessage()]);
            return AgentResponseDTO::smallTalk($this->getRandomGreeting($message));
        }
    }

    /**
     * Get random fallback greeting based on input type.
     */
    private function getRandomGreeting(string $message): string
    {
        $lower = mb_strtolower($message);

        // Greetings
        if ($this->containsAny($lower, ['привіт', 'привет', 'hi', 'hello', 'вітаю', 'добрий'])) {
            $greetings = [
                'Привіт! Що шукаємо?',
                'Вітаю 👋 Чим можу допомогти?',
                'Привіт! Розкажи, що потрібно.',
                'Хей! Чим можу допомогти?',
            ];
            return $greetings[array_rand($greetings)];
        }

        // Thanks
        if ($this->containsAny($lower, ['дякую', 'спасибі', 'thanks', 'thank'])) {
            $thanks = [
                'Будь ласка! Звертайтесь, якщо потрібна допомога.',
                'Радий допомогти! Якщо будуть питання — пишіть.',
                'Немає за що! Гарного дня!',
            ];
            return $thanks[array_rand($thanks)];
        }

        // Goodbye
        if ($this->containsAny($lower, ['бувай', 'до побачення', 'bye', 'пока', 'бай'])) {
            $goodbyes = [
                'До побачення! Гарного дня!',
                'Бувайте! Звертайтесь будь-коли.',
                'До зустрічі! Слава Україні! 🇺🇦',
            ];
            return $goodbyes[array_rand($goodbyes)];
        }

        // Default
        return '👋 Слухаю вас! Чим можу допомогти?';
    }

    /**
     * Check if string contains any of the patterns.
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
