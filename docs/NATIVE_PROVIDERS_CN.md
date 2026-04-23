# 原生接入 Kimi / Qwen / GLM / MiniMax — 用户指南

> SuperAgent v0.8.8 起，Moonshot Kimi、Alibaba Qwen、Z.AI GLM、MiniMax 四家以**原生 provider** 形式接入 —— 有自己的类、自己的 region 配置、自己的原生特性入口，不再是"给 OpenAIProvider 换 base_url"的变通路径。
>
> **语言:** [中文](NATIVE_PROVIDERS_CN.md) · 英文版待翻译

---

## 1. 快速开始

### 1.1 设置凭证

```bash
# 四家默认都走国际版 endpoint（intl region）
export KIMI_API_KEY=sk-moonshot-xxx
export QWEN_API_KEY=sk-dashscope-xxx
export GLM_API_KEY=your-zai-key
export MINIMAX_API_KEY=your-minimax-key
export MINIMAX_GROUP_ID=your-group-id   # 可选
```

支持的 env 别名（自动识别）：

| 主变量 | 别名 |
|---|---|
| `KIMI_API_KEY` | `MOONSHOT_API_KEY` |
| `QWEN_API_KEY` | `DASHSCOPE_API_KEY` |
| `GLM_API_KEY` | `ZAI_API_KEY` / `ZHIPU_API_KEY` |
| `MINIMAX_API_KEY` | — |

### 1.2 选 region（国内 key 必须用国内 endpoint）

```bash
export KIMI_REGION=cn          # intl | cn
export QWEN_REGION=cn          # intl | us | cn | hk
export GLM_REGION=cn           # intl | cn
export MINIMAX_REGION=cn       # intl | cn
```

**重要**：四家的 API key 都**绑 host**。国际版 key 无法调用国内 endpoint，反之亦然。`CredentialPool` 会按 `region` 过滤 key，避免错配。

### 1.3 直接用

```bash
# CLI
superagent chat -p kimi "用 Python 写一个斐波那契"

# PHP
use SuperAgent\Providers\ProviderRegistry;

$provider = ProviderRegistry::createFromEnv('kimi');
// 或指定 region:
$provider = ProviderRegistry::createWithRegion('qwen', 'us', ['api_key' => '...']);
```

---

## 2. 四家默认模型 + region map

| Provider | 默认模型 | 支持区域 → endpoint |
|---|---|---|
| **kimi** | `kimi-k2-6` | `intl` → api.moonshot.ai<br>`cn` → api.moonshot.cn |
| **qwen** | `qwen3.6-max-preview` | `intl` → dashscope-intl.aliyuncs.com (Singapore)<br>`us` → dashscope-us.aliyuncs.com (Virginia)<br>`cn` → dashscope.aliyuncs.com (Beijing)<br>`hk` → cn-hongkong.dashscope.aliyuncs.com |
| **glm** | `glm-4.6` | `intl` → api.z.ai/api/paas/v4<br>`cn` → open.bigmodel.cn/api/paas/v4 |
| **minimax** | `MiniMax-M2.7` | `intl` → api.minimax.io<br>`cn` → api.minimaxi.com |

全量模型清单：`superagent models list` 或查 `resources/models.json`。

---

## 3. 各家原生能力

### 3.1 Kimi K2.6

| 能力 | 用法 |
|---|---|
| Thinking（请求级）| `$options['features']['thinking']` —— 同模型上发 `reasoning_effort`（low/medium/high，根据 `budget` 分桶）+ `thinking: {type: "enabled"}`，**不换模型** |
| 文件抽取（PDF/PPT/Word → 文本） | 内置工具 `kimi_file_extract` |
| Batch 处理（JSONL） | 内置工具 `kimi_batch`，`wait=false` 即返 batch_id |
| **Agent Swarm**（300 sub-agents / 4000 步） | 内置工具 `kimi_swarm` —— 任何主脑可调用；REST schema 为 Moonshot 未公开前的**临时实现**，官方发布后替换 |
| Context Caching | 服务器端自动（不需显式标记） |

### 3.2 Qwen3.6-Max-Preview

| 能力 | 用法 |
|---|---|
| **Thinking**（`enable_thinking` + `thinking_budget`） | `$options['features']['thinking']` 或直接 `$options['enable_thinking'] = true` |
| **Code Interpreter**（服务器沙盒） | `$options['enable_code_interpreter'] = true` |
| **Qwen-Long**（10M tokens via 文件引用） | 内置工具 `qwen_long_file` 上传，回传 `fileid://xxx`；放进 system message 即可让 Qwen-Long 访问。**注意：当前只支持 `cn` region** |
| 多模态（VL / Omni） | 用 `qwen3-vl-plus` / `qwen3-omni` 模型 |
| OCR | 用 `qwen-vl-ocr` 模型 |

### 3.3 GLM（Z.AI / BigModel）

GLM 的特色是**工具以独立 REST endpoint 暴露**，因此主 LLM 可以不是 GLM，也能调它的工具。

