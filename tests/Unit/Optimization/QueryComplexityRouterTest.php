<?php

namespace SuperAgent\Tests\Unit\Optimization;

use PHPUnit\Framework\TestCase;
use SuperAgent\Optimization\QueryComplexityRouter;

class QueryComplexityRouterTest extends TestCase
{
    private QueryComplexityRouter $router;

    protected function setUp(): void
    {
        $this->router = new QueryComplexityRouter(
            enabled: true,
            primaryModel: 'claude-sonnet-4-6',
            fastModel: 'claude-haiku-4-5-20251001',
        );
    }

    public function test_simple_question_routes_to_fast_model(): void
    {
        $model = $this->router->route('What time is it?');
        $this->assertEquals('claude-haiku-4-5-20251001', $model);
    }

    public function test_complex_query_returns_null_for_primary(): void
    {
        $model = $this->router->route(
            'Debug the authentication error in src/Auth/LoginController.php. ' .
            'The stack trace shows a null pointer exception on line 42. ' .
            'First check the middleware, then review the session handler.'
        );
        $this->assertNull($model);
    }

    public function test_code_content_is_complex(): void
    {
        $model = $this->router->route("Fix this:\n```php\nfunction test() { return null; }\n```");
        $this->assertNull($model);
    }

    public function test_url_content_is_complex(): void
    {
        $model = $this->router->route('Check https://api.example.com/status for errors');
        $this->assertNull($model);
    }

    public function test_disabled_returns_null(): void
    {
        $router = new QueryComplexityRouter(enabled: false);
        $this->assertNull($router->route('Hello'));
    }

    public function test_already_cheap_model_returns_null(): void
    {
        $router = new QueryComplexityRouter(
            primaryModel: 'claude-haiku-4-5-20251001',
            fastModel: 'claude-haiku-4-5-20251001',
        );
        $this->assertNull($router->route('Hello'));
    }

    public function test_analyze_returns_score(): void
    {
        $analysis = $this->router->analyze('Hi');
        $this->assertTrue($analysis['is_simple']);
        $this->assertLessThan(0.3, $analysis['score']);

        $analysis = $this->router->analyze(
            'Implement a full authentication system with OAuth2, JWT tokens, ' .
            'and role-based access control. First, create the database migrations...'
        );
        $this->assertFalse($analysis['is_simple']);
        $this->assertGreaterThanOrEqual(0.3, $analysis['score']);
    }

    public function test_multi_step_keywords_are_complex(): void
    {
        $model = $this->router->route('First fix the bug, then refactor the code, finally run the tests.');
        $this->assertNull($model);
    }

    public function test_empty_query_returns_null(): void
    {
        $this->assertNull($this->router->route(''));
        $this->assertNull($this->router->route('  '));
    }
}
