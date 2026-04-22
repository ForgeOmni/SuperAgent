# 迁移指南 —— 从 OpenAI-compat 模式切到 Kimi/Qwen/GLM/MiniMax 原生 provider

> 用于之前以"`OpenAIProvider` + 自定义 base_url"方式调用 Moonshot / Alibaba / Z.AI / MiniMax 的用户。v0.8.8 发布了真正的原生 provider —— 本指南给出最小切换 diff 和切换后解锁的新能力。
>
> **语言:** [English](MIGRATION_NATIVE.md) · [中文](MIGRATION_NATIVE_CN.md) · [Français](MIGRATION_NATIVE_FR.md)

## TL;DR

```diff
- $kimi = ProviderRegistry::create('openai', [
-     'api_key'  => getenv('KIMI_API_KEY'),
-     'base_url' => 'https://api.moonshot.ai',
-     'model'    => 'kimi-k2-6',
- ]);
+ $kimi = ProviderRegistry::create('kimi');
```

一行迁移的全部。其他都是附加好处。

## 这行改动带来什么

| 好处 | OpenAI-compat（之前） | Native provider（现在） |
|---|---|---|
| 区域切换 | 每个区域手改 `base_url` | `createWithRegion('kimi', 'cn')` 或 `KIMI_REGION=cn` env |
| 错误 tag | 报错显示 `provider: 'openai'` | 报错显示 `provider: 'kimi'`（日志易读） |
| `CredentialPool` 安全 | 国内 key 可能漏给国际 endpoint | region 打标签；错配 key 被过滤 |
| Native 特性（`thinking` / `swarm` / `agent_teams` / …） | 访问不到 | 通过 `$options['features']` / `SupportsSwarm` / FeatureAdapter |
| Specialty 工具（GLM Web Search / Kimi File-Extract / MiniMax TTS / …） | 访问不到 | 作为常规 `Tool` 类导入 |
| 模型目录条目（定价、max_context、regions） | 缺失 | 已在 `resources/models.json` 中 ship |

## 按 provider 迁移

### Kimi（Moonshot）

```diff
- 'api_key'  => getenv('KIMI_API_KEY'),
- 'base_url' => 'https://api.moonshot.ai',
  // 'provider' => 'openai'
+ 'provider' => 'kimi',
+ 'region'   => 'intl',   // 或 'cn'
```

**Env**：`KIMI_API_KEY`（之前是 `OPENAI_API_KEY` 指向 Moonshot）。旧别名 `MOONSHOT_API_KEY` 也识别。

**默认模型**：`kimi-k2-6`（之前要硬编码）。

**解锁的新能力**：
- `kimi_file_extract` / `kimi_batch` / `kimi_swarm` 工具
- `SupportsSwarm` —— `$kimi->submitSwarm(...)` 返 `JobHandle`

### Qwen（DashScope）

```diff
- 'api_key'  => getenv('DASHSCOPE_API_KEY'),
- 'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
  // 'provider' => 'openai'
+ 'provider' => 'qwen',
+ 'region'   => 'intl',   // 或 'us' / 'cn' / 'hk'
```

**Body shape 变化** —— 原生 provider 走 DashScope 的 `text-generation/generation` 端点（不是 chat-completions）。只调 `chat()` 的 caller 无需操心；但如果之前手搓请求走 compat 端点，要么迁进 `QwenProvider::buildRequestBody()`，要么走 `$options` 传新 shape（`enable_thinking` / `enable_code_interpreter` 等）。

**Env**：`QWEN_API_KEY`（旧 `DASHSCOPE_API_KEY` 继续生效）。

**解锁的新能力**：
- 四个 region（而不是一个）
- `enable_thinking` + `thinking_budget` 透传
- `enable_code_interpreter`
- `qwen_long_file` 工具（10M token 文件引用）
- 完整 `SupportsThinking` + `ThinkingAdapter` 集成

### GLM（Z.AI / BigModel）

```diff
- 'api_key'  => getenv('ZAI_API_KEY'),
- 'base_url' => 'https://api.z.ai/api/paas/v4',
  // 'provider' => 'openai'
+ 'provider' => 'glm',
+ 'region'   => 'intl',   // 或 'cn'（BigModel）
```

