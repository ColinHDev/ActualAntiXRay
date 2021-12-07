<?php

namespace ColinHDev\AntiXRay\utils;

use pocketmine\world\format\Chunk;
use pocketmine\world\utils\SubChunkExplorer as PMMPSubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;

class SubChunkExplorer extends PMMPSubChunkExplorer {

    /**
     * @return int
     * @phpstan-return SubChunkExplorerStatus::*
     */
    public function moveToChunk(int $chunkX, int $chunkY, int $chunkZ) : int {
        if ($this->currentChunk === null || $this->currentX !== $chunkX || $this->currentZ !== $chunkZ) {
            $this->currentX = $chunkX;
            $this->currentZ = $chunkZ;
            $this->currentSubChunk = null;

            $this->currentChunk = $this->world->getChunk($this->currentX, $this->currentZ);
            if ($this->currentChunk === null) {
                return SubChunkExplorerStatus::INVALID;
            }
        }

        if ($this->currentSubChunk === null || $this->currentY !== $chunkY) {
            $this->currentY = $chunkY;

            if ($this->currentY < Chunk::MIN_SUBCHUNK_INDEX || $this->currentY > Chunk::MAX_SUBCHUNK_INDEX) {
                $this->currentSubChunk = null;
                return SubChunkExplorerStatus::INVALID;
            }

            $this->currentSubChunk = $this->currentChunk->getSubChunk($chunkY);
            return SubChunkExplorerStatus::MOVED;
        }

        return SubChunkExplorerStatus::OK;
    }
}