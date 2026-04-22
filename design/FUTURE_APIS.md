# 未来 API 接入建议

> **日期:** 2026-04-21 · **状态:** 待实施（调研完成，未开工）
>
> **范围:** v0.8.8 已支持 10 家 provider（Anthropic / OpenAI / OpenRouter / Bedrock / Ollama / Gemini / Kimi / Qwen / GLM / MiniMax）+ 11 个 specialty tool + MCP 统一接入。本文档列出**值得额外接入的 API**，按优先级分级。
>
> **不包含:** 已实现的任何 API。此文档只列**新增**目标。

---

## 架构前提

v0.8.8 已具的 `ChatCompletionsProvider` 基类让**任何 OpenAI-wire 协议的 provider 变得极廉价**（~40-60 行的薄子类）。真正花时间的是：
- 非 OpenAI-wire 协议（Azure 的 deployment URL、Cohere、Vertex OAuth）
- 独特能力（Perplexity 的 search-augmented chat）
- 新的能力领域（embeddings、rerank —— 需要新 capability 接口）

---

## Tier 1 —— 强需求 + 低成本（推荐优先落地）

### 1. DeepSeek ⭐⭐⭐
- **Endpoint:** `https://api.deepseek.com/v1`，Bearer
- **模型:** `deepseek-chat`（V3），`deepseek-reasoner`（R1），`deepseek-coder`（legacy）
- **协议:** OpenAI-compat
- **独特能力:** `deepseek-reasoner` 返回 `reasoning_content` 字段（独立的思考链），需要单独 parse
- **env vars:** `DEEPSEEK_API_KEY`
- **为什么:** 中文圈最流行的推理/编码模型之一，价格约是 OpenAI 的 1/20，对成本敏感用户首选
- **实施代价:** ~50 行 provider + models.json 条目 + reasoning_content adapter 可选 ~60 行

### 2. Azure OpenAI ⭐⭐⭐
- **Endpoint:** `https://{resource}.openai.azure.com/openai/deployments/{deployment}/chat/completions?api-version={version}`
- **Auth:** `api-key: {key}` header（不是 Bearer）OR OAuth via Entra ID
- **配置关键:** `model` 字段被忽略 —— deployment name 决定了用哪个模型
- **env vars:** `AZURE_OPENAI_API_KEY`, `AZURE_OPENAI_RESOURCE`, `AZURE_OPENAI_DEPLOYMENT`, `AZURE_OPENAI_API_VERSION`
- **为什么:** 所有企业用户都要这个
- **实施代价:** 中等——需要给 `ChatCompletionsProvider` 加 `buildAuthHeaders()` 钩子（替换 Bearer 逻辑），override `chatCompletionsPath()` 做动态 deployment URL + query string。约 100 行含 provider

### 3. xAI / Grok ⭐⭐
- **Endpoint:** `https://api.x.ai/v1`，Bearer
- **模型:** `grok-4`, `grok-3-latest`, `grok-3-mini`
- **协议:** OpenAI-compat
- **独特能力:** 集成 X 平台实时数据
- **env vars:** `XAI_API_KEY` / `GROK_API_KEY`
- **实施代价:** ~40 行

### 4. Groq ⭐⭐⭐
- **Endpoint:** `https://api.groq.com/openai/v1`，Bearer
- **模型:** `llama-3.3-70b-versatile`, `llama-4-scout`, `mixtral-8x7b-32768`, `gemma2-9b-it`
- **协议:** OpenAI-compat
- **独特能力:** **不是模型是推理引擎** —— LPU 硬件跑开源模型，速度比传统云快 10-20 倍
- **env vars:** `GROQ_API_KEY`
- **为什么:** 延迟敏感的 agent 任务（实时 agent 循环、语音对话前端）首选
- **实施代价:** ~40 行

