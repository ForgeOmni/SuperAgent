# 原生接入 Kimi / Qwen / GLM / MiniMax 设计文档

> **日期:** 2026-04-21 | **状态:** 待实施
>
> **说明:** 本文档不指定具体版本号，版本号由维护者在每个 Phase 发布时手工制定。
>
> **语言:** [中文](NATIVE_PROVIDERS_CN.md)

---

## 1. 背景与目标

### 1.1 背景

SuperAgent 当前已支持 6 家 LLM provider（Anthropic / OpenAI / OpenRouter / Bedrock / Ollama / Gemini）。2026 年 Q1–Q2，四家中国大模型厂商陆续发布新一代 agentic 模型并提供国际版 endpoint：

| 厂商 | 最新模型 | 发布时间 | 标志性能力 |
|---|---|---|---|
| **Moonshot Kimi** | K2.6（1T / 32B active MoE） | 2026-04-20 | Agent Swarm（300 sub-agents / 4000 步）、Claw Groups、Skills |
| **Alibaba Qwen** | Qwen3.6-Max-Preview（260K ctx） | 2026-04-20 | thinking 模式、Qwen-Long 10M ctx、`enable_code_interpreter` |
| **智谱 Z.AI** | GLM-5（744B / 40B active） | 2026-03–04 | MCP-Atlas 第一、独立 Web Search / Reader / OCR 端点 |
| **MiniMax** | M2.7（230B / 10B active，self-evolving） | 2026-03-18 | 原生 MCP、Agent Teams、dynamic tool search、97% Skill 遵循率 |

OpenAI-compatible 兼容层能覆盖基本 chat / streaming / tools，但**无法触达四家的特色能力**。本设计目标即：以纯加法方式接入四家**原生**能力，并在现有架构上形成**混合调用**能力。

### 1.2 目标

1. 四家 provider 以原生接口接入，支持各区域选择（国际 / 国内 / 美国 / 香港）
2. 特色能力（Swarm / thinking / Web Search / TTS / Video / Skills / MCP）对 Agent 层可见、可调用
3. 支持**跨 provider 混合调用**：主脑 = 任意 provider，工具箱 = 四家特色能力 + MCP + 通用工具
4. **纯向上兼容**：现有 API、配置、models.json、CLI 命令、测试全部保持
5. 容量 / 成本 / 区域路由自动化

### 1.3 非目标

- 不实现 OpenAI-compat 子类（已有路径覆盖）
- 不破坏现有 6 家 provider 的任何行为
- 不强制启用新能力（默认 opt-in，到里程碑版本才默认开启）
- Kimi Agent Swarm / Claw Groups 的 REST schema 官方未放出前，不承诺完整覆盖

---

## 2. 四家 Provider 能力矩阵

### 2.1 区域与 endpoint

| Provider | 区域 | Base URL |
|---|---|---|
| Kimi | intl | `https://api.moonshot.ai/v1` |
| Kimi | cn | `https://api.moonshot.cn/v1` |
| Qwen | intl (Singapore) | `https://dashscope-intl.aliyuncs.com/api/v1` |
| Qwen | us | `https://dashscope-us.aliyuncs.com/api/v1` |
| Qwen | cn | `https://dashscope.aliyuncs.com/api/v1` |
| Qwen | hk | `https://cn-hongkong.dashscope.aliyuncs.com/api/v1` |
| GLM | intl | `https://api.z.ai/api/paas/v4/` |
| GLM | cn | `https://open.bigmodel.cn/api/paas/v4/` |
| MiniMax | intl | `https://api.minimax.io/v1` |
| MiniMax | cn | `https://api.minimaxi.com/v1` |

**关键约束:** 所有四家 API key 与 host 绑定，国内 key 不能打国际 endpoint。

### 2.2 能力矩阵

