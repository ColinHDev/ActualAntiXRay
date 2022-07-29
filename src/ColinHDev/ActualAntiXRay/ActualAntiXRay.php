<?php

namespace ColinHDev\ActualAntiXRay;

use ColinHDev\ActualAntiXRay\listener\DataPacketSendListener;
use ColinHDev\ActualAntiXRay\listener\PlayerCreationListener;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;

class ActualAntiXRay extends PluginBase {
    use SingletonTrait;

    public function onEnable() : void {
        self::setInstance($this);
        ResourceManager::getInstance();
        $this->getServer()->getPluginManager()->registerEvents(new DataPacketSendListener(), $this);
        $this->getServer()->getPluginManager()->registerEvents(new PlayerCreationListener(), $this);
    }
}