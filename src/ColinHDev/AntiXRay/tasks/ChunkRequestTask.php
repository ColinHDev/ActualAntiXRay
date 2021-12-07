<?php

namespace ColinHDev\AntiXRay\tasks;

use ColinHDev\AntiXRay\utils\SubChunkExplorer;
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
use pocketmine\utils\AssumptionFailedError;
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
    private static int $blockChangeChance = 75;

    private int $worldMinY;
    private int $worldMaxY;

    private int $chunkX;
    private int $chunkZ;
    private int $subChunkCount;

    private string $chunks;
    private string $tiles;

    private Compressor $compressor;

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
        }

        $this->worldMinY = $world->getMinY();
        $this->worldMaxY = $world->getMaxY();

        $this->chunkX = $chunkX;
        $this->chunkZ = $chunkZ;
        $this->subChunkCount = ChunkSerializer::getSubChunkCount($chunk);

        $this->chunks = igbinary_serialize(
            array_map(
                fn (?Chunk $c) => $c !== null ? FastChunkSerializer::serializeTerrain($c) : null,
                array_merge(
                    [World::chunkHash($chunkX, $chunkZ) => $chunk],
                    $world->getAdjacentChunks($chunkX, $chunkZ)
                )
            )) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
        $this->tiles = ChunkSerializer::serializeTiles($chunk);

        $this->compressor = $compressor;

        $this->storeLocal(self::TLS_KEY_PROMISE, $promise);
        $this->storeLocal(self::TLS_KEY_ERROR_HOOK, $onError);
    }

    public function onRun() : void {
        $manager = new SimpleChunkManager($this->worldMinY, $this->worldMaxY);
        $chunks = array_map(
            fn (?string $serialized) => $serialized !== null ? FastChunkSerializer::deserializeTerrain($serialized) : null,
            igbinary_unserialize($this->chunks)
        );
        foreach ($chunks as $chunkHash => $chunk) {
            World::getXZ($chunkHash, $chunkX, $chunkZ);
            $manager->setChunk($chunkX, $chunkZ, $chunk);
        }

        $explorer = new SubChunkExplorer($manager);
        for ($s = 0; $s < $this->subChunkCount; $s++) {
            for ($x = 0; $x < 16; $x++) {
                for ($z = 0; $z < 16; $z++) {
                    for ($y = 0; $y < 16; $y++) {

                        if ($s === 0 && $y === 0) continue;
                        if ($s + 1 === $this->subChunkCount && $y === 15) continue;

                        $vector = new Vector3($x, $y, $z);
                        if (!$this->isBlockReplaceable($explorer, $vector, $s)) {
                            // If the current block is not replaceable, we can increment the y coordinate by one,
                            // as we can skip the following loop which would check that block again as block below.
                            $y++;
                            continue;
                        }

                        // We could use the random_int() function instead but since mt_rand() is faster than random_int(),
                        // we use that as it is not important if our the returned values are cryptographically secure.
                        if (mt_rand(1, 100) > self::$blockChangeChance) {
                            continue;
                        }

                        foreach (Facing::ALL as $facing) {
                            $blockSide = $vector->getSide($facing);
                            if (!$this->isBlockReplaceable($explorer, $blockSide, $s)) {
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

                        $randomBlockId = self::$blocksToReplaceWith[array_rand(self::$blocksToReplaceWith)];
                        $explorer->currentSubChunk->setFullBlock($x, $y, $z, $randomBlockId);
                    }
                }
            }
        }

        $encoderContext = new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary());
        $payload = ChunkSerializer::serializeFullChunk($manager->getChunk($this->chunkX, $this->chunkZ), RuntimeBlockMapping::getInstance(), $encoderContext, $this->tiles);
        $this->setResult($this->compressor->compress(PacketBatch::fromPackets($encoderContext, LevelChunkPacket::create($this->chunkX, $this->chunkZ, $this->subChunkCount, null, $payload))->getBuffer()));
    }

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

        $moved = $explorer->moveToChunk($chunkX, $chunkY, $chunkZ);
        if ($moved === SubChunkExplorerStatus::OK || $moved === SubChunkExplorerStatus::MOVED) {
            return in_array($explorer->currentSubChunk->getFullBlock($x, $y, $z), self::$blocksToReplace, true);
        }
        return false;
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

    public function onCompletion() : void{
        /** @var CompressBatchPromise $promise */
        $promise = $this->fetchLocal(self::TLS_KEY_PROMISE);
        $promise->resolve($this->getResult());
    }
}