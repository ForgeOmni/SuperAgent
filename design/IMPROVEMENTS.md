# v0.8.8 发布后改进清单

> **日期:** 2026-04-21 · **基线版本:** v0.8.8 · **状态:** 待实施
>
> 诚实盘点 v0.8.8 发布后尚存的**设计债、未验证点、DX 毛刺**。按影响面分三级。
>
> **定位:** 这些不是 bug，是"架构正确但细节未打磨"的点。每项注明现状、影响、推荐解法、工作量。

---

## 🔴 高优先级 —— 真正有风险

### 1. Kimi Agent Swarm REST schema 是推测的
- **现状:** `KimiProvider::submitSwarm()` 的 `/v1/swarm/jobs` 端点、body 字段、响应形状——我按最合理结构写，标了 `provisional`，但 Moonshot 官方未公开规范
- **影响:** 第一次真实调用可能就报错；设计里为其他 provider 铺了基础（`SwarmRouter`、`KimiSwarmTool`），但实际跑不通就是空架子
- **解法:**
  1. 盯 Moonshot changelog / GitHub / 官方 API reference 发布
  2. 或抓 `MoonshotAI/kimi-cli` 流量逆向（`kimi mcp`、`kimi swarm` CLI 底层 HTTP 调用）
  3. 或用 Kimi Code CLI 作为 wrapper（不走 REST，走 CLI binary）
- **工作量:** 30 行改动（endpoint + body 字段映射）+ 集成测试

### 2. 全部 provider / tool 测试用 MockHandler
- **现状:** 6803 断言，一条都没真打过 vendor endpoint
- **影响:** 任何上游 schema 漂移我们看不见；新增 provider 可能写错 endpoint 还过所有测试
- **解法:**
  - 新建 `tests/Integration/` testsuite，env 变量触发（`SUPERAGENT_INTEGRATION=1` + 各家 `*_API_KEY`）
  - 每家 provider 至少 1 条真 chat + 1 条特色工具
  - CI 不跑，本地 / release 前跑
- **工作量:** 每家 ~2 小时，10 家 ~3 天

### 3. 仓库没 CI（`.github/workflows/` 不存在）
- **现状:** 没有自动化测试流水线
- **影响:** 合并者忘跑测试就可能 ship 回归；靠 maintainer 自觉
- **解法:** `test.yml` 跑 Unit + Smoke + Compat（不跑 Integration）；`release.yml` 打 tag 时自动跑全套并创建 GitHub release
- **工作量:** 半天

### 4. `resources/models.json` 新 provider 定价是估算
- **现状:** 22 条新 entries（Kimi / Qwen / GLM / MiniMax）的 input/output 价格是我根据官网截图大致写的，可能误差几倍
- **影响:** `CostCalculator` 出的账单数字不准；用户按这个做预算会翻车
- **解法:** 逐家核对官方定价页面，更新 `models.json`
- **工作量:** 2 小时（纯查资料）

### 5. Kimi `SupportsThinking` 没实现
- **现状:** Kimi thinking 走"选特定 thinking 变体模型"路径（`kimi-k2-thinking`），不是 request field；我留了注释没实现
- **影响:** Kimi 主脑走 thinking feature 目前全走 CoT 降级，浪费 Kimi 的原生思考能力
- **解法:** `thinkingRequestFragment($budget)` 在 Kimi 里返 `['model' => 'kimi-k2-thinking-preview']` —— 触发模型切换
- **工作量:** 10 行

---

## 🟡 中优先级 —— 设计债 / 兜底不全

### 6. `CapabilityRouter` 缺 cost / latency 排序
- **现状:** 仅按 `preferred` 列表 → 原生 feature 数 → catalog 顺序排序
- **影响:** 多候选时选不到最优成本 / 最快响应的组合
- **解法:** 用 `ModelCatalog::pricing()` 计算每 1K tokens 代价；`ProviderRegistry::getCapabilities()` 里增加 `typical_latency_ms` 字段
- **工作量:** ~100 行 + 测试

### 7. `AgentConfig` 未集成 `features` 字段
- **现状:** 设计 §4.4 明确列为 Phase 3 交付物，但没实际接入
- **影响:** 用户只能在每次 `$provider->chat()` 调用时传 `features`，不能从 AgentConfig 统一配
- **解法:** `AgentConfig` 加可选 `features` 属性；`Agent::run()` 注入到每次 chat 的 `$options`
- **工作量:** ~40 行（含 tests/Compat/ 锁定 AgentConfig 默认行为）