| 能力 | Kimi K2.6 | Qwen3.6-Max | GLM-5 | MiniMax M2.7 |
|---|---|---|---|---|
| Chat / streaming / tools | ✅ | ✅ | ✅ | ✅ |
| Vision | ✅ | ✅（VL / Omni） | ✅（5V） | ✅ |
| Thinking 模式 | ✅（模型选择） | ✅（`enable_thinking`） | ✅（`thinking.type`） | — |
| Context Caching | ✅（自动，75-83% 省） | — | — | — |
| File Extract（服务端抽取） | ✅（`purpose=file-extract`） | ✅（Qwen-Long `fileid://`） | ⚠️（OCR / Layout） | ✅ |
| Long Context | 256K | 260K–10M（文件模式） | 200K | 204K |
| Server-side Web Search | ✅（`$web_search`） | — | ✅（**独立端点**） | ⚠️（MCP） |
| Code Interpreter | — | ✅（`enable_code_interpreter`） | — | — |
| OCR / Layout Parsing | — | ✅（qwen-vl-ocr） | ✅（GLM-OCR） | — |
| ASR（语音转文本） | — | — | ✅（GLM-ASR-2512） | ⚠️ |
| TTS | — | — | — | ✅（T2A，300+ voices） |
| Music Generation | — | — | — | ✅（music-2.6） |
| Image Generation | — | — | ✅（CogView-4） | ✅（image-01） |
| Video Generation | — | — | ✅（CogVideoX / Vidu） | ✅（Hailuo-2.3） |
| Voice Cloning / Design | — | — | — | ✅ |
| Swarm / 多 agent | ✅（Agent Swarm 原生） | ☍（Qwen-Agent 框架） | ☍（框架） | ✅（Agent Teams 原生） |
| Skills | ✅（PDF→Skill） | — | — | ✅（97% 遵循率） |
| MCP | ✅（CLI 一等公民） | ✅（Qwen-Agent 深度集成） | ✅（MCP-Atlas #1） | ✅（模型原生） |
| Batch | ✅（`/batches`） | ⚠️ | — | — |

图例：✅ 原生 | ☍ 框架层 | ⚠️ 部分支持 | — 不支持

---

## 3. 架构总览

### 3.1 设计原则

1. **纯加法兼容**：不改任何现有 public API 签名、不改现有 schema 语义
2. **能力声明 > 硬编码**：能力通过 interface + `models.json` 声明，Router 自动选路
3. **特色虚拟化为 Tool**：各家独有端点包装成标准 Tool，任何主脑都能调
4. **异步任务有 handle**：长耗时任务（Swarm / 视频 / 音乐）走 JobHandle，不阻塞 agent loop
5. **降级有策略**：required 特性硬失败，preferred 特性自动降级
6. **权限集中**：审批 / 配额 / 沙盒在 SuperAgent 层统一，不依赖各家 API 层

### 3.2 架构分层

```
┌──────────────────────────────────────────────┐
│  Agent Loop / BaseAgent（现有，加可选字段）   │
└──────────────────────────────────────────────┘
              │
┌──────────────────────────────────────────────┐
│  CapabilityRouter（新）                       │
│   输入 request + preferences                  │
│   输出 (Provider, Model, Region, Features)    │
└──────────────────────────────────────────────┘
              │
   ┌──────────┼──────────┬──────────────┐
   ▼          ▼          ▼              ▼
┌──────┐ ┌────────┐ ┌─────────────┐ ┌─────────────┐
│LLM   │ │Feature │ │Specialty    │ │MCP          │
│Chat  │ │Adapter │ │-as-Tool     │ │Manager      │
└──────┘ └────────┘ └─────────────┘ └─────────────┘
   │          │          │              │
   ▼          ▼          ▼              ▼
 LLMProvider（现有）+ Capability 接口（新，平行）
```

### 3.3 新增模块清单

