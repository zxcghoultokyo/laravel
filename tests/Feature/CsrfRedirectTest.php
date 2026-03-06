<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsrfRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_csrf_on_web_request_redirects_to_login(): void
    {
        // Simulate expired CSRF token by making the exception handler process it
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $request = \Illuminate\Http\Request::create('/login', 'POST');
        $e = new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.');

        $response = $handler->render($request, $e);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContains('/login', $response->headers->get('Location'));
    }

    public function test_expired_csrf_on_api_request_returns_json_419(): void
    {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $request = \Illuminate\Http\Request::create('/api/chat', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $e = new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.');

        $response = $handler->render($request, $e);

        $this->assertEquals(419, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('session_expired', $data['error']);
    }

    public function test_other_http_exceptions_not_affected(): void
    {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $request = \Illuminate\Http\Request::create('/nonexistent', 'GET');
        $e = new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

        $response = $handler->render($request, $e);

        $this->assertEquals(404, $response->getStatusCode());
    }

    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertNotNull($haystack);
        $this->assertTrue(str_contains($haystack, $needle), "Failed asserting that '$haystack' contains '$needle'");
    }
}
