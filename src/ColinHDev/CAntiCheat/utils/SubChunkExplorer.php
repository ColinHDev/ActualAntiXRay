<?php

namespace ColinHDev\CAntiCheat\utils;

use pocketmine\world\utils\SubChunkExplorer as PMMP_SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;

class SubChunkExplorer extends PMMP_SubChunkExplorer {

    /**
     * @return int | null
     */
    public function getCurrentX() : ?int {
        return $this->currentX;
    }

    /**
     * @return int | null
     */
    public function getCurrentY() : ?int {
        return $this->currentY;
    }

    /**
     * @return int | null
     */
    public function getCurrentZ() : ?int {
        return $this->currentZ;
    }

    /**
     * @param int $chunkX
     * @param int $chunkY
     * @param int $chunkZ
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

            if ($this->currentY < 0 || $this->currentY >= $this->currentChunk->getHeight()) {
                $this->currentSubChunk = null;
                return SubChunkExplorerStatus::INVALID;
            }

            $this->currentSubChunk = $this->currentChunk->getSubChunk($chunkY);
            return SubChunkExplorerStatus::MOVED;
        }

        return SubChunkExplorerStatus::OK;
    }
}