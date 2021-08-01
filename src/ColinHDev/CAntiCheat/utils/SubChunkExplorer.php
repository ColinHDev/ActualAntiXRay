<?php

namespace ColinHDev\CAntiCheat\utils;

use pocketmine\world\utils\SubChunkExplorer as PMMP_SubChunkExplorer;

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
}