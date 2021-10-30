<?php

namespace ColinHDev\CAntiCheat\tasks;

use ColinHDev\CAntiCheat\utils\SubChunkExplorer;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorerStatus;
use pocketmine\world\World;

class ChunkRequestTask extends AsyncTask {

    private const TLS_KEY_PROMISE = "promise";
    private const TLS_KEY_ERROR_HOOK = "errorHook";

    /** @var int[] */
    private static array $blocksToReplace = [];
    /** @var int[] */
    private static array $blocksToReplaceWith = [];
    private static int $blocksToReplaceWithCount;
    private static int $blockChangeChance = 75;
    private static array $blockSides = [
        Facing::UP,
        Facing::DOWN,
        Facing::NORTH,
        Facing::SOUTH,
        Facing::WEST,
        Facing::EAST
    ];

    private int $worldMinY;
    private int $worldMaxY;

    private int $chunkX;
    private int $chunkZ;
    private int $subChunkCount;

    private string $chunks;
    private string $tiles;

    private Compressor $compressor;

    /**
     * ChunkRequestTask constructor.
     * @param World $world
     * @param int $chunkX
     * @param int $chunkZ
     * @param Chunk $chunk
     * @param CompressBatchPromise $promise
     * @param Compressor $compressor
     * @param \Closure|null $onError
     */
    public function __construct(World $world, int $chunkX, int $chunkZ, Chunk $chunk, CompressBatchPromise $promise, Compressor $compressor, ?\Closure $onError = null) {
        if (empty(self::$blocksToReplace)) {
            self::$blocksToReplace = [
                VanillaBlocks::STONE()->getFullId(),
                VanillaBlocks::DIRT()->getFullId(),
                VanillaBlocks::GRAVEL()->getFullId()
            ];
        }
        if (empty(self::$blocksToReplaceWith)) {
            self::$blocksToReplaceWith = [
                VanillaBlocks::COAL_ORE()->getFullId(),
                VanillaBlocks::IRON_ORE()->getFullId(),
                VanillaBlocks::LAPIS_LAZULI_ORE()->getFullId(),
                VanillaBlocks::REDSTONE_ORE()->getFullId(),
                VanillaBlocks::GOLD_ORE()->getFullId(),
                VanillaBlocks::DIAMOND_ORE()->getFullId(),
                VanillaBlocks::EMERALD_ORE()->getFullId()
            ];
            self::$blocksToReplaceWithCount = count(self::$blocksToReplaceWith);
        }

        $this->worldMinY = $world->getMinY();
        $this->worldMaxY = $world->getMaxY();

        $this->chunkX = $chunkX;
        $this->chunkZ = $chunkZ;
        $this->subChunkCount = count($chunk->getSubChunks());

        $serializedChunks = [];

        $serializedChunks[World::chunkHash($chunkX, $chunkZ)] = FastChunkSerializer::serializeTerrain($chunk);
        $this->tiles = ChunkSerializer::serializeTiles($chunk);

        $chunkAround = $world->getChunk($chunkX + 1, $chunkZ);
        if ($chunkAround !== null) {
            $serializedChunks[World::chunkHash($chunkX + 1, $chunkZ)] = FastChunkSerializer::serializeTerrain($chunkAround);
        }
        $chunkAround = $world->getChunk($chunkX - 1, $chunkZ);
        if ($chunkAround !== null) {
            $serializedChunks[World::chunkHash($chunkX - 1, $chunkZ)] = FastChunkSerializer::serializeTerrain($chunkAround);
        }

        $chunkAround = $world->getChunk($chunkX, $chunkZ + 1);
        if ($chunkAround !== null) {
            $serializedChunks[World::chunkHash($chunkX, $chunkZ + 1)] = FastChunkSerializer::serializeTerrain($chunkAround);
        }
        $chunkAround = $world->getChunk($chunkX, $chunkZ - 1);
        if ($chunkAround !== null) {
            $serializedChunks[World::chunkHash($chunkX, $chunkZ - 1)] = FastChunkSerializer::serializeTerrain($chunkAround);
        }

        $this->chunks = serialize($serializedChunks);

        $this->compressor = $compressor;

        $this->storeLocal(self::TLS_KEY_PROMISE, $promise);
        $this->storeLocal(self::TLS_KEY_ERROR_HOOK, $onError);
    }

