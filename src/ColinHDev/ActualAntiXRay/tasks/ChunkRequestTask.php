<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay\tasks;

use ColinHDev\ActualAntiXRay\utils\SubChunkExplorer;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\ChunkRequestTask as PMMPChunkRequestTask;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\types\ChunkPosition;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\BinaryStream;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function assert;
use function is_array;
use function is_int;

class ChunkRequestTask extends PMMPChunkRequestTask {

    /** @var int[] */
    private static array $replaceableBlocks = [];
    /** @var int[] */
    private static array $replacingBlocks = [];

    private int $worldMinY;
    private int $worldMaxY;

    private string $adjacentChunks;
    private string $tiles;

    public function __construct(World $world, int $chunkX, int $chunkZ, Chunk $chunk, CompressBatchPromise $promise, Compressor $compressor, ?\Closure $onError = null) {
        parent::__construct($chunkX, $chunkZ, $chunk, $promise, $compressor, $onError);
        if (empty(self::$replaceableBlocks)) {
            self::$replaceableBlocks = [
                VanillaBlocks::STONE()->getStateId(),
                VanillaBlocks::DIRT()->getStateId(),
                VanillaBlocks::GRAVEL()->getStateId()
            ];
        }
        if (empty(self::$replacingBlocks)) {
            self::$replacingBlocks = [
                VanillaBlocks::COAL_ORE()->getStateId(),
                VanillaBlocks::IRON_ORE()->getStateId(),
                VanillaBlocks::LAPIS_LAZULI_ORE()->getStateId(),
                VanillaBlocks::REDSTONE_ORE()->getStateId(),
                VanillaBlocks::GOLD_ORE()->getStateId(),
                VanillaBlocks::DIAMOND_ORE()->getStateId(),
                VanillaBlocks::EMERALD_ORE()->getStateId()
            ];
        }

        $this->worldMinY = $world->getMinY();
        $this->worldMaxY = $world->getMaxY();

        $this->tiles = ChunkSerializer::serializeTiles($chunk);

        $adjacentChunks = [];
        for ($x = -1; $x <= 1; $x++) {
            for ($z = -1; $z <= 1; $z++) {
                if ($x === 0 || $z === 0) {
                    if ($x === 0 && $z === 0) {
                        continue;
                    }
                    $cx = $chunkX + $x;
                    $cz = $chunkZ + $z;
                    $temporaryChunkLoader = new class implements ChunkLoader{};
                    $world->registerChunkLoader($temporaryChunkLoader, $cx, $cz);
                    $adjacentChunks[World::chunkHash($x, $z)] = $world->loadChunk($cx, $cz);
                    $world->unregisterChunkLoader($temporaryChunkLoader, $cx, $cz);
                }
            }
        }
        $this->adjacentChunks = igbinary_serialize(
            array_map(
                static fn(?Chunk $c) => $c !== null ? FastChunkSerializer::serializeTerrain($c) : null,
                $adjacentChunks
            )) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
    }