| 路径 | 用途 |
|---|---|
| `src/Providers/Capabilities/*.php` | Capability 接口（`SupportsThinking`、`SupportsSwarm` …） |
| `src/Providers/Features/*.php` | FeatureAdapter（把通用 feature 翻译到各 provider 请求字段） |
| `src/Providers/CapabilityRouter.php` | 能力感知路由（扩展 ModelRouter） |
| `src/Providers/AsyncCapable.php` | 长任务异步 handle 接口 |
| `src/Providers/KimiProvider.php` | Kimi 原生 |
| `src/Providers/QwenProvider.php` | Qwen DashScope 原生 |
| `src/Providers/GlmProvider.php` | Z.AI / BigModel 原生 |
| `src/Providers/MiniMaxProvider.php` | MiniMax 原生 |
| `src/Tools/Providers/Glm/*.php` | GLM 特色工具（Web Search / Reader / OCR / ASR） |
| `src/Tools/Providers/Kimi/*.php` | Kimi 特色工具（FileExtract / Batch） |
| `src/Tools/Providers/Qwen/*.php` | Qwen 特色工具（CodeInterpreter / QwenLongFile） |
| `src/Tools/Providers/MiniMax/*.php` | MiniMax 特色工具（TTS / Music / Video / Image） |
| `src/MCP/` | 统一 MCP Manager（四家共享） |
| `src/Skills/` | SuperAgent Skill 系统（Kimi / MiniMax bridge） |
| `src/Security/ToolSecurityValidator.php` | 工具级审批 / 配额 |
| `tests/Compat/` | 兼容性锁定测试 |

---

## 4. 核心设计细节

### 4.1 Capability 接口（平行于 `LLMProvider`）

```php
namespace SuperAgent\Providers\Capabilities;

interface SupportsThinking {
    public function thinkingMode(int $budget): array;  // 返回 provider-specific 请求片段
}

interface SupportsContextCaching {
    public function cacheHint(array $content): array;
}

interface SupportsSwarm extends AsyncCapable {
    public function submitSwarm(array $prompt, array $opts): JobHandle;
}

interface SupportsFileExtract {
    public function uploadForExtract(string $path): string;   // 返回 file_id
    public function referenceFile(string $fileId): array;
}

interface SupportsWebSearch {
    public function webSearch(string $query, array $opts): array;
}

interface SupportsTTS extends AsyncCapable {
    public function textToSpeech(string $text, array $opts): JobHandle;
}

// 其他：SupportsMusic / SupportsVideo / SupportsImage /
//       SupportsOCR / SupportsASR / SupportsCodeInterpreter /
//       SupportsSkills / SupportsBatch
```

**检测与调度:**
```php
if ($provider instanceof SupportsThinking) {
    $options['features']['thinking'] = $provider->thinkingMode(4000);
}
```

**兼容性:** 现有 6 家 provider 不声明这些接口 → `instanceof` false → 降级路径 = 当前行为。

### 4.2 请求 schema：`$options['features']` 侧信道

不改 `chat()` 签名，features 走 options：

```php
$provider->chat($messages, $tools, $system, [
    'features' => [
        'thinking'      => ['budget' => 4000, 'required' => false],
        'context_cache' => ['enabled' => true],
        'long_context'  => ['file_id' => 'f_abc123'],
        'web_search'    => ['enabled' => true, 'required' => false],
    ],
]);
```

**required vs preferred:**
- `required: true` → provider 不支持 → `FeatureNotSupportedException`
- `required: false`（默认）→ 走 FeatureAdapter 的降级路径

### 4.3 FeatureAdapter：通用 feature → provider 字段

```php
namespace SuperAgent\Providers\Features;

class ThinkingAdapter {
    public static function apply(LLMProvider $p, array $spec, array &$body): void {
        $budget = $spec['budget'] ?? 4000;
        match (true) {
            $p instanceof QwenProvider =>
                $body['parameters']['enable_thinking'] = true,
            $p instanceof GlmProvider =>
                $body['thinking'] = ['type' => 'enabled'],
            $p instanceof KimiProvider =>
                $body['model'] = self::pickThinkingModel($body['model']),
            $p instanceof AnthropicProvider =>
                $body['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget],
            default =>
                ($spec['required'] ?? false)
                    ? throw new FeatureNotSupportedException('thinking', $p->getName())
                    : self::injectCotPrompt($body),
        };
    }
}
```

**降级对照表:**

| Feature | Native | Graceful fallback |
|---|---|---|
| thinking | 字段 / 模型 | CoT system prompt 注入 |
| web_search | 独立端点 / 内置 tool | 注册 MCP web-search tool |
| context_cache | 显式 cache | 透明跳过 |
| file_extract | 服务端抽取 | 本地 PDF → text |
| code_interpreter | 服务端 | 本地 sandbox（如有） |
| long_context_file | fileid 引用 | 降级为多段 message |
| tts / video / music | 服务端 | **不降级**（required fail） |
| swarm | Kimi 原生 | SuperAgent 自写简易编排 |

