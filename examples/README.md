# SuperAgent Examples

This directory contains practical examples demonstrating various SuperAgent capabilities.

## Basic Examples

1. **[Simple Chat](01-simple-chat.php)** - Basic interactive chat session
2. **[Code Refactoring](02-code-refactoring.php)** - Automated code refactoring
3. **[Test Generation](03-test-generation.php)** - Generate unit tests for existing code
4. **[Documentation](04-documentation.php)** - Auto-generate documentation
5. **[Code Review](05-code-review.php)** - Automated code review

## Advanced Examples

6. **[Multi-Agent Task](06-multi-agent-task.php)** - Coordinate multiple agents
7. **[Plugin Development](07-plugin-development.php)** - Create custom plugins
8. **[Skill Creation](08-skill-creation.php)** - Define reusable skills
9. **[MCP Integration](09-mcp-integration.php)** - Connect to MCP servers
10. **[Web Scraping](10-web-scraping.php)** - Extract data from websites

## Configuration Examples

11. **[Custom Tools](11-custom-tools.php)** - Implement custom tools
12. **[Provider Switching](12-provider-switching.php)** - Use different LLM providers
13. **[Cost Optimization](13-cost-optimization.php)** - Optimize for cost efficiency
14. **[Stream Processing](14-stream-processing.php)** - Handle streaming responses
15. **[Memory Management](15-memory-management.php)** - Persistent memory across sessions

## Running Examples

Each example can be run from the command line:

```bash
php examples/01-simple-chat.php
```

Make sure to set your API keys in the `.env` file:

```
ANTHROPIC_API_KEY=your_api_key_here
OPENAI_API_KEY=your_api_key_here  # For OpenAI examples
```

## Requirements

- PHP 8.1+
- Laravel 10+
- Composer dependencies installed
- Valid API keys for the providers you want to use