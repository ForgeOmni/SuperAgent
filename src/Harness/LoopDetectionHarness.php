<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\Guardrails\LoopDetector;
use SuperAgent\Guardrails\LoopViolation;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\StreamingHandler;

/**
 * Glue between `Guardrails\LoopDetector` (the pure detector) and the
 * streaming agent loop. Wraps a user-provided `StreamingHandler` with
 * one that observes every text / thinking / tool-use chunk through
 * the detector, fires an `onViolation` callback the first time a
 * detector trips, and always delegates to the inner handler so the
 * outer UI stays unaffected by our decoration.
 *
 * Usage pattern:
 *
 *   $detector = new LoopDetector();
 *   $wrapped = LoopDetectionHarness::wrap(
 *       inner:       $userHandler,            // may be null
 *       detector:    $detector,
 *       onViolation: function (LoopViolation $v) use ($wireEmitter) {
 *           $wireEmitter->emit(LoopDetectedEvent::fromViolation($v));
 *           // caller-specific policy: throw to stop the turn, log
 *           // and continue, flip a flag, etc.
 *       },
 *   );
 *   // Hand $wrapped to Agent::prompt($input, $wrapped).
 *
 * Default-off by intent: if you don't wrap, nothing changes — pre-
 * Phase-5 callers see zero behaviour difference.
 *
 * Design note: StreamingHandler is a concrete class with closure
 * properties rather than an interface, so we "decorate" by building
 * a fresh StreamingHandler whose closures observe + delegate. This
 * keeps the existing event-bus semantics intact (closure-optional,
 * no virtual dispatch).
 */
final class LoopDetectionHarness
{
    /**
     * Create a decorated StreamingHandler. The returned handler
     * observes every streaming event through `$detector` and fires
     * `$onViolation($violation)` the first time any detector trips.
     * Subsequent events continue to delegate to the inner handler
     * (the detector's own "sticky" behaviour suppresses re-firing
     * of the callback).
     *
     * @param ?StreamingHandler $inner     User-provided handler (null OK — we still observe).
     * @param LoopDetector      $detector  Typically a fresh per-prompt instance.
     * @param ?\Closure(LoopViolation): void $onViolation  Invoked once per violation.
     */
    public static function wrap(
        ?StreamingHandler $inner,
        LoopDetector $detector,
        ?\Closure $onViolation = null,
    ): StreamingHandler {
        $violationFired = false;
        $fire = static function (?LoopViolation $v) use (&$violationFired, $onViolation): void {
            if ($v === null || $violationFired || $onViolation === null) {
                return;
            }
            $violationFired = true;
            $onViolation($v);
        };

        return new StreamingHandler(
            onText: static function (string $delta, string $full) use ($inner, $detector, $fire): void {
                $fire($detector->observeContent($delta));
                $inner?->emitText($delta, $full);
            },
            onThinking: static function (string $delta, string $fullThinking) use ($inner, $detector, $fire): void {
                $fire($detector->observeThought($delta));
                $inner?->emitThinking($delta, $fullThinking);
            },
            onToolUse: static function (ContentBlock $block) use ($inner, $detector, $fire): void {
                $fire($detector->observeToolCall(
                    (string) $block->toolName,
                    is_array($block->toolInput) ? $block->toolInput : [],
                ));
                $inner?->emitToolUse($block);
            },
            // Tool result / turn / final message / raw event delegate
            // verbatim — none of them feed the detector. Preserving
            // them is what keeps the wrap transparent.
            onToolResult: $inner !== null ? static function (string $id, string $name, string $result, bool $isError) use ($inner): void {
                $inner->emitToolResult($id, $name, $result, $isError);
            } : null,
            onTurn: $inner !== null ? static function ($message, int $turnNumber) use ($inner): void {
                $inner->emitTurn($message, $turnNumber);
            } : null,
            onFinalMessage: $inner !== null ? static function ($msg) use ($inner): void {
                $inner->emitFinalMessage($msg);
            } : null,
            onRawEvent: $inner !== null ? static function (string $event, array $data) use ($inner): void {
                $inner->emitRawEvent($event, $data);
            } : null,
        );
    }
}