### 4.4 Specialty-as-Tool：特色能力虚拟化为 Tool

关键模式：让主 LLM 可以是任意 provider，但工具箱含所有家的特色能力。

```php
namespace SuperAgent\Tools\Providers\Glm;

class WebSearchTool implements Tool {
    public function __construct(private GlmProvider $glm) {}

    public function name(): string { return 'glm_web_search'; }

    public function schema(): array {
        return [
            'name' => 'glm_web_search',
            'description' => 'Search the web using GLM-optimized search engine',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'count' => ['type' => 'integer', 'default' => 10],
                ],
                'required' => ['query'],
            ],
        ];
    }

    public function execute(array $args): ToolResult {
        return $this->glm->webSearch($args['query'], $args);
    }
}
```

**注册:**
```php
$toolRegistry->registerProvider('glm', [
    new Glm\WebSearchTool($glmProvider),
    new Glm\WebReaderTool($glmProvider),
    new Glm\OcrTool($glmProvider),
]);
```

**效果:** Claude / OpenAI / Gemini 作主脑时，都能看到 `glm_web_search`、`minimax_tts` 等工具，调用时由对应 provider 执行。

### 4.5 CapabilityRouter：能力感知路由

```php
class CapabilityRouter {
    public static function pick(array $request): RoutingDecision {
        $features = $request['features'] ?? [];
        $preferredRegion = $request['region'] ?? null;

        $candidates = ModelCatalog::all();
        $candidates = self::filterByCapabilities($candidates, $features);
        $candidates = self::filterByRegion($candidates, $preferredRegion);
        $candidates = self::filterByCredentials($candidates);
        $candidates = self::rankByCostLatency($candidates);

        return new RoutingDecision(
            provider: $candidates[0]->provider,
            model: $candidates[0]->id,
            region: $candidates[0]->region,
            features: self::resolveFeatures($features, $candidates[0]),
        );
    }
}
```

**与现有 `ModelRouter` 的关系:** CapabilityRouter 先过滤能力，再把剩下的候选交给 ModelRouter 做成本/容量决策。ModelRouter 签名不变。

### 4.6 AsyncJobHandle：长任务异步接口

```php
interface AsyncCapable {
    public function submit(array $params): JobHandle;
    public function poll(JobHandle $h): JobStatus;      // pending / running / done / failed
    public function fetch(JobHandle $h): mixed;
    public function cancel(JobHandle $h): bool;
}

class JobHandle {
    public function __construct(
        public readonly string $provider,
        public readonly string $jobId,
        public readonly string $kind,    // swarm / video / music / tts-long
        public readonly int $createdAt,
    ) {}
}
```

**Tool wrapper 两种模式:**
- **同步包装**（默认）：`execute()` 内部 submit + 轮询循环 + fetch，对主 LLM 透明
- **异步返回**：`execute()` 返回 `{job_id, status: "pending", poll_tool: "check_job"}`，主 LLM 在下一轮决定是否等

### 4.7 `models.json` schema v2

```json
{
  "_meta": {"schema_version": 2},
  "providers": {
    "kimi": {
      "regions": ["intl", "cn"],
      "env": {
        "intl": "KIMI_API_KEY",
        "cn": "KIMI_CN_API_KEY"
      },
      "models": [
        {
          "id": "kimi-k2-6",
          "family": "k2",
          "date": "20260420",
          "regions": ["intl", "cn"],
          "input": 0.60, "output": 2.50,
          "capabilities": {
            "thinking": true,
            "tools": true,
            "file_extract": true,
            "context_cache": "auto",
            "swarm": true,
            "skills": true,
            "mcp": true,
            "max_context": 262144
          }
        }
      ]
    }
  }
}
```

**Loader 兼容 v1:**
- `_meta.schema_version` 缺失或为 1 → 走 legacy 路径，`capabilities` 从 `ProviderRegistry::getCapabilities()` 推导
- v2 entry 缺 `capabilities` → 同上
- 用户的 `~/.superagent/models.json` 若是 v1 自动识别；`superagent models update` 拉 v2 覆盖

