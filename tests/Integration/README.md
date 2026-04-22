# Integration tests

These tests hit **real vendor endpoints**. They are deliberately gated:

- **Default:** skipped (Unit / Smoke / Compat suites don't touch them).
- **Activation:** `SUPERAGENT_INTEGRATION=1 vendor/bin/phpunit --testsuite Integration`
- **Per-provider:** each test self-skips when its `*_API_KEY` env var is
  absent, so you can run only the providers you have keys for.

## Why gated

- Real calls cost money.
- Vendor rate limits can flake CI.
- Schemas drift — these tests catch drift, but a flaky network shouldn't
  fail an unrelated PR.

## Running a subset

```bash
# Just Kimi
SUPERAGENT_INTEGRATION=1 \
KIMI_API_KEY=sk-... \
vendor/bin/phpunit tests/Integration/KimiIntegrationTest.php

# Everything the machine has keys for
SUPERAGENT_INTEGRATION=1 \
ANTHROPIC_API_KEY=...  OPENAI_API_KEY=...  GEMINI_API_KEY=... \
KIMI_API_KEY=...       QWEN_API_KEY=...    GLM_API_KEY=...   MINIMAX_API_KEY=... \
vendor/bin/phpunit --testsuite Integration
```

## What each test asserts

- **Provider reachable:** the base URL resolves, TLS handshake completes,
  auth works.
- **Wire shape stable:** the response has the fields SuperAgent reads
  (`choices[0].delta.content`, `usage.prompt_tokens`, etc.).
- **Streaming protocol intact:** SSE frames parse, `[DONE]` terminator
  recognised.

Each test is minimal — one short chat call. They're canaries, not
functional coverage. Deep behavioural tests stay in `tests/Unit/` with
`MockHandler`.

## Adding a new vendor

1. Create `tests/Integration/<Name>IntegrationTest.php`.
2. Extend `IntegrationTestCase`.
3. Call `$this->requireEnv('<NAME>_API_KEY')` at the top of each test.
4. Make one minimal chat call with a cheap model.
5. Assert the response shape — don't assert semantic output (LLMs vary).
