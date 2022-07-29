<?php

namespace ColinHDev\ActualAntiXRay\listener;

use ColinHDev\ActualAntiXRay\ResourceManager;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\world\World;
use function assert;
use function count;

class DataPacketSendListener implements Listener {

    public function onDataPacketSend(DataPacketSendEvent $event) : void {
        $applyableTargets = [];
        foreach ($event->getTargets() as $target) {
            $world = $target->getPlayer()?->getWorld();
            if (!($world instanceof World)) {
                continue;
            }
            if (!ResourceManager::getInstance()->isEnabledForWorld($world->getFolderName())) {
                continue;
            }
            $applyableTargets[] = $target;
        }
        if (count($applyableTargets) === 0) {
            return;
        }
        $packets = $event->getPackets();
        foreach ($packets as $packet) {
            if (!$packet instanceof UpdateBlockPacket) {
                continue;
            }
            $blockPosition = new Vector3($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
            foreach ($blockPosition->sides() as $vector) {
                $vectors = array_merge([$vector], $vector->sidesArray());
                foreach ($applyableTargets as $target) {
                    $world = $target->getPlayer()?->getWorld();
                    assert($world instanceof World);
                    foreach ($world->createBlockUpdatePackets($vectors) as $updateBlockPacket) {
                        $target->addToSendBuffer($updateBlockPacket);
                    }
                }
            }
        }
    }
}