### 4.8 区域与凭证

**Provider 配置扩展:**
```php
$config = [
    'region'   => 'intl',                         // 新，默认 'default'
    'api_key'  => $_ENV['KIMI_API_KEY'],
    'base_url' => null,                           // null → 按 region 自动解析
];
```

**CredentialPool 加 region 标签:**
```php
$pool->add('kimi', 'sk-xxx', ['region' => 'intl']);
$pool->add('kimi', 'sk-yyy', ['region' => 'cn']);

$key = $pool->getKey('kimi', 'intl');   // 只取 intl 池
```

**兼容:** 没标 region 的 key = "通用"，任何 region 请求都可用。旧代码不受影响。

### 4.9 MCP Manager（统一）

```
src/MCP/
  McpManager.php          ← 发现 / 注册 / 生命周期
  McpServer.php           ← 一个 MCP server 的抽象（stdio / http / sse）
  McpTool.php implements Tool   ← MCP tool 桥接到 SuperAgent Tool 接口
  McpAuth.php             ← OAuth / header 认证
```

**配置位置:** `~/.superagent/mcp.json`（用户级）+ 项目级 `.superagent/mcp.json`

**四家互通:** 同一套 MCP server 配置，Kimi / Qwen / GLM / MiniMax / Anthropic 等任何主脑都能用。

**CLI:**
```
superagent mcp add <name> --transport {stdio|http|sse} --url ...
superagent mcp list
superagent mcp remove <name>
superagent mcp auth <name>
```

### 4.10 Skills 系统

**SuperAgent Skill schema**（markdown + frontmatter，参考 Claude Code skills）:
```markdown
---
name: code-review
description: Review pull request with project conventions
inputs:
  - repo_path
outputs:
  - review_md
tools: [read, grep, git_log]
---
# 指令正文
…
```

**Provider bridges:**
- Kimi：把 Skill markdown 上传 → 转成 Kimi Skill
- MiniMax：Skill 序列化进 prompt（M2.7 吃 2000+ token skills，97% 遵循率）
- 其他：Skill 内容注入 system prompt

**目录:** `~/.superagent/skills/` + 项目级 `.superagent/skills/`

### 4.11 权限与审批

扩展现有 `BashSecurityValidator` 思路：

```php
class ToolSecurityValidator {
    public function validate(Tool $tool, array $args): ValidationResult {
        return match (true) {
            $tool instanceof \SuperAgent\Tools\BashTool =>
                $this->bashValidator->validate($args),   // delegate 到旧 validator
            $tool->hasAttribute('network') =>
                $this->networkPolicy->check($tool, $args),
            $tool->hasAttribute('cost') =>
                $this->costLimiter->check($tool, $args),
            default =>
                ValidationResult::allow(),
        };
    }
}
```

**Tool 声明属性:**
- `network` — 外部网络（GLM Web Reader / Qwen 搜索）
- `cost` — 需付费（MiniMax 视频 / 音乐）
- `sensitive` — 需审批（写文件 / 执行代码）
- `readonly` — 默认放行

**配置:**
```php
// config/superagent.php
'tool_policy' => [
    'default' => 'ask',                     // ask / allow / deny
    'readonly' => 'allow',
    'cost' => ['mode' => 'ask', 'daily_limit_usd' => 10.0],
],
```

---

## 5. 向上兼容性保证

### 5.1 兼容性红线（违反即拒绝 PR）

1. ❌ 给现有 public method 加必填参数
2. ❌ 改 `models.json` 现有字段的语义
3. ❌ 让现有 env 变量改含义
4. ❌ 现有 exception 类型的语义变化
5. ❌ `tests/Compat/` 任何测试变红

### 5.2 兼容性测试套件 `tests/Compat/`

锁定以下行为：

| 场景 | 断言 |
|---|---|
| 不传 `features` 的 chat | 请求 body 与当前基线版本 byte-exact 一致 |
| v1 `models.json` 加载 | 字段映射与旧 catalog 一致 |
| 不传 region 的 provider 创建 | base_url 与旧版本一致 |
| 旧 Tool 接口实现 | 调用路径无变化 |
| 旧 AgentConfig | 默认字段填充与旧版本一致 |
| 现有 6 家 provider 集成测试 | 全部通过，无 deprecation warning |
| `BashSecurityValidator` | 57 个既有测试全绿 |

