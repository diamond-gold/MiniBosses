<?php

namespace MiniBosses;

use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\Explosion;

class BossProjectile extends Projectile
{
    protected string $networkId;
    protected float $explodeRadius;
    protected bool $explodeDestroyBlocks;
    protected bool $canBeAttacked;
    protected int $despawnAfter;
    protected bool $canBeDeflected;
    protected bool $followNearest;

    public function __construct(Location $location, ?Entity $shootingEntity, ?CompoundTag $nbt = null)
    {
        parent::__construct($location, $shootingEntity, $nbt);
        if($shootingEntity instanceof Boss) {
            $this->networkId = $nbt->getString("networkId");
            $this->setBaseDamage($shootingEntity->projectileOptions["attackDamage"]);
            $this->explodeRadius = $shootingEntity->projectileOptions["explodeRadius"];
            $this->explodeDestroyBlocks = $shootingEntity->projectileOptions["explodeDestroyBlocks"];
            $this->setHealth($shootingEntity->projectileOptions["health"]);
            $this->canBeAttacked = $shootingEntity->projectileOptions["canBeAttacked"];
            $this->despawnAfter = $shootingEntity->projectileOptions["despawnAfter"];
            $this->gravity = $shootingEntity->projectileOptions["gravity"];
            $this->canBeDeflected = $shootingEntity->projectileOptions["canBeDeflected"];
            $this->followNearest = $shootingEntity->projectileOptions["followNearest"];
            $this->setCanSaveWithChunk(false);
        }else
            $this->flagForDespawn();
    }

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo(0.25, 0.25);
    }

    public static function getNetworkTypeId(): string
    {
        return "BossProjectile";
    }

    public function sendSpawnPacket(Player $player): void
    {
        if ($this->networkId !== EntityIds::PLAYER) {
            $player->getNetworkSession()->sendDataPacket(AddActorPacket::create(
                $this->getId(),
                $this->getId(),
                $this->networkId,
                $this->location->asVector3(),
                $this->getMotion(), $this->location->pitch, $this->location->yaw, $this->location->yaw,
                array_map(function (Attribute $attr): NetworkAttribute {
                    return new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue());
                }, $this->attributeMap->getAll()),
                $this->getAllNetworkData(),
                []
            ));
        }
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->despawnAfter > 0 && $this->ticksLived > $this->despawnAfter)
            $this->flagForDespawn();
        if($this->followNearest) {
            $player = null;
            $dist = PHP_INT_MAX;
            foreach ($this->getViewers() as $p) {
                if (!$p->isSpectator() && $p->isAlive() && ($d = $p->location->distanceSquared($this->location)) < $dist) {
                    $player = $p;
                    $dist = $d;
                }
            }
            if($player !== null)
                $this->setMotion($player->getEyePos()->subtractVector($this->location)->normalize()->multiply($this->motion->length()));
        }
        return parent::onUpdate($currentTick);
    }

    protected function onHit(ProjectileHitEvent $event): void
    {
        parent::onHit($event);
        if ($this->explodeRadius > 0) {
            $explosion = new Explosion($this->location, $this->explodeRadius, $this);
            if ($this->explodeDestroyBlocks)
                $explosion->explodeA();
            $explosion->explodeB();
        }
        $this->flagForDespawn();
    }

    public function canCollideWith(Entity $entity): bool
    {
        return !$entity instanceof Boss && parent::canCollideWith($entity);
    }

    public function attack(EntityDamageEvent $source): void
    {
        if ($this->canBeAttacked) {
            Entity::attack($source);
            if ($source instanceof EntityDamageByEntityEvent) {
                $attacker = $source->getDamager();
                if ($attacker && $this->canBeDeflected)
                    $this->setMotion($attacker->getDirectionVector()->multiply($this->motion->length()));
            }
        } else
            parent::attack($source);
    }
}