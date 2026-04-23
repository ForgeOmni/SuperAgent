<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SuperAgent\Support\LanguageDetector;

/**
 * Pins the CJK gate behaviour. The detector is deliberately binary
 * (zh-or-not); these tests lock the corners where false-positive /
 * false-negative decisions matter.
 */
class LanguageDetectorTest extends TestCase
{
    public function test_plain_ascii_is_not_cjk(): void
    {
        $this->assertFalse(LanguageDetector::isCjk('hello world'));
    }

    public function test_empty_string_is_not_cjk(): void
    {
        $this->assertFalse(LanguageDetector::isCjk(''));
    }

    public function test_null_is_not_cjk(): void
    {
        $this->assertFalse(LanguageDetector::isCjk(null));
    }

    public function test_non_string_is_not_cjk(): void
    {
        $this->assertFalse(LanguageDetector::isCjk(42));
    }

    public function test_simplified_chinese_detected(): void
    {
        $this->assertTrue(LanguageDetector::isCjk('分析这份报告'));
    }

    public function test_traditional_chinese_detected(): void
    {
        $this->assertTrue(LanguageDetector::isCjk('請分析這份報告'));
    }

    public function test_mixed_cjk_and_latin_detected(): void
    {
        // Any CJK ideograph flips the gate — mixed zh-en is still zh for
        // our purposes (downstream templates pick the zh variant so the
        // user gets a coherent Chinese surface).
        $this->assertTrue(LanguageDetector::isCjk('analyze 这个 codebase'));
    }

    public function test_japanese_kanji_also_trips_the_gate(): void
    {
        // We don't distinguish ja vs zh — both land in the CJK block.
        // That's fine: the template repertoire is zh / en, and a ja
        // user is better served by the zh template than the en one.
        $this->assertTrue(LanguageDetector::isCjk('日本語テスト'));
    }

    public function test_hiragana_katakana_without_kanji_not_detected(): void
    {
        // Hiragana (U+3040..U+309F) and katakana (U+30A0..U+30FF) live
        // outside the U+4E00..U+9FFF gate. For zh/en binary routing
        // that's the right call — pure-kana text is rare in engineering
        // contexts and landing on the en template is acceptable.
        $this->assertFalse(LanguageDetector::isCjk('ひらがな'));
    }

    // ------------------------------------------------------------------
    // pick()
    // ------------------------------------------------------------------

    public function test_pick_chooses_zh_for_cjk_input(): void
    {
        $out = LanguageDetector::pick('分析代码', [
            'zh' => '中文模板',
            'en' => 'english template',
        ]);
        $this->assertSame('中文模板', $out);
    }

    public function test_pick_chooses_en_for_latin_input(): void
    {
        $out = LanguageDetector::pick('analyze code', [
            'zh' => '中文模板',
            'en' => 'english template',
        ]);
        $this->assertSame('english template', $out);
    }

    public function test_pick_falls_back_to_en_when_zh_missing(): void
    {
        $out = LanguageDetector::pick('分析代码', [
            'en' => 'only english available',
        ]);
        $this->assertSame('only english available', $out);
    }

    public function test_pick_returns_default_when_no_template_matches(): void
    {
        $out = LanguageDetector::pick('分析', [], 'fallback');
        $this->assertSame('fallback', $out);
    }
}