### 5.3 版本节奏（原则，不含具体版本号）

> 具体版本号由维护者手工制定，本节只描述**发布策略**。

- **分步小版本递增** — 每个 Phase 独立交付、独立发布；新能力默认 **opt-in**（`capabilities.enabled = false`）
- **里程碑版本** — 所有 Phase 完成 + 兼容测试全绿 + 反馈收集充分后 tag 一个里程碑版本，默认开启主要新特性，旧 API 全保留
- **后续维护版本** — bugfix 和增量优化
- **暂不 deprecate 任何公开 API** — 即便到下一个大版本也是加法为主

---

## 6. 分步实施计划

每个 Phase 独立可交付、独立可测、独立可发。

### Phase 0 — 地基（0.5 周）

**交付物:**
- `src/Providers/Capabilities/` 目录 + 10 个能力接口定义
- `src/Providers/Features/FeatureAdapter.php` 基类
- `src/Providers/AsyncCapable.php` + `JobHandle`
- `src/Exceptions/FeatureNotSupportedException.php`
- `tests/Unit/Providers/Capabilities/` 接口契约测试

**不含任何 provider 实现，纯骨架。**

验收: 现有测试 100% 绿；新接口可独立被测试 mock。

---

### Phase 1 — 兼容性锁定（0.5 周）

**交付物:**
- `tests/Compat/` 套件（见 5.2 表格）
- `models.json` schema v2 loader（兼容 v1）
- `ModelCatalog::deriveCapabilitiesFromProvider()` 推导器
- CI 增加 compat job

验收: v1 / v2 models.json 并行加载测试通过；当前基线 tag 的 fixture 请求 body 在新代码上完全一致。

---

### Phase 2 — 四家原生 Chat + Region（1 周）

**交付物:**
- `KimiProvider`（platform API）
- `QwenProvider`（DashScope 原生 `generation` 端点）
- `GlmProvider`（`/chat/completions` + `thinking`）
- `MiniMaxProvider`（`/text/chatcompletion_v2`）
- 每家支持 region 配置
- `CredentialPool` region-aware 扩展
- `ProviderRegistry::createWithRegion()`
- `resources/models.json` v2 补齐四家条目
- 四家单元测试（mock HTTP）

验收: 四家均能走通 `chat/stream/tools/vision`；区域切换 key-host 绑定正确；旧 6 家行为零变化。

---

### Phase 3 — FeatureAdapter + CapabilityRouter（1 周）

**交付物:**
- `ThinkingAdapter` / `ContextCachingAdapter` / `WebSearchAdapter` / `LongContextFileAdapter`
- `CapabilityRouter::pick()` 实现
- `AgentConfig` 加可选 `features` 字段
- CLI `--thinking` / `--long-context-file` flag
- 降级路径测试（required=false 时）

验收: 同一个 `features: {thinking: {...}}` 请求可以打到 Kimi / Qwen / GLM / Anthropic 任一家并正确翻译；其他 provider 走 CoT 降级。

---

### Phase 4 — Specialty-as-Tool（1.5 周）

按家分批落地特色工具：

**4a. GLM 工具（0.5 周）**
- `GlmWebSearchTool` / `GlmWebReaderTool` / `GlmOcrTool` / `GlmAsrTool`
- 作为 built-in tools 可供任何主脑调用

**4b. Qwen 工具（0.3 周）**
- `QwenCodeInterpreterTool`
- `QwenLongFileTool`（上传 + fileid 引用）

**4c. Kimi 工具（0.3 周）**
- `KimiFileExtractTool`（上传 PDF/PPT → 抽取文本）
- `KimiBatchTool`

**4d. MiniMax 工具（0.4 周）**
- `MiniMaxTtsTool`（同步短文本）
- `MiniMaxTtsAsyncTool`（长文本，返 JobHandle）
- `MiniMaxMusicTool`
- `MiniMaxVideoTool`
- `MiniMaxImageTool`

