<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Log;

/**
 * Traces the full message processing pipeline.
 *
 * Collects step-by-step events as a message flows through:
 *   Controller → Agent → GPT → Tool → Meili/Semantic → Response
 *
 * Usage:
 *   $tracer = PipelineTracer::start($sessionId, $message);
 *   $tracer->step('agent.preprocess', ['slang' => 'тодлер']);
 *   $tracer->step('agent.gpt_call', ['model' => 'gpt-4.1-mini']);
 *   ...
 *   $trace = $tracer->finish();
 */
class PipelineTracer
{
    private string $sessionId;

    private string $message;

    private float $startTime;

    /** @var array<int, array{step: string, data: array, time_ms: int, elapsed_ms: int}> */
    private array $steps = [];

    private static ?self $current = null;

    private function __construct(string $sessionId, string $message)
    {
        $this->sessionId = $sessionId;
        $this->message = $message;
        $this->startTime = microtime(true);
    }

    /**
     * Start tracing a new message pipeline.
     */
    public static function start(string $sessionId, string $message): self
    {
        $instance = new self($sessionId, $message);
        self::$current = $instance;

        $instance->step('pipeline.start', [
            'session_id' => $sessionId,
            'message' => mb_substr($message, 0, 200),
            'message_length' => mb_strlen($message),
            'word_count' => count(preg_split('/\s+/', trim($message))),
        ]);

        return $instance;
    }

    /**
     * Get the current active tracer (if any).
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Record a pipeline step.
     */
    public function step(string $name, array $data = []): self
    {
        $now = microtime(true);
        $stepTime = (int) (($now - ($this->steps ? end($this->steps)['_time'] : $this->startTime)) * 1000);
        $elapsed = (int) (($now - $this->startTime) * 1000);

        $entry = [
            'step' => $name,
            'data' => $data,
            'time_ms' => $stepTime,
            'elapsed_ms' => $elapsed,
            '_time' => $now, // internal, stripped in output
        ];

        $this->steps[] = $entry;

        // Also log each step for pail / log files
        Log::info("TRACE [{$this->sessionId}] {$name}", array_merge($data, [
            'step_ms' => $stepTime,
            'elapsed_ms' => $elapsed,
        ]));

        return $this;
    }

    /**
     * Finish tracing and return the full trace.
     */
    public function finish(): array
    {
        $totalMs = (int) ((microtime(true) - $this->startTime) * 1000);

        $this->step('pipeline.finish', [
            'total_ms' => $totalMs,
            'total_steps' => count($this->steps),
        ]);

        $trace = [
            'session_id' => $this->sessionId,
            'message' => mb_substr($this->message, 0, 200),
            'total_ms' => $totalMs,
            'total_steps' => count($this->steps),
            'steps' => array_map(function ($s) {
                unset($s['_time']);

                return $s;
            }, $this->steps),
        ];

        // Log full summary
        Log::info("TRACE [{$this->sessionId}] SUMMARY", [
            'message' => mb_substr($this->message, 0, 100),
            'total_ms' => $totalMs,
            'steps' => array_map(fn ($s) => $s['step'].' ('.$s['elapsed_ms'].'ms)', $this->steps),
        ]);

        // Store trace for diagnostic retrieval
        $this->storeTrace($trace);

        self::$current = null;

        return $trace;
    }

    /**
     * Get steps collected so far (without finishing).
     */
    public function getSteps(): array
    {
        return array_map(function ($s) {
            unset($s['_time']);

            return $s;
        }, $this->steps);
    }

    /**
     * Get trace summary as a compact array for SSE meta / response meta.
     */
    public function getSummary(): array
    {
        $totalMs = (int) ((microtime(true) - $this->startTime) * 1000);

        return [
            'total_ms' => $totalMs,
            'step_count' => count($this->steps),
            'path' => array_map(fn ($s) => $s['step'], $this->steps),
            'steps' => array_map(function ($s) {
                $entry = [
                    'step' => $s['step'],
                    'elapsed_ms' => $s['elapsed_ms'],
                ];
                // Include key data fields for debugging
                foreach (['results_count', 'products_count', 'category', 'detected_category', 'fallback', 'query', 'filter', 'source', 'model', 'intent', 'handler', 'error'] as $key) {
                    if (isset($s['data'][$key])) {
                        $entry[$key] = $s['data'][$key];
                    }
                }

                return $entry;
            }, $this->steps),
        ];
    }

    /**
     * Store trace in cache for diagnostic endpoint retrieval.
     */
    private function storeTrace(array $trace): void
    {
        try {
            $cacheKey = "pipeline_trace_{$this->sessionId}";
            // Keep last 5 traces per session
            $existing = cache()->get($cacheKey, []);
            $existing[] = $trace;
            $existing = array_slice($existing, -5);
            cache()->put($cacheKey, $existing, now()->addHours(2));
        } catch (\Throwable $e) {
            // Don't let trace storage break the pipeline
            Log::debug('PipelineTracer: cache store failed', ['error' => $e->getMessage()]);
        }
    }
}