### 5. Mistral ⭐⭐
- **Endpoint:** `https://api.mistral.ai/v1`，Bearer
- **模型:** `mistral-large-latest`, `mistral-small-latest`, `codestral-latest`, `pixtral-large-latest`
- **协议:** OpenAI-compat
- **独特能力:** Codestral 是专门的代码模型；欧洲托管（GDPR 友好）
- **env vars:** `MISTRAL_API_KEY`
- **实施代价:** ~40 行

**Tier 1 总代价:** ~300 行生产代码 + 5 组 models.json 条目 + 约 50 个测试

---

## Tier 2 —— 独特价值（按场景落地）

### 6. Perplexity Sonar ⭐⭐
- **Endpoint:** `https://api.perplexity.ai`，Bearer
- **模型:** `sonar`, `sonar-pro`, `sonar-reasoning`, `sonar-reasoning-pro`, `sonar-deep-research`
- **协议:** OpenAI-compat +**内置 citations**
- **独特能力:** 模型**自带实时 web search** —— 响应直接包含 citations 数组。不是单纯 LLM，是 search+LLM 一体
- **两种接法:**
  - 作为 provider（`PerplexityProvider extends ChatCompletionsProvider`）
  - 作为 Tool（`PerplexitySearchTool` 直接问答，任何主脑可调）
- **env vars:** `PERPLEXITY_API_KEY`
- **实施代价:** Provider 50 行 + Tool 60 行

### 7. Google Vertex AI（企业版 Gemini）
- **Endpoint:** `https://{location}-aiplatform.googleapis.com/v1/projects/{project}/locations/{location}/publishers/google/models/{model}:streamGenerateContent`
- **Auth:** OAuth2 服务账户 JWT（不是 API key）
- **协议:** 和 AI Studio Gemini 相同的 body shape
- **为什么:** 已有 `GeminiProvider`（AI Studio）但企业一律走 Vertex（私有网络、GCP IAM）
- **env vars:** `GOOGLE_APPLICATION_CREDENTIALS`（服务账户 JSON 路径），`VERTEX_PROJECT`, `VERTEX_LOCATION`
- **实施代价:** 中等——需要 Google Auth Library PHP 做服务账户 token 交换；本体 ~80 行 + 依赖

### 8. Cohere ⭐
- **Endpoint:** `https://api.cohere.com/v2/chat`
- **协议:** **非 OpenAI-compat** —— Cohere 自己的 body shape
- **独特能力:** RAG 场景最强（原生 `citations` 支持），`/v2/rerank` 独家
- **env vars:** `COHERE_API_KEY`
- **实施代价:** 独立 provider 类似 Anthropic 的复杂度，~150 行

### 9. ByteDance Doubao（豆包）
- **Endpoint:** `https://ark.cn-beijing.volces.com/api/v3`（通过火山方舟）
- **协议:** OpenAI-compat
- **env vars:** `DOUBAO_API_KEY` / `ARK_API_KEY`
- **为什么:** 国内增长最猛的模型之一，完善中国 provider 矩阵
- **实施代价:** ~50 行

### 10. Baidu ERNIE / iFlytek Spark
- **Endpoint:** 各自独立（ERNIE via `qianfan.baidubce.com`，Spark via `spark-api.xf-yun.com`）
- **Auth:** 都是定制的签名流程（ERNIE 用 access_token，Spark 用 HMAC-SHA256 签名 URL）
- **协议:** 都不是 OpenAI-compat
- **实施代价:** 每家 ~150 行（签名逻辑）
- **优先级:** 较低——国内四强（Kimi/Qwen/GLM/MiniMax）已覆盖 80% 场景

### 11. Together AI / Fireworks AI
- **Endpoint:** 
  - Together: `https://api.together.xyz/v1`
  - Fireworks: `https://api.fireworks.ai/inference/v1`
- **协议:** OpenAI-compat
- **独特能力:** 一个 key 访问数十个开源模型（Llama / DeepSeek / Qwen / GLM / Mixtral）
- **env vars:** `TOGETHER_API_KEY`, `FIREWORKS_API_KEY`
- **实施代价:** 各 ~40 行
- **价值:** 开源模型 benchmark / A-B 对比场景刚需

