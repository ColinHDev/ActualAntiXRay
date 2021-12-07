<?php

namespace ColinHDev\AntiXRay\player;

use ColinHDev\AntiXRay\ResourceManager;
use ColinHDev\AntiXRay\tasks\ChunkRequestTask;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\player\Player as PMMP_PLAYER;
use pocketmine\player\UsedChunkStatus;
use pocketmine\timings\Timings;
use pocketmine\utils\Utils;
use pocketmine\world\World;
use ReflectionProperty;

class Player extends PMMP_PLAYER {

    /**
     * Requests chunks from the world to be sent, up to a set limit every tick. This operates on the results of the most recent chunk
     * order.
     */
    protected function requestChunks() : void{
        if(!$this->isConnected()){
            return;
        }

        $standard = ResourceManager::getInstance()->getAntiXRayStandard();
        $worlds = ResourceManager::getInstance()->getWorlds();
        if (
            ($standard && in_array($this->getWorld()->getFolderName(), $worlds, true))
            ||
            (!$standard && !in_array($this->getWorld()->getFolderName(), $worlds, true))
        ) {
            parent::requestChunks();
            return;
        }

        Timings::$playerChunkSend->startTiming();

        $count = 0;
        $world = $this->getWorld();
        $property = new ReflectionProperty(PMMP_PLAYER::class, "activeChunkGenerationRequests");
        $property->setAccessible(true);
        $activeChunkGenerationRequests = $property->getValue($this);
        $limit = $this->chunksPerTick - count($activeChunkGenerationRequests);
        foreach($this->loadQueue as $index => $distance){
            if($count >= $limit){
                break;
            }

            $X = null;
            $Z = null;
            World::getXZ($index, $X, $Z);
            assert(is_int($X) and is_int($Z));

            ++$count;

            $this->usedChunks[$index] = UsedChunkStatus::REQUESTED_GENERATION();
            $activeChunkGenerationRequests[$index] = true;
            $property->setValue($this, $activeChunkGenerationRequests);
            unset($this->loadQueue[$index]);
            $this->getWorld()->registerChunkLoader($this->chunkLoader, $X, $Z, true);
            $this->getWorld()->registerChunkListener($this, $X, $Z);

            $this->getWorld()->requestChunkPopulation($X, $Z, $this->chunkLoader)->onCompletion(
                function() use ($X, $Z, $index, $world) : void{
                    if(!$this->isConnected() || !isset($this->usedChunks[$index]) || $world !== $this->getWorld()){
                        return;
                    }
                    if(!$this->usedChunks[$index]->equals(UsedChunkStatus::REQUESTED_GENERATION())){
                        //We may have previously requested this, decided we didn't want it, and then decided we did want
                        //it again, all before the generation request got executed. In that case, the promise would have
                        //multiple callbacks for this player. In that case, only the first one matters.
                        return;
                    }
                    $property = new ReflectionProperty(PMMP_PLAYER::class, "activeChunkGenerationRequests");
                    $property->setAccessible(true);
                    $activeChunkGenerationRequests = $property->getValue($this);
                    unset($activeChunkGenerationRequests[$index]);
                    $property->setValue($this, $activeChunkGenerationRequests);
                    $this->usedChunks[$index] = UsedChunkStatus::REQUESTED_SENDING();

                    $this->startUsingChunk($X, $Z, function() use ($X, $Z, $index) : void{
                        $this->usedChunks[$index] = UsedChunkStatus::SENT();
                        if($this->spawnChunkLoadCount === -1){
                            $this->spawnEntitiesOnChunk($X, $Z);
                        }elseif($this->spawnChunkLoadCount++ === $this->spawnThreshold){
                            $this->spawnChunkLoadCount = -1;

                            $this->spawnEntitiesOnAllChunks();

                            $this->getNetworkSession()->notifyTerrainReady();
                        }
                    });
                },
                static function() : void{
                    //NOOP: we'll re-request this if it fails anyway
                }
            );
        }

        Timings::$playerChunkSend->stopTiming();
    }

