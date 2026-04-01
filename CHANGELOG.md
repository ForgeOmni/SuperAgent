# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.1] - 2026-03-31

### Added
- **Multiple Named Provider Instances** - Support registering multiple instances of the same provider type (e.g. multiple Anthropic-compatible APIs) with different configurations
- New `driver` config field to decouple instance name from provider class selection
- All provider types now supported in `Agent::resolveProvider()` (Anthropic, OpenAI, OpenRouter, Bedrock, Ollama)
- Documentation for multi-provider instance usage in both English and Chinese READMEs

### Changed
- `Agent::resolveProvider()` now uses a `driver` field to determine which provider class to instantiate, falling back to the provider name for backward compatibility

## [0.5.0] - 2026-03-31

### Added
- **Initial release of SuperAgent SDK**
- Multi-provider AI support (Anthropic Claude, OpenAI GPT, AWS Bedrock, OpenRouter)
- 56+ built-in tools for file operations, code editing, web search, and task management
- Streaming output support for real-time responses
- Comprehensive permission system with 6 different modes
- Lifecycle hooks system for custom logic integration
- Context compression with smart conversation history management
- Memory system for cross-session persistence
- Multi-agent collaboration (Swarm mode)
- MCP (Model Context Protocol) integration
- OpenTelemetry observability and tracing
- File history with version control and rollback capabilities
- Cost tracking and token usage statistics
- Laravel Artisan commands for CLI interaction
- Custom tool, plugin, and skill development framework
- Cache system with Redis support
- Comprehensive configuration system
- Database migrations for memory and task management
- Multi-language documentation (English and Chinese)

### Features
- **Core Functionality**
  - Agent class with query and streaming methods
  - Configuration management with environment variable support
  - Provider abstraction for multiple AI services
  - Tool registry and execution framework
  - Permission validation and security controls

- **Built-in Tools**
  - File operations (read, write, edit, delete)
  - Code editing and syntax highlighting
  - Bash command execution with safety controls
  - Web search and content fetching
  - Task management and tracking
  - Image processing and analysis
  - JSON and YAML manipulation
  - Git operations and version control

- **Advanced Features**
  - Smart context compression to handle token limits
  - Cross-session memory with learning capabilities
  - Multi-agent task distribution and coordination
  - Real-time telemetry and performance monitoring
  - Automatic file versioning and rollback
  - Intelligent permission management
  - Hook-based extensibility system

- **Development Tools**
  - Artisan commands for interactive chat
  - Tool scaffolding and generation
  - Plugin development framework
  - Custom skill creation system
  - Comprehensive testing suite

### Documentation
- Complete installation guide with system requirements
- Multi-language README (English and Chinese)
- Detailed configuration documentation
- API reference and examples
- Best practices and security guidelines
- Troubleshooting and FAQ sections

### Security
- Input validation and sanitization
- API key management and encryption
- Command execution safety controls
- File access permission validation
- Rate limiting and abuse prevention
- Secure error handling without information leakage

### Performance
- Optimized memory usage with context compression
- Efficient caching with Redis support
- Async operation support
- Batch processing capabilities
- Connection pooling and resource management

## Previous Development Versions
*Note: Versions prior to 0.5.0 were development releases and not publicly available.*

---

## Links
- [Homepage](https://github.com/forgeomni/superagent)
- [Documentation](README.md)
- [Installation Guide](INSTALL.md)
- [中文文档](README.zh-CN.md)
- [中文安装手册](INSTALL.zh-CN.md)

## License
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

**Note**: For upgrade instructions and breaking changes, please refer to our [Installation Guide](INSTALL.md#upgrade-guide).