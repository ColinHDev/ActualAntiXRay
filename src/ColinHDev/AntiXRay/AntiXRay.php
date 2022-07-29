<?php

namespace ColinHDev\AntiXRay;

use ColinHDev\AntiXRay\listener\DataPacketSendListener;
use ColinHDev\AntiXRay\listener\PlayerCreationListener;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;

class AntiXRay extends PluginBase {
    use SingletonTrait;

    public function onEnable() : void {
        self::setInstance($this);
        ResourceManager::getInstance();
        $this->getServer()->getPluginManager()->registerEvents(new DataPacketSendListener(), $this);
        $this->getServer()->getPluginManager()->registerEvents(new PlayerCreationListener(), $this);
    }
}