    public function onRun() : void {
        $manager = new SimpleChunkManager($this->worldMinY, $this->worldMaxY);
        foreach (unserialize($this->chunks, ["allowed_classes" => false]) as $hash => $serializedChunk) {
            World::getXZ($hash, $chunkX, $chunkZ);
            $manager->setChunk($chunkX, $chunkZ, FastChunkSerializer::deserializeTerrain($serializedChunk));
        }

        $explorer = new SubChunkExplorer($manager);
        for ($s = 0; $s < $this->subChunkCount; $s++) {
            for ($x = 0; $x < 16; $x++) {
                for ($z = 0; $z < 16; $z++) {
                    for ($y = 0; $y < 16; $y++) {

                        if ($s === 0 && $y === 0) continue;
                        if ($s + 1 === $this->subChunkCount && $y === 15) continue;

                        $vector = new Vector3($x, $y, $z);
                        if (!$this->isBlockReplaceable($explorer, $vector, $s)) continue;
                        if (random_int(1, 100) > self::$blockChangeChance) continue;

                        foreach (self::$blockSides as $side) {
                            $blockSide = $vector->getSide($side);
                            if (!$this->isBlockReplaceable($explorer, $blockSide, $s)) continue 2;
                        }

                        $randomBlockId = self::$blocksToReplaceWith[random_int(0, self::$blocksToReplaceWithCount - 1)];
                        $explorer->currentSubChunk->setFullBlock($x, $y, $z, $randomBlockId);
                    }
                }
            }
        }

        /** @var Chunk $chunk */
        $chunk = $manager->getChunk($this->chunkX, $this->chunkZ);
        $subCount = ChunkSerializer::getSubChunkCount($chunk);
        $encoderContext = new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary());
        $payload = ChunkSerializer::serializeFullChunk($chunk, RuntimeBlockMapping::getInstance(), $encoderContext, $this->tiles);
        $this->setResult($this->compressor->compress(PacketBatch::fromPackets($encoderContext, LevelChunkPacket::withoutCache($this->chunkX, $this->chunkZ, $subCount, $payload))->getBuffer()));
    }

    /**
     * @param SubChunkExplorer  $explorer
     * @param Vector3           $vector
     * @param int               $chunkY
     * @return bool
     */
    private function isBlockReplaceable(SubChunkExplorer $explorer, Vector3 $vector, int $chunkY) : bool {
        $chunkX = $this->chunkX;
        $x = $vector->getX();
        if ($x < 0) {
            $x = 15;
            $chunkX--;
        } else if ($x > 15) {
            $x = 0;
            $chunkX++;
        }

        $chunkZ = $this->chunkZ;
        $z = $vector->getZ();
        if ($z < 0) {
            $z = 15;
            $chunkZ--;
        } else if ($z > 15) {
            $z = 0;
            $chunkZ++;
        }

        $y = $vector->getY();
        if ($y < 0) {
            $y = 15;
            $chunkY--;
        } else if ($y > 15) {
            $y = 0;
            $chunkY++;
        }

        static $explorerMovedStatus = [SubChunkExplorerStatus::OK, SubChunkExplorerStatus::MOVED];
        if ($explorer->getCurrentX() !== $chunkX || $explorer->getCurrentY() !== $chunkY || $explorer->getCurrentZ() !== $chunkZ) {
            if (!in_array($explorer->moveToChunk($chunkX, $chunkY, $chunkZ), $explorerMovedStatus, true)) {
                return false;
            }
        }
        return in_array($explorer->currentSubChunk->getFullBlock($x, $y, $z), self::$blocksToReplace, true);
    }

    public function onError() : void{
        /**
         * @var \Closure|null $hook
         * @phpstan-var (\Closure() : void)|null $hook
         */
        $hook = $this->fetchLocal(self::TLS_KEY_ERROR_HOOK);
        if($hook !== null){
            $hook();
        }
    }

    public function onCompletion() : void {
        /** @var CompressBatchPromise $promise */
        $promise = $this->fetchLocal(self::TLS_KEY_PROMISE);
        $promise->resolve($this->getResult());
    }
}