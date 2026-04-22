# Migration — du mode compat OpenAI vers les fournisseurs natifs Kimi/Qwen/GLM/MiniMax

> Pour les utilisateurs qui appelaient Moonshot / Alibaba / Z.AI /
> MiniMax via `OpenAIProvider` avec une `base_url` personnalisée. La
> v0.8.8 livre de vrais fournisseurs natifs — ce guide donne le diff
> minimal pour basculer et ce qui se débloque.
>
> **Langues :** [English](MIGRATION_NATIVE.md) · [中文](MIGRATION_NATIVE_CN.md) · [Français](MIGRATION_NATIVE_FR.md)

## TL;DR

```diff
- $kimi = ProviderRegistry::create('openai', [
-     'api_key'  => getenv('KIMI_API_KEY'),
-     'base_url' => 'https://api.moonshot.ai',
-     'model'    => 'kimi-k2-6',
- ]);
+ $kimi = ProviderRegistry::create('kimi');
```

C'est toute la migration. Le reste est un bonus.

## Ce que cette ligne apporte

| Bénéfice | Compat OpenAI (avant) | Fournisseur natif (après) |
|---|---|---|
| Bascule de région | Remplacement manuel de `base_url` par région | `createWithRegion('kimi', 'cn')` ou env `KIMI_REGION=cn` |
| Tag d'erreur | Les erreurs affichent `provider: 'openai'` | Les erreurs affichent `provider: 'kimi'` (logs lisibles) |
| Sécurité `CredentialPool` | Fuite possible de clés CN vers endpoints intl | Étiquetage par région ; clés mal appariées filtrées |
| Fonctionnalités natives (`thinking` / `swarm` / `agent_teams` / …) | Inaccessibles | Via `$options['features']` / `SupportsSwarm` / FeatureAdapter |
| Outils spécialisés (GLM Web Search / Kimi File-Extract / MiniMax TTS / …) | Inaccessibles | Importables comme classes `Tool` standard |
| Entrées de catalogue de modèles (prix, max_context, régions) | Absentes | Livrées dans `resources/models.json` |

## Migration par fournisseur

### Kimi (Moonshot)

```diff
- 'api_key'  => getenv('KIMI_API_KEY'),
- 'base_url' => 'https://api.moonshot.ai',
  // 'provider' => 'openai'
+ 'provider' => 'kimi',
+ 'region'   => 'intl',   // ou 'cn'
```

**Variable d'env** : `KIMI_API_KEY` (auparavant `OPENAI_API_KEY` détourné). Alias `MOONSHOT_API_KEY` aussi reconnu.

**Modèle par défaut** : `kimi-k2-6` (auparavant à coder en dur).

**Capacités débloquées** :
- Outils `kimi_file_extract` / `kimi_batch` / `kimi_swarm`
- `SupportsSwarm` — `$kimi->submitSwarm(...)` renvoie un `JobHandle`

### Qwen (DashScope)

```diff
- 'api_key'  => getenv('DASHSCOPE_API_KEY'),
- 'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
  // 'provider' => 'openai'
+ 'provider' => 'qwen',
+ 'region'   => 'intl',   // ou 'us' / 'cn' / 'hk'
```

**Changement de forme du body** — le fournisseur natif utilise
l'endpoint `text-generation/generation` de DashScope (pas
chat-completions). Les appelants qui n'utilisent que `chat()` n'ont rien
à faire ; la traduction est interne. Si vous construisiez des requêtes
à la main contre l'endpoint compat, il faut soit les déplacer dans
`QwenProvider::buildRequestBody()`, soit passer par `$options` avec la
nouvelle forme (`enable_thinking`, `enable_code_interpreter`, etc.).

**Variable d'env** : `QWEN_API_KEY` (l'ancien `DASHSCOPE_API_KEY` marche toujours).

**Capacités débloquées** :
- Quatre régions au lieu d'une
- Passage de `enable_thinking` + `thinking_budget`
- `enable_code_interpreter`
- Outil `qwen_long_file` pour le mode référence de fichier à 10M tokens
- Intégration complète `SupportsThinking` avec `ThinkingAdapter`

### GLM (Z.AI / BigModel)

```diff
- 'api_key'  => getenv('ZAI_API_KEY'),
- 'base_url' => 'https://api.z.ai/api/paas/v4',
  // 'provider' => 'openai'
+ 'provider' => 'glm',
+ 'region'   => 'intl',   // ou 'cn' (BigModel)
```