    public function onRun() : void {
        $adjacentChunks = igbinary_unserialize($this->adjacentChunks);
        assert(is_array($adjacentChunks));
        $chunks = array_map(
            static fn (?string $serialized) => $serialized !== null ? FastChunkSerializer::deserializeTerrain($serialized) : null,
            array_merge(
                [World::chunkHash(0, 0) => $this->chunk],
                $adjacentChunks
            )
        );
        $manager = new SimpleChunkManager($this->worldMinY, $this->worldMaxY);
        foreach($chunks as $relativeChunkHash => $chunk) {
            if (!($chunk instanceof Chunk)) {
                continue;
            }
            World::getXZ($relativeChunkHash, $relativeChunkX, $relativeChunkZ);
            $manager->setChunk($this->chunkX + $relativeChunkX, $this->chunkZ + $relativeChunkZ, $chunk);
        }

        $explorer = new SubChunkExplorer($manager);
        for ($subChunkY = Chunk::MIN_SUBCHUNK_INDEX; $subChunkY < Chunk::MAX_SUBCHUNK_INDEX; $subChunkY++) {
            $explorer->moveToChunk($this->chunkX, $subChunkY, $this->chunkZ);
            if ($explorer->currentSubChunk instanceof SubChunk && $explorer->currentSubChunk->isEmptyFast()) {
                continue;
            }

            for ($x = 0; $x < 16; $x++) {
                for ($z = 0; $z < 16; $z++) {
                    for ($y = 0; $y < 16; $y++) {

                        if ($subChunkY === Chunk::MIN_SUBCHUNK_INDEX && $y === 0) continue;
                        if ($subChunkY + 1 === Chunk::MAX_SUBCHUNK_INDEX && $y === 15) continue;

                        $vector = new Vector3($x, $y, $z);
                        if (!$this->isBlockReplaceable($explorer, $vector, $subChunkY)) {
                            // If the current block is not replaceable, we can increment the y coordinate by one,
                            // as we can skip the following loop which would check that block again as block below.
                            $y++;
                            continue;
                        }

                        // We could use the random_int() function instead but since mt_rand() is faster than random_int(),
                        // we use that as it is not important if our the returned values are cryptographically secure.
                        if (mt_rand(1, 100) > 75) {
                            continue;
                        }

                        foreach (Facing::ALL as $facing) {
                            $blockSide = $vector->getSide($facing);
                            if (!$this->isBlockReplaceable($explorer, $blockSide, $subChunkY)) {
                                if ($facing === Facing::UP) {
                                    // If the block above is not replaceable, we can increment the y coordinate by two,
                                    // as we can skip the following two loops which would check that block again.
                                    // First, as the "main" block, then as the block below.
                                    $y += 2;
                                    continue 2;
                                }
                                continue 2;
                            }
                        }

                        $randomBlockId = self::$replacingBlocks[array_rand(self::$replacingBlocks)];
                        assert($explorer->currentSubChunk instanceof SubChunk);
                        $explorer->currentSubChunk->setBlockStateId($x, $y, $z, $randomBlockId);
                    }
                }
            }
        }

        $chunk = $manager->getChunk($this->chunkX, $this->chunkZ);
        assert($chunk instanceof Chunk);
        $subCount = ChunkSerializer::getSubChunkCount($chunk);
        $encoderContext = new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary());
        $payload = ChunkSerializer::serializeFullChunk($chunk, RuntimeBlockMapping::getInstance(), $encoderContext, $this->tiles);

        $stream = new BinaryStream();
        PacketBatch::encodePackets($stream, $encoderContext, [LevelChunkPacket::create(new ChunkPosition($this->chunkX, $this->chunkZ), $subCount, false, null, $payload)]);
        $this->setResult($this->compressor->deserialize()->compress($stream->getBuffer()));
    }

    private function isBlockReplaceable(SubChunkExplorer $explorer, Vector3 $vector, int $subChunkY) : bool {
        $chunkX = $this->chunkX;
        $x = $vector->getX();
        assert(is_int($x));
        if ($x < 0) {
            $x = 15;
            $chunkX--;
        } else if ($x > 15) {
            $x = 0;
            $chunkX++;
        }

        $chunkZ = $this->chunkZ;
        $z = $vector->getZ();
        assert(is_int($z));
        if ($z < 0) {
            $z = 15;
            $chunkZ--;
        } else if ($z > 15) {
            $z = 0;
            $chunkZ++;
        }

        $y = $vector->getY();
        assert(is_int($y));
        if ($y < 0) {
            $y = 15;
            $subChunkY--;
        } else if ($y > 15) {
            $y = 0;
            $subChunkY++;
        }

        $explorer->moveToChunk($chunkX, $subChunkY, $chunkZ);
        if ($explorer->currentSubChunk instanceof SubChunk) {
            return in_array($explorer->currentSubChunk->getBlockStateId($x, $y, $z), self::$replaceableBlocks, true);
        }
        return false;
    }
}