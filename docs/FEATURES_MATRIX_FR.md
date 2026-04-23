# Matrice des fonctionnalités — SuperAgent v0.8.8

> Où se situe chaque fournisseur enregistré pour chaque capacité. À
> utiliser comme aide-mémoire pour choisir un fournisseur, ou comme
> référence quand le code requiert une fonctionnalité native précise.
>
> **Langues :** [English](FEATURES_MATRIX.md) · [中文](FEATURES_MATRIX_CN.md) · [Français](FEATURES_MATRIX_FR.md)

**Légende**

- ✅ natif — le fournisseur implémente directement la capacité (meilleure qualité, latence la plus basse)
- ⚠️ repli — SuperAgent approxime via injection de prompt système ou émulation locale (fonctionne, qualité possiblement réduite)
- ➖ non pris en charge
- 🧩 outil — exposé comme `Tool` SuperAgent autonome, appelable par tout cerveau principal

## Chat de base

| Fournisseur | Streaming | Tool calling | Vision | Bascule région | Contexte max |
|---|:---:|:---:|:---:|:---:|:---:|
| anthropic | ✅ | ✅ | ✅ | ➖ | 200K |
| openai | ✅ | ✅ | ✅ | ➖ | 128K |
| openrouter | ✅ | ✅ | ✅ | ➖ | selon modèle |
| bedrock | ✅ | selon modèle | selon modèle | ➖ | selon modèle |
| ollama | ✅ | ➖ | selon modèle | ➖ | selon modèle |
| gemini | ✅ | ✅ | ✅ | ➖ | 1,05M |
| **kimi** | ✅ | ✅ | ✅ | intl / cn | 256K |
| **qwen** | ✅ | ✅ | ✅ | intl / us / cn / hk | 260K |
| **glm** | ✅ | ✅ | ✅ | intl / cn | 200K |
| **minimax** | ✅ | ✅ | ✅ | intl / cn | 204K |

## Capacités via canal feature

> Activées via `$options['features']['<name>']` et routées par
> `FeatureDispatcher` vers l'implémentation native ou un adaptateur de
> repli. Les fournisseurs en gras implémentent nativement.

| Fonctionnalité | Fournisseurs natifs | Repli |
|---|:---|:---|
| `thinking` | anthropic, qwen, glm, kimi | Injection CoT dans le prompt système |
| `agent_teams` | minimax | Structure injectée dans le prompt système |
| `code_interpreter` | qwen-native | Prompt guidant vers un outil sandbox local (le `qwen` par défaut passe par l'endpoint OpenAI-compat qui n'expose pas ce champ) |
| `context_cache` | anthropic | Ignoré silencieusement |
| `file_extract` | kimi (outil) | Wrapper outil ; pas de repli automatique |
| `long_context_file` | qwen (outil) | Wrapper outil ; pas de repli automatique |
| `web_search` | glm (outil) | Serveur MCP web-search |
| `prompt_cache_key` | kimi | Skip silencieux (cache de session : optimisation de perf, pas de correctness) |

**required vs preferred** : `required: true` sans natif ni repli → exception `FeatureNotSupportedException`. Défaut `required: false` → dégradation gracieuse.

## Specialty-as-Tool (appelable par tout cerveau)

> Chaque outil déclare `attributes()` (`network` / `cost` / `sensitive`), honoré par `ToolSecurityValidator`.

| Outil | Fournisseur | Attributs | Async ? |
|---|---|---|:---:|
| `glm_web_search` | glm | network, cost | sync |
| `glm_web_reader` | glm | network, cost | sync |
| `glm_ocr` | glm | network, cost | sync |
| `glm_asr` | glm | network, cost | sync |
| `kimi_file_extract` | kimi | network, cost, sensitive | sync |
| `kimi_batch` | kimi | network, cost, sensitive | async (sync-wait) |
| `kimi_swarm` | kimi | network, cost | async (sync-wait) |
| `qwen_long_file` | qwen | network, cost, sensitive | sync |
| `minimax_tts` | minimax | network, cost | sync |
| `minimax_music` | minimax | network, cost | async (sync-wait) |
| `minimax_video` | minimax | network, cost | async (sync-wait) |
| `minimax_image` | minimax | network, cost | sync |

## MCP et Skills (sans distinction entre fournisseurs)

| Surface | Fonctionne avec tous ? | Notes |
|---|:---:|---|
| Outils MCP | ✅ | `MCPTool` implémente le contrat `Tool` ; toutes les `formatTools()` le gèrent identiquement (verrouillé par `CrossProviderToolFormatTest`). |
| Skills | ✅ | `SkillInjector` fusionne dans `$options['system_prompt']`. Ponts prêts pour Kimi/MiniMax lorsque les specs natives seront publiées. |
| Contrôle de sécurité | ✅ | `ToolSecurityValidator` délègue Bash à `BashSecurityValidator`, applique network / cost / sensitive au reste. |

## Ce qui n'est délibérément pas dans v0.8.8

- **Kimi Claw Groups** — montage d'agents externes dans le swarm Kimi. Aperçu recherche ; REST + modèle de permission non publics.
- **REST natif d'upload Skills Kimi / MiniMax** — les ponts (`SkillInjector::registerBridge`) sont en place, insertion sans modification d'appelant quand les specs sortiront.
- **Flux OAuth 2.0 MCP** — squelette `McpAuthTool` existe ; flux complet device-code / authorization-code laissé pour quand un serveur MCP initié par l'utilisateur l'exigera.
- **Exécution `superagent swarm`** — la planification est livrée ; dispatch réel vers les trois stratégies dans la prochaine mineure une fois les trois cibles stabilisées.
- **Clonage de voix / design vocal (MiniMax)** — enveloppable avec le même pattern que `MiniMaxTtsTool` ; différé jusqu'à une demande utilisateur concrète.

## Version

Cette matrice suit **SuperAgent v0.8.8**. Gardez-la à jour — si vous
ajoutez une capacité ou qu'un fournisseur gagne un support natif, mettez
à jour la ligne correspondante dans le même PR.