### 8. Qwen `code_interpreter` 不是 FeatureAdapter
- **现状:** 通过 `$options['enable_code_interpreter'] = true` 直接生效，和 `thinking` 风格不一致
- **影响:** 跨 provider 语义不统一；非 Qwen provider 无法通过统一 feature channel 请求代码解释器
- **解法:** 新建 `CodeInterpreterAdapter`；非原生 provider 降级为"注册一个本地代码执行 tool 并提示 LLM"
- **工作量:** ~80 行

### 9. `pollUntilDone()` 阻塞整个 PHP 进程
- **现状:** 用 `usleep()` 阻塞；CLI 下 OK
- **影响:** Laravel queue worker 里跑 15 分钟视频生成会卡死 worker
- **解法:**
  - 暴露非阻塞变体 `pollIterator()` 返 Generator
  - 或集成 Laravel queue 的 release/delay 机制
  - 或真走 async（ReactPHP / AMPHP）—— 大改
- **工作量:** Generator 方案 ~30 行；Queue 集成 ~150 行；真 async ~Phase 级别

### 10. Skills bridges（Kimi / MiniMax）只有空钩子
- **现状:** `SkillInjector::registerBridge()` 接口在；`KimiSkillBridge` / `MiniMaxSkillBridge` 实现没写
- **影响:** Skill 在这两家走不了原生路径，和其他 provider 一样的 system prompt 注入
- **阻塞:** 两家 Skills REST 官方未公开（同 Kimi Swarm）
- **解法:** 等 schema，或抓 CLI 流量
- **工作量:** 每家 ~80 行

### 11. `superagent swarm` CLI 只规划不执行
- **现状:** CLI 打 plan 然后 hint "wiring lands in a follow-up phase"
- **影响:** 用户需要自己读 plan 的 strategy/provider 再手写调度
- **解法:** 实现 3 条执行路径：
  - `native_swarm` → 调用 `KimiSwarmTool`
  - `agent_teams` → 用 `AgentTeamsAdapter` 加 MiniMaxProvider
  - `local_swarm` → 走 `src/Swarm/Team` + `ParallelAgentCoordinator`
- **工作量:** ~200 行 + tests

### 12. 英文文档缺失
- **现状:** `docs/ADVANCED_USAGE.md` / `ARCHITECTURE.md` 有三语（en/cn/fr）；我新写的 3 个文档（`NATIVE_PROVIDERS_CN.md` / `FEATURES_MATRIX.md` / `MIGRATION_NATIVE.md`）**只有中文**（`FEATURES_MATRIX.md` 和 `MIGRATION_NATIVE.md` 其实是英文但没中文版）
- **影响:** 一致性破坏；国际用户看不到指南
- **解法:** 
  - `docs/NATIVE_PROVIDERS.md`（英文）+ `docs/NATIVE_PROVIDERS_FR.md`（法文）
  - `docs/FEATURES_MATRIX_CN.md`
  - `docs/MIGRATION_NATIVE_CN.md`
- **工作量:** 翻译 ~3 小时

---

## 🟢 低优先级 —— 洁癖 / DX

### 13. `SkillManager` 构造器里的 `getenv('PHPUNIT_RUNNING')` 检查
- **现状:** 测试关注点泄到生产代码
- **解法:** 构造器加 `bool $autoLoadDisk = true` 参数；`tests/bootstrap.php` 里某个 test base 类继承时传 `false`
- **工作量:** ~20 行

### 14. Provider tool 测试用反射注入 mock client
- **现状:** 每个 tool test 都 20+ 行反射样板（reflect `client` property → set mock）
- **解法:** 提炼 `tests/Helpers/ProviderTestHelper::injectMockClient($provider, $responses, &$history)` 一行调用
- **工作量:** ~30 行 helper，简化 15 个 test 文件

### 15. 现有 209 warnings / 25 skipped 测试未调查
- **现状:** 从 Phase 1 到 Phase 9 我每次都写"与之前一致"
- **影响:** 未知；其中可能有真 bug 或 deprecated API 用法
- **解法:** 单独花半天跑 `phpunit --fail-on-warning` 看哪里冒泡，分类处理
- **工作量:** 0.5 - 1 天（视 warning 数量）

