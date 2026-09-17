<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Injection;

/**
 * The packs that ship with this SDK.
 *
 * English was the only language covered until 1.6.0. These add the three a
 * host is most likely to be reading untrusted text in next, and the registry
 * takes more: `PatternPacks::register(new PatternPack('de', [...]))`.
 *
 * Every pattern carries the `u` modifier — a CJK pack without it matches
 * bytes, not characters — and none of them use `\b`, which does not mean
 * anything between two Han characters.
 *
 * @since 1.6.0
 */
final class PatternPacks
{
    /** @var array<string, PatternPack> */
    private static array $registered = [];

    /** Language-agnostic rules, always applied. */
    public static function universal(): PatternPack
    {
        return new PatternPack('universal', [
            'data_exfiltration' => [
                '/curl\s+(?:-[sSkLfO]*\s+)*https?:\/\//iu',
                '/wget\s+(?:-[qO]*\s+)*https?:\/\//iu',
                '/fetch\s*\(\s*[\'"]https?:\/\//iu',
                '/(?:nc|netcat|ncat)\s+-[a-z]*\s+\d+\.\d+\.\d+\.\d+/iu',
            ],
            'invisible_unicode' => [
                '/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u',
                '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
                '/[\x{E0001}-\x{E007F}]/u',
            ],
            'hidden_content' => [
                '/<!--[\s\S]*?-->/su',
                '/<div\s+style\s*=\s*["\'].*?display\s*:\s*none.*?["\'].*?>/isu',
                '/<span\s+style\s*=\s*["\'].*?font-size\s*:\s*0.*?["\'].*?>/isu',
            ],
            'encoding_evasion' => [
                '/(?:base64|b64)\s*(?:decode|encode)\s*\(/iu',
                '/\\\\x[0-9a-fA-F]{2}(?:\\\\x[0-9a-fA-F]{2}){3,}/iu',
                '/\\\\u[0-9a-fA-F]{4}(?:\\\\u[0-9a-fA-F]{4}){3,}/iu',
            ],
            'role_confusion' => [
                '/\[system\]|\[SYSTEM\]|<\|system\|>/iu',
            ],
        ]);
    }

    public static function english(): PatternPack
    {
        return new PatternPack('en', [
            'instruction_override' => [
                '/ignore\s+(all\s+)?(previous|prior|above|earlier)\s+(instructions?|prompts?|rules?|directions?)/iu',
                '/disregard\s+(all\s+)?(previous|prior|above)\s+(instructions?|context)/iu',
                '/forget\s+(everything|all)\s+(you\s+)?(know|learned|were\s+told)/iu',
                '/override\s+(system|safety|security)\s+(prompt|instructions?|rules?)/iu',
                '/new\s+instructions?\s*[:=]/iu',
            ],
            'system_prompt_extraction' => [
                '/(?:print|show|display|reveal|output|repeat|echo|tell|give)\s+(?:me\s+|us\s+)?(?:your\s+|the\s+)?(?:system\s+)?(?:prompt|instructions?|rules?)/iu',
                '/what\s+(?:are|is)\s+your\s+(?:system\s+)?(?:prompt|instructions?|rules?|guidelines?)/iu',
                '/(?:beginning|start)\s+of\s+(?:your\s+)?(?:system\s+)?(?:prompt|instructions?)/iu',
            ],
            'role_confusion' => [
                '/you\s+are\s+now\s+(?:a\s+)?(?:different|new|my|an?\s+)/iu',
                '/(?:act|behave|respond|pretend)\s+(?:as|like)\s+(?:if\s+)?(?:you\s+(?:are|were)\s+)?(?:a\s+)?/iu',
                '/(?:switch|change)\s+(?:to|into)\s+(?:a\s+)?(?:different|new)\s+(?:mode|role|persona)/iu',
            ],
        ]);
    }

    /** Simplified Chinese. */
    public static function simplifiedChinese(): PatternPack
    {
        return new PatternPack('zh-Hans', [
            'instruction_override' => [
                '/(忽略|无视|不要理会|不用管)(上面|以上|之前|先前|前面)(的)?(所有)?(指令|指示|提示|规则|要求)/u',
                '/忘(记|掉)(之前|以上|上面|前面)(的)?(所有)?(指令|指示|内容|规则)/u',
                '/(覆盖|绕过|跳过)(系统|安全)(提示词?|指令|规则|限制)/u',
                '/(新的?|以下)(指令|指示|规则)\s*[:：]/u',
                '/(从现在开始|接下来)(你)?(要|请|必须)?(忽略|不再遵守)/u',
            ],
            'system_prompt_extraction' => [
                '/(显示|输出|打印|重复|告诉我|复述)(一下)?(你的)?(系统)?(提示词|提示语|指令|规则|设定)/u',
                '/你的(系统)?(提示词|提示语|指令|规则|设定)(是什么|有哪些)/u',
                '/(原始|完整)的?(系统)?(提示词|指令)/u',
                '/(你的)?(系统)?(提示词|提示语|指令|规则|设定).{0,8}(输出|显示|打印|发给我|告诉我|重复|复述|给我看|列出来)/u',
            ],
            'role_confusion' => [
                '/你(现在|从现在起)(是|就是|将是)(一个)?/u',
                '/(假装|假设|扮演|模拟)(你是|自己是|成为)/u',
                '/(切换|进入|转换)(到|成)(另一个|新的|不同的)?(模式|角色|人格)/u',
                '/开发者模式|越狱模式|无限制模式/u',
            ],
        ]);
    }