---

## Tier 3 —— Specialty Tools（包装成 ProviderToolBase，不是完整 provider）

这些是**工具级别**的接入——把现有 SuperAgent 主脑接到新的独立 API。不需要做完整 LLMProvider。

### 12. ElevenLabs TTS ⭐⭐⭐
- **Endpoint:** `https://api.elevenlabs.io/v1`
- **独特能力:** TTS 事实标准，音色质量和可控性甩 MiniMax 一截
- **要包的工具:**
  - `ElevenLabsTtsTool` —— sync TTS
  - `ElevenLabsVoiceCloneTool` —— 上传音频 → 克隆声纹
  - `ElevenLabsVoiceDesignTool` —— 通过描述生成声纹
- **env vars:** `ELEVENLABS_API_KEY`
- **实施代价:** ~150 行（3 个工具）

### 13. Exa / Tavily / Brave Search ⭐⭐
- **Exa:** `https://api.exa.ai/search` —— AI-native embedding 搜索
- **Tavily:** `https://api.tavily.com/search` —— agent 优化的搜索
- **Brave:** `https://api.search.brave.com/res/v1/web/search` —— 隐私独立索引
- **独特价值:** `GlmWebSearchTool` 是中国大陆优化的；海外场景下这三家结果质量明显更高
- **实施代价:** 每家 ~80 行

### 14. Deepgram / AssemblyAI ASR ⭐
- **Deepgram:** `https://api.deepgram.com/v1/listen`（Nova-3 模型，~300ms 延迟）
- **AssemblyAI:** `https://api.assemblyai.com/v2`
- **独特价值:** 比 `GlmAsrTool` 质量高、多语言覆盖广；Deepgram 有真正的实时流式转写
- **实施代价:** 各 ~100 行

### 15. Runway / Luma Dream Machine ⭐
- **Runway:** `https://api.dev.runwayml.com/v1`（Gen-3 / Gen-4）
- **Luma:** `https://api.lumalabs.ai/dream-machine/v1`
- **独特价值:** 质量在某些场景下超过 MiniMax Hailuo；走完整的**异步 polling** 模式，复用现有 `pollUntilDone`
- **实施代价:** 各 ~120 行

### 16. OpenAI 独立工具（Whisper / TTS / DALL-E）
- 已有 `OpenAIProvider` 做 chat，但 `/v1/audio/transcriptions`、`/v1/audio/speech`、`/v1/images/generations` 没单独包装
- **完整性问题:** 既然 MiniMax 的 TTS/Video/Image 都有独立工具，OpenAI 对等工具也该有
- **实施代价:** 3 个工具各 ~80 行，~240 行总

---

## Tier 4 —— Embeddings / Rerank（新能力领域）

**SuperAgent 目前没有 embedding 抽象。** 这是个完整的空白。需要先铺基础设施再接入各家。

### 17. 新建 `SupportsEmbedding` / `SupportsRerank` 能力接口
- 新增 `src/Providers/Capabilities/SupportsEmbedding.php` + `SupportsRerank.php`
- 新增 `EmbeddingResult` / `RerankResult` 值对象
- 新增 `src/Tools/Providers/*/EmbeddingTool` 系列

### 18. Voyage AI（RAG embedding 事实标准）⭐
- **Endpoint:** `https://api.voyageai.com/v1/embeddings`
- **为什么:** 独立评测中嵌入质量常年第一
- **env vars:** `VOYAGE_API_KEY`

### 19. Cohere Embed + Rerank
- **Endpoint:** `https://api.cohere.com/v2/embed` + `/v2/rerank`
- **独特:** `rerank-v3.5` 是 RAG 生产栈必备组件

### 20. OpenAI Embeddings
- `text-embedding-3-large` / `text-embedding-3-small`
- 已有 `OpenAIProvider`，加 embedding 方法即可

### 21. Jina AI
- `https://api.jina.ai/v1/embeddings` + `/v1/rerank`
- 多语言（中文）优势明显

