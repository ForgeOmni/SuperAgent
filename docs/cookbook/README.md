# SuperAgent cookbook

Focused, runnable examples of SuperAgent's higher-level subsystems. Each
file is one topic, self-contained, copy-pasteable.

| # | File | Topic |
|---|---|---|
| 01 | [`01-debate-protocol.md`](./01-debate-protocol.md) | Proposer / critic / judge structured debate |
| 02 | [`02-redteam-attack.md`](./02-redteam-attack.md) | Builder / attacker / reviewer adversarial pattern |
| 03 | [`03-cost-autopilot.md`](./03-cost-autopilot.md) | Budget-driven model tiering, reactive |
| 04 | [`04-cost-prediction.md`](./04-cost-prediction.md) | KNN-based spend prediction, proactive |
| 05 | [`05-adaptive-feedback.md`](./05-adaptive-feedback.md) | Corrections promote into reusable patterns |

## Conventions

- Each file opens with a one-line goal
- Prerequisites listed under the goal
- Code is copy-pasteable into `php artisan tinker` or a controller
- Closes with `## See also` cross-references
- Linked from the package README under "Examples"

## See also

- SuperAICore cookbook (host-side concerns: dispatch, caching, rotation, tracing)