验收: 每个工具能被 Claude 主脑调用并拿到正确结果；costs 正确记入 CostCalculator；`tool_policy` 正确识别 cost / network 属性。

---

### Phase 5 — 统一 MCP Manager（1 周）

**交付物:**
- `src/MCP/` 完整实现
- 支持 stdio / streamable HTTP / SSE 三种 transport
- OAuth 2.0 授权流（参考 Kimi CLI 实现）
- `~/.superagent/mcp.json` 配置
- CLI: `superagent mcp add/list/remove/auth`
- MCP tool 自动注册到 ToolRegistry，任何 provider 主脑都能看到

验收: 通过 Anthropic 的 `filesystem` / `fetch` 等官方 MCP server 连通；Kimi / Qwen / GLM / MiniMax 主脑都能调用 MCP tool。

---

### Phase 6 — Skills 系统（1 周）

**交付物:**
- `src/Skills/SkillLoader.php` — 解析 markdown + frontmatter
- `src/Skills/SkillInjector.php` — system prompt 注入
- `src/Skills/Bridges/KimiSkillBridge.php` — 上传 → Kimi Skill
- `src/Skills/Bridges/MiniMaxSkillBridge.php` — 序列化进 prompt
- CLI: `superagent skills install/list/apply`

验收: 同一个 Skill 在 Kimi / MiniMax / Claude 三家主脑下表现一致（允许精度差异）。

---

### Phase 7 — Agent Team 编排（1 周）

**交付物:**
- `KimiSwarmTool`（submit + 长轮询 + 产物下载）
- `MiniMaxAgentTeamsAdapter`（M2.7 原生 Agent Teams 参数）
- SuperAgent 自写的简易多 agent 编排（兜底，用于不支持的 provider）
- CLI: `superagent swarm <prompt>`（自动选最合适的 provider）

验收: 同一个复杂任务（"分析这个 repo 并生成 PPT"）能分别通过 Kimi Swarm / MiniMax Agent Teams / SuperAgent 简易编排三条路径完成。

---

### Phase 8 — 权限 / 审批 / 配额（0.5 周）

**交付物:**
- `src/Security/ToolSecurityValidator.php`
- `src/Security/CostLimiter.php`
- `src/Security/NetworkPolicy.php`
- Tool 属性声明机制
- `config/superagent.php` 的 `tool_policy` 节
- CLI 审批交互

验收: 成本型工具（视频 / 音乐）有 daily limit 生效；network 工具在 `--offline` 模式下被阻止；Bash 工具行为零变化（delegate 到旧 validator）。

---

### Phase 9 — 里程碑发布与文档（0.5 周）

**交付物:**
- `docs/NATIVE_PROVIDERS_CN.md` 用户文档
- `docs/FEATURES_MATRIX.md` 能力矩阵
- `docs/MIGRATION_NATIVE.md` 迁移指南（如何从 OpenAI-compat 切到原生）
- 示例: `examples/mixed_agent.php`（展示混合调用）
- `CHANGELOG.md`
- 里程碑 tag + GitHub release（默认开启新能力，版本号由维护者指定）

---

## 7. 时间总览

| Phase | 工时 | 是否独立发布 |
|---|---|---|
| P0 地基 | 0.5 周 | — |
| P1 兼容锁定 | 0.5 周 | ✓ |
| P2 四家原生 chat | 1 周 | ✓ |
| P3 FeatureAdapter + Router | 1 周 | ✓ |
| P4 Specialty-as-Tool | 1.5 周 | ✓ |
| P5 MCP Manager | 1 周 | ✓ |
| P6 Skills | 1 周 | ✓ |
| P7 Agent Team | 1 周 | ✓ |
| P8 权限 | 0.5 周 | ✓ |
| P9 里程碑发布 | 0.5 周 | ✓（里程碑） |
| **合计** | **8.5 周** | 版本号由维护者手工制定 |

关键路径: P0 → P1 → P2 → P3 → P4/P5/P6/P7（可并行） → P8 → P9。

---

## 8. 风险与对策

