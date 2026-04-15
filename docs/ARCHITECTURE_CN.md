# SuperAgent 架构 — 依赖关系图

> **版本:** 0.8.6 | **生成日期:** 2026-04-14

> 主 mermaid 依赖图见 [英文版 ARCHITECTURE.md](ARCHITECTURE.md#core-system-dependencies)。中文版保留核心依赖图，仅对新增子系统（CLI / Auth / Foundation / Memory Palace / Coordinator / Middleware）的说明作概述更新。英文版包含完整的 CLI + OAuth 依赖关系。

> **语言**: [English](ARCHITECTURE.md) | [中文](ARCHITECTURE_CN.md) | [Français](ARCHITECTURE_FR.md)

## 核心系统依赖

```mermaid
%%{init: {'flowchart': {'rankSpacing': 60, 'nodeSpacing': 30}}}%%
graph TB
    subgraph Entry["入口点"]
        Agent["Agent.php"]
        HarnessLoop["HarnessLoop"]
        Bridge["Bridge HTTP 代理"]
    end

    subgraph Core["核心引擎"]
        QE["QueryEngine<br/>(930 行)"]
        SPB["SystemPromptBuilder"]
        MR["ModelResolver"]
    end

    subgraph Providers["LLM 提供者"]
        PR["ProviderRegistry"]
        CP["CredentialPool"]
        AP["AnthropicProvider"]
        OP["OpenAIProvider"]
        ORP["OpenRouterProvider"]
        BP["BedrockProvider"]
        OLP["OllamaProvider"]
        FP["FallbackProvider"]
        RM["RetryMiddleware"]
    end

    subgraph Tools["工具系统"]
        TL["ToolLoader"]
        BTR["BuiltinToolRegistry<br/>(65 个工具)"]
        TNR["ToolNameResolver"]
        TSM["ToolStateManager"]
        TSF["ToolSchemaFilter"]
    end

    subgraph Optimization["优化 (v0.7.0+v0.8.0)"]
        TRC["ToolResultCompactor"]
        CC["ContextCompressor<br/>(v0.8.0)"]
        MRt["ModelRouter"]
        QCR["QueryComplexityRouter<br/>(v0.8.0)"]
        RP["ResponsePrefill"]
        PCP["PromptCachePinning"]
    end

    subgraph Performance["性能"]
        PTE["ParallelToolExecutor<br/>(路径感知 v0.8.0)"]
        STD["StreamingToolDispatch"]
        SP["SpeculativePrefetch"]
        AMT["AdaptiveMaxTokens"]
        CPool["ConnectionPool"]
    end

    subgraph Security["安全与护栏"]
        BSV["BashSecurityValidator<br/>(23 项检查)"]
        SCC["SecurityCheckChain<br/>(v0.8.0)"]
        GE["GuardrailsEngine"]
        PID["PromptInjectionDetector<br/>(v0.8.0)"]
        PE["PermissionEngine"]
        PRE["PathRuleEvaluator"]
    end

    subgraph Memory["记忆系统 (v0.8.0)"]
        MPI["MemoryProviderInterface"]
        MPM["MemoryProviderManager"]
        BMP["BuiltinMemoryProvider"]
        MS["MemoryStorage"]
        ME["MemoryExtractor"]
        MRv["MemoryRetriever"]
        ADC["AutoDreamConsolidator"]
        DL["DailyLog (KAIROS)"]
        SME["SessionMemoryExtractor"]
    end

    subgraph Session["会话 (v0.8.0)"]
        SM["SessionManager"]
        SS["SessionStorage (文件)"]
        SPS["SessionPruner"]
        SQS["SqliteSessionStorage<br/>(WAL+FTS5 v0.8.0)"]
    end

    subgraph Swarm["多智能体编排"]
        PAC["ParallelAgentCoordinator"]
        AT["AgentTool"]
        PB["ProcessBackend"]
        IPB["InProcessBackend"]
        TB["TmuxBackend"]
        ITB["ITermBackend"]
        DB["DistributedBackend"]
        ADM["AgentDependencyManager"]
        ACP["AgentCommunicationProtocol"]
        APP["AgentPerformanceProfiler"]
        TC["TeamContext"]
    end

    subgraph Intelligence["智能"]
        SCM["SmartContextManager"]
        KG["KnowledgeGraph"]
        AF["AdaptiveFeedback"]
        CA["CostAutopilot"]
        CPred["CostPredictor"]
        SD["DistillationEngine"]
    end

    subgraph Pipeline["流水线与工作流"]
        PEng["PipelineEngine"]
        SH["SelfHealingStrategy"]
        DO["DebateOrchestrator"]
        FM["ForkManager"]
        RR["ReplayRecorder"]
    end

    subgraph Infra["基础设施"]
        MCP["MCPManager"]
        PM["PluginManager"]
        SKM["SkillManager"]
        SKC["SkillCatalogTool<br/>(v0.8.0)"]
        HR["HookRegistry"]
        HRL["HookReloader"]
        PH["PromptHook"]
        CM["ChannelManager"]
        AS["AppStateStore"]
        SSW["SafeStreamWriter<br/>(v0.8.0)"]
    end

    %% 核心流程
    Agent --> QE
    HarnessLoop --> QE
    Agent --> MR
    Agent --> PR
    QE --> SPB
    QE --> TL
    QE --> PTE

    %% 提供者链
    PR --> CP
    PR --> AP
    PR --> OP
    PR --> ORP
    PR --> BP
    PR --> OLP
    PR --> FP
    CP -.->|密钥轮转| AP
    CP -.->|密钥轮转| OP
    RM -.->|包裹| AP
    RM -.->|包裹| OP

    %% 工具
    TL --> BTR
    TL --> TNR
    BTR --> TSM
    QE --> TSF

    %% 优化
    QE --> TRC
    QE --> CC
    QE --> MRt
    QE --> QCR
    QE --> RP
    SPB --> PCP

    %% 安全
    SPB --> PID
    QE --> GE
    QE --> PE
    PE --> PRE
    GE --> BSV
    BSV -.-> SCC

    %% 记忆
    MPM --> BMP
    MPM --> MPI
    BMP --> MS
    BMP --> MRv
    BMP --> ME
    ADC --> MS
    ADC --> DL
    SPB --> MPM

    %% 会话
    SM --> SS
    SM --> SPS
    SM --> SQS

    %% 多智能体
    AT --> PAC
    AT --> PB
    AT --> IPB
    PAC --> TC
    PAC --> ADM
    PAC --> ACP
    PAC --> APP
    PB --> DB
    PB --> TB
    PB --> ITB

    %% 智能
    QE --> SCM
    QE --> KG
    QE --> CA
    CA --> CPred
    QE --> AF

    %% 流水线
    PEng --> SH
    PEng --> QE

    %% 基础设施
    SPB -.->|扫描上下文文件| PID
    QE --> MCP
    QE --> HR
    HR --> HRL
    HR --> PH
    PM --> SKM
    SKM --> SKC

    classDef new fill:#e1f5fe,stroke:#0288d1,stroke-width:2px
    classDef core fill:#fff3e0,stroke:#f57c00,stroke-width:2px
    classDef security fill:#fce4ec,stroke:#c62828,stroke-width:2px

    class CC,QCR,PID,SCC,CP,MPM,MPI,BMP,SQS,SKC,SSW,ADM new
    class QE,Agent,HarnessLoop core
    class BSV,GE,PID,PE,PRE security
```

## 子系统统计（v0.8.6）

| 分类 | 文件数 | 行数 | 自 v0.8.0 的变化 |
|------|--------|------|-------------------|
| 核心（Agent、QueryEngine、Prompt） | 12 | ~2,600 | — |
| **CLI + Console + Auth（新）** | **17** | **~2,687** | **新增（v0.8.5 + v0.8.6）** |
| **Foundation（新）** | **2** | **~550** | **新增（v0.8.5 + v0.8.6）** |
| 提供者（支持 OAuth） | 12 | ~3,800 | +~100（OAuth 路径） |
| 工具 | 74 | ~11,300 | — |
| 优化 | 8 | ~2,100 | — |
| 性能 | 8 | ~2,100 | — |
| 安全与护栏 | 33 | ~3,200 | — |
| 记忆（含 **Palace**） | 42 | ~5,400 | **+2,289（Palace v0.8.5）** |
| 会话 | 4 | ~1,600 | — |
| 多智能体编排 | 34 | ~7,300 | — |
| **Coordinator（新）** | **14** | **~2,800** | **新增（v0.8.2）** |
| Harness | 21 | ~1,800 | — |
| **Middleware（新）** | **7** | **~900** | **新增（v0.8.1）** |
| 智能 | 20 | ~3,500 | — |
| 流水线 | 24 | ~3,764 | — |
| 基础设施 | 40 | ~5,000 | — |
| **总计** | **566** | **~93,395** | **+70 文件 / +12,159 行** |

## 数据流

```mermaid
sequenceDiagram
    participant 用户 as 用户
    participant Agent as Agent
    participant QE as QueryEngine
    participant SPB as SystemPromptBuilder
    participant PID as PromptInjectionDetector
    participant Provider as LLM 提供者
    participant CP as CredentialPool
    participant Tools as 工具系统
    participant PTE as ParallelToolExecutor

    用户->>Agent: prompt(消息)
    Agent->>QE: runLoop()
    QE->>SPB: 构建系统提示
    SPB->>PID: 扫描上下文文件
    PID-->>SPB: 威胁 / 安全
    QE->>CP: getKey(provider)
    CP-->>QE: 轮转后的 API 密钥
    QE->>Provider: chat(消息, 工具)
    Provider-->>QE: 助手响应
    QE->>PTE: classify(工具块)
    PTE-->>QE: 并行 / 串行 分组
    QE->>Tools: 执行（并行）
    Tools-->>QE: 工具结果
    QE->>Provider: 继续处理结果
    Provider-->>QE: 最终响应
    QE-->>Agent: AgentResult
    Agent-->>用户: 响应
```

## 关键设计决策

1. **双部署架构（v0.8.6）**：Laravel 包 + 独立 CLI 二进制共享同一套 `Agent` / `HarnessLoop` / `CommandRouter` / `MemoryProviderManager` / `SessionManager`。差异只在边界（polyfill `config()`/`app()`/`storage_path()` + `Foundation\Application` 最小容器，镜像 Laravel 的 bind/singleton/make API）
2. **OAuth 凭证导入（v0.8.6）**：`src/Auth/` 读取本地 Claude Code / Codex 已有的 token，注入到 provider 的 Bearer 模式。自动续期；provider 自动注入 Claude Code 身份 system block；legacy 模型 id 静默改写
3. **Memory Palace 作为外部 provider（v0.8.5）**：通过 `MemoryProviderManager` 作为第二个 provider 接入，与内置 `MEMORY.md` 流程并存。Wings/Halls/Rooms/Drawers + Tunnels + 4 层栈（L0 身份 / L1 关键事实 / L2 房间召回 / L3 深度搜索）。同一 Room 出现在多个 Wing 时自动建立 Tunnel
4. **协作管道（v0.8.2）**：分阶段多智能体 DAG，拓扑排序，4 种失败策略，8 事件生命周期监听。阶段内智能体通过 `ParallelPhaseExecutor` + `ProcessBackend` / `InProcessBackend` 真并行执行
5. **中间件洋葱（v0.8.1）**：`MiddlewarePipeline` 按优先级把 rate-limit + retry + cost-tracking + logging + guardrail 中间件组合在每次 provider 调用外围
6. **双写会话（v0.8.0）**：文件（向后兼容）+ SQLite（搜索）。SQLite 不可用时优雅降级
7. **路径感知并行（v0.8.0）**：写工具按目标路径分类，而非仅按只读标志
8. **记忆提供者隔离**：外部提供者错误永远不会导致 Agent 崩溃
9. **凭证轮转（v0.8.0）+ OAuth Bearer（v0.8.6）**：均在 `ProviderRegistry` 层集成——对所有消费者透明
10. **Prompt 注入扫描**：集成到 `SystemPromptBuilder` — 在 `withContextFiles()` 时自动扫描上下文文件
11. **渐进式技能加载**：两阶段（元数据 → 完整内容）最小化 token 开销
12. **`SecurityCheckChain`**：包裹现有 23 项检查验证器，同时支持自定义检查插入