| 能力 | 用法 |
|---|---|
| **Thinking**（`thinking: {type: enabled}`） | `$options['thinking'] = true` |
| **Web Search** | 内置工具 `glm_web_search` |
| **Web Reader**（URL → 干净 markdown） | 内置工具 `glm_web_reader` |
| **OCR / Layout Parsing** | 内置工具 `glm_ocr` |
| **ASR**（语音转文本，GLM-ASR-2512） | 内置工具 `glm_asr` |
| Agentic 基准最强开源模型 | 用 `glm-5`（744B / 40B active）— MCP-Atlas / τ²-Bench / BrowseComp 开源第一 |

### 3.4 MiniMax M2.7

M2.7 是**自进化 agent 模型**，多 agent 协作能力训练在模型里（不靠 prompt 工程）。

| 能力 | 用法 |
|---|---|
| **Agent Teams**（原生多 agent，角色边界 + 对抗推理 + 协议遵守） | `$options['features']['agent_teams']` + `roles` / `objective` |
| **Skills**（2000+ token skill，97% 遵循率） | `SkillManager` + `SkillInjector` 自动注入 system prompt |
| **Dynamic Tool Search**（模型自己找工具） | 把所有相关工具挂上即可 |
| TTS（同步短文本 ≤10K 字符） | 内置工具 `minimax_tts` |
| 音乐生成（music-2.6） | 内置工具 `minimax_music` |
| 视频（Hailuo-2.3, T2V + I2V） | 内置工具 `minimax_video`（异步，`wait=false` 可获取 task_id） |
| 图像（image-01） | 内置工具 `minimax_image` |
| Voice cloning / design | 需要时补工具（endpoint 已在 `api.minimax.io/v1`） |

---

## 4. 混合调用 —— 四家 + 其他 provider 一起用

这是 v0.8.8 的核心设计。**主脑是谁无所谓，每家特色都能被调。**

### 4.1 例：Claude 做主脑，用 GLM 搜网 + MiniMax 做 TTS

```php
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Tools\Providers\Glm\GlmWebSearchTool;
use SuperAgent\Tools\Providers\MiniMax\MiniMaxTtsTool;

$claude   = ProviderRegistry::createFromEnv('anthropic');
$glmProv  = ProviderRegistry::createFromEnv('glm');
$mmProv   = ProviderRegistry::createFromEnv('minimax');

$tools = [
    new GlmWebSearchTool($glmProv),
    new MiniMaxTtsTool($mmProv),
];

// Claude 主脑 + GLM 工具 + MiniMax 工具
$response = $claude->chat($messages, $tools);
```

看完整示例：`examples/mixed_agent.php`。

### 4.2 原理

- SuperAgent 的 `Tool` 接口是 provider-agnostic 的 —— 主 LLM 只看到 `name` / `description` / `inputSchema` / `execute()`
- 每家的特色 Tool（`GlmWebSearchTool` 等）内部**复用**该家 provider 的 Guzzle client（已配好 bearer / base_url / region）
- 主脑调 Tool 时，实际 HTTP 请求打到对应 vendor，拿结果返回
- 加上 MCP tools（任何 MCP server）和 Skills（system prompt 注入）— 都走同一个 Tool 接口

---

## 5. 特性（`features` 字段）

`$options['features']` 是跨 provider 的统一入口，由 `FeatureDispatcher` 路由：

```php
$provider->chat($messages, $tools, $system, [
    'features' => [
        'thinking' => ['budget' => 4000, 'required' => false],
        'agent_teams' => [
            'objective' => '生成 10 页行业分析',
            'roles' => [
                ['name' => 'researcher', 'description' => '搜集资料'],
                ['name' => 'writer', 'description' => '撰写'],
                ['name' => 'critic', 'description' => '挑刺'],
            ],
        ],
    ],
]);
```

**降级策略：**
- `required: false`（默认）→ 无原生支持时走 fallback（CoT prompt / scaffold 注入）
- `required: true` → 无原生支持时抛 `FeatureNotSupportedException`（继承 `ProviderException`，不改现有 `catch`）
- `enabled: false` → 硬 no-op

查每家支持哪些 feature：`docs/FEATURES_MATRIX.md`。

### 5.1 `extra_body` 直通口（高级用户）

所有继承 `ChatCompletionsProvider` 的 provider（OpenAI / OpenRouter / Kimi / GLM / MiniMax）都支持 `$options['extra_body']` —— 一个在 `customizeRequestBody`、`FeatureDispatcher` **全部执行完之后**再深度合并到请求 body 顶层的数组。对标 OpenAI Python SDK 的 `extra_body=` 约定，专门留给"厂商上了新请求字段但我们还没来得及出能力 adapter"的场景：

```php
$provider->chat($messages, $tools, $system, [
    // Kimi：不用等能力 adapter，直接开 session 级 prompt cache
    'extra_body' => ['prompt_cache_key' => $sessionId],
]);

// 覆盖 adapter 的选择：FeatureDispatcher 算出 "medium"，我们要 "high"
$provider->chat($messages, $tools, $system, [
    'features'   => ['thinking' => ['budget' => 4000]],
    'extra_body' => ['reasoning_effort' => 'high'],
]);
```

