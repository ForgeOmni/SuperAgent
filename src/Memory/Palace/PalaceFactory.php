<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use Psr\Log\LoggerInterface;
use SuperAgent\Memory\Palace\Diary\AgentDiary;
use SuperAgent\Memory\Palace\Layers\LayerManager;

/**
 * Build the palace and all its collaborators from config.
 *
 * Config shape (config/superagent.php):
 *
 *   'palace' => [
 *       'enabled'         => env('PALACE_ENABLED', false),
 *       'base_path'       => null,        // default: {memory_path}/palace
 *       'default_wing'    => null,        // e.g. 'wing_myproject'
 *       'vector' => [
 *           'enabled' => env('PALACE_VECTOR_ENABLED', false),
 *           // Callable resolved from the container as a service alias, or
 *           // pass a closure directly when building the factory.
 *           'embed_fn' => null,
 *       ],
 *       'dedup' => [
 *           'enabled'   => true,
 *           'threshold' => 0.85,
 *       ],
 *       'scoring' => [
 *           'keyword' => 1.0,
 *           'vector'  => 2.0,
 *           'recency' => 0.5,
 *           'access'  => 0.3,
 *       ],
 *   ],
 *
 * The factory returns a bundle of (storage, graph, retriever, layers,
 * detector, diary, dedup, provider) so callers can pick what they need.
 */
class PalaceFactory
{
    /**
     * @param array{
     *   enabled?: bool,
     *   base_path?: ?string,
     *   default_wing?: ?string,
     *   vector?: array{enabled?: bool, embed_fn?: ?callable},
     *   dedup?: array{enabled?: bool, threshold?: float},
     *   scoring?: array{keyword?: float, vector?: float, recency?: float, access?: float}
     * } $config
     */
    public static function make(string $memoryBasePath, array $config = [], ?LoggerInterface $logger = null): PalaceBundle
    {
        $palacePath = $config['base_path'] ?? ($memoryBasePath . '/palace');

        $storage = new PalaceStorage($palacePath);
        $graph = new PalaceGraph($storage);

        $vectorCfg = $config['vector'] ?? [];
        $embedFn = null;
        if (($vectorCfg['enabled'] ?? false) && is_callable($vectorCfg['embed_fn'] ?? null)) {
            $embedFn = $vectorCfg['embed_fn'];
        }

        $scoring = $config['scoring'] ?? [];
        $retriever = new PalaceRetriever(
            storage: $storage,
            graph: $graph,
            embedFn: $embedFn,
            keywordWeight: (float) ($scoring['keyword'] ?? 1.0),
            vectorWeight: (float) ($scoring['vector'] ?? 2.0),
            recencyWeight: (float) ($scoring['recency'] ?? 0.5),
            accessWeight: (float) ($scoring['access'] ?? 0.3),
        );

        $layers = new LayerManager($storage, $retriever);
        $detector = new WingDetector($storage, $config['default_wing'] ?? 'wing_general');

        $dedupCfg = $config['dedup'] ?? [];
        $dedup = ($dedupCfg['enabled'] ?? true)
            ? new MemoryDeduplicator($storage, threshold: (float) ($dedupCfg['threshold'] ?? 0.85))
            : null;

        $diary = new AgentDiary($storage);

        $provider = new PalaceMemoryProvider(
            storage: $storage,
            graph: $graph,
            retriever: $retriever,
            layers: $layers,
            detector: $detector,
            dedup: $dedup,
            defaultWingSlug: $config['default_wing'] ?? null,
            logger: $logger,
        );

        return new PalaceBundle($storage, $graph, $retriever, $layers, $detector, $diary, $dedup, $provider);
    }
}

/**
 * Container-of-services returned from PalaceFactory::make().
 */
class PalaceBundle
{
    public function __construct(
        public readonly PalaceStorage $storage,
        public readonly PalaceGraph $graph,
        public readonly PalaceRetriever $retriever,
        public readonly LayerManager $layers,
        public readonly WingDetector $detector,
        public readonly AgentDiary $diary,
        public readonly ?MemoryDeduplicator $dedup,
        public readonly PalaceMemoryProvider $provider,
    ) {}
}
