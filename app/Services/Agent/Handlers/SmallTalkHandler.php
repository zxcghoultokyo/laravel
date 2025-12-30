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
     * Handle unknown/off-topic messages.
     * Instead of saying "I don't understand", AI generates a polite response
     * and invites user back to product search.
     */
    public function handleUnknown(string $message, array $context = []): array
    {
        try {
            $lastCategory = $context['last_category'] ?? null;
            $hasHistory = !empty($context['shown_products']);
            
            $contextHint = '';
            if ($hasHistory && $lastCategory) {
                $contextHint = "Раніше користувач цікавився категорією: {$lastCategory}.";
            }

            $prompt = "Ти — консультант магазину тактичного спорядження (плитоноски, шоломи, бронеплити, взуття, рюкзаки, форма).

Користувач написав: \"{$message}\"

{$contextHint}

Це повідомлення НЕ стосується товарів або замовлень. Твоє завдання:
1. Відповісти коротко, ввічливо, природно (без пафосу)
2. М'яко повернути розмову до підбору товару
3. Якщо питання загальне (погода, політика, особисте) — делікатно поясни що ти консультант магазину
4. Якщо користувач жартує — можеш пожартувати у відповідь, але поверни до справи

Формат відповіді (до 30 слів):
- Коротка реакція на повідомлення
- Запрошення повернутися до підбору товару

Приклади:
- 'Гарне питання, але я більше по плитоносках 😅 Може підберемо щось для тебе?'
- 'Це трохи не моя тема, я спеціалізуюсь на тактичному спорядженні. Чим можу допомогти з товарами?'
- 'Хех, цікаво! Але давай краще про шоломи чи бронеплити — тут я в своїй стихії 💪'

Поверни ТІЛЬКИ текст відповіді, без лапок.";

            $response = $this->aiRouter->callOpenAI($prompt, 0.8, 80);
            $reply = trim($response, " \n\r\t\"'");

            // Validate response
            if (empty($reply) || mb_strlen($reply) > 200) {
                $reply = $this->getFallbackUnknownResponse();
            }

            Log::info('SmallTalkHandler: handled unknown message', [
                'message' => mb_substr($message, 0, 50),
                'response' => mb_substr($reply, 0, 50),
            ]);

            return AgentResponseDTO::text($reply)->toArray();
        } catch (\Throwable $e) {
            Log::warning('SmallTalkHandler: AI failed for unknown', ['error' => $e->getMessage()]);
            return AgentResponseDTO::text($this->getFallbackUnknownResponse())->toArray();
        }
    }

    /**
     * Fallback response when AI fails.
     */
    private function getFallbackUnknownResponse(): string
    {
        $responses = [
            'Цікаво! Але я більше спеціалізуюсь на тактичному спорядженні 🎯 Може підберемо щось?',
            'Хм, це трохи не моя тема. Давай краще про плитоноски чи шоломи — тут я корисніший!',
            'Не зовсім моя область 😅 Але якщо потрібна допомога з тактичним спорядженням — я тут!',
            'Гарне питання, але я консультант по тактиці. Чим можу допомогти з товарами?',
        ];
        return $responses[array_rand($responses)];
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
