# Bundled agent templates

Reference YAML agent specs. Copy any of these to `~/.superagent/agents/`
(user-level) or `<project>/.superagent/agents/` (project-level) and
tweak to taste — `AgentManager::loadFromDirectory()` picks up `.yaml` /
`.yml` automatically when those directories exist.

The `extend:` key is honoured: a child spec in your user dir can extend
one of the bundled base agents without copy-pasting its system prompt
or tool list.

Example — derive a read-only reviewer from the bundled `base-coder`:

```yaml
# ~/.superagent/agents/my-reviewer.yaml
extend: base-coder
name: my-reviewer
description: Reviews code, never writes.
read_only: true
exclude_tools: [Write, Edit, MultiEdit]
```

See `docs/ADVANCED_USAGE.md` §… or `src/Agent/YamlAgentDefinition.php`
for the complete field catalog.
