<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

/**
 * Glob-based path permission rule.
 * Matches file paths against glob patterns to allow or deny access.
 */
class PathRule
{
    public function __construct(
        public readonly string $pattern,  // Glob pattern, e.g. "*.env", "/tmp/**", "database/migrations/*"
        public readonly bool $allow,      // true = allow, false = deny
    ) {}

    /**
     * Check if this rule matches the given file path.
     */
    public function matches(string $filePath): bool
    {
        // Normalize path separators
        $filePath = str_replace('\\', '/', $filePath);
        $pattern = str_replace('\\', '/', $this->pattern);

        // Convert ** to a regex-compatible fnmatch by replacing ** with a
        // sentinel, then using fnmatch without FNM_PATHNAME so * crosses dirs.
        $usesDoubleStar = str_contains($pattern, '**');

        if (str_starts_with($pattern, '/')) {
            if ($usesDoubleStar) {
                return $this->matchDoubleStar($pattern, $filePath);
            }
            return fnmatch($pattern, $filePath, FNM_PATHNAME);
        }

        // Try matching against full path and basename
        if ($usesDoubleStar) {
            return $this->matchDoubleStar($pattern, $filePath)
                || $this->matchDoubleStar('**/' . $pattern, $filePath);
        }

        return fnmatch($pattern, $filePath, FNM_PATHNAME)
            || fnmatch($pattern, basename($filePath))
            || fnmatch('*/' . $pattern, $filePath, FNM_PATHNAME);
    }

    /**
     * Match a pattern containing ** against a file path.
     * ** matches any number of path segments (including zero).
     */
    private function matchDoubleStar(string $pattern, string $filePath): bool
    {
        // Replace ** with a marker, then convert to regex
        $regex = preg_quote($pattern, '#');
        // Restore ** markers (preg_quote escapes *, so ** becomes \*\*)
        $regex = str_replace('\\*\\*', '##DOUBLESTAR##', $regex);
        // Restore single * markers
        $regex = str_replace('\\*', '[^/]*', $regex);
        // ** matches anything including /
        $regex = str_replace('##DOUBLESTAR##', '.*', $regex);
        // ? matches single non-slash char
        $regex = str_replace('\\?', '[^/]', $regex);

        return (bool)preg_match('#^' . $regex . '$#', $filePath);
    }

    public static function allow(string $pattern): self
    {
        return new self($pattern, true);
    }

    public static function deny(string $pattern): self
    {
        return new self($pattern, false);
    }

    public function toArray(): array
    {
        return ['pattern' => $this->pattern, 'allow' => $this->allow];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['pattern'], $data['allow'] ?? false);
    }
}