| 风险 | 概率 | 影响 | 对策 |
|---|---|---|---|
| Kimi Swarm / Claw Groups REST 不公开 | 高 | 中 | P7 兜底 = CLI wrapper + SuperAgent 自写编排 |
| 各家 API 格式频繁变更 | 中 | 中 | `ModelCatalog` 远程更新机制已就位；适配层解耦 |
| 国内 key 被 host 锁，测试困难 | 中 | 低 | CI 只跑 intl；国内能力走 mock 测试 |
| 跨 provider tool 调用成本失控 | 中 | 中 | P8 CostLimiter + `tool_policy` 硬闸 |
| 用户升级踩到旧 models.json 格式问题 | 低 | 高 | P1 兼容锁定 + schema v1 永久支持 |
| MCP OAuth 流程在 CLI 中复杂 | 中 | 低 | 参考 Kimi CLI 实现；首次走浏览器，token 落盘缓存 |
| Qwen-Agent 是 Python 框架，PHP 要重写 | 中 | 中 | MCP 部分参考协议，不直接移植代码 |

---

## 9. 成功指标

里程碑发布时必须达成:

1. 四家 provider 在 intl / cn 两区域均能走通 chat + streaming + tools
2. `tests/Compat/` 全绿，当前基线版本的示例代码零修改跑通
3. 至少 10 个特色 Tool 可用（GLM 4 个 + Qwen 2 + Kimi 2 + MiniMax 5）
4. MCP Manager 能连通至少 3 个官方 MCP server
5. 能跑通混合示例：Claude 主脑 → GLM Web Search → Qwen thinking → MiniMax TTS
6. 文档完整（中英双语）
7. 新增代码覆盖率 ≥ 80%
8. 无新 P0/P1 bug

---

## 10. 附录

### 10.1 参考文档

- Kimi K2.6 Tech Blog: https://www.kimi.com/blog/kimi-k2-6
- Kimi Platform API: https://platform.kimi.ai/docs
- MoonshotAI/kimi-cli: https://github.com/MoonshotAI/kimi-cli
- DashScope API Reference: https://www.alibabacloud.com/help/en/model-studio/qwen-api-via-dashscope
- Qwen-Agent: https://github.com/QwenLM/Qwen-Agent
- Z.AI Developer Docs: https://docs.z.ai/
- GLM-5 GitHub: https://github.com/zai-org/GLM-5
- MiniMax API Docs: https://platform.minimax.io/docs/api-reference/api-overview
- MiniMax M2.7: https://www.minimax.io/news/minimax-m27-en
- MCP Spec: https://modelcontextprotocol.io/

### 10.2 术语表

- **Agent Swarm** — Kimi K2.6 的原生多 agent 协调，300 sub-agent / 4000 步
- **Claw Groups** — Kimi K2.6 的外部 agent 挂载机制（research preview）
- **Skill** — 可复用的任务 / 风格 / 流程封装（SuperAgent / Kimi / MiniMax 各有实现）
- **MCP** — Model Context Protocol，Anthropic 2024-11 提出的工具接入标准
- **Feature** — SuperAgent 抽象的通用能力（thinking / caching / web_search…）
- **Capability** — provider 声明自己支持哪些 Feature 的接口
- **Specialty Tool** — 把 provider 独有端点包装成的标准 Tool
- **Region** — provider 的地理区域（intl / cn / us / hk / eu）
- **JobHandle** — 长任务的异步句柄

### 10.3 决策记录

- **不做 OpenAI-compat 子类**: 用户明确要求；原生接入才能解锁特色能力
- **Features 走 `$options['features']`** 而非新位置参数: 向上兼容硬约束
- **Capability 接口平行于 `LLMProvider`**: 避免基接口膨胀，`instanceof` 检测简单
- **Specialty 包装为 Tool**: 使任何 provider 作主脑时都能调用别家能力
- **MCP 统一 Manager**: 四家都支持 MCP，不分别实现
- **Skills 以 SuperAgent 为 canonical**: bridge 到各家，不跟随任一家的私有格式
- **Kimi Agent Swarm 走 CLI wrapper + 等 REST**: 官方 REST 未公开前的务实路径
- **权限在 SuperAgent 层**: API 层太粗，且跨 provider 一致性重要

---

**文档状态:** 待讨论 / 确认 / 实施  
**下一步:** 确认 Phase 0 + Phase 1 细节 → 开工
