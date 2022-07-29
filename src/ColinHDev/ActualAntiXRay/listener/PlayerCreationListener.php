<?php

namespace ColinHDev\ActualAntiXRay\listener;

use ColinHDev\ActualAntiXRay\player\Player;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerCreationEvent;

class PlayerCreationListener implements Listener {

    public function onPlayerCreation(PlayerCreationEvent $event) : void {
        $event->setPlayerClass(Player::class);
    }
}