    /** Traditional Chinese. */
    public static function traditionalChinese(): PatternPack
    {
        return new PatternPack('zh-Hant', [
            'instruction_override' => [
                '/(忽略|無視|不要理會|不用管)(上面|以上|之前|先前|前面)(的)?(所有)?(指令|指示|提示|規則|要求)/u',
                '/忘(記|掉)(之前|以上|上面|前面)(的)?(所有)?(指令|指示|內容|規則)/u',
                '/(覆蓋|繞過|跳過)(系統|安全)(提示詞?|指令|規則|限制)/u',
                '/(新的?|以下)(指令|指示|規則)\s*[:：]/u',
                '/(從現在開始|接下來)(你)?(要|請|必須)?(忽略|不再遵守)/u',
            ],
            'system_prompt_extraction' => [
                '/(顯示|輸出|列印|重複|告訴我|複述)(一下)?(你的)?(系統)?(提示詞|提示語|指令|規則|設定)/u',
                '/你的(系統)?(提示詞|提示語|指令|規則|設定)(是什麼|有哪些)/u',
                '/(原始|完整)的?(系統)?(提示詞|指令)/u',
                '/(你的)?(系統)?(提示詞|提示語|指令|規則|設定).{0,8}(輸出|顯示|列印|發給我|告訴我|重複|複述|給我看|列出來)/u',
            ],
            'role_confusion' => [
                '/你(現在|從現在起)(是|就是|將是)(一個)?/u',
                '/(假裝|假設|扮演|模擬)(你是|自己是|成為)/u',
                '/(切換|進入|轉換)(到|成)(另一個|新的|不同的)?(模式|角色|人格)/u',
                '/開發者模式|越獄模式|無限制模式/u',
            ],
        ]);
    }

    public static function french(): PatternPack
    {
        return new PatternPack('fr', [
            'instruction_override' => [
                '/ignore[sz]?\s+(toutes\s+)?(les\s+)?(instructions?|consignes?|r[èe]gles?)\s+(pr[ée]c[ée]dentes?|ci-dessus|ant[ée]rieures?)/iu',
                '/oubli(?:e|ez)\s+(tout\s+)?(ce\s+qui\s+pr[ée]c[èe]de|les\s+instructions?)/iu',
                '/(contourne[sz]?|outrepasse[sz]?)\s+(les\s+)?(r[èe]gles?|instructions?|s[ée]curit[ée])/iu',
                '/nouvelles?\s+instructions?\s*[:=]/iu',
            ],
            'system_prompt_extraction' => [
                '/(affiche[sz]?|montre[sz]?|r[ée]v[èe]le[sz]?|r[ée]p[èe]te[sz]?)\s+(ton|votre|le)\s+(prompt|message)?\s*(syst[èe]me|instructions?)/iu',
                '/quelles?\s+sont\s+(tes|vos)\s+(instructions?|r[èe]gles?|consignes?)/iu',
            ],
            'role_confusion' => [
                '/tu\s+es\s+maintenant\s+(un|une|mon|ma)\b/iu',
                '/(fais|agis)\s+comme\s+si\s+tu\s+[ée]tais/iu',
                '/mode\s+(d[ée]veloppeur|sans\s+restriction)/iu',
            ],
        ]);
    }

    /**
     * Add or replace a pack. A host with its own language, or its own rules
     * for one already here, registers it once at boot.
     */
    public static function register(PatternPack $pack): void
    {
        self::$registered[$pack->language] = $pack;
    }

    public static function forget(string $language): void
    {
        unset(self::$registered[$language]);
    }

    /**
     * Every pack that applies, universal first.
     *
     * @param list<string>|null $languages null = every bundled and registered pack
     * @return list<PatternPack>
     */
    public static function resolve(?array $languages = null): array
    {
        $bundled = [
            'en' => self::english(),
            'zh-Hans' => self::simplifiedChinese(),
            'zh-Hant' => self::traditionalChinese(),
            'fr' => self::french(),
        ];

        $available = $bundled;
        foreach (self::$registered as $language => $pack) {
            $available[$language] = $pack;
        }

        if ($languages === null) {
            return array_merge([self::universal()], array_values($available));
        }

        $selected = [self::universal()];
        foreach ($languages as $language) {
            if (isset($available[$language])) {
                $selected[] = $available[$language];
            }
        }

        return $selected;
    }

    /** @return list<string> */
    public static function languages(): array
    {
        return array_values(array_unique(array_merge(
            ['en', 'zh-Hans', 'zh-Hant', 'fr'],
            array_keys(self::$registered)
        )));
    }
}