### 16. `OpenRouter` 也是 chat-completions 家族但没接入新基类
- **现状:** `OpenRouterProvider` 是 v0.8.8 前独立实现，理论上该 `extends ChatCompletionsProvider`
- **影响:** 冗余代码 ~200 行；`OpenAI-Organization` / `HTTP-Referer` / `X-Title` 等 OpenRouter 特色 header 需要作为 `extraHeaders()` 处理
- **解法:** 按 `OpenAIProvider` 的重构模板再做一次
- **工作量:** ~50 行改动 + Compat 锁定测试（参照 Phase 2 的 OpenAI 重构）

### 17. MCP OAuth 2.0 授权流程没实现
- **现状:** `McpAuthTool` 只有 56 行骨架
- **影响:** 接需要第三方 OAuth 的 MCP server 会露怯
- **解法:** 实现 device-code flow（类似 GitHub CLI 的 `gh auth login` 交互）；token 落盘到 `~/.superagent/mcp-auth.json`（chmod 0600）
- **工作量:** ~200 行（+ 依赖 OAuth 库或手搓）

### 18. 没有 provider health check
- **现状:** `ProviderRegistry::discover()` 只看 env 变量存不存在
- **影响:** 用户配了个失效 key 到初次调用才知道
- **解法:** 新增 `ProviderRegistry::healthCheck(string $name): HealthStatus` —— 每家一个轻量 ping（`GET /v1/models` 或最小 chat 调用），5s 超时
- **工作量:** ~80 行 + 测试

### 19. Feature spec 是松散 array，容易拼错 key
- **现状:** `'features' => ['thinking' => ['budget' => 4000]]` —— 写成 `'budjet'` 静默忽略
- **解法:**
  - 轻量版：adapter 内部校验未知 key，DEBUG 模式下 warning
  - 完整版：做 `ThinkingSpec::new()->budget(4000)` builder（每 feature 一个 spec 类）
- **工作量:** 轻量 ~30 行；完整 ~200 行

### 20. 没有统一的 feature flag 系统
- **现状:** 所有新能力默认开启
- **影响:** 用户想临时关掉（比如测试环境关 cost 检查）需要去配置 CostLimiter
- **解法:** `~/.superagent/features.json` + `FeatureRegistry::enabled('capability.enabled.thinking')`；env 变量也能覆盖（`SUPERAGENT_DISABLE=thinking,cost_limit`）
- **工作量:** ~120 行基础设施 + 改造各处检查点

---

## 推荐执行顺序

### 两小时内最值得做（MVP 安全网）
1. **#3** CI 基础 workflow（半天）
2. **#4** 核对定价数据（2 小时）

### 本周内做完（消除最大风险）
3. **#2** 集成测试套件（2-3 天）
4. **#1** Kimi Swarm schema 验证（视上游发布时机）
5. **#5** Kimi SupportsThinking 简单实现（10 行）

### 下个小版本（0.8.9）
6. **#7** `AgentConfig.features` 字段（最影响 DX）
7. **#11** `superagent swarm` 真正执行（补齐 Phase 7 尾巴）
8. **#12** 英文文档（~3 小时）
9. **#9** `pollUntilDone` Generator 变体（解决 Laravel queue 场景）

### 有空时做（技术债清理）
10. **#6** Router 成本/延迟排序
11. **#8** CodeInterpreterAdapter
12. **#13-16** 代码洁癖项
13. **#15** Warnings 分类处理
14. **#18** Health check
15. **#19** Feature spec 校验

### 需求驱动时做（不主动）
16. **#10** Skills bridges（等 schema）
17. **#17** MCP OAuth（等用户诉求）
18. **#20** Feature flag 系统（等用户诉求）

---

## 工作量总览

| 优先级 | 总工作量 | 消除风险 |
|---|---|---|
| 🔴 高（#1-5） | ~4-5 天 | 定价失准、schema 未验、CI 空白 |
| 🟡 中（#6-12） | ~5 天 | DX 毛刺、设计债 |
| 🟢 低（#13-20） | ~4 天 | 洁癖 / 扩展性 |
| **合计** | **~2-3 周** | |

不全做也 OK。**最低限度做 #1-5 就能显著降低 0.8.8 真实用户使用的风险。**

---

**文档状态:** 待用户拍板开工顺序。推荐从 **#3 CI + #4 定价** 开始（纯查资料 + 配置文件，零代码风险）。
