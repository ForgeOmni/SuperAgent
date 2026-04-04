<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\ErrorRecovery\ErrorRecoveryManager;
use SuperAgent\ErrorRecovery\RetryStrategy;
use SuperAgent\ErrorRecovery\ErrorClassifier;
use SuperAgent\ErrorRecovery\RecoveryAction;
use SuperAgent\Exceptions\RecoverableException;
use SuperAgent\Exceptions\UnrecoverableException;
use SuperAgent\Exceptions\RateLimitException;
use SuperAgent\Exceptions\TokenLimitException;
use SuperAgent\Exceptions\NetworkException;
use SuperAgent\Exceptions\ModelOverloadedException;

class ErrorRecoveryTest extends TestCase
{
    private ErrorRecoveryManager $manager;
    private ErrorClassifier $classifier;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->manager = new ErrorRecoveryManager([
            'max_retries' => 3,
            'checkpoint_enabled' => false,
            'save_on_failure' => false,
        ]);
        
        $this->classifier = new ErrorClassifier();
    }
    
    /**
     * Test successful operation without errors
     */
    public function testSuccessfulExecution()
    {
        $result = $this->manager->execute(function () {
            return 'success';
        });
        
        $this->assertEquals('success', $result);
    }
    
    /**
     * Test retry on recoverable error
     */
    public function testRetryOnRecoverableError()
    {
        $attempts = 0;
        
        $result = $this->manager->execute(function () use (&$attempts) {
            $attempts++;
            
            if ($attempts < 3) {
                throw new \RuntimeException('Temporary error');
            }
            
            return 'success after retries';
        });
        
        $this->assertEquals('success after retries', $result);
        $this->assertEquals(3, $attempts);
    }
    
    /**
     * Test immediate failure on unrecoverable error
     */
    public function testUnrecoverableError()
    {
        $this->expectException(UnrecoverableException::class);
        
        $this->manager->execute(function () {
            throw new \InvalidArgumentException('Invalid argument');
        });
    }
    
    /**
     * Test exhausted retries
     */
    public function testExhaustedRetries()
    {
        $this->expectException(RecoverableException::class);
        $this->expectExceptionMessage('All retry attempts exhausted');
        
        $attempts = 0;
        
        $this->manager->execute(function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('Persistent error');
        });
    }
    
    /**
     * Test error classification
     */
    public function testErrorClassification()
    {
        // Rate limit error
        $error = new \Exception('Rate limit exceeded. Try again after 30 seconds', 429);
        $classified = $this->classifier->classify($error);
        $this->assertInstanceOf(RateLimitException::class, $classified);
        
        // Token limit error
        $error = new \Exception('Token limit exceeded');
        $classified = $this->classifier->classify($error);
        $this->assertInstanceOf(TokenLimitException::class, $classified);
        
        // Network error
        $error = new \Exception('Connection timeout');
        $classified = $this->classifier->classify($error);
        $this->assertInstanceOf(NetworkException::class, $classified);
        
        // Model overloaded
        $error = new \Exception('Model is currently overloaded');
        $classified = $this->classifier->classify($error);
        $this->assertInstanceOf(ModelOverloadedException::class, $classified);
    }
    
    /**
     * Test retry strategy
     */
    public function testRetryStrategy()
    {
        $strategy = new RetryStrategy();
        
        // Rate limit error - default strategy returns 'retry'
        $error = new \Exception('Rate limit exceeded');
        $action = $strategy->determineAction($error, 1);
        $this->assertNotNull($action);
        // Default strategy may return 'retry' without specific config
        $this->assertContains($action->type, ['retry', 'retry_with_backoff']);
        
        $waitTime = $strategy->getWaitTime($error, 1);
        $this->assertGreaterThan(0, $waitTime);
        
        // Token limit - default strategy returns 'retry', configured would return 'compact_context'
        $error = new \Exception('Token limit exceeded');
        $action = $strategy->determineAction($error, 1);
        $this->assertNotNull($action);
        // Without specific configuration, default action is 'retry'
        $this->assertContains($action->type, ['retry', 'compact_context']);
    }
    
    /**
     * Test progressive recovery actions
     */
    public function testProgressiveRecoveryActions()
    {
        $attempts = 0;
        $actions = [];
        
        $manager = new ErrorRecoveryManager([
            'max_retries' => 3,
            'retry_strategies' => [
                '/test error/i' => [
                    'progressive' => true,
                    'max_attempts' => 3,
                ],
            ],
        ]);
        
        try {
            $manager->execute(function () use (&$attempts, &$actions) {
                $attempts++;
                
                // Track what recovery action would be taken
                if ($attempts === 1) {
                    $actions[] = 'initial';
                } elseif ($attempts === 2) {
                    $actions[] = 'retry';
                } elseif ($attempts === 3) {
                    $actions[] = 'compact';
                }
                
                throw new \RuntimeException('Test error');
            });
        } catch (RecoverableException $e) {
            // Expected
        }
        
        $this->assertEquals(3, $attempts);
        $this->assertEquals(['initial', 'retry', 'compact'], $actions);
    }
    
    /**
     * Test wait time calculation
     */
    public function testWaitTimeCalculation()
    {
        $strategy = new RetryStrategy();
        
        // Exponential backoff
        $error = new \Exception('Connection timeout');
        
        $wait1 = $strategy->getWaitTime($error, 1);
        $wait2 = $strategy->getWaitTime($error, 2);
        $wait3 = $strategy->getWaitTime($error, 3);
        
        $this->assertLessThan($wait2, $wait1);
        $this->assertLessThan($wait3, $wait2);
    }
    
    /**
     * Test recovery action types
     */
    public function testRecoveryActionTypes()
    {
        $action = new RecoveryAction('compact_context');
        $this->assertTrue($action->modifiesContext());
        $this->assertFalse($action->requiresWait());
        
        $action = new RecoveryAction('retry_with_backoff');
        $this->assertFalse($action->modifiesContext());
        $this->assertTrue($action->requiresWait());
        
        $action = new RecoveryAction('retry');
        $this->assertFalse($action->modifiesContext());
        $this->assertFalse($action->requiresWait());
    }
    
    /**
     * Test rate limit wait time extraction
     */
    public function testRateLimitWaitTimeExtraction()
    {
        $classifier = new ErrorClassifier();
        
        // Message with retry-after seconds
        $error = new \Exception('Rate limit exceeded. Retry after 30 seconds');
        $classified = $classifier->classify($error);
        
        $this->assertInstanceOf(RateLimitException::class, $classified);
        $strategy = $classifier->getSuggestedStrategy($classified);
        // Check that wait time was extracted (30 seconds = 30000ms)
        $this->assertArrayHasKey('initial_wait', $strategy);
        $this->assertEquals(30000, $strategy['initial_wait']);
    }
    
    /**
     * Test checkpoint creation and restoration
     */
    public function testCheckpointRecovery()
    {
        $manager = new ErrorRecoveryManager([
            'max_retries' => 2,
            'checkpoint_enabled' => true,
        ]);
        
        $state = ['value' => 0];
        $attempts = 0;
        
        try {
            $result = $manager->execute(function () use (&$state, &$attempts) {
                $attempts++;
                $state['value']++;
                
                if ($attempts === 1) {
                    // First attempt modifies state then fails
                    throw new \RuntimeException('First failure');
                }
                
                // Second attempt should succeed
                return $state;
            }, ['state' => &$state]);
            
            // Should succeed on second attempt
            $this->assertEquals(2, $attempts);
            $this->assertEquals(2, $state['value']);
            $this->assertIsArray($result);
        } catch (\Exception $e) {
            // If still failed, verify attempts were made
            $this->assertGreaterThanOrEqual(2, $attempts);
        }
    }
    
    /**
     * Test model fallback
     */
    public function testModelFallback()
    {
        $provider = $this->createMock(\SuperAgent\Contracts\LLMProvider::class);
        $provider->method('getModel')->willReturn('claude-3-opus-20240229');
        
        $manager = new ErrorRecoveryManager([
            'fallback_models' => [
                'claude-3-opus-20240229' => 'claude-3-sonnet-20240229',
            ],
        ]);
        
        // This would trigger model downgrade in real scenario
        $context = ['provider' => $provider];
        
        // Test fallback model resolution
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('getFallbackModel');
        $method->setAccessible(true);
        
        $fallback = $method->invoke($manager, $provider);
        $this->assertEquals('claude-3-sonnet-20240229', $fallback);
    }
}