**Variable d'env** : `GLM_API_KEY` (alias `ZAI_API_KEY` / `ZHIPU_API_KEY`).

**Capacités débloquées** :
- `glm_web_search` / `glm_web_reader` / `glm_ocr` / `glm_asr` — utilisables par tout cerveau principal, pas seulement GLM lui-même
- `$options['thinking'] = true` déclenche `thinking: {type: enabled}`

### MiniMax

```diff
- 'api_key'  => getenv('MINIMAX_API_KEY'),
- 'base_url' => 'https://api.minimax.io',
  // 'provider' => 'openai'
+ 'provider' => 'minimax',
+ 'region'   => 'intl',   // ou 'cn' (api.minimaxi.com)
+ 'group_id' => getenv('MINIMAX_GROUP_ID'),  // optionnel, définit le header X-GroupId
```

**Changement de chemin** : le fournisseur natif frappe `/v1/text/chatcompletion_v2` (auparavant par défaut en mode compat).

**Capacités débloquées** :
- `minimax_tts` / `minimax_music` / `minimax_video` / `minimax_image` comme outils
- La fonctionnalité `agent_teams` active le mode multi-agent natif de M2.7

## CredentialPool — étiquetez vos clés

Si vous mutualisez plusieurs clés par fournisseur, ajoutez une étiquette
`region` pour que le pool ne serve pas une clé CN à un endpoint intl
(ou l'inverse) :

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

Les chaînes sans région restent acceptées et traitées comme
"universelles", donc cette migration est optionnelle — à faire dès
l'ajout d'une clé pour une seconde région.

## Spécification de fonctionnalité — `$options['features']`

Au lieu de chaînes magiques spécifiques à chaque fournisseur, le chemin
natif expose un canal de fonctionnalités uniforme :

```diff
  $provider->chat($messages, $tools, $systemPrompt, [
-     // bricolé par fournisseur
-     'enable_thinking' => true,
-     'thinking_budget' => 4000,
+     'features' => [
+         'thinking' => ['budget' => 4000],
+     ],
  ]);
```

La même map de fonctionnalités fonctionne pour Anthropic (`thinking`),
Qwen (`parameters.enable_thinking`), GLM (`thinking: {type: enabled}`),
Kimi (bascule de variante de modèle) — `FeatureDispatcher` traduit.

## Ce qui reste identique

- **Chaque méthode publique pré-v0.8.8** garde sa signature. `catch (ProviderException)` capture toujours les nouvelles erreurs (le nouveau `FeatureNotSupportedException` l'étend).
- **`resources/models.json` v1** continue à se charger inchangé. Le schéma v2 est purement additif.
- **`OpenAIProvider`** existe toujours, pointe toujours sur `api.openai.com`, honore toujours OAuth / Organization. Si vous utilisiez `openai` pour le vrai OpenAI, rien ne change.
- **`BashSecurityValidator`** intouché. Les 23 vérifications et leur suite de tests sont verrouillées ; le nouveau `ToolSecurityValidator` délègue à lui pour les outils Bash.

## Garanties de compatibilité

La suite `tests/Compat/` du dépôt fige :
- Le mapping champ-à-valeur du `models.json` v1 est exact au byte près.
- L'hôte de base-URL par défaut de chaque fournisseur pré-v0.8.8.
- La forme de `ProviderRegistry::getCapabilities()` pour les six
  fournisseurs livrés.

Tout ce qui casse ces tests est par définition un changement cassant et
passe par un chemin de dépréciation explicite — pas par un bump
silencieux.

## Quand conserver le mode compat

Ne continuez à pointer `openai` sur la `base_url` d'un fournisseur que si :
- Vous ne voulez explicitement pas des fonctionnalités natives (modèle mental plus simple).
- Vous enveloppez un fournisseur sans provider dédié en 0.8.8 (improbable ; tous les grands fournisseurs chinois en ont un maintenant).
- Vous prototypez et n'avez pas décidé quel fournisseur adopter.

Pour tout ce qui tend vers la production — passez aux fournisseurs
natifs. La sécurité de région et l'attribution d'erreur justifient à
elles seules ce changement d'une ligne.

---

**Version :** v0.8.8 · **Mise à jour :** 2026-04-21
