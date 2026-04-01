<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

use InvalidArgumentException;

class PermissionRuleParser
{
    public function parse(string $rule): PermissionRuleValue
    {
        $rule = trim($rule);
        
        if (empty($rule)) {
            throw new InvalidArgumentException('Empty permission rule');
        }
        
        $openParen = $this->findUnescapedCharacter($rule, '(');
        $closeParen = $this->findUnescapedCharacter($rule, ')', $openParen !== false ? $openParen + 1 : 0);
        
        if ($openParen === false && $closeParen === false) {
            return new PermissionRuleValue($rule);
        }
        
        if ($openParen === false || $closeParen === false) {
            throw new InvalidArgumentException("Invalid permission rule format: {$rule}");
        }
        
        if ($closeParen !== strlen($rule) - 1) {
            throw new InvalidArgumentException("Invalid permission rule format: {$rule}");
        }
        
        $toolName = substr($rule, 0, $openParen);
        $content = substr($rule, $openParen + 1, $closeParen - $openParen - 1);
        
        $content = $this->unescapeContent($content);
        
        $content = $this->handleLegacyFormat($content);
        
        return new PermissionRuleValue($toolName, $content);
    }
    
    private function findUnescapedCharacter(string $str, string $char, int $start = 0): int|false
    {
        $len = strlen($str);
        for ($i = $start; $i < $len; $i++) {
            if ($str[$i] === $char) {
                $backslashCount = 0;
                for ($j = $i - 1; $j >= 0 && $str[$j] === '\\'; $j--) {
                    $backslashCount++;
                }
                
                if ($backslashCount % 2 === 0) {
                    return $i;
                }
            }
        }
        
        return false;
    }
    
    private function unescapeContent(string $content): string
    {
        $result = '';
        $len = strlen($content);
        $i = 0;
        
        while ($i < $len) {
            if ($i < $len - 1 && $content[$i] === '\\') {
                $nextChar = $content[$i + 1];
                if (in_array($nextChar, ['\\', '(', ')'], true)) {
                    $result .= $nextChar;
                    $i += 2;
                    continue;
                }
            }
            
            $result .= $content[$i];
            $i++;
        }
        
        return $result;
    }
    
    private function handleLegacyFormat(string $content): string
    {
        if (preg_match('/^([^:]+):(.*)$/', $content, $matches)) {
            $prefix = $matches[1];
            $suffix = $matches[2];
            
            if ($suffix === '' || $suffix === '*') {
                return $prefix . '*';
            }
        }
        
        return $content;
    }
}