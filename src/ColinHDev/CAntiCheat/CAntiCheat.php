<?php

namespace ColinHDev\CAntiCheat;

use ColinHDev\CAntiCheat\listener\DataPacketSendListener;
use ColinHDev\CAntiCheat\listener\PlayerCreationListener;
use pocketmine\plugin\PluginBase;

class CAntiCheat extends PluginBase {

    private static CAntiCheat $instance;

    /**
     * @return CAntiCheat
     */
    public static function getInstance() : CAntiCheat {
        return self::$instance;
    }

    public function onLoad() : void {
        self::$instance = $this;

        new ResourceManager();
    }

    public function onEnable() : void {
        $this->getServer()->getPluginManager()->registerEvents(new DataPacketSendListener(), $this);
        $this->getServer()->getPluginManager()->registerEvents(new PlayerCreationListener(), $this);
    }
}