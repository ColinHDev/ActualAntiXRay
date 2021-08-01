<?php

namespace ColinHDev\CAntiCheat\tasks;

use pocketmine\block\VanillaBlocks;
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
use pocketmine\world\utils\SubChunkExplorer;
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

    private int $worldMinY;
    private int $worldMaxY;
    private int $chunkX;
    private int $chunkZ;
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

        $serializedChunks = [];

        $serializedChunks[World::chunkHash($chunkX, $chunkZ)] = FastChunkSerializer::serializeWithoutLight($chunk);
        $this->tiles = ChunkSerializer::serializeTiles($chunk);

        $chunkAround = $world->loadChunk($chunkX + 1, $chunkZ);
        if ($chunkAround !== null) {
            $serializedChunks[World::chunkHash($chunkX + 1, $chunkZ)] = FastChunkSerializer::serializeWithoutLight($chunkAround);
        }
        $chunkAround = $world->loadChunk($chunkX - 1, $chunkZ);
        if ($chunkAround !== null) {
            $serializedChunks[World::chunkHash($chunkX - 1, $chunkZ)] = FastChunkSerializer::serializeWithoutLight($chunkAround);
        }

        $chunkAround = $world->loadChunk($chunkX, $chunkZ + 1);
        if ($chunkAround !== null) {
            $serializedChunks[World::chunkHash($chunkX, $chunkZ + 1)] = FastChunkSerializer::serializeWithoutLight($chunkAround);
        }
        $chunkAround = $world->loadChunk($chunkX, $chunkZ - 1);
        if ($chunkAround !== null) {
            $serializedChunks[World::chunkHash($chunkX, $chunkZ - 1)] = FastChunkSerializer::serializeWithoutLight($chunkAround);
        }

        $this->chunks = serialize($serializedChunks);

        $this->compressor = $compressor;

        $this->storeLocal(self::TLS_KEY_PROMISE, $promise);
        $this->storeLocal(self::TLS_KEY_ERROR_HOOK, $onError);
    }

    public function onRun() : void {
        $manager = new SimpleChunkManager($this->worldMinY, $this->worldMaxY);
        foreach (unserialize($this->chunks) as $hash => $serializedChunk) {
            World::getXZ($hash, $chunkX, $chunkZ);
            $manager->setChunk($chunkX, $chunkZ, FastChunkSerializer::deserialize($serializedChunk));
        }

        $explorer = new SubChunkExplorer($manager);
        $explorerMovedStatus = [SubChunkExplorerStatus::OK, SubChunkExplorerStatus::MOVED];
        for ($x = $this->chunkX * 16; $x < $this->chunkX * 16 + 16; $x++) {
            for ($z = $this->chunkZ * 16; $z < $this->chunkZ * 16 + 16; $z++) {
                for ($y = $this->worldMinY; $y < $this->worldMaxY; $y++) {

                    if (in_array($explorer->moveTo($x, $y, $z), $explorerMovedStatus, true)) {
                        if ($explorer->currentSubChunk->isEmptyFast()) continue;
                        $blockId = $explorer->currentSubChunk->getFullBlock($x & 0x0f, $y & 0x0f, $z & 0x0f);
                        if (!in_array($blockId, self::$blocksToReplace, true)) continue;
                    }

                    if (in_array($explorer->moveTo($x + 1, $y, $z), $explorerMovedStatus, true)) {
                        if ($explorer->currentSubChunk->isEmptyFast()) continue;
                        $blockId = $explorer->currentSubChunk->getFullBlock(($x + 1) & 0x0f, $y & 0x0f, $z & 0x0f);
                        if (!in_array($blockId, self::$blocksToReplace, true)) continue;
                    }

                    if (in_array($explorer->moveTo($x - 1, $y, $z), $explorerMovedStatus, true)) {
                        if ($explorer->currentSubChunk->isEmptyFast()) continue;
                        $blockId = $explorer->currentSubChunk->getFullBlock(($x - 1) & 0x0f, $y & 0x0f, $z & 0x0f);
                        if (!in_array($blockId, self::$blocksToReplace, true)) continue;
                    }

                    if (in_array($explorer->moveTo($x, $y, $z + 1), $explorerMovedStatus, true)) {
                        if ($explorer->currentSubChunk->isEmptyFast()) continue;
                        $blockId = $explorer->currentSubChunk->getFullBlock($x & 0x0f, $y & 0x0f, ($z + 1) & 0x0f);
                        if (!in_array($blockId, self::$blocksToReplace, true)) continue;
                    }

                    if (in_array($explorer->moveTo($x, $y, $z - 1), $explorerMovedStatus, true)) {
                        if ($explorer->currentSubChunk->isEmptyFast()) continue;
                        $blockId = $explorer->currentSubChunk->getFullBlock($x & 0x0f, $y & 0x0f, ($z - 1) & 0x0f);
                        if (!in_array($blockId, self::$blocksToReplace, true)) continue;
                    }

                    if ($y < ($this->worldMaxY - 1)) {
                        if (in_array($explorer->moveTo($x, $y + 1, $z), $explorerMovedStatus, true)) {
                            if ($explorer->currentSubChunk->isEmptyFast()) continue;
                            $blockId = $explorer->currentSubChunk->getFullBlock($x & 0x0f, ($y + 1) & 0x0f, $z & 0x0f);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;
                        }
                    }

                    if ($y > $this->worldMinY) {
                        if (in_array($explorer->moveTo($x, $y - 1, $z), $explorerMovedStatus, true)) {
                            if ($explorer->currentSubChunk->isEmptyFast()) continue;
                            $blockId = $explorer->currentSubChunk->getFullBlock($x & 0x0f, ($y - 1) & 0x0f, $z & 0x0f);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;
                        }
                    }

                    if (in_array($explorer->moveTo($x, $y, $z), $explorerMovedStatus, true)) {
                        if (random_int(1, 100) > self::$blockChangeChance) continue;
                        if (!isset(self::$blocksToReplaceWith[random_int(0, self::$blocksToReplaceWithCount - 1)])) continue;
                        $explorer->currentSubChunk->setFullBlock($x & 0x0f, $y & 0x0f, $z & 0x0f, self::$blocksToReplaceWith[random_int(0, self::$blocksToReplaceWithCount - 1)]);
                    }
                }
            }
        }


        /*for ($s = 0; $s < $this->subChunkCount; $s++) {
            if (!in_array($explorer->moveToChunk($this->chunkX, $s, $this->chunkZ), $explorerMovedStatus, true)) continue;
            if ($explorer->currentSubChunk->isEmptyFast()) continue;
            for ($x = 0; $x < 16; $x++) {
                for ($z = 0; $z < 16; $z++) {
                    for ($y = 0; $y < 16; $y++) {

                        if ($s === 0 && $y === 0) continue;
                        if ($s + 1 === $this->subChunkCount && $y === 15) continue;

                        $cblockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x, $y, $z);
                        if (!in_array($cblockId, self::$blocksToReplace, true)) {
                            $y++;
                            continue;
                        }

                        if ($x === 0) {
                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x + 1, $y, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX - 1, $s, $this->chunkZ, 15, $y, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                        } else if ($x === 15) {
                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX + 1, $s, $this->chunkZ, 0, $y, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x - 1, $y, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                        } else {
                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x + 1, $y, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x - 1, $y, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                        }

                        if ($z === 0) {
                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x, $y, $z + 1);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ - 1, $x, $y, 15);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                        } else if ($z === 15) {
                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ + 1, $x, $y, 0);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x, $y, $z - 1);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                        } else {
                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x, $y, $z + 1);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x, $y, $z - 1);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                        }

                        if ($y === 0) {
                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x, $y + 1, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true)) {
                                $y++;
                                continue;
                            }

                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s - 1, $this->chunkZ, $x, 15, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                        } else if ($y === 15) {
                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s + 1, $this->chunkZ, $x, 0, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true)) continue;

                        } else {
                            $blockId = $this->getFullBlockAtSubChunk($explorer, $this->chunkX, $s, $this->chunkZ, $x, $y + 1, $z);
                            if (!in_array($blockId, self::$blocksToReplace, true))  {
                                $y++;
                                continue;
                            }
                        }

                        if (random_int(1, 100) > self::$blockChangeChance) continue;
                        if (!isset(self::$blocksToReplaceWith[random_int(0, self::$blocksToReplaceWithCount - 1)])) continue;
                        $explorer->currentSubChunk->setFullBlock($x, $y, $z, self::$blocksToReplaceWith[random_int(0, self::$blocksToReplaceWithCount - 1)]);
                    }
                }
            }
        }*/

        /** @var Chunk $chunk */
        $chunk = $manager->getChunk($this->chunkX, $this->chunkZ);
        $subCount = ChunkSerializer::getSubChunkCount($chunk);
        $encoderContext = new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary());
        $payload = ChunkSerializer::serializeFullChunk(
            $chunk,
            RuntimeBlockMapping::getInstance(),
            $encoderContext,
            $this->tiles
        );
        $this->setResult(
            $this->compressor->compress(
                PacketBatch::fromPackets(
                    $encoderContext,
                    LevelChunkPacket::withoutCache($this->chunkX, $this->chunkZ, $subCount, $payload)
                )->getBuffer()
            )
        );
    }

    /**
     * @param SubChunkExplorer  $explorer
     * @param int               $chunkX
     * @param int               $chunkY
     * @param int               $chunkZ
     * @param int               $x
     * @param int               $y
     * @param int               $z
     * @return int
     *
    private function getFullBlockAtSubChunk(SubChunkExplorer $explorer, int $chunkX, int $chunkY, int $chunkZ, int $x, int $y, int $z) : int {
        if ($explorer->getCurrentX() !== $chunkX || $explorer->getCurrentY() !== $chunkY || $explorer->getCurrentZ() !== $chunkZ) {
            if (!in_array($explorer->moveToChunk($chunkX, $chunkY, $chunkZ), [SubChunkExplorerStatus::OK, SubChunkExplorerStatus::MOVED], true)) {
                return 0;
            }
        }
        return $explorer->currentSubChunk->getFullBlock($x, $y, $z);
    }*/

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