**Tier 4 总代价:** 基础设施 ~200 行 + 4 家 provider × 50 行 = ~400 行 + 测试

---

## 明确不建议做的

以下虽有人问但**性价比低** / **已有替代**，本文档列出只是把研究过的结论记下来：

- **Midjourney API** —— 官方 API 极难拿到 token，非官方 proxy 合规问题；用 Runway / Luma / MiniMax 更稳
- **Stability AI (SDXL)** —— 自建负担重，服务端 pipeline 完全不同；当前用户需求量小
- **IBM Watsonx / AI21 Jamba / Reka** —— 使用率低，观望
- **Meta Llama 官方 API** —— 没有统一的官方 cloud endpoint；通过 Groq / Together / Fireworks 接更合适
- **Character.AI** —— 娱乐向，agent 应用场景不对口
- **Writer / Jasper / Copy.ai** —— 套壳商业化产品，不是基础 API
- **OpenAI Assistants API v2** —— OpenAI 自己 2026 初已 deprecate 转向 Responses API；追这条路线会烂账

---

## 建议执行顺序

**Sprint A（2 天）:** Tier 1 一次性全做 —— DeepSeek + Azure + xAI + Groq + Mistral。这是**5 家 × 50 行 ≈ 300 行生产代码** + 精简测试，单轮能完成。

**Sprint B（1 天）:** Tier 2 中的 Perplexity + ByteDance Doubao。各 ~50 行，快速补齐。

**Sprint C（2 天）:** Tier 3 的 ElevenLabs + Exa + Tavily —— 三个高价值独家 Tool。

**Sprint D（3 天）:** Vertex AI + Cohere —— 企业 Gemini + RAG 生态。这两家 auth/协议都更复杂，需要更多测试时间。

**Sprint E（视需求，~1 周）:** Tier 4 embedding 基础设施 + 4 家 embedding provider。独立 Phase 级别工作。

**不做的:** Baidu ERNIE / iFlytek Spark（签名复杂 + 优先级低），国内次级模型。

---

## 代价总览

| Tier | 代价估算 | 单位价值 |
|---|---|---|
| Tier 1（5 家 OpenAI-wire） | ~300 行生产 + 50 测试 = 2 天 | **极高**（覆盖 90% 新需求） |
| Tier 2（6 家特色） | ~600 行 = 3 天 | 中高 |
| Tier 3（媒体 / 搜索工具 11 个） | ~800 行 = 3 天 | 中高（填独家能力空白） |
| Tier 4（embeddings 新能力） | ~600 行 + 架构改动 = 5 天 | 高（填能力领域空白） |

**全做完:** 约 2.5 周，~2300 行生产代码。

**建议最少做:** Tier 1 + Tier 3 的 ElevenLabs（最有独家价值的单品）——约 3 天，~450 行。

---

## 附录 —— 实施 checklist 模板

添加任何新 provider 时，至少完成：

- [ ] `src/Providers/<Name>Provider.php`（或 Tool）
- [ ] `resources/models.json` 条目（含 capabilities + regions + 估算价格）
- [ ] `ProviderRegistry::$providers` 注册
- [ ] `ProviderRegistry::$defaultConfigs` 条目
- [ ] `ProviderRegistry::validateConfig` 分支
- [ ] `ProviderRegistry::createFromEnv` 分支（含 env alias）
- [ ] `ProviderRegistry::discover` 分支
- [ ] `ProviderRegistry::getCapabilities` 条目
- [ ] `tests/Unit/Providers/<Name>ProviderTest.php`（constructor / default model / base URL / name / auth header）
- [ ] Full suite 跑绿（不回归现有 2435 测试）
- [ ] `docs/FEATURES_MATRIX.md` 表格更新
- [ ] `CHANGELOG.md` 新条目

---

**文档状态:** 待用户拍板从哪批开工。Sprint A 的 5 家 OpenAI-wire provider 是最划算的起点。
