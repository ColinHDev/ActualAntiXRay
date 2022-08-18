<?php

namespace ColinHDev\ActualAntiXRay\listener;

use ColinHDev\ActualAntiXRay\ResourceManager;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\world\World;
use function array_filter;
use function array_key_exists;
use function array_merge;
use function count;

class DataPacketSendListener implements Listener {

    public function onDataPacketSend(DataPacketSendEvent $event) : void {
        $applyableTargets = [];
        $applyableWorlds = [];
        foreach ($event->getTargets() as $target) {
            $world = $target->getPlayer()?->getWorld();
            if (!($world instanceof World)) {
                continue;
            }
            if (!ResourceManager::getInstance()->isEnabledForWorld($world->getFolderName())) {
                continue;
            }
            $applyableTargets[] = $target;
            $applyableWorlds[$world->getFolderName()] = $world;
        }
        if (count($applyableTargets) === 0) {
            return;
        }
        $blockMapping = RuntimeBlockMapping::getInstance();
        /** @var array<string, array<int, Vector3|null>> $positionsToUpdatePerWorld */
        $positionsToUpdatePerWorld = [];
        $packets = $event->getPackets();
        foreach ($packets as $packet) {
            if (!$packet instanceof UpdateBlockPacket) {
                continue;
            }
            $x = $packet->blockPosition->getX();
            $y = $packet->blockPosition->getY();
            $z = $packet->blockPosition->getZ();
            foreach($applyableWorlds as $world) {
                // If the sent block does not match the existing block at that position, then someone uses this packet
                // to send fake blocks to the client, for example through the InvMenu virion.
                // Since we don't want to undermine his efforts, we will ignore this and don't send any block updates.
                if ($blockMapping->toRuntimeId($world->getBlockAt($x, $y, $z)->getFullId()) !== $packet->blockRuntimeId) {
                    continue;
                }
                $worldName = $world->getFolderName();
                if (!isset($positionsToUpdatePerWorld[$worldName])) {
                    $positionsToUpdatePerWorld[$worldName] = [];
                }
                $positionsToUpdatePerWorld[$worldName][World::blockHash($x, $y, $z)] = null;
                foreach((new Vector3($x, $y, $z))->sides() as $point) {
                    foreach(array_merge([$point], $point->sidesArray()) as $point2) {
                        $hash = World::blockHash($point2->getFloorX(), $point2->getFloorY(), $point2->getFloorZ());
                        if (!array_key_exists($hash, $positionsToUpdatePerWorld[$worldName])) {
                            $positionsToUpdatePerWorld[$worldName][$hash] = $point2;
                        }
                    }
                }
            }
        }
        if (count($positionsToUpdatePerWorld) === 0) {
            return;
        }
        foreach($positionsToUpdatePerWorld as $worldName => $positionsToUpdate) {
            $worldTargets = array_filter(
                $applyableTargets,
                static function(NetworkSession $target) use($worldName) : bool {
                    return $target->getPlayer()?->getWorld()->getFolderName() === $worldName;
                }
            );
            $positionsToUpdate = array_filter(
                $positionsToUpdate,
                static function(Vector3|null $value) : bool {
                    return $value !== null;
                }
            );
            $world = $applyableWorlds[$worldName];
            foreach ($world->createBlockUpdatePackets($positionsToUpdate) as $packet) {
                foreach($worldTargets as $target) {
                    $target->addToSendBuffer($packet);
                }
            }
        }
    }
}