合并语义：标量字段覆盖；associative 子对象深度合并（叶子胜出）；indexed 列表整体替换（不拼接）。

---

## 6. MCP（跨 provider 统一工具接入）

```bash
# 用户级配置
superagent mcp add filesystem stdio npx --arg -y --arg @modelcontextprotocol/server-filesystem --arg /tmp
superagent mcp add search http https://mcp.example.com/search --header "Authorization: Bearer x"
superagent mcp list
superagent mcp remove filesystem
superagent mcp path   # 输出 ~/.superagent/mcp.json
```

配置一次，**四家主脑 + 6 家既有主脑全部可调**。MCP tool 自动包装为 SuperAgent `Tool`，走标准的 `formatTools()` 路径。

---

## 7. Skills

SuperAgent 的 Skill 系统把指令 / 风格 / 规则打包成 markdown 文件，放在：
- `~/.superagent/skills/`（用户级）
- `<project>/.superagent/skills/`（项目级）

```bash
superagent skills install my-skill.md
superagent skills list
superagent skills show my-skill
superagent skills remove my-skill
```

**Skill 文件格式**（frontmatter + body）:
```markdown
---
name: code-review
description: Review PR against project conventions
category: engineering
---
Review the diff. Focus on: ...
```

`SkillInjector` 默认把 skill body 合并进 `$options['system_prompt']`（带幂等标题 `## Skill: <name>`）。Kimi / MiniMax 原生 Skills API 公开后，注册 bridge 即可切换到原生上传路径。

---

## 8. 安全（Security Layer）

每个工具都声明 `attributes()`，`ToolSecurityValidator` 据此决策：

| attribute | 含义 | 默认行为 |
|---|---|---|
| `network` | 访问公网 | `SUPERAGENT_OFFLINE=1` 时 deny |
| `cost` | 按量计费 | 走 `CostLimiter`（per-call / per-tool-daily / global-daily） |
| `sensitive` | 上传用户数据 | 默认 `ask`（可配置 `allow` / `deny`） |
| （Bash tools） | — | 委托给现有 `BashSecurityValidator`（23 checks，行为不变） |

**示例配置：**
```php
use SuperAgent\Security\ToolSecurityValidator;

$validator = new ToolSecurityValidator([
    'sensitive_default' => 'ask',
    'cost' => [
        'global_daily_usd' => 10.00,
        'per_tool_daily_usd' => ['minimax_video' => 5.00],
        'ask_threshold_usd' => 0.50,
    ],
]);

$decision = $validator->validate($tool, $input, /*estimated_cost=*/0.20);
// $decision->verdict ∈ {'allow', 'ask', 'deny'}
```

Ledger 路径：`~/.superagent/cost_ledger.json`（UTC 自动滚动）。

---

## 9. Agent Team / Swarm 编排

三条路径，`SwarmRouter` 自动选或手动指定：

```bash
# 规划（暂不执行）
superagent swarm "分析这个 repo 并生成 PPT" --max-sub-agents 100
# → native_swarm (Kimi)

superagent swarm "写市场分析" --role researcher:搜资料 --role writer:撰写
# → agent_teams (MiniMax M2.7)

superagent swarm "简单并行任务"
# → local_swarm（走 src/Swarm/ 现有基础设施）

superagent swarm ... --json   # 输出 plan 的 JSON
```

实际执行 wiring 在下一个小版本补齐；目前 CLI 只**规划**，可以拿 plan 的 `strategy` + `provider` 手写调度。

---

## 10. 常见问题

**Q: 我原来用 OpenAIProvider 加 base_url 调 Kimi，需要迁移吗？**
A: 建议迁移到 `KimiProvider` —— 能用上 region 自动切换、native 特色能力（Swarm 等）、统一错误 tag。迁移指南：`docs/MIGRATION_NATIVE.md`。

**Q: 四家我没账号，只想用 Claude，会受影响吗？**
A: 不会。这些都是 opt-in。没设 env 变量就不会被 `discover()` 挑到，也不会出现在 `superagent models list` 的非零价位模型里。

**Q: Kimi Agent Swarm 真的能用吗？**
A: **SuperAgent 侧架构完整**（`SupportsSwarm` 接口 + `KimiSwarmTool` + `SwarmRouter`）；但 Moonshot 的 Swarm REST 端点**目前官方未公开规范**。我们按最合理结构实现并标注 `provisional`，正式发布后 30 行改动即可对齐。

**Q: 为什么 Qwen 有 4 个 region，其他只有 2 个？**
A: Qwen（DashScope）实际支持 Singapore / Virginia / Beijing / Hong Kong 四个地域；其他三家目前只对外宣传国际版 + 国内版。

**Q: MCP / Skills 改变了吗？**
A: 已有的 MCP（`MCPManager` 1200+ 行）和 Skills（`SkillManager` + builtins）**不动**。本版本只是把 `~/.superagent/mcp.json` 和 `~/.superagent/skills/` 做成一等目录 + 加 CLI，让用户管理更方便。

---

**版本:** v0.8.8 · **更新日期:** 2026-04-21 · **设计文档:** `design/NATIVE_PROVIDERS_CN.md`