    /**
     * Instructs the networksession to start using the chunk at the given coordinates. This may occur asynchronously.
     * @param \Closure $onCompletion To be called when chunk sending has completed.
     * @phpstan-param \Closure() : void $onCompletion
     */
    public function startUsingChunk(int $chunkX, int $chunkZ, \Closure $onCompletion) : void{
        Utils::validateCallableSignature(function() : void{}, $onCompletion);

        $world = $this->getLocation()->getWorld();
        $this->request(ChunkCache::getInstance($world, $this->getNetworkSession()->getCompressor()), $chunkX, $chunkZ)->onResolve(

        //this callback may be called synchronously or asynchronously, depending on whether the promise is resolved yet
            function(CompressBatchPromise $promise) use ($world, $onCompletion, $chunkX, $chunkZ) : void{
                if(!$this->isConnected()){
                    return;
                }
                $currentWorld = $this->getLocation()->getWorld();
                if($world !== $currentWorld or ($status = $this->getUsedChunkStatus($chunkX, $chunkZ)) === null){
                    $this->logger->debug("Tried to send no-longer-active chunk $chunkX $chunkZ in world " . $world->getFolderName());
                    return;
                }
                if(!$status->equals(UsedChunkStatus::REQUESTED_SENDING())){
                    //TODO: make this an error
                    //this could be triggered due to the shitty way that chunk resends are handled
                    //right now - not because of the spammy re-requesting, but because the chunk status reverts
                    //to NEEDED if they want to be resent.
                    return;
                }
                $world->timings->syncChunkSend->startTiming();
                try{
                    $this->getNetworkSession()->queueCompressed($promise);
                    $onCompletion();
                }finally{
                    $world->timings->syncChunkSend->stopTiming();
                }
            }
        );
    }

    /**
     * Requests asynchronous preparation of the chunk at the given coordinates.
     *
     * @return CompressBatchPromise a promise of resolution which will contain a compressed chunk packet.
     */
    public function request(ChunkCache $chunkCache, int $chunkX, int $chunkZ) : CompressBatchPromise{
        $property = new ReflectionProperty(ChunkCache::class, "world");
        $property->setAccessible(true);
        $world = $property->getValue($chunkCache);

        $world->registerChunkListener($chunkCache, $chunkX, $chunkZ);
        $chunk = $world->getChunk($chunkX, $chunkZ);
        if ($chunk === null) {
            throw new \InvalidArgumentException("Cannot request an unloaded chunk");
        }
        $chunkHash = World::chunkHash($chunkX, $chunkZ);

        $cacheProperty = new ReflectionProperty(ChunkCache::class, "caches");
        $cacheProperty->setAccessible(true);
        $caches = $cacheProperty->getValue($chunkCache);

        if(isset($caches[$chunkHash])){

            $property = new ReflectionProperty(ChunkCache::class, "hits");
            $property->setAccessible(true);
            $property->setValue($chunkCache, $property->getValue($chunkCache) + 1);

            return $caches[$chunkHash];
        }

        $property = new ReflectionProperty(ChunkCache::class, "misses");
        $property->setAccessible(true);
        $property->setValue($chunkCache, $property->getValue($chunkCache) + 1);

        $world->timings->syncChunkSendPrepare->startTiming();
        try{
            $caches[$chunkHash] = new CompressBatchPromise();
            $cacheProperty->setValue($chunkCache, $caches);

            $property = new ReflectionProperty(ChunkCache::class, "compressor");
            $property->setAccessible(true);
            $compressor = $property->getValue($chunkCache);

            $world->getServer()->getAsyncPool()->submitTask(
                new ChunkRequestTask(
                    $world,
                    $chunkX,
                    $chunkZ,
                    $chunk,
                    $caches[$chunkHash],
                    $compressor,
                    function() use ($world, $chunkCache, $chunkX, $chunkZ) : void{
                        $world->getLogger()->error("Failed preparing chunk $chunkX $chunkZ, retrying");

                        $this->restartPendingRequest($chunkCache, $chunkX, $chunkZ);
                    }
                )
            );

            return $caches[$chunkHash];
        }finally{
            $world->timings->syncChunkSendPrepare->stopTiming();
        }
    }

    /**
     * Restarts an async request for an unresolved chunk.
     *
     * @throws \InvalidArgumentException
     */
    private function restartPendingRequest(ChunkCache $chunkCache, int $chunkX, int $chunkZ) : void{
        $chunkHash = World::chunkHash($chunkX, $chunkZ);

        $property = new ReflectionProperty(ChunkCache::class, "caches");
        $property->setAccessible(true);
        $caches = $property->getValue($chunkCache);

        $existing = $caches[$chunkHash] ?? null;
        if($existing === null or $existing->hasResult()){
            throw new \InvalidArgumentException("Restart can only be applied to unresolved promises");
        }
        $existing->cancel();
        unset($caches[$chunkHash]);
        $property->setValue($chunkCache, $caches);

        $this->request($chunkCache, $chunkX, $chunkZ)->onResolve(...$existing->getResolveCallbacks());
    }
}