<?php

namespace ColinHDev\AntiXRay;

use ColinHDev\AntiXRay\listener\DataPacketSendListener;
use ColinHDev\AntiXRay\listener\PlayerCreationListener;
use pocketmine\plugin\PluginBase;

class AntiXRay extends PluginBase {

    private static AntiXRay $instance;

    public static function getInstance() : AntiXRay {
        return self::$instance;
    }

    public function onEnable() : void {
        self::$instance = $this;
        ResourceManager::getInstance();
        $this->getServer()->getPluginManager()->registerEvents(new DataPacketSendListener(), $this);
        $this->getServer()->getPluginManager()->registerEvents(new PlayerCreationListener(), $this);
    }
}