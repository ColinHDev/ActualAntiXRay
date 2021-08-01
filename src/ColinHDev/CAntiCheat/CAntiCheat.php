<?php

namespace ColinHDev\CAntiCheat;

use ColinHDev\CAntiCheat\listener\DataPacketSendListener;
use ColinHDev\CAntiCheat\listener\PlayerCreationListener;
use pocketmine\plugin\PluginBase;

class CAntiCheat extends PluginBase {

    public function onEnable() : void {
        $this->getServer()->getPluginManager()->registerEvents(new DataPacketSendListener(), $this);
        $this->getServer()->getPluginManager()->registerEvents(new PlayerCreationListener(), $this);
    }
}