**Env**：`GLM_API_KEY`（别名 `ZAI_API_KEY` / `ZHIPU_API_KEY`）。

**解锁的新能力**：
- `glm_web_search` / `glm_web_reader` / `glm_ocr` / `glm_asr` —— 任何主脑可用，不限 GLM
- `$options['thinking'] = true` 触发 `thinking: {type: enabled}`

### MiniMax

```diff
- 'api_key'  => getenv('MINIMAX_API_KEY'),
- 'base_url' => 'https://api.minimax.io',
  // 'provider' => 'openai'
+ 'provider' => 'minimax',
+ 'region'   => 'intl',   // 或 'cn'（api.minimaxi.com）
+ 'group_id' => getenv('MINIMAX_GROUP_ID'),  // 可选，设 X-GroupId header
```

**路径变化**：原生 provider 打 `/v1/text/chatcompletion_v2`（compat 默认是别的）。

**解锁的新能力**：
- `minimax_tts` / `minimax_music` / `minimax_video` / `minimax_image` 作为工具
- `agent_teams` feature 激活 M2.7 原生多 agent 模式

## CredentialPool —— 给 key 打标签

如果池化多个 key，加 `region` 标签防止国内 key 漏给国际端点：

```diff
  $pool = CredentialPool::fromConfig([
      'kimi' => [
          'strategy' => 'round_robin',
          'keys' => [
-             'sk-kimi-1',
-             'sk-kimi-2',
+             ['key' => 'sk-kimi-intl', 'region' => 'intl'],
+             ['key' => 'sk-kimi-cn',   'region' => 'cn'],
          ],
      ],
  ]);
```

无 region 字符串继续被接受、视为"通用"，所以这个迁移是可选的 —— 加第二个 region 的 key 时才必须。

## Feature spec —— `$options['features']`

不再写 provider-specific 魔法字符串，原生路径暴露统一 feature channel：

```diff
  $provider->chat($messages, $tools, $systemPrompt, [
-     // 手动拼每个 provider 的私有字段
-     'enable_thinking' => true,
-     'thinking_budget' => 4000,
+     'features' => [
+         'thinking' => ['budget' => 4000],
+     ],
  ]);
```

同一 feature map 在 Anthropic (`thinking`) / Qwen (`parameters.enable_thinking`) / GLM (`thinking: {type: enabled}`) / Kimi（切模型变体）上都有效 —— `FeatureDispatcher` 负责翻译。

## 什么保持不变

- **所有 v0.8.8 前公开方法**签名都在。`catch (ProviderException)` 依然能接新错误（新的 `FeatureNotSupportedException` 继承它）
- **`resources/models.json` v1** 继续正常加载。Schema v2 纯加法
- **`OpenAIProvider`** 依然在、依然指 `api.openai.com`、依然支持 OAuth / Organization。对真正的 OpenAI 用户零变化
- **`BashSecurityValidator`** 未动。23 个检查和测试套件被锁定；新 `ToolSecurityValidator` 对 Bash 工具 delegate 到它

## 兼容性保证

仓库的 `tests/Compat/` 套件锁定：
- v1 `models.json` 字段映射 byte-exact
- 每个 v0.8.8 前 provider 的默认 base-URL host
- `ProviderRegistry::getCapabilities()` 对 6 家老 provider 的 shape

任何破坏这些测试的改动按定义是 breaking change，走明确的 deprecation 流程 —— 不会悄悄升版。

## 什么时候保留 compat 模式

只在以下情况继续用 `openai` + vendor `base_url`：
- 明确不想要 native 特性（心智模型更简单）
- 包的 vendor 在 0.8.8 没有专用 provider（现在基本不可能 —— 主要中国厂家都有了）
- 还在原型阶段，没决定用哪家

对生产用途 —— 切到 native provider。单 region 安全 + 错误归属两点就足以值回这一行改动。

---

**版本:** v0.8.8 · **最后更新:** 2026-04-21
